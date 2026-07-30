<?php
/**
 * Unit tests for Constraint_Checker — COD and weight gating, the fail-open behaviour for
 * unknown carrier data, the filter override seam, and its fail-closed validation.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Constraint_Checker
 */
final class ConstraintCheckerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, $decimals );
			}
		);
	}

	/**
	 * Builds a point with a valid base payload, overridden by $extra.
	 *
	 * @param array<string, mixed> $extra
	 */
	private function point( array $extra = [] ): Pickup_Point {
		return Pickup_Point::from_array(
			array_merge(
				[
					'id'      => 'P1',
					'name'    => 'Точка',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Москва',
					'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
				],
				$extra
			)
		);
	}

	// ---- the seven specified cases ----

	public function test_a_plain_point_is_selectable(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_cod_is_blocked_when_the_point_refuses_it(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertNotNull( $verdict['reason'] );
	}

	public function test_cod_refusal_is_irrelevant_for_another_payment_method(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_unknown_cod_support_is_permissive(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'cod', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_overweight_cart_is_blocked(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 20000 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertNotNull( $verdict['reason'] );
	}

	public function test_cart_within_the_limit_passes(): void {
		// Also pins the boundary: 15000g against a 15000g limit is NOT "over the limit" — a
		// duplicate of this case that only varied the assertion used to exist as a separate
		// test; folded in here rather than kept as a second test with identical inputs.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 15000 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_the_filter_can_override_the_verdict(): void {
		Functions\when( 'apply_filters' )->justReturn(
			[
				'allowed' => false,
				'reason'  => 'нельзя',
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( 'нельзя', $verdict['reason'] );
	}

	// ---- mutation-resistance: weight boundary ----

	public function test_weight_one_gram_over_the_limit_is_blocked(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 15001 );
		$this->assertFalse( $verdict['allowed'], '15001g must be blocked against a 15000g limit' );
	}

	public function test_weight_one_gram_under_the_limit_passes(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 14999 );
		$this->assertTrue( $verdict['allowed'], '14999g must pass a 15000g limit' );
	}

	// ---- mutation-resistance: unknown / zero cart weight ----

	public function test_max_weight_present_but_cart_weight_unknown_is_permissive(): void {
		// Cart weight of 0 (unknown/not computed) must never be treated as "over the limit".
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_a_positive_cart_weight_with_no_declared_max_weight_is_permissive(): void {
		// Pins that "no max_weight" (null) is never coerced to a 0g limit — a positive cart
		// weight against an absent limit must not be blocked.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 5000 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	// ---- mutation-resistance: max_weight <= 0 means "no limit", not "the tightest limit" ----

	public function test_a_zero_max_weight_is_treated_as_no_limit(): void {
		// Several carriers encode "no limit" as 0; a non-positive limit must not become the
		// most restrictive possible gate. Without this guard 1g > 0g would block every cart.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 0 ] ), 'bacs', 1 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_a_negative_max_weight_is_treated_as_no_limit(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => -5000 ] ), 'bacs', 1 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_a_zero_gram_cart_still_passes_a_positive_limit(): void {
		// The non-positive-limit guard must not be paired with a `$cart_weight > 0` guard — an
		// empty/unknown cart against any positive limit must pass regardless.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	// ---- mutation-resistance: exact message content, not just the allowed flag ----

	public function test_the_weight_message_names_both_numbers_in_kilograms_in_the_right_order(): void {
		// Pins the rendered string exactly: kills dropping the g->kg conversion on either
		// number, swapping the two sprintf arguments (which would tell the customer a 15kg
		// order exceeds a 20.5kg limit — the reviewer's exact failure mode), and replacing the
		// message with unrelated text. 20500g cart against a 15000g limit, deliberately using
		// two DIFFERENT numbers so a transposition is observable.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 20500 );
		$this->assertSame(
			'Вес заказа 20.50 кг превышает ограничение пункта выдачи — 15.00 кг.',
			$verdict['reason']
		);
	}

	public function test_the_cod_message_is_pinned_exactly(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertSame(
			'В этом пункте выдачи недоступна оплата при получении. Выберите другой пункт или другой способ оплаты.',
			$verdict['reason']
		);
	}

	// ---- mutation-resistance: which reason wins when both constraints are violated ----

	public function test_weight_reason_wins_when_both_constraints_are_violated(): void {
		// The weight check runs first and short-circuits the COD check once the verdict is
		// already blocked. This is a deliberate design choice, not an artifact of if-block
		// order: weight is UNFIXABLE at the point picker (only removing cart items clears it),
		// while COD is fixable by switching payment method. Leading with the unfixable reason
		// avoids sending the customer to change gateway only to hit a second, unfixable wall.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$point   = $this->point(
			[
				'accepts_cod' => false,
				'max_weight'  => 15000,
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $point, 'cod', 20000 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertStringContainsString( 'Вес заказа', $verdict['reason'] );
		$this->assertStringNotContainsString( 'оплата при получении', $verdict['reason'] );
	}

	// ---- mutation-resistance: explicit true, and custom COD method lists ----

	public function test_accepts_cod_true_explicitly_passes_under_cod(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => true ] ), 'cod', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_a_custom_cod_method_list_is_honored(): void {
		// 'cod' is NOT in the custom list, so a refusing point must still be selectable
		// under the plain 'cod' payment method id.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$checker = new Constraint_Checker( [ 'sberpay_cod', 'paypal_cod' ] );
		$verdict = $checker->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_a_custom_cod_method_list_blocks_its_own_ids(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$checker = new Constraint_Checker( [ 'sberpay_cod', 'paypal_cod' ] );
		$verdict = $checker->check( $this->point( [ 'accepts_cod' => false ] ), 'sberpay_cod', 0 );
		$this->assertFalse( $verdict['allowed'] );
	}

	// ---- mutation-resistance: the filter receives the right arguments ----

	public function test_the_filter_receives_the_computed_verdict_and_call_context(): void {
		$captured = [];

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $verdict = null, ...$args ) use ( &$captured ) {
				$captured[] = [ $tag, $verdict, $args ];
				return $verdict;
			}
		);

		$point = $this->point( [ 'max_weight' => 15000 ] );
		( new Constraint_Checker() )->check( $point, 'bacs', 20000 );

		$this->assertCount( 1, $captured );
		[ $tag, $verdict, $args ] = $captured[0];

		$this->assertSame( 'woodev_shipping_pickup_point_selectable', $tag );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertNotNull( $verdict['reason'] );
		$this->assertSame( [ $point, 'bacs', 20000 ], $args );
	}

	// ---- fail-closed: a malformed filter return must not become the verdict ----

	public function test_a_non_array_filter_return_fails_closed_to_the_computed_verdict(): void {
		Functions\when( 'apply_filters' )->justReturn( 'not-an-array' );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_a_filter_return_missing_the_allowed_key_fails_closed(): void {
		Functions\when( 'apply_filters' )->justReturn( [ 'reason' => 'x' ] );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		// The computed verdict (blocked) must survive, not a silently-permissive default.
		$this->assertFalse( $verdict['allowed'] );
	}

	public function test_a_filter_return_with_a_non_bool_allowed_fails_closed(): void {
		Functions\when( 'apply_filters' )->justReturn(
			[
				'allowed' => 'yes',
				'reason'  => null,
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertIsBool( $verdict['allowed'] );
	}

	public function test_a_filter_return_with_a_non_string_non_null_reason_fails_closed(): void {
		Functions\when( 'apply_filters' )->justReturn(
			[
				'allowed' => false,
				'reason'  => [ 'not', 'a', 'string' ],
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		// Falls back to the computed verdict, which for this input is also blocked — but the
		// reason must be the computed string, never the malformed array.
		$this->assertFalse( $verdict['allowed'] );
		$this->assertIsString( $verdict['reason'] );
	}

	public function test_a_filter_return_missing_the_reason_key_fails_closed(): void {
		// 'allowed' is present and well-typed, but 'reason' is missing entirely (not merely
		// null) — the shape is still malformed and must not silently drop the computed reason.
		Functions\when( 'apply_filters' )->justReturn( [ 'allowed' => true ] );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertFalse( $verdict['allowed'], 'a filter return missing the reason key must fail closed' );
		$this->assertNotNull( $verdict['reason'] );
	}

	public function test_a_well_formed_filter_return_drops_extra_keys(): void {
		// The verdict becomes `selectable` in the point's browser JSON payload — an unvalidated
		// third-party key must not ride along. Rebuilding the array key-by-key (rather than
		// `return $filtered`) is what enforces this; pin it directly.
		Functions\when( 'apply_filters' )->justReturn(
			[
				'allowed' => true,
				'reason'  => null,
				'extra'   => 'should not survive',
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertSame( [ 'allowed' => true, 'reason' => null ], $verdict );
	}

	public function test_a_well_formed_filter_return_with_a_null_reason_is_honored(): void {
		// A null reason paired with allowed === true is a legitimate override shape, and must
		// not be rejected by the fail-closed guard as if it were malformed.
		Functions\when( 'apply_filters' )->justReturn(
			[
				'allowed' => true,
				'reason'  => null,
			]
		);
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}
}
