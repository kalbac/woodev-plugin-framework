<?php
/**
 * The article a new card of a product would get.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * An **optional** part of a marketplace adapter, for a marketplace whose seller article is not simply the product's SKU: one card
 * may hold several variations, and its article may be built of the product's article and an attribute value. The mapping form then
 * shows, under a saved mapping, which article each product without a card would be sent with. An adapter that does not implement
 * it shows nothing there.
 *
 * The form asks while the merchant looks at it, for many products in one request, so the method **reads the shop and what the
 * plugin has stored: no request to the marketplace**.
 *
 * @since 2.1.27
 */
interface Article_Preview {
	/**
	 * Returns the article of the card a product would be created with.
	 *
	 * Asked about every sellable unit: a simple product, or one variation. Units that answer the same article are shown on one line.
	 * Only units without a card are asked about, and the answer is about the mapping and the profile given: what the merchant typed
	 * on one product for one account is no part of it.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param Mapping     $mapping The mapping on the screen, as saved.
	 * @param Profile     $profile Its profile; an empty one when the mapping chose none.
	 * @return string The article as the merchant knows it (not the key of a link); '' when it cannot be worked out.
	 */
	public function preview_article( \WC_Product $product, Mapping $mapping, Profile $profile ): string;
}
