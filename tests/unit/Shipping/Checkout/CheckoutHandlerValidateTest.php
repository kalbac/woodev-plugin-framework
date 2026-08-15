<?php
/**
 * Tests for Checkout_Handler::validate() — conditional-required (A2) and
 * Checkout_Handler::save() / is_native_wc_field() — native-WC-field skip (Codex HIGH #3).
 *
 * Covers Task 7 of the checkout-field-layer plan (2026-07-06):
 *   - conditional required blocks checkout when condition is met
 *   - conditional required passes when condition is not met
 *   - plain-bool required still works (regression guard)
 *   - is_native_wc_field() identifies billing_* / shipping_* prefixes
 *   - save() skips persistence for native WC fields (via persist_field seam)
 *   - save() persists genuinely new fields (via persist_field seam)
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/presets/class-pickup-field.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::validate
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::save
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::is_native_wc_field
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::set_requires_pickup_methods
 */
class CheckoutHandlerValidateTest extends TestCase {

	// -----------------------------------------------------------------------
	// Part A — conditional-required in validate()
	// -----------------------------------------------------------------------

	/**
	 * A condition-spec required field is required when the condition is met
	 * (chosen method matches): should add an error and return false.
	 */
	public function test_conditional_required_blocks_when_pickup_method_chosen(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] );
		$this->assertFalse( $ok );
	}

	/**
	 * A condition-spec required field is NOT required when the condition is not
	 * met (different method): blank value should pass without an error.
	 */
	public function test_conditional_required_passes_when_other_method(): void {
		Functions\expect( 'wc_add_notice' )->never();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );
		$this->assertTrue( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'flat_rate' ] ) );
	}

	/**
	 * Plain-bool required = true still blocks an empty value (regression guard).
	 */
	public function test_plain_bool_required_blocks_blank(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'myfield' )->set_required( true )->to_array(),
		] );
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'myfield' => '' ], [] );
		$this->assertFalse( $ok );
	}

	/**
	 * Plain-bool required = false passes a blank value (regression guard).
	 */
	public function test_plain_bool_required_false_passes_blank(): void {
		Functions\expect( 'wc_add_notice' )->never();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'myfield' )->set_required( false )->to_array(),
		] );
		$this->assertTrue( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'myfield' => '' ], [] ) );
	}

	/**
	 * validate() default second-param = [] does not fatal on a plain-bool required field.
	 */
	public function test_validate_no_state_default(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'f' )->set_required( true )->to_array(),
		] );
		// call with only one argument — second param defaults to []
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'f' => '' ] );
		$this->assertFalse( $ok );
	}

	// -----------------------------------------------------------------------
	// Part B — is_native_wc_field() unit tests
	// -----------------------------------------------------------------------

	/**
	 * billing_* IDs are recognised as native WC fields.
	 *
	 * Uses a transparent subclass to expose the protected method without reflection.
	 */
	public function test_is_native_wc_field_billing_prefix(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertTrue( $handler->probe( 'billing_city' ) );
		$this->assertTrue( $handler->probe( 'billing_country' ) );
		$this->assertTrue( $handler->probe( 'billing_' ) );
	}

	/**
	 * shipping_* IDs are recognised as native WC fields.
	 */
	public function test_is_native_wc_field_shipping_prefix(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertTrue( $handler->probe( 'shipping_address_1' ) );
		$this->assertTrue( $handler->probe( 'shipping_' ) );
	}

	/**
	 * Plugin-defined IDs are NOT native WC fields.
	 */
	public function test_is_native_wc_field_returns_false_for_custom_ids(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertFalse( $handler->probe( 'carrier_pickup_point' ) );
		$this->assertFalse( $handler->probe( 'pvz' ) );
		$this->assertFalse( $handler->probe( '' ) );
	}

	// -----------------------------------------------------------------------
	// Part B — save() skip / persist via spy subclass
	// -----------------------------------------------------------------------

	/**
	 * save() must NOT call persist_field() for a billing_* field.
	 *
	 * Uses a spy subclass that overrides persist_field() to record calls so
	 * the test is independent of the Woodev_Order_Compatibility static method.
	 */
	public function test_save_skips_native_wc_fields(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->to_array(),
		] );

		$spy      = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 123, [ 'billing_city' => 'Москва' ] );

		$this->assertSame( [], $spy->persisted, 'billing_city must NOT be persisted' );
	}

	/**
	 * save() MUST call persist_field() for a plugin-defined (non-native) field.
	 */
	public function test_save_persists_new_field(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->to_array(),
		] );

		// do_action is called inside save() — stub it.
		Functions\when( 'do_action' )->justReturn();

		$spy = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 123, [ 'carrier_pickup_point' => 'PVZ-001' ] );

		$this->assertSame(
			[ [ 'order' => 123, 'id' => 'carrier_pickup_point', 'value' => 'PVZ-001' ] ],
			$spy->persisted,
			'carrier_pickup_point must be persisted once'
		);
	}

	/**
	 * save() skips a native field AND persists a custom field in the same call.
	 */
	public function test_save_skips_native_but_persists_custom_in_same_call(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->to_array(),
			Field::create( 'carrier_pickup_point' )->to_array(),
		] );

		Functions\when( 'do_action' )->justReturn();

		$spy = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 99, [ 'billing_city' => 'Москва', 'carrier_pickup_point' => 'PVZ-001' ] );

		$this->assertCount( 1, $spy->persisted );
		$this->assertSame( 'carrier_pickup_point', $spy->persisted[0]['id'] );
	}

	// -----------------------------------------------------------------------
	// Part B — independent pickup backstop (Task 7b)
	// -----------------------------------------------------------------------

	/**
	 * When a pickup method is chosen and the pickup field is empty, the backstop
	 * must block checkout regardless of the field's condition-spec. Matches by
	 * prefix ('carrier_pickup:3' matches id 'carrier_pickup').
	 */
	public function test_requires_pickup_backstop_blocks_regardless_of_spec(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )->atLeast()->once();
		$fields  = Checkout_Fields::from_array( [ \Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );
		$this->assertFalse( $handler->validate( [ 'carrier_pickup_point' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup:3' ] ) ); // matches by prefix
	}

	/**
	 * When the pickup field is filled, the backstop must pass even when the
	 * method matches (exact match variant).
	 */
	public function test_backstop_passes_when_pickup_filled(): void {
		$fields  = Checkout_Fields::from_array( [ \Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );
		$this->assertTrue( $handler->validate( [ 'carrier_pickup_point' => 'PVZ-1' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] ) );
	}

	/**
	 * When a different (non-pickup) method is chosen, the backstop must be silent
	 * even when the pickup field is empty.
	 */
	public function test_backstop_ignored_for_other_method(): void {
		$fields  = Checkout_Fields::from_array( [ \Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array() ] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );
		$this->assertTrue( $handler->validate( [ 'carrier_pickup_point' => '' ], [ 'chosen_shipping_method' => 'flat_rate' ] ) );
	}

	// -----------------------------------------------------------------------
	// Part C — measured duplication fix + error_label fallback (#299, #134)
	// -----------------------------------------------------------------------

	/**
	 * Measured duplication: when a `Pickup_Field` preset's condition-spec matches
	 * EXACTLY the same method id list passed to `set_requires_pickup_methods()`
	 * (the mainline, non-degenerate usage), both the per-field conditional-required
	 * loop AND the independent backstop previously fired for the identical blank
	 * field on the identical submit — two `wc_add_notice()` calls, one condition.
	 * The per-field loop runs first, so the ONE surviving notice must be the
	 * `required_message()` text (using the preset's default `error_label`), and the
	 * backstop's own "Для доставки..." text must NOT appear a second time.
	 */
	public function test_exact_match_dedupes_required_and_backstop_into_one_notice(): void {
		// A plain `->once()->with()` expectation only asserts that ONE call matched
		// those exact arguments — it does NOT reject an ADDITIONAL call with different
		// arguments (e.g. the backstop's own notice firing on top of the per-field
		// loop's). Capture every call instead so the dedup guard is actually exercised:
		// mutating away the `blank_required_ids` skip must redden this test with a
		// second, different notice.
		$captured = [];
		\Brain\Monkey\Functions\when( 'wc_add_notice' )->alias(
			static function ( $message, $type ) use ( &$captured ) {
				$captured[] = [ $message, $type ];
			}
		);

		$fields  = Checkout_Fields::from_array( [
			\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

		$this->assertFalse(
			$handler->validate( [ 'carrier_pickup_point' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] )
		);

		$this->assertSame(
			[ [ 'Вы не выбрали пункт выдачи заказов.', 'error' ] ],
			$captured,
			'exactly one notice — the backstop must not repeat the per-field loop\'s notice'
		);
	}

	// -----------------------------------------------------------------------
	// Part C2 — a button-driven field does not have a "value" to specify (#327)
	// -----------------------------------------------------------------------

	/**
	 * Issue #327, found by the operator on the rig. The generic template says «Укажите
	 * значение поля «X».», which describes an INPUT — and a pickup field's visible
	 * control is a button, so the buyer goes looking for a field that is not on the page.
	 * #299 had deliberately widened the verb («Заполните» → «Укажите») to make ONE
	 * template cover both shapes; that compromise is what this replaces — the rest of the
	 * sentence still describes a typed input.
	 */
	public function test_required_message_for_a_pickup_slot_field_names_the_choice_not_a_field(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Вы не выбрали пункт выдачи заказов.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->set_required( true )
				->set_error_label( 'Пункт выдачи' )
				->mark_pickup_slot()
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => '' ], [] ) );
	}

	/**
	 * The generic template is correct for a field the customer really does type into, so
	 * #327 must not touch it. Pins the branch, not just the new message.
	 */
	public function test_required_message_for_an_ordinary_field_keeps_the_field_template(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Укажите значение поля «Город».', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_city' )->set_required( true )->set_error_label( 'Город' )->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_city' => '' ], [] ) );
	}

	/**
	 * The framework's default names OUR vocabulary («пункт выдачи»), and a carrier's may
	 * differ — Почта РФ has отделения, not пункты выдачи. Same ownership split #323
	 * settled for the trigger button: the framework owns the mechanism, the plugin owns
	 * the words. `set_required_message()` replaces the whole sentence, because there is
	 * no template a carrier-neutral framework can supply that fits every carrier's noun.
	 */
	public function test_required_message_can_be_replaced_wholesale_by_the_plugin(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Вы не выбрали отделение Почты России.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->set_required( true )
				->mark_pickup_slot()
				->set_required_message( 'Вы не выбрали отделение Почты России.' )
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => '' ], [] ) );
	}

	/**
	 * The override is about the FIELD, not about one code path, so the independent
	 * backstop honours it too — otherwise a plugin that renamed the message would still
	 * see the framework's own noun whenever the two method-id lists diverge.
	 */
	public function test_backstop_honours_the_plugins_required_message(): void {
		$captured = [];
		\Brain\Monkey\Functions\when( 'wc_add_notice' )->alias(
			static function ( $message, $type ) use ( &$captured ) {
				$captured[] = [ $message, $type ];
			}
		);

		$fields  = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->mark_pickup_slot()
				->set_required_message( 'Вы не выбрали отделение Почты России.' )
				->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

		$this->assertFalse(
			$handler->validate( [ 'carrier_pickup_point' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] )
		);

		$this->assertSame( [ [ 'Вы не выбрали отделение Почты России.', 'error' ] ], $captured );
	}

	/**
	 * #328 — the SIBLING outcome had the same defect and no seam. «Поле «Пункт выдачи»
	 * заполнено некорректно.» sends the customer looking for an input that is not on the
	 * page, exactly as #327's message did. The framework deliberately ships no
	 * button-specific DEFAULT for it (for an already-chosen point "filled in incorrectly"
	 * most likely means "that point is unavailable" — a different statement only the domain
	 * can vouch for), so what it offers instead is the override.
	 */
	public function test_invalid_message_can_be_replaced_wholesale_by_the_plugin(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Выбранное отделение сейчас недоступно.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->mark_pickup_slot()
				->set_invalid_message( 'Выбранное отделение сейчас недоступно.' )
				->set_validate_callback( static fn( $value ) => false )
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => 'PVZ-1' ], [] ) );
	}

	/**
	 * Without an override the framework's own template still applies — the seam adds a
	 * choice, it does not change the default for every field that never asked.
	 */
	public function test_invalid_message_keeps_the_framework_template_when_no_override_was_supplied(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Поле «Город» заполнено некорректно.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_city' )
				->set_label( 'Город' )
				->set_validate_callback( static fn( $value ) => false )
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_city' => 'X' ], [] ) );
	}

	/**
	 * A `WP_Error` from the callback carries its OWN words, and they win over the
	 * override — the plugin said something more specific about THIS value than its own
	 * generic sentence for the field.
	 */
	public function test_a_wp_error_from_the_callback_still_beats_the_supplied_invalid_message(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Этот пункт закрыт на ремонт до 20 августа.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->mark_pickup_slot()
				->set_invalid_message( 'Выбранное отделение сейчас недоступно.' )
				->set_validate_callback(
					static function ( $value ) {
						return new \WP_Error( 'closed', 'Этот пункт закрыт на ремонт до 20 августа.' );
					}
				)
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => 'PVZ-1' ], [] ) );
	}

	/**
	 * The backstop must still fire ALONE — and checkout must still block — when the
	 * per-field loop does NOT catch the same field because the descriptor's own
	 * condition-spec method-id list diverges from the list passed to
	 * `set_requires_pickup_methods()`. `Checkout_Condition`'s `in` operator does a
	 * strict string match against `chosen_shipping_method`, so a method id present in
	 * `requires_pickup_methods` but absent from the condition-spec's `value` list
	 * never trips the per-field loop's `required` gate.
	 *
	 * This IS genuinely reachable: both lists are supplied independently by the host
	 * plugin (`Pickup_Field::create()`'s second argument vs. `set_requires_pickup_methods()`)
	 * and nothing enforces they stay in sync. By contrast, a `method_id:instance_id`
	 * suffix divergence is NOT reachable here — every real entry point normalizes the
	 * posted method id to its bare form before calling `validate()` (see
	 * `chosen_shipping_method()` and `process()`, both via `normalize_method_id()`), so
	 * that shape only ever reaches `validate()` when a test calls it directly.
	 */
	public function test_backstop_fires_alone_when_requires_pickup_methods_diverges_from_condition_spec(): void {
		// Same weakness as the dedup test above: `->once()->with()` cannot detect an
		// EXTRA call with different arguments, so "fires ALONE" needs every call
		// captured and the full list asserted, not just one matching call confirmed.
		$captured = [];
		\Brain\Monkey\Functions\when( 'wc_add_notice' )->alias(
			static function ( $message, $type ) use ( &$captured ) {
				$captured[] = [ $message, $type ];
			}
		);

		$fields  = Checkout_Fields::from_array( [
			// Condition-spec only lists 'carrier_pickup' — the per-field loop's strict
			// `in` comparison will not match 'carrier_pickup_express'.
			\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array(),
		] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		// set_requires_pickup_methods() lists a broader set than the condition-spec —
		// a mismatch a host plugin can genuinely introduce by not keeping both lists
		// in sync.
		$handler->set_requires_pickup_methods( [ 'carrier_pickup', 'carrier_pickup_express' ] );

		$this->assertFalse(
			$handler->validate( [ 'carrier_pickup_point' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup_express' ] )
		);

		// #327: the backstop keeps its OWN wording, distinct from the per-field message —
		// it fires precisely when the field's own condition did not match, so it is the
		// one that can explain WHY a point is needed. Both dropped «значение поля».
		$this->assertSame(
			[ [ 'Для этого способа доставки нужно выбрать пункт выдачи заказов.', 'error' ] ],
			$captured,
			'the backstop must fire ALONE — no additional notice from the per-field loop'
		);
	}

	/**
	 * required_message() must prefer `error_label` over `label` over `id` (#299,
	 * #134): a field with an empty `label` no longer leaks its raw id into the
	 * buyer-facing message when an `error_label` is set.
	 */
	public function test_required_message_prefers_error_label_over_label_and_id(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Укажите значение поля «Пункт выдачи».', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->set_required( true )->set_error_label( 'Пункт выдачи' )->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => '' ], [] ) );
	}

	/**
	 * Without an `error_label`, required_message() still falls back to the raw id
	 * (regression guard — pre-existing safety net for a field nobody labelled at all).
	 */
	public function test_required_message_falls_back_to_id_without_error_label_or_label(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Укажите значение поля «carrier_pickup_point».', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->set_required( true )->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => '' ], [] ) );
	}

	/**
	 * invalid_message() must also prefer `error_label` over `label` over `id`,
	 * matching required_message()'s fallback order.
	 */
	public function test_invalid_message_prefers_error_label(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )
			->once()
			->with( 'Поле «Пункт выдачи» заполнено некорректно.', 'error' );

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )
				->set_error_label( 'Пункт выдачи' )
				->set_validate_callback( static fn() => false )
				->to_array(),
		] );

		$this->assertFalse( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'carrier_pickup_point' => 'x' ], [] ) );
	}

	// -----------------------------------------------------------------------
	// Part D — entry-point parity for the chosen shipping method
	// -----------------------------------------------------------------------

	/**
	 * process() must strip the `:instance_id` suffix before evaluating condition-specs,
	 * exactly as the `woocommerce_checkout_process` path and the JS store do.
	 *
	 * Regression: process() used to thread the RAW posted value ('carrier_pickup:3') into
	 * the state map, so a spec declared against the bare id ('carrier_pickup') evaluated to
	 * "not required" here while blocking on the other entry point — the same order was
	 * gated or not depending on which path validated it.
	 */
	public function test_process_normalizes_instance_id_before_evaluating_conditions(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )->atLeast()->once();
		\Brain\Monkey\Functions\when( 'wc_clean' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );

		$handler = new Checkout_Handler( $fields, 'carrier' );

		$blocked = $handler->process(
			[ 'pvz' => '', 'shipping_method' => [ 'carrier_pickup:3' ], 'billing_country' => 'RU' ],
			0
		);

		$this->assertFalse( $blocked, 'process() must block: the bare-id spec has to match a posted method carrying an instance id' );
	}

	/**
	 * The same spec must still pass through process() when a genuinely different
	 * method is chosen (guards against the normalization matching too eagerly).
	 */
	public function test_process_does_not_match_a_different_method_with_instance_id(): void {
		\Brain\Monkey\Functions\expect( 'wc_add_notice' )->never();
		\Brain\Monkey\Functions\when( 'wc_clean' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );

		$handler = new SpyCheckoutHandler( $fields, 'carrier' );

		$this->assertTrue(
			$handler->process( [ 'pvz' => '', 'shipping_method' => [ 'flat_rate:2' ], 'billing_country' => 'RU' ], 0 )
		);
	}
}

/**
 * Spy subclass for Checkout_Handler that records persist_field() calls without
 * invoking the real Woodev_Order_Compatibility::update_order_meta().
 *
 * @internal For testing only.
 */
class SpyCheckoutHandler extends Checkout_Handler {

	/** @var array<int, array{order: mixed, id: string, value: mixed}> */
	public array $persisted = [];

	/**
	 * {@inheritdoc}
	 */
	protected function persist_field( $order, string $id, $value ): void {
		$this->persisted[] = [ 'order' => $order, 'id' => $id, 'value' => $value ];
	}
}

/**
 * Transparent probe subclass that exposes is_native_wc_field() publicly.
 *
 * Avoids ReflectionMethod::setAccessible() (deprecated in PHP 8.5) while still
 * testing the protected helper without changing its visibility in production code.
 *
 * @internal For testing only.
 */
class NativeFieldProbe extends Checkout_Handler {

	/**
	 * Delegates to the protected is_native_wc_field() for assertion in tests.
	 *
	 * @param string $id field id to test
	 *
	 * @return bool
	 */
	public function probe( string $id ): bool {
		return $this->is_native_wc_field( $id );
	}
}
