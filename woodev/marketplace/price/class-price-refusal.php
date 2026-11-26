<?php
/**
 * Why a price the marketplace decided on later is not on the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * Reason codes of a price that was accepted for processing and did not take effect. A price pusher whose marketplace decides later
 * answers {@see Price_Pusher::settle()} with a failed {@see \Woodev\Framework\Marketplace\Ledger\Item_Result}; the framework reads
 * its code only to tell a quarantine from every other refusal, and stores one of these codes beside the price.
 *
 * @since 2.1.25
 */
final class Price_Refusal {
	/**
	 * The marketplace refused the price for this product.
	 */
	public const REFUSED = 'price_refused';

	/**
	 * The marketplace holds the price in quarantine and keeps the old one. A pusher gives this code to such an item.
	 */
	public const QUARANTINED = 'price_quarantined';

	/**
	 * The marketplace gave no answer before the task's deadline.
	 */
	public const EXPIRED = 'price_expired';
}
