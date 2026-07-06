<?php
/**
 * Tests for Checkout_Handler::inject() — enhance-in-place, per-section, options pre-fill.
 *
 * Covers Task 6 of the checkout-field-layer plan (2026-07-06):
 *   - existing WC field is enhanced in-place (our keys override, WC keys preserved)
 *   - Codex MED #8: WC's `validate`, `class`, `priority` survive a merge
 *   - a genuinely new field is added under its own section
 *   - an options-kind root field has its source() called to pre-fill native select options
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject
 */
class CheckoutHandlerInjectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// inject() calls apply_filters — pass the second arg through unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	// -------------------------------------------------------------------------
	// Enhance existing field in place — conservative merge (Codex MED #8)
	// -------------------------------------------------------------------------

	public function test_inject_enhances_existing_field_in_place(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$wc = [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'class' => [ 'form-row-wide' ], 'priority' => 70, 'validate' => [ 'city' ] ] ] ];
		$out = $handler->inject( $wc );

		$this->assertSame( 'select', $out['billing']['billing_city']['type'] );             // enhanced
		$this->assertSame( [ 'form-row-wide' ], $out['billing']['billing_city']['class'] ); // preserved
		$this->assertSame( 70, $out['billing']['billing_city']['priority'] );               // preserved
		$this->assertSame( [ 'city' ], $out['billing']['billing_city']['validate'] );       // WC validate preserved (Codex #8)
	}

	public function test_inject_preserves_wc_custom_attributes(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$attrs = [ 'data-foo' => 'bar' ];
		$wc    = [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'custom_attributes' => $attrs ] ] ];
		$out   = $handler->inject( $wc );

		$this->assertSame( $attrs, $out['billing']['billing_city']['custom_attributes'] );
	}

	// -------------------------------------------------------------------------
	// Add new field in its own section
	// -------------------------------------------------------------------------

	public function test_inject_adds_new_field_in_its_section(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'carrier_pickup_point' )->set_type( 'hidden' )->set_section( 'order' )->to_array() ] );
		$out    = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );
		$this->assertSame( 'hidden', $out['order']['carrier_pickup_point']['type'] );
	}

	public function test_inject_creates_section_when_not_present(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )->to_array() ] );
		// pass an empty array — 'order' section doesn't exist yet
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [] );
		$this->assertArrayHasKey( 'order', $out );
		$this->assertArrayHasKey( 'carrier_pvz', $out['order'] );
	}

	// -------------------------------------------------------------------------
	// Fields in different sections go to their respective sections
	// -------------------------------------------------------------------------

	public function test_inject_routes_fields_to_their_own_sections(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_extra' )->set_type( 'text' )->set_section( 'billing' )->to_array(),
			Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )->to_array(),
		] );

		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'billing' => [], 'order' => [] ] );

		$this->assertArrayHasKey( 'billing_extra', $out['billing'] );
		$this->assertArrayHasKey( 'carrier_pvz', $out['order'] );
		$this->assertArrayNotHasKey( 'billing_extra', $out['order'] );
		$this->assertArrayNotHasKey( 'carrier_pvz', $out['billing'] );
	}

	// -------------------------------------------------------------------------
	// options-kind root field — source() is called to pre-fill options
	// -------------------------------------------------------------------------

	public function test_inject_prefills_options_root_from_source(): void {
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )->set_section( 'billing' )
				->set_source( static fn( $ctx ) => [ [ 'value' => '77', 'label' => 'Москва' ] ], 'options' )->to_array(),
		] );
		// subclass to stub the country (avoid WC() in unit tests)
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function current_country(): string { return 'RU'; }
		};
		$out = $handler->inject( [ 'billing' => [] ] );
		// A placeholder empty option (with a "choose…" label) is prepended so WC always
		// renders the <select> and never shows a blank first row.
		$this->assertSame( [ '' => 'Выберите…', '77' => 'Москва' ], $out['billing']['billing_state']['options'] );
	}

	public function test_inject_skips_options_for_dependent_field(): void {
		$called  = false;
		$src     = static function () use ( &$called ) { $called = true; return []; };
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )
				->set_source( $src, 'options' )->depends_on( 'billing_state' )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function current_country(): string { return 'RU'; }
		};
		$handler->inject( [ 'billing' => [] ] );
		$this->assertFalse( $called, 'Source must NOT be called for dependent (non-root) fields at inject time' );
	}

	public function test_inject_skips_options_for_suggest_kind(): void {
		$called = false;
		$src    = static function () use ( &$called ) { $called = true; return []; };
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )
				->set_source( $src, 'suggest' )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function current_country(): string { return 'RU'; }
		};
		$handler->inject( [ 'billing' => [] ] );
		$this->assertFalse( $called, 'Source must NOT be called for suggest-kind fields at inject time' );
	}

	// -------------------------------------------------------------------------
	// label / required are still injected
	// -------------------------------------------------------------------------

	public function test_inject_sets_label_and_required_for_new_field(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pvz' )->set_type( 'text' )->set_label( 'ПВЗ' )->set_required( true )->set_section( 'order' )->to_array(),
		] );
		$out    = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );

		$this->assertSame( 'ПВЗ', $out['order']['carrier_pvz']['label'] );
		$this->assertTrue( $out['order']['carrier_pvz']['required'] );
	}

	/**
	 * A condition-spec `required` (array) must NOT become WC's static `required => true`
	 * — otherwise WooCommerce's own validation blocks a blank conditional field regardless
	 * of the chosen method. Conditional requiredness is enforced by our validate()/store.
	 * (Codex review P1.)
	 */
	public function test_inject_emits_false_required_for_condition_spec_field(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )
				->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )
				->to_array(),
		] );
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );

		$this->assertFalse( $out['order']['carrier_pvz']['required'] );
	}

	/**
	 * Enhancing a native WC field with a descriptor that does NOT set `required` must
	 * NOT strip WC's own required flag (e.g. turning `billing_city` into a select must
	 * keep it required if WC required it). (Codex re-critic P1.)
	 */
	public function test_inject_preserves_wc_required_when_descriptor_does_not_set_it(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )->to_array(),
		] );
		$wc  = [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'required' => true ] ] ];
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( $wc );

		$this->assertSame( 'select', $out['billing']['billing_city']['type'] ); // enhanced
		$this->assertTrue( $out['billing']['billing_city']['required'] );        // WC required preserved
	}

	/**
	 * A `select` field with no computed options (a suggest field, or a takeover-true country
	 * with no regions) must still get a placeholder option, or WooCommerce's
	 * woocommerce_form_field() renders the select as an empty string and the field vanishes.
	 * (Caught by live browser e2e on the classic checkout.)
	 */
	public function test_inject_gives_empty_select_a_placeholder_option(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )
				->set_source( static fn() => [], 'suggest' )->depends_on( 'billing_state' )->to_array(),
		] );
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'billing' => [] ] );

		$this->assertSame( [ '' => 'Выберите…' ], $out['billing']['billing_city']['options'] );
	}

	/**
	 * A field that declares a takeover_condition is owned by the CLIENT and must NOT be
	 * enhanced server-side (the server renders a guessed country that may differ from what the
	 * customer sees). WooCommerce's native field is left untouched; the adapter converts it for
	 * the actual country on load + on country change. (Caught by live browser e2e.)
	 */
	public function test_inject_skips_field_when_takeover_condition_false(): void {
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )->set_section( 'billing' )
				->set_source( static fn() => [], 'options' )
				->set_takeover_condition( static fn( $c ) => 'RU' === ( $c['country'] ?? '' ) )->to_array(),
		] );
		// current_country() stubbed to a takeover-FALSE country.
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function current_country(): string { return 'US'; }
		};
		$wc  = [ 'billing' => [ 'billing_state' => [ 'type' => 'state', 'label' => 'State' ] ] ];
		$out = $handler->inject( $wc );

		// Untouched: still WC's native 'state' type, not our 'select'.
		$this->assertSame( 'state', $out['billing']['billing_state']['type'] );
	}
}
