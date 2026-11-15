<?php
/**
 * Order lines made while an imported product was simple, after the product became variable.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * {@see Group_Assembler} turns a simple product into a variable one in place: the product keeps its id, and what it sold —
 * its SKU, its price, its stock — is from then on its first variation. An order line made before that names the product and
 * **no variation**. WooCommerce resolves such a line to the variable product, which manages no stock, so paying, cancelling or
 * refunding the old order would no longer reduce or restore anything.
 *
 * This resolves those lines to the first variation, through WooCommerce's own filter of an order line's product
 * (`woocommerce_order_item_product`, the one `wc_reduce_stock_levels()`, the stock restore and the refund restock read through
 * `WC_Order_Item_Product::get_product()`). Only a line with no variation on a product that carries
 * {@see Group_Assembler::first_variation_key()} is touched: a variable product cannot be bought without a variation, so such
 * a line can only have been made while the product was simple. Nothing is written to any order.
 *
 * @since 2.1.6
 */
final class Legacy_Order_Lines {
	/**
	 * The adapter's id.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Creates the resolver.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marketplace_id The adapter's id.
	 */
	public function __construct( string $marketplace_id ) {
		$this->marketplace_id = $marketplace_id;
	}

	/**
	 * Hooks the resolver.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_order_item_product', [ $this, 'resolve' ], 10, 2 );
	}

	/**
	 * Handles `woocommerce_order_item_product`.
	 *
	 * @since 2.1.6
	 *
	 * @param mixed $product The product WooCommerce resolved the line to, false when it is gone.
	 * @param mixed $item    The order line.
	 * @return mixed The first variation of a converted product for a line that names no variation; `$product` otherwise.
	 */
	public function resolve( $product, $item ) {
		if ( ! $product instanceof \WC_Product || ! $item instanceof \WC_Order_Item_Product || $item->get_variation_id() > 0 || ! $product->is_type( 'variable' ) ) {
			return $product;
		}

		$variation_id = (int) $product->get_meta( Group_Assembler::first_variation_key( $this->marketplace_id ) );
		$variation    = $variation_id > 0 ? wc_get_product( $variation_id ) : null;

		return $variation instanceof \WC_Product && (int) $variation->get_parent_id() === (int) $product->get_id() ? $variation : $product;
	}
}
