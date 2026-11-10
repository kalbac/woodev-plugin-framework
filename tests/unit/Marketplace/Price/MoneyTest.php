<?php
/**
 * Tests for Money.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Price;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Price\Money;

/**
 * Money is exact: no float anywhere, half-up rounding on every division, and v1's lost cents cannot come back.
 */
final class MoneyTest extends TestCase {
	/**
	 * Decimal strings parse into exact minor units, comma or dot, with half-up rounding past the minor unit.
	 *
	 * @return void
	 */
	public function test_from_string_parses_decimals_exactly(): void {
		$this->assertSame( 10050, Money::from_string( '100.50', 'RUB' )->minor() );
		$this->assertSame( 10050, Money::from_string( '100,5', 'RUB' )->minor() );
		$this->assertSame( 10000, Money::from_string( '100', 'RUB' )->minor() );
		$this->assertSame( 1001, Money::from_string( '10.005', 'RUB' )->minor(), 'half rounds away from zero' );
		$this->assertSame( 1000, Money::from_string( '10.0049999', 'RUB' )->minor() );
		$this->assertSame( -250, Money::from_string( '-2.5', 'RUB' )->minor() );
		$this->assertSame( 199, Money::from_string( '1.99', 'RUB' )->minor(), '1.99 must not become 198 through a float' );
	}

	/**
	 * Ozon sends prices like "1234.0000"; JPY has no minor unit.
	 *
	 * @return void
	 */
	public function test_from_string_handles_ozon_style_and_zero_decimal_currencies(): void {
		$this->assertSame( 123400, Money::from_string( '1234.0000', 'RUB' )->minor() );
		$this->assertSame( 1235, Money::from_string( '1234.5', 'JPY' )->minor() );
		$this->assertSame( '1235', Money::of( 1235, 'JPY' )->to_string() );
	}

	/**
	 * Anything that is not a plain decimal is refused instead of guessed.
	 *
	 * @dataProvider malformed_amounts
	 * @param string $amount Malformed amount.
	 * @return void
	 */
	public function test_from_string_rejects_malformed_input( string $amount ): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::from_string( $amount, 'RUB' );
	}

	/**
	 * Inputs that must be refused.
	 *
	 * @return array<string,string[]>
	 */
	public function malformed_amounts(): array {
		return array(
			'empty'           => array( '' ),
			'text'            => array( 'abc' ),
			'exponent'        => array( '1e3' ),
			'thousands'       => array( '1,234.50' ),
			'two separators'  => array( '1.2.3' ),
			'too many digits' => array( '12345678901234567890' ),
		);
	}

	/**
	 * Strings and minor units round-trip, including values below one unit and negatives.
	 *
	 * @return void
	 */
	public function test_to_string_formats_minor_units(): void {
		$this->assertSame( '1234.50', Money::of( 123450, 'RUB' )->to_string() );
		$this->assertSame( '0.05', Money::of( 5, 'RUB' )->to_string() );
		$this->assertSame( '0.00', Money::zero( 'RUB' )->to_string() );
		$this->assertSame( '-0.99', Money::of( -99, 'RUB' )->to_string() );
	}

	/**
	 * A malformed currency code is refused.
	 *
	 * @return void
	 */
	public function test_currency_must_be_iso_code(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::of( 100, 'rub' );
	}

	/**
	 * Adding, subtracting and comparing need the same currency; a mismatch never converts silently.
	 *
	 * @return void
	 */
	public function test_arithmetic_requires_same_currency(): void {
		$rub = Money::of( 100, 'RUB' );
		$this->assertSame( 250, $rub->add( Money::of( 150, 'RUB' ) )->minor() );
		$this->assertSame( -50, $rub->sub( Money::of( 150, 'RUB' ) )->minor() );

		$this->expectException( \InvalidArgumentException::class );
		$rub->add( Money::of( 100, 'CNY' ) );
	}

	/**
	 * Comparison and equality use value and currency.
	 *
	 * @return void
	 */
	public function test_compare_and_equals(): void {
		$this->assertSame( -1, Money::of( 1, 'RUB' )->compare( Money::of( 2, 'RUB' ) ) );
		$this->assertSame( 0, Money::of( 2, 'RUB' )->compare( Money::of( 2, 'RUB' ) ) );
		$this->assertTrue( Money::of( 2, 'RUB' )->equals( Money::of( 2, 'RUB' ) ) );
		$this->assertFalse( Money::of( 2, 'RUB' )->equals( Money::of( 2, 'CNY' ) ) );
	}

	/**
	 * The v1 defect: a quantity times a price must keep its cents (2 x 100.50 = 201.00).
	 *
	 * @return void
	 */
	public function test_multiplying_by_quantity_keeps_cents(): void {
		$this->assertSame( '201.00', Money::from_string( '100.50', 'RUB' )->mul( 2 )->to_string() );
	}

	/**
	 * Percentages apply to minor units with half-up rounding; decimals in the percentage work.
	 *
	 * @return void
	 */
	public function test_mul_percent_rounds_half_up(): void {
		$this->assertSame( '20.00', Money::from_string( '100', 'RUB' )->mul_percent( '20' )->to_string() );
		$this->assertSame( '12.50', Money::from_string( '100', 'RUB' )->mul_percent( '12.5' )->to_string() );
		$this->assertSame( '0.03', Money::of( 5, 'RUB' )->mul_percent( '50' )->to_string(), '2.5 kopecks rounds up to 3' );
		$this->assertSame( '-0.03', Money::of( -5, 'RUB' )->mul_percent( '50' )->to_string(), 'half rounds away from zero' );
		$this->assertSame( '0.00', Money::of( 100, 'RUB' )->mul_percent( '0' )->to_string() );
		$this->assertSame( '-20.00', Money::from_string( '100', 'RUB' )->mul_percent( '-20' )->to_string() );
	}

	/**
	 * A malformed percentage is refused.
	 *
	 * @return void
	 */
	public function test_mul_percent_rejects_malformed_percentage(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::of( 100, 'RUB' )->mul_percent( '20%' );
	}

	/**
	 * The ratio helper refuses a zero denominator and an overflowing product instead of wrapping into a float.
	 *
	 * @return void
	 */
	public function test_mul_ratio_guards_zero_and_overflow(): void {
		$this->assertSame( 33, Money::of( 100, 'RUB' )->mul_ratio( 1, 3 )->minor() );
		$this->assertSame( 67, Money::of( 100, 'RUB' )->mul_ratio( 2, 3 )->minor() );

		try {
			Money::of( 100, 'RUB' )->mul_ratio( 1, 0 );
			$this->fail( 'Zero denominator accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'zero', $e->getMessage() );
		}

		$this->expectException( \OverflowException::class );
		Money::of( PHP_INT_MAX, 'RUB' )->mul( 2 );
	}

	/**
	 * Rounding to whole units and tens.
	 *
	 * @return void
	 */
	public function test_round_to_whole_units_and_tens(): void {
		$this->assertSame( '1235.00', Money::from_string( '1234.50', 'RUB' )->round_to( 1 )->to_string() );
		$this->assertSame( '1234.00', Money::from_string( '1234.49', 'RUB' )->round_to( 1 )->to_string() );
		$this->assertSame( '1230.00', Money::from_string( '1234.56', 'RUB' )->round_to( 10 )->to_string() );
		$this->assertSame( '1240.00', Money::from_string( '1235', 'RUB' )->round_to( 10 )->to_string() );
	}

	/**
	 * Rounding up to a price ending: the smallest whole-unit value ending in 99 that is not below the amount.
	 *
	 * @return void
	 */
	public function test_round_up_to_ending(): void {
		$this->assertSame( '1299.00', Money::from_string( '1234.56', 'RUB' )->round_up_to_ending( 100, 99 )->to_string() );
		$this->assertSame( '1199.00', Money::from_string( '1199', 'RUB' )->round_up_to_ending( 100, 99 )->to_string() );
		$this->assertSame( '1299.00', Money::from_string( '1199.01', 'RUB' )->round_up_to_ending( 100, 99 )->to_string() );
		$this->assertSame( '99.00', Money::from_string( '50', 'RUB' )->round_up_to_ending( 100, 99 )->to_string() );
	}

	/**
	 * Out-of-range rounding arguments are refused.
	 *
	 * @return void
	 */
	public function test_rounding_arguments_are_validated(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::of( 100, 'RUB' )->round_to( 0 );
	}
}
