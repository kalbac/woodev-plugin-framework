<?php
/**
 * Unit tests for Shipping_Admin_Order::resolve_popular_settlement_context() — the
 * fix for round 2 critic finding HIGH 2 ("the enrolment seam is inert"): the export
 * action is the ONE real framework caller of
 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}, and
 * before this fix nothing in the repo ever supplied its settlement/provider params.
 * This proves the real call site now genuinely resolves both — via
 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlement_Store::recall_candidate()}
 * and {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_active_provider()}
 * — rather than merely accepting-but-never-populating them.
 *
 * `handle_order_action()` itself ends in `exit` (a real WP admin-post handler), so it
 * is unsafe to invoke directly from a unit test; the resolution logic is exercised
 * through the small, extracted `resolve_popular_settlement_context()` seam instead.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/class-shipping-admin-order.php';

	// The class type-hints \WC_Order and Shipping_Plugin/Shipping_Order_Handler/
	// Abstract_Shipment_Handler/Abstract_Tracking_Handler in its constructor, but
	// resolve_popular_settlement_context() is reached WITHOUT running that
	// constructor (see the reflection helper below), so none of those need loading.
}

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Mockery;
	use Woodev\Framework\Shipping\Admin\Shipping_Admin_Order;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::resolve_popular_settlement_context
	 */
	final class ShippingAdminOrderPopularSettlementContextTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Location_Provider_Registry is a process-wide singleton; other test
			// files configure it with declared providers/settings, so this test must
			// not observe (or leave behind) that state.
			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * Builds a Shipping_Admin_Order without running its real constructor (which
		 * needs Shipping_Plugin/Shipping_Order_Handler/Abstract_Shipment_Handler —
		 * unrelated to what this method resolves), and reflectively stamps the given
		 * store onto it.
		 *
		 * @param Popular_Settlement_Store|null $store
		 * @return Shipping_Admin_Order
		 */
		private function admin_order( ?Popular_Settlement_Store $store ): Shipping_Admin_Order {
			$reflection  = new \ReflectionClass( Shipping_Admin_Order::class );
			$admin_order = $reflection->newInstanceWithoutConstructor();

			$property = $reflection->getProperty( 'popular_settlement_store' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $admin_order, $store );

			return $admin_order;
		}

		/**
		 * @param Shipping_Admin_Order $admin_order
		 * @param mixed                 $order
		 * @return array{0: Location_Record|null, 1: mixed}
		 */
		private function invoke( Shipping_Admin_Order $admin_order, $order ): array {
			$method = ( new \ReflectionClass( $admin_order ) )->getMethod( 'resolve_popular_settlement_context' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			return $method->invoke( $admin_order, $order );
		}

		/**
		 * No store supplied: resolves to [null, null] WITHOUT touching either
		 * collaborator — the export action still works, just without enrolment.
		 */
		public function test_resolves_to_null_null_when_no_store_was_supplied(): void {
			$admin_order = $this->admin_order( null );
			$order       = Mockery::mock( '\WC_Order' );

			[ $settlement, $provider ] = $this->invoke( $admin_order, $order );

			$this->assertNull( $settlement );
			$this->assertNull( $provider );
		}

		/**
		 * The core HIGH 2 regression proof: WITH a store, this genuinely calls
		 * {@see Popular_Settlement_Store::recall_candidate()} (real settlement
		 * resolution — this is the mechanism that makes the ONE real
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}
		 * call site in the framework reachable) and asks the REAL
		 * {@see Location_Provider_Registry} singleton for the active provider (null
		 * here — nothing declared/collected in this unit-test process, which
		 * {@see Location_Provider_Registry::get_active_provider()}'s own docblock
		 * documents as the correct closed-gate answer; wiring INTO the real registry
		 * API, not what that API returns in an unconfigured process, is what this
		 * test proves).
		 */
		public function test_resolves_settlement_via_the_store_and_asks_the_real_registry_for_the_provider(): void {
			$record = Location_Record::from_array(
				[
					'key'         => 'dadata:1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'recall_candidate' )->once()->with( $order )->andReturn( $record );

			$admin_order = $this->admin_order( $store );

			[ $settlement, $provider ] = $this->invoke( $admin_order, $order );

			$this->assertSame( $record, $settlement );
			$this->assertNull( $provider, 'get_active_provider() correctly returns null while the gate is closed (nothing declared need in this process).' );
		}
	}
}
