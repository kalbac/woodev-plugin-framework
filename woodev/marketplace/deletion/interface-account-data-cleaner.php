<?php
/**
 * The marketplace's half of deleting an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter, like {@see \Woodev\Framework\Marketplace\Admin\Order\Order_Canceller}: an adapter that
 * implements it is asked by {@see Account_Deletion_Job} for the two things only the plugin knows. The framework deletes its own
 * tables, options, transients, queued jobs and the pinned-article product meta by itself; an adapter that keeps nothing else and
 * can do nothing at the marketplace does not implement this.
 *
 * @since 2.1.5
 */
interface Account_Data_Cleaner {
	/**
	 * Deletes a batch of the data the plugin keeps for an account outside the framework's tables (its own product meta, for one).
	 *
	 * Called again until it returns 0, so an account with many products is cleaned in bounded batches, and again after an
	 * interruption: it must find what is left by itself and tolerate nothing being left.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account being deleted.
	 * @param int     $limit   Most rows to delete in this call.
	 * @return int Rows deleted; 0 when nothing is left.
	 */
	public function erase_account_data( Account $account, int $limit ): int;

	/**
	 * Removes the notification address the account registered at the marketplace, when the marketplace's API can.
	 *
	 * Called once, before the account row goes (the credentials are still needed), and only for an account of a marketplace
	 * with push notifications. A refusal or a failure is not an error: the merchant is told to remove the address in the
	 * marketplace's cabinet.
	 *
	 * It is called whatever the licence state: it is clean-up the merchant asked for, not a new remote action, so no licence
	 * gate belongs here.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account being deleted.
	 * @return bool True when the address was removed at the marketplace; false when it cannot be (no such API method, or it failed).
	 */
	public function unsubscribe_notifications( Account $account ): bool;
}
