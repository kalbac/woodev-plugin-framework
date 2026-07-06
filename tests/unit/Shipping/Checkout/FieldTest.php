<?php
/**
 * Tests for Field fluent builder.
 *
 * Covers the full builder API: set_type, set_label, set_required,
 * depends_on, set_source, and the condition-spec passthrough for required.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Field
 */
class FieldTest extends TestCase {

	public function test_builder_produces_normalized_array(): void {
		$src   = static function () { return []; };
		$array = Field::create( 'billing_city' )
			->set_type( 'select' )
			->set_label( 'Город' )
			->set_required( true )
			->depends_on( 'billing_state' )
			->set_source( $src, 'suggest' )
			->to_array();
		$this->assertSame( 'billing_city', $array['id'] );
		$this->assertSame( 'select', $array['type'] );
		$this->assertSame( 'billing_state', $array['depends_on'] );
		$this->assertSame( 'suggest', $array['source_kind'] );
		$this->assertSame( $src, $array['source'] );
	}

	public function test_set_required_accepts_condition_spec(): void {
		$spec  = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'x' ] ];
		$array = Field::create( 'pvz' )->set_required( $spec )->to_array();
		$this->assertSame( $spec, $array['required'] );
	}
}
