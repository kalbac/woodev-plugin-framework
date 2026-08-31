<?php
/**
 * Unit tests for Checkout_Field_Policy — the store-level singleton that turns the
 * «Поля» settings (Task 5's Checkout_Field_Settings) into real WooCommerce checkout
 * behaviour via two instruments (issue #362, design §4.3):
 *
 *  - `woocommerce_get_country_locale` (Instrument A) — reaches BOTH the classic and the
 *    block checkout.
 *  - `woocommerce_checkout_fields` (Instrument B) — classic checkout only, by
 *    construction.
 *
 * The two pure contribution methods ({@see Checkout_Field_Policy::locale_contribution()}
 * / {@see Checkout_Field_Policy::checkout_fields_contribution()}) are this file's main
 * subject — they touch no WordPress function at all. A second group exercises
 * {@see Checkout_Field_Policy::register()} and its two filter callbacks against a real
 * {@see Checkout_Field_Settings} handler.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Environment;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy
 */
final class CheckoutFieldPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Checkout_Field_Policy::reset_for_tests();
	}

	protected function tearDown(): void {
		Checkout_Field_Policy::reset_for_tests();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// locale_contribution() — Instrument A, pure
	// -------------------------------------------------------------------------

	public function test_locale_contribution_for_preset_only(): void {
		$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => true, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [] ], [ 'RU', 'KZ' ] );
		// The address block keeps WooCommerce's own 40-90 band (first_name 10 / last_name 20 /
		// company 30 sit below it, phone 100 / email 110 above), reordered to
		// Страна > Регион > Город > Адрес > Кв. > Индекс. Measured on the rig: the design's
		// original 10-60 collided with the name block and split the customer's name in half.
		$this->assertSame( 40, $out['RU']['country']['priority'] );
		$this->assertSame( 50, $out['RU']['state']['priority'] );
		$this->assertSame( 60, $out['RU']['city']['priority'] );
		$this->assertSame( 70, $out['RU']['address_1']['priority'] );
		$this->assertSame( 80, $out['RU']['address_2']['priority'] );
		$this->assertSame( 90, $out['RU']['postcode']['priority'] );
		$this->assertSame( 50, $out['KZ']['state']['priority'] );        // every shipping country (S5)
		$this->assertTrue( $out['RU']['city']['required'] );            // settlement invariant, always
		$this->assertArrayNotHasKey( 'hidden', $out['RU']['state'] );
	}

	public function test_locale_contribution_removes_region_and_postcode(): void {
		$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'remove', 'postcode_field' => 'remove' ], [], [ 'RU' ] );
		$this->assertTrue( $out['RU']['state']['hidden'] );
		$this->assertFalse( $out['RU']['state']['required'] );
		$this->assertTrue( $out['RU']['postcode']['hidden'] );
		$this->assertArrayNotHasKey( 'priority', $out['RU']['country'] );
		// existing locale keys survive
		$out2 = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [ 'postcode' => [ 'label' => 'Индекс' ] ] ], [ 'RU' ] );
		$this->assertSame( 'Индекс', $out2['RU']['postcode']['label'] );
	}

	/**
	 * S5: an unconfigured store (no shipping countries) gets no contribution at all —
	 * the locale array is returned unchanged, never a spurious empty-country entry.
	 */
	public function test_locale_contribution_is_a_no_op_when_no_shipping_countries(): void {
		$locale = [ 'RU' => [ 'postcode' => [ 'label' => 'Индекс' ] ] ];
		$out    = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => true, 'region_field' => 'remove', 'postcode_field' => 'remove' ], $locale, [] );

		$this->assertSame( $locale, $out );
	}

	// -------------------------------------------------------------------------
	// checkout_fields_contribution() — Instrument B, pure
	// -------------------------------------------------------------------------

	public function test_checkout_fields_late_unset_removes_from_both_sections(): void {
		$fields = [ 'billing' => [ 'billing_state' => [], 'billing_postcode' => [], 'billing_city' => [ 'required' => true ] ], 'shipping' => [ 'shipping_state' => [], 'shipping_postcode' => [], 'shipping_city' => [ 'required' => true ] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'remove', 'postcode_field' => 'show' ], $fields, false );
		$this->assertArrayNotHasKey( 'billing_state', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_state', $out['shipping'] );
		$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * A `postcode_field=remove` setting must ALSO unset the postcode field — not only
	 * the region test above — proving both settings are wired independently, not just
	 * one accidentally covering the other.
	 */
	public function test_checkout_fields_removes_postcode_independently_of_region(): void {
		$fields = [ 'billing' => [ 'billing_state' => [], 'billing_postcode' => [] ], 'shipping' => [ 'shipping_state' => [], 'shipping_postcode' => [] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'show', 'postcode_field' => 'remove' ], $fields, false );

		$this->assertArrayHasKey( 'billing_state', $out['billing'] );
		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * A `hide_for_pickup` postcode value is a JS-driven, classic-only VALUE (T2) — this
	 * PHP instrument must never unset for anything other than the literal `'remove'`
	 * string, regardless of whether a pickup method is chosen.
	 */
	public function test_checkout_fields_does_not_touch_hide_for_pickup(): void {
		$fields = [ 'billing' => [ 'billing_postcode' => [] ], 'shipping' => [ 'shipping_postcode' => [] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'show', 'postcode_field' => 'hide_for_pickup' ], $fields, true );

		$this->assertArrayHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
	}

	// -------------------------------------------------------------------------
	// checkout_fields_contribution() — required-relaxation for hide_for_pickup
	// (issue #362 pickup-required-relaxation fix; gotcha
	// js-hidden-checkout-field-is-still-required-server-side)
	// -------------------------------------------------------------------------

	/**
	 * The core fix, measured on the rig (gotcha
	 * js-hidden-checkout-field-is-still-required-server-side): `address_field` and
	 * `postcode_field` both set to `hide_for_pickup`, a pickup method chosen — every
	 * one of the four fields the browser hid must come back `required === false` in
	 * BOTH sections, and must still be PRESENT (never unset — the value must still be
	 * able to post so `pickup-mount.js`'s address-replacement can fill it).
	 */
	public function test_checkout_fields_relaxes_required_for_hide_for_pickup_when_pickup_is_chosen(): void {
		$fields = [
			'billing'  => [ 'billing_address_1' => [ 'required' => true ], 'billing_postcode' => [ 'required' => true ] ],
			'shipping' => [ 'shipping_address_1' => [ 'required' => true ], 'shipping_postcode' => [ 'required' => true ] ],
		];
		$out    = Checkout_Field_Policy::checkout_fields_contribution(
			[ 'address_field' => 'hide_for_pickup', 'postcode_field' => 'hide_for_pickup' ],
			$fields,
			true
		);

		$this->assertFalse( $out['billing']['billing_address_1']['required'] );
		$this->assertFalse( $out['shipping']['shipping_address_1']['required'] );
		$this->assertFalse( $out['billing']['billing_postcode']['required'] );
		$this->assertFalse( $out['shipping']['shipping_postcode']['required'] );
		$this->assertArrayHasKey( 'billing_address_1', $out['billing'] );
		$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * Control run: WITHOUT this test, a green result above proves nothing — it would
	 * pass even if `required` were relaxed unconditionally. `hide_for_pickup` set, but
	 * no pickup method chosen (e.g. a courier method), must leave `required` exactly
	 * as the caller passed it in.
	 */
	public function test_checkout_fields_leaves_required_alone_when_no_pickup_is_chosen(): void {
		$fields = [
			'billing'  => [ 'billing_address_1' => [ 'required' => true ], 'billing_postcode' => [ 'required' => true ] ],
			'shipping' => [ 'shipping_address_1' => [ 'required' => true ], 'shipping_postcode' => [ 'required' => true ] ],
		];
		$out    = Checkout_Field_Policy::checkout_fields_contribution(
			[ 'address_field' => 'hide_for_pickup', 'postcode_field' => 'hide_for_pickup' ],
			$fields,
			false
		);

		$this->assertTrue( $out['billing']['billing_address_1']['required'] );
		$this->assertTrue( $out['shipping']['shipping_address_1']['required'] );
		$this->assertTrue( $out['billing']['billing_postcode']['required'] );
		$this->assertTrue( $out['shipping']['shipping_postcode']['required'] );
	}

	/**
	 * A pickup method chosen is a NECESSARY but not SUFFICIENT condition: when the
	 * merchant's policy is plain `show` (the default), a pickup choice must not relax
	 * anything at all.
	 */
	public function test_checkout_fields_leaves_required_alone_when_policy_is_show_even_if_pickup_chosen(): void {
		$fields = [
			'billing'  => [ 'billing_address_1' => [ 'required' => true ], 'billing_postcode' => [ 'required' => true ] ],
			'shipping' => [ 'shipping_address_1' => [ 'required' => true ], 'shipping_postcode' => [ 'required' => true ] ],
		];
		$out    = Checkout_Field_Policy::checkout_fields_contribution(
			[ 'address_field' => 'show', 'postcode_field' => 'show' ],
			$fields,
			true
		);

		$this->assertTrue( $out['billing']['billing_address_1']['required'] );
		$this->assertTrue( $out['shipping']['shipping_postcode']['required'] );
	}

	/**
	 * `postcode_field=remove` wins over the pickup relaxation: the field is UNSET (T1),
	 * not merely relaxed, and the relaxation branch must not fatal trying to write
	 * `['required']` onto a field that is no longer there.
	 */
	public function test_checkout_fields_postcode_remove_is_unset_not_relaxed_when_pickup_chosen(): void {
		$fields = [
			'billing'  => [ 'billing_postcode' => [ 'required' => true ] ],
			'shipping' => [ 'shipping_postcode' => [ 'required' => true ] ],
		];
		$out    = Checkout_Field_Policy::checkout_fields_contribution(
			[ 'postcode_field' => 'remove' ],
			$fields,
			true
		);

		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * A section missing entirely from `$fields`, and a field missing from a section
	 * that IS present, must not raise a notice or fatal — both are ordinary shapes a
	 * third-party field manager (or an already-removed field) can produce.
	 */
	public function test_checkout_fields_relaxation_tolerates_missing_section_and_missing_field(): void {
		$fields = [ 'billing' => [ 'billing_address_1' => [ 'required' => true ] ] ]; // no 'shipping' key; no 'billing_postcode' key.
		$out    = Checkout_Field_Policy::checkout_fields_contribution(
			[ 'address_field' => 'hide_for_pickup', 'postcode_field' => 'hide_for_pickup' ],
			$fields,
			true
		);

		$this->assertFalse( $out['billing']['billing_address_1']['required'] );
		$this->assertArrayNotHasKey( 'shipping', $out );
		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
	}

	// -------------------------------------------------------------------------
	// any_pickup_method_chosen() — pure, mirrors Checkout_Handler::chosen_method_matches()
	// -------------------------------------------------------------------------

	public function test_any_pickup_method_chosen_matches_a_bare_method_id(): void {
		$this->assertTrue( Checkout_Field_Policy::any_pickup_method_chosen( [ 'pickup' ], [ 'pickup' ] ) );
	}

	/**
	 * WooCommerce posts `method_id:instance_id` whenever the zone has an instance id —
	 * the usual case. Must match the same way {@see Checkout_Handler::chosen_method_matches()}
	 * already does for pickup-slot requiredness.
	 */
	public function test_any_pickup_method_chosen_matches_method_id_with_instance_suffix(): void {
		$this->assertTrue( Checkout_Field_Policy::any_pickup_method_chosen( [ 'pickup:5' ], [ 'pickup' ] ) );
	}

	public function test_any_pickup_method_chosen_is_false_for_an_unrelated_method(): void {
		$this->assertFalse( Checkout_Field_Policy::any_pickup_method_chosen( [ 'flat_rate:3' ], [ 'pickup' ] ) );
	}

	/**
	 * `chosen_shipping_methods` carries one entry per shipping PACKAGE. The rule is
	 * "ANY package is pickup" — a split shipment with one courier package and one
	 * pickup package must still relax the fields: relaxing can never block an order
	 * the browser already allowed, only being stricter than the UI can.
	 */
	public function test_any_pickup_method_chosen_is_true_when_any_package_is_pickup(): void {
		$this->assertTrue(
			Checkout_Field_Policy::any_pickup_method_chosen( [ 'flat_rate:3', 'pickup:9' ], [ 'pickup' ] )
		);
	}

	public function test_any_pickup_method_chosen_is_false_when_no_pickup_ids_are_configured(): void {
		$this->assertFalse( Checkout_Field_Policy::any_pickup_method_chosen( [ 'pickup:5' ], [] ) );
	}

	public function test_any_pickup_method_chosen_is_false_for_an_empty_chosen_list(): void {
		$this->assertFalse( Checkout_Field_Policy::any_pickup_method_chosen( [], [ 'pickup' ] ) );
	}

	// -------------------------------------------------------------------------
	// merge_chosen_shipping_methods() — pure; POST must win over the session
	// (Codex P0 follow-up, issue #362): our late `woocommerce_checkout_fields` filter
	// fires from WC_Checkout::get_posted_data() — BEFORE WC_Checkout::update_session()
	// writes this submit's posted `shipping_method[]` into the session — so the session
	// alone can still be one submit stale. See merge_chosen_shipping_methods()'s own
	// docblock for the verified WC_Checkout line numbers.
	// -------------------------------------------------------------------------

	/**
	 * The direction that reintroduces the ORIGINAL bug if missed: the session still
	 * names a courier (e.g. from a previous `update_order_review` AJAX call), but THIS
	 * submit posts a pickup method. The posted value must win, or the customer is
	 * rejected on invisible fields again.
	 */
	public function test_merge_chosen_shipping_methods_posted_pickup_overrides_session_courier(): void {
		$out = Checkout_Field_Policy::merge_chosen_shipping_methods( [ 'flat_rate:3' ], [ 'pickup:9' ] );

		$this->assertSame( [ 'pickup:9' ], $out );
	}

	/**
	 * The worse, silent-acceptance direction: the session names a pickup method, but
	 * THIS submit posts a courier — a real order with genuinely empty, VISIBLE address
	 * fields must NOT be waved through because the session was stale in our favour.
	 */
	public function test_merge_chosen_shipping_methods_posted_courier_overrides_session_pickup(): void {
		$out = Checkout_Field_Policy::merge_chosen_shipping_methods( [ 'pickup:9' ], [ 'flat_rate:3' ] );

		$this->assertSame( [ 'flat_rate:3' ], $out );
	}

	/**
	 * A plain page render (no checkout submit at all) has no `shipping_method` POST
	 * key — WC_Checkout::get_posted_data() itself defaults it to `''` in that case
	 * (not an array). The session alone must still decide.
	 */
	public function test_merge_chosen_shipping_methods_no_post_leaves_session_untouched(): void {
		$this->assertSame( [ 'pickup:9' ], Checkout_Field_Policy::merge_chosen_shipping_methods( [ 'pickup:9' ], '' ) );
	}

	/**
	 * A posted `shipping_method` that is not an array (WC_Checkout::get_posted_data()'s
	 * own `''` default, or a malformed submit) must be ignored wholesale, not partially
	 * applied or fataled on.
	 */
	public function test_merge_chosen_shipping_methods_non_array_post_is_ignored(): void {
		$this->assertSame( [ 'flat_rate:3' ], Checkout_Field_Policy::merge_chosen_shipping_methods( [ 'flat_rate:3' ], 'not-an-array' ) );
	}

	/**
	 * WC_Checkout::update_session() itself only merges STRING values
	 * (`if ( ! is_string( $value ) ) { continue; }`) — replicated verbatim. A malformed
	 * non-string entry at one index must not clobber that index's session value, and
	 * must not fatal.
	 */
	public function test_merge_chosen_shipping_methods_skips_a_non_string_entry(): void {
		$out = Checkout_Field_Policy::merge_chosen_shipping_methods( [ 'flat_rate:3' ], [ [ 'not', 'a', 'string' ] ] );

		$this->assertSame( [ 'flat_rate:3' ], $out );
	}

	/**
	 * `chosen_shipping_methods` is keyed PER PACKAGE INDEX (multi-package carts). The
	 * merge must override only the index actually present in the POST, leaving every
	 * other package's session value alone — exactly WC_Checkout::update_session()'s own
	 * per-index `foreach`.
	 */
	public function test_merge_chosen_shipping_methods_overrides_only_the_posted_index(): void {
		$out = Checkout_Field_Policy::merge_chosen_shipping_methods(
			[ 'flat_rate:1', 'flat_rate:2' ],
			[ 1 => 'pickup:9' ]
		);

		$this->assertSame( [ 'flat_rate:1', 'pickup:9' ], $out );
	}

	// -------------------------------------------------------------------------
	// restore_invariants() — settlement-field invariant restoration (S8), instance
	// -------------------------------------------------------------------------

	public function test_settlement_removed_by_a_third_party_is_restored_and_recorded(): void {
		$fields = [ 'billing' => [ 'billing_address_1' => [] ], 'shipping' => [ 'shipping_address_1' => [] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'label' => 'Город', 'required' => true, 'class' => [ 'form-row-wide' ], 'priority' => 70 ] ] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
		$this->assertSame(
			[ [ 'field' => 'city', 'what' => 'restored' ], [ 'field' => 'city', 'what' => 'restored' ] ],
			array_map( static fn( $o ) => [ 'field' => $o['field'], 'what' => $o['what'] ], $policy->get_overrides() )
		);
	}

	/**
	 * The invariant is «the settlement field EXISTS and is REQUIRED» — both halves, always.
	 * `WC_Countries::get_default_address_fields()` is itself filterable
	 * (`woocommerce_default_address_fields`), which is exactly where a third-party field
	 * manager would relax `city`. Re-inserting that template verbatim would then restore an
	 * OPTIONAL settlement field while reporting success — the invariant silently half-kept.
	 */
	public function test_a_restored_settlement_field_is_required_even_when_the_template_is_not(): void {
		$fields = [ 'billing' => [ 'billing_address_1' => [] ], 'shipping' => [ 'shipping_address_1' => [] ] ];
		$policy = new Checkout_Field_Policy();

		$out = $policy->restore_invariants( $fields, [ 'city' => [ 'label' => 'Город', 'required' => false, 'priority' => 70 ] ] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
		// Everything else in the template survives — only the invariant is asserted over it.
		$this->assertSame( 'Город', $out['billing']['billing_city']['label'] );
		$this->assertSame( 70, $out['billing']['billing_city']['priority'] );
	}

	/**
	 * Degenerate case: no WooCommerce runtime, so the template is empty. The field must still
	 * come back required rather than as a bare `[]` that renders as an optional text input.
	 */
	public function test_a_restored_settlement_field_is_required_when_no_template_exists(): void {
		$fields = [ 'billing' => [], 'shipping' => [] ];
		$policy = new Checkout_Field_Policy();

		$out = $policy->restore_invariants( $fields, [] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
	}

	public function test_settlement_made_optional_is_made_required_again(): void {
		$fields = [ 'billing' => [ 'billing_city' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => false ] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );

		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
		$this->assertSame( 'required', $policy->get_overrides()[0]['what'] );
	}

	public function test_other_fields_are_left_to_the_field_manager(): void {
		$fields = [ 'billing' => [ 'billing_city' => [ 'required' => true ], 'billing_phone' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => true ] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );

		$this->assertFalse( $out['billing']['billing_phone']['required'] );
		$this->assertSame( [], $policy->get_overrides() );
	}

	/**
	 * A second call describes only the outcome of THAT pass — overrides never
	 * accumulate across repeat filter applications within the same request.
	 */
	public function test_restore_invariants_resets_overrides_on_each_call(): void {
		$policy = new Checkout_Field_Policy();

		$policy->restore_invariants( [ 'billing' => [], 'shipping' => [] ], [ 'city' => [ 'required' => true ] ] );
		$this->assertNotSame( [], $policy->get_overrides() );

		$policy->restore_invariants(
			[ 'billing' => [ 'billing_city' => [ 'required' => true ] ], 'shipping' => [ 'shipping_city' => [ 'required' => true ] ] ],
			[ 'city' => [ 'required' => true ] ]
		);
		$this->assertSame( [], $policy->get_overrides(), 'a second call must not accumulate overrides from the first' );
	}

	// -------------------------------------------------------------------------
	// register() / filter callbacks — real Checkout_Field_Settings
	// -------------------------------------------------------------------------

	private function settings_handler( array $stored = [] ): Checkout_Field_Settings {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( $stored ) {
				foreach ( $stored as $id => $value ) {
					if ( false !== strpos( (string) $name, $id ) ) {
						return $value;
					}
				}

				return null;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		return new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
	}

	public function test_register_hooks_both_filters_at_the_late_priority(): void {
		$calls = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$calls ) {
				$calls[] = [ $hook, $priority ];

				return true;
			}
		);

		Checkout_Field_Policy::instance()->register( $this->settings_handler() );

		$this->assertSame(
			[
				[ 'woocommerce_get_country_locale', Checkout_Field_Policy::LATE ],
				[ 'woocommerce_checkout_fields', Checkout_Field_Policy::LATE ],
			],
			$calls
		);
	}

	public function test_register_is_idempotent_across_repeat_calls(): void {
		$call_count = 0;
		Functions\when( 'add_filter' )->alias(
			static function () use ( &$call_count ) {
				++$call_count;

				return true;
			}
		);

		$policy   = Checkout_Field_Policy::instance();
		$settings = $this->settings_handler();

		$policy->register( $settings );
		$policy->register( $settings );
		$policy->register( $settings );

		$this->assertSame( 2, $call_count, 'exactly two filters must be added, regardless of how many times register() is called' );
	}

	/**
	 * Isolated in its own process — same idiom, and for the same reason, as
	 * `CheckoutConfigTest::test_wc_states_degrades_a_false_get_states_result_to_states_present_false()`:
	 * Brain Monkey/Patchwork instruments a mocked `WC()` for the REST OF THE PHP
	 * PROCESS once any test stubs it, which would otherwise silently poison every
	 * other test in the suite relying on `function_exists( 'WC' ) === false`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_country_locale_reads_effective_settings_and_shipping_countries(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'WC' )->justReturn(
			(object) [
				'countries' => new class() {
					public function get_shipping_countries(): array {
						return [ 'RU' => 'Россия', 'KZ' => 'Казахстан' ];
					}
				},
			]
		);

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler( [ 'region_field' => 'remove' ] ) );

		$out = $policy->filter_country_locale( [] );

		$this->assertTrue( $out['RU']['state']['hidden'] );
		$this->assertTrue( $out['KZ']['state']['hidden'] );
	}

	public function test_filter_checkout_fields_unsets_the_removed_field(): void {
		Functions\when( 'add_filter' )->justReturn( true );

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler( [ 'postcode_field' => 'remove' ] ) );

		$out = $policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_postcode' => [] ],
				'shipping' => [ 'shipping_postcode' => [] ],
			]
		);

		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_postcode', $out['shipping'] );
	}

	// -------------------------------------------------------------------------
	// filter_checkout_fields() end-to-end — restoration is persisted (S8)
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_checkout_fields_restores_and_persists_the_override_report(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'WC' )->justReturn(
			(object) [
				'countries' => new class() {
					public function get_default_address_fields(): array {
						return [ 'city' => [ 'label' => 'Город', 'required' => true, 'priority' => 70 ] ];
					}
				},
			]
		);

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler() );

		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$written ) {
				$written = [ $name, $value ];

				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		$out = $policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_address_1' => [] ],
				'shipping' => [ 'shipping_address_1' => [] ],
			]
		);

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertSame( Checkout_Field_Policy::OPTION_LAST_OVERRIDES, $written[0] );
		$this->assertNotEmpty( $written[1] );
	}

	/**
	 * The control case (design S8): once the offending plugin stops interfering —
	 * the very next observation finds nothing to restore — the stale report must be
	 * cleared, not left behind accusing a plugin that is no longer active.
	 */
	public function test_filter_checkout_fields_clears_a_stale_report_once_nothing_is_overridden(): void {
		Functions\when( 'add_filter' )->justReturn( true );

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler() );

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return false !== strpos( (string) $name, Checkout_Field_Policy::OPTION_LAST_OVERRIDES )
					? [ [ 'field' => 'city', 'section' => 'billing', 'what' => 'restored' ] ]
					: $default;
			}
		);

		$deleted = false;
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted = ( Checkout_Field_Policy::OPTION_LAST_OVERRIDES === $name );

				return true;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		$policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_city' => [ 'required' => true ] ],
				'shipping' => [ 'shipping_city' => [ 'required' => true ] ],
			]
		);

		$this->assertTrue( $deleted, 'once nothing needs restoring, the stale report must be cleared' );
	}
}
