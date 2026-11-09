<?php
/**
 * Order line value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One product line of a remote order. A line with an empty `seller_sku` or unknown remote ids is a product the shop has
 * not linked; the writer keeps it with the marketplace's name and adds an order note.
 *
 * @since 2.0.29
 */
final class Order_Line {
	/**
	 * Seller article.
	 *
	 * @var string
	 */
	private $seller_sku;

	/**
	 * Marketplace identifiers of the item.
	 *
	 * @var array<string,int|string>
	 */
	private $remote_ids;

	/**
	 * Units ordered.
	 *
	 * @var int
	 */
	private $quantity;

	/**
	 * Price of one unit.
	 *
	 * @var Money
	 */
	private $unit_price;

	/**
	 * Marketplace's product name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Creates a line.
	 *
	 * @since 2.0.29
	 *
	 * @param string                   $seller_sku Seller article.
	 * @param array<string,int|string> $remote_ids Marketplace identifiers.
	 * @param int                      $quantity   Units, not negative.
	 * @param Money                    $unit_price Price of one unit.
	 * @param string                   $name       Marketplace's product name.
	 * @throws \InvalidArgumentException When the quantity is negative.
	 */
	public function __construct( string $seller_sku, array $remote_ids, int $quantity, Money $unit_price, string $name = '' ) {
		if ( $quantity < 0 ) {
			throw new \InvalidArgumentException( 'Order line quantity must not be negative.' );
		}

		$this->seller_sku = $seller_sku;
		$this->remote_ids = $remote_ids;
		$this->quantity   = $quantity;
		$this->unit_price = $unit_price;
		$this->name       = $name;
	}

	/**
	 * Returns a copy with another quantity (a cancelled member's line is zeroed this way).
	 *
	 * @since 2.0.29
	 *
	 * @param int $quantity New quantity.
	 * @return self
	 */
	public function with_quantity( int $quantity ): self {
		return new self( $this->seller_sku, $this->remote_ids, $quantity, $this->unit_price, $this->name );
	}

	/**
	 * Returns the line total: unit price times quantity, exact.
	 *
	 * @since 2.0.29
	 *
	 * @return Money
	 */
	public function line_total(): Money {
		return $this->unit_price->mul( $this->quantity );
	}

	/**
	 * Returns the seller article.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function seller_sku(): string {
		return $this->seller_sku;
	}

	/**
	 * Returns the marketplace identifiers.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,int|string>
	 */
	public function remote_ids(): array {
		return $this->remote_ids;
	}

	/**
	 * Returns the units ordered.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function quantity(): int {
		return $this->quantity;
	}

	/**
	 * Returns the price of one unit.
	 *
	 * @since 2.0.29
	 *
	 * @return Money
	 */
	public function unit_price(): Money {
		return $this->unit_price;
	}

	/**
	 * Returns the marketplace's product name.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}
}
