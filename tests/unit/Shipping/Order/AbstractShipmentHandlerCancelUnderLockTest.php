<?php
/**
 * Unit: `Abstract_Shipment_Handler::cancel_under_lock()` (#1007) — the background cancellation of a
 * cancelled / fully refunded order takes the SAME per-order lock as an export, reads the stored
 * carrier id again under it from a fresh copy of the order, and only then asks the carrier.
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
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-action-result.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-lock.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-request-purpose.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-export-retry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	if ( ! class_exists( 'Cancel_Lock_Test_Shipment_Handler' ) ) {
		/**
		 * Concrete handler whose lock and fresh-order seams the test drives.
		 */
		class Cancel_Lock_Test_Shipment_Handler extends Abstract_Shipment_Handler {

			/** @var bool whether the export lock is granted */
			public bool $lock_granted = true;

			/** @var int[] order ids the lock was taken for */
			public array $locked = [];

			/** @var int[] order ids the lock was released for */
			public array $released = [];

			/** @var \WC_Order|null what fresh_order() returns; null = the order given */
			public $fresh;

			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return '';
			}

			protected function acquire_export_lock( int $order_id ): bool {
				$this->locked[] = $order_id;

				return $this->lock_granted;
			}

			protected function release_export_lock( int $order_id ): void {
				$this->released[] = $order_id;
			}

			protected function fresh_order( \WC_Order $order ): \WC_Order {
				return $this->fresh ?? $order;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::cancel_under_lock
	 */
	final class AbstractShipmentHandlerCancelUnderLockTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * @param mixed $api           a Shipping_API mock.
		 * @param mixed $order_handler a Shipping_Order_Handler mock.
		 */
		private function handler( $api, $order_handler ): \Cancel_Lock_Test_Shipment_Handler {
			return new \Cancel_Lock_Test_Shipment_Handler( $api, $order_handler, 'test', Mockery::mock( Popular_Settlement_Store::class ) );
		}

		private function order( int $id ) {
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( $id );

			return $order;
		}

		public function test_a_busy_order_is_not_cancelled_and_the_carrier_is_not_asked(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'cancel_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldNotReceive( 'get' );

			$handler               = $this->handler( $api, $order_handler );
			$handler->lock_granted = false;

			$result = $handler->cancel_under_lock( $this->order( 123 ) );

			$this->assertNotNull( $result );
			$this->assertTrue( $result->is_busy(), 'an export holds the lock: look again shortly' );
			$this->assertFalse( $result->is_success() );
			$this->assertSame( [ 123 ], $handler->locked );
			$this->assertSame( [], $handler->released, 'a lock never taken is never released' );
		}

		public function test_an_order_with_no_shipment_answers_null_and_releases_the_lock(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'cancel_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id' )->andReturn( '' );

			$handler = $this->handler( $api, $order_handler );

			$this->assertNull( $handler->cancel_under_lock( $this->order( 123 ) ) );
			$this->assertSame( [ 123 ], $handler->released );
		}

		public function test_the_shipment_is_cancelled_with_the_carrier_and_the_lock_is_released(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once()->with( Mockery::type( '\WC_Order' ), 'carrier_order_id', '' );

			$handler = $this->handler( $api, $order_handler );
			$result  = $handler->cancel_under_lock( $this->order( 123 ) );

			$this->assertNotNull( $result );
			$this->assertTrue( $result->is_success() );
			$this->assertSame( [ 123 ], $handler->locked );
			$this->assertSame( [ 123 ], $handler->released );
		}

		public function test_the_carrier_id_is_read_again_under_the_lock_from_a_fresh_copy(): void {
			$stale = $this->order( 123 );
			$fresh = $this->order( 123 );

			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-FINISHED-JUST-NOW' );

			// The caller's copy has no id (the export finished while it waited); the fresh one has.
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->with( $stale, 'carrier_order_id' )->andReturn( '' );
			$order_handler->shouldReceive( 'get' )->with( $fresh, 'carrier_order_id' )->andReturn( 'CARRIER-FINISHED-JUST-NOW' );
			$order_handler->shouldReceive( 'set' )->once()->with( $fresh, 'carrier_order_id', '' );

			$handler        = $this->handler( $api, $order_handler );
			$handler->fresh = $fresh;

			$result = $handler->cancel_under_lock( $stale );

			$this->assertNotNull( $result );
			$this->assertTrue( $result->is_success() );
		}

		public function test_a_refusal_carries_the_carriers_text_and_still_releases_the_lock(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->andThrow( new \Woodev_API_Exception( 'Заказ уже передан курьеру' ) );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldNotReceive( 'set' );

			$handler = $this->handler( $api, $order_handler );
			$result  = $handler->cancel_under_lock( $this->order( 123 ) );

			$this->assertNotNull( $result );
			$this->assertFalse( $result->is_success() );
			$this->assertFalse( $result->is_busy() );
			$this->assertSame( 'Заказ уже передан курьеру', $result->get_message() );
			$this->assertSame( [ 123 ], $handler->released );
		}

		public function test_the_lock_is_released_even_when_the_carrier_call_blows_up(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->andThrow( new \RuntimeException( 'boom' ) );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );

			$handler = $this->handler( $api, $order_handler );

			try {
				$handler->cancel_under_lock( $this->order( 123 ) );
				$this->fail( 'the exception must propagate' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'boom', $exception->getMessage() );
			}

			$this->assertSame( [ 123 ], $handler->released );
		}
	}
}
