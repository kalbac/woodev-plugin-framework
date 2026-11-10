<?php
/**
 * One price to push.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * The calculated price triple for one link. The adapter maps it to its own payload (Ozon: `price`, `old_price`,
 * `min_price`; Wildberries: `price` plus a whole-percent `discount`).
 *
 * @since 2.0.29
 */
final class Price_Line {
	/**
	 * Link.
	 *
	 * @var Product_Link
	 */
	private $link;

	/**
	 * Calculated prices.
	 *
	 * @var Price_Pair
	 */
	private $pair;

	/**
	 * Creates a line.
	 *
	 * @since 2.0.29
	 *
	 * @param Product_Link $link Link.
	 * @param Price_Pair   $pair Calculated prices.
	 */
	public function __construct( Product_Link $link, Price_Pair $pair ) {
		$this->link = $link;
		$this->pair = $pair;
	}

	/**
	 * Returns the link.
	 *
	 * @since 2.0.29
	 *
	 * @return Product_Link
	 */
	public function link(): Product_Link {
		return $this->link;
	}

	/**
	 * Returns the calculated prices.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Pair
	 */
	public function pair(): Price_Pair {
		return $this->pair;
	}
}
