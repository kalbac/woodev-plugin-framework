<?php
/**
 * Who may work on an account's bulk run.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The exclusion every tick and the stop take before they read the run (#1795): one holder per account at a time, freed when the
 * holder's database connection closes. {@see Bulk_Run_Lock} is the MySQL named lock; the interface lets a test with no MySQL stand in.
 *
 * @since 2.1.8
 */
interface Bulk_Run_Locking {
	/**
	 * Takes the account's lock without waiting.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return bool Whether this connection holds it now.
	 */
	public function acquire( int $account_id ): bool;

	/**
	 * Releases the lock this connection holds; a connection that does not hold it releases nothing.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return void
	 */
	public function release( int $account_id ): void;

	/**
	 * Tells whether the connection the query runs on right now still holds the lock. After a reconnect `wpdb` runs on a new
	 * connection that holds nothing, so the answer is false there.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_held( int $account_id ): bool;
}
