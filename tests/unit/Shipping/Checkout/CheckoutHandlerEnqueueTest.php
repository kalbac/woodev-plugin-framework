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
 *   - no location scripts, no `location` config key, when the layer is inactive
 *   - no location scripts (but the config DOES carry `location`) when the layer
 *     is active but the Task 10/11 files are not yet on disk (the real state today)
 *   - location scripts enqueued with the right handles/paths/deps once the
 *     files exist (probed via an `asset_exists()` override, mirroring
 *     Pickup_Handler's own test-probe pattern)
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

	public function get_customer_record(): ?array {
		return null;
	}

	public function is_country_supported( string $country, ?string $level = null ): bool {
		return false;
	}

	public function provider_for_level( string $level ): ?Location_Provider {
		return null;
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

	private function wire_spies( array &$scripts, array &$localized ): void {
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps, $ver, $footer ) use ( &$scripts ) {
				$scripts[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
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
		$this->wire_spies( $scripts, $localized );

		$handler = new Checkout_Handler( $fields, 'carrier', new Checkout_Handler_Fake_Location_Service( false ) );
		$handler->enqueue_assets();

		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );

		$this->assertCount( 1, $localized );
		$this->assertArrayNotHasKey( 'location', $localized[0][2] );
	}

	public function test_no_location_scripts_and_no_location_config_block_when_no_service_injected(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$this->wire_spies( $scripts, $localized );

		// 2-arg construction — pre-Task-9 call sites keep working unchanged.
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->enqueue_assets();

		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );
		$this->assertArrayNotHasKey( 'location', $localized[0][2] );
	}

	// -------------------------------------------------------------------------
	// Layer active, Task 10/11 files not yet on disk — still no 404, config present
	// -------------------------------------------------------------------------

	public function test_active_but_unbuilt_location_assets_are_never_enqueued(): void {
		[ $fields ] = $this->fixture();
		$scripts    = [];
		$localized  = [];
		$this->wire_spies( $scripts, $localized );

		$handler = new Checkout_Handler( $fields, 'carrier', new Checkout_Handler_Fake_Location_Service( true ) );
		$handler->enqueue_assets();

		// Real files do not exist yet in this PR block — must never be enqueued.
		$this->assertArrayNotHasKey( 'woodev-location-typeahead', $scripts );
		$this->assertArrayNotHasKey( 'woodev-location-cascade', $scripts );

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
		$this->wire_spies( $scripts, $localized );

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

		$this->assertCount( 1, $localized );
		$this->assertArrayHasKey( 'location', $localized[0][2] );
	}
}
