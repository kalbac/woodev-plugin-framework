<?php
/**
 * Tests for Checkout_Handler::effective_fields() — the Rule 7b fan-out
 * (AGENT-RULES.md Rule 7b; issue #458).
 *
 * A field declared via Field::source_location() carries ONE section and ONE id.
 * effective_fields() — consumed by inject(), sanitize_posted_data(), validate(),
 * save(), handle_checkout_get_value() and guard_native_field_conflicts() — expands
 * it into the section(s) the FRAMEWORK, not the plugin, attaches it to, derived
 * from `woocommerce_ship_to_destination`:
 *   - `billing_only` ("force shipping to the customer billing address") -> `billing_*` alone
 *   - every other value -> BOTH `billing_*` and `shipping_*`
 *
 * A non-location field (source_kind `'options'`/`'suggest'`/`null`) is unaffected —
 * its declared section/id pass through unchanged. That is the control this file
 * exercises separately (see `test_non_location_field_keeps_its_declared_section`):
 * without it, a future "fan out everything" regression would still pass every other
 * test here.
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
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::effective_fields
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::location_target_sections
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::location_field_variants
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::strip_address_prefix
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::sanitize_posted_data
 */
class CheckoutHandlerEffectiveFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// inject() calls apply_filters — pass the second arg through unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Mirrors WooCommerce's own wc_ship_to_billing_address_only() (a single boolean
		// wc-order-functions.php helper, unavailable in this unit-test bootstrap) against the
		// RAW `woocommerce_ship_to_destination` option, so tests below can stub the option
		// value directly — the same three states AGENT-RULES.md Rule 7b's own table names.
		Functions\when( 'wc_ship_to_billing_address_only' )->alias(
			static fn() => 'billing_only' === get_option( 'woocommerce_ship_to_destination' )
		);
	}

	/**
	 * Every id `inject()` placed under any section, flattened to one list — the shape these
	 * tests assert on. Asserting the resulting ID SET (not an internal call to
	 * `wc_ship_to_billing_address_only()`, and not `effective_fields()` directly, which is
	 * `protected`) is what the coordinator's re-litigation asked for: it is the same evidence
	 * WooCommerce itself would act on.
	 *
	 * @param array<string, mixed> $out
	 * @return string[]
	 */
	private static function injected_ids( array $out ): array {
		$ids = [];
		foreach ( $out as $section_fields ) {
			$ids = array_merge( $ids, array_keys( (array) $section_fields ) );
		}
		sort( $ids );
		return $ids;
	}

	// -------------------------------------------------------------------------
	// Rule 7b branch 1: billing_only -> billing_* alone, shipping_* absent
	// -------------------------------------------------------------------------

	public function test_billing_only_attaches_to_billing_id_only(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'shipping_city' )->set_type( 'text' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame( [ 'billing_city' ], self::injected_ids( $out ) );
		$this->assertArrayNotHasKey( 'shipping', $out, 'billing_only must not create a shipping section entry at all.' );
	}

	// -------------------------------------------------------------------------
	// Rule 7b branch 2: every other value -> BOTH billing_* and shipping_*
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider ship_to_destination_both_columns_provider
	 */
	public function test_every_other_value_attaches_to_both_columns( string $ship_to_destination ): void {
		Functions\when( 'get_option' )->justReturn( $ship_to_destination );

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'shipping_city' )->set_type( 'text' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame( [ 'billing_city', 'shipping_city' ], self::injected_ids( $out ) );
		$this->assertArrayHasKey( 'billing_city', $out['billing'] );
		$this->assertArrayHasKey( 'shipping_city', $out['shipping'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function ship_to_destination_both_columns_provider(): array {
		return [
			// Default: "ship to a different address?" unchecked by default, but the checkbox
			// (and the shipping fieldset behind it) is still rendered.
			'shipping (WC default)'                        => [ 'shipping' ],
			// "Force shipping to billing" checked by DEFAULT but the checkbox — and the
			// shipping fieldset — are still on the page; only `billing_only` drops them.
			'billing (force-shipping-to-billing default)'  => [ 'billing' ],
		];
	}

	// -------------------------------------------------------------------------
	// The declared section is genuinely ignored (the half of Rule 7b re-litigated
	// twice) — a `source_location()` field explicitly declared 'shipping' still
	// moves to 'billing' under billing_only.
	// -------------------------------------------------------------------------

	public function test_declared_section_is_ignored_field_still_moves_to_billing_under_billing_only(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );

		$fields  = Checkout_Fields::from_array( [
			// The plugin explicitly asked for 'shipping' — Rule 7b says the framework, not the
			// plugin, owns the section for a source_location() field, so this must be overridden.
			Field::create( 'shipping_city' )->set_type( 'text' )->set_section( 'shipping' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertArrayHasKey( 'billing_city', $out['billing'] ?? [], "A field explicitly declared ->set_section('shipping') must still land on billing under billing_only." );
		$this->assertArrayNotHasKey( 'shipping', $out, "The plugin's ->set_section('shipping') call must not survive billing_only." );
	}

	// -------------------------------------------------------------------------
	// Control: a NON-location field keeps its declared section and is NOT fanned
	// out. Without this test, a future "fan out everything" regression would
	// still pass every test above.
	// -------------------------------------------------------------------------

	public function test_non_location_field_keeps_its_declared_section(): void {
		// Deliberately NOT stubbing get_option()/wc_ship_to_billing_address_only() beyond
		// setUp()'s alias — a non-location field must never reach that seam at all. If
		// effective_fields() ever started fanning every field out regardless of source_kind,
		// this field's real section here ('shipping', chosen specifically because it is NOT
		// the framework's billing_only fallback) would either duplicate into 'billing' too or
		// silently move, and the assertions below would catch either.
		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_extra' )->set_type( 'select' )->set_section( 'shipping' )
				->set_source( static fn() => [ [ 'value' => '1', 'label' => 'One' ] ], 'options' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame( [ 'billing_extra' ], self::injected_ids( $out ) );
		$this->assertArrayHasKey( 'billing_extra', $out['shipping'] );
		$this->assertArrayNotHasKey( 'billing', $out, 'A non-location field must never spawn a billing counterpart.' );
	}

	// -------------------------------------------------------------------------
	// A consuming path keyed off the fanned set: sanitize_posted_data() must read
	// $_POST under the id WooCommerce/the browser ACTUALLY used. Picked over
	// save() (which skips every billing_*/shipping_* id either way — see
	// Checkout_Handler::is_native_wc_field()) because getting this wrong loses
	// the customer's typed address outright: under billing_only the shipping
	// fieldset is not even rendered, so a handler still reading the plugin's raw
	// `shipping_city` declaration would read an always-absent POST key and
	// silently sanitize the customer's real, billing-column input to ''.
	// -------------------------------------------------------------------------

	public function test_sanitize_posted_data_reads_billing_id_under_billing_only(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		Functions\when( 'wc_clean' )->returnArg();

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'shipping_city' )->set_type( 'text' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$clean = $handler->sanitize_posted_data( [ 'billing_city' => 'Москва' ] );

		$this->assertSame(
			'Москва',
			$clean['billing_city'] ?? null,
			"The customer's typed value must be captured under the id WooCommerce actually posts under billing_only — losing it here loses their address."
		);
		$this->assertArrayNotHasKey( 'shipping_city', $clean );
	}

	// -------------------------------------------------------------------------
	// Round 4 (issue #458, AGENT-RULES.md Rule 7c): a directly-declared descriptor and a Rule 7b
	// fan-out variant can claim the SAME id, and on the rig fixture they DO -- the section 8 demo
	// declares `billing_state`/`billing_city` while the location fields fan out onto the same ids.
	//
	// Round 3 resolved that first-registration-wins and reported it with `_doing_it_wrong()`. Both
	// halves were wrong. The notice fired on a legitimate configuration the FRAMEWORK creates (the
	// plugin never asked for the second claim), and `_doing_it_wrong()` asserts the developer called
	// something incorrectly, which nobody did -- it failed five integration tests, in all three CI
	// matrix combinations, on `WP_UnitTestCase`'s caught-doing-it-wrong assertion.
	//
	// The rule now: a DIRECT declaration beats a fan-out variant whatever the order; same-kind
	// claims fall back to first-registration-wins; nothing is reported. Proved in BOTH orders,
	// because order-independence is the whole point of the change.
	// -------------------------------------------------------------------------

	public function test_direct_declaration_registered_first_wins_the_id_collision(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		Functions\expect( '_doing_it_wrong' )->never();

		$fields  = Checkout_Fields::from_array( [
			// Declared FIRST -- an explicit, non-location billing_city field.
			Field::create( 'billing_city' )->set_type( 'select' )->set_label( 'Explicit City' )->set_section( 'billing' )
				->set_source( static fn() => [], 'options' )->to_array(),
			// Declared SECOND -- a location-backed field whose Rule 7b fan-out also claims
			// billing_city (and, uncontested, shipping_city).
			Field::create( 'city' )->set_type( 'text' )->set_label( 'Location City' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame(
			'select',
			$out['billing']['billing_city']['type'] ?? null,
			'A direct declaration must win the id over a Rule 7b fan-out variant.'
		);
		$this->assertSame(
			'text',
			$out['shipping']['shipping_city']['type'] ?? null,
			'The non-colliding shipping_city fan-out variant is unaffected by the billing_city collision.'
		);
	}

	public function test_direct_declaration_registered_second_still_wins_the_id_collision(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		Functions\expect( '_doing_it_wrong' )->never();

		$fields  = Checkout_Fields::from_array( [
			// Declared FIRST -- the location-backed field, whose fan-out claims billing_city
			// (and shipping_city) before the explicit descriptor below ever runs.
			Field::create( 'city' )->set_type( 'text' )->set_label( 'Location City' )->source_location( 'settlement' )->to_array(),
			// Declared SECOND -- an explicit, non-location billing_city field.
			Field::create( 'billing_city' )->set_type( 'select' )->set_label( 'Explicit City' )->set_section( 'billing' )
				->set_source( static fn() => [], 'options' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame(
			'select',
			$out['billing']['billing_city']['type'] ?? null,
			'Precedence is by KIND, not by order: the direct declaration wins even when the fan-out variant was registered first.'
		);
		$this->assertSame( 'text', $out['shipping']['shipping_city']['type'] ?? null );
	}

	/**
	 * The losing claim must not move the field. `inject()` walks effective_fields() in order, so a
	 * direct declaration displacing an earlier fan-out variant has to inherit that slot -- otherwise
	 * the checkout field ORDER would depend on which descriptor won, i.e. the customer would see the
	 * columns reshuffle for a reason invisible to them.
	 */
	public function test_a_displaced_fanout_variant_leaves_its_position_to_the_winner(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		Functions\expect( '_doing_it_wrong' )->never();

		$fields  = Checkout_Fields::from_array( [
			// Fans out to billing_city + shipping_city -- billing_city takes the first slot.
			Field::create( 'city' )->set_type( 'text' )->set_label( 'Location City' )->source_location( 'settlement' )->to_array(),
			Field::create( 'billing_company' )->set_type( 'text' )->set_label( 'Company' )->set_section( 'billing' )->to_array(),
			// Displaces the fan-out variant above, and must take ITS slot, not the end.
			Field::create( 'billing_city' )->set_type( 'select' )->set_label( 'Explicit City' )->set_section( 'billing' )
				->set_source( static fn() => [], 'options' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame(
			[ 'billing_city', 'billing_company' ],
			array_keys( $out['billing'] ),
			'The winning direct declaration must inherit the displaced fan-out position.'
		);
	}

	/**
	 * Two fan-out variants can only collide when two source_location() fields strip to the SAME
	 * suffix -- Rule 7b makes that redundant by definition, so it resolves first-wins and stays
	 * silent like every other same-kind tie-break here.
	 */
	public function test_two_fanout_variants_resolve_first_registration_wins(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		Functions\expect( '_doing_it_wrong' )->never();

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->set_type( 'text' )->set_label( 'First Location City' )->source_location( 'settlement' )->to_array(),
			Field::create( 'shipping_city' )->set_type( 'text' )->set_label( 'Second Location City' )->source_location( 'settlement' )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );

		$out = $handler->inject( [] );

		$this->assertSame( 'First Location City', $out['billing']['billing_city']['label'] ?? null );
		$this->assertSame( 'First Location City', $out['shipping']['shipping_city']['label'] ?? null );
	}
}
