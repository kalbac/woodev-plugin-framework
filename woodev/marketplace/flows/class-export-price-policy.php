<?php
/**
 * The effective price of an exported card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Override;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * One reading of «what price does this card carry» for the validator, the card builder, the creation-time price intent and every
 * later price intent:
 *
 * 1. The rule: the profile's `price_rule`, else the account's. Two stored shapes exist and both are read: the settings / profile
 *    screens store `Price_Rule::from_array()` keys (`markup_kind`, scalar `markup`, …); a plugin's own migration may store the profile's
 *    earlier «increase» as `markup: { kind: fixed|percent, value }`. A rule that is still unreadable is an error,
 *    never «no markup».
 * 2. The currency ({@see Price_Currency}): the shop's prices are converted into the cabinet currency **first** (the profile's
 *    `currency_rule`: `rate:N` multiplies by N; `field:KEY` replaces the shop price with the price the product holds for the marketplace), and
 *    the rule works on the converted prices. A fixed markup, the rounding («до 99») and the override amounts therefore mean what
 *    the marketplace shows; rounding before the conversion would be lost in it.
 * 3. The link's `price_rule_override` ({@see Price_Override}) replaces the rule field by field.
 *
 * {@see self::invert()} is the same path backwards, for the `from_marketplace` direction.
 *
 * @since 2.1.0
 */
final class Export_Price_Policy {
	public const KIND_FIXED   = Price_Override::KIND_FIXED;
	public const KIND_PERCENT = Price_Override::KIND_PERCENT;

	/**
	 * Resolves the stored rule of a profile or, without one, of the account.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @param Profile $profile Profile.
	 * @return Price_Rule
	 * @throws \InvalidArgumentException When the stored rule cannot be read.
	 */
	public static function rule( Account $account, Profile $profile ): Price_Rule {
		$stored = $profile->setting( 'price_rule' );
		$stored = is_array( $stored ) && [] !== $stored ? $stored : $account->setting( 'price_rule' );

		return Price_Rule::from_array( self::normalised( is_array( $stored ) ? $stored : [] ) );
	}

	/**
	 * Applies the link's override to the rule: `price.percent` replaces the markup, `price.none` removes it, `old_price.none`
	 * switches the old price off, `min_price.percent` replaces the minimum price percentage.
	 *
	 * @since 2.1.0
	 *
	 * @param Price_Rule     $rule     Rule of the profile or account.
	 * @param Price_Override $override Override of the link.
	 * @return Price_Rule
	 */
	public static function effective_rule( Price_Rule $rule, Price_Override $override ): Price_Rule {
		$price     = $override->field_of( Price_Override::FIELD_PRICE );
		$old_price = $override->field_of( Price_Override::FIELD_OLD_PRICE );
		$min_price = $override->field_of( Price_Override::FIELD_MIN_PRICE );
		$kind      = $rule->markup_kind();
		$markup    = $rule->markup();

		if ( null !== $price && Price_Override::KIND_PERCENT === $price['kind'] ) {
			$kind   = Price_Rule::MARKUP_PERCENT;
			$markup = $price['value'];
		} elseif ( null !== $price && Price_Override::KIND_NONE === $price['kind'] ) {
			$kind   = Price_Rule::MARKUP_NONE;
			$markup = '0';
		}

		return new Price_Rule(
			$rule->base(),
			$kind,
			$markup,
			$rule->rounding(),
			null !== $old_price && Price_Override::KIND_NONE === $old_price['kind'] ? Price_Rule::OLD_PRICE_OFF : $rule->old_price_rule(),
			null !== $min_price && Price_Override::KIND_PERCENT === $min_price['kind'] ? $min_price['value'] : $rule->min_percent()
		);
	}

	/**
	 * Calculates the price triple of a product, in the cabinet currency.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product              $product  Product or variation.
	 * @param Price_Rule               $rule     Rule in force.
	 * @param array<string,mixed>|null $override The link's `price_rule_override`, or null.
	 * @param Price_Currency           $currency Currency terms of the product.
	 * @return Price_Pair
	 * @throws \InvalidArgumentException When the product has no usable price or the override is malformed.
	 */
	public static function pair( \WC_Product $product, Price_Rule $rule, ?array $override, Price_Currency $currency ): Price_Pair {
		$target    = $currency->target();
		$override  = Price_Override::from_array( $override, true );
		$regular   = $currency->is_one_way() || '' === (string) $product->get_regular_price() ? null : $currency->from_shop( (string) $product->get_regular_price() );
		$active    = $currency->field_price() ?? $currency->from_shop( (string) $product->get_price() );
		$effective = self::effective_rule( $rule, $override );
		$pair      = Price_Calculator::calculate( $active, $regular, $effective );
		$sell      = self::overridden( $override->field_of( Price_Override::FIELD_PRICE ), $pair->price(), $target );
		$old       = self::old_price( $override->field_of( Price_Override::FIELD_OLD_PRICE ), $pair, $regular, $sell, $target );
		$min       = self::min_price( $override->field_of( Price_Override::FIELD_MIN_PRICE ), $effective, $sell, $target );

		return new Price_Pair( $sell, $old, $min );
	}

	/**
	 * Recovers the WooCommerce prices from the prices the marketplace holds (the `from_marketplace` direction), by the inverse of the rule: a percent
	 * markup is divided out, a fixed one subtracted, and the currency rate divides (never a subtraction of a percent).
	 *
	 * Rounding cannot be undone, so the result is the nearest minor unit. A link whose selling price is an absolute override, or
	 * whose price comes from a product field (`field:KEY`), cannot be inverted and gives null: its marketplace price was never derived from
	 * the site's.
	 *
	 * The marketplace's old price is inverted the way it was made (see {@see self::old_price()}): through the rule when the rule made it, by
	 * the old price's own percentage when the link overrides it with a percent, and not at all when the link fixes it (an absolute
	 * old price says nothing about the shop's regular price, which is then left as it is). An old price above the price is a sale:
	 * regular = old price inverted, sale = price inverted. An old price of 0 or not above the price ends the sale: regular = price
	 * inverted, sale cleared, whatever the rule's base.
	 *
	 * @since 2.1.0
	 *
	 * @param Money                    $price     Selling price on the marketplace, in the cabinet currency.
	 * @param Money|null               $old_price Old price on the marketplace; null or zero = none.
	 * @param Price_Rule               $rule      Rule in force.
	 * @param array<string,mixed>|null $override  The link's override.
	 * @param Price_Currency           $currency  Currency terms (the same ones {@see self::pair()} used).
	 * @return array{regular:Money|null,sale:Money|null}|null Prices in the shop currency; a null `regular` means «leave the shop's
	 *                                                        regular price alone».
	 * @throws \InvalidArgumentException When the override is malformed.
	 */
	public static function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
		$override = Price_Override::from_array( $override, true );

		if ( $override->has_fixed_price() || $currency->is_one_way() ) {
			return null;
		}

		$rule     = self::effective_rule( $rule, $override );
		$restored = self::restored( $price, $rule, $currency );

		if ( null === $old_price || ! $old_price->is_positive() || $old_price->compare( $price ) <= 0 ) {
			return [
				'regular' => $restored,
				'sale'    => null,
			];
		}

		return [
			'regular' => self::restored_old_price( $old_price, $override->field_of( Price_Override::FIELD_OLD_PRICE ), $rule, $currency ),
			'sale'    => $restored,
		];
	}

	/**
	 * Renders a price pair as the payload kept in `sync_state(kind = price)` and sent to the marketplace: strings, `old_price` '0'
	 * to clear it, `min_price` null when absent.
	 *
	 * @since 2.1.0
	 *
	 * @param Price_Pair $pair Pair in the cabinet currency.
	 * @return array{price:string,old_price:string,min_price:string|null,currency_code:string}
	 */
	public static function payload( Price_Pair $pair ): array {
		$old = $pair->old_price();
		$min = $pair->min_price();

		return [
			'price'         => $pair->price()->to_string(),
			'old_price'     => null === $old ? '0' : $old->to_string(),
			'min_price'     => null === $min ? null : $min->to_string(),
			'currency_code' => $pair->price()->currency(),
		];
	}

	/**
	 * Converts the migrated `markup: { kind, value }` shape into the `Price_Rule::from_array()` keys.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $stored Stored rule.
	 * @return array<string,mixed>
	 */
	public static function normalised( array $stored ): array {
		$markup = $stored['markup'] ?? null;

		if ( is_array( $markup ) ) {
			$kind                  = (string) ( $markup['kind'] ?? '' );
			$stored['markup_kind'] = self::KIND_PERCENT === $kind ? Price_Rule::MARKUP_PERCENT : ( self::KIND_FIXED === $kind ? Price_Rule::MARKUP_FIXED : Price_Rule::MARKUP_NONE );
			$stored['markup']      = (string) ( $markup['value'] ?? '0' );
		}

		return $stored;
	}

	/**
	 * Undoes the rule on one marketplace price and brings it back to the shop currency.
	 *
	 * @param Money          $remote   Price on the marketplace.
	 * @param Price_Rule     $rule     Effective rule.
	 * @param Price_Currency $currency Currency terms.
	 * @return Money
	 */
	private static function restored( Money $remote, Price_Rule $rule, Price_Currency $currency ): Money {
		return $currency->to_shop( Price_Calculator::invert( $remote, $rule ) );
	}

	/**
	 * Undoes the way the old price was made.
	 *
	 * @param Money                                $old_price Old price on the marketplace.
	 * @param array{kind:string,value:string}|null $field     The override's old-price field.
	 * @param Price_Rule                           $rule      Effective rule.
	 * @param Price_Currency                       $currency  Currency terms.
	 * @return Money|null Null for a fixed old price: the regular price cannot be recovered from it.
	 */
	private static function restored_old_price( Money $old_price, ?array $field, Price_Rule $rule, Price_Currency $currency ): ?Money {
		if ( null !== $field && self::KIND_FIXED === $field['kind'] ) {
			return null;
		}

		if ( null !== $field && self::KIND_PERCENT === $field['kind'] ) {
			list( $numerator, $denominator ) = Money::percent_ratio( $field['value'] );

			return $currency->to_shop( $old_price->mul_ratio( $denominator, $denominator + $numerator ) );
		}

		return self::restored( $old_price, $rule, $currency );
	}

	/**
	 * Returns the selling price: the override's fixed amount, else the rule's.
	 *
	 * @param array{kind:string,value:string}|null $field  Override field.
	 * @param Money                                $ruled   Price the rule gave.
	 * @param string                               $target  Cabinet currency.
	 * @return Money
	 */
	private static function overridden( ?array $field, Money $ruled, string $target ): Money {
		return null !== $field && self::KIND_FIXED === $field['kind'] ? Money::from_string( $field['value'], $target ) : $ruled;
	}

	/**
	 * Returns the old price: the override's, else the rule's.
	 *
	 * The rule's old price was derived against the rule's selling price. A fixed selling-price override replaces that price, so the
	 * derived old price is re-checked against the final one and omitted when it no longer exceeds it (the `Price_Calculator` rule).
	 * An explicit override is kept as is, so an invalid one still reaches the validator.
	 *
	 * @param array{kind:string,value:string}|null $field   Override field.
	 * @param Price_Pair                           $pair    Pair the rule gave.
	 * @param Money|null                           $regular Regular price in the cabinet currency.
	 * @param Money                                $sell    Selling price.
	 * @param string                               $target  Cabinet currency.
	 * @return Money|null
	 */
	private static function old_price( ?array $field, Price_Pair $pair, ?Money $regular, Money $sell, string $target ): ?Money {
		if ( null === $field || Price_Override::KIND_NONE === $field['kind'] ) {
			$old = $pair->old_price(); // Already null for «none»: the effective rule switched the old price off.

			return null !== $old && $old->compare( $sell ) > 0 ? $old : null;
		}

		if ( self::KIND_FIXED === $field['kind'] ) {
			return Money::from_string( $field['value'], $target );
		}

		$base = $regular ?? $sell;

		return $base->mul_ratio( ...self::grown( $field['value'] ) );
	}

	/**
	 * Returns the minimum price: the override's fixed amount, else the effective rule's percent of the selling price (a percent
	 * override already replaced the rule's percentage).
	 *
	 * @param array{kind:string,value:string}|null $field  Override field.
	 * @param Price_Rule                           $rule   Effective rule.
	 * @param Money                                $sell   Selling price.
	 * @param string                               $target Cabinet currency.
	 * @return Money|null
	 */
	private static function min_price( ?array $field, Price_Rule $rule, Money $sell, string $target ): ?Money {
		if ( null !== $field && self::KIND_FIXED === $field['kind'] ) {
			return Money::from_string( $field['value'], $target );
		}

		return null === $rule->min_percent() ? null : $sell->mul_percent( $rule->min_percent() );
	}

	/**
	 * Returns the ratio of `(100 + p) / 100`.
	 *
	 * @param string $percent Percent.
	 * @return array{0:int,1:int}
	 */
	private static function grown( string $percent ): array {
		list( $numerator, $denominator ) = Money::percent_ratio( $percent );

		return [ $denominator + $numerator, $denominator ];
	}
}
