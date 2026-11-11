<?php
/**
 * The price a product should carry on the marketplace, and back.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * What the price sync asks the export side: a product's mapping, profile, price rule and currency rule decide its marketplace
 * price, and the export flow is where they are resolved. The price intents and the refresher depend on this contract only, so
 * they do not know how a product finds its profile.
 *
 * @since 2.0.30
 */
interface Price_Context {
	/**
	 * Loads a product or a variation.
	 *
	 * @since 2.0.30
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Product|null Null when the product no longer exists.
	 */
	public function product( int $product_id ): ?\WC_Product;

	/**
	 * Works out the prices a card should carry on the marketplace.
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @param Link_Record $link    Its offer row (the price override lives there).
	 * @return Price_Pair|null Null when the product has no price.
	 * @throws \InvalidArgumentException When the rule, the rate or the override cannot be read.
	 */
	public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair;

	/**
	 * Returns the `sync_state` payload of a pair: `{price, old_price, min_price, currency_code}`, the shape
	 * {@see \Woodev\Framework\Marketplace\Price\Price_Intent::pair()} reads back.
	 *
	 * @since 2.0.30
	 *
	 * @param Price_Pair $pair Prices.
	 * @return array<string,string|null>
	 */
	public function payload( Price_Pair $pair ): array;

	/**
	 * Returns the price rule and the currency rule in force for a product.
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @return array{rule:Price_Rule,currency:Price_Currency}
	 * @throws \InvalidArgumentException When the rule or the rate cannot be read.
	 */
	public function price_terms( Account $account, \WC_Product $product ): array;

	/**
	 * Undoes the rule on a marketplace price: what the shop's regular and sale price must be for the rule to give these prices.
	 *
	 * @since 2.0.30
	 *
	 * @param Money                    $price     The marketplace's selling price.
	 * @param Money|null               $old_price The marketplace's crossed-out price, if any.
	 * @param Price_Rule               $rule      Price rule.
	 * @param array<string,mixed>|null $override  The link's price override.
	 * @param Price_Currency           $currency  Currency rule.
	 * @return array{regular:Money|null,sale:Money|null}|null Null when the price is set apart from the shop's (an absolute
	 *                                                        override, a price taken from a product field): nothing to restore.
	 * @throws \InvalidArgumentException When the override cannot be read.
	 */
	public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array;
}
