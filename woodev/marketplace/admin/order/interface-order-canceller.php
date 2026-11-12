<?php
/**
 * The marketplace's half of cancelling a posting from an order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * What the order metabox and {@see Cancel_Ajax} ask the marketplace (`Capability::CANCEL_ORDER`): which posting an order carries,
 * whether it is finished, the reasons a cancellation may give and the cancellation itself. The plugin implements it next to its
 * adapter. Every remote failure is thrown as a {@see Cancel_Refused} with a sentence for the merchant (status 502 when the
 * marketplace refused or did not answer), so nothing marketplace-specific reaches the screen.
 *
 * @since 2.1.2
 */
interface Order_Canceller {
	/**
	 * Returns the posting number an order carries.
	 *
	 * @since 2.1.2
	 *
	 * @param \WC_Order $order Order.
	 * @return string Empty when the order is not an order of this marketplace.
	 */
	public function posting_number( \WC_Order $order ): string;

	/**
	 * Tells whether the posting is already delivered or cancelled.
	 *
	 * @since 2.1.2
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_finished( \WC_Order $order ): bool;

	/**
	 * Lists the reasons a cancellation may give.
	 *
	 * @since 2.1.2
	 *
	 * @param Account $account        Account the order belongs to.
	 * @param string  $posting_number Posting number.
	 * @return array<int,array{id:int,title:string}> Empty when the marketplace offers none for this posting.
	 * @throws Cancel_Refused When the marketplace refuses or does not answer.
	 */
	public function reasons( Account $account, string $posting_number ): array;

	/**
	 * Returns the id of the reason that asks the merchant for a free-text message.
	 *
	 * @since 2.1.2
	 *
	 * @return int
	 */
	public function other_reason_id(): int;

	/**
	 * Cancels the posting on the marketplace. The licence gate and the reason check live here.
	 *
	 * @since 2.1.2
	 *
	 * @param Account $account        Account the order belongs to.
	 * @param string  $posting_number Posting number.
	 * @param int     $reason_id      Chosen reason.
	 * @param string  $message        Free text for the «other» reason.
	 * @return array{title:string} The reason as the marketplace names it.
	 * @throws Cancel_Refused When the cancellation is refused or fails.
	 */
	public function cancel( Account $account, string $posting_number, int $reason_id, string $message ): array;

	/**
	 * Queues the refresh that brings the cancellation into the order. The cancellation is already done remotely, so a queue that
	 * refuses is not an error for the merchant: the catch-up poll refreshes every open posting anyway.
	 *
	 * @since 2.1.2
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting number.
	 * @return void
	 */
	public function queue_refresh( Account $account, string $posting_number ): void;
}
