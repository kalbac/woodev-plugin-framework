<?php
/**
 * Unit: the Action Scheduler callback that carries out a delayed export attempt (card #954).
 *
 * `Orders_Registry::run_export_retry()` finds the order's carrier and handler through the registry
 * that already maps an order to its carrier, then performs the same «export» the order screen does.
 * It never exports — and never reschedules — an order that is gone, belongs to no registered carrier,
 * or no longer offers «export».
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
	private function order( string $status = 'pending', int $attempts = 0 ) {
		$this->meta['_cdek_marker'] = '1';

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static fn( $key ) => Export_Retry::ATTEMPTS_META === $key ? $attempts : ''
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

	public function test_an_order_of_no_registered_carrier_is_left_alone(): void {
		$handler = $this->register_handler(); // a handler, but no provider claims the order.
		$handler->shouldNotReceive( 'export' );

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_carrier_without_a_handler_is_left_alone(): void {
		$this->register_provider();
		$this->order();

		Orders_Registry::instance()->run_export_retry( 123 );

		$this->addToAssertionCount( 1 ); // nothing to call, nothing thrown.
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
}
