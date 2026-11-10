<?php
/**
 * Storage contract of the request governor.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * Persists governor buckets so that concurrent Action Scheduler runs and web requests see one state. The write is a
 * compare-and-swap on a version counter, not on a timestamp: two acquisitions inside the same second must not both
 * succeed.
 *
 * @since 2.0.29
 */
interface Throttle_Store {
	/**
	 * Reads a bucket.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account the bucket belongs to.
	 * @param string $bucket      Bucket key.
	 * @return Throttle_Row|null Null when the bucket was never stored.
	 */
	public function read( string $account_key, string $bucket ): ?Throttle_Row;

	/**
	 * Creates a bucket at version 0 unless it exists already (an insert that ignores a duplicate key).
	 *
	 * @since 2.0.29
	 *
	 * @param string              $account_key Account.
	 * @param string              $bucket      Bucket key.
	 * @param array<string,mixed> $state       Initial state.
	 * @return void
	 */
	public function create( string $account_key, string $bucket, array $state ): void;

	/**
	 * Replaces a bucket if nobody changed it since it was read.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $account_key      Account.
	 * @param string              $bucket           Bucket key.
	 * @param int                 $expected_version Version the caller read.
	 * @param array<string,mixed> $state            New state.
	 * @param int|null            $disabled_until   New switch-off time (Unix), null for none.
	 * @param string              $disabled_reason  New switch-off reason.
	 * @return bool True when this call won the swap; false when the version had moved on (re-read and retry).
	 */
	public function swap( string $account_key, string $bucket, int $expected_version, array $state, ?int $disabled_until, string $disabled_reason ): bool;

	/**
	 * Raises a bucket's switch-off time to at least the given moment, in ONE atomic statement that cannot lose.
	 *
	 * This is the write for a monotonic maximum (a cooldown after a 429): no version check is needed because the result
	 * is the same whatever the order of concurrent writers. The row is created when it does not exist; an existing,
	 * later time is kept. MySQL / MariaDB form:
	 *
	 * `INSERT INTO t (account_id, bucket, state, version, disabled_until, disabled_reason) VALUES (…, '{}', 0, :when, '')
	 *  ON DUPLICATE KEY UPDATE disabled_until = GREATEST(COALESCE(disabled_until, :when), :when)`
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param int    $until       Unix time to hold until.
	 * @return bool False when the database refused the statement.
	 */
	public function raise_until( string $account_key, string $bucket, int $until ): bool;
}
