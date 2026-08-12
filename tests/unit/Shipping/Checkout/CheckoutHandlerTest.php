<?php
/**
 * Tests for Checkout_Handler::maybe_suppress_wc_address_providers() — the
 * server-side half of Task 12's WC Address Autocomplete arbitration (spec D2,
 * 2026-08-12 location-provider plan).
 *
 * When the Location Provider layer is active AND every WC selling country is
 * covered by the D15 provider chain, the documented full kill
 * (`woocommerce_address_providers` filtered to `[]` at `PHP_INT_MAX`) is
 * applied. A mixed-country store — one selling to at least one country our
 * layer does NOT cover — must keep WC's own autocomplete alive for those
 * countries, so the filter is left completely untouched (gotcha
 * `wc-address-autocomplete-hosts-only-address1-and-flattens-identity`; spec
 * §4.7).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
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
 * exactly the shape it needs" discipline as `Checkout_Handler_Fake_Location_Service`
 * in `CheckoutHandlerEnqueueTest`. `is_active()` and `get_supported_countries()`
 * are the only two methods Task 12's suppression check reads.
 */
final class Checkout_Handler_Suppression_Fake_Location_Service extends Location_Service {

	private bool $active;

	/** @var string[] */
	private array $supported_countries;

	/**
	 * @param string[] $supported_countries
	 */
	public function __construct( bool $active, array $supported_countries = [] ) {
		$this->active              = $active;
		$this->supported_countries = $supported_countries;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_supported_countries(): array {
		return $this->supported_countries;
	}
}

/**
 * A probe that hands back a fixed WC "selling countries" list without
 * bootstrapping WooCommerce — mirrors every other `wc_*` seam this class
 * already exposes for testability (e.g. `wc_country_codes()` in
 * `CheckoutConfigTest`).
 */
class Checkout_Handler_Selling_Countries_Probe extends Checkout_Handler {

	/** @var string[] */
	private array $selling_countries;

	/**
	 * @param string[] $selling_countries
	 */
	public function __construct(
		Checkout_Fields $fields,
		string $hook_prefix,
		?Location_Service $location_service,
		array $selling_countries
	) {
		parent::__construct( $fields, $hook_prefix, $location_service );
		$this->selling_countries = $selling_countries;
	}

	protected function wc_selling_country_codes(): array {
		return $this->selling_countries;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::maybe_suppress_wc_address_providers
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::wc_selling_country_codes
 */
/**
 * A {@see Checkout_Handler} whose customer seam returns a fixed shipping state.
 */
final class Checkout_Handler_Customer_Probe extends Checkout_Handler {

	/** @var object */
	private $customer;

	public function __construct( Checkout_Fields $fields, string $plugin_id, string $state ) {
		parent::__construct( $fields, $plugin_id );

		$this->customer = new class( $state ) {
			private string $state;
			public function __construct( string $state ) {
				$this->state = $state;
			}
			public function get_shipping_state(): string {
				return $this->state;
			}
		};
	}

	protected function wc_customer() {
		return $this->customer;
	}
}

class CheckoutHandlerTest extends TestCase {

	// -------------------------------------------------------------------------
	// WooCommerce's `*` "no state" sentinel must never surface in a managed field
	// -------------------------------------------------------------------------

	/**
	 * `woocommerce_default_country` is stored as `COUNTRY:STATE`, and a merchant who picked a
	 * country without naming a state gets `RU:*` — so WooCommerce resolves the customer's
	 * default state to the literal `*`. Natively that is invisible (a state field is a
	 * `<select>`, and `*` matches no option); a field this layer manages is a text input, so
	 * the sentinel would be shown to the customer and submitted as if they had typed it.
	 */
	/**
	 * `woocommerce_checkout_get_value` is a SHORT-CIRCUIT filter: WC applies it with `null`
	 * before resolving anything and honours a non-null answer. So the callback must resolve
	 * the value itself; a callback written to receive `'*'` is never called with it.
	 */
	private function handler_with_customer_state( string $state ): Checkout_Handler {
		// A probe subclass, NOT `Functions\when( 'WC' )`: mocking WC with Brain Monkey defines
		// the function globally and PHP cannot un-define it, so it leaks into every later test
		// in the process (gotcha `brain-monkey-function-pollution`) — it broke six unrelated
		// PickupHandlerTest cases that assert WooCommerce is ABSENT.
		return new Checkout_Handler_Customer_Probe(
			Checkout_Fields::from_array( [ Field::create( 'shipping_state' )->to_array() ] ),
			'carrier',
			$state
		);
	}

	public function test_the_wc_no_state_sentinel_is_blanked_for_a_managed_field(): void {
		$handler = $this->handler_with_customer_state( '*' );

		$this->assertSame( '', $handler->handle_checkout_get_value( null, 'shipping_state' ) );
	}

	public function test_a_real_customer_state_is_left_to_wc_to_resolve(): void {
		$handler = $this->handler_with_customer_state( 'Москва' );

		// null == "carry on, WC" — we only ever short-circuit the sentinel.
		$this->assertNull( $handler->handle_checkout_get_value( null, 'shipping_state' ) );
	}

	public function test_a_field_this_layer_does_not_manage_is_never_touched(): void {
		$handler = $this->handler_with_customer_state( '*' );

		$this->assertNull( $handler->handle_checkout_get_value( null, 'billing_state' ) );
	}

	public function test_an_earlier_filter_answer_is_respected(): void {
		$handler = $this->handler_with_customer_state( '*' );

		$this->assertSame( 'уже решено', $handler->handle_checkout_get_value( 'уже решено', 'shipping_state' ) );
	}

	private function handler( bool $active, array $supported, array $selling ): Checkout_Handler {
		return new Checkout_Handler_Selling_Countries_Probe(
			Checkout_Fields::from_array( [] ),
			'carrier',
			new Checkout_Handler_Suppression_Fake_Location_Service( $active, $supported ),
			$selling
		);
	}

	// -------------------------------------------------------------------------
	// Full kill — every selling country covered
	// -------------------------------------------------------------------------

	public function test_filter_applied_at_php_int_max_when_every_selling_country_is_covered(): void {

		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_address_providers', '__return_empty_array', PHP_INT_MAX );

		$this->handler( true, [ 'RU', 'KZ' ], [ 'RU' ] )->maybe_suppress_wc_address_providers();
	}

	public function test_filter_applied_when_selling_countries_exactly_match_the_supported_set(): void {

		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_address_providers', '__return_empty_array', PHP_INT_MAX );

		$this->handler( true, [ 'RU', 'KZ' ], [ 'RU', 'KZ' ] )->maybe_suppress_wc_address_providers();
	}

	// -------------------------------------------------------------------------
	// Otherwise — the filter must NOT be touched at all
	// -------------------------------------------------------------------------

	public function test_filter_not_touched_when_a_selling_country_is_outside_the_supported_set(): void {

		Functions\expect( 'add_filter' )->never();

		// A mixed-country store: RU is covered, US is not — WC's own autocomplete
		// must keep serving US.
		$this->handler( true, [ 'RU' ], [ 'RU', 'US' ] )->maybe_suppress_wc_address_providers();
	}

	public function test_filter_not_touched_when_layer_is_inactive(): void {

		Functions\expect( 'add_filter' )->never();

		$this->handler( false, [ 'RU' ], [ 'RU' ] )->maybe_suppress_wc_address_providers();
	}

	public function test_filter_not_touched_when_no_selling_countries_are_known(): void {

		Functions\expect( 'add_filter' )->never();

		// WC not bootstrapped (e.g. `WC()->countries` unavailable) — never claim
		// full coverage of an unknown set.
		$this->handler( true, [ 'RU' ], [] )->maybe_suppress_wc_address_providers();
	}

	public function test_filter_not_touched_when_the_active_provider_covers_nothing(): void {

		Functions\expect( 'add_filter' )->never();

		$this->handler( true, [], [ 'RU' ] )->maybe_suppress_wc_address_providers();
	}
}
