<?php
/**
 * Store API destination address selection for locality validation.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
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
require_once dirname( __DIR__ ) . '/Order/order-marker-fakes.php';

final class StoreApiValidationLocationService extends Location_Service {
	public int $forgetCalls = 0;

	/** @since 2.0.2 @return bool */
	public function is_active(): bool {
		return true;
	}

	/** @since 2.0.2 @param string $level @param string|null $for_country @return Location_Record|null */
	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return Location_Record::from_array( [
			'key'         => 'dadata:podolsk',
			'provider_id' => 'dadata',
			'level'       => 'settlement',
			'country'     => 'RU',
			'settlement'  => [ 'name' => 'Подольск', 'type' => 'г' ],
		] );
	}

	/** @since 2.0.2 @return void */
	public function forget_customer_record(): void {
		$this->forgetCalls++;
	}
}

/** @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::store_api_delivery_address */
class CheckoutHandlerStoreApiDeliveryAddressTest extends TestCase {

	public function test_billing_only_order_validates_locality_against_billing_address(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_billing_city' )->once()->andReturn( 'Подольск' );
		$order->shouldReceive( 'get_billing_state' )->once()->andReturn( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		$order->shouldReceive( 'get_billing_country' )->once()->andReturn( 'RU' );

		$this->assertSame( [ 'city' => 'Подольск', 'state' => 'МОСКОВСКАЯ ОБЛАСТЬ', 'country' => 'RU' ], $this->handler()->delivery_address( $order ) );
	}

	public function test_shipping_address_remains_the_order_locality_source_in_normal_mode(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_shipping_city' )->once()->andReturn( 'Подольск' );
		$order->shouldReceive( 'get_shipping_state' )->once()->andReturn( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		$order->shouldReceive( 'get_shipping_country' )->once()->andReturn( 'RU' );

		$this->assertSame( [ 'city' => 'Подольск', 'state' => 'МОСКОВСКАЯ ОБЛАСТЬ', 'country' => 'RU' ], $this->handler()->delivery_address( $order ) );
	}

	public function test_store_api_validation_forgets_record_using_billing_city_only_for_checkout_draft(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$service = new StoreApiValidationLocationService();
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'has_status' )->twice()->with( 'checkout-draft' )->andReturn( true, false );
		$order->shouldReceive( 'get_billing_city' )->once()->andReturn( 'Казань' );
		$order->shouldReceive( 'get_billing_state' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_billing_country' )->once()->andReturn( 'RU' );
		$errors = new \WP_Error();

		$handler->handle_store_api_validate_order( $order, $errors );

		$this->assertSame( 1, $service->forgetCalls );
	}

	public function test_store_api_validation_does_not_forget_current_record_for_an_existing_order(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$service = new StoreApiValidationLocationService();
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'has_status' )->twice()->with( 'checkout-draft' )->andReturn( false, false );
		$errors = new \WP_Error();

		$handler->handle_store_api_validate_order( $order, $errors );

		$this->assertSame( 0, $service->forgetCalls );
	}

	private function handler(): object {
		return new class() extends Checkout_Handler {
			public function __construct() {
				parent::__construct( Checkout_Fields::from_array( [] ), 'carrier' );
			}

			public function delivery_address( object $order ): array {
				return $this->store_api_delivery_address( $order );
			}
		};
	}
}
