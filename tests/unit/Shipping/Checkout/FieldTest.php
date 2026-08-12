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

	// -------------------------------------------------------------------------
	// source_location() — Task 9 (Location Provider layer)
	// -------------------------------------------------------------------------

	public function test_source_location_sets_source_kind_and_level(): void {
		$array = Field::create( 'billing_city' )->source_location( 'settlement' )->to_array();
		$this->assertSame( 'location', $array['source_kind'] );
		$this->assertSame( 'settlement', $array['location_level'] );
	}

	/**
	 * @dataProvider provide_levels
	 */
	public function test_source_location_carries_each_cascade_level( string $level ): void {
		$array = Field::create( 'x' )->source_location( $level )->to_array();
		$this->assertSame( $level, $array['location_level'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_levels(): array {
		return [
			'region'     => [ 'region' ],
			'settlement' => [ 'settlement' ],
			'address'    => [ 'address' ],
		];
	}

	public function test_source_location_does_not_set_a_source_callable(): void {
		$array = Field::create( 'billing_city' )->source_location( 'settlement' )->to_array();
		$this->assertArrayNotHasKey( 'source', $array );
	}
}
