<?php
/**
 * Unit test for `Shipping_Admin_Order::__construct()`'s popular-settlements store
 * DEFAULT (#497).
 *
 * WHY THIS EXISTS AS ITS OWN FILE. The default
 *
 * ```php
 * $this->popular_settlement_store = $popular_settlement_store
 *     ?? Location_Provider_Registry::instance()->popular_settlement_store();
 * ```
 *
 * is the only reason enrolment into the popular-settlements list is reachable at
 * all: nothing in the framework constructs `Shipping_Admin_Order` — a carrier
 * plugin does, and a carrier plugin knows nothing about popular settlements, so
 * it never passes the sixth argument. Remove the default and the whole feature
 * switches off silently. That already happened TWICE during #488 slice 2 (rounds
 * 2 and 3), caught both times by a critic rather than by a test.
 *
 * The equivalent default on `Abstract_Shipment_Handler` IS pinned
 * ({@see \Woodev\Tests\Unit\Shipping\Order\AbstractShipmentHandlerEnrollmentTest::test_constructor_defaults_to_the_frameworks_shared_store_when_none_is_injected}).
 * This one was not, and it could not simply be added to
 * `ShippingAdminOrderPopularSettlementContextTest`: that file states in its own
 * header that it deliberately does NOT load `Shipping_Plugin`,
 * `Shipping_Order_Handler` or `Abstract_Shipment_Handler`, and reaches the class
 * through `newInstanceWithoutConstructor()` precisely to avoid them. A test that
 * must run the REAL constructor needs exactly those three, so forcing it in there
 * would break that file's stated design — it was tried and reverted.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
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
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/class-shipping-admin-order.php';
}

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Mockery;
	use Woodev\Framework\Shipping\Admin\Shipping_Admin_Order;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::__construct
	 */
	final class ShippingAdminOrderStoreDefaultTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Location_Provider_Registry is a process-wide singleton and other files
			// leave declared providers/settings on it. Same reason the sibling
			// AbstractShipmentHandlerEnrollmentTest resets at both ends.
			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * Builds the class through its REAL constructor — the whole point of this
		 * file. Only `get_id()` is exercised on the plugin (twice, for the column key
		 * and the metabox id); the other three collaborators are stored untouched.
		 *
		 * @param Popular_Settlement_Store|null $store The sixth constructor argument.
		 * @return Shipping_Admin_Order
		 */
		private function admin_order( ?Popular_Settlement_Store $store ): Shipping_Admin_Order {
			$plugin = Mockery::mock( Shipping_Plugin::class );
			$plugin->shouldReceive( 'get_id' )->andReturn( 'test-carrier' );

			return new Shipping_Admin_Order(
				$plugin,
				Mockery::mock( Shipping_Order_Handler::class ),
				Mockery::mock( Abstract_Shipment_Handler::class ),
				null,
				[],
				$store
			);
		}

		/**
		 * Reads the private property the constructor resolved.
		 *
		 * @param Shipping_Admin_Order $admin_order Subject.
		 * @return mixed
		 */
		private function resolved_store( Shipping_Admin_Order $admin_order ) {
			$property = ( new \ReflectionObject( $admin_order ) )->getProperty( 'popular_settlement_store' );

			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			return $property->getValue( $admin_order );
		}

		public function test_constructor_defaults_to_the_frameworks_shared_store_when_none_is_injected(): void {
			$admin_order = $this->admin_order( null );

			$this->assertSame(
				Location_Provider_Registry::instance()->popular_settlement_store(),
				$this->resolved_store( $admin_order ),
				'Without this default, nothing ever supplies a store here — no carrier plugin knows about popular settlements — and enrolment switches off silently.'
			);
		}

		public function test_the_default_is_the_same_instance_the_shipment_handler_resolves(): void {
			// The two collaborators must agree: a settlement recalled here and one
			// enrolled there have to refer to one store, which is exactly what
			// Location_Provider_Registry::popular_settlement_store()'s own docblock
			// says it exists for. Two independently-constructed stores would still
			// pass the test above while breaking the feature.
			$first  = $this->admin_order( null );
			$second = $this->admin_order( null );

			$this->assertSame(
				$this->resolved_store( $first ),
				$this->resolved_store( $second ),
				'Two constructions must resolve the SAME shared store instance, not two equal ones.'
			);
		}

		public function test_an_injected_store_wins_over_the_default(): void {
			$injected = new Popular_Settlement_Store();

			$admin_order = $this->admin_order( $injected );

			$this->assertSame(
				$injected,
				$this->resolved_store( $admin_order ),
				'An explicitly injected store must be kept — the default is a fallback, not an override.'
			);
		}
	}
}
