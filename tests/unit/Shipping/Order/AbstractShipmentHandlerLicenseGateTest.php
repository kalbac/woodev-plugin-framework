<?php
/**
 * Unit: the licence gate of the shipment handler (#1270) — a NEW export and a cancellation at the carrier are
 * refused while the licence of the plugin that owns the handler is not in force, before any carrier call.
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
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	if ( ! class_exists( 'License_Gate_Test_Shipment_Handler' ) ) {
		/** Minimal concrete subclass. */
		class License_Gate_Test_Shipment_Handler extends Abstract_Shipment_Handler {
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return '';
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::cancel
	 * @covers \Woodev\Framework\Shipping\License_Gate::handler_refusal
	 */
	final class AbstractShipmentHandlerLicenseGateTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );

			Location_Provider_Registry::instance()->reset_for_tests();
			Orders_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * A handler registered under a provider owned by a plugin with the given licence state.
		 *
		 * @param mixed    $api          Shipping_API double.
		 * @param mixed    $order_handler order-meta accessor double.
		 * @param bool|int $license      true / false = a paid plugin whose licence is / is not in force; 0 = no store product.
		 * @param bool     $with_plugin  whether the provider was registered with its plugin.
		 */
		private function registered_handler( $api, $order_handler, $license, bool $with_plugin = true ): \License_Gate_Test_Shipment_Handler {
			$handler = new \License_Gate_Test_Shipment_Handler( $api, $order_handler, 'test', Mockery::mock( Popular_Settlement_Store::class ) );

			$plugin = null;

			if ( $with_plugin ) {
				$plugin = Mockery::mock( Shipping_Plugin::class );

				if ( 0 === $license ) {
					$plugin->shouldReceive( 'get_download_id' )->andReturn( 0 );
				} else {
					$engine = Mockery::mock( \Woodev_Plugins_License::class );
					$engine->shouldReceive( 'is_license_in_force' )->andReturn( $license );
					$plugin->shouldReceive( 'get_download_id' )->andReturn( 9001 );
					$plugin->shouldReceive( 'get_license_instance' )->andReturn( $engine );
				}
			}

			// the handler first, the provider second — either order is allowed, and the gate must not care
			Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );
			Orders_Registry::instance()->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ), $plugin );

			return $handler;
		}

		public function test_a_new_export_is_refused_while_the_licence_is_not_in_force(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'create_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( '' );

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldNotReceive( 'get_id' ); // refused before the lock, the hooks, anything.

			$result = $this->registered_handler( $api, $order_handler, false )->export( $order );

			$this->assertFalse( $result->is_success() );
			$this->assertTrue( $result->is_license_refused() );
			$this->assertFalse( $result->is_busy() );
			$this->assertStringContainsString( 'выгрузить заказ перевозчику', $result->get_message() );
			$this->assertStringContainsString( 'https://shop.test/wp-admin/admin.php?page=woodev-licenses', $result->get_message() );
		}

		/**
		 * An order that is ALREADY exported is not a new carrier action: handing back its id asks nothing of the carrier.
		 */
		public function test_an_already_exported_order_still_answers_with_its_id(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'create_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-7' );

			$result = $this->registered_handler( $api, $order_handler, false )->export( Mockery::mock( '\WC_Order' ) );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-7', $result->get_carrier_order_id() );
		}

		public function test_a_cancellation_is_refused_while_the_licence_is_not_in_force(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'cancel_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldNotReceive( 'set' );

			$result = $this->registered_handler( $api, $order_handler, false )->cancel( Mockery::mock( '\WC_Order' ) );

			$this->assertFalse( $result->is_success() );
			$this->assertTrue( $result->is_license_refused() );
			$this->assertStringContainsString( 'отменить заявку у перевозчика', $result->get_message() );
		}

		public function test_a_cancellation_goes_through_while_the_licence_is_in_force(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once();

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'update_meta_data' );
			$order->shouldReceive( 'save_meta_data' );
			$order->shouldReceive( 'get_meta' )->andReturn( '' );
			Functions\when( 'do_action' )->justReturn( null );

			$result = $this->registered_handler( $api, $order_handler, true )->cancel( $order );

			$this->assertTrue( $result->is_success() );
			$this->assertFalse( $result->is_license_refused() );
		}

		/**
		 * The background cancellation of a cancelled WooCommerce order reaches the carrier through
		 * cancel_under_lock() → cancel(), so it is gated too.
		 */
		public function test_the_background_cancellation_is_gated_through_cancel(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldNotReceive( 'cancel_order' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );

			$handler = $this->registered_handler( $api, $order_handler, false );
			$result  = $handler->cancel( Mockery::mock( '\WC_Order' ) );

			$this->assertTrue( $result->is_license_refused() );
		}

		public function test_a_plugin_with_no_store_product_is_never_gated(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once();

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'update_meta_data' );
			$order->shouldReceive( 'save_meta_data' );
			$order->shouldReceive( 'get_meta' )->andReturn( '' );
			Functions\when( 'do_action' )->justReturn( null );

			$this->assertTrue( $this->registered_handler( $api, $order_handler, 0 )->cancel( $order )->is_success() );
		}

		/**
		 * A handler that no registry knows (a unit test, a half-wired carrier) has no plugin to ask.
		 */
		public function test_a_handler_registered_with_no_plugin_is_not_gated(): void {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( 'cancel_order' )->once()->with( 'CARRIER-1' );

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldReceive( 'set' )->once();

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'update_meta_data' );
			$order->shouldReceive( 'save_meta_data' );
			$order->shouldReceive( 'get_meta' )->andReturn( '' );
			Functions\when( 'do_action' )->justReturn( null );

			$this->assertTrue( $this->registered_handler( $api, $order_handler, true, false )->cancel( $order )->is_success() );
		}
	}
}
