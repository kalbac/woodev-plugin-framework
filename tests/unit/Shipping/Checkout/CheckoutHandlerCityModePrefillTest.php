<?php
/**
 * Range-shipping city mode spec §4.4 (framework 2.0.26): the classic checkout view shows a
 * delivery City / street / postcode empty when WooCommerce would prefill them from a saved
 * city that names none of the listed places — the customer's stored address is untouched.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\{Checkout_Fields, Checkout_Handler};
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-address-target.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * A city-mode service that lists city names per country (an empty map = city mode off).
 */
final class City_Mode_Prefill_Fake_Service extends Location_Service {

	/** @var array<string, string[]> */
	private array $listed;

	/**
	 * @param array<string, string[]> $listed Listed city names, keyed by country.
	 */
	public function __construct( array $listed ) {
		$this->listed = $listed;
	}

	public function is_active(): bool {
		return true;
	}

	public function has_settlement_choices(): bool {
		return [] !== $this->listed;
	}

	public function has_settlement_choices_for( string $country ): bool {
		return [] !== ( $this->listed[ $country ] ?? [] );
	}

	public function names_listed_settlement( string $city, string $country = '' ): bool {
		return in_array( $city, $this->listed[ $country ] ?? [], true );
	}

	public function resolve_default_country(): string {
		return 'RU';
	}
}

/**
 * A checkout handler whose WooCommerce customer saved `$city` in `$country` on both addresses.
 */
final class City_Mode_Prefill_Handler extends Checkout_Handler {

	private string $city;

	private string $country;

	public function __construct( Location_Service $service, string $city, string $country = 'RU' ) {
		parent::__construct( new Checkout_Fields(), 'carrier', $service );
		$this->city    = $city;
		$this->country = $country;
	}

	protected function wc_customer() {
		return new class( $this->city, $this->country ) {
			private string $city;

			private string $country;

			public function __construct( string $city, string $country ) {
				$this->city    = $city;
				$this->country = $country;
			}

			public function get_billing_city(): string {
				return $this->city;
			}

			public function get_shipping_city(): string {
				return $this->city;
			}

			public function get_billing_country(): string {
				return $this->country;
			}

			public function get_shipping_country(): string {
				return $this->country;
			}
		};
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_checkout_get_value
 */
final class CheckoutHandlerCityModePrefillTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( 'billing' );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
	}

	/**
	 * @dataProvider delivery_prefill_fields
	 */
	public function test_a_saved_tula_prefill_is_blanked_in_the_delivery_section( string $input ): void {
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [ 'RU' => [ 'Москва', 'Химки' ] ] ), 'Тула' );

		$this->assertSame( '', $handler->handle_checkout_get_value( null, $input ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function delivery_prefill_fields(): array {
		return [
			'city'     => [ 'billing_city' ],
			'street'   => [ 'billing_address_1' ],
			'postcode' => [ 'billing_postcode' ],
		];
	}

	public function test_a_listed_saved_city_is_left_to_woocommerce(): void {
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [ 'RU' => [ 'Москва', 'Химки' ] ] ), 'Химки' );

		$this->assertNull( $handler->handle_checkout_get_value( null, 'billing_address_1' ) );
	}

	public function test_the_payer_section_is_not_the_delivery_section_and_stays_as_saved(): void {
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [ 'RU' => [ 'Москва' ] ] ), 'Тула' );

		// The store ships to billing by default, so the shipping column is not the delivery one.
		$this->assertNull( $handler->handle_checkout_get_value( null, 'shipping_city' ) );
	}

	public function test_a_saved_city_of_a_country_without_listed_places_is_left_to_woocommerce(): void {
		// RU lists places, the customer saved Ташкент in UZ: city mode does not apply there.
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [ 'RU' => [ 'Москва', 'Химки' ] ] ), 'Ташкент', 'UZ' );

		$this->assertNull( $handler->handle_checkout_get_value( null, 'billing_city' ) );
		$this->assertNull( $handler->handle_checkout_get_value( null, 'billing_address_1' ) );
	}

	public function test_a_saved_city_is_judged_by_the_places_of_its_own_country(): void {
		// «Алматы» is a listed KZ place, not an RU one.
		$service = new City_Mode_Prefill_Fake_Service(
			[
				'RU' => [ 'Москва', 'Химки' ],
				'KZ' => [ 'Алматы', 'Астана' ],
			]
		);

		$this->assertNull( ( new City_Mode_Prefill_Handler( $service, 'Алматы', 'KZ' ) )->handle_checkout_get_value( null, 'billing_city' ) );
		$this->assertSame( '', ( new City_Mode_Prefill_Handler( $service, 'Алматы', 'RU' ) )->handle_checkout_get_value( null, 'billing_city' ) );
	}

	public function test_a_customer_without_a_saved_country_is_judged_by_the_store_default_country(): void {
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [ 'RU' => [ 'Москва' ] ] ), 'Тула', '' );

		$this->assertSame( '', $handler->handle_checkout_get_value( null, 'billing_city' ) );
	}

	public function test_nothing_is_blanked_while_city_mode_is_off(): void {
		$handler = new City_Mode_Prefill_Handler( new City_Mode_Prefill_Fake_Service( [] ), 'Тула' );

		$this->assertNull( $handler->handle_checkout_get_value( null, 'billing_city' ) );
	}
}
