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
 * - **waiting** (2.1.24): a marketplace that decides later leaves the pair in flight with the id of its price task
 *   ({@see self::await()}); it is not due until the task decides ({@see self::accept()}, {@see self::refuse()}) or lets it go
 *   ({@see self::release()}, {@see self::expire()}).
 * - **refused** (2.1.24): a price a task refused for good ({@see self::refuse()}) is kept beside the pair and not sent again; a
 *   different pending price is due, also one equal to the sent payload — it is what releases a quarantined price. A pair whose
 *   task expired is due whatever it was changed to: what the marketplace holds is unknown.
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
				. ' WHERE s.account_id = %d AND s.kind = %s AND s.warehouse_id = 0 AND s.pending_payload IS NOT NULL AND s.task_id IS NULL'
				. ' AND ( s.sent_payload IS NULL OR s.sent_payload <> s.pending_payload OR s.refused_payload IS NOT NULL OR s.error_code IS NOT NULL )'
				. ' AND ( s.refused_payload IS NULL OR s.refused_payload <> s.pending_payload )'
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
	 * Reads what a link wants on the marketplace right now: its pending payload and revision, whatever was sent before and whenever
	 * the next send is due. The one-time price pull reads it before it writes the shop price, to know whether anything is queued, and
	 * again after, to see whether the merchant edited the price meanwhile (#1797).
	 *
	 * @since 2.1.10
	 *
	 * @param int $account_id Account id.
	 * @param int $link_id    Link id.
	 * @return Price_Intent|null Null when the link has no price row, or the row holds no pending payload.
	 * @throws \RuntimeException When the read fails (a column or table missing, a lost connection).
	 */
	public function pending_of( int $account_id, int $link_id ): ?Price_Intent {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables, read fresh.
			$this->db->prepare(
				'SELECT pending_rev, pending_payload, pending_source FROM %i WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND pending_payload IS NOT NULL LIMIT 1',
				$this->table,
				$account_id,
				$link_id,
				self::KIND
			),
			'ARRAY_A'
		);

		// A failed read is not «nothing is queued»: the pull would report success and leave the old send due to undo it.
		if ( ! is_array( $rows ) || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'The price queue could not be read: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$row     = [] !== $rows ? $rows[0] : null;
		$payload = null === $row ? null : json_decode( (string) $row['pending_payload'], true );

		$source = null === $row || null === $row['pending_source'] ? null : json_decode( (string) $row['pending_source'], true );

		return is_array( $payload ) ? new Price_Intent( $link_id, (int) $row['pending_rev'], $payload, 0, is_array( $source ) ? $source : null ) : null;
	}

	/**
	 * Settles the pair of a price the merchant pulled from the marketplace: the marketplace already holds this price, so the pair has
	 * nothing left to send. Without it a send queued before the pull (the shop at 150, the marketplace at 100) would still be due and
	 * would undo the pull a minute later, because the sender sends what differs between `pending_payload` and `sent_payload` and
	 * knows nothing of the read.
	 *
	 * One claim-fenced statement: the payload read from the marketplace becomes both the pending and the sent one, the in-flight
	 * snapshot and the retry state are cleared. It applies only while the pending revision is still the one the pull saw
	 * (`$revision`): a price the merchant saved after that read has a newer revision and is left untouched, and the caller restores it.
	 *
	 * @since 2.1.10
	 *
	 * @param int                       $account_id Account id.
	 * @param int                       $link_id    Link id.
	 * @param int                       $revision   The pending revision the pull read before it wrote the shop price.
	 * @param array<string,string|null> $payload    The marketplace's price as a send payload.
	 * @param string                    $token      Account claim token.
	 * @return bool False when the pending revision moved on or the claim is no longer held; nothing was changed then.
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	public function settle_pulled( int $account_id, int $link_id, int $revision, array $payload, string $token ): bool {
		$json    = (string) json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function; the store encodes the same way.
		$changed = $this->write(
			'UPDATE %i SET pending_payload = %s, pending_source = NULL, sent_payload = %s, sent_rev = pending_rev, in_flight_rev = NULL, in_flight_payload = NULL,'
			. ' attempts = 0, next_attempt_at = NULL, last_error = NULL, task_id = NULL, refused_payload = NULL, error_code = NULL'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND pending_rev = %d',
			[ $this->table, $json, $json, $account_id, $link_id, self::KIND, $revision ],
			$token
		);

		// MySQL and MariaDB count changed rows, not matched ones: a pair that an earlier pull or a send already left exactly settled
		// changes none. That is a settle that stands, but only when the row is read back as settled under the claim.
		if ( 0 === $changed && ! $this->holds_settled( $account_id, $link_id, $revision, $json, $token ) ) {
			return false;
		}

		$this->write(
			'UPDATE %i SET last_pushed_price = %s, last_pushed_price_at = %s WHERE link_id = %d',
			[ $this->links_table, (string) ( $payload['price'] ?? '0' ), $this->format( $this->now() ), $link_id ],
			$token
		);

		return true;
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
		// The order of the assignments matters (MySQL reads the values already assigned): the CASEs compare the revision of the
		// previous attempt before `in_flight_rev` moves, and the text of a price reason is cleared before its code.
		$changed = $this->write(
			'UPDATE %i SET attempts = CASE WHEN in_flight_rev IS NOT NULL AND in_flight_rev = pending_rev THEN attempts ELSE 0 END,'
			. ' last_error = CASE WHEN error_code IS NOT NULL AND ( in_flight_rev IS NULL OR in_flight_rev <> pending_rev ) THEN NULL ELSE last_error END,'
			. ' error_code = CASE WHEN in_flight_rev IS NOT NULL AND in_flight_rev = pending_rev THEN error_code ELSE NULL END,'
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
			. ' attempts = 0, next_attempt_at = NULL, last_error = NULL, task_id = NULL, refused_payload = NULL, error_code = NULL'
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
			'UPDATE %i SET attempts = attempts + 1, next_attempt_at = %s, last_error = %s, task_id = NULL, refused_payload = NULL, error_code = NULL'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $this->format( $this->now() + $delay ), $message, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);
	}

	/**
	 * Deletes the price rows of an account whose link no longer exists (its product was deleted while a sender held the claim, so
	 * the delete could not retire them). {@see self::due()} only reads rows that have a link, so nothing else would ever touch them.
	 *
	 * @since 2.1.8
	 *
	 * @param int    $account_id Account id.
	 * @param string $token      Account claim token.
	 * @return int Rows deleted; 0 also when the claim is not held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function forget_orphans( int $account_id, string $token ): int {
		return $this->write(
			'DELETE FROM %i WHERE account_id = %d AND kind = %s AND NOT EXISTS (SELECT 1 FROM %i AS l WHERE l.link_id = %i.link_id)',
			[ $this->table, $account_id, self::KIND, $this->links_table, $this->table ],
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
	 * Makes a pair in flight wait for a price task: the marketplace decides later, and nothing is sent for the pair meanwhile.
	 *
	 * @since 2.1.24
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that was sent.
	 * @param int          $task_id    Id of the price task in the ledger.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function await( int $account_id, Price_Intent $intent, int $task_id, string $token ): bool {
		$changed = $this->write(
			'UPDATE %i SET task_id = %d, next_attempt_at = NULL WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $task_id, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);

		return $changed > 0 || $this->holds_snapshot( $account_id, $intent, $token, false );
	}

	/**
	 * Lists the pairs that wait for a price task, each as the price that was sent. A pair that was pulled from the marketplace or
	 * detached meanwhile no longer waits and is not listed.
	 *
	 * @since 2.1.24
	 *
	 * @param int $account_id Account id.
	 * @param int $task_id    Id of the price task.
	 * @return array<int,array{intent:Price_Intent,expired_before:bool}> By link id; `expired_before` says an earlier task already
	 *                                                                     let this price expire.
	 * @throws \RuntimeException When the read fails.
	 */
	public function awaiting( int $account_id, int $task_id ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
			$this->db->prepare(
				'SELECT link_id, in_flight_rev, in_flight_payload, error_code FROM %i WHERE account_id = %d AND kind = %s AND warehouse_id = 0 AND task_id = %d AND in_flight_rev IS NOT NULL',
				$this->table,
				$account_id,
				self::KIND,
				$task_id
			),
			'ARRAY_A'
		);

		if ( ! is_array( $rows ) || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'The prices of a price task could not be read: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$waiting = [];

		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row['in_flight_payload'], true );

			if ( is_array( $payload ) ) {
				$waiting[ (int) $row['link_id'] ] = [
					'intent'         => new Price_Intent( (int) $row['link_id'], (int) $row['in_flight_rev'], $payload ),
					'expired_before' => Price_Refusal::EXPIRED === (string) $row['error_code'],
				];
			}
		}

		return $waiting;
	}

	/**
	 * Records that the sent price will not take effect and is not to be sent again: it is kept as the refused one until a price is
	 * accepted or pulled, and only a different pending price is due.
	 *
	 * @since 2.1.24
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that was sent.
	 * @param string       $code       A {@see Price_Refusal} code.
	 * @param string       $message    The sentence or the marketplace's text the merchant reads.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function refuse( int $account_id, Price_Intent $intent, string $code, string $message, string $token ): bool {
		// `refused_payload` is taken from the snapshot before the snapshot is cleared.
		return 1 === $this->write(
			'UPDATE %i SET refused_payload = in_flight_payload, error_code = %s, last_error = %s, task_id = NULL, in_flight_rev = NULL, in_flight_payload = NULL,'
			. ' attempts = 0, next_attempt_at = NULL'
			. ' WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $code, $message, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);
	}

	/**
	 * Lets a waiting pair go undecided: the marketplace asked to wait, so it is sent again after the delay, with no attempt counted.
	 *
	 * @since 2.1.24
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that was sent.
	 * @param int          $delay      Seconds to wait.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function release( int $account_id, Price_Intent $intent, int $delay, string $token ): bool {
		return 1 === $this->write(
			'UPDATE %i SET task_id = NULL, next_attempt_at = %s WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, $this->format( $this->now() + $delay ), $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);
	}

	/**
	 * Lets a waiting pair go because its task's deadline passed: it is due again at once, marked as expired once, so that a second
	 * expiry of the same revision can be told apart.
	 *
	 * @since 2.1.24
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The pair that was sent.
	 * @param string       $message    The sentence the merchant reads meanwhile.
	 * @param string       $token      Account claim token.
	 * @return bool False when the snapshot is no longer that revision or the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function expire( int $account_id, Price_Intent $intent, string $message, string $token ): bool {
		return 1 === $this->write(
			// An earlier refusal says nothing any more: what the marketplace holds now is unknown.
			'UPDATE %i SET task_id = NULL, next_attempt_at = NULL, refused_payload = NULL, error_code = %s, last_error = %s WHERE account_id = %d AND link_id = %d AND warehouse_id = 0 AND kind = %s AND in_flight_rev = %d',
			[ $this->table, Price_Refusal::EXPIRED, $message, $account_id, $intent->link_id(), self::KIND, $intent->revision() ],
			$token
		);
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
	 * Reads back whether a pair is exactly the state {@see self::settle_pulled()} leaves, under the held claim: the proof that a settle
	 * which changed no row had nothing to change. Every column the settle writes is compared, so a pair that merely shares the
	 * revision (a retry state, an in-flight snapshot, another payload) is not taken for settled.
	 *
	 * @param int    $account_id Account id.
	 * @param int    $link_id    Link id.
	 * @param int    $revision   The pending revision the pull read.
	 * @param string $json       The settled payload as stored.
	 * @param string $token      Account claim token.
	 * @return bool
	 */
	private function holds_settled( int $account_id, int $link_id, int $revision, string $json, string $token ): bool {
		$found = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
			$this->db->prepare(
				'SELECT 1 FROM %i s WHERE s.account_id = %d AND s.link_id = %d AND s.warehouse_id = 0 AND s.kind = %s'
				. ' AND s.pending_rev = %d AND s.sent_rev = %d AND s.pending_payload = %s AND s.sent_payload = %s AND s.pending_source IS NULL'
				. ' AND s.in_flight_rev IS NULL AND s.in_flight_payload IS NULL AND s.attempts = 0 AND s.next_attempt_at IS NULL AND s.last_error IS NULL'
				. ' AND s.task_id IS NULL AND s.refused_payload IS NULL AND s.error_code IS NULL'
				. ' AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = s.account_id AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
				$this->table,
				$account_id,
				$link_id,
				self::KIND,
				$revision,
				$revision,
				$json,
				$json,
				$this->accounts_table,
				$token,
				$this->format( $this->now() )
			)
		);

		return null !== $found && false !== $found;
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
