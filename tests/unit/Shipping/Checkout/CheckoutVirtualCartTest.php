<?php
/**
 * Tests for issue #1664: a cart with nothing to ship (virtual/downloadable products only)
 * must not get the hidden city/settlement field back, nor a settlement validation error.
 *
 * Every place the checkout layer touches the Location-Provider fields asks the one shared
 * answer, {@see Checkout_Config::cart_needs_shipping()}:
 *   - `Checkout_Field_Policy::filter_checkout_fields()` — the city invariant restoration;
 *   - `Checkout_Field_Policy::filter_country_locale()` — the locale's `city.required` invariant;
 *   - `Checkout_Handler::cart_effective_fields()` — the descriptors the browser config publishes;
 *   - `Checkout_Handler::inject()` — the managed (location) field injection;
 *   - `Checkout_Handler::validate()` — the location field's own `required` check;
 *   - `Checkout_Handler::guard_custom_settlement()` — the #531 settlement backstop.
 * A physical cart keeps the behaviour it always had.
 *
 * Every test runs in its own process: a mocked `WC()` outlives the test otherwise (see
 * `CheckoutFieldPolicyTest::test_filter_country_locale_reads_effective_settings_and_shipping_countries()`).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\{Checkout_Config, Checkout_Field_Environment, Checkout_Field_Policy, Checkout_Field_Settings, Checkout_Fields, Checkout_Handler, Field};
use Woodev\Framework\Shipping\Location\{Location_Provider_Registry, Location_Record, Location_Service};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';

/**
 * A {@see Location_Service} that insists on a picked settlement record (option OFF, `ajax-select2`)
 * and has none — the situation in which {@see Checkout_Handler::guard_custom_settlement()} refuses.
 */
final class Virtual_Cart_Fake_Location_Service extends Location_Service {

	public function __construct() {}

	public function is_level_chooser_available( string $level, ?string $country = null ): bool {
		return true;
	}

	public function is_custom_settlement_allowed(): bool {
		return false;
	}

	public function get_field_mode_settlement(): string {
		return Location_Provider_Registry::MODE_AJAX_SELECT2;
	}

	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return null;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Config::cart_needs_shipping
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::filter_checkout_fields
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::filter_country_locale
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::locale_contribution
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::cart_effective_fields
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::guard_custom_settlement
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::validate
 */
final class CheckoutVirtualCartTest extends TestCase {

	private const NOT_IN_LIST = 'The selected locality is not in the list — pick it from the suggestions.';

	protected function setUp(): void {
		parent::setUp();

		Checkout_Field_Policy::reset_for_tests();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
	}

	protected function tearDown(): void {
		Checkout_Field_Policy::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Stubs `WC()` with a cart that does (or does not) need shipping.
	 *
	 * @param bool $needs_shipping What `WC()->cart->needs_shipping()` answers.
	 */
	private function stub_cart( bool $needs_shipping ): void {
		Functions\when( 'WC' )->justReturn(
			(object) [
				'customer'  => null,
				'cart'      => new class( $needs_shipping ) {
					private bool $needs_shipping;

					public function __construct( bool $needs_shipping ) {
						$this->needs_shipping = $needs_shipping;
					}

					public function needs_shipping(): bool {
						return $this->needs_shipping;
					}
				},
				'countries' => new class() {
					public function get_default_address_fields(): array {
						return [ 'city' => [ 'label' => 'Город', 'required' => true ] ];
					}
				},
			]
		);
	}

	private static function settlement_handler( bool $required = false ): Checkout_Handler {
		return new Checkout_Handler(
			Checkout_Fields::from_array( [
				Field::create( 'billing_city' )->set_type( 'text' )->set_required( $required )->source_location( 'settlement' )->to_array(),
			] ),
			'carrier',
			new Virtual_Cart_Fake_Location_Service()
		);
	}

	private function policy(): Checkout_Field_Policy {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		$policy = Checkout_Field_Policy::instance();
		$policy->register( new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) ) );

		return $policy;
	}

	// -------------------------------------------------------------------------
	// Checkout_Config::cart_needs_shipping()
	// -------------------------------------------------------------------------

	public function test_cart_needs_shipping_is_true_without_woocommerce(): void {
		$this->assertTrue( Checkout_Config::cart_needs_shipping() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_needs_shipping_is_true_without_a_cart(): void {
		Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

		$this->assertTrue( Checkout_Config::cart_needs_shipping() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_needs_shipping_follows_the_cart(): void {
		$this->stub_cart( false );

		$this->assertFalse( Checkout_Config::cart_needs_shipping() );
	}

	/**
	 * The order-pay page carries whatever cart the session happens to hold, not the order being paid.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_needs_shipping_is_true_on_the_order_pay_page(): void {
		$this->stub_cart( false );
		Functions\when( 'is_checkout_pay_page' )->justReturn( true );

		$this->assertTrue( Checkout_Config::cart_needs_shipping() );
	}

	// -------------------------------------------------------------------------
	// Checkout_Field_Policy::filter_checkout_fields() — the city invariant
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_does_not_get_the_removed_city_field_back(): void {
		$this->stub_cart( false );

		$out = $this->policy()->filter_checkout_fields( [ 'billing' => [], 'shipping' => [] ] );

		$this->assertArrayNotHasKey( 'billing_city', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_city', $out['shipping'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_keeps_a_city_field_the_site_made_optional(): void {
		$this->stub_cart( false );

		$out = $this->policy()->filter_checkout_fields( [ 'billing' => [ 'billing_city' => [ 'required' => false ] ] ] );

		$this->assertFalse( $out['billing']['billing_city']['required'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_still_gets_the_city_field_restored(): void {
		$this->stub_cart( true );

		$out = $this->policy()->filter_checkout_fields( [ 'billing' => [], 'shipping' => [] ] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
	}

	// -------------------------------------------------------------------------
	// Checkout_Handler::guard_custom_settlement()
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_is_not_refused_for_a_settlement_that_was_not_picked(): void {
		$this->stub_cart( false );
		Functions\expect( 'wc_add_notice' )->never();

		$this->assertTrue( self::settlement_handler()->guard_custom_settlement( [ 'billing_city' => 'Совсем другой город' ], 'RU', 'billing' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_is_still_refused_for_a_settlement_that_was_not_picked(): void {
		$this->stub_cart( true );
		Functions\expect( 'wc_add_notice' )->once()->with( self::NOT_IN_LIST, 'error' );

		$this->assertFalse( self::settlement_handler()->guard_custom_settlement( [ 'billing_city' => 'Совсем другой город' ], 'RU', 'billing' ) );
	}

	// -------------------------------------------------------------------------
	// Checkout_Handler::validate() / inject()
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_does_not_owe_a_required_location_field(): void {
		$this->stub_cart( false );
		Functions\expect( 'wc_add_notice' )->never();

		$this->assertTrue( self::settlement_handler( true )->validate( [] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_still_owes_a_required_location_field(): void {
		$this->stub_cart( true );
		Functions\expect( 'wc_add_notice' )->atLeast()->once();

		$this->assertFalse( self::settlement_handler( true )->validate( [] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_leaves_the_checkout_fields_as_the_site_set_them(): void {
		$this->stub_cart( false );

		$wc  = [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'required' => false ] ] ];
		$out = self::settlement_handler( true )->inject( $wc );

		$this->assertSame( $wc, $out );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_still_gets_the_location_field_injected(): void {
		$this->stub_cart( true );

		$out = self::settlement_handler( true )->inject( [ 'billing' => [], 'shipping' => [] ] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
	}

	// -------------------------------------------------------------------------
	// Checkout_Field_Policy::filter_country_locale() — reaches the block checkout too
	// -------------------------------------------------------------------------

	/**
	 * `shipping_countries()` reads `WC()->countries`; give the stub one.
	 */
	private function stub_cart_with_countries( bool $needs_shipping ): void {
		$this->stub_cart( $needs_shipping );
		Functions\when( 'WC' )->justReturn(
			(object) [
				'customer'  => null,
				'cart'      => WC()->cart,
				'countries' => new class() {
					public function get_shipping_countries(): array {
						return [ 'RU' => 'Россия' ];
					}

					public function get_default_address_fields(): array {
						return [];
					}
				},
			]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_keeps_the_locale_city_requirement_as_it_was(): void {
		$this->stub_cart_with_countries( false );

		$out = $this->policy()->filter_country_locale( [ 'RU' => [ 'city' => [ 'required' => false ] ] ] );

		$this->assertFalse( $out['RU']['city']['required'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_still_gets_the_locale_city_required(): void {
		$this->stub_cart_with_countries( true );

		$out = $this->policy()->filter_country_locale( [ 'RU' => [ 'city' => [ 'required' => false ] ] ] );

		$this->assertTrue( $out['RU']['city']['required'] );
	}

	// -------------------------------------------------------------------------
	// Checkout_Handler::cart_effective_fields() — what the browser config publishes
	// -------------------------------------------------------------------------

	/**
	 * The descriptors the classic browser config is built from (`enqueue_assets()` feeds
	 * `cart_effective_fields()` to `Checkout_Config::build()`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function published_descriptors( Checkout_Fields $fields ): array {
		$handler = new class( $fields, 'carrier', new Virtual_Cart_Fake_Location_Service() ) extends Checkout_Handler {
			public function published(): array {
				return $this->cart_effective_fields();
			}
		};

		return $handler->published();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_virtual_cart_publishes_no_location_descriptor_but_keeps_the_others(): void {
		$this->stub_cart( false );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'text' )->set_required( true )->source_location( 'settlement' )->to_array(),
			Field::create( 'billing_phone' )->set_type( 'tel' )->to_array(),
		] );

		$this->assertSame( [ 'billing_phone' ], array_keys( self::published_descriptors( $fields ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_physical_cart_publishes_the_location_descriptors(): void {
		$this->stub_cart( true );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'text' )->set_required( true )->source_location( 'settlement' )->to_array(),
		] );

		$this->assertSame( [ 'billing_city', 'shipping_city' ], array_keys( self::published_descriptors( $fields ) ) );
	}
}
