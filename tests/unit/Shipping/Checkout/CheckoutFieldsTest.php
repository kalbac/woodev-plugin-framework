<?php
/**
 * Tests for Checkout_Fields::normalize() — generic descriptor keys.
 *
 * Covers the new keys added in 2.0.2: section, depends_on, source,
 * source_kind, and takeover_condition. Also covers the required-array
 * passthrough (condition-spec arrays must NOT be coerced to bool).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Fields::normalize
 */
class CheckoutFieldsTest extends TestCase {

	public function test_normalize_fills_new_keys_with_defaults(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'billing_city' ] );
		$this->assertSame( 'order', $field['section'] );
		$this->assertNull( $field['depends_on'] );
		$this->assertNull( $field['source'] );
		$this->assertNull( $field['source_kind'] );
		$this->assertNull( $field['takeover_condition'] );
		$this->assertFalse( $field['required'] );
	}

	public function test_normalize_keeps_condition_spec_required_as_array(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ];
		$this->assertSame( $spec, Checkout_Fields::normalize( [ 'id' => 'pvz', 'required' => $spec ] )['required'] );
	}

	public function test_normalize_drops_non_callable_source_and_keeps_callable(): void {
		$noop = static function () { return []; };
		$this->assertNull( Checkout_Fields::normalize( [ 'id' => 'a', 'source' => 'nope' ] )['source'] );
		$this->assertSame( $noop, Checkout_Fields::normalize( [ 'id' => 'b', 'source' => $noop ] )['source'] );
	}

	public function test_normalize_coerces_depends_on_and_source_kind(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'c', 'depends_on' => 'billing_state', 'source_kind' => 'suggest' ] );
		$this->assertSame( 'billing_state', $field['depends_on'] );
		$this->assertSame( 'suggest', $field['source_kind'] );
	}

	public function test_add_and_from_array_accept_field_instance(): void {
		// from_array path: a Field instance in the list is accepted and normalized.
		$collection = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' ) ] );
		$this->assertSame( 'select', $collection->get_field( 'billing_city' )['type'] );

		// add() path: a Field instance replaces the raw definition.
		$collection->add( Field::create( 'billing_city' )->set_type( 'hidden' ) );
		$this->assertSame( 'hidden', $collection->get_field( 'billing_city' )['type'] );
	}
}
