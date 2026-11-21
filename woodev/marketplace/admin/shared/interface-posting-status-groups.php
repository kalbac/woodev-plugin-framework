<?php
/**
 * Optional adapter contract: which of the marketplace's own posting statuses mean what for the analytics.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * The registry stores a posting's status as the marketplace sent it (`status_raw`); only the adapter knows what those codes mean.
 * An adapter that implements this tells the «Аналитика» tab which codes wait on the shop (packing, handing over) and which ones
 * the marketplace cancelled or did not accept. Without it the framework does not guess: the tab shows no stuck postings block and
 * counts only cancelled WooCommerce orders as cancellations ({@see Posting_Status_Sets}). Empty lists are a real answer: «none of
 * my codes is one of these».
 *
 * @since 2.1.13
 */
interface Posting_Status_Groups {
	/**
	 * The statuses of a posting that waits on the shop: to be packed or handed over.
	 *
	 * @since 2.1.13
	 *
	 * @return string[] Codes as stored.
	 */
	public function waiting_posting_statuses(): array;

	/**
	 * The statuses of a posting the marketplace cancelled or did not accept — a cancellation even while its WooCommerce order
	 * still has another status.
	 *
	 * @since 2.1.13
	 *
	 * @return string[] Codes as stored.
	 */
	public function rejected_posting_statuses(): array;
}
