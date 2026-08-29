<?php
/**
 * Unit tests for the "fixed default locality is stale" admin notice (#410):
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::is_default_locality_provider_mismatched()},
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::default_locality_stale_notice()}
 * and {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_default_locality_stale_notice()}'s
 * registration (gate, page-scoping via {@see \Woodev_Admin_Pages::is_woodev_page()},
 * fleet-wide dedup, non-dismissible params).
 *
 * The pure decision ({@see Location_Provider_Registry::default_locality_stale_notice()})
 * is exercised directly against a real registry singleton — the same
 * `declare_needed()`/`collect()` setup {@see \Woodev\Tests\Unit\Shipping\Location\LocationProviderRegistryTest}
 * already uses for {@see Location_Provider_Registry::apply_default_locality_status_note()}'s
 * own F4 coverage, since the two share the same underlying predicate.
 * Registration is exercised via `ReflectionClass::newInstanceWithoutConstructor()`,
 * the same technique {@see \Woodev\Tests\Unit\Shipping\ShippingPluginLocationProviderNoticeTest}
 * uses for its own sibling notice — fixture class names here are distinct
 * from that file's (`Stale_*` prefix) since both load in the same PHPUnit
 * process.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/admin/class-admin-pages.php';

/**
 * A minimal fake provider, parameterized by id/name only — distinct class
 * name from the sibling notice test's own fake provider fixtures.
 */
class Stale_Fake_Location_Provider extends Abstract_Location_Provider {

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
		return [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}
}

/**
 * Bare fixture implementing only what PHP requires to instantiate
 * Shipping_Plugin at all — never actually `new`'d, only reflected on.
 */
class Stale_Bare_Shipping_Plugin_Fixture extends Shipping_Plugin {

	protected function get_shipping_method_classes(): array {
		return [];
	}

	public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
		return null;
	}

	protected function get_file() {
		return __FILE__;
	}

	public function get_plugin_name() {
		return 'Stale Notice Stub';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * Same bare fixture, but opting IN to the Location Provider layer.
 */
class Stale_Opted_In_Shipping_Plugin_Fixture extends Stale_Bare_Shipping_Plugin_Fixture {

	public function needs_location_provider(): bool {
		return true;
	}
}

/**
 * Records the notice registrations made by one plugin's own handler.
 */
class Stale_Recording_Admin_Notice_Handler {

	/** @var array<int, array{message: string, id: string, params: array<string, mixed>}> */
	public array $notices = [];

	/**
	 * @param array<string, mixed> $params Notice registration parameters.
	 */
	public function add_admin_notice( string $message, string $id, array $params = [] ): void {
		$this->notices[] = [
			'message' => $message,
			'id'      => $id,
			'params'  => $params,
		];
	}
}

/**
 * Exposes the protected registration path and supplies a distinct handler for
 * each plugin, reproducing the fleet-wide duplication that the real plugins
 * otherwise produce.
 */
class Stale_Registration_Shipping_Plugin_Fixture extends Stale_Opted_In_Shipping_Plugin_Fixture {

	private ?Stale_Recording_Admin_Notice_Handler $notice_handler = null;

	public function set_notice_handler( Stale_Recording_Admin_Notice_Handler $notice_handler ): void {
		$this->notice_handler = $notice_handler;
	}

	public function publish_default_locality_stale_notice(): void {
		$this->add_default_locality_stale_notice();
	}

	public function get_admin_notice_handler() {
		return $this->notice_handler;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry::is_default_locality_provider_mismatched
 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry::default_locality_stale_notice
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_default_locality_stale_notice
 */
final class ShippingPluginDefaultLocalityStaleNoticeTest extends TestCase {

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
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php?page=wc-settings' );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		Location_Provider_Registry::instance()->reset_for_tests();

		unset( $_GET['page'], $GLOBALS['submenu'] );
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		unset( $_GET['page'], $GLOBALS['submenu'] );

		parent::tearDown();
	}

	/**
	 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers
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
	 * @return array{key: string, provider_id: string, level: string, country: string, label: string}
	 */
	private function record_array_for( string $provider_id ): array {
		return [
			'key'         => $provider_id . ':city-1',
			'provider_id' => $provider_id,
			'level'       => Location_Record::LEVEL_SETTLEMENT,
			'country'     => 'RU',
			'label'       => 'Москва',
		];
	}

	// -------------------------------------------------------------------------
	// Location_Provider_Registry::default_locality_stale_notice() — the PURE
	// decision.
	// -------------------------------------------------------------------------

	public function test_no_notice_while_the_gate_is_closed(): void {
		// declare_needed()/collect() never called — no settings handler exists,
		// so get_default_locality_policy() resolves 'off' (mirrors
		// ShippingPluginLocationProviderNoticeTest's own gate-closed case).
		$this->assertNull( Location_Provider_Registry::instance()->default_locality_stale_notice() );
	}

	public function test_no_notice_when_the_policy_is_not_fixed(): void {
		$provider = new Stale_Fake_Location_Provider( 'stale-acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'stale-acme';
				}

				return $default; // default_locality_policy unset -> 'off'.
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->default_locality_stale_notice() );
	}

	public function test_no_notice_when_fixed_but_no_record_is_stored(): void {
		$provider = new Stale_Fake_Location_Provider( 'stale-acme', 'ACME' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'stale-acme';
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

		$this->assertNull( $registry->default_locality_stale_notice(), 'the "no record picked" case stays on the settings note alone — out of scope for the admin notice (#410)' );
	}

	public function test_no_notice_when_the_active_provider_matches_the_record(): void {
		$provider = new Stale_Fake_Location_Provider( 'stale-acme', 'ACME' );
		$record   = $this->record_array_for( 'stale-acme' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'stale-acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->default_locality_stale_notice() );
	}

	public function test_notice_when_the_active_provider_differs_from_the_record(): void {
		$provider = new Stale_Fake_Location_Provider( 'stale-acme', 'ACME' );
		$record   = $this->record_array_for( 'stale-other' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'stale-acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$notice = $registry->default_locality_stale_notice();

		$this->assertNotNull( $notice );
		$this->assertSame( 'location-default-locality-stale', $notice['notice_id'] );
		$this->assertStringContainsString( 'admin.php?page=woodev-settings&tab=shipping', $notice['message'], 'the settings link points at the Shipping tab\'s Location section' );
	}

	/**
	 * `apply_filters()` is forced to return `null` for
	 * {@see Location_Provider_Registry::FILTER_ACTIVE_PROVIDER} specifically —
	 * NOT achieved via an empty providers list, because
	 * {@see Location_Provider_Registry::collect()} unconditionally registers
	 * the bundled DaData provider (its class now exists in this codebase) even
	 * when the {@see Location_Provider_Registry::FILTER_PROVIDERS} candidates
	 * list is empty, so `get_active_provider()` would still resolve to it via
	 * the `self::DEFAULT_PROVIDER_ID` fallback. Forcing the filter's return
	 * value directly is the one route documented as reachable by both
	 * `get_active_provider()`'s own docblock and card #410's critic finding.
	 */
	private function stub_active_provider_filter_returning_null(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				if ( Location_Provider_Registry::FILTER_ACTIVE_PROVIDER === $tag ) {
					return null;
				}

				return $default;
			}
		);
	}

	public function test_no_notice_when_a_record_exists_but_no_active_provider_resolves(): void {
		$record = $this->record_array_for( 'stale-other' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_active_provider_filter_returning_null();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertNull( $registry->default_locality_stale_notice(), 'with nothing to re-pick, the non-dismissible notice must not become a permanent unactionable banner (critic finding, PR #661)' );
	}

	public function test_predicate_still_reports_a_mismatch_when_no_active_provider_resolves(): void {
		$record = $this->record_array_for( 'stale-other' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_active_provider_filter_returning_null();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertTrue( $registry->is_default_locality_provider_mismatched(), 'parity with apply_default_locality_status_note(): the shared predicate still counts a record with nothing currently active as a mismatch, even though the admin notice deliberately stays silent here' );
	}

	// -------------------------------------------------------------------------
	// Shipping_Plugin::add_default_locality_stale_notice() — the registration:
	// needs_location_provider() gate, Woodev-page scoping, fleet-wide dedup,
	// non-dismissible params.
	// -------------------------------------------------------------------------

	private function seed_mismatched_registry(): void {
		$provider = new Stale_Fake_Location_Provider( 'stale-reg-acme', 'ACME' );
		$record   = $this->record_array_for( 'stale-reg-other' );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $record ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'stale-reg-acme';
				}
				if ( 'woodev_location_default_locality_policy' === $name ) {
					return Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED;
				}
				if ( 'woodev_location_default_locality_record' === $name ) {
					return json_encode( $record );
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();
	}

	public function test_registration_is_skipped_when_the_plugin_did_not_opt_into_the_location_provider_layer(): void {
		$this->seed_mismatched_registry();

		$_GET['page'] = 'woodev';

		// Stale_Bare_Shipping_Plugin_Fixture never overrides get_admin_notice_handler()
		// (it stays the parent's real implementation, which needs a fully
		// constructed plugin) — invoking the method directly via reflection
		// proves the gate returns BEFORE ever reaching that call, since reaching
		// it here would throw rather than silently succeed.
		$plugin = ( new \ReflectionClass( Stale_Bare_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		// No setAccessible() call: since PHP 8.1 it is a no-op (and calling it
		// prints a deprecation notice PHPUnit's own `failOnRisky="true"` turns
		// into a hard failure) — ReflectionMethod::invoke() already reaches a
		// protected method directly.
		( new \ReflectionMethod( $plugin, 'add_default_locality_stale_notice' ) )->invoke( $plugin );

		$this->addToAssertionCount( 1 ); // reaching this line without a fatal IS the assertion.
	}

	public function test_registration_is_skipped_off_a_woodev_page(): void {
		$this->seed_mismatched_registry();

		$_GET['page'] = 'wc-settings';

		$handler = new Stale_Recording_Admin_Notice_Handler();
		$plugin  = ( new \ReflectionClass( Stale_Registration_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$plugin->set_notice_handler( $handler );

		$plugin->publish_default_locality_stale_notice();

		$this->assertCount( 0, $handler->notices, 'the operator\'s middle-loudness scoping: not shown outside Woodev admin pages' );
	}

	public function test_registration_happens_on_a_woodev_page_and_is_non_dismissible(): void {
		$this->seed_mismatched_registry();

		$_GET['page'] = 'woodev';

		$handler = new Stale_Recording_Admin_Notice_Handler();
		$plugin  = ( new \ReflectionClass( Stale_Registration_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$plugin->set_notice_handler( $handler );

		$plugin->publish_default_locality_stale_notice();

		$this->assertCount( 1, $handler->notices );
		$this->assertSame( 'location-default-locality-stale', $handler->notices[0]['id'] );
		$this->assertArrayHasKey( 'dismissible', $handler->notices[0]['params'] );
		$this->assertFalse( $handler->notices[0]['params']['dismissible'], 'a LIVE-computed notice must not be permanently dismissible' );
	}

	public function test_only_one_plugin_registers_the_fleet_wide_stale_notice(): void {
		$this->seed_mismatched_registry();

		$_GET['page'] = 'woodev';

		$first_handler  = new Stale_Recording_Admin_Notice_Handler();
		$second_handler = new Stale_Recording_Admin_Notice_Handler();
		$first_plugin   = ( new \ReflectionClass( Stale_Registration_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$second_plugin  = ( new \ReflectionClass( Stale_Registration_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$first_plugin->set_notice_handler( $first_handler );
		$second_plugin->set_notice_handler( $second_handler );

		$first_plugin->publish_default_locality_stale_notice();
		$second_plugin->publish_default_locality_stale_notice();

		$this->assertCount( 1, $first_handler->notices );
		$this->assertCount( 0, $second_handler->notices );
	}
}
