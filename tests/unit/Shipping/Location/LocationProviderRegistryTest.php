<?php
/**
 * Unit tests for Location_Provider_Registry — the activation gate (spec §4.1:
 * inert until a plugin declares "I need a location provider"), provider
 * collection on `init` (never `is_checkout()`-gated), first-wins duplicate
 * rejection, the unknown-active-id fallback chain, the `woodev_location_providers`
 * / `woodev_location_active_provider` filters, and the provider-declared-settings
 * seam merging the ACTIVE provider's fields onto the shared SP-1 surface.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Customer_Location_Store;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-composite-settings-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';

/**
 * A minimal, fully-parameterized fake provider — every test builds exactly the
 * shape it needs (id, name, and optionally declared settings fields) rather
 * than a family of narrow single-purpose fixtures.
 */
class Fake_Location_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;

	/** @var array<string, array<string, mixed>> */
	private array $settings_fields;

	public function __construct( string $id, string $name, array $settings_fields = [] ) {
		$this->id              = $id;
		$this->name             = $name;
		$this->settings_fields = $settings_fields;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function get_settings_fields(): array {
		return $this->settings_fields;
	}
}

/**
 * A `list`-capable fake provider (Task 13) — {@see Fake_Location_Provider} never
 * overrides {@see \Woodev\Framework\Shipping\Location\Abstract_Location_Provider::list_localities()},
 * so its reflection-derived capability set never contains `list`; this fixture
 * exists specifically to exercise the `related-list`/`ajax-select2` mode gate and
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::inject_related_list_states()},
 * neither of which the DaData-shaped fake above can ever reach.
 */
class Fake_List_Location_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;

	/** @var string[] */
	private array $countries;

	/** @var array<string, \Woodev\Framework\Shipping\Location\Location_Record[]> country => region-level records. */
	private array $region_records_by_country;

	private bool $configured;

	/** @var int Spy: how many times list_localities() was actually called. */
	public int $list_localities_calls = 0;

	/**
	 * @param array<string, \Woodev\Framework\Shipping\Location\Location_Record[]> $region_records_by_country country => region-level records
	 *                                                                                                          {@see self::list_localities()} returns
	 *                                                                                                          for a region-level scope.
	 */
	public function __construct(
		string $id,
		string $name,
		array $countries,
		array $region_records_by_country = [],
		bool $configured = true
	) {
		$this->id                       = $id;
		$this->name                     = $name;
		$this->countries                = $countries;
		$this->region_records_by_country = $region_records_by_country;
		$this->configured                = $configured;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
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
		++$this->list_localities_calls;

		if ( Location_Record::LEVEL_REGION !== $scope->level() ) {
			return [];
		}

		return $this->region_records_by_country[ $scope->country() ] ?? [];
	}
}

/**
 * A `locate`-capable fake provider (Task 14) — {@see Fake_Location_Provider}
 * never overrides `locate()`, so its reflection-derived capability set never
 * contains `locate`; this fixture exists specifically to exercise the
 * `geoip` default-locality policy's OFFERED-options gate.
 */
class Fake_Locate_Location_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;

	public function __construct( string $id, string $name ) {
		$this->id   = $id;
		$this->name = $name;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function locate( string $ip ): ?Location_Record {
		return null;
	}
}

/**
 * A fake provider that serves `address` ONLY for one specific country — exists
 * to prove the DaData-fields wide `show_if` condition (#375/#377) is evaluated
 * for the STORE'S OWN country via {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()},
 * never a country-blind union: "serves address" genuinely varies by country
 * for a real provider (the bundled DaData provider itself is the reference
 * case — RU/BY/KZ/UZ but not AM/AZ/KG/TJ/TM), so a country-blind computation
 * would answer a different question than the one the merchant is actually
 * configuring for (coordinator correction, s82).
 */
class Fake_Country_Scoped_Location_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;

	/** @var string[] */
	private array $countries;

	private string $address_country;

	/**
	 * @param string[] $countries Countries this provider covers at all.
	 */
	public function __construct( string $id, string $name, array $countries, string $address_country ) {
		$this->id              = $id;
		$this->name            = $name;
		$this->countries       = $countries;
		$this->address_country = strtoupper( $address_country );
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_countries(): array {
		return $this->countries;
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_ADDRESS ];
	}

	protected function narrow_suggest_levels_for_country( array $levels, string $country ): array {
		if ( strtoupper( trim( $country ) ) === $this->address_country ) {
			return $levels;
		}

		return array_values( array_diff( $levels, [ Location_Record::LEVEL_ADDRESS ] ) );
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry
 * @covers \Woodev\Framework\Shipping\Location\Location_Settings
 */
final class LocationProviderRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Harmless generically-stubbed WP primitives every code path under test
		// touches; individual tests re-stub add_action/apply_filters/get_option
		// with assertions of their own where the test is actually ABOUT them.
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		// #375/#377: register_settings() now builds a `show_if` condition for the
		// bundled default provider's own fields via Location_Service::resolve_default_country(),
		// which reads wc_get_base_location() — a harmless 'RU' default every test in
		// this file gets unless it re-stubs this itself (matching the same blanket-
		// default convention LocationServiceTest/LocationServiceDefaultTest already use).
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Stubs `apply_filters` so `Location_Provider_Registry::FILTER_PROVIDERS`
	 * returns exactly `$providers` and every other tag passes its default
	 * through untouched (matching real WordPress with nothing hooked).
	 *
	 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers Providers the filter should return.
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
	 * Stubs `get_option` so the stored `active_provider` value is `$id` and
	 * every other option name falls through to its own caller-supplied default
	 * (matching real `get_option()` semantics) — used where a test needs a
	 * specific NON-default provider id to become active (Task 7 made the
	 * DEFAULT_PROVIDER_ID id, `'dadata'`, belong to the real bundled provider,
	 * so fixtures needing a different active id can no longer rely on the
	 * default-id fallback alone).
	 *
	 * @param string $id Provider id the `active_provider` setting should resolve to.
	 * @return void
	 */
	private function stub_active_provider_option( string $id ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $id ) {
				return 'woodev_location_active_provider' === $name ? $id : $default;
			}
		);
	}

	// -------------------------------------------------------------------------
	// Activation gate — closed
	// -------------------------------------------------------------------------

	public function test_gate_closed_registry_is_fully_inert(): void {
		// The strongest form of "inert": add_action() must never even be called —
		// add_hooks() only runs from declare_needed(), which nothing calls here.
		Functions\expect( 'add_action' )->never();

		$registry = Location_Provider_Registry::instance();

		$this->assertFalse( $registry->is_needed() );
		$this->assertSame( [], $registry->get_providers() );
		$this->assertNull( $registry->get_active_provider() );
		$this->assertNull( $registry->get_settings_handler() );
		$this->assertFalse( Settings_Page_Registry::instance()->has_providers() );
	}

	public function test_gate_closed_has_provider_is_false_for_any_id(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertFalse( $registry->has_provider( Location_Provider_Registry::DEFAULT_PROVIDER_ID ) );
	}

	// -------------------------------------------------------------------------
	// Activation gate — opened: collection hooked on `init`, never `is_checkout()`
	// -------------------------------------------------------------------------

	/**
	 * Pins BOTH that `init` (priority 20) is the hook declare_needed() wires, AND
	 * that collect() itself never calls is_checkout(): this test never stubs
	 * is_checkout() at all, so if collection were ever wrapped in an
	 * `is_checkout()` guard, invoking the captured callback below would fatal on
	 * an undefined function instead of quietly passing.
	 */
	public function test_declare_needed_hooks_collection_on_init_at_priority_20(): void {
		$captured = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$captured ) {
				$captured[] = [ $hook, $priority ];

				return true;
			}
		);
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();

		$this->assertContains( [ 'init', 20 ], $captured );

		// Simulate `init` firing — no is_checkout() stub exists anywhere in this
		// test, so a checkout guard added later would fatal here, failing the test.
		$registry->collect();

		$this->assertTrue( $registry->is_needed() );

		// Settings registration now lives at Shipping_Settings_Tab (design S9) — the
		// location layer hands its handler over instead of registering a tab of its
		// own (see register_settings()). Prove the handoff happened: once a shipping
		// plugin declares itself, the «Локация» section appears, backed by the exact
		// handler collect() just built, even with zero providers.
		Shipping_Settings_Tab::instance()->declare_shipping_plugin();
		$section_ids = array_map(
			static function ( $section ) {
				return $section->get_id();
			},
			Shipping_Settings_Tab::instance()->build_sections()
		);
		$this->assertContains( 'location', $section_ids, 'the location handler must be handed to Shipping_Settings_Tab once the gate opens, even with zero providers' );
	}

	public function test_declare_needed_is_idempotent_across_multiple_plugins(): void {
		$hook_calls = 0;
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hook_calls ) {
				if ( 'init' === $hook ) {
					++$hook_calls;
				}

				return true;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->declare_needed();
		$registry->declare_needed();

		$this->assertSame( 1, $hook_calls, 'a second/third declaration must not re-hook init' );
	}

	// -------------------------------------------------------------------------
	// P1 finding: Customer_Location_Store::handle_wp_login() is documented as
	// the intended wp_login callback but nothing ever called add_action() for
	// it (gotcha built-on-both-sides-with-no-caller-in-the-middle). Wired here,
	// reusing this registry's own add_hooks() $hooked guard, so it registers
	// exactly ONCE regardless of how many shipping plugins are active (each
	// constructing its own objects and independently calling declare_needed()).
	// -------------------------------------------------------------------------

	public function test_declare_needed_hooks_the_login_migration_on_wp_login(): void {
		$captured = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$captured ) {
				$captured[] = [ $hook, $callback, $priority, $args ];

				return true;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();

		$login_hooks = array_values( array_filter( $captured, static fn( $entry ) => 'wp_login' === $entry[0] ) );

		$this->assertCount( 1, $login_hooks, 'exactly one wp_login registration for a single declaring plugin' );
		$this->assertIsArray( $login_hooks[0][1], 'the callback must be an [ object, method ] pair' );
		$this->assertInstanceOf( Customer_Location_Store::class, $login_hooks[0][1][0] );
		$this->assertSame( 'handle_wp_login', $login_hooks[0][1][1] );
		$this->assertSame( 10, $login_hooks[0][2], 'priority must match the 2-arg wp_login signature' );
		$this->assertSame( 2, $login_hooks[0][3], 'wp_login passes ( $user_login, $user ) — both must be requested' );
	}

	public function test_declare_needed_hooks_wp_login_exactly_once_for_two_plugins(): void {
		// Simulates two shipping plugins active simultaneously, each constructing
		// its own objects and independently calling declare_needed() — WordPress
		// dedups add_action() by object hash + method, so two DISTINCT
		// Customer_Location_Store instances would NOT dedupe on their own; the
		// dedup must come from this registry's own $hooked guard instead.
		$login_hook_calls = 0;
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$login_hook_calls ) {
				if ( 'wp_login' === $hook ) {
					++$login_hook_calls;
				}

				return true;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed(); // plugin A
		$registry->declare_needed(); // plugin B

		$this->assertSame( 1, $login_hook_calls, 'two declaring plugins must still register the login migration only once' );
	}

	public function test_gate_open_collects_the_bundled_and_filter_registered_providers(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ new Fake_Location_Provider( 'acme', 'ACME' ) ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertTrue( $registry->has_provider( 'acme' ) );
		$this->assertArrayHasKey( 'acme', $registry->get_providers() );
	}

	public function test_collect_is_a_no_op_once_already_collected(): void {
		$calls = 0;
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( &$calls ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
					++$calls;

					return [];
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();
		$registry->collect();
		$registry->collect();

		$this->assertSame( 1, $calls );
	}

	// -------------------------------------------------------------------------
	// The bundled-provider seam (Task 7 plugs in here)
	// -------------------------------------------------------------------------

	public function test_default_provider_id_is_dadata(): void {
		$this->assertSame( 'dadata', Location_Provider_Registry::DEFAULT_PROVIDER_ID );
	}

	/**
	 * Pins the exact FQCN Task 7's DaData provider must use — a value-mutant
	 * guard on the seam itself, since {@see Location_Provider_Registry::collect()}
	 * silently registers nothing for a class that does not (yet) exist.
	 */
	public function test_bundled_provider_seam_names_the_future_dadata_provider_fqcn(): void {
		$method = new \ReflectionMethod( Location_Provider_Registry::class, 'bundled_provider_classes' );
		// Version-guarded on purpose, in both directions (gotcha
		// `reflection-setaccessible-version-guard`). Reflection became
		// accessible-by-default in 8.1, so WITHOUT this call the test passes on a
		// modern local PHP and fails the 7.4/8.0 CI matrix; and setAccessible() is
		// deprecated as of 8.5, so calling it UNCONDITIONALLY raises a deprecation
		// that `failOnRisky="true"` turns into a local failure. The repo's supported
		// span (7.4 … current) contains both hazards, so the guard is the only form
		// that is green everywhere.
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertContains(
			'\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Provider',
			$method->invoke( null )
		);
	}

	public function test_the_bundled_dadata_provider_registers_once_the_class_exists(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		// Task 7 shipped the class this seam names — collection now registers
		// it automatically (superseding the earlier "does not exist yet, skip
		// without erroring" assertion this test pinned during Task 3; the
		// class_exists() skip path itself is still exercised by
		// bundled_provider_classes() being safe to carry a not-yet-existing
		// FQCN, which is no longer constructible now that the class is real).
		$this->assertTrue( $registry->has_provider( 'dadata' ) );
		$this->assertCount( 1, $registry->get_providers() );
	}

	// -------------------------------------------------------------------------
	// Duplicate ids — first registration wins
	// -------------------------------------------------------------------------

	public function test_duplicate_provider_id_the_first_registration_wins(): void {
		$first  = new Fake_Location_Provider( 'acme', 'First ACME' );
		$second = new Fake_Location_Provider( 'acme', 'Second ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $first, $second ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( \Mockery::type( 'string' ), \Mockery::pattern( '/acme/' ), '2.0.2' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( $first, $registry->get_providers()['acme'] );
		// The bundled DaData provider also registers (Task 7) alongside 'acme' —
		// the duplicate-id assertion under test concerns 'acme' specifically.
		$this->assertCount( 2, $registry->get_providers() );
		$this->assertTrue( $registry->has_provider( 'dadata' ) );
	}

	// -------------------------------------------------------------------------
	// A filter entry that is not a Location_Provider — rejected, others survive
	// -------------------------------------------------------------------------

	public function test_an_invalid_filter_entry_is_rejected_and_does_not_poison_the_rest(): void {
		$good_a = new Fake_Location_Provider( 'a', 'A' );
		$good_b = new Fake_Location_Provider( 'b', 'B' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $good_a, 'not-a-provider', $good_b ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( \Mockery::type( 'string' ), \Mockery::pattern( '/does not implement Location_Provider/' ), '2.0.2' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertTrue( $registry->has_provider( 'a' ) );
		$this->assertTrue( $registry->has_provider( 'b' ) );
		// Plus the bundled DaData provider (Task 7).
		$this->assertTrue( $registry->has_provider( 'dadata' ) );
		$this->assertCount( 3, $registry->get_providers() );
	}

	// -------------------------------------------------------------------------
	// Active provider resolution
	// -------------------------------------------------------------------------

	public function test_active_provider_resolves_from_the_stored_setting_value(): void {
		$acme  = new Fake_Location_Provider( 'acme', 'ACME' );
		$other = new Fake_Location_Provider( 'other', 'Other' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $acme, $other ] );
		Functions\when( 'get_option' )->justReturn( 'other' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( $other, $registry->get_active_provider() );
	}

	public function test_unset_active_setting_falls_back_to_the_default_provider_id(): void {
		// The bundled DaData provider IS DEFAULT_PROVIDER_ID now (Task 7) — no
		// fake stand-in needed/possible any more: a fake sharing its id would
		// collide with the real bundled one (first-wins silently drops the fake).
		$acme = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $acme ] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( 'dadata', $registry->get_active_provider()->get_id() );
	}

	public function test_unknown_active_id_falls_back_to_the_default_provider_when_it_is_registered(): void {
		$acme = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $acme ] );
		// Stored value names a provider nothing is (or was ever) registered under.
		Functions\when( 'get_option' )->justReturn( 'ghost-id' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( 'dadata', $registry->get_active_provider()->get_id() );
	}

	/**
	 * Originally pinned "resolution lands on null when even the default id has
	 * nothing registered" (Task 3, written before Task 7 shipped the bundled
	 * class this registry's own `bundled_provider_classes()` names). That exact
	 * scenario is no longer constructible through the public `collect()` path:
	 * the bundled DaData provider now ALWAYS registers under
	 * `DEFAULT_PROVIDER_ID` whenever the gate is open, since
	 * `Shipping_Plugin::includes()` unconditionally `require_once`'s its class
	 * file. This test now pins the opposite, now-true outcome instead of the
	 * no-longer-reachable one — the `null` branch inside
	 * `get_active_provider()` remains real code (reachable in principle, e.g. if
	 * the bundled require were ever removed) but has no fixture left able to
	 * exercise it.
	 */
	public function test_unknown_active_id_falls_back_to_the_real_bundled_provider(): void {
		$acme = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $acme ] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( 'dadata', $registry->get_active_provider()->get_id() );
	}

	public function test_active_provider_filter_can_swap_the_resolved_instance(): void {
		$acme    = new Fake_Location_Provider( 'acme', 'ACME' );
		$swapped = new Fake_Location_Provider( 'swapped', 'Swapped' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $acme, $swapped ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
					return [ $acme ];
				}
				if ( Location_Provider_Registry::FILTER_ACTIVE_PROVIDER === $tag ) {
					return $swapped;
				}

				return $default;
			}
		);
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( $swapped, $registry->get_active_provider() );
	}

	public function test_get_active_provider_is_null_before_init_has_collected_even_when_the_gate_is_open(): void {
		Functions\when( 'add_action' )->justReturn( true );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		// collect() deliberately not called — mirrors "gate open, init has not fired yet".

		$this->assertNull( $registry->get_active_provider() );
	}

	// -------------------------------------------------------------------------
	// Provider-declared settings seam: EVERY provider's fields, show_if-gated
	// (#375/#377 — no longer merged for the active provider only)
	// -------------------------------------------------------------------------

	/**
	 * Every registered provider's fields are now ALWAYS registered on the
	 * handler (#375/#377), each carrying its OWN `show_if` condition on
	 * `active_provider` — the active provider's field is visible right now
	 * (its condition matches the CURRENT stored value), the inactive
	 * provider's field is registered too but its condition does not match.
	 * Field ids deliberately avoid `token`/`clean_secret` — those collide
	 * with the REAL bundled Dadata_Provider, which registers unconditionally
	 * in every test in this file, and that collision is covered by its own
	 * dedicated test below.
	 */
	public function test_active_providers_declared_settings_are_rendered_on_the_surface(): void {
		// A distinct id (not DEFAULT_PROVIDER_ID — that belongs to the now-real
		// bundled Dadata_Provider, Task 7) made active via an explicit stored
		// setting value rather than the default-id fallback.
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[
				'active_fixture_key' => [
					'name'      => 'Active fixture key',
					'type'      => \Woodev_Setting::TYPE_STRING,
					'sensitive' => true,
					'default'   => '',
				],
			]
		);
		$inactive = new Fake_Location_Provider(
			'inactive',
			'Inactive',
			[
				'inactive_secret' => [
					'name'    => 'Inactive secret',
					'type'    => \Woodev_Setting::TYPE_STRING,
					'default' => '',
				],
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active, $inactive ] );
		$this->stub_active_provider_option( 'active-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$handler = $registry->get_settings_handler();
		$this->assertNotNull( $handler );

		$active_setting = $handler->get_setting( 'active_fixture_key' );
		$this->assertNotNull( $active_setting, 'the active provider field must be REGISTERED' );
		$this->assertSame(
			[ 'setting' => Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, 'value' => 'active-fixture' ],
			$active_setting->get_show_if_conditions(),
			'gated by a plain equality show_if on its own provider id'
		);

		$inactive_setting = $handler->get_setting( 'inactive_secret' );
		$this->assertNotNull( $inactive_setting, 'a NON-active provider field must STILL be registered (#375/#377 — dynamic without saving)' );
		$this->assertSame(
			[ 'setting' => Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, 'value' => 'inactive' ],
			$inactive_setting->get_show_if_conditions()
		);

		// The whole point: evaluated against the CURRENT active_provider value,
		// the active fixture's field is visible and the inactive one is not.
		$submitted = [ Location_Provider_Registry::SETTING_ACTIVE_PROVIDER => 'active-fixture' ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $active_setting->get_show_if_conditions(), $submitted ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $inactive_setting->get_show_if_conditions(), $submitted ) );
	}

	public function test_provider_field_marked_sensitive_gets_a_password_control(): void {
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[
				'active_fixture_key' => [
					'name'      => 'Active fixture key',
					'type'      => \Woodev_Setting::TYPE_STRING,
					'sensitive' => true,
					'default'   => '',
				],
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active ] );
		$this->stub_active_provider_option( 'active-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( 'active_fixture_key' );

		$this->assertTrue( $setting->is_sensitive() );
		$this->assertSame( \Woodev_Control::TYPE_PASSWORD, $setting->get_control()->get_type() );
	}

	public function test_active_provider_setting_options_list_every_registered_provider(): void {
		$a = new Fake_Location_Provider( 'a', 'Alpha' );
		$b = new Fake_Location_Provider( 'b', 'Beta' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $a, $b ] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER );

		// The bundled DaData provider (Task 7) is registered FIRST (before the
		// filter-supplied candidates), so it leads the options list.
		$this->assertSame( [ 'dadata' => 'DaData', 'a' => 'Alpha', 'b' => 'Beta' ], $setting->get_options() );
		$this->assertSame( \Woodev_Control::TYPE_SELECT, $setting->get_control()->get_type() );
	}

	/**
	 * D4 (secrets discipline): a provider-declared field never leaks into
	 * anything client-visible just by being registered — this handler only
	 * exposes it through the normal `Woodev_Setting` object, whose `sensitive`
	 * flag is exactly what the REST settings layer (tested elsewhere) already
	 * masks on. Pinned here as: the raw field VALUE is never present as a bare
	 * scalar anywhere reachable except through the setting object itself.
	 */
	public function test_a_sensitive_provider_field_is_flagged_sensitive_not_silently_plain(): void {
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[
				'secret' => [
					'name'      => 'Secret',
					'type'      => \Woodev_Setting::TYPE_STRING,
					'sensitive' => true,
					'default'   => '',
				],
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active ] );
		$this->stub_active_provider_option( 'active-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertTrue( $registry->get_settings_handler()->get_setting( 'secret' )->is_sensitive() );
	}

	// -------------------------------------------------------------------------
	// Field-id collisions across two DIFFERENT providers (#375/#377)
	// -------------------------------------------------------------------------

	/**
	 * The shared option namespace (`woodev_location_*`) means two UNRELATED
	 * providers can now declare the same field id — first-registered wins and
	 * the conflict is reported, mirroring {@see Location_Provider_Registry::inject_related_list_states()}'s
	 * own duplicate-LABEL discipline and {@see Location_Provider_Registry::register_provider()}'s
	 * own duplicate-ID discipline.
	 */
	public function test_duplicate_field_id_across_two_providers_the_first_registration_wins_and_warns(): void {
		$first = new Fake_Location_Provider(
			'first',
			'First',
			[ 'shared_key' => [ 'name' => 'From first', 'type' => \Woodev_Setting::TYPE_STRING, 'default' => 'from-first' ] ]
		);
		$second = new Fake_Location_Provider(
			'second',
			'Second',
			[ 'shared_key' => [ 'name' => 'From second', 'type' => \Woodev_Setting::TYPE_STRING, 'default' => 'from-second' ] ]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $first, $second ] );
		$this->stub_active_provider_option( 'first' );

		Functions\expect( '_doing_it_wrong' )->once();

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( 'shared_key' );

		$this->assertNotNull( $setting );
		$this->assertSame( 'from-first', $setting->get_default(), 'the FIRST registration wins the collision' );
		$this->assertSame(
			[ 'setting' => Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, 'value' => 'first' ],
			$setting->get_show_if_conditions(),
			'the winning field keeps ITS OWN provider\'s show_if, not the loser\'s'
		);
	}

	// -------------------------------------------------------------------------
	// is_configured() === false must NOT remove a provider from the select
	// -------------------------------------------------------------------------

	public function test_an_unconfigured_provider_is_not_removed_from_the_active_provider_select(): void {
		$unconfigured = new Fake_Location_Provider(
			'unconfigured',
			'Unconfigured',
			[
				'required_key' => [
					'name'     => 'Required key',
					'type'     => \Woodev_Setting::TYPE_STRING,
					'required' => true,
					'default'  => '',
				],
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $unconfigured ] );
		$this->stub_active_provider_option( 'unconfigured' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertFalse( $unconfigured->is_configured(), 'sanity: the fixture really is unconfigured (a required field with no value)' );

		$provider_select = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER );
		$this->assertArrayHasKey(
			'unconfigured',
			$provider_select->get_options(),
			'is_configured() === false must NOT remove a provider from the select (#375)'
		);
		$this->assertInstanceOf( Fake_Location_Provider::class, $registry->get_active_provider(), 'an unconfigured provider can still be resolved as ACTIVE' );
	}

	// -------------------------------------------------------------------------
	// Saving must not wipe an inactive provider's stored keys (#375/#377)
	// -------------------------------------------------------------------------

	/**
	 * `filter_visible_values()` strips a hidden field from the SUBMITTED map
	 * only — it never calls `update_option()`/`delete_option()` itself, so an
	 * inactive provider's ALREADY-STORED value is never touched by a save that
	 * merely doesn't include it. This is the entire safety of the "register
	 * every provider's fields" approach (#375/#377).
	 */
	public function test_hidden_provider_field_is_stripped_from_the_submitted_map_only(): void {
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[ 'active_fixture_key' => [ 'name' => 'Active key', 'type' => \Woodev_Setting::TYPE_STRING, 'default' => '' ] ]
		);
		$inactive = new Fake_Location_Provider(
			'inactive',
			'Inactive',
			[ 'inactive_secret' => [ 'name' => 'Inactive secret', 'type' => \Woodev_Setting::TYPE_STRING, 'default' => '' ] ]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active, $inactive ] );
		$this->stub_active_provider_option( 'active-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$submitted = [
			Location_Provider_Registry::SETTING_ACTIVE_PROVIDER => 'active-fixture',
			'active_fixture_key' => 'new-active-value',
			// A crafted/stale submission still carrying the HIDDEN field — this
			// is exactly what a naive "merge and save whatever was posted"
			// implementation would wipe.
			'inactive_secret'    => 'attempted-overwrite',
		];

		$filtered = $registry->get_settings_handler()->filter_visible_values( $submitted );

		$this->assertArrayHasKey( 'active_fixture_key', $filtered, 'a VISIBLE field survives the filter' );
		$this->assertArrayNotHasKey( 'inactive_secret', $filtered, 'a HIDDEN field is stripped from what gets persisted' );
	}

	// -------------------------------------------------------------------------
	// The bundled default provider's own fields (#377) — the WIDE `in`
	// condition, evaluated for the STORE's own country
	// -------------------------------------------------------------------------

	/**
	 * The real bundled Dadata_Provider registers under `DEFAULT_PROVIDER_ID`
	 * ('dadata') in every test in this file (autoloaded, `class_exists()`
	 * always true) — so `token`/`clean_secret` always exist on the handler,
	 * and their `show_if` is always the WIDE `in` condition, never the plain
	 * equality one every other provider's field gets.
	 */
	public function test_dadata_own_fields_get_the_wide_in_show_if_condition(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$token = $registry->get_settings_handler()->get_setting( 'token' );
		$this->assertNotNull( $token );

		$conditions = $token->get_show_if_conditions();
		$this->assertSame( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, $conditions['setting'] );
		$this->assertSame( 'in', $conditions['operator'] );
		$this->assertContains( 'dadata', $conditions['value'], 'DaData is unconditionally in its own wide list' );
	}

	/**
	 * A carrier that DOES serve `address` FOR THE STORE'S OWN COUNTRY must NOT
	 * appear in DaData's wide `in` list — DaData's keys stay hidden while such
	 * a carrier is active, because its OWN address suggestions already work
	 * and DaData is not needed as a fallback (operator's #377 reasoning, its
	 * converse).
	 */
	public function test_dadata_fields_hide_a_carrier_that_serves_address_for_the_store_country(): void {
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'KZ', 'state' => '' ] );

		$kz_carrier = new Fake_Country_Scoped_Location_Provider( 'kz-carrier', 'KZ Carrier', [ 'KZ' ], 'KZ' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $kz_carrier ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$conditions = $registry->get_settings_handler()->get_setting( 'token' )->get_show_if_conditions();

		$this->assertNotContains(
			'kz-carrier',
			$conditions['value'],
			'kz-carrier serves address for the store\'s own country (KZ) — DaData keys must stay hidden while it is active'
		);
	}

	/**
	 * THE COUNTRY-SENSITIVITY PROOF (coordinator correction, s82): the SAME
	 * provider that serves `address` for KZ does NOT serve it for RU — so
	 * whether its id lands in DaData's wide list depends on the STORE's own
	 * country, never a country-blind "does this provider EVER serve address"
	 * union. Same fixture as the test above, opposite store country.
	 */
	public function test_dadata_fields_show_the_same_carrier_for_a_different_store_country_it_does_not_serve_address_in(): void {
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		$kz_carrier = new Fake_Country_Scoped_Location_Provider( 'kz-carrier', 'KZ Carrier', [ 'KZ', 'RU' ], 'KZ' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $kz_carrier ] );
		$this->stub_active_provider_option( 'kz-carrier' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$conditions = $registry->get_settings_handler()->get_setting( 'token' )->get_show_if_conditions();

		$this->assertContains(
			'kz-carrier',
			$conditions['value'],
			'the same carrier does NOT serve address for the store\'s own country (RU) — DaData keys must be reachable as the fallback'
		);

		$submitted = [ Location_Provider_Registry::SETTING_ACTIVE_PROVIDER => 'kz-carrier' ];
		$this->assertTrue(
			\Woodev_Setting::evaluate_conditions( $conditions, $submitted ),
			'the DaData key fields must actually SHOW while kz-carrier is active, for this store country'
		);
	}

	/**
	 * A carrier NOT even covering the store's country (no `get_countries()`
	 * overlap) trivially cannot serve address there either — it lands in
	 * DaData's wide list too, via the SAME {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_serves_level()}
	 * predicate (its own country-coverage gate), not a second hand-rolled check.
	 */
	public function test_dadata_fields_show_a_carrier_that_does_not_cover_the_store_country_at_all(): void {
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		$uncovering_carrier = new Fake_Country_Scoped_Location_Provider( 'elsewhere-carrier', 'Elsewhere', [ 'KZ' ], 'KZ' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $uncovering_carrier ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$conditions = $registry->get_settings_handler()->get_setting( 'token' )->get_show_if_conditions();

		$this->assertContains( 'elsewhere-carrier', $conditions['value'] );
	}

	/**
	 * THE "test-list" SCENARIO (coordinator correction, s82): a list-only
	 * carrier that NEVER serves address, for ANY country — {@see Fake_Location_Provider}
	 * itself, matching the rig's own `test-list` fixture shape exactly. The
	 * #375 target table's "test-list shows nothing" is about test-list's OWN
	 * fields and its OWN notice — NOT about DaData's fallback keys, which
	 * correctly stay reachable here (the converse of the operator's #377
	 * reasoning: this carrier brings no addresses, so DaData is the only
	 * thing that can, and its keys must be enterable).
	 */
	public function test_dadata_fields_show_while_a_test_list_shaped_provider_is_active(): void {
		$list_only = new Fake_Location_Provider( 'test-list-like', 'List-only', [] );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $list_only ] );
		$this->stub_active_provider_option( 'test-list-like' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$conditions = $registry->get_settings_handler()->get_setting( 'token' )->get_show_if_conditions();
		$this->assertContains( 'test-list-like', $conditions['value'] );

		$submitted = [ Location_Provider_Registry::SETTING_ACTIVE_PROVIDER => 'test-list-like' ];
		$this->assertTrue(
			\Woodev_Setting::evaluate_conditions( $conditions, $submitted ),
			'DaData key fields must actually SHOW while a test-list-shaped (list-only, never-address) provider is active'
		);
	}

	// -------------------------------------------------------------------------
	// reset_for_tests()
	// -------------------------------------------------------------------------

	public function test_reset_for_tests_returns_the_registry_to_the_closed_gate(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ new Fake_Location_Provider( 'acme', 'ACME' ) ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();
		$this->assertTrue( $registry->has_provider( 'acme' ) );

		$registry->reset_for_tests();

		$fresh = Location_Provider_Registry::instance();
		$this->assertFalse( $fresh->is_needed() );
		$this->assertSame( [], $fresh->get_providers() );
	}

	// -------------------------------------------------------------------------
	// Task 13 (spec D7) — offered field modes = f(active provider capabilities)
	// -------------------------------------------------------------------------

	public function test_offered_field_modes_typeahead_only_for_the_real_dadata_provider(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] ); // active provider falls back to the bundled DaData default.

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( [ Location_Provider_Registry::MODE_TYPEAHEAD ], $registry->get_offered_field_modes() );
	}

	public function test_offered_field_modes_include_related_list_and_ajax_select2_for_a_list_capable_active_provider(): void {
		$list_provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ] );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $list_provider ] );
		$this->stub_active_provider_option( 'list-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame(
			[ Location_Provider_Registry::MODE_TYPEAHEAD, Location_Provider_Registry::MODE_RELATED_LIST, Location_Provider_Registry::MODE_AJAX_SELECT2 ],
			$registry->get_offered_field_modes()
		);
	}

	public function test_offered_field_modes_typeahead_only_when_no_active_provider_resolves(): void {
		// Gate open, but nothing collected yet — get_active_provider() is null.
		Functions\when( 'add_action' )->justReturn( true );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();

		$this->assertSame( [ Location_Provider_Registry::MODE_TYPEAHEAD ], $registry->get_offered_field_modes() );
	}

	/**
	 * The settings surface's own `field_mode` select must offer EXACTLY the
	 * same options {@see Location_Provider_Registry::get_offered_field_modes()}
	 * computes — proving the registration-time computation (which cannot call
	 * `get_active_provider()` — the settings handler does not exist yet, see
	 * that private helper's own docblock) agrees with the read-time one.
	 */
	public function test_field_mode_setting_options_match_the_list_capable_active_providers_offered_modes(): void {
		$list_provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ] );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $list_provider ] );
		$this->stub_active_provider_option( 'list-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_FIELD_MODE );

		$this->assertSame(
			array_keys( $setting->get_options() ),
			$registry->get_offered_field_modes()
		);
		$this->assertSame( \Woodev_Control::TYPE_SELECT, $setting->get_control()->get_type() );
	}

	/**
	 * PR #304 review finding 6: `register_settings()` used to resolve the
	 * active provider by looking `$this->providers[ $id ]` up DIRECTLY,
	 * entirely skipping {@see Location_Provider_Registry::FILTER_ACTIVE_PROVIDER}
	 * — the same filter {@see Location_Provider_Registry::get_active_provider()}
	 * (and therefore the runtime's {@see Location_Provider_Registry::get_offered_field_modes()})
	 * always applies. The stored id here names `acme`, which has no `list`
	 * capability; the filter swaps the RESOLVED provider to a list-capable one
	 * regardless. Before the fix, the settings page — built once, at
	 * collection time, straight from the unfiltered id — would offer ONLY
	 * `typeahead`, while the runtime (which DOES apply the filter) would
	 * accept `related-list`/`ajax-select2` too: an admin could never even see
	 * the option the runtime would actually honour. The mutant this pins:
	 * reverting `register_settings()` to a direct `$this->providers[ $id ]`
	 * lookup makes this assertion fail (the settings page would offer only
	 * `[ 'typeahead' ]`, diverging from `get_offered_field_modes()`).
	 */
	public function test_settings_page_field_mode_options_reflect_the_active_provider_filter_not_the_raw_stored_id(): void {
		$acme          = new Fake_Location_Provider( 'acme', 'ACME' ); // no `list` capability.
		$list_provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ] );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $acme, $list_provider ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
					return [ $acme, $list_provider ];
				}
				if ( Location_Provider_Registry::FILTER_ACTIVE_PROVIDER === $tag ) {
					return $list_provider; // swaps the resolved provider regardless of the stored id.
				}

				return $default;
			}
		);
		Functions\when( 'get_option' )->justReturn( 'acme' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_FIELD_MODE );

		$this->assertSame(
			$registry->get_offered_field_modes(),
			array_keys( $setting->get_options() ),
			'the settings page must offer exactly what the runtime would accept — both go through the SAME resolution now'
		);
		$this->assertContains(
			Location_Provider_Registry::MODE_RELATED_LIST,
			array_keys( $setting->get_options() ),
			'the filter-swapped list-capable provider must be reflected on the settings page, not the raw stored id alone'
		);
	}

	// -------------------------------------------------------------------------
	// Task 13 — get_field_mode(): default + clamp against the offered set
	// -------------------------------------------------------------------------

	public function test_field_mode_defaults_to_typeahead(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $registry->get_field_mode() );
	}

	public function test_field_mode_returns_the_stored_value_when_it_is_offered(): void {
		$list_provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ] );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $list_provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'list-fixture';
				}
				if ( 'woodev_location_field_mode' === $name ) {
					return Location_Provider_Registry::MODE_RELATED_LIST;
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::MODE_RELATED_LIST, $registry->get_field_mode() );
	}

	/**
	 * A stored `related-list` value from BEFORE a provider switch to a
	 * non-`list` provider (e.g. back to DaData) must never be served as-is —
	 * clamps to typeahead, the one mode every provider can always back.
	 */
	public function test_field_mode_clamps_a_stored_value_the_current_active_provider_no_longer_supports(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] ); // active provider falls back to DaData (no `list`).
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return 'woodev_location_field_mode' === $name ? Location_Provider_Registry::MODE_RELATED_LIST : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $registry->get_field_mode() );
	}

	public function test_field_mode_is_typeahead_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $registry->get_field_mode() );
	}

	// -------------------------------------------------------------------------
	// Task 13 — inject_related_list_states(): the related-list region renderer
	// -------------------------------------------------------------------------

	private function region_record( string $provider_id, string $native_id, string $country, string $name ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $provider_id . ':' . $native_id,
				'provider_id' => $provider_id,
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => $country,
				'region'      => [ 'name' => $name, 'type' => 'область' ],
				'label'       => $name,
			]
		);
	}

	/**
	 * Activates a `list`-capable fixture provider as ACTIVE, with `field_mode`
	 * stored as `related-list`, and collects the registry — the common setup
	 * every `inject_related_list_states()` test below needs.
	 */
	private function activate_related_list_mode( Fake_List_Location_Provider $provider ): Location_Provider_Registry {
		Functions\when( 'add_action' )->justReturn( true );
		// Real WC() is never loaded in unit tests — mirrors the real
		// `wc_strtoupper()` (`mb_strtoupper()` when available, Cyrillic-aware,
		// see `wc-formatting-functions.php`) so the label -> key transform
		// `inject_related_list_states()` now applies (PR #304 review finding 2)
		// exercises the same semantics the real function would.
		Functions\when( 'wc_strtoupper' )->alias(
			static function ( $string ) {
				return mb_strtoupper( (string) $string );
			}
		);
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $provider ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return $provider->get_id();
				}
				if ( 'woodev_location_field_mode' === $name ) {
					return Location_Provider_Registry::MODE_RELATED_LIST;
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		return $registry;
	}

	/**
	 * s71 correction: the injected VALUE is the record's human-readable LABEL,
	 * never its `provider_id:native_id` key — a `billing_state`/`shipping_state`
	 * value is permanent order data, and a provider-namespaced key renders as
	 * raw garbage in the customer's formatted address the instant this injector
	 * is not present (rig measurement, see the method's own docblock).
	 *
	 * PR #304 review finding 2: the KEY is `wc_strtoupper( $label )`, never the
	 * bare (possibly mixed-case) label — `WC_Checkout::validate_posted_data()`
	 * uppercases whatever the customer posted before matching it against the
	 * registered keys, so a mixed-case key used bare would never match its own
	 * uppercased submission again on the next render.
	 */
	public function test_inject_injects_regions_keyed_by_their_own_uppercased_label(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'МОСКОВСКАЯ ОБЛАСТЬ' => 'Московская область' ], $states['RU'] );
	}

	/**
	 * Two records legitimately colliding on the same label within one country is
	 * a real ambiguity — WooCommerce's state array is keyed by value, so only
	 * ONE option could ever be selected under that text. The first (provider's
	 * own enumeration order) wins; the collision is reported exactly once, never
	 * silently dropped and never disambiguated with a synthetic suffix that
	 * would itself leak into `billing_state`.
	 */
	public function test_inject_keeps_the_first_of_a_duplicate_label_and_warns_once(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[
				'RU' => [
					$this->region_record( 'list-fixture', 'mo-1', 'RU', 'Московская область' ),
					$this->region_record( 'list-fixture', 'mo-2', 'RU', 'Московская область' ),
				],
			]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'МОСКОВСКАЯ ОБЛАСТЬ' => 'Московская область' ], $states['RU'], 'the first record wins; the duplicate is dropped, not merged or suffixed' );
	}

	/**
	 * PR #304 review finding 2: two labels differing only in case now collide
	 * too, since duplicate detection moved onto the uppercased key.
	 */
	public function test_inject_treats_labels_differing_only_in_case_as_a_duplicate(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[
				'RU' => [
					$this->region_record( 'list-fixture', 'mo-1', 'RU', 'Московская область' ),
					$this->region_record( 'list-fixture', 'mo-2', 'RU', 'МОСКОВСКАЯ ОБЛАСТЬ' ),
				],
			]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'МОСКОВСКАЯ ОБЛАСТЬ' => 'Московская область' ], $states['RU'], 'the first record wins, case-insensitively' );
	}

	public function test_inject_records_ownership_for_every_country_it_wrote(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertTrue( $registry->owns_region_states( 'RU', $states['RU'] ) );
		$this->assertFalse( $registry->owns_region_states( 'BY', [] ), 'a country never injected into must not be reported owned' );
	}

	/**
	 * PR #304 review finding 3: ownership must be re-confirmed against the
	 * FINAL registered states, not merely trusted from the recorded flag — a
	 * later filter callback (e.g. a §8 carrier takeover hooked after this
	 * injector, both at the default `woocommerce_states` priority) can
	 * overwrite what this injector wrote. The mutant this pins: reverting
	 * `owns_region_states()` to trust the recorded flag alone (ignoring
	 * `$final_states`) makes this assert `true` for a country this injector no
	 * longer actually owns.
	 */
	public function test_owns_region_states_is_false_once_a_later_filter_overwrites_the_injection(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$registry->inject_related_list_states( [] );

		// Simulates a §8 carrier takeover (Checkout_Handler::inject_states())
		// running AFTER this injector on the same `woocommerce_states` filter
		// and unconditionally overwriting the country's states.
		$overwritten_by_someone_else = [ 'MOW' => 'Москва (перехвачено §8)' ];

		$this->assertFalse( $registry->owns_region_states( 'RU', $overwritten_by_someone_else ) );
	}

	/**
	 * PR #304 review finding 4: a record whose label is `''` (documented as
	 * possible, {@see Location_Record::label()}) must never become a
	 * selectable option — WooCommerce would render it indistinguishably from
	 * its own "select an option…" placeholder. The mutant this pins: dropping
	 * the empty-label guard makes `$options['']` register, so `RU` would carry
	 * a blank entry and this assertion would fail.
	 */
	public function test_inject_skips_a_record_with_an_empty_or_whitespace_only_label(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[
				'RU' => [
					$this->region_record( 'list-fixture', 'blank', 'RU', '' ),
					$this->region_record( 'list-fixture', 'whitespace', 'RU', '   ' ),
					$this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ),
				],
			]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'МОСКОВСКАЯ ОБЛАСТЬ' => 'Московская область' ], $states['RU'], 'the empty/whitespace-only labels must never become options' );
	}

	/**
	 * PR #304 review finding 4: labels are untrimmed by the provider, so
	 * `'Москва'` and `'Москва '` would otherwise register as two visually
	 * identical options — trimming BEFORE using the label as key or value
	 * collapses them into one, same as the real duplicate-label path.
	 */
	public function test_inject_trims_labels_before_using_them_as_key_or_value(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[
				'RU' => [
					$this->region_record( 'list-fixture', 'mo-1', 'RU', 'Москва' ),
					$this->region_record( 'list-fixture', 'mo-2', 'RU', 'Москва ' ),
				],
			]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'МОСКВА' => 'Москва' ], $states['RU'], 'a trailing-whitespace label is the same option as its trimmed form' );
	}

	/**
	 * The gotcha `checkout-field-takeover-woocommerce-states` discipline:
	 * writing an empty array for a country tells WooCommerce it has NO states
	 * at all and HIDES the field — a country the provider claims to cover but
	 * has nothing to enumerate for must be left untouched instead.
	 */
	public function test_inject_never_writes_an_empty_array_for_a_country_with_nothing_to_enumerate(): void {
		$provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ], [] ); // no region data at all.
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [ 'RU' => [ 'MOW' => 'Москва (native)' ] ] );

		// Untouched — WC's own pre-existing entry survives.
		$this->assertSame( [ 'MOW' => 'Москва (native)' ], $states['RU'] );
		$this->assertFalse( $registry->owns_region_states( 'RU', $states['RU'] ), 'nothing was actually injected — must not be reported owned' );
	}

	/**
	 * First-wins: a country ALREADY carrying non-empty states (from an earlier
	 * `woocommerce_states` callback — WC native, or a plugin's §8 carrier
	 * takeover) is never clobbered by this injector.
	 */
	public function test_inject_never_overwrites_an_already_non_empty_country(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [ 'RU' => [ '77' => 'Москва (карьер)' ] ] );

		$this->assertSame( [ '77' => 'Москва (карьер)' ], $states['RU'], 'first-wins — the pre-existing entry is kept' );
		$this->assertFalse( $registry->owns_region_states( 'RU', $states['RU'] ), 'a country this injector skipped is never reported owned' );
	}

	public function test_inject_is_a_no_op_outside_related_list_mode(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		// active_provider = list-fixture, but field_mode left at its default (typeahead).
		$this->stub_active_provider_option( 'list-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$states = $registry->inject_related_list_states( [] );

		$this->assertArrayNotHasKey( 'RU', $states );
		$this->assertSame( 0, $provider->list_localities_calls, 'the provider must never even be asked outside related-list mode' );
	}

	public function test_inject_is_a_no_op_when_the_active_provider_lacks_the_list_capability(): void {
		// The real bundled DaData provider is active (no fixture override) — it
		// has no `list` capability at all, so even if field_mode somehow held
		// 'related-list' (a value get_field_mode() itself would clamp away —
		// this test calls the injector directly to prove the injector's OWN
		// gate is independently defensive, not merely relying on that clamp).
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return 'woodev_location_field_mode' === $name ? Location_Provider_Registry::MODE_RELATED_LIST : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [], $states );
	}

	public function test_inject_is_a_no_op_when_the_active_provider_is_not_configured(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ],
			false // not configured.
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertArrayNotHasKey( 'RU', $states );
		$this->assertSame( 0, $provider->list_localities_calls );
	}

	public function test_inject_tolerates_a_non_array_input(): void {
		$provider = new Fake_List_Location_Provider( 'list-fixture', 'List Fixture', [ 'RU' ], [] );
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( 'not-an-array' );

		$this->assertIsArray( $states );
	}

	// -------------------------------------------------------------------------
	// Task 14 — get_offered_default_locality_policies() / get_default_locality_policy():
	// default + clamp against the offered set, mirroring the field_mode block above.
	// -------------------------------------------------------------------------

	public function test_default_locality_policy_offered_options_default_to_off_and_fixed_only(): void {
		// A provider that does NOT declare `locate` — deliberately explicit
		// (not "providers=[], falls back to the bundled DaData") because the
		// REAL bundled Dadata_Provider (Task 7) genuinely DOES declare `locate`
		// (its `iplocate/address` endpoint) whenever its class happens to be
		// autoloaded elsewhere in the same test run — unlike `list`, which it
		// genuinely lacks (see the field_mode clamp test's own precedent,
		// which that asymmetry is why it does NOT apply here unmodified).
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		$this->stub_active_provider_option( 'acme' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame(
			[ Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED ],
			$registry->get_offered_default_locality_policies()
		);
	}

	public function test_default_locality_policy_geoip_offered_when_the_active_provider_has_locate(): void {
		$provider = new Fake_Locate_Location_Provider( 'geo-fixture', 'Geo Fixture' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		$this->stub_active_provider_option( 'geo-fixture' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame(
			[
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF,
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
				Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP,
			],
			$registry->get_offered_default_locality_policies()
		);
	}

	public function test_default_locality_policy_defaults_to_off(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, $registry->get_default_locality_policy() );
	}

	public function test_default_locality_policy_returns_the_stored_value_when_it_is_offered(): void {
		$provider = new Fake_Locate_Location_Provider( 'geo-fixture', 'Geo Fixture' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'geo-fixture';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP;
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP, $registry->get_default_locality_policy() );
	}

	/**
	 * A stored `geoip` value from BEFORE a provider switch to a non-`locate`
	 * provider must never be served as-is — clamps to `off`, mirroring
	 * {@see LocationProviderRegistryTest::test_field_mode_clamps_a_stored_value_the_current_active_provider_no_longer_supports()}.
	 */
	public function test_default_locality_policy_clamps_a_stored_geoip_value_the_current_provider_no_longer_supports(): void {
		// A stored 'geoip' value from BEFORE a switch AWAY from a `locate`-capable
		// provider — the NOW-active provider explicitly does not declare `locate`.
		// See test_default_locality_policy_offered_options_default_to_off_and_fixed_only()'s
		// own comment for why "providers=[], falls back to DaData" is NOT used here.
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP;
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, $registry->get_default_locality_policy() );
	}

	public function test_default_locality_policy_is_off_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertSame( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF, $registry->get_default_locality_policy() );
	}

	// -------------------------------------------------------------------------
	// Task 14 — get_default_locality_record() / set_default_locality_record():
	// the merchant-picked FIXED record, serialized as JSON.
	// -------------------------------------------------------------------------

	public function test_default_locality_record_is_null_when_unset(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->get_default_locality_record() );
	}

	public function test_default_locality_record_round_trips_through_set_and_get(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$record = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);

		$registry->set_default_locality_record( $record );

		$fetched = $registry->get_default_locality_record();

		$this->assertNotNull( $fetched );
		$this->assertSame( 'dadata:fias-1', $fetched->key() );
	}

	public function test_default_locality_record_is_null_for_invalid_json(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return 'woodev_location_default_locality_record' === $name ? 'not-json{{{' : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->get_default_locality_record() );
	}

	public function test_default_locality_record_is_null_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertNull( $registry->get_default_locality_record() );
	}

	public function test_set_default_locality_record_is_a_no_op_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$record = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
			]
		);

		// Must not throw/fatal — there is no settings handler to write through.
		$registry->set_default_locality_record( $record );

		$this->assertNull( $registry->get_default_locality_record() );
	}

	// -------------------------------------------------------------------------
	// Task 14 — get_default_locality_needs_repick() / set_default_locality_needs_repick():
	// the informational "stale foreign-namespace default" flag.
	// -------------------------------------------------------------------------

	public function test_default_locality_needs_repick_defaults_to_false(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertFalse( $registry->get_default_locality_needs_repick() );
	}

	public function test_default_locality_needs_repick_round_trips(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$registry->set_default_locality_needs_repick( true );
		$this->assertTrue( $registry->get_default_locality_needs_repick() );

		$registry->set_default_locality_needs_repick( false );
		$this->assertFalse( $registry->get_default_locality_needs_repick() );
	}

	public function test_default_locality_needs_repick_is_false_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		// Must not throw/fatal — there is no settings handler to write through.
		$registry->set_default_locality_needs_repick( true );

		$this->assertFalse( $registry->get_default_locality_needs_repick() );
	}

	// -------------------------------------------------------------------------
	// Review finding F4: `default_locality_record` / `default_locality_needs_repick`
	// stay registered (the internal read/write machinery still works) but are
	// registered WITHOUT a control — never an editable widget on the settings
	// page.
	// -------------------------------------------------------------------------

	public function test_default_locality_record_and_needs_repick_have_no_control(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$handler = $registry->get_settings_handler();
		$this->assertNotNull( $handler );

		$record_setting = $handler->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD );
		$this->assertNotNull( $record_setting, 'the setting itself must stay registered — internal read/write still needs it' );
		$this->assertNull( $record_setting->get_control(), 'mutant: registering a control here re-exposes the raw-JSON textarea on the settings page' );

		$repick_setting = $handler->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK );
		$this->assertNotNull( $repick_setting );
		$this->assertNull( $repick_setting->get_control(), 'mutant: registering a control here re-exposes the editable toggle a merchant could use to mask a stranded default' );
	}

	// -------------------------------------------------------------------------
	// Review finding F4: apply_default_locality_status_note() — the settings
	// page must say so when the `fixed` policy is not actually configured,
	// computed LIVE (never from the orphaned needs_repick flag, which
	// Location_Service no longer writes per review finding F2(a)).
	// -------------------------------------------------------------------------

	public function test_status_note_is_empty_when_the_policy_is_not_fixed(): void {
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}

				return $default; // default_locality_policy unset -> 'off'.
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY );
		$this->assertSame( '', $setting->get_description() );
	}

	public function test_status_note_warns_when_fixed_has_no_valid_record(): void {
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}

				return $default; // default_locality_record left unset.
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY );
		$this->assertNotSame( '', $setting->get_description(), 'an unconfigured fixed policy must not stay silent — mutant: removing this branch' );
	}

	public function test_status_note_warns_when_the_stored_record_no_longer_matches_the_active_provider(): void {
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		$record = Location_Record::from_array(
			[
				'key'         => 'other-provider:city-1',
				'provider_id' => 'other-provider',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record->to_array() );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY );
		$this->assertNotSame( '', $setting->get_description(), 'a record picked under a DIFFERENT provider than the one now active must surface live, without relying on a stored flag' );
	}

	public function test_status_note_is_empty_when_fixed_has_a_valid_matching_record(): void {
		$provider = new Fake_Location_Provider( 'acme', 'ACME' );

		$record = Location_Record::from_array(
			[
				'key'         => 'acme:city-1',
				'provider_id' => 'acme',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record->to_array() );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY );
		$this->assertSame( '', $setting->get_description(), 'a correctly-namespaced record under the active provider has nothing to warn about' );
	}

	// -------------------------------------------------------------------------
	// Task 10 (issue #362; design S3/§3.1/§3.2/§4.2/§7) — `address_suggestions`
	// store switch: setting registration, ordering, the relabelled `field_mode`
	// name, the availability-driven disabled-control gate, and the clamp-on-read
	// effective value.
	// -------------------------------------------------------------------------

	public function test_address_suggestions_setting_is_a_boolean_checkbox_defaulting_to_true(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS );

		$this->assertNotNull( $setting );
		$this->assertSame( \Woodev_Setting::TYPE_BOOLEAN, $setting->get_type() );
		$this->assertTrue( $setting->get_default() );
		$this->assertSame( \Woodev_Control::TYPE_CHECKBOX, $setting->get_control()->get_type() );
	}

	public function test_owned_setting_ids_order_places_address_suggestions_after_field_mode(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$ids = $registry->get_settings_handler()->get_owned_setting_ids();

		// The bundled DaData provider (Task 7) is the resolved active
		// provider here (empty candidate filter), so its own declared
		// fields ('token', 'clean_secret') trail the fixed prefix below —
		// only the FIXED, ordered prefix is under test.
		$this->assertSame(
			[
				Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
				Location_Provider_Registry::SETTING_FIELD_MODE,
				Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS,
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK,
			],
			array_slice( $ids, 0, 6 )
		);
	}

	public function test_field_mode_setting_is_relabelled_to_field_type(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$setting = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_FIELD_MODE );

		$this->assertSame( 'Тип поля НП/Регион', $setting->get_name() );
	}

	public function test_address_suggestions_control_is_disabled_when_nobody_serves_address(): void {
		// 'acme' only declares `region` (Fake_Location_Provider's fixed
		// declare_suggest_levels()); DaData stays unconfigured (no token
		// stubbed) -> nobody in the chain can ever serve `address`.
		$active = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active ] );
		$this->stub_active_provider_option( 'acme' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$control = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS )->get_control();

		$this->assertTrue( $control->is_disabled() );
		$this->assertSame(
			'Выбранный провайдер не отдаёт адреса, а учётные данные DaData не заполнены.',
			$control->get_disabled_reason()
		);
		$this->assertFalse( $registry->is_address_suggestions_available() );
		// The stored value defaults to `true`, but the effective (clamped) value
		// must still answer `false` — the merchant's stored preference is never
		// honest when nobody can serve `address` at all.
		$this->assertFalse( $registry->is_address_suggestions_enabled() );
	}

	public function test_address_suggestions_control_enabled_via_the_bundled_dadata_fallback_while_the_active_provider_does_not_serve_address(): void {
		// The live rig configuration (spec §4.2): the active provider only
		// covers region/settlement, and the bundled DaData fallback alone
		// serves `address` — a mixed chain, not a single-provider one.
		$active = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'acme';
				}
				if ( 'woodev_location_token' === $name ) {
					return 'tok';
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$control = $registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS )->get_control();

		$this->assertFalse( $control->is_disabled() );
		$this->assertTrue( $registry->is_address_suggestions_available() );
		$this->assertTrue( $registry->is_address_suggestions_enabled() );
	}

	public function test_address_suggestions_read_never_writes_the_stored_option(): void {
		Functions\expect( 'update_option' )->never();

		$active = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $active ] );
		$this->stub_active_provider_option( 'acme' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$registry->is_address_suggestions_available();
		$registry->is_address_suggestions_enabled();
		$registry->get_address_suggestions_raw();
		$registry->get_settings_handler()->get_setting( Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS )->get_control()->is_disabled();
	}

	public function test_address_suggestions_raw_is_true_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertTrue( $registry->get_address_suggestions_raw() );
	}

	public function test_address_suggestions_available_and_enabled_are_false_while_the_gate_is_closed(): void {
		$registry = Location_Provider_Registry::instance();

		$this->assertFalse( $registry->is_address_suggestions_available() );
		$this->assertFalse( $registry->is_address_suggestions_enabled() );
	}
}
