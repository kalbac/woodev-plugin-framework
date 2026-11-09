<?php
/**
 * A marketplace's own way to name the base price of a product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The profile's `currency_rule` has three neutral shapes the layer understands by itself: empty, `fixed:CODE` and `rate:N`. A
 * marketplace may add a shape of its own, for example `field:KEY`, «the product field KEY holds the price on this marketplace».
 * The adapter supplies a `Price_Source` for those shapes; without one such a rule is reported as unsupported and never priced.
 *
 * The source answers three questions about a stored rule: is the shape yours, can this product be priced by it, and what is the
 * price. The layer asks them in that order, so {@see self::price()} may assume {@see self::problem()} returned null.
 *
 * @since 2.0.29
 */
interface Price_Source {
	/**
	 * Returns the merchant-facing name of this choice in the profile, already translated by the adapter.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Tells whether a stored currency rule has this source's shape. The check is about the form only, not about the product.
	 *
	 * @since 2.0.29
	 *
	 * @param string $rule Trimmed `currency_rule`.
	 * @return bool
	 */
	public function supports( string $rule ): bool;

	/**
	 * Tells why the product cannot be priced by the rule.
	 *
	 * @since 2.0.29
	 *
	 * @param string           $rule           Trimmed `currency_rule` this source supports.
	 * @param \WC_Product      $product        Product or variation.
	 * @param \WC_Product|null $parent_product Parent of a variation.
	 * @return string|null Null when the product has a usable price; otherwise the merchant sentence, already translated.
	 */
	public function problem( string $rule, \WC_Product $product, ?\WC_Product $parent_product ): ?string;

	/**
	 * Reads the base price of the product, in the cabinet currency. It replaces the shop price the price rule works on.
	 *
	 * @since 2.0.29
	 *
	 * @param string           $rule           Trimmed `currency_rule` this source supports.
	 * @param string           $currency       Cabinet currency.
	 * @param \WC_Product      $product        Product or variation.
	 * @param \WC_Product|null $parent_product Parent of a variation.
	 * @return Money
	 * @throws \InvalidArgumentException When the product holds no usable price.
	 */
	public function price( string $rule, string $currency, \WC_Product $product, ?\WC_Product $parent_product ): Money;
}
