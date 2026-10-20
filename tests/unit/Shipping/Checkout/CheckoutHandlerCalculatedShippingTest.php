<?php
/**
 * Tests for Checkout_Handler::handle_calculated_shipping() — the stored customer locality
 * versus the city the cart's shipping calculator just saved (issue #331, fix round 1).
 *
 * WooCommerce's text wins: a stored settlement record that no longer names the saved city is
 * forgotten; a matching record, a blank city and an absent record leave the store alone. A
 * different COUNTRY is not handled here — it already makes the record stale server-side
 * (`Location_Service::is_customer_record_stale()` rule b).
 *
 * Issue #1368: where the classic checkout ships to billing by default (`billing_only`, or
 * `billing` with the «ship to a different address» checkbox unchecked), the calculator's country /
 * region / postcode / city are ALSO written to billing (the checkout reads it) and the record is
 * judged against that address; where the checkbox is checked by default (`shipping`) billing is
 * never touched.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\{Checkout_Fields, Checkout_Handler, Field};
use Woodev\Framework\Shipping\Location\{Location_Provider_Registry, Location_Record, Location_Service};
use Woodev\Framework\Shipping\Pickup\Address_Target;
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-address-target.php';

final class Calculated_Shipping_Fake_Service extends Location_Service {

	public int $forgotten = 0;
	private ?Location_Record $record;

	public function __construct( ?Location_Record $record ) {
		$this->record = $record;
	}

	public function is_active(): bool {
		return true;
	}

	public function is_custom_settlement_allowed(): bool {
		return false;
	}

	public function get_field_mode_settlement(): string {
		return Location_Provider_Registry::MODE_AJAX_SELECT2;
	}

	public function is_level_chooser_available( string $level, ?string $country = null ): bool {
		return true;
	}

	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return $this->record;
	}

	public function forget_customer_record(): void {
		++$this->forgotten;
	}
}

final class Calculated_Shipping_Handler extends Checkout_Handler {

	public object $customer;

	protected function wc_customer() {
		return $this->customer;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_calculated_shipping
 */
class CheckoutHandlerCalculatedShippingTest extends TestCase {

	/**
	 * The default shipping destination unless a test says `billing_only`. Stubbed every time: once
	 * any other test has stubbed `wc_ship_to_billing_address_only()` the function exists for the
	 * rest of the process, and an unstubbed call would then throw instead of answering.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		Functions\when( 'wc_add_notice' )->justReturn( null );
	}

	private function record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'test:44',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Казань, Татарстан',
				'settlement'  => [ 'name' => 'Казань', 'type' => 'г' ],
			]
		);
	}

	private function run_with( ?Location_Record $record, string $city ): int {
		$service           = new Calculated_Shipping_Fake_Service( $record );
		$handler           = new Calculated_Shipping_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->customer = new class( $city ) {
			private string $city;
			public function __construct( string $city ) {
				$this->city = $city;
			}
			public function get_shipping_city(): string {
				return $this->city;
			}
		};

		$handler->handle_calculated_shipping();

		return $service->forgotten;
	}

	public function test_a_saved_city_that_names_another_settlement_forgets_the_record(): void {
		$this->assertSame( 1, $this->run_with( $this->record(), 'Москва' ) );
	}

	public function test_the_same_city_keeps_the_record_regardless_of_case_and_spacing(): void {
		$this->assertSame( 0, $this->run_with( $this->record(), '  казань ' ) );
	}

	/**
	 * @dataProvider same_city_spellings
	 */
	public function test_equivalent_spellings_of_the_stored_city_keep_the_record( string $record_name, string $saved ): void {
		$record = Location_Record::from_array(
			[
				'key'         => 'test:77',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => $record_name,
				'settlement'  => [ 'name' => $record_name, 'type' => 'г' ],
			]
		);

		$this->assertSame( 0, $this->run_with( $record, $saved ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function same_city_spellings(): array {
		return [
			'type prefix with dot'   => [ 'Москва', 'г. Москва' ],
			'type prefix word'       => [ 'Москва', 'город Москва' ],
			'type prefix no dot'     => [ 'Москва', 'г Москва' ],
			'yo folded'              => [ 'Королёв', 'Королев' ],
			'yo folded the other way' => [ 'Королев', 'Королёв' ],
			'case and spaces'        => [ 'Москва', '  москва ' ],
			'inner whitespace'       => [ 'Нижний Новгород', 'Нижний   Новгород' ],
			'prefix and yo'          => [ 'Королёв', 'г. Королев' ],
		];
	}

	public function test_a_genuinely_different_city_still_forgets_even_with_a_type_prefix(): void {
		$this->assertSame( 1, $this->run_with( $this->record(), 'г. Москва' ) );
		$this->assertSame( 1, $this->run_with( $this->record(), 'Казанка' ) );
	}

	public function test_a_blank_city_never_forgets(): void {
		$this->assertSame( 0, $this->run_with( $this->record(), '' ) );
	}

	public function test_no_stored_record_is_a_no_op(): void {
		$this->assertSame( 0, $this->run_with( null, 'Москва' ) );
	}

	/**
	 * A WC_Customer stand-in carrying both addresses; records what is written to billing.
	 *
	 * @param array<string, string> $shipping The calculator's address (country/state/postcode/city).
	 * @param array<string, string> $billing  The customer's earlier billing address.
	 */
	private function customer_with( array $shipping, array $billing ): object {
		return new class( $shipping, $billing ) {
			public array $shipping;
			public array $billing;
			public int $saves = 0;

			public function __construct( array $shipping, array $billing ) {
				$this->shipping = $shipping;
				$this->billing  = $billing;
			}

			public function get_shipping_country(): string {
				return $this->shipping['country'];
			}

			public function get_shipping_state(): string {
				return $this->shipping['state'];
			}

			public function get_shipping_postcode(): string {
				return $this->shipping['postcode'];
			}

			public function get_shipping_city(): string {
				return $this->shipping['city'];
			}

			public function get_billing_city(): string {
				return $this->billing['city'];
			}

			public function get_billing_postcode(): string {
				return $this->billing['postcode'];
			}

			public function set_billing_country( string $value ): void {
				$this->billing['country'] = $value;
			}

			public function set_billing_state( string $value ): void {
				$this->billing['state'] = $value;
			}

			public function set_billing_city( string $value ): void {
				$this->billing['city'] = $value;
			}

			public function set_billing_postcode( string $value ): void {
				$this->billing['postcode'] = $value;
			}

			public function save(): void {
				++$this->saves;
			}
		};
	}

	private function lipetsk_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'test:lip',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Липецк, Липецкая область',
				'settlement'  => [ 'name' => 'Липецк', 'type' => 'г' ],
			]
		);
	}

	/**
	 * @return array{0: Calculated_Shipping_Handler, 1: Calculated_Shipping_Fake_Service}
	 */
	private function handler_for( object $customer, ?Location_Record $record, string $destination ): array {
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( 'billing_only' === $destination );
		Functions\when( 'get_option' )->justReturn( $destination );

		$service           = new Calculated_Shipping_Fake_Service( $record );
		$handler           = new Calculated_Shipping_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->customer = $customer;

		return [ $handler, $service ];
	}

	/** @var array<string, string> */
	private const MOSCOW_BILLING = [ 'country' => 'RU', 'state' => 'RU-MOW', 'postcode' => '125009', 'city' => 'Москва' ];

	/** @var array<string, string> */
	private const LIPETSK_CALC = [ 'country' => 'RU', 'state' => 'RU-LIP', 'postcode' => '', 'city' => 'Липецк' ];

	public function test_billing_only_writes_the_calculator_address_to_billing_and_keeps_the_record(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler, $service ] = $this->handler_for( $customer, $this->lipetsk_record(), 'billing_only' );
		$handler->handle_calculated_shipping();

		$this->assertSame( 'Липецк', $customer->billing['city'] );
		$this->assertSame( 'RU-LIP', $customer->billing['state'] );
		$this->assertSame( 'RU', $customer->billing['country'] );
		$this->assertSame( 0, $service->forgotten, 'the picked record names the delivery (billing) city' );
		$this->assertSame( 1, $customer->saves );
	}

	public function test_billing_only_copies_the_calculator_postcode_even_when_blank(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler ] = $this->handler_for( $customer, $this->lipetsk_record(), 'billing_only' );
		$handler->handle_calculated_shipping();

		$this->assertSame( '', $customer->billing['postcode'], 'a Moscow postcode must not stay behind Lipetsk' );

		$customer = $this->customer_with( [ 'postcode' => '398000' ] + self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler ] = $this->handler_for( $customer, $this->lipetsk_record(), 'billing_only' );
		$handler->handle_calculated_shipping();

		$this->assertSame( '398000', $customer->billing['postcode'] );
	}

	public function test_billing_only_still_forgets_a_record_that_does_not_name_the_delivery_city(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler, $service ] = $this->handler_for( $customer, $this->record(), 'billing_only' );
		$handler->handle_calculated_shipping();

		$this->assertSame( 'Липецк', $customer->billing['city'] );
		$this->assertSame( 1, $service->forgotten, 'a Kazan record must not confirm a Lipetsk delivery address' );
	}

	public function test_billing_only_without_a_calculator_country_leaves_billing_alone(): void {
		$customer = $this->customer_with( [ 'country' => '' ] + self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler, $service ] = $this->handler_for( $customer, $this->lipetsk_record(), 'billing_only' );
		$handler->handle_calculated_shipping();

		$this->assertSame( self::MOSCOW_BILLING, $customer->billing );
		$this->assertSame( 0, $customer->saves );
		$this->assertSame( 0, $service->forgotten, 'the shipping city still names the record' );
	}

	public function test_the_default_mode_never_touches_billing_and_judges_the_shipping_city(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler, $service ] = $this->handler_for( $customer, $this->lipetsk_record(), 'shipping' );
		$handler->handle_calculated_shipping();

		$this->assertSame( self::MOSCOW_BILLING, $customer->billing );
		$this->assertSame( 0, $customer->saves );
		$this->assertSame( 0, $service->forgotten );

		[ $handler, $service ] = $this->handler_for( $customer, $this->record(), 'shipping' );
		$handler->handle_calculated_shipping();

		$this->assertSame( 1, $service->forgotten, 'a record for another city is still forgotten' );
		$this->assertSame( self::MOSCOW_BILLING, $customer->billing );
	}

	/**
	 * The calculator, then the classic checkout's guard exactly as `handle_checkout_process()`
	 * feeds it: billing is the posted address unless the checkbox is ticked, and the checkbox
	 * starts ticked only for the `shipping` destination.
	 */
	private function checkout_accepts_after_calculator( string $destination, ?Location_Record $record ): bool {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ , $service ] = $this->handler_for( $customer, $record, $destination );

		$handler           = new Calculated_Shipping_Handler(
			Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'text' )->source_location( 'settlement' )->to_array() ] ),
			'carrier',
			$service
		);
		$handler->customer = $customer;
		$handler->handle_calculated_shipping();

		$section = Address_Target::resolve( 'shipping' === $destination );
		$values  = [
			'billing_city'  => $customer->billing['city'],
			'shipping_city' => $customer->shipping['city'],
		];

		return $handler->guard_custom_settlement( $values, 'RU', $section );
	}

	public function test_billing_only_classic_checkout_accepts_the_calculators_record(): void {
		$this->assertTrue( $this->checkout_accepts_after_calculator( 'billing_only', $this->lipetsk_record() ) );
	}

	public function test_billing_destination_with_the_checkbox_unchecked_accepts_the_calculators_record(): void {
		$this->assertTrue( $this->checkout_accepts_after_calculator( 'billing', $this->lipetsk_record() ) );
	}

	public function test_shipping_destination_with_the_checkbox_checked_accepts_the_calculators_record(): void {
		$this->assertTrue( $this->checkout_accepts_after_calculator( 'shipping', $this->lipetsk_record() ) );
	}

	/**
	 * @dataProvider billing_defaulting_destinations
	 */
	public function test_a_record_for_another_city_is_still_refused_at_checkout( string $destination ): void {
		$this->assertFalse( $this->checkout_accepts_after_calculator( $destination, $this->record() ), 'a Kazan record must not confirm a Lipetsk address' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function billing_defaulting_destinations(): array {
		return [
			'billing_only' => [ 'billing_only' ],
			'billing'      => [ 'billing' ],
			'shipping'     => [ 'shipping' ],
		];
	}

	public function test_billing_destination_copies_the_calculator_address_to_billing(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler, $service ] = $this->handler_for( $customer, $this->lipetsk_record(), 'billing' );
		$handler->handle_calculated_shipping();

		$this->assertSame( 'Липецк', $customer->billing['city'] );
		$this->assertSame( 'RU-LIP', $customer->billing['state'] );
		$this->assertSame( '', $customer->billing['postcode'] );
		$this->assertSame( 0, $service->forgotten );
		$this->assertSame( 1, $customer->saves );
	}

	public function test_the_checkbox_default_filter_decides_the_target(): void {
		$customer = $this->customer_with( self::LIPETSK_CALC, self::MOSCOW_BILLING );

		[ $handler ] = $this->handler_for( $customer, $this->lipetsk_record(), 'shipping' );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'woocommerce_ship_to_different_address_checked' === $hook ? 0 : $value;
			}
		);
		$handler->handle_calculated_shipping();

		$this->assertSame( 'Липецк', $customer->billing['city'], 'a filter that unchecks the box makes billing the delivery address' );
	}
}
