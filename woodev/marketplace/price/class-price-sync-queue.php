<?php
/**
 * The price side of the sync queue.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

use Woodev\Framework\Marketplace\Governor\Sync_Cadence;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and settles the `sync_state(kind = price)` rows for the account's single flusher (spec 3.1, 3.5, 5.4). Writing the
 * *wanted* payload is {@see \Woodev\Framework\Marketplace\Stock\Sync_State_Store}'s job; this class owns the sending side:
 *
 * - **due**: the pending payload differs from the sent one **as a whole** (so an old-price-only or min-price-only change is sent),
 *   the link is a `created` card with price sync on, the pair's deadline `next_attempt_at` has passed, and the last accepted send is
 *   at least {@see Sync_Cadence::min_interval()} seconds old (the marketplace's limit on updates per product). The deadline
 *   belongs to the **pair**, not to a revision: an item throttle, an uncertain send and a refusal all keep it, whatever is edited
 *   meanwhile, and the newest payload goes out when it passes. A pair whose current revision already failed
 *   {@see Sync_Cadence::max_attempts()} times waits until the payload changes (and its deadline passes).
 * - **in flight**: {@see self::begin()} copies `pending_payload / pending_rev` into the immutable `in_flight_*` snapshot; the
 *   acknowledgement of that exact revision ({@see self::accept()}) makes it the sent payload and leaves a newer `pending_rev`
 *   pending for the next run.
 * - **fenced**: every write names the account claim token and changes nothing unless that claim is still held (spec 3.5 rule 1), so
 *   a sender whose claim expired and was taken over cannot settle, back off or disturb the current sender's rows. A write that
 *   was refused for that reason returns false.
 *
 * @since 2.0.29
 */
final class Price_Sync_Queue {
	public const KIND = 'price';

	private const STATE_CREATED = 'created';
	private const KIND_OFFER    = 'offer';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Sync state table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Links table.
	 *
	 * @var string
	 */
	private $links_table;

	/**
	 * Accounts table, which holds the claim.
	 *
	 * @var string
	 */
	private $accounts_table;

	/**
	 * Clock: Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The marketplace's limits on price sends.
	 *
	 * @var Sync_Cadence
	 */
	private $cadence;

	/**
	 * Creates the queue.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db             Database.
	 * @param string        $table          Full `sync_state` table name.
	 * @param string        $links_table    Full `links` table name.
	 * @param string        $accounts_table Full `accounts` table name (the claim lives there).
	 * @param Sync_Cadence  $cadence        The marketplace's minimum interval between accepted sends of one product and attempt cap.
	 * @param callable|null $clock          `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $links_table, string $accounts_table, Sync_Cadence $cadence, ?callable $clock = null ) {
		$this->db             = $db;
		$this->table          = $table;
		$this->links_table    = $links_table;
		$this->accounts_table = $accounts_table;
		$this->cadence        = $cadence;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Returns the marketplace's limits on price sends, for the runners that must wait out the same interval.
	 *
	 * @since 2.0.29
	 *
	 * @return Sync_Cadence
	 */
	public function cadence(): Sync_Cadence {
		return $this->cadence;
	}

	/**
	 * Lists the pairs due for sending, oldest intent first.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most pairs.
	 * @return Price_Intent[] Each with the failed attempts of its current revision.
	 */
	public function due( int $account_id, int $limit ): array {
		$now  = $this->now();
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare(
				'SELECT s.link_id, s.pending_rev, s.pending_payload, CASE WHEN s.pending_rev > COALESCE( s.in_flight_rev, 0 ) THEN 0 ELSE s.attempts END AS attempts FROM %i s INNER JOIN %i l ON l.link_id = s.link_id AND l.account_id = s.account_id'
				. ' WHERE s.account_id = %d AND s.kind = %s AND s.warehouse_id = 0 AND s.pending_payload IS NOT NULL'
				. ' AND ( s.sent_payload IS NULL OR s.sent_payload <> s.pending_payload )'
				. ' AND ( s.next_attempt_at IS NULL OR s.next_attempt_at <= %s )'
				. ' AND ( s.attempts < %d OR s.pending_rev > COALESCE( s.in_flight_rev, 0 ) )'
				. ' AND ( s.sent_at IS NULL OR s.sent_at <= %s )'
				. ' AND l.kind = %s AND l.card_state = %s AND l.sync_price = 1'
				. ' ORDER BY s.pending_since ASC, s.link_id ASC LIMIT %d',
				$this->table,
				$this->links_table,
				$account_id,
				self::KIND,
				$this->format( $now ),
				$this->cadence->max_attempts(),
				$this->format( $now - $this->cadence->min_interval() ),
				self::KIND_OFFER,
				self::STATE_CREATED,
				$limit
			),
			'ARRAY_A'
		);

		$due = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$payload = json_decode( (string) $row['pending_payload'], true );

			if ( is_array( $payload ) ) {
				$due[] = new Price_Intent( (int) $row['link_id'], (int) $row['pending_rev'], $payload, (int) $row['attempts'] );
			}
		}

		return $due;
	}

	/**
	 * Copies the pending payload of a pair into its in-flight snapshot, when the pair is still at the revision that was read and
	 * the claim is still held.
	 *
	 * A retry of the same revision finds the snapshot already equal to the pending payload: the UPDATE then changes no row (MySQL
	 * and MariaDB count changed rows, not matched ones), so the snapshot is read back instead and, if it is exactly that revision
	 * under the held claim, the begin stands. A revision that differs from the one the previous attempt used starts a fresh
	 * attempt count.
	 *
	 * @since 2.0.29
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair as read by {@see self::due()}.
	 * @param string       $token      Account claim token.
	 * @return bool False when the pair changed meanwhile or the claim is no longer held (the next run reads it again).
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function begin( int $account_id, Price_Intent $intent, string $token ): bool {
		$changed = $this->write(
			'UPDATE %i SET attempts = CASE WHEN in_flight_rev IS NOT NULL AND in_flight_rev = pending_rev THEN attempts ELSE 0 END,'
			. ' in_flight_rev = pending_rev, in_flight_payload = pending_payload'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND pending_rev = %d',
			[ $this->table, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);

		return $changed > 0 || $this->holds_snapshot( $account_id, $intent, $token, true );
	}

	/**
	 * Records that the marketplace accepted exactly the snapshot of a revision: it becomes the sent payload; a newer pending revision stays
	 * pending.
	 *
	 * @since 2.0.29
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that was sent.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	public function accept( int $account_id, Price_Intent $intent, string $token ): bool {
		$now     = $this->format( $this->now() );
		$settled = 1 === $this->write(
			'UPDATE %i SET sent_payload = in_flight_payload, sent_rev = in_flight_rev, sent_at = %s, in_flight_rev = NULL, in_flight_payload = NULL,'
			. ' attempts = 0, next_attempt_at = NULL, last_error = NULL'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $now, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);

		if ( $settled ) {
			$this->write(
				'UPDATE %i SET last_pushed_price = %s, last_pushed_price_at = %s WHERE link_id = %d',
				[ $this->links_table, (string) ( $intent->payload()['price'] ?? '0' ), $now, $intent->link_id() ],
				$token
			);
		}

		return $settled;
	}

	/**
	 * Records a failed send of a revision: the attempt is counted, the marketplace's text kept, and the pair waits before the next try.
	 *
	 * @since 2.0.29
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that failed.
	 * @param string       $message    The marketplace's text, verbatim.
	 * @param int          $delay      Seconds before the next try.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function reject( int $account_id, Price_Intent $intent, string $message, int $delay, string $token ): bool {
		return 1 === $this->write(
			'UPDATE %i SET attempts = attempts + 1, next_attempt_at = %s, last_error = %s'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $this->format( $this->now() + $delay ), $message, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);
	}

	/**
	 * Makes a pair wait without counting an attempt (the marketplace asked for a pause, or the outcome of a send is uncertain).
	 *
	 * @since 2.0.29
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair.
	 * @param int          $delay      Seconds to wait.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function defer( int $account_id, Price_Intent $intent, int $delay, string $token ): bool {
		$changed = $this->write(
			'UPDATE %i SET next_attempt_at = %s WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $this->format( $this->now() + $delay ), $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);

		return $changed > 0 || $this->holds_snapshot( $account_id, $intent, $token, false );
	}

	/**
	 * Runs a claim-fenced write and returns the number of rows it changed.
	 *
	 * The statement's `WHERE` is extended with the proof that `$token` still owns the account row (spec 3.5 rule 1), so a sender whose
	 * claim expired changes nothing.
	 *
	 * @param string           $sql    Statement with placeholders and a first `%i` for the table it updates.
	 * @param array<int,mixed> $args   Its arguments.
	 * @param string           $token  Account claim token.
	 * @return int Rows changed.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	private function write( string $sql, array $args, string $token ): int {
		$sql    .= ' AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %i.account_id AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)';
		$args[]  = $this->accounts_table;
		$args[]  = $args[0];
		$args[]  = $token;
		$args[]  = $this->format( $this->now() );
		$changed = $this->db->query( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own tables, placeholders only.

		if ( false === $changed ) {
			throw new \RuntimeException( 'The price queue write failed: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $changed;
	}

	/**
	 * Reads back whether a pair's snapshot is exactly an intent's revision under the held claim: the proof that a statement which
	 * changed no row had nothing to change.
	 *
	 * @param int          $account_id   Account id.
	 * @param Price_Intent $intent       The pair.
	 * @param string       $token        Account claim token.
	 * @param bool         $same_pending Whether the pending revision must still be that revision too.
	 * @return bool
	 */
	private function holds_snapshot( int $account_id, Price_Intent $intent, string $token, bool $same_pending ): bool {
		$found = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare(
				'SELECT 1 FROM %i s WHERE s.account_id = %d AND s.link_id = %d AND s.warehouse_id = 0 AND s.kind = %s AND s.in_flight_rev = %d AND ( %d = 0 OR s.pending_rev = %d )'
				. ' AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = s.account_id AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
				$this->table,
				$account_id,
				$intent->link_id(),
				self::KIND,
				$intent->revision(),
				$same_pending ? 1 : 0,
				$intent->revision(),
				$this->accounts_table,
				$token,
				$this->format( $this->now() )
			)
		);

		return null !== $found && false !== $found;
	}

	/**
	 * Returns the current Unix time.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Formats a Unix time as a UTC datetime.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function format( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}
}
