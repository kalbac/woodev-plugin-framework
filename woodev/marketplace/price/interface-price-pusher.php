<?php
/**
 * Price push contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Sends calculated prices to a marketplace. Marketplaces whose prices cannot be pushed return null from
 * `Marketplace_Adapter::price_pusher()`.
 *
 * @since 2.0.29
 */
interface Price_Pusher {
	/**
	 * Submits prices.
	 *
	 * @since 2.0.29
	 *
	 * @param Account      $account Account.
	 * @param Price_Line[] $lines   Prices to send.
	 * @return Push_Receipt Per-item results at once (Ozon), or a pending task (Wildberries; HTTP 208 is a duplicate of a running task).
	 */
	public function submit( Account $account, array $lines ): Push_Receipt;

	/**
	 * Settles a pending receipt later (Wildberries: history, buffer and quarantine endpoints). Ozon never returns a
	 * pending receipt, so its implementation returns an empty list.
	 *
	 * The task is the stored one: its remote id, its submission time and its result payload — what the pending receipt's task
	 * carried, plus `revisions` (link id => revision of the price that was sent), which names the links of the task.
	 *
	 * Each result decides one link:
	 *
	 * - accepted;
	 * - a failure with `retry_after_seconds`: not decided, the price is sent again after the wait, without counting an attempt;
	 * - a failure with the code {@see Price_Refusal::QUARANTINED}: the marketplace holds the price in quarantine and keeps the old
	 *   one;
	 * - any other failure: refused, with the marketplace's text as the message.
	 *
	 * A refused or quarantined price is not sent again until the price changes — also when a pending receipt of
	 * {@see self::submit()} carries that failure at once. A link with no result is asked about again until the task's deadline
	 * ({@see Price_Task_Schedule}).
	 *
	 * @since 2.0.29
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Pending task of kind `price`.
	 * @return Item_Result[] Results for the items the marketplace has decided on so far.
	 */
	public function settle( Account $account, Async_Task $task ): array;
}
