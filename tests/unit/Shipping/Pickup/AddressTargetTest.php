<?php
/**
 * Unit tests for Address_Target — which fieldset (billing_* or shipping_*) receives a
 * pickup point's address, following WooCommerce's own posted-address resolution rather
 * than deriving it from the raw option.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Address_Target;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-address-target.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Address_Target
 */
final class AddressTargetTest extends TestCase {

	public function test_billing_only_mode_targets_billing_when_the_flag_is_true(): void {
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );
		$this->assertSame( 'billing', Address_Target::resolve( true ) );
	}

	public function test_billing_only_mode_targets_billing_when_the_flag_is_false(): void {
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );
		$this->assertSame( 'billing', Address_Target::resolve( false ) );
	}

	public function test_default_mode_with_the_flag_unset_targets_billing(): void {
		// "Ship to a different address" unchecked — WooCommerce copies billing to shipping
		// itself, so the target is billing.
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		$this->assertSame( 'billing', Address_Target::resolve( false ) );
	}

	public function test_default_mode_with_the_flag_set_targets_shipping(): void {
		// "Ship to a different address" checked — billing stays the customer's own address.
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		$this->assertSame( 'shipping', Address_Target::resolve( true ) );
	}
}
