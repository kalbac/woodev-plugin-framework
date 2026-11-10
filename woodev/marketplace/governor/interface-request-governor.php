<?php
/**
 * Request governor contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * Per-account token buckets. A runner asks before each request and reschedules itself when told to wait. The bucket
 * numbers come from `Marketplace_Adapter::bucket_specs()`.
 *
 * @since 2.0.29
 */
interface Request_Governor {
	/**
	 * Asks permission for a request.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account the bucket belongs to.
	 * @param string $bucket      Bucket key.
	 * @param int    $cost        Tokens the request costs (item count for an items bucket).
	 * @return int 0 to go ahead, otherwise the seconds to wait.
	 */
	public function acquire( string $account_key, string $bucket, int $cost = 1 ): int;

	/**
	 * Records that the marketplace answered 429 with a wait time.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param int    $retry_after Seconds the marketplace asked us to wait.
	 * @return void
	 */
	public function report_rate_limited( string $account_key, string $bucket, int $retry_after ): void;

	/**
	 * Records that the marketplace retired the method behind a bucket, so it is not called again for a while.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param string $reason      Short machine-readable reason.
	 * @return void
	 */
	public function report_disabled( string $account_key, string $bucket, string $reason ): void;

	/**
	 * Tells whether a bucket's method is switched off.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @return bool
	 */
	public function is_disabled( string $account_key, string $bucket ): bool;
}
