<?php
/**
 * Pure price policy engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a WooCommerce price into the marketplace price triple and back. No WordPress, no I/O, no float.
 *
 * Order of work: base choice -> markup -> rounding. The old price is the regular price pushed through the same markup
 * and rounding, and only while the product is on sale and the result stays above the selling price.
 *
 * @since 2.0.29
 */
final class Price_Calculator {
	/**
	 * Calculates the price triple for a product.
	 *
	 * @since 2.0.29
	 *
	 * @param Money      $base    WooCommerce active price (the sale price while a sale runs); must be above zero.
	 * @param Money|null $regular WooCommerce regular price, or null when unknown.
	 * @param Price_Rule $rule    Pricing policy.
	 * @return Price_Pair
	 * @throws \InvalidArgumentException When the base price is not above zero or the currencies differ.
	 */
	public static function calculate( Money $base, ?Money $regular, Price_Rule $rule ): Price_Pair {
		if ( ! $base->is_positive() ) {
			throw new \InvalidArgumentException( 'A product without a price cannot be priced.' );
		}

		$use_regular = Price_Rule::BASE_REGULAR === $rule->base() && null !== $regular;
		$price       = self::apply( $use_regular ? $regular : $base, $rule );

		return new Price_Pair( $price, self::old_price( $price, $base, $regular, $rule ), self::min_price( $price, $rule ) );
	}

	/**
	 * Recovers the WooCommerce price from a marketplace price by inverting the rule.
	 *
	 * A percent markup is removed by division (120 with +20 % gives 100, never 120 - 24); a fixed markup by subtraction.
	 * Rounding cannot be undone, so the result is the nearest minor unit, and a fixed markup larger than the remote
	 * price gives zero. The result is on the rule's base (regular price for `BASE_REGULAR`).
	 *
	 * @since 2.0.29
	 *
	 * @param Money      $remote Price read from the marketplace.
	 * @param Price_Rule $rule   Pricing policy that produced it.
	 * @return Money
	 */
	public static function invert( Money $remote, Price_Rule $rule ): Money {
		if ( Price_Rule::MARKUP_FIXED === $rule->markup_kind() ) {
			$restored = $remote->sub( Money::from_string( $rule->markup(), $remote->currency() ) );

			return $restored->is_positive() ? $restored : Money::zero( $remote->currency() );
		}

		if ( Price_Rule::MARKUP_PERCENT === $rule->markup_kind() ) {
			list( $numerator, $denominator ) = Money::percent_ratio( $rule->markup() );

			return $remote->mul_ratio( $denominator, $denominator + $numerator );
		}

		return $remote;
	}

	/**
	 * Applies markup and rounding to one price.
	 *
	 * @param Money      $price Starting price.
	 * @param Price_Rule $rule  Policy.
	 * @return Money
	 */
	private static function apply( Money $price, Price_Rule $rule ): Money {
		$marked_up = self::mark_up( $price, $rule );
		$rounded   = self::round( $marked_up, $rule->rounding() );

		return $rounded->is_positive() ? $rounded : $marked_up;
	}

	/**
	 * Adds the markup.
	 *
	 * @param Money      $price Starting price.
	 * @param Price_Rule $rule  Policy.
	 * @return Money
	 */
	private static function mark_up( Money $price, Price_Rule $rule ): Money {
		if ( Price_Rule::MARKUP_FIXED === $rule->markup_kind() ) {
			return $price->add( Money::from_string( $rule->markup(), $price->currency() ) );
		}

		if ( Price_Rule::MARKUP_PERCENT === $rule->markup_kind() ) {
			// One rounding of the resulting price. Rounding the adjustment instead and adding it loses half-kopeck cases
			// for negative percentages (100.05 at -10 % is 90.045 and must be 90.05, not 90.04).
			list( $numerator, $denominator ) = Money::percent_ratio( $rule->markup() );

			return $price->mul_ratio( $denominator + $numerator, $denominator );
		}

		return $price;
	}

	/**
	 * Rounds a price by mode.
	 *
	 * @param Money  $price Price.
	 * @param string $mode  `Price_Rule::ROUND_*`.
	 * @return Money
	 */
	private static function round( Money $price, string $mode ): Money {
		switch ( $mode ) {
			case Price_Rule::ROUND_TO_1:
				return $price->round_to( 1 );
			case Price_Rule::ROUND_TO_10:
				return $price->round_to( 10 );
			case Price_Rule::ROUND_ENDS_99:
				return $price->round_up_to_ending( 100, 99 );
			default:
				return $price;
		}
	}

	/**
	 * Works out the old price: the marked-up regular price while on sale, only when it stays above the selling price.
	 *
	 * @param Money      $price   Calculated selling price.
	 * @param Money      $base    Active price.
	 * @param Money|null $regular Regular price.
	 * @param Price_Rule $rule    Policy.
	 * @return Money|null
	 */
	private static function old_price( Money $price, Money $base, ?Money $regular, Price_Rule $rule ): ?Money {
		$on_sale = Price_Rule::BASE_ACTIVE === $rule->base() && null !== $regular && $regular->compare( $base ) > 0;
		if ( Price_Rule::OLD_PRICE_ON_SALE !== $rule->old_price_rule() || ! $on_sale ) {
			return null;
		}

		$old_price = self::apply( $regular, $rule );

		return $old_price->compare( $price ) > 0 ? $old_price : null;
	}

	/**
	 * Works out the minimum price as a percentage of the selling price.
	 *
	 * @param Money      $price Calculated selling price.
	 * @param Price_Rule $rule  Policy.
	 * @return Money|null
	 */
	private static function min_price( Money $price, Price_Rule $rule ): ?Money {
		return null === $rule->min_percent() ? null : $price->mul_percent( $rule->min_percent() );
	}
}
