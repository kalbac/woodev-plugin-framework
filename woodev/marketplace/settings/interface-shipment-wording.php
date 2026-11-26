<?php
/**
 * Marketplace settings — the marketplace's words for its shipments.
 *
 * @package Woodev\Framework\Marketplace\Settings
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An optional part of a {@see Settings_Descriptor}: a marketplace that does not call a shipment «отгрузка» (Wildberries says
 * «поставка») gives its words here. The page of shipments, its menu entry and the actions on orders use them.
 *
 * @since 2.1.28
 */
interface Shipment_Wording {
	/**
	 * Returns the words.
	 *
	 * @since 2.1.28
	 *
	 * @return Shipment_Noun
	 */
	public function shipment_noun(): Shipment_Noun;
}
