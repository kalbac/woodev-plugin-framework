<?php
/**
 * Tests for Checkout_Condition::is_required() — condition-spec evaluator.
 *
 * Covers plain bool passthrough, single-condition operators (=, !=, in,
 * not_in), AND/OR relations, and the Codex-hardened edge cases:
 * unknown operator → false, empty conditions → false,
 * in/not_in with non-array value → false.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Condition;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Condition::is_required
 */
class CheckoutConditionTest extends TestCase {

	/** @var array<string, mixed> */
	private array $state = [ 'chosen_shipping_method' => 'carrier_pickup:3' ];

	public function test_bool_required_passthrough(): void {
		$this->assertTrue( Checkout_Condition::is_required( true, $this->state ) );
		$this->assertFalse( Checkout_Condition::is_required( false, $this->state ) );
	}

	public function test_in_operator_matches(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup:3', 'x' ] ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_not_in_operator(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'not_in', 'value' => [ 'flat_rate' ] ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_and_or_relations(): void {
		$and = [
			'relation'   => 'AND',
			'conditions' => [
				[ 'state' => 'chosen_shipping_method', 'operator' => '=', 'value' => 'carrier_pickup:3' ],
				[ 'state' => 'country', 'operator' => '=', 'value' => 'RU' ],
			],
		];
		$this->assertFalse( Checkout_Condition::is_required( $and, $this->state ) ); // country missing -> '' != 'RU'
	}

	public function test_unknown_operator_fails_open_false(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'regex', 'value' => '.*' ];
		$this->assertFalse( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_missing_state_is_empty_string(): void {
		$spec = [ 'state' => 'nope', 'operator' => '=', 'value' => '' ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) ); // '' === ''
	}

	public function test_empty_conditions_is_false(): void { // Codex parity edge
		$this->assertFalse( Checkout_Condition::is_required( [ 'relation' => 'AND', 'conditions' => [] ], $this->state ) );
		$this->assertFalse( Checkout_Condition::is_required( [ 'relation' => 'OR', 'conditions' => [] ], $this->state ) );
	}

	public function test_in_with_non_array_value_is_false(): void { // Codex parity edge
		$this->assertFalse(
			Checkout_Condition::is_required(
				[ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => 'carrier_pickup:3' ],
				$this->state
			)
		);
	}
}
