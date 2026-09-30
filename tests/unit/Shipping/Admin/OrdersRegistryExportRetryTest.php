<?php
/**
 * Unit: the Action Scheduler callback that carries out a delayed export attempt (card #954).
 *
 * `Orders_Registry::run_export_retry()` finds the order's carrier and handler through the registry
 * that already maps an order to its carrier, then performs the same «export» the order screen does.
 * It never exports an order that is gone or no longer offers «export», and ends the chain for those;
 * for an order of a carrier that is not registered it says so in a note. A BUSY order (a native edit lock,
 * or the export lock of a concurrent click) is put back in the queue without counting an attempt.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Framework\Shipping\Order\Export_Retry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-export-retry.php';
// `Order_Actions::for_order()` reads the order's edit lock, so the lock's doubles must be loaded.
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::run_export_retry
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::add_hooks
 */
final class OrdersRegistryExportRetryTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active order. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\stubs( [ 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function register_provider(): void {
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] )
		);
	}

	private function register_handler(): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	/**
	 * @param string $status     the order status.
	 * @param int    $attempts   the export attempts the order's counter holds.
	 * @return \WC_Order
	 */
	private function order( string $status = 'pending', int $attempts = 0, int $deferrals = 0 ) {
		$this->meta['_cdek_marker'] = '1';

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key ) use ( $attempts, $deferrals ) {
				if ( Export_Retry::ATTEMPTS_META === $key ) {
					return $attempts;
				}

				return Export_Retry::DEFERRALS_META === $key ? $deferrals : '';
			}
		);

		Functions\when( 'wc_get_order' )->justReturn( $order );

		return $order;
	}

	public function test_the_registry_hooks_the_export_retry_action_in_every_request(): void {
		Actions\expectAdded( Export_Retry::HOOK )->once()->with( Mockery::on( fn( $callback ) => is_array( $callback ) && 'run_export_retry' === $callback[1] ) );

		$this->register_provider();

		$this->addToAssertionCount( 1 ); // expectAdded() is the assertion.
	}

	public function test_a_due_attempt_exports_the_order_through_its_carriers_handler(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$order   = $this->order( 'pending', 1 );

		$handler->shouldReceive( 'export' )->once()->with( $order, null, null )->andReturn( Action_Result::success( 'CARRIER-1' ) );

		Orders_Registry::instance()->run_export_retry( '123' ); // Action Scheduler hands the stored argument over as it is.

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_that_is_gone_is_left_alone(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'export' );

		Functions\when( 'wc_get_order' )->justReturn( false );

		Orders_Registry::instance()->run_export_retry( 999 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_of_no_registered_carrier_ends_the_chain_with_a_note(): void {
		$handler = $this->register_handler(); // a handler, but no provider claims the order.
		$handler->shouldNotReceive( 'export' );

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_meta' )->with( Export_Retry::ATTEMPTS_META )->andReturn( 2 );
		$order->shouldReceive( 'get_meta' )->with( Export_Retry::DEFERRALS_META )->andReturn( '' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Export_Retry::ATTEMPTS_META );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Повтор выгрузки не выполнен: перевозчик не найден (плагин отключён?)' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_carrier_without_a_handler_ends_the_chain_with_a_note(): void {
		$this->register_provider();
		$order = $this->order( 'pending', 3 );

		$order->shouldReceive( 'delete_meta_data' )->once()->with( Export_Retry::ATTEMPTS_META );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Повтор выгрузки не выполнен: перевозчик не найден (плагин отключён?)' );

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_exported_in_the_meantime_is_not_exported_again_and_its_counter_is_cleared(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'export' );

		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'pending', 2 );

		$order->shouldReceive( 'delete_meta_data' )->once()->with( Export_Retry::ATTEMPTS_META );
		$order->shouldReceive( 'save_meta_data' )->once();

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_that_no_longer_offers_export_ends_the_chain(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'export' );

		$order = $this->order( 'cancelled', 3 );

		$order->shouldReceive( 'delete_meta_data' )->once()->with( Export_Retry::ATTEMPTS_META );
		$order->shouldReceive( 'save_meta_data' )->once();

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}

	// ----- a busy order is put back, uncounted -----

	/** A queue double: the pending attempts `as_get_scheduled_actions()` reports, and every action scheduled. */
	private function stub_queue( array &$scheduled ): void {
		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $timestamp, $hook, $args = [], $group = '' ) use ( &$scheduled ) {
				$scheduled[] = [ $timestamp, $hook, $args, $group ];

				return 500 + count( $scheduled );
			}
		);
	}

	private function lock_order_for_another_manager(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [
			'time'    => time(),
			'user_id' => 7,
		];

		$user               = new \stdClass();
		$user->ID           = 7;
		$user->display_name = 'Мария';
		Functions\when( 'get_user_by' )->justReturn( $user );
	}

	public function test_a_due_attempt_that_meets_the_edit_lock_is_put_back_without_counting(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'export' );

		$scheduled = [];
		$this->stub_queue( $scheduled );
		$this->lock_order_for_another_manager();

		$order = $this->order( 'pending', 2, 3 );
		$order->shouldReceive( 'update_meta_data' )->once()->with( Export_Retry::DEFERRALS_META, 4 );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldNotReceive( 'delete_meta_data' ); // the chain is NOT over: nothing is cleared.

		$before = time();
		Orders_Registry::instance()->run_export_retry( 123 );

		$this->assertCount( 1, $scheduled );
		$this->assertSame( [ Export_Retry::HOOK, [ 123 ], Export_Retry::GROUP ], [ $scheduled[0][1], $scheduled[0][2], $scheduled[0][3] ] );
		$this->assertEqualsWithDelta( $before + 300, $scheduled[0][0], 3, 'back in five minutes' );
	}

	public function test_a_due_attempt_that_meets_the_export_lock_is_put_back_without_counting(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$order   = $this->order( 'pending', 2 );

		$handler->shouldReceive( 'export' )->once()->andReturn( Action_Result::busy( 'Этот заказ уже выгружается' ) );

		$scheduled = [];
		$this->stub_queue( $scheduled );

		$order->shouldReceive( 'update_meta_data' )->once()->with( Export_Retry::DEFERRALS_META, 1 );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldNotReceive( 'delete_meta_data' );

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->assertCount( 1, $scheduled );
	}

	public function test_a_carrier_failure_is_not_put_back_by_the_runner(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$this->order( 'pending', 2 );

		// The handler booked the failure and queued the next attempt itself; the runner adds nothing.
		$handler->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure( 'Too Many Requests' ) );

		$scheduled = [];
		$this->stub_queue( $scheduled );

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->assertSame( [], $scheduled );
	}

	public function test_a_busy_order_that_cannot_be_queued_ends_the_chain(): void {
		$this->register_provider();
		$handler = $this->register_handler();
		$order   = $this->order( 'pending', 2 );

		$handler->shouldReceive( 'export' )->once()->andReturn( Action_Result::busy( 'Этот заказ уже выгружается' ) );

		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'as_schedule_single_action' )->justReturn( 0 );

		$order->shouldReceive( 'delete_meta_data' )->once()->with( Export_Retry::ATTEMPTS_META );
		$order->shouldReceive( 'save_meta_data' )->once();

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}
}
