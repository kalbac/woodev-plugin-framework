<?php
/**
 * Unit: the per-order edit lock an UPDATE takes before it reads anything (#981 round 4).
 *
 * Two admin saves of one order are serialised through a MySQL named lock. Pinned here without a
 * database: the lock's name (unique per site and order, within MySQL's 64 characters), the timeout
 * the constructor sets, the 409 a refused lock answers before the order is read, and the release
 * that follows every outcome. The real contention — a second connection holding the lock — is
 * proven by the integration tests on both datastores.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/order-editor-lock-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::update
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::update_lock_name
 */
final class OrderEditorUpdateLockTest extends TestCase {

	/** @var Order_Editor_Fake_Wpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb       = new Order_Editor_Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * An editor whose collaborators must not be reached.
	 *
	 * @param int $timeout seconds to wait for the lock.
	 * @return Order_Editor
	 */
	private function editor( int $timeout = Order_Editor::UPDATE_LOCK_TIMEOUT ): Order_Editor {
		$validator = Mockery::mock( Order_Payload_Validator::class );
		$validator->shouldNotReceive( 'validate' );

		return new Order_Editor( Mockery::mock( Orders_Registry::class ), $validator, $timeout );
	}

	public function test_the_lock_name_is_unique_per_site_and_order_and_fits_mysqls_limit(): void {
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->dbname = 'shop';

		$name = Order_Editor::update_lock_name( 123 );

		$this->assertStringStartsWith( 'woodev_order_edit_123_', $name );
		$this->assertSame( $name, Order_Editor::update_lock_name( 123 ), 'stable: both requests must compute the same name' );
		$this->assertNotSame( $name, Order_Editor::update_lock_name( 124 ), 'another order, another lock' );

		$this->wpdb->prefix = 'wp_2_';
		$this->assertNotSame( $name, Order_Editor::update_lock_name( 123 ), 'another blog of a multisite, another lock' );

		$this->wpdb->prefix = 'wp_';
		$this->wpdb->dbname = 'other_shop';
		$this->assertNotSame( $name, Order_Editor::update_lock_name( 123 ), 'another database on the same server, another lock' );

		$this->wpdb->prefix = str_repeat( 'p', 40 ) . '_';
		$this->wpdb->dbname = str_repeat( 'd', 64 );
		$this->assertLessThanOrEqual( 64, strlen( Order_Editor::update_lock_name( PHP_INT_MAX ) ), 'MySQL refuses a lock name over 64 characters, whatever the prefix' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', Order_Editor::update_lock_name( PHP_INT_MAX ) );
	}

	public function test_a_lock_that_times_out_answers_409_before_the_order_is_read(): void {
		$this->wpdb->grant = '0';

		Functions\expect( 'wc_get_order' )->never();

		$result = $this->editor( 3 )->update( 123, [ 'status' => 'processing' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_order_busy', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'], 'the transport contract\'s «try again», same shape as the other 409s' );

		$this->assertSame(
			[ "SELECT GET_LOCK('" . Order_Editor::update_lock_name( 123 ) . "', 3)" ],
			$this->wpdb->lock_statements(),
			'one GET_LOCK with the constructor\'s timeout, and no RELEASE_LOCK for a lock never held'
		);
	}

	public function test_a_lock_the_database_refuses_answers_409_too(): void {
		$this->wpdb->grant = null;

		Functions\expect( 'wc_get_order' )->never();

		$result = $this->editor()->update( 123, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_order_busy', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->wpdb->lock_statements(), 'nothing to release' );
	}

	public function test_the_default_timeout_is_the_documented_ten_seconds(): void {
		$this->wpdb->grant = '0';

		Functions\expect( 'wc_get_order' )->never();

		$this->editor()->update( 123, [] );

		$this->assertSame( 10, Order_Editor::UPDATE_LOCK_TIMEOUT );
		$this->assertStringEndsWith( ', 10)', $this->wpdb->lock_statements()[0] );
	}

	public function test_a_granted_lock_is_released_whatever_the_update_answers(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->editor()->update( 123, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'], 'the lock was held: the update ran and answered from the read' );

		$name = Order_Editor::update_lock_name( 123 );

		$this->assertSame(
			[
				"SELECT GET_LOCK('" . $name . "', 10)",
				"SELECT RELEASE_LOCK('" . $name . "')",
			],
			$this->wpdb->lock_statements(),
			'taken before the read, released after the answer'
		);
	}
}
