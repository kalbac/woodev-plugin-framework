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
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Fields::validate_required_spec
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

	// -------------------------------------------------------------------------
	// error_label — messages-only label (#299, #134)
	// -------------------------------------------------------------------------

	public function test_normalize_error_label_defaults_to_empty_string(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'x' ] );
		$this->assertSame( '', $field['error_label'] );
	}

	public function test_normalize_carries_error_label_through(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'carrier_pickup_point', 'error_label' => 'Пункт выдачи' ] );
		$this->assertSame( 'Пункт выдачи', $field['error_label'] );
	}

	public function test_normalize_error_label_is_independent_of_label(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'x', 'label' => '', 'error_label' => 'Пункт выдачи' ] );
		$this->assertSame( '', $field['label'] );
		$this->assertSame( 'Пункт выдачи', $field['error_label'] );
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

	// -------------------------------------------------------------------------
	// location_level — Task 9 (Location Provider layer)
	// -------------------------------------------------------------------------

	public function test_normalize_location_level_defaults_to_null(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'x' ] );
		$this->assertNull( $field['location_level'] );
	}

	/**
	 * @dataProvider provide_location_levels
	 */
	public function test_normalize_carries_source_kind_location_and_level_for_each_cascade_level( string $level ): void {
		$field = Checkout_Fields::normalize( Field::create( 'billing_' . $level )->source_location( $level )->to_array() );
		$this->assertSame( 'location', $field['source_kind'] );
		$this->assertSame( $level, $field['location_level'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_location_levels(): array {
		return [
			'region'     => [ 'region' ],
			'settlement' => [ 'settlement' ],
			'address'    => [ 'address' ],
		];
	}

	public function test_normalize_coerces_location_level_to_string_and_drops_empty(): void {
		$this->assertNull( Checkout_Fields::normalize( [ 'id' => 'x', 'location_level' => '' ] )['location_level'] );
		$this->assertSame( 'region', Checkout_Fields::normalize( [ 'id' => 'x', 'location_level' => 'region' ] )['location_level'] );
	}

	// -------------------------------------------------------------------------
	// takeover_condition vs. source_location() — mutually exclusive (issue #474)
	// -------------------------------------------------------------------------

	/**
	 * A field declaring BOTH source_location() and set_takeover_condition() is a
	 * contradictory descriptor: the location source owns the field, so the
	 * takeover condition must be dropped and reported via _doing_it_wrong().
	 */
	public function test_normalize_drops_takeover_condition_on_location_field_and_warns(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->atLeast()->once();

		$field = Checkout_Fields::normalize(
			Field::create( 'billing_city' )
				->source_location( 'settlement' )
				->set_takeover_condition( static fn() => true )
				->to_array()
		);

		$this->assertNull( $field['takeover_condition'] );
		$this->assertSame( 'location', $field['source_kind'] );
	}

	/**
	 * A location field with NO takeover condition is a normal, legitimate
	 * configuration — must stay silent.
	 */
	public function test_normalize_location_field_without_takeover_is_silent(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->never();

		$field = Checkout_Fields::normalize(
			Field::create( 'billing_city' )->source_location( 'settlement' )->to_array()
		);

		$this->assertNull( $field['takeover_condition'] );
		$this->assertSame( 'location', $field['source_kind'] );
	}

	/**
	 * A takeover field that is NOT location-backed (the §8 demo's ordinary use
	 * case) must keep its condition untouched and stay silent.
	 */
	public function test_normalize_takeover_field_that_is_not_location_is_silent(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->never();

		$condition = static fn() => true;
		$field     = Checkout_Fields::normalize(
			Field::create( 'billing_state' )->set_takeover_condition( $condition )->to_array()
		);

		$this->assertSame( $condition, $field['takeover_condition'] );
		$this->assertNull( $field['source_kind'] );
	}

	public function test_normalize_is_pickup_slot_defaults_to_false(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'x' ] );
		$this->assertFalse( $field['is_pickup_slot'] );
	}

	public function test_normalize_is_pickup_slot_true_when_set(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'x', 'is_pickup_slot' => true ] );
		$this->assertTrue( $field['is_pickup_slot'] );
	}

	public function test_add_and_from_array_accept_field_instance(): void {
		// from_array path: a Field instance in the list is accepted and normalized.
		$collection = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' ) ] );
		$this->assertSame( 'select', $collection->get_field( 'billing_city' )['type'] );

		// add() path: a Field instance replaces the raw definition.
		$collection->add( Field::create( 'billing_city' )->set_type( 'hidden' ) );
		$this->assertSame( 'hidden', $collection->get_field( 'billing_city' )['type'] );
	}

	// -----------------------------------------------------------------------
	// Part A — register-time condition-spec validation (Task 7b)
	// -----------------------------------------------------------------------

	/**
	 * A single-condition spec with an unknown operator (e.g. 'inn') must fire
	 * _doing_it_wrong() at least once.
	 */
	public function test_malformed_required_operator_triggers_doing_it_wrong(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->atLeast()->once();
		Checkout_Fields::from_array( [ Field::create( 'pvz' )->set_required( [ 'state' => 's', 'operator' => 'inn', 'value' => [] ] )->to_array() ] );
	}

	/**
	 * A single-condition spec with a valid operator ('in') must NOT fire
	 * _doing_it_wrong().
	 */
	public function test_valid_spec_does_not_trigger_doing_it_wrong(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->never();
		Checkout_Fields::from_array( [ Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'x' ] ] )->to_array() ] );
	}

	/**
	 * A plain bool required=true must never fire _doing_it_wrong().
	 */
	public function test_bool_required_never_triggers_doing_it_wrong(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->never();
		Checkout_Fields::from_array( [ Field::create( 'a' )->set_required( true )->to_array() ] );
	}

	/**
	 * `is_pickup_method` (issue #709) is registered at exactly the moment
	 * {@see \Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create()}'s own
	 * docblock says the real id list cannot yet be resolved — it must be accepted
	 * here, at registration time, with no `value` key at all.
	 */
	public function test_is_pickup_method_operator_does_not_trigger_doing_it_wrong(): void {
		\Brain\Monkey\Functions\expect( '_doing_it_wrong' )->never();
		Checkout_Fields::from_array( [ Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'is_pickup_method' ] )->to_array() ] );
	}
}
