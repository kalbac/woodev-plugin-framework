<?php
/**
 * Offers that must be sent to the marketplace together.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: an adapter's {@see Export_Facts} that implements it says which offers form one card (or one group of cards) on the
 * marketplace, for example the sizes and colours of one variable product.
 *
 * The export never splits a group across import requests: offers queued together that share a group reach
 * {@see Export_Gateway::submit()} in one call, even when the group alone is larger than a usual batch. «Together» is what one export
 * queues: an offer the validator refuses, or one the merchant did not select, is not waited for.
 *
 * Group only offers of one WooCommerce product (the variations of a variable product). That is what every export path keeps
 * together; a group that spans several products may arrive in parts from a bulk run over a filter.
 *
 * @since 2.1.21
 */
interface Export_Grouping {
	/**
	 * Names the group of an offer.
	 *
	 * @since 2.1.21
	 *
	 * @param Link_Record $link    Its offer row; the identifiers a card already has are on it.
	 * @param \WC_Product $product Product or variation.
	 * @return string The group, the same string for every offer of it; '' when the offer stands alone.
	 */
	public function export_group( Link_Record $link, \WC_Product $product ): string;
}
