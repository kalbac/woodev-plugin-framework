<?php
/**
 * What the product tab asks the marketplace that the adapter contract does not answer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace-specific half of the product tab: where a card lives, how its remote identifiers read, whether the marketplace
 * has an oversize flag. The plugin implements it next to its adapter; every sentence it returns is already translated.
 *
 * @since 2.1.2
 */
interface Product_Tab_Facts {
	/**
	 * Returns the public address of the card on the marketplace.
	 *
	 * @since 2.1.2
	 *
	 * @param Link_Record $link Offer row.
	 * @return string Empty when the card has no public page yet.
	 */
	public function card_url( Link_Record $link ): string;

	/**
	 * Returns the remote identifiers of a card as one line («ID товара 1 · SKU 2»).
	 *
	 * @since 2.1.2
	 *
	 * @param Link_Record $link Offer row.
	 * @return string Empty when the marketplace has assigned none yet.
	 */
	public function identifier_summary( Link_Record $link ): string;

	/**
	 * Tells whether the marketplace has an oversize flag on a product (the tab shows the switch only then).
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function supports_oversize(): bool;
}
