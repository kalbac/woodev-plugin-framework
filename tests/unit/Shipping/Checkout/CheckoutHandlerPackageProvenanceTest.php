<?php
/**
 * Tests for Checkout_Handler::add_location_provenance_to_packages() (SP-11 C-1, #1087; round-1
 * review, finding 3): the customer's chosen locality rides in every shipping package, so
 * WooCommerce's rate cache — keyed by the package hash — is invalidated when the locality changes
 * and nothing else does.
 *
 * WooCommerce itself is not loaded in the unit process, so `Rate_Cache_Harness` reproduces the ONE
 * decision under test — «reuse the stored rates while the package hashes the same» — line for line
 * from `WC_Shipping::calculate_shipping_for_package()` / `get_package_hash()` (WooCommerce 11.1,
 * `includes/class-wc-shipping.php:324-330`, `:419-450`). It counts how often a carrier would be
 * asked; it is not a claim about anything else WooCommerce does.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
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

final class Package_Provenance_Fake_Service extends Location_Service {

	public bool $active = true;

	public string $provenance = '';

	public function __construct() {
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_provenance(): string {
		return $this->provenance;
	}
}

/**
 * WooCommerce's rate-cache decision for one request: a package is recalculated when no rates are
 * stored for its key or the stored hash differs from the package's.
 */
final class Rate_Cache_Harness {

	/** How many times a shipping method's `calculate_shipping()` would have run. */
	public int $calculations = 0;

	/** @var array<string, array{package_hash: string}> the session's `shipping_for_package_N` entries */
	private array $session = [];

	/**
	 * @param array<int, array<string, mixed>> $packages What `woocommerce_cart_shipping_packages` answered.
	 */
	public function rate( array $packages ): void {
		foreach ( $packages as $key => $package ) {
			$hash   = 'wc_ship_' . md5( (string) json_encode( $package ) );
			$stored = $this->session[ 'shipping_for_package_' . $key ] ?? null;

			if ( ! is_array( $stored ) || $hash !== $stored['package_hash'] ) {
				++$this->calculations;
				$this->session[ 'shipping_for_package_' . $key ] = [ 'package_hash' => $hash ];
			}
		}
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::add_location_provenance_to_packages
 */
class CheckoutHandlerPackageProvenanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Checkout_Handler::reset_native_field_registry();
	}

	protected function tearDown(): void {
		Checkout_Handler::reset_native_field_registry();
		parent::tearDown();
	}

	/** The cart's one package: the same destination on every request. */
	private function packages(): array {
		return [
			[
				'contents'    => [],
				'destination' => [ 'country' => 'RU', 'state' => '', 'postcode' => '', 'city' => 'Подольск' ],
			],
		];
	}

	private function registered_service(): Package_Provenance_Fake_Service {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		$service = new Package_Provenance_Fake_Service();

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service ) )->register();

		return $service;
	}

	public function test_register_wires_one_static_callback_however_many_plugins_register(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\expect( 'add_filter' )
			->twice()
			->with( 'woocommerce_cart_shipping_packages', [ Checkout_Handler::class, 'add_location_provenance_to_packages' ] );
		Functions\expect( 'add_filter' )->zeroOrMoreTimes()->withAnyArgs();

		// A `[ ClassName, 'method' ]` callback has one id: WordPress stores it once for both plugins.
		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier-a', new Package_Provenance_Fake_Service() ) )->register();
		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier-b', new Package_Provenance_Fake_Service() ) )->register();
	}

	public function test_an_explicit_locality_is_added_to_every_package(): void {
		$service             = $this->registered_service();
		$service->provenance = 'dadata:podolsk';
		$packages            = array_merge( $this->packages(), $this->packages() );

		$filtered = Checkout_Handler::add_location_provenance_to_packages( $packages );

		$this->assertSame( 'dadata:podolsk', $filtered[0]['woodev_location'] );
		$this->assertSame( 'dadata:podolsk', $filtered[1]['woodev_location'] );
		$this->assertSame( $packages[0]['destination'], $filtered[0]['destination'] );
	}

	public function test_packages_are_left_alone_without_an_explicit_locality(): void {
		$service = $this->registered_service();

		// No record, or the store's own implicit default: both are `''` (rated the same).
		$this->assertSame( $this->packages(), Checkout_Handler::add_location_provenance_to_packages( $this->packages() ) );

		$service->provenance = 'dadata:podolsk';
		$service->active     = false;

		$this->assertSame( $this->packages(), Checkout_Handler::add_location_provenance_to_packages( $this->packages() ) );
	}

	public function test_packages_are_left_alone_before_any_handler_registered_and_for_a_non_array(): void {
		$this->assertSame( $this->packages(), Checkout_Handler::add_location_provenance_to_packages( $this->packages() ) );
		$this->assertFalse( Checkout_Handler::add_location_provenance_to_packages( false ) );
		$this->assertSame( [], Checkout_Handler::add_location_provenance_to_packages( [] ) );
	}

	/**
	 * The scenario of finding 3: the native address — the package destination — never changes, only
	 * the saved locality does. Every change must make the carrier calculate again; an unchanged
	 * locality must keep using the stored rates.
	 */
	public function test_a_changed_locality_recalculates_rates_for_an_unchanged_destination(): void {
		$service = $this->registered_service();
		$cache   = new Rate_Cache_Harness();
		$rate    = fn() => $cache->rate( Checkout_Handler::add_location_provenance_to_packages( $this->packages() ) );

		$service->provenance = 'dadata:podolsk';
		$rate();
		$this->assertSame( 1, $cache->calculations );

		$rate();
		$this->assertSame( 1, $cache->calculations, 'an unchanged locality must reuse the stored rates' );

		// `/forget` lands: same destination, no record.
		$service->provenance = '';
		$rate();
		$this->assertSame( 2, $cache->calculations, 'a forgotten locality must be rated again' );

		// A homonymous city picked for the same city text.
		$service->provenance = 'dadata:podolsk-2';
		$rate();
		$this->assertSame( 3, $cache->calculations, 'a different record must be rated again' );
	}

	public function test_without_the_filter_the_same_changes_would_reuse_stale_rates(): void {
		// The control: WooCommerce's hash alone does not see the locality at all.
		$cache = new Rate_Cache_Harness();

		$cache->rate( $this->packages() );
		$cache->rate( $this->packages() );
		$cache->rate( $this->packages() );

		$this->assertSame( 1, $cache->calculations );
	}
}
