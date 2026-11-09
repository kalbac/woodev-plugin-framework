<?php
/**
 * Tests for the stock formulas.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Stock;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;

/**
 * Both pushed formulas, and the mirrored quantity. Which pushed formula is in force is the adapter's decision, so the policy has no
 * default.
 */
final class StockPolicyTest extends TestCase {
	/**
	 * The adapter names the formula in force; the layer has no default (plain WC stock here, as the Ozon adapter passes it).
	 *
	 * @return void
	 */
	public function test_the_formula_the_adapter_names_is_in_force(): void {
		$policy = new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY );

		$this->assertSame( Stock_Policy::FORMULA_WC_ONLY, $policy->formula() );
		$this->assertSame( 7, $policy->pushed_quantity( 7, 2 ) );
		$this->assertSame( 0, $policy->pushed_quantity( -3, 2 ), 'a backorder count sends 0' );
	}

	/**
	 * Formula A adds the last read reservation; an unread one counts as 0.
	 *
	 * @return void
	 */
	public function test_formula_a_adds_the_reservation(): void {
		$policy = new Stock_Policy( Stock_Policy::FORMULA_PLUS_RESERVED );

		$this->assertSame( 9, $policy->pushed_quantity( 7, 2 ) );
		$this->assertSame( 7, $policy->pushed_quantity( 7, null ) );
		$this->assertSame( 2, $policy->pushed_quantity( -1, 2 ) );
	}

	/**
	 * The mirrored quantity is what Ozon can still sell.
	 *
	 * @return void
	 */
	public function test_the_mirror_is_present_minus_reserved(): void {
		$this->assertSame( 9, Stock_Policy::mirrored_quantity( 10, 1 ) );
		$this->assertSame( 0, Stock_Policy::mirrored_quantity( 1, 3 ) );
	}

	/**
	 * An unknown formula is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_formula_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Stock_Policy( 'guess' );
	}
}
