<?php
/**
 * Inbound event ledger contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Durable store of inbound notifications. Request time only records; Action Scheduler runners claim and process.
 *
 * Every state change after `claim()` carries the claim token and returns false when the token no longer owns the row
 * (the lease expired and another worker took over): the caller must then treat its work as lost.
 *
 * @since 2.0.29
 */
interface Event_Ledger {
	/**
	 * Stores an event.
	 *
	 * @since 2.0.29
	 *
	 * @param Inbound_Event $event Event to store.
	 * @return int|null New row id, or null for a duplicate (same account and dedup key).
	 * @throws \RuntimeException When the insert fails, so the endpoint can answer 500 and the marketplace retries.
	 */
	public function record( Inbound_Event $event ): ?int;

	/**
	 * Claims up to `$limit` due events of an account with one token.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to claim.
	 * @return Claimed_Batch The token and the rows read back by that token.
	 */
	public function claim( int $account_id, int $limit ): Claimed_Batch;

	/**
	 * Marks a claimed event done.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param string $result   Short result text.
	 * @return bool False when the token no longer owns the row.
	 */
	public function complete( int $event_id, string $token, string $result ): bool;

	/**
	 * Schedules a claimed event for another attempt.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param int    $delay    Seconds until the next attempt.
	 * @param string $error    Error text.
	 * @return bool False when the token no longer owns the row.
	 */
	public function retry( int $event_id, string $token, int $delay, string $error ): bool;

	/**
	 * Marks a claimed event failed for good.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param string $error    Error text.
	 * @return bool False when the token no longer owns the row.
	 */
	public function fail( int $event_id, string $token, string $error ): bool;
}
