<?php
/**
 * Card export contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Status;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates marketplace cards. Both marketplaces are «submit now, reconcile later»: Ozon returns a task id to
 * poll, Wildberries returns nothing to poll and its `poll()` reads the error list and the card list instead.
 *
 * @since 2.0.29
 */
interface Card_Exporter {
	/**
	 * Submits cards.
	 *
	 * @since 2.0.29
	 *
	 * @param Account        $account Account.
	 * @param Product_Link[] $links   Links to export (at most one request's worth).
	 * @param Mapping        $mapping Mapping the products resolve to.
	 * @param Profile        $profile Export profile.
	 * @return Async_Task The task to poll; `remote_task_id` is null when the marketplace has none.
	 */
	public function submit( Account $account, array $links, Mapping $mapping, Profile $profile ): Async_Task;

	/**
	 * Polls a submitted task.
	 *
	 * @since 2.0.29
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Task returned by `submit()`.
	 * @return Task_Status
	 */
	public function poll( Account $account, Async_Task $task ): Task_Status;
}
