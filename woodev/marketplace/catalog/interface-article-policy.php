<?php
/**
 * A marketplace's rules for the seller's article.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * What a marketplace accepts as the seller's article (its `offer_id`, `vendorCode`, ...): how long it may be, how a WooCommerce SKU
 * is turned into one, and what a product without a SKU gets. The adapter supplies the policy; {@see Offer_Id} applies it, so the
 * card builder and the validator always work out the same article.
 *
 * @since 2.0.29
 */
interface Article_Policy {
	/**
	 * Returns the longest article the marketplace accepts.
	 *
	 * @since 2.0.29
	 *
	 * @return int Characters.
	 */
	public function max_length(): int;

	/**
	 * Turns a WooCommerce SKU into an article: the marketplace's own cleaning (for example spaces become dashes) and the length cap.
	 *
	 * @since 2.0.29
	 *
	 * @param string $sku WooCommerce SKU.
	 * @return string Empty when nothing is left.
	 */
	public function normalise( string $sku ): string;

	/**
	 * Returns the article of a product that has no usable SKU.
	 *
	 * @since 2.0.29
	 *
	 * @param int $product_id WooCommerce product or variation id.
	 * @return string
	 */
	public function fallback( int $product_id ): string;
}
