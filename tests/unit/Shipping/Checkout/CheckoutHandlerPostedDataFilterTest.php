<?php
/**
 * Tests for the `woodev_shipping_checkout_posted_data` seam in Checkout_Handler (#1556).
 *
 * A classic checkout whose form lost the `order` section posts no pickup field at all. The
 * handler reads the posted data through the filter, so a contributor (the pickup handler, from
 * the session) can supply the value, and the validation and the order save see the same one.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\{Checkout_Fields, Checkout_Handler, Field};
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

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::get_posted_data
 */
final class CheckoutHandlerPostedDataFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias( static fn( $value ) => is_string( $value ) ? trim( $value ) : $value );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		$_POST = [ 'shipping_method' => [ 'carrier_pickup:7' ] ];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	private function handler(): Checkout_Handler {
		$handler = new Checkout_Handler(
			Checkout_Fields::from_array( [ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ] ),
			'carrier'
		);
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

		return $handler;
	}

	public function test_validation_blocks_a_pickup_method_when_nothing_supplies_the_point(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'wc_add_notice' )->atLeast()->once();

		$this->handler()->handle_checkout_process();
	}

	public function test_validation_accepts_a_point_contributed_through_the_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'woodev_shipping_checkout_posted_data' === $hook ) {
					$value['carrier_pvz'] = 'P-SESSION';
				}

				return $value;
			}
		);
		Functions\expect( 'wc_add_notice' )->never();

		$this->handler()->handle_checkout_process();
	}

	public function test_a_filter_returning_a_non_array_leaves_the_posted_data_standing(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'woodev_shipping_checkout_posted_data' === $hook ? null : $value
		);
		Functions\expect( 'wc_add_notice' )->atLeast()->once();

		$this->handler()->handle_checkout_process();
	}
}
