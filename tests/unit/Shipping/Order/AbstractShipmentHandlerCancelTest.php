<?php
/**
 * Unit: `Abstract_Shipment_Handler::cancel()` — round 2 (HIGH 1): a successful
 * cancel must clear the stored carrier order id, or a merchant can send the same
 * destructive carrier cancellation a second time («Отменить» would keep offering
 * itself because {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_order()}
 * gates on that stored id staying non-empty).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/api/interface-shipping-api.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-async-request.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-background-job-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	if ( ! class_exists( 'Cancel_Test_Shipment_Handler' ) ) {
		/**
		 * Minimal concrete subclass — cancel() itself never calls extract_carrier_order_id(),
		 * this only satisfies the abstract method.
		 */
		class Cancel_Test_Shipment_Handler extends Abstract_Shipment_Handler {
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return '';
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Actions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::cancel
	 */
	final class AbstractShipmentHandlerCancelTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * @param mixed $api A Shipping_API mock.
		 */
		private function handler( $api, Shipping_Order_Handler $order_handler ): \Cancel_Test_Shipment_Handler {
			$retry_handler = Mockery::mock( '\Woodev_Background_Job_Handler' );
			$store         = Mockery::mock( Popular_Settlement_Store::class );

			return new \Cancel_Test_Shipment_Handler( $api, $order_handler, $retry_handler, 'test', $store );
		}

		public function test_a_successful_cancel_clears_the_stored_carrier_order_id(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id', '' );

			$order = Mockery::mock( '\WC_Order' );

			$result = $this->handler( $api, $order_handler )->cancel( $order );

			$this->assertTrue( $result );
		}

		/**
		 * The `shipment_cancelled` action still carries the id that WAS cancelled — a
		 * subscriber needs to know what was cancelled, even though the stored id is
		 * already cleared by the time the action fires.
		 */
		public function test_the_cancelled_action_still_carries_the_cancelled_id(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id', '' );

			$order = Mockery::mock( '\WC_Order' );

			Actions\expectDone( 'woodev_shipping_test_shipment_cancelled' )->once()->with( $order, 'CARRIER-1' );

			$this->handler( $api, $order_handler )->cancel( $order );
		}

		public function test_a_failed_cancel_leaves_the_stored_carrier_order_id_untouched(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' )->andThrow( new \Woodev_API_Exception( 'carrier rejected' ) );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldNotReceive( 'set' );

			$order = Mockery::mock( '\WC_Order' );

			$result = $this->handler( $api, $order_handler )->cancel( $order );

			$this->assertFalse( $result );
		}

		public function test_cancel_with_no_stored_carrier_order_id_never_calls_the_api_or_clears_anything(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'cancel_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->once()->andReturn( '' );
			$order_handler->shouldNotReceive( 'set' );

			$order = Mockery::mock( '\WC_Order' );

			$result = $this->handler( $api, $order_handler )->cancel( $order );

			$this->assertFalse( $result );
		}
	}
}
