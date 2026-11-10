<?php
/**
 * One stock quantity to push.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * An absolute quantity for one link. Both Ozon and Wildberries set stock, they do not add to it.
 *
 * @since 2.0.29
 */
final class Stock_Line {
	/**
	 * Link.
	 *
	 * @var Product_Link
	 */
	private $link;

	/**
	 * Quantity to set.
	 *
	 * @var int
	 */
	private $quantity;

	/**
	 * Creates a line.
	 *
	 * @since 2.0.29
	 *
	 * @param Product_Link $link     Link.
	 * @param int          $quantity Absolute quantity, not negative.
	 * @throws \InvalidArgumentException When the quantity is negative.
	 */
	public function __construct( Product_Link $link, int $quantity ) {
		if ( $quantity < 0 ) {
			throw new \InvalidArgumentException( 'Stock quantity must not be negative.' );
		}

		$this->link     = $link;
		$this->quantity = $quantity;
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
	 * Returns the quantity to set.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function quantity(): int {
		return $this->quantity;
	}
}
