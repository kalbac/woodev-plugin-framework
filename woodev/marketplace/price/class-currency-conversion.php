<?php
/**
 * Converts a shop price into the marketplace cabinet's currency.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The arithmetic of the profile's `currency_rule` shapes `rate:N` (multiply by N) and the empty / `fixed:CODE` rule (send as it is,
 * in the cabinet currency; v1 sent its prices unconverted). A shape of the marketplace's own, such as `field:KEY`, is not a rate: its
 * {@see Price_Source} supplies a price ({@see Price_Currency}, which reads every shape for one product).
 *
 * All arithmetic is integer (`Money::mul_ratio()`); no float.
 *
 * @since 2.0.29
 */
final class Currency_Conversion {
	/**
	 * Most decimals a rate may have.
	 */
	public const MAX_RATE_DECIMALS = 6;

	/**
	 * Reads the rate of a `rate:N` rule.
	 *
	 * @since 2.0.29
	 *
	 * @param string $rule Stored currency rule.
	 * @return array{0:int,1:int}|null Numerator and denominator, or null for every other rule («no conversion»; a marketplace
	 *                                 shape carries a price, not a rate, see {@see Price_Currency}).
	 * @throws \InvalidArgumentException When a rate rule gives no number above zero.
	 */
	public static function rate( string $rule ): ?array {
		$rule = trim( $rule );

		return 0 === strpos( $rule, 'rate:' ) ? self::ratio( substr( $rule, 5 ) ) : null;
	}

	/**
	 * Converts an amount and re-expresses it in the target currency.
	 *
	 * @since 2.0.29
	 *
	 * @param Money                   $amount Amount in the shop currency.
	 * @param array{0:int,1:int}|null $rate   Rate from {@see self::rate()}, or null.
	 * @param string                  $target Cabinet currency.
	 * @return Money
	 */
	public static function convert( Money $amount, ?array $rate, string $target ): Money {
		$converted = null === $rate ? $amount : $amount->mul_ratio( $rate[0], $rate[1] );

		if ( $converted->currency() === $target ) {
			return $converted;
		}

		$shift = self::decimals( $target ) - self::decimals( $converted->currency() );

		if ( $shift >= 0 ) {
			return Money::of( $converted->minor() * (int) ( 10 ** $shift ), $target );
		}

		return Money::of( $converted->mul_ratio( 1, (int) ( 10 ** -$shift ) )->minor(), $target );
	}

	/**
	 * Parses a decimal rate into a ratio.
	 *
	 * @param string $rate Rate such as `0.08` or `12.5`.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException When it is not a decimal number above zero.
	 */
	private static function ratio( string $rate ): array {
		if ( 1 !== preg_match( '/^\s*(\d{1,9})(?:\.(\d{1,' . self::MAX_RATE_DECIMALS . '}))?\s*$/', $rate, $parts ) ) {
			throw new \InvalidArgumentException( 'The currency rate is not a decimal number.' );
		}

		$fraction  = $parts[2] ?? '';
		$numerator = (int) ( $parts[1] . $fraction );

		if ( $numerator <= 0 ) {
			throw new \InvalidArgumentException( 'The currency rate must be above zero.' );
		}

		return [ $numerator, (int) ( 10 ** strlen( $fraction ) ) ];
	}

	/**
	 * Returns the number of decimals of a currency.
	 *
	 * @param string $currency ISO code.
	 * @return int
	 */
	private static function decimals( string $currency ): int {
		$zero = Money::zero( $currency )->to_string();
		$dot  = strpos( $zero, '.' );

		return false === $dot ? 0 : strlen( $zero ) - $dot - 1;
	}
}
