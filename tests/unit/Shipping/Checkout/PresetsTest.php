<?php
/**
 * Tests for Checkout Field Presets: Dependent_Select and Pickup_Field.
 *
 * Covers that each static factory returns a correctly-configured Field
 * builder — type, depends_on, required condition-spec, and is_pickup_slot
 * marker. No domain data is baked in; all ids and method lists are caller-
 * supplied.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select;
use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/presets/class-dependent-select.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/presets/class-pickup-field.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select
 * @covers \Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field
 */
class PresetsTest extends TestCase {

	public function test_dependent_select_sets_type_and_parent(): void {
		$a = Dependent_Select::create( 'billing_city', 'billing_state' )->set_label( 'Город' )->to_array();
		$this->assertSame( 'select', $a['type'] );
		$this->assertSame( 'billing_state', $a['depends_on'] );
	}

	public function test_dependent_select_id_is_set(): void {
		$a = Dependent_Select::create( 'billing_city', 'billing_state' )->to_array();
		$this->assertSame( 'billing_city', $a['id'] );
	}

	public function test_dependent_select_returns_field_instance(): void {
		$field = Dependent_Select::create( 'billing_city', 'billing_state' );
		// Must be a Field builder so further methods can be chained.
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Checkout\Field::class, $field );
	}

	public function test_pickup_field_is_hidden_and_required_when_method_chosen(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array();
		$this->assertSame( 'hidden', $a['type'] );
		$this->assertSame( 'in', $a['required']['operator'] );
		$this->assertSame( [ 'carrier_pickup' ], $a['required']['value'] );
		$this->assertSame( 'chosen_shipping_method', $a['required']['state'] );
		$this->assertTrue( $a['is_pickup_slot'] );
	}

	public function test_pickup_field_id_is_set(): void {
		$a = Pickup_Field::create( 'yandex_pvz', [ 'yandex_pickup' ] )->to_array();
		$this->assertSame( 'yandex_pvz', $a['id'] );
	}

	public function test_pickup_field_normalises_pickup_method_ids_to_list(): void {
		$a = Pickup_Field::create( 'pvz', [ 'method_a', 'method_b' ] )->to_array();
		$this->assertSame( [ 'method_a', 'method_b' ], $a['required']['value'] );
	}

	public function test_pickup_field_returns_field_instance(): void {
		$field = Pickup_Field::create( 'carrier_pvz', [ 'carrier_pickup' ] );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Checkout\Field::class, $field );
	}
}
