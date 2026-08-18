<?php
/**
 * Tests for Checkout_Handler::enqueue_assets() — the location-provider asset
 * wiring (Task 9, 2026-08-12 plan).
 *
 * Location_Cascade/typeahead JS (Tasks 10-11) does not exist yet in this PR
 * block (PR-B); this handler is wired NOW so those tasks land with zero code
 * changes here. The guard mirrors
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_script_if_built()}'s
 * own "never enqueue a handle whose file is not on disk" discipline: a script
 * that does not exist must NEVER be enqueued (gotcha
 * `built-on-both-sides-with-no-caller-in-the-middle`).
 *
 * Covers:
 *   - no location scripts/styles, no `location` config key, when the layer is inactive
 *   - no location scripts/styles (but the config DOES carry `location`) when the layer
 *     is active but the Task 10/11/theme-resistant-CSS files are not yet on disk (the
 *     real state today)
 *   - location scripts AND the typeahead's own `location.css` enqueued with the right
 *     handles/paths/deps once the files exist (probed via an `asset_exists()` override,
 *     mirroring Pickup_Handler's own test-probe pattern)
 *   - the location block still rides inside the SAME single wp_localize_script()
 *     call — one config object, one enqueue path
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Unit\TestCase;

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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * A directly-controlled fake {@see Location_Service} — same "each test builds
 * exactly the shape it needs" discipline as `Checkout_Config_Fake_Location_Service`
 * in `CheckoutConfigTest`. Only `is_active()` varies across these tests; every
 * other method returns a trivially safe, already-covered-elsewhere value.
 */
final class Checkout_Handler_Fake_Location_Service extends Location_Service {

	private bool $active;

	public function __construct( bool $active ) {
		$this->active = $active;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_record( ?string $for_country = null ): ?array {
		return null;
	}

	public function is_country_supported( string $country, ?string $level = null ): bool {
		return false;
	}

	public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
		return null;
	}

	public function get_field_mode(): string {
		return \Woodev\Framework\Shipping\Location\Location_Provider_Registry::MODE_TYPEAHEAD;
	}

	public function owns_region_states( string $country, array $final_states ): bool {
		return false;
	}

	/**
	 * Issue #296: never touches `get_option()` — this fake, like every other
	 * method above, stays entirely clear of real WordPress option state
	 * (mirrors `Checkout_Config_Fake_Location_Service`'s own override in
	 * `CheckoutConfigTest`, for the identical reason).
	 */
	public function resolve_default_country(): string {
		return 'RU';
	}
}

/**
 * Probe forcing every location asset to report as already built on disk,
 * mirroring `Pickup_Handler_Assets_Built_Probe` in `PickupHandlerTest`.
 */
class Checkout_Handler_Location_Assets_Built_Probe extends Checkout_Handler {
	protected static function asset_exists( string $path ): bool {
		return true;
	}
}

/**
 * Probe forcing every location asset to report as NOT built on disk —
 * the counterpart to {@see Checkout_Handler_Location_Assets_Built_Probe}.
 *
 * Task 12 shipped the real `location-typeahead.js`/`location-cascade.js`
 * files, so the "not yet built" scenario this test class exists to cover
 * can no longer rely on ambient filesystem state (the files ARE there now);
 * it must force-simulate the absence instead, exactly like the sibling probe
 * force-simulates presence.
 */
class Checkout_Handler_Location_Assets_Not_Built_Probe extends Checkout_Handler {
	protected static function asset_exists( string $path ): bool {
		return false;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::enqueue_assets
 */
class CheckoutHandlerEnqueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'plugins_url' )->alias(
			static fn( $path, $file ) => 'https://example.test/wp-content/plugins/x/' . basename( $path )
		);

		// enqueue_assets() now reaches Shipping_Settings_Tab::instance()->get_field_settings()
		// for the `field_policy` config block (Task 6, issue #362) — that lazily constructs a
		// real Checkout_Field_Settings, which registers settings/controls through
		// Woodev_Abstract_Settings; stub the WP primitives that path touches, same as
		// CheckoutFieldSettingsTest/ShippingSettingsTabTest.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Builds a minimal managed-field set and a spy that records every
	 * wp_enqueue_script()/wp_localize_script() call.
	 *
	 * @return array{0: Checkout_Fields, 1: array<string, array{src: string, deps: string[]}>, 2: array<int, array{0: string, 1: string, 2: array<string, mixed>}>}
	 */
	private function fixture(): array {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );

		return [ $fields, [], [] ];
	}

	/**
	 * Wires spies for wp_enqueue_script()/wp_enqueue_style()/wp_localize_script(). `$styles` is
	 * optional (defaults to a throwaway array) so the register-side tests that never touch CSS
	 * do not need to pass it — mirrors the same "not every caller cares" shape as `$scripts`.
	 *
	 * @param array<string, array{src: string, deps: string[]}> $scripts
	 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $localized
	 * @param array<string, array{src: string, deps: string[]}> $styles
	 */
	private function wire_spies( array &$scripts, array &$localized, array &$styles = [] ): void {
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps, $ver, $footer ) use ( &$scripts ) {
				$scripts[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
				$styles[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $object_name, $data ) use ( &$localized ) {
				$localized[] = [ $handle, $object_name, $data ];
			}
		);
	}

	// -------------------------------------------------------------------------
	// Layer inactive — no location scripts, no `location` config block
	// -------------------------------------------------------------------------

	public function test_no_location_scripts_and_no_location_config_block_when_layer_inactive(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$styles     = [];
		$this->wire_spies( $scripts, $localized, $styles );

		$handler = new Checkout_Handler( $fields, 'carrier', new Checkout_Handler_Fake_Location_Service( false ) );
		$handler->enqueue_assets();

		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-select-modes', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-styles', $styles );

		$this->assertCount( 1, $localized );
		$this->assertArrayNotHasKey( 'location', $localized[0][2] );
	}

	public function test_no_location_scripts_and_no_location_config_block_when_no_service_injected(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$styles     = [];
		$this->wire_spies( $scripts, $localized, $styles );

		// 2-arg construction — pre-Task-9 call sites keep working unchanged.
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->enqueue_assets();

		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-select-modes', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-styles', $styles );
		$this->assertArrayNotHasKey( 'location', $localized[0][2] );
	}

	// -------------------------------------------------------------------------
	// Layer active, Task 10/11 files not yet on disk — still no 404, config present
	// -------------------------------------------------------------------------

	public function test_active_but_unbuilt_location_assets_are_never_enqueued(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$styles     = [];
		$this->wire_spies( $scripts, $localized, $styles );

		$handler = new Checkout_Handler_Location_Assets_Not_Built_Probe(
			$fields,
			'carrier',
			new Checkout_Handler_Fake_Location_Service( true )
		);
		$handler->enqueue_assets();

		// Files forced to report as not-yet-built — must never be enqueued.
		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-select-modes', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-styles', $styles );

		// But the location block itself DOES ride inside the existing config —
		// one config object, one enqueue path, unaffected by whether the
		// client-side files have landed yet.
		$this->assertCount( 1, $localized );
		$this->assertArrayHasKey( 'location', $localized[0][2] );
	}

	// -------------------------------------------------------------------------
	// Layer active AND the files exist — real enqueue, correct handles/deps
	// -------------------------------------------------------------------------

	public function test_location_scripts_enqueued_once_built(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$styles     = [];
		$this->wire_spies( $scripts, $localized, $styles );

		$handler = new Checkout_Handler_Location_Assets_Built_Probe(
			$fields,
			'carrier',
			new Checkout_Handler_Fake_Location_Service( true )
		);
		$handler->enqueue_assets();

		$this->assertArrayHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertStringContainsString( 'location-typeahead.js', $scripts['woodev-location-typeahead']['src'] );

		$this->assertArrayHasKey( 'woodev-location-cascade', $scripts );
		$this->assertStringContainsString( 'location-cascade.js', $scripts['woodev-location-cascade']['src'] );
		$this->assertContains( 'woodev-location-typeahead', $scripts['woodev-location-cascade']['deps'] );
		$this->assertContains( 'woodev-checkout-field-store', $scripts['woodev-location-cascade']['deps'] );

		// Task 13's renderer registry (spec D7) — enqueued with the SAME "selectWoo" dependency
		// `woodev-checkout-field-classic` already requires (select2 can only enhance a real
		// `<select>`), and declared as a dependency of the cascade itself so its own renderer
		// registration has always already run by the time the cascade reads it.
		$this->assertArrayHasKey( 'woodev-location-select-modes', $scripts );
		$this->assertStringContainsString( 'location-select-modes.js', $scripts['woodev-location-select-modes']['src'] );
		$this->assertContains( 'selectWoo', $scripts['woodev-location-select-modes']['deps'] );
		$this->assertContains( 'woodev-location-select-modes', $scripts['woodev-location-cascade']['deps'] );

		// The typeahead's own suggestion-listbox stylesheet — same "built" probe, same guard.
		$this->assertArrayHasKey( 'woodev-location-styles', $styles );
		$this->assertStringContainsString( 'location.css', $styles['woodev-location-styles']['src'] );

		$this->assertCount( 1, $localized );
		$this->assertArrayHasKey( 'location', $localized[0][2] );
	}

	// -------------------------------------------------------------------------
	// CSS content itself
	// -------------------------------------------------------------------------

	/*
	 * `location.css` styles (the isolation reset, the `!important` guard on `display`, the
	 * hostile-theme-resistant positioning) are NOT unit-testable here: no test in this suite
	 * renders CSS cascade resolution — PHPUnit/Brain Monkey exercises the enqueue WIRING only
	 * (handle registered, correct src/deps/version, guarded on the file existing on disk), never
	 * what a browser actually computes from the stylesheet's rules. That is exactly why the
	 * hostile-theme injection check on this widget belongs to a live-browser rig pass, the same
	 * way `docs-internal/gotchas/hostile-theme-button-display-none-needs-important.md` documents
	 * for `pickup.css`/`woodev-modal.css` — no unit test in this repo asserts a computed `display`
	 * value either, for the identical reason.
	 */
}
