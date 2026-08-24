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

	// -------------------------------------------------------------------------
	// WC-array `label` is the visual `label` verbatim, never `error_label`
	// (#316 review finding 1): woocommerce_form_field() renders a <label> for
	// ANY non-empty `label`, even on a `hidden` field — it only skips the
	// `for` attribute — so handing WC our `error_label` would render a stray,
	// orphaned caption for a field whose real control lives elsewhere (e.g. a
	// "Choose pickup point" button).
	// -------------------------------------------------------------------------

	public function test_inject_wc_label_never_falls_back_to_error_label(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->set_type( 'hidden' )->set_error_label( 'Пункт выдачи' )->set_section( 'order' )->to_array(),
		] );
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );

		$this->assertSame( '', $out['order']['carrier_pickup_point']['label'] );
	}

	public function test_inject_wc_label_uses_the_explicit_label_verbatim(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pvz' )->set_type( 'text' )->set_label( 'ПВЗ' )->set_error_label( 'Пункт выдачи' )->set_section( 'order' )->to_array(),
		] );
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );

		$this->assertSame( 'ПВЗ', $out['order']['carrier_pvz']['label'], 'error_label must never influence the WC-facing label when a visual label is set.' );
	}

	public function test_inject_wc_label_stays_empty_when_neither_label_nor_error_label_set(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )->to_array(),
		] );
		$out = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );

		$this->assertSame( '', $out['order']['carrier_pvz']['label'] );
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

	/**
	 * A takeover STATE field's regions are injected as WooCommerce NATIVE states (via the
	 * woocommerce_states filter) for every takeover-true country, so WC renders the select and
	 * persists the value natively (surviving update_checkout). Non-takeover countries keep their
	 * existing states. (The robust replacement for the fragile client-side state conversion.)
	 */
	public function test_inject_states_adds_regions_for_takeover_countries(): void {
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )
				->set_source( static fn( $c ) => 'RU' === ( $c['country'] ?? '' ) ? [ [ 'value' => '77', 'label' => 'Москва' ] ] : [], 'options' )
				->set_takeover_condition( static fn( $c ) => in_array( $c['country'] ?? '', [ 'RU', 'BY' ], true ) )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function wc_country_codes(): array {
				return [ 'RU', 'BY', 'US' ];
			}
		};

		$states = $handler->inject_states( [ 'US' => [ 'CA' => 'California' ] ] );

		$this->assertSame( [ '77' => 'Москва' ], $states['RU'] );          // regions injected
		$this->assertSame( [ 'CA' => 'California' ], $states['US'] );        // non-takeover preserved
		$this->assertArrayNotHasKey( 'BY', $states );                       // takeover-true but source empty → no entry
	}

	/**
	 * A takeover country whose source yields NOTHING (unserved country, or a transient carrier
	 * API failure) must keep WooCommerce's own states rather than being overwritten with an
	 * empty set — an empty array tells WooCommerce the country has no states at all and it
	 * HIDES the region field, which is a worse checkout than falling back to WC's list.
	 */
	public function test_inject_states_empty_source_preserves_existing_wc_states(): void {
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )
				->set_source( static fn() => [], 'options' )
				->set_takeover_condition( static fn() => true )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function wc_country_codes(): array {
				return [ 'RU' ];
			}
		};

		$states = $handler->inject_states( [ 'RU' => [ 'MOW' => 'Москва' ] ] );

		$this->assertSame( [ 'MOW' => 'Москва' ], $states['RU'] );
	}

	/**
	 * `woocommerce_states` is keyed by COUNTRY, not by field, so a country can only carry one
	 * region set. When two state descriptors disagree for the same country, the conflict must
	 * be reported loudly and the first registration kept — not silently overwritten by
	 * whichever descriptor happened to be iterated last.
	 */
	public function test_inject_states_conflicting_descriptors_warn_and_keep_first(): void {
		Functions\expect( '_doing_it_wrong' )->atLeast()->once();

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )
				->set_source( static fn() => [ [ 'value' => '77', 'label' => 'Москва' ] ], 'options' )
				->set_takeover_condition( static fn() => true )->to_array(),
			Field::create( 'shipping_state' )->set_type( 'select' )
				->set_source( static fn() => [ [ 'value' => '78', 'label' => 'Санкт-Петербург' ] ], 'options' )
				->set_takeover_condition( static fn() => true )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function wc_country_codes(): array {
				return [ 'RU' ];
			}
		};

		$this->assertSame( [ '77' => 'Москва' ], $handler->inject_states( [] )['RU'] );
	}

	/**
	 * Two state descriptors that agree (the normal billing/shipping pair sharing one source)
	 * are NOT a conflict and must stay silent.
	 */
	public function test_inject_states_agreeing_descriptors_do_not_warn(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$source  = static fn() => [ [ 'value' => '77', 'label' => 'Москва' ] ];
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )
				->set_source( $source, 'options' )->set_takeover_condition( static fn() => true )->to_array(),
			Field::create( 'shipping_state' )->set_type( 'select' )
				->set_source( $source, 'options' )->set_takeover_condition( static fn() => true )->to_array(),
		] );
		$handler = new class( $fields, 'carrier' ) extends Checkout_Handler {
			protected function wc_country_codes(): array {
				return [ 'RU' ];
			}
		};

		$this->assertSame( [ '77' => 'Москва' ], $handler->inject_states( [] )['RU'] );
	}
	// -------------------------------------------------------------------------
	// `data-input-classes` survives the type override (issue #469)
	// -------------------------------------------------------------------------

	/**
	 * WooCommerce prints `data-input-classes` only from the `state` branch of
	 * `woocommerce_form_field()`. Overriding `type` moves the field out of that branch, the
	 * attribute disappears, and `country-select.js` reads `undefined` off the statebox and
	 * stamps it as a literal CSS class. Re-declare it through `custom_attributes`, which every
	 * render branch interpolates.
	 */
	public function test_inject_redeclares_data_input_classes_for_a_wc_state_field(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_state' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$wc  = [ 'billing' => [ 'billing_state' => [ 'type' => 'state', 'input_class' => [ 'foo', 'bar' ] ] ] ];
		$out = $handler->inject( $wc );

		$this->assertSame( 'select', $out['billing']['billing_state']['type'] );
		$this->assertSame( 'foo bar', $out['billing']['billing_state']['custom_attributes']['data-input-classes'] );
	}

	/**
	 * An empty class list is declared as a single SPACE, never as an empty string —
	 * `woocommerce_form_field()` runs `array_filter( custom_attributes, 'strlen' )`
	 * (`wc-template-functions.php:3367`, WooCommerce 11.0.1) and drops an empty-string
	 * attribute outright, so declaring `''` here would render nothing and leave the defect
	 * untouched. A space is equivalent for a class-list attribute: measured on the rig,
	 * `data-input-classes=" "` made WooCommerce's own rebuild produce `class="state_select"`,
	 * byte-identical to the `""` control. This is the case a stock install always hits, since
	 * WooCommerce core sets no `input_class` on any address field.
	 */
	public function test_inject_declares_data_input_classes_as_a_space_when_wc_has_no_input_class(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'shipping_state' )->set_type( 'select' )->set_section( 'shipping' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [ 'shipping' => [ 'shipping_state' => [ 'type' => 'state' ] ] ] );

		$attribute = $out['shipping']['shipping_state']['custom_attributes']['data-input-classes'];

		$this->assertSame( ' ', $attribute );
		$this->assertNotSame( 0, strlen( $attribute ), 'an empty string would be stripped by woocommerce_form_field()' );
	}

	/**
	 * The re-declaration overlays ONE key; WooCommerce's own custom attributes on the same
	 * field must survive it.
	 */
	public function test_inject_data_input_classes_keeps_wc_own_custom_attributes(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_state' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$wc = [
			'billing' => [
				'billing_state' => [
					'type'              => 'state',
					'input_class'       => [ 'foo' ],
					'custom_attributes' => [ 'autocomplete' => 'address-level1' ],
				],
			],
		];

		$attributes = $handler->inject( $wc )['billing']['billing_state']['custom_attributes'];

		$this->assertSame( 'address-level1', $attributes['autocomplete'] );
		$this->assertSame( 'foo', $attributes['data-input-classes'] );
	}

	/**
	 * A field WooCommerce does NOT render through its `state` branch never carried the attribute
	 * in the first place — adding it there would be noise, not a fix.
	 */
	public function test_inject_does_not_add_data_input_classes_to_a_non_state_field(): void {
		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'input_class' => [ 'foo' ] ] ] ] );

		$this->assertArrayNotHasKey( 'data-input-classes', $out['billing']['billing_city']['custom_attributes'] ?? [] );
	}
}
