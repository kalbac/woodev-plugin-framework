<?php
/**
 * Unit tests for Location_Service::get_popular_settlements_for_country()
 * (issue #530 — #488's customer-facing half).
 *
 * Exercises the D3/D4 gating this method must apply on its own (mirroring
 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlements_Tools}'s own
 * D3 rule: never present-and-partial for a provider that cannot resolve by
 * key), the per-country narrowing (the store itself is not country-scoped),
 * and the wire shape shared with `/suggest`/`/list`
 * ({@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::to_response_records()}):
 * `{ key, label, level, record }`, `record` the settlement's own
 * `Location_Record::to_array()` untouched — including `ancestors`, the flat
 * key set the client actually needs for local region filtering (this file's
 * own MEASURED correction of an earlier draft that assumed a single "region
 * key" field; {@see Location_Record::region()} carries only `{ name, type }`).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;

	/**
	 * Declares CAPABILITY_RESOLVE_KEY (overrides resolve_key()) — a D4-eligible
	 * provider, mirroring `Checkout_Listener_Resolving_Fixture_Provider` in
	 * LocationProviderRegistryPopularSettlementsTest.php.
	 */
	class Popular_Settlements_Service_Resolving_Fixture_Provider extends Abstract_Location_Provider {
		public function get_id(): string {
			return 'svc-resolving';
		}

		public function get_name(): string {
			return 'Resolving Fixture';
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

	/**
	 * Does NOT override resolve_key() — D4-ineligible.
	 */
	class Popular_Settlements_Service_Non_Resolving_Fixture_Provider extends Abstract_Location_Provider {
		public function get_id(): string {
			return 'svc-non-resolving';
		}

		public function get_name(): string {
			return 'Non-Resolving Fixture';
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
	}
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Locality_Key;
	use Woodev\Framework\Shipping\Location\Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Location\Location_Settings;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Service::get_popular_settlements_for_country
	 */
	final class LocationServicePopularSettlementsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		/**
		 * Builds a registry instance WITHOUT going through the singleton (private
		 * constructor) — isolates each test from any state another test file's
		 * `declare_needed()`/`collect()` may have left on the real singleton, same
		 * discipline as `LocationProviderRegistryPopularSettlementsTest::registry_with()`.
		 *
		 * @param array<int, \Woodev\Framework\Shipping\Location\Location_Provider> $providers
		 * @param string                                                            $active_provider_id
		 * @param Popular_Settlement_Store|null                                     $store               Injected so a real `\wpdb` is never touched.
		 *
		 * @return Location_Provider_Registry
		 */
		private function registry_with( array $providers, string $active_provider_id, ?Popular_Settlement_Store $store = null ): Location_Provider_Registry {
			$reflection = new \ReflectionClass( Location_Provider_Registry::class );
			$registry   = $reflection->newInstanceWithoutConstructor();

			$this->set_property( $reflection, $registry, 'needed', true );

			$indexed = [];
			foreach ( $providers as $provider ) {
				$indexed[ $provider->get_id() ] = $provider;
			}
			$this->set_property( $reflection, $registry, 'providers', $indexed );

			$settings = Mockery::mock( Location_Settings::class );
			$settings->shouldReceive( 'get_value' )->with( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER )->andReturn( $active_provider_id );
			$this->set_property( $reflection, $registry, 'settings_handler', $settings );

			if ( null !== $store ) {
				$this->set_property( $reflection, $registry, 'popular_settlement_store', $store );
			}

			return $registry;
		}

		private function set_property( \ReflectionClass $reflection, object $object, string $name, $value ): void {
			$property = $reflection->getProperty( $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $object, $value );
		}

		/**
		 * @param string   $provider_id
		 * @param string   $native_id
		 * @param string   $country
		 * @param string[] $ancestors
		 *
		 * @return Location_Record
		 */
		private function record( string $provider_id, string $native_id, string $country = 'RU', array $ancestors = [] ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => Locality_Key::compose( $provider_id, $native_id ),
					'provider_id' => $provider_id,
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => $country,
					'settlement'  => [ 'name' => 'Fixture Town ' . $native_id, 'type' => 'г' ],
					'label'       => 'Fixture Town ' . $native_id,
					'ancestors'   => $ancestors,
				]
			);
		}

		private function entry( int $id, Location_Record $record, int $order_count = 1 ): Popular_Settlement_Entry {
			return new Popular_Settlement_Entry( $id, $record->provider_id(), $record->country(), $record, $order_count, 1000, null, 1000 );
		}

		// -------------------------------------------------------------------
		// D4 gate: absent, not present-and-partial
		// -------------------------------------------------------------------

		public function test_no_active_provider_returns_an_empty_list(): void {
			$registry = $this->registry_with( [], 'nothing-registered' );
			$service  = new Location_Service( $registry );

			$this->assertSame( [], $service->get_popular_settlements_for_country( 'RU' ) );
		}

		public function test_an_active_provider_without_the_resolve_key_capability_gets_no_popular_list_at_all(): void {
			$provider = new \Popular_Settlements_Service_Non_Resolving_Fixture_Provider();

			// Even if the store somehow still carries rows for this provider (stale
			// data from before a capability change, or a bug elsewhere), the D4 gate
			// on THIS method must still refuse them — never present-and-partial.
			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'all_for_provider' );

			$registry = $this->registry_with( [ $provider ], $provider->get_id(), $store );
			$service  = new Location_Service( $registry );

			$this->assertSame( [], $service->get_popular_settlements_for_country( 'RU' ) );
		}

		// -------------------------------------------------------------------
		// Country narrowing — the store is not itself country-scoped
		// -------------------------------------------------------------------

		public function test_narrows_to_the_requested_country_and_preserves_the_stores_own_rank_order(): void {
			$provider = new \Popular_Settlements_Service_Resolving_Fixture_Provider();

			$ru_first  = $this->entry( 1, $this->record( $provider->get_id(), 'ru-1', 'RU' ), 50 );
			$by_entry  = $this->entry( 2, $this->record( $provider->get_id(), 'by-1', 'BY' ), 99 );
			$ru_second = $this->entry( 3, $this->record( $provider->get_id(), 'ru-2', 'RU' ), 10 );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'all_for_provider' )->once()->with( $provider->get_id() )
				->andReturn( [ $ru_first, $by_entry, $ru_second ] ); // already ranked by the store itself.

			$registry = $this->registry_with( [ $provider ], $provider->get_id(), $store );
			$service  = new Location_Service( $registry );

			$popular = $service->get_popular_settlements_for_country( 'RU' );

			$this->assertCount( 2, $popular, 'the BY-scoped entry must not leak into an RU-scoped answer' );
			$this->assertSame( $ru_first->record()->key(), $popular[0]['key'], 'rank order from the store must survive untouched' );
			$this->assertSame( $ru_second->record()->key(), $popular[1]['key'] );
		}

		// -------------------------------------------------------------------
		// Wire shape — shared with /suggest and /list
		// -------------------------------------------------------------------

		public function test_each_entry_carries_the_shared_suggest_list_wire_shape_including_ancestors(): void {
			$provider = new \Popular_Settlements_Service_Resolving_Fixture_Provider();
			$region_key = Locality_Key::compose( $provider->get_id(), 'region-1' );
			$record     = $this->record( $provider->get_id(), 'ru-1', 'RU', [ $region_key ] );
			$entry      = $this->entry( 1, $record, 7 );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'all_for_provider' )->with( $provider->get_id() )->andReturn( [ $entry ] );

			$registry = $this->registry_with( [ $provider ], $provider->get_id(), $store );
			$service  = new Location_Service( $registry );

			$popular = $service->get_popular_settlements_for_country( 'RU' );

			$this->assertCount( 1, $popular );
			$this->assertSame( $record->key(), $popular[0]['key'] );
			$this->assertSame( $record->label(), $popular[0]['label'] );
			$this->assertSame( $record->level(), $popular[0]['level'] );
			$this->assertSame( $record->to_array(), $popular[0]['record'], 'record must round-trip UNTOUCHED — the same object /select would receive from a search pick (spec D1)' );
			// The load-bearing correction this method's own docblock documents:
			// there is no separate "region key" field — the client's region filter
			// is `record.ancestors`, the same flat key set is_within() already uses.
			$this->assertSame( [ $region_key ], $popular[0]['record']['ancestors'] );
		}
	}
}
