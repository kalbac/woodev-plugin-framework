<?php
/**
 * Immutable money value in minor units.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * An amount of money held as an integer count of minor units (kopecks, cents) plus an ISO 4217 currency.
 *
 * No float ever touches an amount: strings are parsed digit by digit, percentages are applied as an integer ratio and
 * every division rounds half away from zero. v1 truncated cents with `intval()`; Wildberries sends integer kopecks, so
 * the type matches both marketplaces.
 *
 * @since 2.0.29
 */
final class Money {
	/**
	 * Currencies that have no minor unit; every other currency has two decimal places.
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL_CURRENCIES = [ 'JPY', 'KRW', 'VND' ];

	/**
	 * Most decimal places accepted in a percentage such as `12.5`.
	 */
	private const MAX_PERCENT_DECIMALS = 4;

	/**
	 * Amount in minor units.
	 *
	 * @var int
	 */
	private $minor;

	/**
	 * ISO 4217 code.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * Use the named constructors.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Three-letter upper-case currency code.
	 */
	private function __construct( int $minor, string $currency ) {
		$this->minor    = $minor;
		$this->currency = $currency;
	}

	/**
	 * Creates money from an amount in minor units.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $minor    Amount in minor units (kopecks).
	 * @param string $currency Three-letter upper-case currency code.
	 * @return self
	 * @throws \InvalidArgumentException When the currency code is malformed.
	 */
	public static function of( int $minor, string $currency ): self {
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new \InvalidArgumentException( 'Currency must be a three-letter upper-case ISO 4217 code.' );
		}

		return new self( $minor, $currency );
	}

	/**
	 * Creates a zero amount.
	 *
	 * @since 2.0.29
	 *
	 * @param string $currency Currency code.
	 * @return self
	 */
	public static function zero( string $currency ): self {
		return self::of( 0, $currency );
	}

	/**
	 * Parses a decimal string such as `1234.50`, `1234,5`, `-3` or `100`.
	 *
	 * Digits beyond the currency's minor unit are rounded half away from zero (`10.005` is `10.01`).
	 *
	 * @since 2.0.29
	 *
	 * @param string $amount   Decimal amount in major units; exponent notation and thousands separators are rejected.
	 * @param string $currency Currency code.
	 * @return self
	 * @throws \InvalidArgumentException When the string is not a plain decimal number or does not fit an integer.
	 */
	public static function from_string( string $amount, string $currency ): self {
		if ( 1 !== preg_match( '/^\s*(-?)(\d{1,15})(?:[.,](\d+))?\s*$/', $amount, $parts ) ) {
			throw new \InvalidArgumentException( 'Amount is not a plain decimal number.' );
		}

		$exponent = self::exponent_of( $currency );
		$fraction = $parts[3] ?? '';
		$minor    = (int) ( $parts[2] . str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' ) );
		$next     = substr( $fraction, $exponent, 1 );
		if ( '' !== $next && (int) $next >= 5 ) {
			++$minor;
		}

		return self::of( '-' === $parts[1] ? -$minor : $minor, $currency );
	}

	/**
	 * Returns the amount in minor units.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function minor(): int {
		return $this->minor;
	}

	/**
	 * Returns the currency code.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Formats the amount as a plain decimal string in major units, e.g. `1234.50`.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function to_string(): string {
		$exponent = self::exponent_of( $this->currency );
		$digits   = str_pad( (string) abs( $this->minor ), $exponent + 1, '0', STR_PAD_LEFT );
		$sign     = $this->minor < 0 ? '-' : '';
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}

		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Adds an amount of the same currency (another currency throws `InvalidArgumentException`).
	 *
	 * @since 2.0.29
	 *
	 * @param Money $other Amount to add.
	 * @return self
	 * @throws \OverflowException When the sum does not fit an integer.
	 */
	public function add( Money $other ): self {
		$this->assert_same_currency( $other );
		$sum = $this->minor + $other->minor;
		if ( ! is_int( $sum ) ) {
			throw new \OverflowException( 'Money sum overflows.' );
		}

		return new self( $sum, $this->currency );
	}

	/**
	 * Subtracts an amount of the same currency (another currency throws `InvalidArgumentException`).
	 *
	 * @since 2.0.29
	 *
	 * @param Money $other Amount to subtract.
	 * @return self
	 * @throws \OverflowException When the difference does not fit an integer.
	 */
	public function sub( Money $other ): self {
		$this->assert_same_currency( $other );
		$difference = $this->minor - $other->minor;
		if ( ! is_int( $difference ) ) {
			throw new \OverflowException( 'Money difference overflows.' );
		}

		return new self( $difference, $this->currency );
	}

	/**
	 * Multiplies the amount by a whole number.
	 *
	 * @since 2.0.29
	 *
	 * @param int $factor Quantity.
	 * @return self
	 * @throws \OverflowException When the product does not fit an integer.
	 */
	public function mul( int $factor ): self {
		return $this->mul_ratio( $factor, 1 );
	}

	/**
	 * Takes a percentage of the amount, rounded half away from zero (`mul_percent( '20' )` of 100.00 is 20.00).
	 *
	 * @since 2.0.29
	 *
	 * @param string $percent Decimal percentage such as `20` or `12.5`; at most four decimals; may be negative.
	 * @return self
	 * @throws \InvalidArgumentException When the percentage is malformed.
	 */
	public function mul_percent( string $percent ): self {
		list( $numerator, $denominator ) = self::percent_ratio( $percent );

		return $this->mul_ratio( $numerator, $denominator );
	}

	/**
	 * Multiplies by a fraction and rounds half away from zero. The only place that divides money.
	 *
	 * @since 2.0.29
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator, not zero.
	 * @return self
	 * @throws \InvalidArgumentException When the denominator is zero.
	 * @throws \OverflowException         When the intermediate product does not fit an integer.
	 */
	public function mul_ratio( int $numerator, int $denominator ): self {
		if ( 0 === $denominator ) {
			throw new \InvalidArgumentException( 'Denominator must not be zero.' );
		}

		$sign      = ( $this->minor < 0 xor $numerator < 0 xor $denominator < 0 ) ? -1 : 1;
		$magnitude = abs( $this->minor );
		$numerator = abs( $numerator );
		$divisor   = abs( $denominator );
		if ( $numerator > 0 && $magnitude > intdiv( PHP_INT_MAX, $numerator ) ) {
			throw new \OverflowException( 'Money product overflows.' );
		}

		$product   = $magnitude * $numerator;
		$quotient  = intdiv( $product, $divisor );
		$remainder = $product % $divisor;
		if ( $remainder >= $divisor - $remainder ) {
			++$quotient;
		}

		return new self( $sign * $quotient, $this->currency );
	}

	/**
	 * Rounds half away from zero to a multiple of `$step_major` whole currency units (`round_to( 10 )`: 1234.56 becomes 1230.00).
	 *
	 * @since 2.0.29
	 *
	 * @param int $step_major Step in major units, at least 1.
	 * @return self
	 * @throws \InvalidArgumentException When the step is below 1.
	 */
	public function round_to( int $step_major ): self {
		if ( $step_major < 1 ) {
			throw new \InvalidArgumentException( 'Rounding step must be at least one unit.' );
		}

		$step = $step_major * self::unit_of( $this->currency );

		return new self( $this->mul_ratio( 1, $step )->minor * $step, $this->currency );
	}

	/**
	 * Raises the amount to the smallest whole-unit value that ends in `$ending_major` modulo `$modulus_major`.
	 *
	 * `round_up_to_ending( 100, 99 )` turns 1234.56 into 1299.00 and leaves 1199.00 alone.
	 *
	 * @since 2.0.29
	 *
	 * @param int $modulus_major Cycle length in major units, at least 2.
	 * @param int $ending_major  Wanted remainder in major units, below the modulus.
	 * @return self
	 * @throws \InvalidArgumentException When the modulus or ending is out of range.
	 */
	public function round_up_to_ending( int $modulus_major, int $ending_major ): self {
		if ( $modulus_major < 2 || $ending_major < 0 || $ending_major >= $modulus_major ) {
			throw new \InvalidArgumentException( 'Ending must be a remainder of a modulus of at least two units.' );
		}

		$unit    = self::unit_of( $this->currency );
		$modulus = $modulus_major * $unit;
		$ending  = $ending_major * $unit;
		$gap     = $this->minor - $ending;
		$cycles  = $gap > 0 ? intdiv( $gap + $modulus - 1, $modulus ) : intdiv( $gap, $modulus );

		return new self( $ending + $cycles * $modulus, $this->currency );
	}

	/**
	 * Compares two amounts of the same currency.
	 *
	 * @since 2.0.29
	 *
	 * @param Money $other Amount to compare with.
	 * @return int -1, 0 or 1.
	 * @throws \InvalidArgumentException On a currency mismatch.
	 */
	public function compare( Money $other ): int {
		$this->assert_same_currency( $other );

		return $this->minor <=> $other->minor;
	}

	/**
	 * Tells whether both amounts are equal in value and currency.
	 *
	 * @since 2.0.29
	 *
	 * @param Money $other Amount to compare with.
	 * @return bool
	 */
	public function equals( Money $other ): bool {
		return $this->minor === $other->minor && $this->currency === $other->currency;
	}

	/**
	 * Tells whether the amount is above zero.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_positive(): bool {
		return $this->minor > 0;
	}

	/**
	 * Tells whether the amount is exactly zero.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	/**
	 * Parses a percentage into an integer ratio.
	 *
	 * @since 2.0.29
	 *
	 * @param string $percent Decimal percentage.
	 * @return int[] Numerator and denominator of `percent / 100`.
	 * @throws \InvalidArgumentException When the percentage is malformed or has too many decimals.
	 */
	public static function percent_ratio( string $percent ): array {
		$pattern = '/^\s*(-?)(\d{1,6})(?:\.(\d{1,' . self::MAX_PERCENT_DECIMALS . '}))?\s*$/';
		if ( 1 !== preg_match( $pattern, $percent, $parts ) ) {
			throw new \InvalidArgumentException( 'Percentage is not a plain decimal number.' );
		}

		$fraction    = $parts[3] ?? '';
		$numerator   = (int) ( $parts[2] . $fraction );
		$denominator = 100 * (int) str_pad( '1', strlen( $fraction ) + 1, '0' );

		return [ '-' === $parts[1] ? -$numerator : $numerator, $denominator ];
	}

	/**
	 * Throws when the other amount is in another currency.
	 *
	 * @param Money $other Other amount.
	 * @return void
	 * @throws \InvalidArgumentException On a currency mismatch.
	 */
	private function assert_same_currency( Money $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException( 'Money currencies differ.' );
		}
	}

	/**
	 * Number of decimal places of a currency.
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	private static function exponent_of( string $currency ): int {
		return in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;
	}

	/**
	 * Minor units in one major unit.
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	private static function unit_of( string $currency ): int {
		return 0 === self::exponent_of( $currency ) ? 1 : 100;
	}
}
