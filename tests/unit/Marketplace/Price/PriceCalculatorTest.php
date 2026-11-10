<?php
/**
 * Tests for Price_Calculator and Price_Rule.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Price;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Rule;

/**
 * The calculator is a pure function; each case below is a number a merchant would check by hand.
 */
final class PriceCalculatorTest extends TestCase {
	/**
	 * Builds RUB money from a decimal string.
	 *
	 * @param string $amount Decimal amount.
	 * @return Money
	 */
	private function rub( string $amount ): Money {
		return Money::from_string( $amount, 'RUB' );
	}

	/**
	 * An empty rule passes the price through and sends no old or minimum price.
	 *
	 * @return void
	 */
	public function test_default_rule_passes_the_price_through(): void {
		$pair = Price_Calculator::calculate( $this->rub( '1000' ), $this->rub( '1000' ), new Price_Rule() );

		$this->assertSame( '1000.00', $pair->price()->to_string() );
		$this->assertNull( $pair->old_price() );
		$this->assertNull( $pair->min_price() );
	}

	/**
	 * A percent markup is decimal arithmetic on minor units, half-up.
	 *
	 * @return void
	 */
	public function test_percent_markup(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '20' );

		$this->assertSame( '120.00', Price_Calculator::calculate( $this->rub( '100' ), null, $rule )->price()->to_string() );
		$this->assertSame( '1.20', Price_Calculator::calculate( $this->rub( '1' ), null, $rule )->price()->to_string() );
		$this->assertSame( '0.07', Price_Calculator::calculate( Money::of( 6, 'RUB' ), null, $rule )->price()->to_string(), '6 kopecks + 20 % = 7.2, rounds to 7' );
	}

	/**
	 * The resulting price is rounded once, half-up, also for negative percentages (critic finding 1): 100.05 at -10 % is
	 * 90.045 and must be 90.05; 0.01 at -50 % is 0.005 and must stay 0.01, never an avoidable zero price.
	 *
	 * @dataProvider percent_half_kopeck_cases
	 * @param string $price    Price.
	 * @param string $percent  Percent markup.
	 * @param string $expected Price after the markup.
	 * @return void
	 */
	public function test_percent_markup_rounds_the_resulting_price( string $price, string $percent, string $expected ): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, $percent );

		$this->assertSame( $expected, Price_Calculator::calculate( $this->rub( $price ), null, $rule )->price()->to_string() );
	}

	/**
	 * Half-kopeck boundaries for negative and positive percentages.
	 *
	 * @return array<string,string[]>
	 */
	public function percent_half_kopeck_cases(): array {
		return array(
			'-10 % of 100.05' => array( '100.05', '-10', '90.05' ),
			'-50 % of 0.01'   => array( '0.01', '-50', '0.01' ),
			'-50 % of 0.03'   => array( '0.03', '-50', '0.02' ),
			'-25 % of 0.10'   => array( '0.10', '-25', '0.08' ),
			'+10 % of 100.05' => array( '100.05', '10', '110.06' ),
			'+50 % of 0.01'   => array( '0.01', '50', '0.02' ),
		);
	}

	/**
	 * The old price gets the same single rounding, and the mode rounding comes after it.
	 *
	 * @return void
	 */
	public function test_percent_markup_rounding_applies_to_old_price_and_before_the_mode(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '-10' );
		$pair = Price_Calculator::calculate( $this->rub( '70.05' ), $this->rub( '100.05' ), $rule );
		$this->assertSame( '63.05', $pair->price()->to_string(), '70.05 x 0.9 = 63.045' );
		$this->assertSame( '90.05', $pair->old_price()->to_string() );

		$whole = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '-10', Price_Rule::ROUND_TO_1 );
		$this->assertSame( '90.00', Price_Calculator::calculate( $this->rub( '100.05' ), null, $whole )->price()->to_string() );
	}

	/**
	 * A fixed markup adds an amount, with kopecks.
	 *
	 * @return void
	 */
	public function test_fixed_markup(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '150.50' );

		$this->assertSame( '1150.50', Price_Calculator::calculate( $this->rub( '1000' ), null, $rule )->price()->to_string() );
	}

	/**
	 * Each rounding mode.
	 *
	 * @dataProvider rounding_cases
	 * @param string $mode     Rounding mode.
	 * @param string $price    Price before rounding.
	 * @param string $expected Price after rounding.
	 * @return void
	 */
	public function test_rounding_modes( string $mode, string $price, string $expected ): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', $mode );

		$this->assertSame( $expected, Price_Calculator::calculate( $this->rub( $price ), null, $rule )->price()->to_string() );
	}

	/**
	 * Rounding inputs and expectations.
	 *
	 * @return array<string,string[]>
	 */
	public function rounding_cases(): array {
		return array(
			'none keeps kopecks' => array( Price_Rule::ROUND_NONE, '1234.56', '1234.56' ),
			'to_1 rounds up'     => array( Price_Rule::ROUND_TO_1, '1234.56', '1235.00' ),
			'to_1 rounds down'   => array( Price_Rule::ROUND_TO_1, '1234.49', '1234.00' ),
			'to_10 rounds down'  => array( Price_Rule::ROUND_TO_10, '1234.56', '1230.00' ),
			'to_10 half up'      => array( Price_Rule::ROUND_TO_10, '1235', '1240.00' ),
			'ends_99'            => array( Price_Rule::ROUND_ENDS_99, '1234.56', '1299.00' ),
			'ends_99 exact'      => array( Price_Rule::ROUND_ENDS_99, '1199', '1199.00' ),
		);
	}

	/**
	 * Rounding must never turn a positive price into zero (4 rubles rounded to tens would be 0).
	 *
	 * @return void
	 */
	public function test_rounding_never_produces_a_zero_price(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_TO_10 );

		$this->assertSame( '4.00', Price_Calculator::calculate( $this->rub( '4' ), null, $rule )->price()->to_string() );
	}

	/**
	 * While a sale runs, the old price is the marked-up regular price.
	 *
	 * @return void
	 */
	public function test_old_price_is_the_marked_up_regular_price_on_sale(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '10' );
		$pair = Price_Calculator::calculate( $this->rub( '800' ), $this->rub( '1000' ), $rule );

		$this->assertSame( '880.00', $pair->price()->to_string() );
		$this->assertSame( '1100.00', $pair->old_price()->to_string() );
	}

	/**
	 * Without a sale there is no old price (Ozon clears it with 0); a regular price at or below the price is no sale.
	 *
	 * @return void
	 */
	public function test_no_old_price_without_a_sale(): void {
		$rule = new Price_Rule();

		$this->assertNull( Price_Calculator::calculate( $this->rub( '1000' ), $this->rub( '1000' ), $rule )->old_price() );
		$this->assertNull( Price_Calculator::calculate( $this->rub( '1000' ), $this->rub( '900' ), $rule )->old_price() );
		$this->assertNull( Price_Calculator::calculate( $this->rub( '1000' ), null, $rule )->old_price() );
	}

	/**
	 * The merchant can switch the old price off even during a sale.
	 *
	 * @return void
	 */
	public function test_old_price_rule_off(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_NONE, Price_Rule::OLD_PRICE_OFF );

		$this->assertNull( Price_Calculator::calculate( $this->rub( '800' ), $this->rub( '1000' ), $rule )->old_price() );
	}

	/**
	 * «Игнорировать распродажу»: the regular price is the base and nothing is shown as old.
	 *
	 * @return void
	 */
	public function test_regular_base_ignores_the_sale(): void {
		$rule = new Price_Rule( Price_Rule::BASE_REGULAR );
		$pair = Price_Calculator::calculate( $this->rub( '800' ), $this->rub( '1000' ), $rule );

		$this->assertSame( '1000.00', $pair->price()->to_string() );
		$this->assertNull( $pair->old_price() );
	}

	/**
	 * A regular base with an unknown regular price falls back to the active price.
	 *
	 * @return void
	 */
	public function test_regular_base_without_regular_price_uses_active(): void {
		$pair = Price_Calculator::calculate( $this->rub( '800' ), null, new Price_Rule( Price_Rule::BASE_REGULAR ) );

		$this->assertSame( '800.00', $pair->price()->to_string() );
	}

	/**
	 * The minimum price is a percentage of the calculated price; it never exceeds it (Ozon rejects that).
	 *
	 * @return void
	 */
	public function test_min_price_is_a_percentage_of_the_price(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '20', Price_Rule::ROUND_NONE, Price_Rule::OLD_PRICE_ON_SALE, '90' );
		$pair = Price_Calculator::calculate( $this->rub( '1000' ), null, $rule );

		$this->assertSame( '1200.00', $pair->price()->to_string() );
		$this->assertSame( '1080.00', $pair->min_price()->to_string() );
		$this->assertLessThanOrEqual( 0, $pair->min_price()->compare( $pair->price() ) );
	}

	/**
	 * A non-RUB product keeps its currency end to end.
	 *
	 * @return void
	 */
	public function test_currency_is_preserved(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '1.50' );
		$pair = Price_Calculator::calculate( Money::from_string( '10', 'CNY' ), null, $rule );

		$this->assertSame( 'CNY', $pair->price()->currency() );
		$this->assertSame( '11.50', $pair->price()->to_string() );
	}

	/**
	 * A product without a price cannot be priced; mixed currencies are refused.
	 *
	 * @return void
	 */
	public function test_invalid_inputs_are_refused(): void {
		try {
			Price_Calculator::calculate( Money::zero( 'RUB' ), null, new Price_Rule() );
			$this->fail( 'A zero price was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'price', $e->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		Price_Calculator::calculate( $this->rub( '800' ), Money::from_string( '1000', 'CNY' ), new Price_Rule() );
	}

	/**
	 * The v1 defect (code-report 18): +20 % on 100 exports 120, and the inverse must give 100 back, not 96.
	 *
	 * @return void
	 */
	public function test_invert_removes_a_percent_markup_by_division(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '20' );

		$this->assertSame( '100.00', Price_Calculator::invert( $this->rub( '120' ), $rule )->to_string() );
		$this->assertNotSame( '96.00', Price_Calculator::invert( $this->rub( '120' ), $rule )->to_string() );
	}

	/**
	 * Calculate then invert returns the original for markups and prices that do not lose precision.
	 *
	 * @dataProvider round_trip_cases
	 * @param string $kind  Markup kind.
	 * @param string $value Markup value.
	 * @param string $price Starting price.
	 * @return void
	 */
	public function test_invert_undoes_calculate( string $kind, string $value, string $price ): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, $kind, $value );
		$sent = Price_Calculator::calculate( $this->rub( $price ), null, $rule )->price();

		$this->assertSame( $price, Price_Calculator::invert( $sent, $rule )->to_string() );
	}

	/**
	 * Round-trip inputs.
	 *
	 * @return array<string,string[]>
	 */
	public function round_trip_cases(): array {
		return array(
			'percent 20'       => array( Price_Rule::MARKUP_PERCENT, '20', '100.00' ),
			'percent 12.5'     => array( Price_Rule::MARKUP_PERCENT, '12.5', '800.00' ),
			'percent 33.3333'  => array( Price_Rule::MARKUP_PERCENT, '33.3333', '300.00' ),
			'negative percent' => array( Price_Rule::MARKUP_PERCENT, '-10', '500.00' ),
			'fixed'            => array( Price_Rule::MARKUP_FIXED, '150.50', '1000.00' ),
			'none'             => array( Price_Rule::MARKUP_NONE, '0', '1000.00' ),
		);
	}

	/**
	 * A fixed markup bigger than the remote price gives zero, not a negative price.
	 *
	 * @return void
	 */
	public function test_invert_never_returns_a_negative_price(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '500' );

		$this->assertSame( '0.00', Price_Calculator::invert( $this->rub( '100' ), $rule )->to_string() );
	}

	/**
	 * Rules built from stored JSON take defaults for missing keys and reject bad values.
	 *
	 * @return void
	 */
	public function test_rule_from_array_and_validation(): void {
		$rule = Price_Rule::from_array(
			array(
				'markup_kind' => 'percent',
				'markup'      => '5',
				'min_percent' => '',
			)
		);
		$this->assertSame( Price_Rule::BASE_ACTIVE, $rule->base() );
		$this->assertNull( $rule->min_percent() );
		$this->assertSame( '5', $rule->markup() );

		foreach ( array(
			array( 'rounding' => 'banker' ),
			array(
				'markup_kind' => 'percent',
				'markup'      => '-100',
			),
			array(
				'markup_kind' => 'fixed',
				'markup'      => '-5',
			),
			array( 'min_percent' => '0' ),
			array( 'min_percent' => '101' ),
			array( 'base' => 'cost' ),
		) as $invalid ) {
			try {
				Price_Rule::from_array( $invalid );
				$this->fail( 'Accepted an invalid rule: ' . implode( ',', array_keys( $invalid ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
