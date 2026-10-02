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
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Location\Location_Record;
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

final class Calculated_Shipping_Fake_Service extends Location_Service {

	public int $forgotten = 0;
	private ?Location_Record $record;

	public function __construct( ?Location_Record $record ) {
		$this->record = $record;
	}

	public function is_active(): bool {
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

	public function test_a_blank_city_never_forgets(): void {
		$this->assertSame( 0, $this->run_with( $this->record(), '' ) );
	}

	public function test_no_stored_record_is_a_no_op(): void {
		$this->assertSame( 0, $this->run_with( null, 'Москва' ) );
	}
}
