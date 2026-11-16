<?php
/**
 * The export side's price answers with the production price policy, over product doubles.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Export_Price_Policy;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Sync\Price_Context;

/**
 * Where {@see Fake_Price_Context} is the identity rule, this one runs the real {@see Export_Price_Policy} with the rule the test
 * gives (by default the production rounding `to_10`), so a price the rule rounds really goes out different from the shop's, and
 * cannot be turned back. The product doubles answer `get_price()` and `get_regular_price()`.
 */
final class Policy_Price_Context implements Price_Context {
	/**
	 * Products by id.
	 *
	 * @var array<int,\WC_Product>
	 */
	public $products = [];

	/**
	 * Unused: the price is worked out from the product. Declared so that a test written for the identity double can set it.
	 *
	 * @var array<int,mixed>
	 */
	public $pairs = [];

	/**
	 * The rule in force.
	 *
	 * @var Price_Rule
	 */
	public $rule;

	/**
	 * Creates the context.
	 *
	 * @param string $rounding `Price_Rule::ROUND_*`.
	 */
	public function __construct( string $rounding = Price_Rule::ROUND_TO_10 ) {
		$this->rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', $rounding );
	}

	/**
	 * @param int $product_id Product id.
	 * @return \WC_Product|null
	 */
	public function product( int $product_id ): ?\WC_Product {
		return $this->products[ $product_id ] ?? null;
	}

	/**
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Link_Record $link    Link.
	 * @return Price_Pair|null
	 */
	public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair {
		if ( '' === (string) $product->get_price() ) {
			return null;
		}

		return Export_Price_Policy::pair( $product, $this->rule, $link->price_rule_override(), Price_Currency::none( 'RUB' ) );
	}

	/**
	 * @param Price_Pair $pair Prices.
	 * @return array<string,string|null>
	 */
	public function payload( Price_Pair $pair ): array {
		return Export_Price_Policy::payload( $pair );
	}

	/**
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return array{rule:Price_Rule,currency:Price_Currency}
	 */
	public function price_terms( Account $account, \WC_Product $product ): array {
		return [
			'rule'     => $this->rule,
			'currency' => Price_Currency::none( 'RUB' ),
		];
	}

	/**
	 * @param Money                    $price     Selling price.
	 * @param Money|null               $old_price Crossed-out price.
	 * @param Price_Rule               $rule      Rule.
	 * @param array<string,mixed>|null $override  Override.
	 * @param Price_Currency           $currency  Currency rule.
	 * @return array{regular:Money|null,sale:Money|null}|null
	 */
	public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
		return Export_Price_Policy::invert( $price, $old_price, $rule, $override, $currency );
	}
}
