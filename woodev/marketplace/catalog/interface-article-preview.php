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
	 * Only units without a card are asked about, once for each enabled account.
	 *
	 * The answer must be the article of the card the export would send for this mapping and profile — the one
	 * {@see \Woodev\Framework\Marketplace\Flows\Export_Facts::offer_id_for()} would name. An article the merchant typed at the
	 * product is no part of it: the framework reads that one through `offer_id_for()` itself and shows what comes back.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param Mapping     $mapping The mapping the product is sent by for that account, with the product's own values laid over it. It
	 *                             is the one on the screen unless the product names another or a nearer category has its own.
	 * @param Profile     $profile Its profile; an empty one when the mapping chose none.
	 * @return string The article as the merchant knows it (not the key of a link); '' when it cannot be worked out.
	 */
	public function preview_article( \WC_Product $product, Mapping $mapping, Profile $profile ): string;
}
