<?php
/**
 * Where a card of the marketplace's cabinet can be opened.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: an adapter that implements it gives the «Карточки {marketplace}» table the address of each card in the seller's
 * cabinet, so the card's name becomes a link to it. An adapter that does not leaves the name as plain text and the table works as
 * before.
 *
 * @since 2.1.13
 */
interface Remote_Card_Address {
	/**
	 * Returns the address of a card in the seller's cabinet.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $remote_ids The card's identifiers on the marketplace, name => value (for Ozon `product_id`, `sku`, `model_id`).
	 * @return string Empty when the identifiers do not name a card yet.
	 */
	public function remote_card_url( array $remote_ids ): string;
}
