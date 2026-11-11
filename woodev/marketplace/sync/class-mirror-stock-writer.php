<?php
/**
 * The `marketplace` mode write path into WooCommerce stock.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Stock\Stock_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * `marketplace` mode: WC stock = `max(0, present − reserved)` of the account's warehouse, written through
 * `wc_update_product_stock( $product, $qty, 'set' )`. That WooCommerce function saves the product that holds the stock (the
 * variation, or the variable parent that manages it), so its stock status, caches and lookup row are recalculated at once, and
 * WooCommerce's deferred sync recalculates a variable parent when the request ends — a direct meta write leaves all of them
 * stale.
 *
 * The callers are the handler of the marketplace's stock notification and the readback of {@see Stock_Readback}. The write
 * fires WooCommerce's stock hooks; {@see Stock_Intents} ignores them in `marketplace` mode, so the mirror never echoes back.
 *
 * @since 2.1.0
 */
final class Mirror_Stock_Writer {
	/**
	 * Sets a product's stock to what the marketplace can still sell.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param int         $present  Units on the marketplace warehouse.
	 * @param int         $reserved Units reserved by open orders.
	 * @return int|null The stock now, or null when the product does not manage stock (nothing is written).
	 */
	public function mirror( \WC_Product $product, int $present, int $reserved ): ?int {
		if ( ! $product->managing_stock() ) {
			return null;
		}

		$quantity = Stock_Policy::mirrored_quantity( $present, $reserved );

		if ( (int) $product->get_stock_quantity() === $quantity ) {
			return $quantity;
		}

		$written = wc_update_product_stock( $product, $quantity, 'set' );

		return is_numeric( $written ) ? (int) $written : null;
	}
}
