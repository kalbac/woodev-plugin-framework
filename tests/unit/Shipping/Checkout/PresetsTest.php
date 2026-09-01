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

	// -------------------------------------------------------------------------
	// error_label default — #299/#134: a hidden pickup field legitimately has
	// no visual label, so the preset seeds a sensible messages-only default.
	// -------------------------------------------------------------------------

	public function test_pickup_field_sets_a_default_error_label(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array();
		$this->assertSame( 'Pickup point', $a['error_label'] );
	}

	public function test_pickup_field_leaves_the_visual_label_unset(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array();
		$this->assertArrayNotHasKey( 'label', $a );
	}

	public function test_pickup_field_default_error_label_is_overridable(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )
			->set_error_label( 'Пункт самовывоза' )
			->to_array();
		$this->assertSame( 'Пункт самовывоза', $a['error_label'] );
	}

	// -------------------------------------------------------------------------
	// Lazy default — issue #709: $pickup_method_ids is now optional. Omitted, the
	// field's required-ness is derived from is_pickup_shipping() at EVALUATION time
	// (see Checkout_Config::resolve_required()), never baked into an id list here.
	// -------------------------------------------------------------------------

	/**
	 * Omitting the id list produces the `is_pickup_method` sentinel operator — no
	 * `value` key at all, so the spec genuinely "stops naming ids" (card #709).
	 */
	public function test_pickup_field_omitted_list_uses_the_is_pickup_method_operator(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point' )->to_array();

		$this->assertSame( 'chosen_shipping_method', $a['required']['state'] );
		$this->assertSame( 'is_pickup_method', $a['required']['operator'] );
		$this->assertArrayNotHasKey( 'value', $a['required'] );
		$this->assertTrue( $a['is_pickup_slot'] );
		$this->assertSame( 'hidden', $a['type'] );
	}

	/**
	 * Explicit `null` (the default) behaves identically to omitting the argument —
	 * pins the default value itself, not only the omitted-argument call shape.
	 */
	public function test_pickup_field_explicit_null_list_also_derives(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', null )->to_array();

		$this->assertSame( 'is_pickup_method', $a['required']['operator'] );
	}

	/**
	 * The lazy default must never touch `WC()->shipping()` while the field is being
	 * BUILT (card #709's own trap: `Pickup_Field::create()` can run before WooCommerce
	 * has lazily loaded the shipping-method class at all — see that method's own
	 * docblock). `WC()` is undefined for this entire test file/process — if
	 * `create()` called it eagerly, this would fatal with "Call to undefined
	 * function WC()" rather than merely assert something wrong.
	 */
	public function test_pickup_field_omitted_list_does_not_touch_wc_shipping_when_built(): void {
		$this->assertFalse( function_exists( 'WC' ), 'WC() must be undefined for this pin to mean anything.' );

		$a = Pickup_Field::create( 'carrier_pickup_point' )->to_array();

		$this->assertSame( 'is_pickup_method', $a['required']['operator'] );
	}

	/**
	 * An explicit id list — even an empty one — is a real override, never confused
	 * with "omitted": `[]` means "never required by this mechanism", a DIFFERENT
	 * outcome from the derived default silently degrading to the same shape.
	 */
	public function test_pickup_field_explicit_empty_list_is_a_real_override_not_the_derive_default(): void {
		$a = Pickup_Field::create( 'carrier_pickup_point', [] )->to_array();

		$this->assertSame( 'in', $a['required']['operator'] );
		$this->assertSame( [], $a['required']['value'] );
	}
}
