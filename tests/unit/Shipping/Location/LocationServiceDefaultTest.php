<?php
/**
 * Unit tests for Location_Service::resolve_default() and the lazy trigger in
 * get_customer_record() — the store-level default-locality policy (Task 14;
 * spec D11, §4.6): `off` (nothing resolved, nothing stored), `fixed` (the
 * merchant-picked record, stored implicit on first read; an existing
 * EXPLICIT record is never touched; a provider switch strands the stored
 * record and it is re-resolved by components through the new provider on
 * first use, a failure flagging the settings surface rather than serving a
 * stale foreign-namespace record), and `geoip` (the active provider's
 * `locate( $ip )`, called once per resolution, never failure-cached).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Settings\Settings_Page_Registry;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';

	// The `geoip` policy reads WC_Geolocation::get_ip_address() — the same
	// minimal double LocationControllerTest already uses (see that stub
	// file's own docblock for the full rationale).
	if ( ! class_exists( '\\WC_Geolocation' ) ) {
		require_once dirname( __DIR__ ) . '/Rest_Api/wc-geolocation-stub.php';
	}

	/**
	 * Minimal in-memory `\WC_Session` stand-in — same shape as every other
	 * Task 4/5/6 test's own fake session (e.g. `Location_Service_Fake_Session`
	 * in `LocationServiceTest`).
	 */
	final class Default_Test_Fake_Session {

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
	 * Probe substituting a {@see Default_Test_Fake_Session} (or `null`) for the
	 * real `WC()->session` global — mirrors `Location_Service_Customer_Store_Probe`.
	 */
	final class Default_Test_Customer_Store_Probe extends Customer_Location_Store {

		private ?Default_Test_Fake_Session $fake_session;

		public function __construct( ?Default_Test_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * A suggest-only fake provider: id/countries/levels are fixed at
	 * construction, `suggest()` is driven by a closure and spied. Never
	 * overrides `locate()` — used for every `fixed`-policy test, where a
	 * `locate` capability would be a false widening of what is under test.
	 */
	class Default_Test_Fake_Provider extends Abstract_Location_Provider {

		private string $id;

		/** @var callable */
		private $suggest_callback;

		/** @var array<int, array{0: string, 1: Location_Scope}> */
		public array $suggest_calls = [];

		public function __construct( string $id, callable $suggest_callback ) {
			$this->id               = $id;
			$this->suggest_callback = $suggest_callback;
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
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			$this->suggest_calls[] = [ $query, $scope ];

			return ( $this->suggest_callback )( $query, $scope );
		}
	}

	/**
	 * A `locate`-capable fake provider — a SEPARATE class from
	 * {@see Default_Test_Fake_Provider} (never `suggest()`-only) because
	 * capability discovery is reflection-based: only a class that genuinely
	 * OVERRIDES `locate()` ever reports {@see \Woodev\Framework\Shipping\Location\Location_Provider::CAPABILITY_LOCATE}.
	 */
	class Default_Test_Fake_Locate_Provider extends Abstract_Location_Provider {

		private string $id;

		/** @var callable */
		private $locate_callback;

		/** @var array<int, string> */
		public array $locate_calls = [];

		public function __construct( string $id, callable $locate_callback ) {
			$this->id              = $id;
			$this->locate_callback = $locate_callback;
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
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function locate( string $ip ): ?Location_Record {
			$this->locate_calls[] = $ip;

			return ( $this->locate_callback )( $ip );
		}
	}

	/**
	 * A `resolve_key`-capable fake provider (issue #551) — a SEPARATE class
	 * from {@see Default_Test_Fake_Provider} for the same reflection reason
	 * {@see Default_Test_Fake_Locate_Provider}'s own docblock states: only a
	 * class that genuinely OVERRIDES `resolve_key()` ever reports
	 * {@see \Woodev\Framework\Shipping\Location\Location_Provider::CAPABILITY_RESOLVE_KEY}.
	 */
	class Default_Test_Fake_Resolve_Key_Provider extends Abstract_Location_Provider {

		private string $id;

		/** @var callable */
		private $resolve_key_callback;

		/** @var array<int, string> */
		public array $resolve_key_calls = [];

		public function __construct( string $id, callable $resolve_key_callback ) {
			$this->id                    = $id;
			$this->resolve_key_callback  = $resolve_key_callback;
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
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function resolve_key( string $key ): ?Location_Record {
			$this->resolve_key_calls[] = $key;

			return ( $this->resolve_key_callback )( $key );
		}
	}

	/**
	 * A fake provider declaring BOTH `list` and `resolve_key` (issue #551
	 * round 2) — proves the LIST-preferred derivation order: when a provider
	 * offers both, {@see Location_Service::region_ancestor_of()} must use
	 * {@see Location_Provider::CAPABILITY_LIST} and never fall through to
	 * `resolve_key()`.
	 */
	class Default_Test_Fake_List_Provider extends Abstract_Location_Provider {

		private string $id;

		/** @var callable */
		private $list_localities_callback;

		/** @var array<int, string> */
		public array $resolve_key_calls = [];

		/** @var int */
		public int $list_localities_calls = 0;

		public function __construct( string $id, callable $list_localities_callback ) {
			$this->id                        = $id;
			$this->list_localities_callback  = $list_localities_callback;
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
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function list_localities( Location_Scope $scope ): array {
			$this->list_localities_calls++;

			return ( $this->list_localities_callback )( $scope );
		}

		public function resolve_key( string $key ): ?Location_Record {
			// Never legitimately reached when list_localities() already answers
			// — see this class's own docblock. Still callable (not throwing) so
			// a test can prove it was SKIPPED via resolve_key_calls, rather than
			// getting a false pass from a BadMethodCallException short-circuit.
			$this->resolve_key_calls[] = $key;

			return null;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Service
	 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry
	 */
	final class LocationServiceDefaultTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'remove_action' )->justReturn( true );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'update_option' )->justReturn( true );
			Functions\when( 'delete_option' )->justReturn( true );
			Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			// Location_Service::is_customer_record_stale()'s rule (b) (#346) now
			// calls customer_shipping_country() -> resolve_default_country() ->
			// wc_get_base_location() on every stored-record read (including a
			// record resolve_default() itself just wrote) — 'RU' matches every
			// fixture in this file.
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
		 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers to register.
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
		 * Stubs `get_option` for the store-level settings this task adds, plus
		 * `active_provider` — every other option name falls through to the
		 * given `$default` (matching real `get_option()` semantics).
		 *
		 * @param string      $active_provider_id Active provider id.
		 * @param string|null $policy             `default_locality_policy` value, or `null` to leave unset (defaults to `off`).
		 * @param string|null $record_json        `default_locality_record` JSON value, or `null` to leave unset.
		 */
		private function stub_default_locality_options( string $active_provider_id, ?string $policy = null, ?string $record_json = null ): void {
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = null ) use ( $active_provider_id, $policy, $record_json ) {
					if ( 'woodev_location_active_provider' === $name ) {
						return $active_provider_id;
					}
					if ( 'woodev_location_default_locality_policy' === $name && null !== $policy ) {
						return $policy;
					}
					if ( 'woodev_location_default_locality_record' === $name && null !== $record_json ) {
						return $record_json;
					}

					return $default;
				}
			);
		}

		/**
		 * Opens the gate, registers `$providers`, and collects — the real
		 * `Location_Settings` handler this task's methods read/write through
		 * is built as a side effect of `collect()`.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers to register.
		 *
		 * @return Location_Provider_Registry
		 */
		private function activate( array $providers ): Location_Provider_Registry {
			Functions\when( 'add_action' )->justReturn( true );
			$this->stub_providers_filter( $providers );

			$registry = Location_Provider_Registry::instance();
			$registry->declare_needed();
			$registry->collect();

			return $registry;
		}

		private function service( Location_Provider_Registry $registry, ?Default_Test_Fake_Session $session = null ): Location_Service {
			$store = new Default_Test_Customer_Store_Probe( $session ?? new Default_Test_Fake_Session() );

			return new Location_Service( $registry, $store );
		}

		private function record(
			string $key,
			string $level = Location_Record::LEVEL_SETTLEMENT,
			array $extra = []
		): Location_Record {
			return Location_Record::from_array(
				array_merge(
					[
						'key'         => $key,
						'provider_id' => explode( ':', $key )[0],
						'level'       => $level,
						'country'     => 'RU',
						'label'       => 'Москва',
					],
					$extra
				)
			);
		}

		// -------------------------------------------------------------------
		// policy `off` — resolve_default() null, nothing stored
		// -------------------------------------------------------------------

		public function test_policy_off_resolve_default_is_null(): void {
			$registry = $this->activate( [] );
			$service  = $this->service( $registry );

			$this->assertNull( $service->resolve_default() );
		}

		/**
		 * Issue #536: {@see Location_Service::get_default_locality_policy()} is a thin
		 * pass-through to {@see Location_Provider_Registry::get_default_locality_policy()}
		 * (same facade pattern as `get_field_mode_settlement()`) — `Checkout_Config::
		 * build_location_block()` reads it to decide whether `defaultLocality` is worth
		 * sending at all.
		 */
		public function test_get_default_locality_policy_defaults_to_off_when_the_gate_is_closed(): void {
			$service = $this->service( Location_Provider_Registry::instance() );

			$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, $service->get_default_locality_policy() );
		}

		public function test_get_default_locality_policy_reflects_the_stored_setting(): void {
			$provider = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );

			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, $service->get_default_locality_policy() );
		}

		/**
		 * Mirrors the private `Customer_Location_Store::STORAGE_KEY` literal —
		 * same discipline `CustomerLocationStoreTest` itself uses (its own
		 * `SESSION_KEY`/`META_KEY` local consts), since the real constant is
		 * private and this test asserts against the session's raw contents.
		 */
		private const SESSION_STORAGE_KEY = 'woodev_customer_location';

		public function test_policy_off_get_customer_record_is_null_and_nothing_is_stored(): void {
			$registry = $this->activate( [] );
			$session  = new Default_Test_Fake_Session();
			$service  = $this->service( $registry, $session );

			$this->assertNull( $service->get_customer_record() );
			$this->assertNull( $session->get( self::SESSION_STORAGE_KEY ), 'nothing must be written to the session under the off policy' );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — served as-is (no stranding), stored implicit on
		// first read, an existing EXPLICIT record is never touched
		// -------------------------------------------------------------------

		public function test_policy_fixed_is_stored_implicit_on_first_read_when_no_record_exists(): void {
			$provider = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );

			$stored = $this->record( 'prov-a:city-1' );
			// The option stubs must be in place BEFORE activate()'s collect() runs
			// register_settings() — mirrors stub_dadata_token()'s own ordering
			// discipline in LocationServiceTest.
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$fetched = $service->get_customer_record();

			$this->assertNotNull( $fetched );
			$this->assertSame( 'prov-a:city-1', $fetched['record']->key() );
			$this->assertTrue( $fetched['implicit'], 'a resolved default must be flagged implicit' );
			$this->assertCount( 0, $provider->suggest_calls, 'the provider is not the active-namespace mismatch case — no re-resolution needed' );
		}

		public function test_policy_fixed_never_overwrites_an_existing_explicit_record(): void {
			$provider = new Default_Test_Fake_Provider( 'prov-a', fn() => [ $this->record( 'prov-a:should-not-be-used' ) ] );

			$stored = $this->record( 'prov-a:default-city' );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$explicit = $this->record( 'prov-a:customer-own-choice' );
			$service->set_customer_record( $explicit, false );

			$fetched = $service->get_customer_record();

			$this->assertSame( 'prov-a:customer-own-choice', $fetched['record']->key() );
			$this->assertFalse( $fetched['implicit'] );
			$this->assertCount( 0, $provider->suggest_calls, 'resolve_default() must never even run once a real record already exists' );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — the #536 default seeds only a SETTLEMENT record,
		// so scoping a settlement search by region silently fell back to a
		// country-wide search (issue #551, the #538 fallback's unfixed
		// sibling). get_customer_chain() now derives the missing REGION
		// ancestor from the settlement's own published ancestors, via the
		// SAME provider that produced them.
		// -------------------------------------------------------------------

		/**
		 * Stubs an in-memory `get_transient()`/`set_transient()` pair — same
		 * shape `LocationControllerTest`'s own rate-limit tests already use —
		 * for {@see Location_Service::cached_region_lookup()} (issue #551).
		 *
		 * Backed by an `\ArrayObject` rather than a plain array: PHP arrays
		 * are value types, so returning one would hand the caller a snapshot
		 * frozen at call time, unable to see the writes the closures below
		 * make on later calls. An object is a reference type — the same
		 * instance the closures close over — so the caller can still observe
		 * it live.
		 *
		 * @return \ArrayObject<string, mixed> The backing store.
		 */
		private function stub_region_ancestor_transients(): \ArrayObject {
			$store = new \ArrayObject();

			Functions\when( 'get_transient' )->alias(
				static function ( $key ) use ( $store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( $key, $value, $ttl ) use ( $store ) {
					$store[ $key ] = $value;

					return true;
				}
			);

			return $store;
		}

		public function test_fixed_default_derives_a_region_ancestor_when_the_provider_can_resolve_it(): void {
			$this->stub_region_ancestor_transients();

			$region_record = $this->record( 'prov-a:region-1', Location_Record::LEVEL_REGION );

			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( $region_record ): ?Location_Record {
					return 'prov-a:region-1' === $key ? $region_record : null;
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertArrayHasKey( Location_Record::LEVEL_REGION, $chain['records'], 'the implicit default must now yield a region in the chain' );
			$this->assertSame( 'prov-a:region-1', $chain['records'][ Location_Record::LEVEL_REGION ]->key() );
			$this->assertSame( Location_Record::LEVEL_REGION, $chain['records'][ Location_Record::LEVEL_REGION ]->level() );
			$this->assertSame( 'prov-a:city-1', $chain['records'][ Location_Record::LEVEL_SETTLEMENT ]->key() );
			$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $chain['current'], 'the derived region must never become current — it is an ancestor, not the customer\'s own pick' );
			$this->assertSame( [ 'prov-a:region-1' ], $provider->resolve_key_calls );
		}

		public function test_fixed_default_region_ancestor_derivation_is_cached_across_calls(): void {
			$this->stub_region_ancestor_transients();

			$region_record = $this->record( 'prov-a:region-1', Location_Record::LEVEL_REGION );

			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( $region_record ): ?Location_Record {
					return 'prov-a:region-1' === $key ? $region_record : null;
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$first  = $service->get_customer_chain();
			$second = $service->get_customer_chain();

			$this->assertArrayHasKey( Location_Record::LEVEL_REGION, $first['records'] );
			$this->assertArrayHasKey( Location_Record::LEVEL_REGION, $second['records'] );
			$this->assertCount( 1, $provider->resolve_key_calls, 'resolve_key() must not run again once the transient cache holds the answer — it must not run on every checkout page load' );
		}

		public function test_region_ancestor_derivation_degrades_when_the_provider_lacks_resolve_key(): void {
			// Default_Test_Fake_Provider never overrides resolve_key(), so it
			// never declares CAPABILITY_RESOLVE_KEY (reflection-based, see
			// Abstract_Location_Provider's own docblock) — this is DaData's
			// own real shape before #536; #551 must not require every
			// provider to gain this capability to keep working as before.
			$provider = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertArrayNotHasKey( Location_Record::LEVEL_REGION, $chain['records'], 'a provider without CAPABILITY_RESOLVE_KEY must degrade to today\'s behaviour — no region, no crash' );
			$this->assertSame( 'prov-a:city-1', $chain['records'][ Location_Record::LEVEL_SETTLEMENT ]->key() );
		}

		public function test_region_ancestor_derivation_never_overrides_an_explicit_customer_region_pick(): void {
			$this->stub_region_ancestor_transients();

			$region_record = $this->record( 'prov-a:region-1', Location_Record::LEVEL_REGION );

			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( $region_record ): ?Location_Record {
					// A DIFFERENT region than the one the customer actually
					// picked below — proves this is never even consulted.
					return 'prov-a:region-1' === $key ? $region_record : null;
				}
			);

			// stub_default_locality_options() is not about the default-locality
			// policy here (left 'off') — it is what makes 'prov-a' the ACTIVE
			// provider, which is_customer_record_stale()'s rule (a) requires to
			// treat these explicit picks as non-stale.
			$this->stub_default_locality_options( 'prov-a' );
			$registry = $this->activate( [ $provider ] );
			$service  = $this->service( $registry );

			$picked_region = $this->record( 'prov-a:customer-picked-region', Location_Record::LEVEL_REGION );
			$this->assertTrue( $service->set_customer_record( $picked_region, false ) );

			$settlement = $this->record( 'prov-a:city-2', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:customer-picked-region' ] ] );
			$this->assertTrue( $service->set_customer_record( $settlement, false ) );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertSame(
				'prov-a:customer-picked-region',
				$chain['records'][ Location_Record::LEVEL_REGION ]->key(),
				'an explicit customer region pick must never be replaced by a derived one'
			);
			$this->assertCount( 0, $provider->resolve_key_calls, 'derivation must never even run once the chain already carries a region' );
		}

		public function test_unpersisted_default_chain_agrees_with_the_persisted_one_on_the_derived_region(): void {
			$this->stub_region_ancestor_transients();

			$region_record = $this->record( 'prov-a:region-1', Location_Record::LEVEL_REGION );

			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( $region_record ): ?Location_Record {
					return 'prov-a:region-1' === $key ? $region_record : null;
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			// No session at all -> Customer_Location_Store::set() always fails
			// -> get_customer_chain() takes the SYNTHETIC $unpersisted_default
			// branch (review finding F1) rather than the persisted/gated one —
			// issue #551 requires both branches to agree on the derived region.
			$service = new Location_Service( $registry, new Default_Test_Customer_Store_Probe( null ) );

			$first = $service->get_customer_record();
			$this->assertNotNull( $first, 'sanity: the default still resolves without a session' );
			$this->assertTrue( $first['implicit'] );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertArrayHasKey( Location_Record::LEVEL_REGION, $chain['records'], 'the unpersisted-default branch must derive the same region the persisted branch would' );
			$this->assertSame( 'prov-a:region-1', $chain['records'][ Location_Record::LEVEL_REGION ]->key() );
			$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $chain['current'] );
		}

		public function test_region_ancestor_derivation_is_never_cached_when_resolve_key_throws(): void {
			$store = $this->stub_region_ancestor_transients();

			$attempts = 0;
			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( &$attempts ): ?Location_Record {
					$attempts++;

					throw new \RuntimeException( 'transient provider failure' );
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$first = $service->get_customer_chain();

			$this->assertNotNull( $first );
			$this->assertArrayNotHasKey( Location_Record::LEVEL_REGION, $first['records'], 'a provider failure must degrade to "no region" for this call, never crash checkout' );
			$this->assertSame( 1, $attempts );

			$second = $service->get_customer_chain();

			$this->assertNotNull( $second );
			$this->assertArrayNotHasKey( Location_Record::LEVEL_REGION, $second['records'] );
			$this->assertSame( 2, $attempts, 'a thrown resolve_key() must never be cached — the very next call must retry, not calcify into "no region"' );
			$this->assertSame( 0, $store->count(), 'nothing must ever be written to the transient cache for a thrown resolve_key()' );
		}

		/**
		 * Issue #551 round 2: {@see Location_Service::region_ancestor_of()}
		 * must prefer {@see Location_Provider::CAPABILITY_LIST} over
		 * `CAPABILITY_RESOLVE_KEY` when a provider declares both — one cached
		 * dictionary fetch rather than a per-key resolution.
		 */
		public function test_region_ancestor_derivation_prefers_list_over_resolve_key_when_the_provider_declares_both(): void {
			$this->stub_region_ancestor_transients();

			$region_record = $this->record( 'prov-a:region-1', Location_Record::LEVEL_REGION );

			$provider = new Default_Test_Fake_List_Provider(
				'prov-a',
				static function ( Location_Scope $scope ) use ( $region_record ): array {
					return Location_Record::LEVEL_REGION === $scope->level() ? [ $region_record ] : [];
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-1' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertArrayHasKey( Location_Record::LEVEL_REGION, $chain['records'] );
			$this->assertSame( 'prov-a:region-1', $chain['records'][ Location_Record::LEVEL_REGION ]->key() );
			$this->assertSame( 1, $provider->list_localities_calls, 'list_localities() must be used when the provider declares CAPABILITY_LIST' );
			$this->assertSame( [], $provider->resolve_key_calls, 'resolve_key() must never even be tried once list_localities() already answered' );
		}

		/**
		 * Issue #553's own shape, reproduced WITHOUT a live provider: a
		 * provider's `resolve_key()` answers a region-level record for a
		 * DIFFERENT key than the one it was asked to resolve (exactly what the
		 * unfixed test-CDEK fixture did — `resolve_key('...:r81')` answering
		 * Spain's `...:r482` instead). The ancestor-membership guard in
		 * {@see Location_Service::region_ancestor_of()} must reject any
		 * candidate whose own `key()` is not one of the settlement's published
		 * `ancestors()`, regardless of which derivation path produced it —
		 * this is the regression test round 1's own suite could not have
		 * caught, since round 1's mock provider always answered the region it
		 * was actually asked about.
		 */
		public function test_region_ancestor_derivation_rejects_a_region_the_settlement_does_not_descend_from(): void {
			$this->stub_region_ancestor_transients();

			$wrong_region = $this->record( 'prov-a:region-482', Location_Record::LEVEL_REGION, [ 'label' => 'Галисия' ] );

			$provider = new Default_Test_Fake_Resolve_Key_Provider(
				'prov-a',
				static function ( string $key ) use ( $wrong_region ): ?Location_Record {
					// Wrong on purpose — never matches the requested $key,
					// mirroring the #553 provider bug this guard defends
					// against.
					return $wrong_region;
				}
			);

			$stored = $this->record( 'prov-a:city-1', Location_Record::LEVEL_SETTLEMENT, [ 'ancestors' => [ 'prov-a:region-81' ] ] );
			$this->stub_default_locality_options( 'prov-a', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED, wp_json_encode( $stored->to_array() ) );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$chain = $service->get_customer_chain();

			$this->assertNotNull( $chain );
			$this->assertArrayNotHasKey(
				Location_Record::LEVEL_REGION,
				$chain['records'],
				'a region the settlement does not actually descend from must never be accepted, even when the provider itself hands one back — issue #553'
			);
			$this->assertSame( 'prov-a:city-1', $chain['records'][ Location_Record::LEVEL_SETTLEMENT ]->key() );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — provider switch strands the stored default
		// (spec §4.6/D15 amendment)
		// -------------------------------------------------------------------

		public function test_fixed_default_re_resolves_through_the_new_provider_when_stranded(): void {
			$stale_provider  = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );
			$captured        = null;
			$active_provider = new Default_Test_Fake_Provider(
				'prov-b',
				function ( string $query, Location_Scope $scope ) use ( &$captured ) {
					$captured = [ $query, $scope ];

					return [ $this->record( 'prov-b:new-city', Location_Record::LEVEL_SETTLEMENT ) ];
				}
			);

			$stale = $this->record( 'prov-a:old-city', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Московская область', 'type' => 'обл' ] ] );

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $stale_provider, $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNotNull( $result );
			$this->assertSame( 'prov-b:new-city', $result['record']->key(), 'the stale foreign-namespace record must never be served' );
			$this->assertTrue( $result['implicit'] );
			$this->assertCount( 1, $active_provider->suggest_calls, 'the new provider must be queried exactly once' );
			$this->assertCount( 0, $stale_provider->suggest_calls, 'the OLD provider is never re-consulted — it is no longer in the chain' );

			// The re-resolution must be scoped correctly: RU/settlement, WITH a
			// parent constraint (the stale record's own region) — never a bare
			// country-wide scope (review finding F3's own mutant: reverting
			// scope_for_reresolution() to Location_Scope::for_country() alone
			// would still pass a country()/level() assertion, which is why this
			// also pins has_parent()/parent_components() explicitly).
			[ $query, $scope ] = $captured;
			$this->assertSame( 'Москва', $query, 'the query text is the stale record\'s own label' );
			$this->assertSame( 'RU', $scope->country() );
			$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $scope->level() );
			$this->assertTrue( $scope->has_parent(), 'the re-resolution scope must carry a parent constraint, never a bare country-wide search' );
			$this->assertSame(
				[ 'region' => [ 'name' => 'Московская область', 'type' => 'обл' ] ],
				$scope->parent_components(),
				'the parent constraint must be the stale record\'s OWN region'
			);

			// Review finding F2(a): a customer-facing getter must never mutate
			// store settings — the merchant's stored record is left exactly as
			// it was, regardless of how many anonymous requests just resolved
			// a value for themselves. (The informational needs-repick flag
			// this assertion used to also pin was removed entirely by issue
			// #406 — see class-location-provider-registry.php's own removal
			// note — so there is nothing left to assert here.)
			$still_stale = $registry->get_default_locality_record();
			$this->assertNotNull( $still_stale );
			$this->assertSame( 'prov-a:old-city', $still_stale->key(), 'a customer-facing getter must never REPLACE the merchant\'s stored default' );
		}

		/**
		 * Review finding F2(a)'s direct regression test, replacing the OLD
		 * "only the first customer pays" behavior this PR's earlier revision
		 * relied on: that optimization persisted the re-resolved record onto
		 * the MERCHANT's own setting from inside a getter the public,
		 * unauthenticated `/location/suggest` route reaches for every
		 * anonymous guest — i.e. the vulnerability itself. Two SEPARATE
		 * customers (fresh sessions, a fresh registry rebuilt from the SAME
		 * unchanged option each time, mirroring an entirely new PHP process
		 * per guest request) must EACH independently pay the re-resolution
		 * cost, and the merchant's stored option must stay byte-for-byte the
		 * stale value throughout — nothing a guest does ever advances it.
		 */
		public function test_fixed_default_re_resolution_is_never_persisted_to_settings_and_repeats_per_customer(): void {
			$options = [];

			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = null ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'update_option' )->alias(
				static function ( $name, $value ) use ( &$options ) {
					$options[ $name ] = $value;

					return true;
				}
			);

			$active_provider = new Default_Test_Fake_Provider(
				'prov-b',
				fn() => [ $this->record( 'prov-b:new-city', Location_Record::LEVEL_SETTLEMENT ) ]
			);

			$stale = $this->record( 'prov-a:old-city', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Московская область', 'type' => 'обл' ] ] );

			$options['woodev_location_active_provider']         = 'prov-b';
			$options['woodev_location_default_locality_policy'] = Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
			$options['woodev_location_default_locality_record'] = wp_json_encode( $stale->to_array() );

			// Customer 1: a fresh registry, a fresh (empty) session.
			$registry_1 = $this->activate( [ $active_provider ] );
			$customer_1 = $this->service( $registry_1 );
			$result_1   = $customer_1->get_customer_record();

			$this->assertSame( 'prov-b:new-city', $result_1['record']->key() );
			$this->assertCount( 1, $active_provider->suggest_calls );
			$this->assertSame(
				wp_json_encode( $stale->to_array() ),
				$options['woodev_location_default_locality_record'],
				'the merchant option must be UNCHANGED after the first customer\'s resolution'
			);

			// Customer 2: a genuinely SEPARATE guest — a new registry rebuilt
			// from the (still unchanged) option, and a new session. Mirrors an
			// entirely new PHP process the way the old "first customer pays"
			// test did — the difference under this fix is what it now proves:
			// the provider IS queried again, because nothing was persisted for
			// customer 2 to find.
			Location_Provider_Registry::instance()->reset_for_tests();
			Settings_Page_Registry::instance()->reset_for_tests();
			$registry_2 = $this->activate( [ $active_provider ] );
			$customer_2 = $this->service( $registry_2 );
			$result_2   = $customer_2->get_customer_record();

			$this->assertSame( 'prov-b:new-city', $result_2['record']->key() );
			$this->assertCount( 2, $active_provider->suggest_calls, 'a second, separate customer must pay its OWN re-resolution cost — mutant: persisting the re-resolved record to the merchant option' );
			$this->assertSame(
				wp_json_encode( $stale->to_array() ),
				$options['woodev_location_default_locality_record'],
				'the merchant option must STILL be unchanged — an anonymous customer never re-points it'
			);
		}

		public function test_fixed_default_re_resolution_failure_treats_default_as_unset(): void {
			$stale_provider  = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );
			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [] ); // no match at all

			$stale = $this->record( 'prov-a:old-city', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Московская область', 'type' => 'обл' ] ] );

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $stale_provider, $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNull( $result, 'a failed re-resolution must treat the default as unset' );

			// The stale record itself must be left UNTOUCHED — a later manual fix
			// (or the provider coming back with a match) is not foreclosed. No
			// needs-repick assertion here any more (review finding F2(a)): the
			// flag is no longer written from this customer-facing path at all —
			// see test_fixed_default_status_note_reflects_a_stranded_record_live()
			// in LocationProviderRegistryTest for the LIVE equivalent this
			// class's settings page now surfaces instead.
			$still_stale = $registry->get_default_locality_record();
			$this->assertSame( 'prov-a:old-city', $still_stale->key() );
		}

		/**
		 * No-failure-caching, extended to the provider-switch case (mirrors the
		 * `geoip` policy's own "no failure caching" discipline): a re-resolution
		 * failure is retried on the very next call, never sticky.
		 */
		public function test_fixed_default_re_resolution_failure_is_retried_not_cached(): void {
			$stale_provider  = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );
			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [] );

			$stale = $this->record( 'prov-a:old-city', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Московская область', 'type' => 'обл' ] ] );

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $stale_provider, $active_provider ] );

			$service = $this->service( $registry );

			$this->assertNull( $service->get_customer_record() );
			$this->assertNull( $service->get_customer_record() );

			$this->assertCount( 2, $active_provider->suggest_calls, 'nothing was persisted after a failure, so every read retries' );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — F2(b)/F3: never accept an ambiguous `$records[0]`
		// -------------------------------------------------------------------

		/**
		 * The motivating F2 scenario itself: two DIFFERENT places share the
		 * exact same display label at the exact same level (e.g. "Мирный" in
		 * Архангельская область AND in Якутия) — blindly taking `$records[0]`
		 * would silently re-point the merchant to whichever one the provider
		 * happens to list first. Mutant this pins: reverting
		 * self::unambiguous_match()'s call site back to `$records[0] ?? null`.
		 */
		public function test_fixed_default_an_ambiguous_match_is_refused_not_guessed(): void {
			$wrong_place  = $this->record( 'prov-b:mirny-arkhangelsk', Location_Record::LEVEL_SETTLEMENT, [ 'label' => 'Мирный' ] );
			$also_matches = $this->record( 'prov-b:mirny-yakutia', Location_Record::LEVEL_SETTLEMENT, [ 'label' => 'Мирный' ] );

			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [ $wrong_place, $also_matches ] );

			$stale = $this->record(
				'prov-a:mirny',
				Location_Record::LEVEL_SETTLEMENT,
				[ 'label' => 'Мирный', 'region' => [ 'name' => 'Архангельская область', 'type' => 'обл' ] ]
			);

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNull( $result, 'two equally-labeled candidates must refuse, never pick either one' );
			$this->assertCount( 1, $active_provider->suggest_calls );

			$still_stale = $registry->get_default_locality_record();
			$this->assertSame( 'prov-a:mirny', $still_stale->key(), 'the ambiguous match must never replace the stale record' );
		}

		/**
		 * Position-independence: the FIRST returned candidate does not match
		 * the stale record at all, and the SECOND one does. A blind
		 * `$records[0] ?? null` would return the wrong candidate; the correct
		 * behavior finds the one genuine match regardless of its position.
		 */
		public function test_fixed_default_a_unique_match_is_found_even_when_not_first(): void {
			$unrelated    = $this->record( 'prov-b:unrelated', Location_Record::LEVEL_SETTLEMENT, [ 'label' => 'Совершенно другой город' ] );
			$exact_match  = $this->record( 'prov-b:new-city', Location_Record::LEVEL_SETTLEMENT, [ 'label' => 'Мирный' ] );

			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [ $unrelated, $exact_match ] );

			$stale = $this->record(
				'prov-a:mirny',
				Location_Record::LEVEL_SETTLEMENT,
				[ 'label' => 'Мирный', 'region' => [ 'name' => 'Архангельская область', 'type' => 'обл' ] ]
			);

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNotNull( $result );
			$this->assertSame( 'prov-b:new-city', $result['record']->key(), 'the ONE candidate that actually matches must be found regardless of its position in the array' );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — F2: an empty parent-component set must refuse
		// rather than silently degrade to a country-wide search
		// -------------------------------------------------------------------

		public function test_fixed_default_refuses_to_re_resolve_a_settlement_with_no_parent_component(): void {
			// No 'region'/'district' — parent_components_above() returns [].
			$stale = $this->record( 'prov-a:old-city', Location_Record::LEVEL_SETTLEMENT );

			$active_provider = new Default_Test_Fake_Provider(
				'prov-b',
				fn() => [ $this->record( 'prov-b:new-city', Location_Record::LEVEL_SETTLEMENT ) ]
			);

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNull( $result, 'no parent component to narrow by must refuse, never silently search the whole country' );
			$this->assertCount( 0, $active_provider->suggest_calls, 'the provider must never even be queried — mutant: dropping the empty-parent-components guard' );
		}

		// -------------------------------------------------------------------
		// policy `fixed` — F3 test gap: the '' === $query refusal already
		// existed in code but nothing pinned it
		// -------------------------------------------------------------------

		public function test_fixed_default_refuses_to_re_resolve_with_an_empty_query(): void {
			// No label AND no settlement component to fall back to —
			// query_text_for() returns ''.
			$stale = Location_Record::from_array(
				[
					'key'         => 'prov-a:old-city',
					'provider_id' => 'prov-a',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [ $this->record( 'prov-b:new-city' ) ] );

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNull( $result, 'an empty derived query must refuse — a blank string is never a legitimate search key' );
			$this->assertCount( 0, $active_provider->suggest_calls, 'mutant: deleting the \'\' === $query refusal would let this call the provider' );
		}

		// -------------------------------------------------------------------
		// F1: a persist failure (no session — the guest REST scenario) must
		// still serve the resolved value, and must not re-trigger resolution
		// within the same request
		// -------------------------------------------------------------------

		/**
		 * Mirrors the public `/location/suggest` REST path's own worst case
		 * (review finding F1): `Customer_Location_Store::set()` degrades to
		 * `false` when no session exists yet (gotcha
		 * `guest-session-write-needs-the-cart-cookie`) — a `null` fake session,
		 * unlike every other test in this class. Mutant this pins: a store
		 * whose `set()` fails re-triggering resolve_default() (an extra
		 * `locate()` call) on a second read within the SAME request.
		 */
		public function test_a_persist_failure_still_serves_the_resolved_value_and_is_not_re_triggered(): void {
			$located  = $this->record( 'geo:by-ip' );
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => $located );

			$this->stub_default_locality_options( 'geo', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			\WC_Geolocation::$address = '203.0.113.5';

			// No session at all — Customer_Location_Store::set() degrades to
			// false, exactly the REST guest scenario F1 describes.
			$store   = new Default_Test_Customer_Store_Probe( null );
			$service = new Location_Service( $registry, $store );

			$first  = $service->get_customer_record();
			$second = $service->get_customer_record();

			$this->assertNotNull( $first, 'a failed persist must still serve the resolved value for THIS call' );
			$this->assertSame( 'geo:by-ip', $first['record']->key() );
			$this->assertTrue( $first['implicit'] );

			$this->assertNotNull( $second, 'the memoized value must still be served on a second call within the same request' );
			$this->assertSame( 'geo:by-ip', $second['record']->key() );

			$this->assertCount( 1, $provider->locate_calls, 'a persist failure must never re-trigger resolution within the same request' );

			\WC_Geolocation::$address = null;
		}

		// -------------------------------------------------------------------
		// policy `geoip` — locate( $ip ) called ONCE per resolution, stored
		// implicit; failure/null -> no store write, next call may retry
		// -------------------------------------------------------------------

		public function test_policy_geoip_locate_is_called_once_and_result_is_stored_implicit(): void {
			$located  = $this->record( 'geo:by-ip' );
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => $located );

			$this->stub_default_locality_options( 'geo', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			\WC_Geolocation::$address = '203.0.113.5';

			$service = $this->service( $registry );

			$this->assertSame( 0, count( $provider->locate_calls ) );
			$fetched = $service->get_customer_record();

			$this->assertNotNull( $fetched );
			$this->assertSame( 'geo:by-ip', $fetched['record']->key() );
			$this->assertTrue( $fetched['implicit'] );
			$this->assertSame( [ '203.0.113.5' ], $provider->locate_calls );

			\WC_Geolocation::$address = null;
		}

		/**
		 * Pins the LAZY claim against its own neighbouring value: a second
		 * read within the same customer session must NOT call locate() again
		 * (1, not 2) — the record persisted by the first call is what the
		 * second call finds and short-circuits on.
		 */
		public function test_policy_geoip_is_not_resolved_again_once_a_record_exists(): void {
			$located  = $this->record( 'geo:by-ip' );
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => $located );

			$this->stub_default_locality_options( 'geo', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			\WC_Geolocation::$address = '203.0.113.5';

			$service = $this->service( $registry );
			$service->get_customer_record();
			$service->get_customer_record();

			$this->assertCount( 1, $provider->locate_calls, 'exactly once, not twice — the lazy trigger must not fire on every read' );

			\WC_Geolocation::$address = null;
		}

		public function test_policy_geoip_locate_miss_stores_nothing_and_the_next_call_retries(): void {
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => null );

			$this->stub_default_locality_options( 'geo', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			\WC_Geolocation::$address = '203.0.113.5';

			$service = $this->service( $registry );

			$this->assertNull( $service->get_customer_record() );
			$this->assertNull( $service->get_customer_record() );

			$this->assertCount( 2, $provider->locate_calls, 'no failure caching — geo-IP is transient, every call retries' );

			\WC_Geolocation::$address = null;
		}

		public function test_policy_geoip_is_offered_only_when_the_active_provider_has_locate(): void {
			// A provider that does NOT declare `locate` at all.
			$provider = new Default_Test_Fake_Provider( 'no-locate', static fn() => [] );

			// The store setting is stuck at 'geoip' from a PREVIOUS provider that
			// did support it — clamps to 'off' now, exactly like get_field_mode_region()
			// clamps a stale 'related-list' value.
			$this->stub_default_locality_options( 'no-locate', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			$this->assertSame(
				[ Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED ],
				$registry->get_offered_default_locality_policies()
			);
			$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, $registry->get_default_locality_policy() );

			$service = $this->service( $registry );
			$this->assertNull( $service->resolve_default(), 'a clamped-away geoip policy must resolve nothing' );
		}

		public function test_policy_geoip_is_offered_when_the_active_provider_has_locate(): void {
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => null );

			$this->stub_default_locality_options( 'geo' );
			$registry = $this->activate( [ $provider ] );

			$this->assertSame(
				[
					Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF,
					Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
					Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP,
				],
				$registry->get_offered_default_locality_policies()
			);
		}

		// -------------------------------------------------------------------
		// promote_customer_record_to_explicit() — issue #518
		//
		// A confirmed pickup point answers the "please choose your locality"
		// prompt the checkout's address lock represents, even though the
		// locality field was never touched (operator decision, s92). The
		// locality itself does not change; only its standing does.
		// -------------------------------------------------------------------

		public function test_promote_turns_a_geoip_guess_into_the_customer_s_own_record(): void {
			$located  = $this->record( 'geo:by-ip' );
			$provider = new Default_Test_Fake_Locate_Provider( 'geo', static fn() => $located );

			$this->stub_default_locality_options( 'geo', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP );
			$registry = $this->activate( [ $provider ] );

			\WC_Geolocation::$address = '203.0.113.5';

			$service = $this->service( $registry );

			$this->assertTrue( $service->get_customer_record()['implicit'], 'precondition: the seeded default is a guess' );

			$this->assertTrue( $service->promote_customer_record_to_explicit() );

			$promoted = $service->get_customer_record();

			$this->assertFalse( $promoted['implicit'], 'the guess must stop being a guess' );
			$this->assertSame( 'geo:by-ip', $promoted['record']->key(), 'and it must still be the SAME locality' );

			\WC_Geolocation::$address = null;
		}

		public function test_promote_is_a_no_op_when_the_record_is_already_the_customer_s_own(): void {
			// The control: without it, a promotion that simply reported success
			// unconditionally would pass the test above.
			$provider = new Default_Test_Fake_Provider( 'fake', static fn() => [] );

			$this->stub_default_locality_options( 'fake' );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );
			$service->set_customer_record( $this->record( 'fake:picked' ) );

			$this->assertFalse( $service->promote_customer_record_to_explicit() );
			$this->assertFalse( $service->get_customer_record()['implicit'] );
			$this->assertSame( 'fake:picked', $service->get_customer_record()['record']->key() );
		}

		public function test_promote_is_a_no_op_when_there_is_no_record_at_all(): void {
			$provider = new Default_Test_Fake_Provider( 'fake', static fn() => [] );

			$this->stub_default_locality_options( 'fake', Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF );
			$registry = $this->activate( [ $provider ] );

			$service = $this->service( $registry );

			$this->assertNull( $service->get_customer_record(), 'precondition: nothing is stored and nothing resolves' );
			$this->assertFalse( $service->promote_customer_record_to_explicit() );
		}

		public function test_promote_refuses_a_record_the_gate_itself_refuses(): void {
			// Promotion is gated on get_customer_record(), not on the raw store.
			// A record whose provider is no longer registered reads as ABSENT
			// everywhere today (#346/#333/#352); promoting it would leave a
			// guess the customer never made waiting to come back as an explicit
			// choice the day that provider is switched back on.
			$provider = new Default_Test_Fake_Provider( 'fake', static fn() => [] );

			$this->stub_default_locality_options( 'fake' );
			$registry = $this->activate( [ $provider ] );

			$store   = new Default_Test_Customer_Store_Probe( new Default_Test_Fake_Session() );
			$service = new Location_Service( $registry, $store );

			$store->set( $this->record( 'gone:orphan' ), true );

			$this->assertNull( $service->get_customer_record(), 'precondition: the gate refuses this record' );
			$this->assertFalse( $service->promote_customer_record_to_explicit() );
			$this->assertTrue( $store->get_chain()['implicit'], 'the refused record must still be flagged a guess' );
		}
	}
}
