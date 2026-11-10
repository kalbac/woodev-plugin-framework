<?php
/**
 * Currency terms of one product's price.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * How a shop price becomes a cabinet price, read from the profile's `currency_rule` for one product:
 *
 * - empty or `fixed:CODE`: the number is sent as it is, in the cabinet currency;
 * - `rate:N`: the shop price is **multiplied** by N, the cabinet units one shop unit is worth (RUB shop, CNY cabinet: `rate:0.0125`);
 * - any other shape belongs to the marketplace and is read by its {@see Price_Source} (for example `field:KEY`: a product field
 *   that holds the price on that marketplace). The source's price replaces the shop price as the base the price rule works on; such
 *   a card has no regular price, so no old price, unless the link's override sets one. Without a source for the shape the rule is
 *   unsupported and nothing is priced.
 *
 * The price rule (markup, rounding, minimum) works on the **converted** price, in the cabinet currency, so «до 99» and a fixed
 * markup mean what the marketplace shows. All arithmetic is integer ({@see Currency_Conversion}, {@see Money}).
 *
 * @since 2.0.29
 */
final class Price_Currency {
	/**
	 * Shop currency.
	 *
	 * @var string
	 */
	private $shop;

	/**
	 * Cabinet currency.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Rate as numerator and denominator, null = none.
	 *
	 * @var array{0:int,1:int}|null
	 */
	private $rate;

	/**
	 * Base price the marketplace's {@see Price_Source} read from the product, in the cabinet currency.
	 *
	 * @var Money|null
	 */
	private $field_price;

	/**
	 * Whether the profile named a currency rule at all.
	 *
	 * @var bool
	 */
	private $explicit;

	/**
	 * Creates the terms.
	 *
	 * @param string                  $shop        Shop currency.
	 * @param string                  $target      Cabinet currency.
	 * @param array{0:int,1:int}|null $rate        Rate.
	 * @param Money|null              $field_price Base price read from the product by a source.
	 * @param bool                    $explicit    Whether the profile named a currency rule.
	 */
	private function __construct( string $shop, string $target, ?array $rate, ?Money $field_price, bool $explicit ) {
		$this->shop        = $shop;
		$this->target      = $target;
		$this->rate        = $rate;
		$this->field_price = $field_price;
		$this->explicit    = $explicit;
	}

	/**
	 * Terms with no conversion: the shop and the cabinet share a currency.
	 *
	 * @since 2.0.29
	 *
	 * @param string $currency Currency code.
	 * @return self
	 */
	public static function none( string $currency ): self {
		return new self( $currency, $currency, null, null, true );
	}

	/**
	 * Reads the terms a profile's currency rule gives a product.
	 *
	 * @since 2.0.29
	 *
	 * @param string            $shop           Shop currency.
	 * @param string            $target         Cabinet currency; '' = the shop currency.
	 * @param string            $rule           Stored `currency_rule`.
	 * @param \WC_Product       $product        Product or variation.
	 * @param \WC_Product|null  $parent_product Parent of a variation.
	 * @param Price_Source|null $source        The marketplace's own price source; null = the marketplace has none.
	 * @return self
	 * @throws \InvalidArgumentException When a `rate:` is not a number above zero, the source holds no price for this product, or the rule
	 *                                   is a shape of a source the marketplace did not supply.
	 */
	public static function for_product( string $shop, string $target, string $rule, \WC_Product $product, ?\WC_Product $parent_product = null, ?Price_Source $source = null ): self {
		$target = '' === $target ? $shop : $target;
		$rule   = trim( $rule );

		if ( '' !== $rule && null !== $source && $source->supports( $rule ) ) {
			return new self( $shop, $target, null, $source->price( $rule, $target, $product, $parent_product ), true );
		}

		if ( ! self::is_neutral_shape( $rule ) ) {
			throw new \InvalidArgumentException( 'The currency rule needs a price source the marketplace did not supply.' );
		}

		return new self( $shop, $target, Currency_Conversion::rate( $rule ), null, '' !== $rule );
	}

	/**
	 * Tells whether a rule is one of the layer's own shapes: empty, `fixed:...` or `rate:...`. Every other rule, whatever it looks
	 * like, belongs to the marketplace and only a {@see Price_Source} that supports it may read it.
	 *
	 * @since 2.0.29
	 *
	 * @param string $rule Trimmed rule.
	 * @return bool
	 */
	public static function is_neutral_shape( string $rule ): bool {
		return '' === $rule || 0 === strpos( $rule, 'fixed:' ) || 0 === strpos( $rule, 'rate:' );
	}

	/**
	 * Returns the shop currency.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function shop(): string {
		return $this->shop;
	}

	/**
	 * Returns the cabinet currency.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * Returns the base price a {@see Price_Source} read from the product, null when the rule is not a source rule.
	 *
	 * @since 2.0.29
	 *
	 * @return Money|null
	 */
	public function field_price(): ?Money {
		return $this->field_price;
	}

	/**
	 * Tells whether a cabinet price cannot be turned back into a shop price: it came from a price source, not from the shop price.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_one_way(): bool {
		return null !== $this->field_price;
	}

	/**
	 * Tells whether the shop and the cabinet differ in currency while the profile says nothing about it. The number would go out in
	 * the wrong currency (1 000 roubles as 1 000 yuan); the validator refuses to export such a card, and nothing may push its price.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_unresolved(): bool {
		return ! $this->explicit && $this->shop !== $this->target;
	}

	/**
	 * Expresses a shop price in the cabinet currency.
	 *
	 * @since 2.0.29
	 *
	 * @param string $amount Shop price.
	 * @return Money
	 * @throws \InvalidArgumentException When the amount is malformed.
	 */
	public function from_shop( string $amount ): Money {
		return Currency_Conversion::convert( Money::from_string( $amount, $this->shop ), $this->rate, $this->target );
	}

	/**
	 * Expresses a cabinet price in the shop currency: the rate divides.
	 *
	 * @since 2.0.29
	 *
	 * @param Money $remote Price in the cabinet currency.
	 * @return Money
	 */
	public function to_shop( Money $remote ): Money {
		return Currency_Conversion::convert( $remote, null === $this->rate ? null : [ $this->rate[1], $this->rate[0] ], $this->shop );
	}
}
