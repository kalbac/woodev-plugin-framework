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
	 * A price the marketplace put in quarantine is a result with `ok = false` that leaves the old remote price in place.
	 *
	 * @since 2.0.29
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Pending task of kind `price`.
	 * @return Item_Result[] Results for the items the marketplace has decided on so far.
	 */
	public function settle( Account $account, Async_Task $task ): array;
}
