<?php
/**
 * The export side's price answers, as a double.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Sync\Price_Context;

/**
 * Stands in for the export flow, which resolves a product's profile and rule. The test says what each product should cost on
 * the marketplace ({@see self::$pairs}); the way back is the simplest rule there is — the marketplace's price is the shop's
 * price, its old price the shop's regular price — so a test of the refresher reads its expectations off the numbers it gave.
 *
 * A link override of `[ 'absolute' => … ]` cannot be undone (null), one of `[ 'broken' => … ]` cannot be read (it throws).
 */
final class Fake_Price_Context implements Price_Context {
	/**
	 * Products by id.
	 *
	 * @var array<int,\WC_Product>
	 */
	public $products = [];

	/**
	 * What each product should cost: product id => pair, null (no price) or an exception to throw.
	 *
	 * @var array<int,Price_Pair|\InvalidArgumentException|null>
	 */
	public $pairs = [];

	/**
	 * The currency rule in force; `none( RUB )` when null.
	 *
	 * @var Price_Currency|null
	 */
	public $currency;

	/**
	 * Thrown by {@see self::price_terms()} when set.
	 *
	 * @var \InvalidArgumentException|null
	 */
	public $terms_failure;

	/**
	 * Loads a product.
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Product|null
	 */
	public function product( int $product_id ): ?\WC_Product {
		return $this->products[ $product_id ] ?? null;
	}

	/**
	 * Returns the wanted prices.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Link_Record $link    Link.
	 * @return Price_Pair|null
	 * @throws \InvalidArgumentException The scripted failure.
	 */
	public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair {
		$pair = $this->pairs[ $product->get_id() ] ?? null;

		if ( $pair instanceof \InvalidArgumentException ) {
			throw $pair;
		}

		return $pair;
	}

	/**
	 * Returns the payload of a pair.
	 *
	 * @param Price_Pair $pair Prices.
	 * @return array<string,string|null>
	 */
	public function payload( Price_Pair $pair ): array {
		return [
			'price'         => $pair->price()->to_string(),
			'old_price'     => null === $pair->old_price() ? '0' : $pair->old_price()->to_string(),
			'min_price'     => null === $pair->min_price() ? null : $pair->min_price()->to_string(),
			'currency_code' => $pair->price()->currency(),
		];
	}

	/**
	 * Returns the terms in force.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return array{rule:Price_Rule,currency:Price_Currency}
	 * @throws \InvalidArgumentException The scripted failure.
	 */
	public function price_terms( Account $account, \WC_Product $product ): array {
		if ( null !== $this->terms_failure ) {
			throw $this->terms_failure;
		}

		return [
			'rule'     => Price_Rule::from_array( [] ),
			'currency' => $this->currency ?? Price_Currency::none( 'RUB' ),
		];
	}

	/**
	 * Undoes the (identity) rule.
	 *
	 * @param Money                    $price     Selling price.
	 * @param Money|null               $old_price Crossed-out price.
	 * @param Price_Rule               $rule      Rule.
	 * @param array<string,mixed>|null $override  Override.
	 * @param Price_Currency           $currency  Currency rule.
	 * @return array{regular:Money|null,sale:Money|null}|null
	 * @throws \InvalidArgumentException When the override is `broken`.
	 */
	public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
		if ( isset( $override['broken'] ) ) {
			throw new \InvalidArgumentException( 'the override is not a rule' );
		}

		if ( isset( $override['absolute'] ) ) {
			return null;
		}

		$on_sale = null !== $old_price && $old_price->compare( $price ) > 0;

		return [
			'regular' => $on_sale ? $old_price : $price,
			'sale'    => $on_sale ? $price : null,
		];
	}
}
