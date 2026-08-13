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

			// The re-resolution must be scoped correctly: RU/settlement, with the
			// stale record's own region as the parent constraint.
			[ $query, $scope ] = $captured;
			$this->assertSame( 'Москва', $query, 'the query text is the stale record\'s own label' );
			$this->assertSame( 'RU', $scope->country() );
			$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $scope->level() );

			$this->assertFalse( $registry->get_default_locality_needs_repick(), 'a successful re-resolution must clear the flag' );

			// The re-resolved record REPLACES the stale one in the merchant setting —
			// "on first use" (spec §4.6): only the FIRST customer pays this cost.
			$replaced = $registry->get_default_locality_record();
			$this->assertNotNull( $replaced );
			$this->assertSame( 'prov-b:new-city', $replaced->key() );
		}

		/**
		 * Proves "on first use" literally: a SECOND customer (a brand-new
		 * session — nothing customer-specific carries over) hitting a FRESH
		 * registry rebuilt from the (now updated) persisted option must get the
		 * fast path — the provider is never re-queried a second time.
		 */
		public function test_fixed_default_only_the_first_customer_pays_the_re_resolution_cost(): void {
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
				fn() => [ $this->record( 'prov-b:new-city' ) ]
			);

			$stale = $this->record( 'prov-a:old-city' );

			$options['woodev_location_active_provider']         = 'prov-b';
			$options['woodev_location_default_locality_policy'] = Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
			$options['woodev_location_default_locality_record'] = wp_json_encode( $stale->to_array() );

			// Request 1: a fresh registry, a fresh (empty) customer.
			$registry_1 = $this->activate( [ $active_provider ] );
			$customer_1 = $this->service( $registry_1 );
			$result_1   = $customer_1->get_customer_record();

			$this->assertSame( 'prov-b:new-city', $result_1['record']->key() );
			$this->assertCount( 1, $active_provider->suggest_calls );

			// Request 2: simulate an entirely new PHP process — reset the
			// singleton (activate() alone is a no-op the second time around:
			// collect() guards on "already collected this request") and rebuild
			// it, reading get_option() from scratch. The fake options table
			// above now holds request 1's WRITE.
			Location_Provider_Registry::instance()->reset_for_tests();
			Settings_Page_Registry::instance()->reset_for_tests();
			$registry_2 = $this->activate( [ $active_provider ] );
			$customer_2 = $this->service( $registry_2 ); // brand-new session — a different customer.
			$result_2   = $customer_2->get_customer_record();

			$this->assertSame( 'prov-b:new-city', $result_2['record']->key(), 'the second customer must see the ALREADY re-resolved record' );
			$this->assertCount( 1, $active_provider->suggest_calls, 'the provider must NOT be queried a second time' );
		}

		public function test_fixed_default_re_resolution_failure_treats_default_as_unset_and_flags_repick(): void {
			$stale_provider  = new Default_Test_Fake_Provider( 'prov-a', static fn() => [] );
			$active_provider = new Default_Test_Fake_Provider( 'prov-b', static fn() => [] ); // no match at all

			$stale = $this->record( 'prov-a:old-city' );

			$this->stub_default_locality_options(
				'prov-b',
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				wp_json_encode( $stale->to_array() )
			);
			$registry = $this->activate( [ $stale_provider, $active_provider ] );

			$service = $this->service( $registry );
			$result  = $service->get_customer_record();

			$this->assertNull( $result, 'a failed re-resolution must treat the default as unset' );
			$this->assertTrue( $registry->get_default_locality_needs_repick(), 'the settings surface must be flagged as needing re-picking' );

			// The stale record itself must be left UNTOUCHED — a later manual fix
			// (or the provider coming back with a match) is not foreclosed.
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

			$stale = $this->record( 'prov-a:old-city' );

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
			// did support it — clamps to 'off' now, exactly like get_field_mode()
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
	}
}
