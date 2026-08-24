<?php
/**
 * Unit tests for Shipping_Admin_Order::resolve_popular_settlement_context() — the
 * fix for round 2 critic finding HIGH 2 ("the enrolment seam is inert"): the export
 * action is the ONE real framework caller of
 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}, and
 * before this fix nothing in the repo ever supplied its settlement/provider params.
 * This proves the real call site now genuinely resolves both — via
 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlement_Store::recall_candidate()}
 * and, since round 3 (HIGH 2), the SAME provider that produced the recalled
 * settlement (looked up by the settlement's own `provider_id()` through
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_providers()}),
 * NOT {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_active_provider()}
 * — a merchant switching the active provider between checkout and export must never
 * hand `enroll()` a provider that disagrees with the record's own `provider_id()`
 * (it throws `\InvalidArgumentException` when it does, AFTER the carrier order
 * already exists).
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

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;

	/**
	 * A minimal, configurable-id Location_Provider fixture — used to prove
	 * resolve_popular_settlement_context() looks the provider up by the
	 * settlement's own provider_id(), not by whichever provider happens to be
	 * active (round 3, HIGH 2).
	 */
	class Shipping_Admin_Order_Fixture_Provider extends Abstract_Location_Provider {

		private string $id;

		public function __construct( string $id ) {
			$this->id = $id;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return $this->id;
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function resolve_key( string $key ): ?Location_Record {
			return null;
		}
	}
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
		 * @param Popular_Settlement_Store $store
		 * @return Shipping_Admin_Order
		 */
		private function admin_order( Popular_Settlement_Store $store ): Shipping_Admin_Order {
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
		 * Stamps `$providers` directly onto the REAL registry singleton's backing
		 * array, bypassing {@see Location_Provider_Registry::collect()} entirely
		 * (its bundled-provider registration and `register_settings()` call are
		 * unrelated to what {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::resolve_popular_settlement_context()}
		 * exercises — `get_providers()` only ever reads this array).
		 *
		 * @param array<int, \Woodev\Framework\Shipping\Location\Location_Provider> $providers
		 * @return void
		 */
		private function register_providers( array $providers ): void {
			$registry = Location_Provider_Registry::instance();

			$reflection = new \ReflectionClass( Location_Provider_Registry::class );
			$property   = $reflection->getProperty( 'providers' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			$indexed = [];
			foreach ( $providers as $provider ) {
				$indexed[ $provider->get_id() ] = $provider;
			}

			$property->setValue( $registry, $indexed );
		}

		/**
		 * No candidate was recalled (e.g. no active provider at checkout, or the
		 * customer's session already expired): resolves to [null, null] without
		 * ever asking the registry for a provider.
		 */
		public function test_resolves_to_null_null_when_no_candidate_was_recalled(): void {
			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'recall_candidate' )->once()->with( $order )->andReturn( null );

			$admin_order = $this->admin_order( $store );

			[ $settlement, $provider ] = $this->invoke( $admin_order, $order );

			$this->assertNull( $settlement );
			$this->assertNull( $provider );
		}

		/**
		 * The core round-3 HIGH 2 regression proof: the provider returned is the
		 * one registered under the RECALLED SETTLEMENT'S OWN `provider_id()`,
		 * resolved purely via {@see Location_Provider_Registry::get_providers()} —
		 * this test never configures (or even needs) an "active" provider at all,
		 * which is exactly the point: the round-2 behaviour this replaces asked
		 * {@see Location_Provider_Registry::get_active_provider()} for "whichever
		 * provider is active now", a question with a completely different answer
		 * that could hand `enroll()` a provider disagreeing with the record's own
		 * `provider_id()` — which throws AFTER the carrier order already exists.
		 * Two providers are registered (one is never even asked about) to prove the
		 * lookup is genuinely keyed by id, not "whatever's first"/"whatever's
		 * active".
		 */
		public function test_resolves_the_provider_that_produced_the_settlement(): void {
			$this->register_providers(
				[
					new \Shipping_Admin_Order_Fixture_Provider( 'acme' ),
					new \Shipping_Admin_Order_Fixture_Provider( 'other-carrier' ),
				]
			);

			$record = Location_Record::from_array(
				[
					'key'         => 'other-carrier:1',
					'provider_id' => 'other-carrier',
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
			$this->assertNotNull( $provider );
			$this->assertSame( 'other-carrier', $provider->get_id(), 'The provider must be the one that produced the settlement, resolved by its own provider_id().' );
		}

		/**
		 * The settlement's own provider is no longer registered (e.g. a carrier
		 * plugin was deactivated between checkout and export): resolves to
		 * [settlement, null] rather than falling back to any other provider.
		 */
		public function test_resolves_null_provider_when_the_settlements_own_provider_is_no_longer_registered(): void {
			$this->register_providers( [ new \Shipping_Admin_Order_Fixture_Provider( 'acme' ) ] );

			$record = Location_Record::from_array(
				[
					'key'         => 'ghost:1',
					'provider_id' => 'ghost',
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
			$this->assertNull( $provider );
		}
	}
}
