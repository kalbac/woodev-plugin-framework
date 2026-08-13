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
		$this->assertTrue( Settings_Page_Registry::instance()->has_providers(), 'settings must register once the gate opens, even with zero providers' );
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
	// Provider-declared settings seam: merged for the ACTIVE provider only
	// -------------------------------------------------------------------------

	public function test_active_providers_declared_settings_are_rendered_on_the_surface(): void {
		// A distinct id (not DEFAULT_PROVIDER_ID — that belongs to the now-real
		// bundled Dadata_Provider, Task 7) made active via an explicit stored
		// setting value rather than the default-id fallback.
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[
				'token' => [
					'name'      => 'Token',
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
		$this->assertNotNull( $handler->get_setting( 'token' ), 'the ACTIVE provider field must be rendered' );
		$this->assertNull( $handler->get_setting( 'inactive_secret' ), 'a NON-active provider field must not be rendered' );
	}

	public function test_provider_field_marked_sensitive_gets_a_password_control(): void {
		$active = new Fake_Location_Provider(
			'active-fixture',
			'Active',
			[
				'token' => [
					'name'      => 'Token',
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

		$setting = $registry->get_settings_handler()->get_setting( 'token' );

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
	 */
	public function test_inject_injects_regions_keyed_by_their_own_label(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$states = $registry->inject_related_list_states( [] );

		$this->assertSame( [ 'Московская область' => 'Московская область' ], $states['RU'] );
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

		$this->assertSame( [ 'Московская область' => 'Московская область' ], $states['RU'], 'the first record wins; the duplicate is dropped, not merged or suffixed' );
	}

	public function test_inject_records_ownership_for_every_country_it_wrote(): void {
		$provider = new Fake_List_Location_Provider(
			'list-fixture',
			'List Fixture',
			[ 'RU' ],
			[ 'RU' => [ $this->region_record( 'list-fixture', 'mo', 'RU', 'Московская область' ) ] ]
		);
		$registry = $this->activate_related_list_mode( $provider );

		$registry->inject_related_list_states( [] );

		$this->assertTrue( $registry->owns_region_states( 'RU' ) );
		$this->assertFalse( $registry->owns_region_states( 'BY' ), 'a country never injected into must not be reported owned' );
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
		$this->assertFalse( $registry->owns_region_states( 'RU' ), 'nothing was actually injected — must not be reported owned' );
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
		$this->assertFalse( $registry->owns_region_states( 'RU' ), 'a country this injector skipped is never reported owned' );
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
}
