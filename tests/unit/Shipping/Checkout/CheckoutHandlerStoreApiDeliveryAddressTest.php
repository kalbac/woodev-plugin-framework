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
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/** @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::store_api_delivery_address */
class CheckoutHandlerStoreApiDeliveryAddressTest extends TestCase {

	public function test_billing_only_order_validates_locality_against_billing_address(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$order = Mockery::mock();
		$order->shouldReceive( 'get_billing_city' )->once()->andReturn( 'Подольск' );
		$order->shouldReceive( 'get_billing_state' )->once()->andReturn( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		$order->shouldReceive( 'get_billing_country' )->once()->andReturn( 'RU' );

		$this->assertSame( [ 'city' => 'Подольск', 'state' => 'МОСКОВСКАЯ ОБЛАСТЬ', 'country' => 'RU' ], $this->handler()->delivery_address( $order ) );
	}

	public function test_shipping_address_remains_the_order_locality_source_in_normal_mode(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		$order = Mockery::mock();
		$order->shouldReceive( 'get_shipping_city' )->once()->andReturn( 'Подольск' );
		$order->shouldReceive( 'get_shipping_state' )->once()->andReturn( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		$order->shouldReceive( 'get_shipping_country' )->once()->andReturn( 'RU' );

		$this->assertSame( [ 'city' => 'Подольск', 'state' => 'МОСКОВСКАЯ ОБЛАСТЬ', 'country' => 'RU' ], $this->handler()->delivery_address( $order ) );
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
