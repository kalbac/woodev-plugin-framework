<?php
/**
 * Tests for the block-checkout surface guard in Checkout_Handler::enqueue_assets() (SP-11 C-1, #1087):
 * a checkout page that renders the Checkout BLOCK must not boot the classic DOM adapter next to the
 * React chooser.
 *
 * Runs in its own process: the guard asks WooCommerce's `CartCheckoutUtils`, which does not exist in
 * the unit process (and must not, for every other test), so the test declares it.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::enqueue_assets
 */
class CheckoutHandlerBlocksSurfaceTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_checkout_boots_no_classic_script(): void {
		eval( 'namespace Automattic\WooCommerce\Blocks\Utils; class CartCheckoutUtils { public static function is_checkout_block_default() { return true; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );

		( new Checkout_Handler( $fields, 'carrier' ) )->enqueue_assets();

		$this->addToAssertionCount( 1 );
	}
}
