<?php
/**
 * Unit tests for Location_Service — the single façade every other framework
 * layer uses (Task 6): is_active() (gate + active provider + provider
 * configured), the customer-record get/set pass-through (implicit flag
 * preserved), resolve_for() (current record -> resolution cache -> adapter,
 * null when there is no record at all), is_country_supported() (static list,
 * normalized), and provider_for_level() — the D15 chain (chosen -> bundled
 * fallback -> null), exhaustively, including the "fallback never consulted
 * when the chosen provider already answers" case.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Adapter;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Resolution_Cache;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
	use Woodev\Framework\Settings\Settings_Page_Registry;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
	// Task 7: the bundled DaData provider now ALWAYS registers under
	// Location_Provider_Registry::DEFAULT_PROVIDER_ID whenever the gate is
	// open — required here (not left to load-order luck from another test
	// file) so this file's own assertions are correct whether it runs alone
	// or as part of the full suite.
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-provider.php';

	/**
	 * Minimal `\WC_Session` stand-in shared by the customer-store probe and the
	 * resolution-cache probe below — same shape as every other Task 4/5 test's
	 * own fake session.
	 */
	final class Location_Service_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     Session key.
		 * @param mixed  $default Fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}
	}

	/**
	 * Probe substituting a {@see Location_Service_Fake_Session} (or `null`) for
	 * the real `WC()->session` global — mirrors `Customer_Location_Store_Probe`.
	 */
	final class Location_Service_Customer_Store_Probe extends Customer_Location_Store {

		private ?Location_Service_Fake_Session $fake_session;

		public function __construct( ?Location_Service_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * Probe substituting a {@see Location_Service_Fake_Session} (or `null`) for
	 * the real `WC()->session` global — mirrors `Location_Resolution_Cache_Probe`.
	 */
	final class Location_Service_Resolution_Cache_Probe extends Location_Resolution_Cache {

		private ?Location_Service_Fake_Session $fake_session;

		public function __construct( ?Location_Service_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * A spy {@see Location_Resolution_Cache}: counts resolve_for() calls,
	 * captures the LAST record it was called with (issue #336 — proves
	 * {@see Location_Service::resolve_for()}'s optional `$record` argument
	 * reaches the cache unchanged, rather than being silently re-derived from
	 * the customer's current record), and returns a configured value, WITHOUT
	 * touching any session — also used to prove `resolve_for()` never even
	 * reaches the cache when there is no customer record at all.
	 */
	final class Location_Service_Spy_Resolution_Cache extends Location_Resolution_Cache {

		public int $calls = 0;

		public ?Location_Record $last_record = null;

		/** @var mixed */
		private $return_value;

		public function __construct( $return_value = null ) {
			$this->return_value = $return_value;
		}

		public function resolve_for( Shipping_Plugin $plugin, Location_Record $record ) {
			++$this->calls;
			$this->last_record = $record;

			return $this->return_value;
		}
	}

	/**
	 * Bare fixture Shipping_Plugin — built via `newInstanceWithoutConstructor()`
	 * (same discipline as `Location_Resolution_Cache_Fixture_Plugin`).
	 */
	class Location_Service_Fixture_Plugin extends Shipping_Plugin {

		public string $fake_id = 'test_plugin';
		public ?Location_Adapter $fake_adapter = null;

		protected function get_shipping_method_classes(): array {
			return [];
		}

		public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
			return null;
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Stub';
		}

		public function get_download_id() {
			return 0;
		}

		public function get_id() {
			return $this->fake_id;
		}

		public function needs_location_provider(): bool {
			return true;
		}

		public function get_location_adapter(): ?Location_Adapter {
			return $this->fake_adapter;
		}
	}

	/**
	 * A minimal, fully-parameterized fake provider — the same "each test
	 * builds exactly the shape it needs" discipline as
	 * `LocationProviderRegistryTest`'s own `Fake_Location_Provider`, extended
	 * with spy counters on `is_configured()`/`get_suggest_levels()` so the D15
	 * "fallback never consulted" claim can be asserted directly rather than
	 * only inferred from the return value.
	 */
	class Location_Service_Fake_Provider extends Abstract_Location_Provider {

		private string $id;
		private array $countries;
		private array $levels;
		private bool $configured;

		public int $is_configured_calls = 0;
		public int $get_suggest_levels_calls = 0;

		public function __construct( string $id, array $levels, bool $configured = true, array $countries = [ 'RU' ] ) {
			$this->id        = $id;
			$this->levels    = $levels;
			$this->configured = $configured;
			$this->countries = $countries;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return $this->id;
		}

		public function get_countries(): array {
			return $this->countries;
		}

		public function is_configured(): bool {
			++$this->is_configured_calls;

			return $this->configured;
		}

		protected function declare_suggest_levels(): array {
			++$this->get_suggest_levels_calls;

			return $this->levels;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}
	}

	/**
	 * A `list`-capable fake provider (Task 13) — {@see Location_Service_Fake_Provider}
	 * never overrides `list_localities()`, so it can never resolve through
	 * {@see Location_Service::provider_for_list()}; this fixture exists
	 * specifically to exercise that chain.
	 */
	class Location_Service_Fake_List_Provider extends Abstract_Location_Provider {

		private string $id;

		/** @var string[] */
		private array $countries;

		private bool $configured;

		public function __construct( string $id, array $countries = [ 'RU' ], bool $configured = true ) {
			$this->id        = $id;
			$this->countries = $countries;
			$this->configured = $configured;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return $this->id;
		}

		public function get_countries(): array {
			return $this->countries;
		}

		public function is_configured(): bool {
			return $this->configured;
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_REGION ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function list_localities( Location_Scope $scope ): array {
			return [];
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Service
	 */
	final class LocationServiceTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'remove_action' )->justReturn( true );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			// No filter callback hooked by default: apply_filters() must return
			// the passed-through default value unchanged (matches real WP with
			// nothing hooked) — includes Location_Service::FILTER_PROVIDER_FOR_LEVEL.
			Functions\when( 'apply_filters' )->returnArg( 2 );
			// Customer_Location_Store::get()/set() checks this on every call —
			// every fixture here is a guest unless a test says otherwise.
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			// Location_Service::is_customer_record_stale()'s rule (b) (#346) now
			// calls customer_shipping_country() -> resolve_default_country() ->
			// wc_get_base_location() on EVERY stored-record read, not only the
			// resolve_default_country()-specific tests below (which re-stub this
			// per scenario, overriding this default) — 'RU' matches this file's
			// own fixture convention (record()'s default country), so it is the
			// one value that keeps every OTHER test in this file from having to
			// know about a WooCommerce function it is not about.
			Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

			Location_Provider_Registry::instance()->reset_for_tests();
			Settings_Page_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();
			Settings_Page_Registry::instance()->reset_for_tests();
			parent::tearDown();
		}

		/**
		 * Stubs `apply_filters` so `Location_Provider_Registry::FILTER_PROVIDERS`
		 * returns exactly `$providers` and every other tag passes its default
		 * through untouched.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers the filter should return.
		 *
		 * @return void
		 */
		private function stub_providers_filter( array $providers ): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) use ( $providers ) {
					if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
						return $providers;
					}

					return $default;
				}
			);
		}

		/**
		 * Opens the gate and collects the given providers into the shared
		 * registry singleton.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers to register.
		 *
		 * @return void
		 */
		private function activate_with_providers( array $providers ): void {
			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( $providers );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();
		}

		/**
		 * Registers `$provider` and makes it the shop's ACTIVE provider (D15
		 * chain, chosen slot) — #346/#333: {@see Location_Service::get_customer_record()}
		 * / {@see Location_Service::get_customer_chain()} now run every stored
		 * record through {@see Location_Service::provider_for_level()} (the
		 * staleness gate), so a fixture record built via {@see self::record()}
		 * needs a REAL registered owning provider or the gate drops it as
		 * stale — this is the one place that boilerplate lives for every test
		 * below that stores a record and reads it back.
		 *
		 * `Location_Provider_Registry::DEFAULT_PROVIDER_ID` ('dadata') is
		 * NOT reusable here as a fixture id: {@see Location_Provider_Registry::collect()}
		 * unconditionally registers the REAL bundled `Dadata_Provider` under
		 * that id first, and a duplicate id registration is rejected —
		 * `$provider` must use its OWN, different id (this file's established
		 * `'svc-fixture'` convention, e.g. {@see self::test_is_active_true_when_gate_open_provider_active_and_configured()}).
		 *
		 * @param Location_Service_Fake_Provider $provider Provider to activate.
		 *
		 * @return Location_Provider_Registry
		 */
		private function activate_owning_provider( Location_Service_Fake_Provider $provider ): Location_Provider_Registry {
			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( $provider->get_id() );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			return $registry;
		}

		private function record( string $key = 'dadata:fias-1' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);
		}

		/**
		 * Builds a {@see Location_Service_Fixture_Plugin} via
		 * `newInstanceWithoutConstructor()` — a real Shipping_Plugin
		 * constructor touches a long chain of WP/WC calls this test has no
		 * business stubbing (same discipline as
		 * `Location_Resolution_Cache_Fixture_Plugin` in
		 * `LocationResolutionCacheTest`).
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Adapter|null $adapter Adapter to return from get_location_adapter().
		 *
		 * @return Location_Service_Fixture_Plugin
		 */
		private function plugin( ?Location_Adapter $adapter = null ): Location_Service_Fixture_Plugin {
			$instance = ( new \ReflectionClass( Location_Service_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();
			$instance->fake_adapter = $adapter;

			return $instance;
		}

		// -------------------------------------------------------------------
		// is_active(): gate + active provider + provider configured
		// -------------------------------------------------------------------

		public function test_is_active_false_when_the_gate_is_closed(): void {
			// Gate never opened — declare_needed()/collect() never called.
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertFalse( $service->is_active() );
		}

		public function test_is_active_false_when_the_gate_is_open_but_no_provider_is_registered(): void {
			$this->activate_with_providers( [] );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertFalse( $service->is_active() );
		}

		public function test_is_active_false_when_the_active_provider_is_unconfigured(): void {
			// A distinct id — DEFAULT_PROVIDER_ID ('dadata') now belongs to the
			// real bundled Dadata_Provider (Task 7), which ALSO always registers;
			// made active explicitly rather than via the default-id fallback.
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], false );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'svc-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertFalse( $service->is_active() );
		}

		public function test_is_active_true_when_gate_open_provider_active_and_configured(): void {
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'svc-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertTrue( $service->is_active() );
		}

		// -------------------------------------------------------------------
		// get_customer_record() / set_customer_record(): delegate to the store,
		// implicit flag preserved
		// -------------------------------------------------------------------

		public function test_get_customer_record_returns_null_when_nothing_is_stored(): void {
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertNull( $service->get_customer_record() );
		}

		public function test_set_then_get_round_trips_through_the_store_including_the_implicit_flag(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service  = new Location_Service( $registry, $store );

			$record = $this->record( 'svc-fixture:fias-1' );
			$ok     = $service->set_customer_record( $record, true );

			$this->assertTrue( $ok );

			$fetched = $service->get_customer_record();

			$this->assertNotNull( $fetched );
			$this->assertSame( $record->key(), $fetched['record']->key() );
			$this->assertTrue( $fetched['implicit'], 'the implicit flag must survive the façade round-trip' );
		}

		public function test_an_explicit_set_reports_implicit_false_on_read(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service  = new Location_Service( $registry, $store );

			$service->set_customer_record( $this->record( 'svc-fixture:fias-1' ), false );

			$this->assertFalse( $service->get_customer_record()['implicit'] );
		}

		public function test_set_customer_record_returns_false_when_no_session_is_available(): void {
			$store   = new Location_Service_Customer_Store_Probe( null );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertFalse( $service->set_customer_record( $this->record() ) );
		}

		// -------------------------------------------------------------------
		// get_customer_chain() / get_customer_record_at(): location-chain design
		// §4 (docs-internal/specs/2026-08-15-location-chain-design.md) — both
		// routed through get_customer_record() first (lazy default trigger stays
		// in one place), and the self::$unpersisted_default (review finding F1)
		// gap between the two accessors.
		// -------------------------------------------------------------------

		private function record_with_ancestors( string $key, string $level, array $ancestors ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => $level,
					'country'     => 'RU',
					'ancestors'   => $ancestors,
				]
			);
		}

		public function test_get_customer_chain_returns_null_when_nothing_is_stored_and_no_default_resolves(): void {
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertNull( $service->get_customer_chain() );
		}

		public function test_get_customer_chain_reflects_every_level_the_customer_picked(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service  = new Location_Service( $registry, $store );

			$settlement = $this->record( 'svc-fixture:settlement-1' );
			$address    = $this->record_with_ancestors( 'svc-fixture:address-1', Location_Record::LEVEL_ADDRESS, [ 'svc-fixture:settlement-1' ] );

			$service->set_customer_record( $settlement );
			$service->set_customer_record( $address );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertSame(
				[ Location_Record::LEVEL_SETTLEMENT, Location_Record::LEVEL_ADDRESS ],
				array_keys( $chain['records'] )
			);
			$this->assertSame( Location_Record::LEVEL_ADDRESS, $chain['current'] );
		}

		public function test_get_customer_record_at_returns_the_record_for_a_present_level(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service  = new Location_Service( $registry, $store );

			$settlement = $this->record( 'svc-fixture:settlement-1' );
			$address    = $this->record_with_ancestors( 'svc-fixture:address-1', Location_Record::LEVEL_ADDRESS, [ 'svc-fixture:settlement-1' ] );

			$service->set_customer_record( $settlement );
			$service->set_customer_record( $address );

			$at_settlement = $service->get_customer_record_at( Location_Record::LEVEL_SETTLEMENT );
			$at_address    = $service->get_customer_record_at( Location_Record::LEVEL_ADDRESS );

			$this->assertNotNull( $at_settlement );
			$this->assertSame( 'svc-fixture:settlement-1', $at_settlement->key() );
			$this->assertNotNull( $at_address );
			$this->assertSame( 'svc-fixture:address-1', $at_address->key() );
		}

		public function test_get_customer_record_at_returns_null_for_an_absent_level(): void {
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			// Only "settlement" is ever written — "address" and "region" were
			// never picked.
			$service->set_customer_record( $this->record( 'dadata:settlement-1' ) );

			$this->assertNull( $service->get_customer_record_at( Location_Record::LEVEL_ADDRESS ) );
			$this->assertNull( $service->get_customer_record_at( Location_Record::LEVEL_REGION ) );
		}

		public function test_get_customer_record_at_returns_null_for_an_unknown_level_string(): void {
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$service->set_customer_record( $this->record( 'dadata:settlement-1' ) );

			$this->assertNull( $service->get_customer_record_at( 'planet' ) );
		}

		public function test_get_customer_record_at_returns_null_when_nothing_is_stored_at_all(): void {
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertNull( $service->get_customer_record_at( Location_Record::LEVEL_SETTLEMENT ) );
		}

		/**
		 * Review finding F1's chain-visibility gap: a guest REST request with no
		 * session yet resolves a default through get_customer_record() (which
		 * memoizes it in self::$unpersisted_default because
		 * Customer_Location_Store::set() cannot write). get_customer_chain() and
		 * get_customer_record_at() must see that SAME unpersisted default, not
		 * silently answer null/empty for a customer get_customer_record() just
		 * served an answer for.
		 */
		public function test_get_customer_chain_sees_the_unpersisted_default_when_the_session_write_failed(): void {
			$store = new Location_Service_Customer_Store_Probe( null ); // No session at all -> set() always fails.

			// Rather than wiring the real default-locality policy plumbing
			// (`off`/`fixed`/`geoip`, already exhaustively covered by
			// LocationServiceDefaultTest), override resolve_default() directly —
			// get_customer_record()'s lazy trigger calls it unconditionally the
			// same way regardless of which policy produced the answer.
			$service = new class( Location_Provider_Registry::instance(), $store ) extends Location_Service {
				public function resolve_default(): ?Location_Record {
					return Location_Record::from_array(
						[
							'key'         => 'dadata:geo-default',
							'provider_id' => 'dadata',
							'level'       => Location_Record::LEVEL_SETTLEMENT,
							'country'     => 'RU',
						]
					);
				}

				// This test is not ABOUT staleness (FIX 1's own gate on the
				// resolved default) — it is about the get_customer_record()
				// <-> get_customer_chain() handoff for an unpersisted default
				// (review finding F1). The registry above is deliberately
				// left unactivated, so a REAL gate would reject this fixture
				// for having no owning provider at all; bypass it via the
				// same test seam is_customer_record_stale()'s own docblock
				// documents for exactly this situation.
				protected function is_customer_record_stale( Location_Record $record, ?string $for_country = null ): bool {
					return false;
				}
			};

			$first = $service->get_customer_record();

			$this->assertNotNull( $first, 'sanity: the default resolved for get_customer_record()' );
			$this->assertSame( 'dadata:geo-default', $first['record']->key() );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain, 'get_customer_chain() must see the same unpersisted default get_customer_record() just resolved' );
			$this->assertSame( 'dadata:geo-default', $chain['records'][ Location_Record::LEVEL_SETTLEMENT ]->key() );
			$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $chain['current'] );

			$at_level = $service->get_customer_record_at( Location_Record::LEVEL_SETTLEMENT );
			$this->assertNotNull( $at_level );
			$this->assertSame( 'dadata:geo-default', $at_level->key() );
		}

		// -------------------------------------------------------------------
		// #346/#333 — the staleness gate: a stored record whose owning provider
		// or country has moved out from under it is read as ABSENT, never
		// re-resolved. See Location_Service::gate_chain() /
		// Location_Service::is_customer_record_stale() for the two rules this
		// exercises.
		// -------------------------------------------------------------------

		public function test_gate_drops_a_record_whose_provider_no_longer_owns_its_level(): void {
			// Gate never opened at all (registry not activated) — nobody
			// resolves for "settlement", so the 'svc-fixture'-owned record this
			// test wrote earlier is no longer owned by anyone.
			$store = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$store->set( $this->record( 'svc-fixture:fias-1' ) );

			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertNull( $service->get_customer_record(), 'a record whose owning provider no longer resolves for its level must read as absent' );
		}

		public function test_gate_keeps_a_record_from_the_bundled_fallback_provider_still_resolved_as_owner(): void {
			// Regression guard against OVER-dropping: the chosen provider serves
			// REGION only, so the D15 chain falls through to the BUNDLED
			// Dadata_Provider for "settlement" — and that fallback is STILL the
			// resolved owner, so a 'dadata'-provider record must survive even
			// though the shop's ACTIVE provider is a different one entirely.
			$store  = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$store->set( $this->record( 'dadata:settlement-1' ) );

			$service = new Location_Service( $registry, $store );

			// Sanity: the D15 chain really did fall through to the bundled
			// provider for this level, not the (region-only) chosen one.
			$this->assertInstanceOf( Dadata_Provider::class, $service->provider_for_level( Location_Record::LEVEL_SETTLEMENT ) );

			$fetched = $service->get_customer_record();

			$this->assertNotNull( $fetched, 'a record from the BUNDLED fallback provider must survive when that provider is still the resolved owner' );
			$this->assertSame( 'dadata:settlement-1', $fetched['record']->key() );
		}

		public function test_gate_drops_a_record_when_provider_for_level_resolves_to_null(): void {
			// The gate IS open, but nobody resolves for "settlement": the chosen
			// provider only serves region, and the bundled fallback is left
			// unconfigured (no token) — distinct from the "gate never opened"
			// case above, same null outcome.
			$store  = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( '' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$store->set( $this->record( 'dadata:settlement-1' ) );

			$service = new Location_Service( $registry, $store );

			$this->assertNull( $service->provider_for_level( Location_Record::LEVEL_SETTLEMENT, 'RU' ), 'sanity: nobody resolves for this level' );
			$this->assertNull( $service->get_customer_record(), 'provider_for_level() answering null must drop the record as stale' );
		}

		public function test_gate_drops_a_record_whose_country_differs_from_the_customer_shipping_country(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );

			$store->set( $this->record( 'svc-fixture:fias-1' ) ); // country RU

			// The customer switched the checkout country (#346's own rig
			// measurement) — the record's provider ownership is fine, only the
			// country moved.
			$service = new class( $registry, $store ) extends Location_Service {
				protected function customer_shipping_country(): string {
					return 'BY';
				}
			};

			$this->assertNull( $service->get_customer_record(), 'a record whose country no longer matches the customer LIVE shipping country must read as absent' );
		}

		public function test_gate_compares_against_ru_when_no_live_field_and_no_store_country_are_set(): void {
			// Operator correction (s79): "unknown country" is not a reachable
			// state — customer_shipping_country() always resolves through the
			// SAME checkout-field -> store-setting -> RU chain
			// resolve_default_country() embodies. This pins that floor
			// specifically: no live WC()->customer stub at all (so step 1
			// answers nothing), and the store's OWN base location is also
			// empty (so step 2 answers nothing either) — the gate must still
			// land on 'RU' and match an 'RU' fixture record.
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );

			Functions\when( 'wc_get_base_location' )->justReturn( [] ); // step 2 answers nothing either.

			$store->set( $this->record( 'svc-fixture:fias-1' ) ); // country RU

			$service = new Location_Service( $registry, $store );

			$this->assertNotNull(
				$service->get_customer_record(),
				'with no live field and no store country, the gate must fall back to the RU floor and match an RU fixture'
			);
		}

		public function test_gate_drops_a_record_when_the_ru_floor_does_not_match_it(): void {
			// The mirror of the test above: the SAME empty-field/empty-store
			// state, but the stored record is NOT 'RU' — the RU floor must
			// still apply and drop it, proving this is a real comparison, not
			// an accidental always-match.
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'BY' ] ) );

			Functions\when( 'wc_get_base_location' )->justReturn( [] );

			$store->set(
				Location_Record::from_array(
					[
						'key'         => 'svc-fixture:fias-by',
						'provider_id' => 'svc-fixture',
						'level'       => Location_Record::LEVEL_SETTLEMENT,
						'country'     => 'BY',
					]
				)
			);

			$service = new Location_Service( $registry, $store );

			$this->assertNull( $service->get_customer_record(), 'the RU floor must drop a record from a different country, not silently match everything' );
		}

		public function test_gate_partial_chain_keeps_the_surviving_region_and_recomputes_current(): void {
			// 'svc-fixture' serves REGION only, so a settlement stored under the
			// SAME provider id is stale (nobody resolves it) while the region
			// survives — the chain must report ONLY the survivor, with `current`
			// recomputed to it rather than left pointing at the dropped level.
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true, [ 'RU' ] ) );

			$region     = Location_Record::from_array(
				[
					'key'         => 'svc-fixture:region-1',
					'provider_id' => 'svc-fixture',
					'level'       => Location_Record::LEVEL_REGION,
					'country'     => 'RU',
				]
			);
			$settlement = $this->record_with_ancestors( 'svc-fixture:settlement-1', Location_Record::LEVEL_SETTLEMENT, [ 'svc-fixture:region-1' ] );

			$store->set( $region );
			$store->set( $settlement );

			$service = new Location_Service( $registry, $store );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertSame(
				[ Location_Record::LEVEL_REGION ],
				array_keys( $chain['records'] ),
				'the stale settlement must be dropped, the surviving region kept'
			);
			$this->assertSame( Location_Record::LEVEL_REGION, $chain['current'], 'current must be recomputed to the deepest SURVIVING level' );
		}

		public function test_gate_nothing_survives_triggers_the_lazy_default_exactly_like_an_empty_store(): void {
			$store = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$store->set( $this->record( 'unregistered-provider:fias-1' ) ); // nobody owns this — fully stale.

			$service = new class( Location_Provider_Registry::instance(), $store ) extends Location_Service {
				public function resolve_default(): ?Location_Record {
					return Location_Record::from_array(
						[
							'key'         => 'dadata:geo-default',
							'provider_id' => 'dadata',
							'level'       => Location_Record::LEVEL_SETTLEMENT,
							'country'     => 'RU',
						]
					);
				}

				// This test needs the STORED fixture to stay genuinely stale
				// (that is the whole scenario under test — a fully-stale
				// chain must let the lazy trigger run), while the FRESHLY
				// RESOLVED default above must pass FIX 1's own pre-check —
				// so, unlike the sibling test above, this seam discriminates
				// by key rather than bypassing the gate unconditionally.
				protected function is_customer_record_stale( Location_Record $record, ?string $for_country = null ): bool {
					return 'dadata:geo-default' !== $record->key();
				}
			};

			$fetched = $service->get_customer_record();

			$this->assertNotNull( $fetched, 'a fully-stale chain must let the lazy default trigger run, exactly as an empty store does' );
			$this->assertSame( 'dadata:geo-default', $fetched['record']->key() );
		}

		public function test_gate_never_writes_back_to_the_store(): void {
			$store = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$store->set( $this->record( 'unregistered-provider:fias-1' ) );

			// Gate closed (registry never activated) -> get_default_locality_policy()
			// answers 'off' -> resolve_default() answers null with no further
			// calls, so there is nothing to fall back to either.
			$service = new Location_Service( Location_Provider_Registry::instance(), $store );

			$this->assertNull( $service->get_customer_record() );

			$raw = $store->get_chain();
			$this->assertNotNull( $raw, 'the stale blob must still be on disk — the gate re-applies per read, it never forgets' );
			$this->assertSame(
				'unregistered-provider:fias-1',
				$raw['records'][ Location_Record::LEVEL_SETTLEMENT ]->key(),
				'the gate must never write anything back to the store'
			);
		}

		public function test_gate_drops_every_descendant_of_a_dropped_ancestor_even_when_individually_valid(): void {
			// Adversarial review finding, s78 (FIX 2): gate_chain() used to
			// filter each level INDEPENDENTLY — a stale settlement dropped
			// while a deeper address (individually still valid) survived,
			// producing {address: ...} with current = address: an address
			// with no settlement above it, a shape
			// Customer_Location_Store::rebuild_chain() would never itself
			// build and the region > settlement > address cascade does not
			// permit. Gating must instead propagate in CASCADE ORDER: once an
			// ancestor is dropped, every deeper level goes with it.
			$store  = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_ADDRESS ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( '' ); // bundled fallback stays unconfigured -> nobody owns SETTLEMENT.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$settlement = Location_Record::from_array(
				[
					'key'         => 'city-dict:settlement-1',
					'provider_id' => 'city-dict',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);
			$address = Location_Record::from_array(
				[
					'key'         => 'city-dict:address-1',
					'provider_id' => 'city-dict',
					'level'       => Location_Record::LEVEL_ADDRESS,
					'country'     => 'RU',
					'ancestors'   => [ 'city-dict:settlement-1' ],
				]
			);

			$store->set( $settlement );
			$store->set( $address );

			$service = new Location_Service( $registry, $store );

			// Sanity: settlement has no owner at all (stale, rule a), while
			// address's OWN level is still owned by 'city-dict' — proving
			// this test would have kept address under the OLD, per-level
			// independent gate.
			$this->assertNull( $service->provider_for_level( Location_Record::LEVEL_SETTLEMENT, 'RU' ), 'sanity: nobody owns SETTLEMENT' );
			$address_owner = $service->provider_for_level( Location_Record::LEVEL_ADDRESS, 'RU' );
			$this->assertNotNull( $address_owner, 'sanity: city-dict still owns ADDRESS individually' );
			$this->assertSame( 'city-dict', $address_owner->get_id() );

			$this->assertNull(
				$service->get_customer_chain(),
				'a deeper record must be dropped along with a stale ancestor, even when it would individually still pass the gate'
			);
		}

		// -------------------------------------------------------------------
		// #346/#333 FIX 1 (adversarial review, s78): the LAZY DEFAULT must
		// pass the SAME gate a stored record has to pass. resolve_default()
		// resolves against the store-setting/RU floor, while the gate's rule
		// (b) compares against the LIVE customer_shipping_country() — a
		// freshly-resolved default that fails that comparison must never be
		// served ungated, and get_customer_record()/get_customer_chain() must
		// never disagree about whether the customer has a location.
		// -------------------------------------------------------------------

		public function test_get_customer_record_and_chain_agree_when_the_lazy_default_fails_its_own_gate(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );

			// resolve_default() answers an RU default (e.g. what a `fixed`/
			// `geoip` policy resolved against the store-setting/RU floor),
			// but the customer's LIVE shipping country is BY — the same
			// mismatch #346/#333 already gate STORED records against.
			// Before FIX 1, get_customer_record() served this default
			// UNGATED while get_customer_chain() answered null for the exact
			// same state.
			$service = new class( $registry, $store ) extends Location_Service {
				public function resolve_default(): ?Location_Record {
					return Location_Record::from_array(
						[
							'key'         => 'svc-fixture:ru-default',
							'provider_id' => 'svc-fixture',
							'level'       => Location_Record::LEVEL_SETTLEMENT,
							'country'     => 'RU',
						]
					);
				}

				protected function customer_shipping_country(): string {
					return 'BY';
				}
			};

			$record = $service->get_customer_record();
			$chain  = $service->get_customer_chain();

			$this->assertNull( $record, 'a default that cannot pass its own gate must answer null, not an ungated record' );
			$this->assertNull( $chain, 'get_customer_record() and get_customer_chain() must agree — both empty for the same state' );
		}

		public function test_cached_unpersisted_default_is_re_gated_not_served_blindly(): void {
			// The critic's own follow-up on FIX 1: "$this->unpersisted_default
			// branch a few lines above returns ungated too — check it." Seeds
			// the cache directly (bypassing resolve_default()) with a value
			// that fails the SAME gate a stored record must pass, pinning
			// that the early-return branch re-checks it rather than trusting
			// whatever got cached there.
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );

			$service = new class( $registry, $store ) extends Location_Service {
				protected function customer_shipping_country(): string {
					return 'BY';
				}
			};

			$reflection = new \ReflectionProperty( Location_Service::class, 'unpersisted_default' );
			$reflection->setValue(
				$service,
				Location_Record::from_array(
					[
						'key'         => 'svc-fixture:cached-ru',
						'provider_id' => 'svc-fixture',
						'level'       => Location_Record::LEVEL_SETTLEMENT,
						'country'     => 'RU',
					]
				)
			);

			$this->assertNull( $service->get_customer_record(), 'a cached unpersisted default that fails the gate must not be served ungated' );
		}

		// -------------------------------------------------------------------
		// #350/#352 follow-up (FIX 3): the optional $for_country parameter —
		// when given, rule (b) compares against IT instead of the ambient
		// customer_shipping_country(), so a REST read that already carries
		// its own normalized `country` param is no longer forced through the
		// ambient WooCommerce customer (which can disagree with it — gotcha
		// wc-customer-default-location-geolocation-fallback).
		// -------------------------------------------------------------------

		public function test_get_customer_chain_uses_for_country_instead_of_the_ambient_customer_country_when_given(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );

			$store->set( $this->record( 'svc-fixture:fias-1' ) ); // country RU

			// Ambient customer disagrees — e.g. a REST request whose own
			// normalized `country` param is RU, while WooCommerce's ambient
			// customer object answers something else for the SAME request
			// (measured, s79: a fresh guest's shipping country resolves
			// through geolocation, independent of the request at hand).
			$service = new class( $registry, $store ) extends Location_Service {
				protected function customer_shipping_country(): string {
					return 'BY';
				}
			};

			$this->assertNull( $service->get_customer_chain(), 'sanity: without $for_country, the ambient mismatch drops the record' );
			$this->assertNotNull(
				$service->get_customer_chain( 'RU' ),
				'$for_country, when given, must be used INSTEAD of the ambient customer country'
			);
		}

		// -------------------------------------------------------------------
		// resolve_for(): current record -> resolution cache -> adapter; null
		// when there is no record at all, and the cache is never even touched
		// -------------------------------------------------------------------

		public function test_resolve_for_returns_null_and_never_touches_the_cache_when_there_is_no_record(): void {
			$store        = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$spy_cache    = new Location_Service_Spy_Resolution_Cache( 'should-not-be-returned' );
			$service      = new Location_Service( Location_Provider_Registry::instance(), $store, $spy_cache );
			$plugin       = $this->plugin();

			$result = $service->resolve_for( $plugin );

			$this->assertNull( $result );
			$this->assertSame( 0, $spy_cache->calls, 'the resolution cache must never be consulted when there is no customer record' );
		}

		public function test_resolve_for_delegates_to_the_resolution_cache_with_the_current_record(): void {
			$store        = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$spy_cache    = new Location_Service_Spy_Resolution_Cache( 'city-code-42' );
			$registry     = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service      = new Location_Service( $registry, $store, $spy_cache );
			$plugin       = $this->plugin();

			$service->set_customer_record( $this->record( 'svc-fixture:fias-1' ) );

			$result = $service->resolve_for( $plugin );

			$this->assertSame( 'city-code-42', $result );
			$this->assertSame( 1, $spy_cache->calls );
		}

		/**
		 * Issue #336: the pickup map addresses itself by the settlement-preferred
		 * record {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_location_record()}
		 * resolves, which need not be the customer's CURRENT record (e.g. current is
		 * address-level, settlement is an ancestor). `resolve_for()`'s explicit `$record`
		 * argument must reach the cache AS GIVEN, not be silently overridden by the
		 * customer's current record — the cache never even reads the current record when
		 * an explicit one is passed.
		 */
		public function test_resolve_for_resolves_the_explicit_record_when_one_is_passed(): void {
			$store     = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$spy_cache = new Location_Service_Spy_Resolution_Cache( 'city-code-settlement' );
			$service   = new Location_Service( Location_Provider_Registry::instance(), $store, $spy_cache );
			$plugin    = $this->plugin();

			// The CURRENT record is address-level, a DIFFERENT key from the explicit
			// settlement record passed to resolve_for() below.
			$service->set_customer_record( $this->record( 'dadata:address-current' ) );

			$explicit_record = $this->record( 'dadata:settlement-explicit' );

			$result = $service->resolve_for( $plugin, $explicit_record );

			$this->assertSame( 'city-code-settlement', $result );
			$this->assertSame( 1, $spy_cache->calls );
			$this->assertNotNull( $spy_cache->last_record );
			$this->assertSame( 'dadata:settlement-explicit', $spy_cache->last_record->key(), 'the explicit record must reach the cache, not the current one' );
		}

		/**
		 * Issue #336: the default (`null`) must stay exactly today's behaviour — resolve
		 * for the customer's CURRENT record. This is a regression guard for the parameter's
		 * own default value, distinct from `test_resolve_for_delegates_to_the_resolution_cache_with_the_current_record`
		 * above (which never even names the parameter).
		 */
		public function test_resolve_for_defaults_to_the_current_record_when_no_record_is_passed(): void {
			$store     = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$spy_cache = new Location_Service_Spy_Resolution_Cache( 'city-code-42' );
			$registry  = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service   = new Location_Service( $registry, $store, $spy_cache );
			$plugin    = $this->plugin();

			$service->set_customer_record( $this->record( 'svc-fixture:fias-1' ) );

			$result = $service->resolve_for( $plugin, null );

			$this->assertSame( 'city-code-42', $result );
			$this->assertNotNull( $spy_cache->last_record );
			$this->assertSame( 'svc-fixture:fias-1', $spy_cache->last_record->key() );
		}

		public function test_resolve_for_uses_the_real_resolution_cache_end_to_end(): void {
			$store    = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$cache    = new Location_Service_Resolution_Cache_Probe( new Location_Service_Fake_Session() );
			$registry = $this->activate_owning_provider( new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true, [ 'RU' ] ) );
			$service  = new Location_Service( $registry, $store, $cache );

			$adapter = new class implements Location_Adapter {
				public int $calls = 0;

				public function resolve( Location_Record $record ) {
					++$this->calls;

					return 'resolved-' . $record->key();
				}
			};

			$plugin = new class( $adapter ) extends Location_Service_Fixture_Plugin {
				private Location_Adapter $adapter;

				public function __construct( Location_Adapter $adapter ) {
					$this->adapter = $adapter;
				}

				public function get_location_adapter(): ?Location_Adapter {
					return $this->adapter;
				}
			};

			$service->set_customer_record( $this->record( 'svc-fixture:fias-9' ) );

			$first  = $service->resolve_for( $plugin );
			$second = $service->resolve_for( $plugin );

			$this->assertSame( 'resolved-svc-fixture:fias-9', $first );
			$this->assertSame( 'resolved-svc-fixture:fias-9', $second );
			$this->assertSame( 1, $adapter->calls, 'the adapter must be cached across two resolve_for() calls through the façade' );
		}

		// -------------------------------------------------------------------
		// is_country_supported(): static list, case-insensitive/normalized
		// -------------------------------------------------------------------

		public function test_is_country_supported_false_when_no_active_provider(): void {
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertFalse( $service->is_country_supported( 'RU' ) );
		}

		/**
		 * Registers a fake provider under a distinct id (not `DEFAULT_PROVIDER_ID`,
		 * which now belongs to the real bundled Dadata_Provider — Task 7) and
		 * makes it explicitly active.
		 *
		 * @param Location_Service_Fake_Provider $provider Provider to register and activate.
		 * @return void
		 */
		private function activate_as_active_provider( Location_Service_Fake_Provider $provider ): void {
			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( $provider->get_id() );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();
		}

		public function test_is_country_supported_true_for_a_covered_country(): void {
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true, [ 'RU' ] );
			$this->activate_as_active_provider( $provider );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertTrue( $service->is_country_supported( 'RU' ) );
		}

		public function test_is_country_supported_is_case_insensitive(): void {
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true, [ 'RU' ] );
			$this->activate_as_active_provider( $provider );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertTrue( $service->is_country_supported( 'ru' ) );
			$this->assertTrue( $service->is_country_supported( ' Ru ' ) );
		}

		public function test_is_country_supported_false_for_an_uncovered_country(): void {
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true, [ 'RU' ] );
			$this->activate_as_active_provider( $provider );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertFalse( $service->is_country_supported( 'US' ) );
		}

		public function test_is_country_supported_false_for_a_malformed_country_code(): void {
			$provider = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true, [ 'RU' ] );
			$this->activate_as_active_provider( $provider );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertFalse( $service->is_country_supported( 'RUS' ) );
			$this->assertFalse( $service->is_country_supported( '' ) );
		}

		// -------------------------------------------------------------------
		// is_country_supported( $country, $level ): D15 gate fix (block PR-B).
		// The country check must consult whichever provider ACTUALLY serves
		// the requested level, not the active provider unconditionally — a
		// mismatch is reachable the moment any provider's country list differs
		// from another's (Dadata_Provider::FILTER_COUNTRIES, or a plugin
		// registering a multi-country provider).
		// -------------------------------------------------------------------

		/**
		 * Stubs `apply_filters` so `Location_Provider_Registry::FILTER_PROVIDERS`
		 * returns exactly `$providers` AND `Dadata_Provider::FILTER_COUNTRIES`
		 * returns `$countries` (widening the bundled fallback's own country
		 * list beyond its `[ 'RU' ]` default) — every other tag passes its
		 * default through untouched.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers the FILTER_PROVIDERS tag should return.
		 * @param string[]                                                $countries Countries the FILTER_COUNTRIES tag should return.
		 *
		 * @return void
		 */
		private function stub_providers_and_widened_fallback_countries( array $providers, array $countries ): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) use ( $providers, $countries ) {
					if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
						return $providers;
					}
					if ( Dadata_Provider::FILTER_COUNTRIES === $tag ) {
						return $countries;
					}

					return $default;
				}
			);
		}

		/**
		 * False-suppression direction: the chosen provider serves region and
		 * settlement only, in `[ 'RU' ]`; the bundled fallback (configured, and
		 * the D15 universal tail — it serves every level) has its own country
		 * list widened to `[ 'RU', 'BY' ]`. A `BY` "address" request must be
		 * gated against the FALLBACK — the provider that will actually serve
		 * it — and therefore reported supported, even though the ACTIVE
		 * (chosen) provider alone does not cover `BY` at all.
		 */
		public function test_is_country_supported_for_a_level_consults_the_fallback_when_the_fallback_actually_serves_that_level(): void {
			$chosen = new Location_Service_Fake_Provider(
				'city-dict',
				[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
				true,
				[ 'RU' ]
			);

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_and_widened_fallback_countries( [ $chosen ], [ 'RU', 'BY' ] );
			$this->stub_dadata_token( 'tok' ); // the real bundled fallback must be configured.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			// Sanity: "address" is indeed resolved to the fallback, not $chosen.
			$this->assertInstanceOf( Dadata_Provider::class, $service->provider_for_level( Location_Record::LEVEL_ADDRESS ) );

			$this->assertTrue(
				$service->is_country_supported( 'BY', Location_Record::LEVEL_ADDRESS ),
				'BY must be reported supported for "address" — the fallback that actually serves that level covers BY'
			);
			$this->assertFalse(
				$service->is_country_supported( 'BY' ),
				'sanity: the ACTIVE (chosen) provider alone does not cover BY — proves the level-aware answer legitimately differs from the level-blind one'
			);
		}

		/**
		 * False-admission direction: the chosen provider serves region only,
		 * in `[ 'RU', 'FR' ]`; the bundled fallback is configured but its own
		 * country list is left at its (now nine-country, D15 amendment
		 * follow-up) default, which does NOT include `FR`. An `FR`
		 * "address" request must be gated against the FALLBACK — the provider
		 * that will actually serve it — and therefore reported UNSUPPORTED,
		 * even though the ACTIVE (chosen) provider alone does cover `FR`.
		 */
		public function test_is_country_supported_for_a_level_reports_unsupported_when_the_fallback_does_not_cover_it_even_if_the_active_provider_does(): void {
			$chosen = new Location_Service_Fake_Provider(
				'city-dict',
				[ Location_Record::LEVEL_REGION ],
				true,
				[ 'RU', 'FR' ]
			);

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( 'tok' ); // configured fallback, countries left at the (nine-country) default, which excludes FR.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			// Sanity: "address" is indeed resolved to the fallback, not $chosen
			// (which only declares "region").
			$this->assertInstanceOf( Dadata_Provider::class, $service->provider_for_level( Location_Record::LEVEL_ADDRESS ) );

			$this->assertFalse(
				$service->is_country_supported( 'FR', Location_Record::LEVEL_ADDRESS ),
				'FR must be reported UNSUPPORTED for "address" — the fallback that actually serves that level does not cover FR'
			);
			$this->assertTrue(
				$service->is_country_supported( 'FR' ),
				'sanity: the ACTIVE (chosen) provider alone DOES cover FR — proves the level-blind answer would have wrongly admitted it'
			);
		}

		// -------------------------------------------------------------------
		// get_supported_countries(): the union across the whole D15 chain
		// (D15 gate fix, block PR-B) — feeds Checkout_Config's `countries` block.
		// -------------------------------------------------------------------

		public function test_get_supported_countries_unions_every_level_s_resolved_provider(): void {
			$chosen = new Location_Service_Fake_Provider(
				'city-dict',
				[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
				true,
				[ 'RU' ]
			);

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_and_widened_fallback_countries( [ $chosen ], [ 'RU', 'BY' ] );
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			// region/settlement -> $chosen ([ 'RU' ]); address -> the fallback
			// ([ 'RU', 'BY' ]) — the union must be exactly { RU, BY }, deduplicated.
			$countries = $service->get_supported_countries();
			sort( $countries );

			$this->assertSame( [ 'BY', 'RU' ], $countries );
		}

		public function test_get_supported_countries_empty_when_the_chain_resolves_no_provider_for_any_level(): void {
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( [], $service->get_supported_countries() );
		}

		// -------------------------------------------------------------------
		// provider_for_level(): the D15 chain, exhaustively
		// -------------------------------------------------------------------

		public function test_provider_for_level_throws_on_an_unknown_level(): void {
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->expectException( \InvalidArgumentException::class );
			$service->provider_for_level( 'city' );
		}

		/**
		 * D15 chain-fallback tests below (Task 6, extended by Task 7): the
		 * fallback slot ({@see Location_Provider_Registry::DEFAULT_PROVIDER_ID},
		 * `'dadata'`) is now ALWAYS occupied by the real bundled
		 * {@see Dadata_Provider} — `Shipping_Plugin::includes()`
		 * unconditionally requires its class file, and the registry's own
		 * first-wins duplicate-id rule means no fake fixture can be substituted
		 * into that slot any more (it would collide and be rejected). Every
		 * test below stubs `get_option( 'woodev_location_token' )` explicitly
		 * where the scenario needs the real fallback CONFIGURED, and relies on
		 * the class-wide `get_option -> null` default (set in `setUp()`) where
		 * the scenario needs it left unconfigured.
		 */
		private function stub_dadata_token( string $token ): void {
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = null ) use ( $token ) {
					if ( 'woodev_location_active_provider' === $name ) {
						return 'city-dict';
					}
					if ( 'woodev_location_token' === $name ) {
						return $token;
					}

					return $default;
				}
			);
		}

		public function test_chain_city_only_chosen_with_configured_fallback_address_served_by_fallback(): void {
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( 'tok' ); // the real bundled fallback must be configured to serve as fallback.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertInstanceOf( Dadata_Provider::class, $registry->get_providers()['dadata'] );
			$this->assertSame( $registry->get_providers()['dadata'], $service->provider_for_level( Location_Record::LEVEL_ADDRESS ) );
		}

		public function test_chain_city_only_chosen_settlement_and_region_served_by_the_chosen_provider(): void {
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			Functions\when( 'get_option' )->justReturn( 'city-dict' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame( $chosen, $service->provider_for_level( Location_Record::LEVEL_SETTLEMENT ) );
			$this->assertSame( $chosen, $service->provider_for_level( Location_Record::LEVEL_REGION ) );
		}

		public function test_chain_fallback_unconfigured_address_returns_null(): void {
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			// active_provider = 'city-dict', but no token -> the real bundled
			// fallback (Task 7) is left unconfigured.
			$this->stub_dadata_token( '' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertNull( $service->provider_for_level( Location_Record::LEVEL_ADDRESS ) );
		}

		public function test_chain_chosen_serves_every_level_the_fallback_is_never_consulted(): void {
			$chosen = new Location_Service_Fake_Provider( 'svc-fixture', Location_Record::LEVELS, true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			Functions\when( 'get_option' )->justReturn( 'svc-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			// The real bundled DaData provider (Task 7) always occupies the
			// fallback slot too, but $chosen already answers every level, so
			// the fallback must never even be consulted — proven by identity:
			// the result is $chosen itself for every level, never the
			// registry's own 'dadata' entry (which, left unconfigured by the
			// class-wide default, would resolve to null if it WERE consulted —
			// so a wrong short-circuit would surface as a null result too,
			// not just a wrong-instance one).
			foreach ( Location_Record::LEVELS as $level ) {
				$this->assertSame( $chosen, $service->provider_for_level( $level ) );
			}
		}

		public function test_provider_for_level_filter_can_override_the_resolved_provider(): void {
			$chosen  = new Location_Service_Fake_Provider( 'svc-fixture', [ Location_Record::LEVEL_REGION ], true );
			$swapped = new Location_Service_Fake_Provider( 'swapped', [ Location_Record::LEVEL_REGION ], true );

			Functions\when( 'add_action' )->justReturn( true );
			Functions\when( 'get_option' )->justReturn( 'svc-fixture' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) use ( $chosen, $swapped ) {
					if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
						return [ $chosen ];
					}
					if ( Location_Service::FILTER_PROVIDER_FOR_LEVEL === $tag ) {
						return $swapped;
					}

					return $default;
				}
			);

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame( $swapped, $service->provider_for_level( Location_Record::LEVEL_REGION ) );
		}

		// -------------------------------------------------------------------
		// provider_for_level( $level, $country ): D15 amendment follow-up —
		// per-country suggest levels layered on top of the D15 chain. The
		// real bundled Dadata_Provider is the only provider in this codebase
		// whose get_suggest_levels() genuinely varies by country, so these
		// tests exercise it directly rather than a fake.
		// -------------------------------------------------------------------

		public function test_provider_for_level_with_country_resolves_dadata_for_address_in_an_osm_tier_country(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertInstanceOf(
				Dadata_Provider::class,
				$service->provider_for_level( Location_Record::LEVEL_ADDRESS, 'BY' )
			);
		}

		/**
		 * The Yerevan bug this whole follow-up exists to fix: without the
		 * per-country narrowing, an "address" field would attach for AM (a
		 * measured GeoNames-tier, city-only country) and silently always
		 * return nothing. `provider_for_level('address', 'AM')` must resolve
		 * to null so the field stays native instead (spec §4.7).
		 */
		public function test_provider_for_level_with_country_returns_null_for_address_in_a_geonames_tier_country(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertNull( $service->provider_for_level( Location_Record::LEVEL_ADDRESS, 'AM' ) );
			// Sanity: the SAME level, country-blind, resolves to Dadata — proving
			// the null above is the country narrowing, not a broken chain.
			$this->assertInstanceOf( Dadata_Provider::class, $service->provider_for_level( Location_Record::LEVEL_ADDRESS ) );
		}

		public function test_provider_for_level_with_country_still_resolves_settlement_in_a_geonames_tier_country(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertInstanceOf(
				Dadata_Provider::class,
				$service->provider_for_level( Location_Record::LEVEL_SETTLEMENT, 'AM' )
			);
		}

		// -------------------------------------------------------------------
		// get_levels_for_country(): the per-country map Checkout_Config's
		// `location.levels` block is built from (D15 amendment follow-up —
		// the config cannot answer for a single "current" country without
		// going stale the moment the customer changes country client-side,
		// with no round trip in that path).
		// -------------------------------------------------------------------

		public function test_get_levels_for_country_reports_all_three_true_for_ru(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame(
				[ 'region' => true, 'settlement' => true, 'address' => true ],
				$service->get_levels_for_country( 'RU' )
			);
		}

		public function test_get_levels_for_country_reports_address_false_for_a_geonames_tier_country(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame(
				[ 'region' => true, 'settlement' => true, 'address' => false ],
				$service->get_levels_for_country( 'AM' )
			);
		}

		public function test_get_levels_for_country_reports_all_false_when_the_layer_has_no_active_provider(): void {
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame(
				[ 'region' => false, 'settlement' => false, 'address' => false ],
				$service->get_levels_for_country( 'RU' )
			);
		}

		// -------------------------------------------------------------------
		// get_level_owners_for_country(): issue #352's mixed-provider-chain fix
		// (Variant A) — the sibling of get_levels_for_country() above that
		// deliberately DOES reveal which provider serves each level, so the
		// client can refuse to post a foreign-provider record into the
		// server-side chain (Customer_Location_Store::rebuild_chain()'s
		// is_within() check, issue #334, cannot prove cross-provider kinship).
		// -------------------------------------------------------------------

		public function test_get_level_owners_for_country_names_dadata_for_every_level_it_serves_in_ru(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame(
				[ 'region' => 'dadata', 'settlement' => 'dadata', 'address' => 'dadata' ],
				$service->get_level_owners_for_country( 'RU' )
			);
		}

		public function test_get_level_owners_for_country_reports_empty_string_for_an_unserved_level(): void {
			$this->stub_dadata_token( 'tok' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			// AM is GeoNames-tier — DaData serves region/settlement there, never address.
			$this->assertSame(
				[ 'region' => 'dadata', 'settlement' => 'dadata', 'address' => '' ],
				$service->get_level_owners_for_country( 'AM' )
			);
		}

		public function test_get_level_owners_for_country_reports_empty_string_for_every_level_when_the_layer_has_no_active_provider(): void {
			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame(
				[ 'region' => '', 'settlement' => '', 'address' => '' ],
				$service->get_level_owners_for_country( 'RU' )
			);
		}

		/**
		 * The mixed-chain case issue #352 exists for: a chosen provider owning
		 * region/settlement, the bundled DaData fallback owning address alone —
		 * `get_level_owners_for_country()` must name the RIGHT provider PER
		 * LEVEL, never collapse to one id for the whole country. Same fixture
		 * shape as {@see self::test_chain_city_only_chosen_with_configured_fallback_address_served_by_fallback()}.
		 */
		public function test_get_level_owners_for_country_names_different_providers_per_level_in_a_mixed_chain(): void {
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( 'tok' ); // the real bundled fallback must be configured to serve as fallback.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame(
				[ 'region' => 'city-dict', 'settlement' => 'city-dict', 'address' => 'dadata' ],
				$service->get_level_owners_for_country( 'RU' )
			);
		}

		// -------------------------------------------------------------------
		// Degradation: no active provider -> every read-side method answers
		// safely, nothing fatals
		// -------------------------------------------------------------------

		public function test_degradation_no_active_provider_every_read_method_answers_safely(): void {
			// Gate never opened at all.
			$store   = new Location_Service_Customer_Store_Probe( new Location_Service_Fake_Session() );
			$cache   = new Location_Service_Resolution_Cache_Probe( new Location_Service_Fake_Session() );
			$service = new Location_Service( Location_Provider_Registry::instance(), $store, $cache );
			$plugin  = $this->plugin();

			$this->assertFalse( $service->is_active() );
			$this->assertNull( $service->get_customer_record() );
			$this->assertFalse( $service->is_country_supported( 'RU' ) );
			$this->assertNull( $service->provider_for_level( Location_Record::LEVEL_REGION ) );
			$this->assertNull( $service->resolve_for( $plugin ) );
			$this->assertNull( $service->provider_for_list() );
			$this->assertSame( [ Location_Provider_Registry::MODE_TYPEAHEAD ], $service->get_offered_field_modes() );
			$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $service->get_field_mode() );
			$this->assertFalse( $service->owns_region_states( 'RU', [] ) );
		}

		// -------------------------------------------------------------------
		// provider_for_list(): Task 13's own D15-adjacent chain — chosen
		// provider (if it declares `list`) -> bundled fallback (never, DaData
		// has no `list` capability) -> null.
		// -------------------------------------------------------------------

		public function test_provider_for_list_resolves_the_active_provider_when_it_declares_list(): void {
			$provider = new Location_Service_Fake_List_Provider( 'list-fixture', [ 'RU' ] );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'list-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame( $provider, $service->provider_for_list() );
		}

		public function test_provider_for_list_null_when_the_active_provider_does_not_cover_the_country(): void {
			$provider = new Location_Service_Fake_List_Provider( 'list-fixture', [ 'RU' ] );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'list-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertNull( $service->provider_for_list( 'US' ) );
		}

		/**
		 * The real bundled DaData provider has NO `list` capability at all — the
		 * fallback slot is always occupied by it (Task 7), so this proves the
		 * chain correctly resolves `null` rather than wrongly falling back to a
		 * provider that cannot answer.
		 */
		public function test_provider_for_list_null_when_neither_chosen_nor_fallback_declares_list(): void {
			$chosen = new Location_Service_Fake_Provider( 'city-dict', [ Location_Record::LEVEL_REGION ], true );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $chosen ] );
			$this->stub_dadata_token( 'tok' ); // configured fallback — but still no `list` capability.

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertNull( $service->provider_for_list() );
		}

		public function test_provider_for_list_null_when_the_list_capable_provider_is_not_configured(): void {
			$provider = new Location_Service_Fake_List_Provider( 'list-fixture', [ 'RU' ], false );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'list-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertNull( $service->provider_for_list() );
		}

		// -------------------------------------------------------------------
		// get_offered_field_modes() / get_field_mode() / owns_region_states():
		// thin pass-throughs to the registry — LocationProviderRegistryTest
		// covers the actual gating logic exhaustively; these spot-check that
		// the façade genuinely delegates rather than reimplementing anything.
		// -------------------------------------------------------------------

		public function test_get_offered_field_modes_delegates_to_the_registry(): void {
			$provider = new Location_Service_Fake_List_Provider( 'list-fixture', [ 'RU' ] );

			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( [ $provider ] );
			Functions\when( 'get_option' )->justReturn( 'list-fixture' );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			$service = new Location_Service( $registry );

			$this->assertSame( $registry->get_offered_field_modes(), $service->get_offered_field_modes() );
			$this->assertContains( Location_Provider_Registry::MODE_RELATED_LIST, $service->get_offered_field_modes() );
		}

		public function test_get_field_mode_delegates_to_the_registry(): void {
			$this->activate_with_providers( [] );
			$registry = Location_Provider_Registry::instance();
			$service  = new Location_Service( $registry );

			$this->assertSame( $registry->get_field_mode(), $service->get_field_mode() );
		}

		public function test_owns_region_states_delegates_to_the_registry(): void {
			$this->activate_with_providers( [] );
			$registry = Location_Provider_Registry::instance();
			$service  = new Location_Service( $registry );

			$this->assertSame( $registry->owns_region_states( 'RU', [] ), $service->owns_region_states( 'RU', [] ) );
			$this->assertFalse( $service->owns_region_states( 'RU', [] ) );
		}

		// -------------------------------------------------------------------
		// resolve_default_country() — issue #296: checkout field -> WooCommerce
		// store setting (read via wc_get_base_location(), PR #320 review finding
		// 3 — never a raw get_option() read) -> RU, filterable on the FINAL
		// resolved value (PR #320 review finding 2). No registry/provider state
		// is touched at all, so every test here uses a bare Location_Service —
		// the REAL method body runs in every one of them (never a fixture
		// double), satisfying this task's own "a seam must have at least one
		// real-body test" rule by construction.
		// -------------------------------------------------------------------

		/**
		 * Stubs `wc_get_base_location()` the same way WooCommerce's own
		 * `wc_format_country_state_string()` would split it from the
		 * `woocommerce_default_country` option — `$raw` is the raw
		 * `COUNTRY:STATE` (or bare `COUNTRY`, or malformed) value the option
		 * would otherwise carry; `null` models the option answering nothing at
		 * all (WC's own default is `'US:CA'` in that case, but this façade must
		 * never depend on that literal).
		 *
		 * ALSO stubs `get_option( 'woocommerce_default_country' )` with the SAME
		 * raw value (PR #320 review, finding 3's own mutation coverage): a test
		 * that only stubbed `wc_get_base_location()` would pass unchanged code
		 * that still reads the OLD, un-filtered `get_option()` straight
		 * through — because that call would simply hit Brain Monkey's default
		 * `null` and coincidentally so, not because the fix is actually
		 * exercised. Stubbing both means the two code paths are fed identical
		 * data, so a test only diverges when the PRODUCTION CODE actually
		 * changed which one it reads (or how it treats the result).
		 *
		 * @param string|null $raw
		 */
		private function stub_base_location( ?string $raw ): void {
			$raw   = $raw ?? '';
			$parts = explode( ':', $raw, 2 );

			Functions\when( 'wc_get_base_location' )->justReturn(
				[
					'country' => $parts[0],
					'state'   => $parts[1] ?? '',
				]
			);
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = null ) use ( $raw ) {
					return 'woocommerce_default_country' === $name ? $raw : $default;
				}
			);
		}

		public function test_resolve_default_country_splits_the_country_from_a_country_state_option(): void {
			// The exact shape a merchant who picked a country without naming a
			// state gets (gotcha this project already paid for once on the
			// STATE half of this same option — see Checkout_Handler's own
			// handle_checkout_get_value() docblock).
			$this->stub_base_location( 'RU:*' );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country() );
		}

		public function test_resolve_default_country_accepts_a_bare_country_with_no_state_component(): void {
			$this->stub_base_location( 'KZ' );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'KZ', $service->resolve_default_country() );
		}

		/**
		 * PR #320 review, finding 3: `wc_get_base_location()` runs the store's
		 * base location through `apply_filters( 'woocommerce_get_base_location',
		 * ... )` before returning it — a multi-store/multi-vendor/geo plugin can
		 * make that answer diverge from the RAW `woocommerce_default_country`
		 * option. This must be honoured, never bypassed by reading the option
		 * directly: the two are stubbed to DISAGREE here on purpose, and the
		 * `wc_get_base_location()` answer must win.
		 */
		public function test_resolve_default_country_honors_wc_get_base_location_over_the_raw_option(): void {
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = null ) {
					// Would answer 'US' if the raw option were wrongly consulted directly.
					return 'woocommerce_default_country' === $name ? 'US:CA' : $default;
				}
			);
			Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'KZ', 'state' => '' ] );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame(
				'KZ',
				$service->resolve_default_country(),
				'wc_get_base_location() — which a multi-vendor/geo plugin may filter — must win over a raw option read'
			);
		}

		public function test_resolve_default_country_trims_and_upper_cases(): void {
			$this->stub_base_location( ' kz : some-state ' );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'KZ', $service->resolve_default_country() );
		}

		public function test_resolve_default_country_falls_back_to_ru_when_the_base_location_is_empty(): void {
			$this->stub_base_location( '' );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country() );
		}

		public function test_resolve_default_country_falls_back_to_ru_when_wc_get_base_location_answers_nothing_usable(): void {
			Functions\when( 'wc_get_base_location' )->justReturn( [] );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country() );
		}

		/**
		 * The defensive case the card explicitly calls out: the option holding
		 * ONLY WooCommerce's `*` "no state" sentinel, with no country and no
		 * colon at all. A naive un-split read would pass `'*'` straight through
		 * as if it were a country code; this must be recognised as malformed
		 * and fall through to the RU default instead of ever leaking `*` into
		 * a REST response or the checkout config block.
		 */
		public function test_resolve_default_country_never_leaks_the_wildcard_sentinel(): void {
			$this->stub_base_location( '*' );

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country() );
		}

		public function test_resolve_default_country_honors_the_filter_when_the_base_location_has_nothing_usable(): void {
			$this->stub_base_location( '' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) {
					return Location_Service::FILTER_DEFAULT_COUNTRY === $tag ? 'KZ' : $default;
				}
			);

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'KZ', $service->resolve_default_country(), 'a Kazakhstan store must be able to override the RU default' );
		}

		/**
		 * PR #320 review, finding 2: the filter runs on the FINAL resolved
		 * value, so it must be consulted — and able to WIN — even when the
		 * store's own base location already answers something usable. This is
		 * the behaviour the pre-fix code got backwards (it stubbed
		 * `apply_filters()` to prove the filter was NEVER consulted here); on a
		 * real WooCommerce install `woocommerce_default_country` is ALWAYS set
		 * (`WC_Install::create_options()`), so a filter gated the old way could
		 * never fire on a real store at all.
		 */
		public function test_resolve_default_country_the_filter_can_override_the_stores_own_base_location(): void {
			$this->stub_base_location( 'RU:*' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) {
					return Location_Service::FILTER_DEFAULT_COUNTRY === $tag ? 'KZ' : $default;
				}
			);

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame(
				'KZ',
				$service->resolve_default_country(),
				'the filter must be able to override the store\'s own base location, not only stand in for a missing one'
			);
		}

		public function test_resolve_default_country_never_returns_a_malformed_filtered_value(): void {
			$this->stub_base_location( '' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) {
					return Location_Service::FILTER_DEFAULT_COUNTRY === $tag ? 'Kazakhstan' : $default;
				}
			);

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country(), 'a misbehaving filter must never brick the fallback' );
		}

		/**
		 * The same malformed-filter guard, but with a store base location that
		 * ALREADY resolved to something valid: a garbage filter return must
		 * leave the store's own already-resolved answer alone, never force it
		 * down to the hard `RU` default the empty-base-location case falls
		 * back to.
		 */
		public function test_resolve_default_country_a_malformed_filtered_value_keeps_the_stores_own_resolved_country(): void {
			$this->stub_base_location( 'KZ' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) {
					return Location_Service::FILTER_DEFAULT_COUNTRY === $tag ? 'Kazakhstan' : $default;
				}
			);

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'KZ', $service->resolve_default_country() );
		}

		/**
		 * PR #320 review, finding 5: a filter returning a non-string (an
		 * object, in the measured reproduction) must never reach a `(string)`
		 * cast — that throws `Error: Object of class stdClass could not be
		 * converted to string`, uncaught, which would take down the checkout
		 * render entirely rather than "degrade to the hard RU default" as the
		 * old docblock promised.
		 */
		public function test_resolve_default_country_never_fatals_when_the_filter_returns_a_non_string_value(): void {
			$this->stub_base_location( '' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $tag, $default = null ) {
					return Location_Service::FILTER_DEFAULT_COUNTRY === $tag ? new \stdClass() : $default;
				}
			);

			$service = new Location_Service( Location_Provider_Registry::instance() );

			$this->assertSame( 'RU', $service->resolve_default_country() );
		}
	}
}
