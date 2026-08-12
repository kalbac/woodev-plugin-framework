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

	public function test_a_nonexistent_bundled_class_registers_nothing_without_erroring(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		// Dadata_Provider does not exist yet (Task 7) — collection must simply
		// skip it, not error, and the registry ends up with zero providers.
		$this->assertSame( [], $registry->get_providers() );
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
		$this->assertCount( 1, $registry->get_providers() );
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
		$this->assertCount( 2, $registry->get_providers() );
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
		$dadata = new Fake_Location_Provider( Location_Provider_Registry::DEFAULT_PROVIDER_ID, 'Bundled default look-alike' );
		$acme   = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $dadata, $acme ] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( $dadata, $registry->get_active_provider() );
	}

	public function test_unknown_active_id_falls_back_to_the_default_provider_when_it_is_registered(): void {
		$dadata = new Fake_Location_Provider( Location_Provider_Registry::DEFAULT_PROVIDER_ID, 'Bundled default look-alike' );
		$acme   = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $dadata, $acme ] );
		// Stored value names a provider nothing is (or was ever) registered under.
		Functions\when( 'get_option' )->justReturn( 'ghost-id' );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertSame( $dadata, $registry->get_active_provider() );
	}

	/**
	 * Documented decision: when even the DEFAULT id has nothing registered under
	 * it (Task 7 not shipped, or the default was never configured), resolution
	 * lands on `null` rather than inventing a provider — degrading to native
	 * fields per spec §4.7, exactly like "no provider active" today.
	 */
	public function test_unknown_active_id_resolves_to_null_when_the_default_provider_is_also_unregistered(): void {
		$acme = new Fake_Location_Provider( 'acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $acme ] );
		Functions\when( 'get_option' )->justReturn( null );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->get_active_provider() );
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
		$active = new Fake_Location_Provider(
			Location_Provider_Registry::DEFAULT_PROVIDER_ID,
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
		// No stored value -> the setting's own default (DEFAULT_PROVIDER_ID) applies,
		// which is $active's id.
		Functions\when( 'get_option' )->justReturn( null );

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
			Location_Provider_Registry::DEFAULT_PROVIDER_ID,
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
		Functions\when( 'get_option' )->justReturn( null );

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

		$this->assertSame( [ 'a' => 'Alpha', 'b' => 'Beta' ], $setting->get_options() );
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
			Location_Provider_Registry::DEFAULT_PROVIDER_ID,
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
		Functions\when( 'get_option' )->justReturn( null );

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
}
