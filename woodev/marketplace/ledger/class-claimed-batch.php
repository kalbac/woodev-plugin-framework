<?php
/**
 * Batch of events claimed by one worker.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * The rows a worker won in one claim, read back by the claim token (never by the ids it selected beforehand), so a
 * partially won batch is exact. The token fences every later `complete()`, `retry()` and `fail()` call.
 *
 * @since 2.0.29
 */
final class Claimed_Batch {
	/**
	 * Claim token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Claimed events, each carrying its ledger row id.
	 *
	 * @var Inbound_Event[]
	 */
	private $events;

	/**
	 * Creates a batch.
	 *
	 * @since 2.0.29
	 *
	 * @param string          $token  Claim token.
	 * @param Inbound_Event[] $events Claimed events.
	 */
	public function __construct( string $token, array $events ) {
		$this->token  = $token;
		$this->events = $events;
	}

	/**
	 * Returns the claim token.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Returns the claimed events.
	 *
	 * @since 2.0.29
	 *
	 * @return Inbound_Event[]
	 */
	public function events(): array {
		return $this->events;
	}

	/**
	 * Tells whether the worker won nothing.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return [] === $this->events;
	}
}
