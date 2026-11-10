<?php
/**
 * In-memory event ledger with token-fenced claims.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Ledger\Claimed_Batch;
use Woodev\Framework\Marketplace\Ledger\Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * Shows the shape of the ledger contract: duplicates return null, a claim stamps one token on the rows it wins, the
 * batch is read back by that token, and every later change is refused once another token owns the row.
 */
final class Fake_Event_Ledger implements Event_Ledger {
	private const LEASE_SECONDS = 120;

	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Rows by id.
	 *
	 * @var array<int,array{event:Inbound_Event,status:string,token:?string,until:int,error:string}>
	 */
	private $rows = array();

	/**
	 * Claims made so far; makes tokens unique.
	 *
	 * @var int
	 */
	private $claims = 0;

	/**
	 * Creates the ledger.
	 *
	 * @param Fake_Clock $clock Clock.
	 */
	public function __construct( Fake_Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Stores an event; the same account and dedup key is a duplicate.
	 *
	 * @param Inbound_Event $event Event.
	 * @return int|null
	 */
	public function record( Inbound_Event $event ): ?int {
		foreach ( $this->rows as $row ) {
			if ( $row['event']->account_id() === $event->account_id() && $row['event']->dedup_key() === $event->dedup_key() ) {
				return null;
			}
		}

		$id                = count( $this->rows ) + 1;
		$this->rows[ $id ] = array(
			'event'  => $event->with_event_id( $id ),
			'status' => 'new',
			'token'  => null,
			'until'  => 0,
			'error'  => '',
		);

		return $id;
	}

	/**
	 * Claims due rows of an account with one new token.
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows.
	 * @return Claimed_Batch
	 */
	public function claim( int $account_id, int $limit ): Claimed_Batch {
		++$this->claims;
		$token = str_pad( (string) $this->claims, 32, '0', STR_PAD_LEFT );
		$now   = $this->clock->now()->getTimestamp();
		$won   = array();
		foreach ( $this->rows as $id => $row ) {
			$fresh   = in_array( $row['status'], array( 'new', 'retry' ), true ) && $row['until'] <= $now;
			$expired = 'processing' === $row['status'] && $row['until'] < $now;
			$due     = $fresh || $expired;
			if ( count( $won ) >= $limit || $row['event']->account_id() !== $account_id || ! $due ) {
				continue;
			}

			$this->rows[ $id ]['status'] = 'processing';
			$this->rows[ $id ]['token']  = $token;
			$this->rows[ $id ]['until']  = $now + self::LEASE_SECONDS;
			$won[]                       = $id;
		}

		$events = array();
		foreach ( $this->rows as $row ) {
			if ( $row['token'] === $token ) {
				$events[] = $row['event'];
			}
		}

		return new Claimed_Batch( $token, $events );
	}

	/**
	 * Completes a claimed row.
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param string $result   Result text.
	 * @return bool
	 */
	public function complete( int $event_id, string $token, string $result ): bool {
		return $this->finish( $event_id, $token, 'done', 0, $result );
	}

	/**
	 * Schedules another attempt.
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param int    $delay    Seconds.
	 * @param string $error    Error text.
	 * @return bool
	 */
	public function retry( int $event_id, string $token, int $delay, string $error ): bool {
		return $this->finish( $event_id, $token, 'retry', $this->clock->now()->getTimestamp() + $delay, $error );
	}

	/**
	 * Fails a claimed row for good.
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param string $error    Error text.
	 * @return bool
	 */
	public function fail( int $event_id, string $token, string $error ): bool {
		return $this->finish( $event_id, $token, 'failed', 0, $error );
	}

	/**
	 * Returns the status of a row.
	 *
	 * @param int $event_id Row id.
	 * @return string
	 */
	public function status_of( int $event_id ): string {
		return $this->rows[ $event_id ]['status'];
	}

	/**
	 * Applies a fenced state change.
	 *
	 * @param int    $event_id Row id.
	 * @param string $token    Claim token.
	 * @param string $status   New status.
	 * @param int    $until    Time before which a retried row is not due.
	 * @param string $text     Result or error text.
	 * @return bool False when the token does not own the row.
	 */
	private function finish( int $event_id, string $token, string $status, int $until, string $text ): bool {
		if ( ! isset( $this->rows[ $event_id ] ) || $this->rows[ $event_id ]['token'] !== $token ) {
			return false;
		}

		$this->rows[ $event_id ]['status'] = $status;
		$this->rows[ $event_id ]['token']  = null;
		$this->rows[ $event_id ]['until']  = $until;
		$this->rows[ $event_id ]['error']  = $text;

		return true;
	}
}
