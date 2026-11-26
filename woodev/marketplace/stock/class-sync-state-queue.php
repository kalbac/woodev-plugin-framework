<?php
/**
 * The flusher's side of `sync_state`.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

use Woodev\Framework\Marketplace\Governor\Sync_Cadence;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the in-flight, sent, backoff and readback columns of `sync_state` (spec 3.1). {@see Sync_State_Store} writes the
 * pending side at request time; this class belongs to the account's **single** flusher (spec 3.5, 5.3), so every write here carries
 * the account claim in its `WHERE`: a flusher whose claim expired and was taken over changes 0 rows.
 *
 * The revision rule (spec 5.4, the same for stock and price): the flusher copies `pending_payload / pending_rev` into the immutable
 * `in_flight_payload / in_flight_rev` in one statement right before the request, and an acknowledgement settles exactly
 * `in_flight_rev`. A save during the send bumps `pending_rev`, so the row stays due and the newer value goes out on the next run.
 * «Nothing to send» means the pending payload equals the sent one as a whole **and** no send is unresolved: after an uncertain
 * outcome the in-flight snapshot stays, so the newest payload goes out even when it equals an older acknowledged one.
 *
 * **Failed, never dropped** (spec 4.1 d): a pair that failed {@see Sync_Cadence::max_attempts()} counted attempts is no longer selected; its
 * payload and the marketplace's text stay on the row. A new payload ({@see Sync_State_Store::record()}) or {@see self::retry()} arms it again.
 *
 * Every write here throws when the database refuses it, so an SQL error is never mistaken for a lost claim or for success.
 *
 * @since 2.0.29
 */
final class Sync_State_Queue {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the `sync_state` table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Full name of the accounts table, whose row carries the claim.
	 *
	 * @var string
	 */
	private $accounts;

	/**
	 * Clock: Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The marketplace's limits on stock sends.
	 *
	 * @var Sync_Cadence
	 */
	private $cadence;

	/**
	 * Creates the queue.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db       Database.
	 * @param string        $table    Full name of `sync_state`.
	 * @param string        $accounts Full name of the accounts table.
	 * @param Sync_Cadence  $cadence  The marketplace's attempt cap (and minimum interval, which the stock side does not use).
	 * @param callable|null $clock    `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $accounts, Sync_Cadence $cadence, ?callable $clock = null ) {
		$this->db       = $db;
		$this->table    = $table;
		$this->accounts = $accounts;
		$this->cadence  = $cadence;
		$this->clock    = $clock ?? 'time';
	}

	/**
	 * Returns the marketplace's limits on stock sends, for the flusher that counts attempts against the same cap.
	 *
	 * @since 2.0.29
	 *
	 * @return Sync_Cadence
	 */
	public function cadence(): Sync_Cadence {
		return $this->cadence;
	}

	/**
	 * Lists the rows of an account that have something to send and are not backing off: the zeros of left warehouses that never
	 * waited first (since 2.1.25, so a limit never cuts one off behind the stock that replaces it), then the oldest intent first.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $kind       `Sync_State_Store::KIND_STOCK` or `KIND_PRICE`.
	 * @param int    $limit      Most rows.
	 * @return array<int,array<string,mixed>> Rows: the key columns, `pending_payload` and `sent_payload` (arrays; null when empty),
	 *                                        `pending_rev`, `pending_since`, `in_flight_rev` (null when no send is unresolved),
	 *                                        `sent_rev` (null when nothing was ever accepted), `attempts`, `last_error`,
	 *                                        `remote_present` (null when never read nor sent; since 2.1.25).
	 */
	public function due( int $account_id, string $kind, int $limit ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The flusher must read fresh.
			$this->db->prepare(
				'SELECT account_id, link_id, warehouse_id, kind, pending_payload, sent_payload, pending_rev, pending_since, in_flight_rev, sent_rev, attempts, last_error, remote_present FROM %i WHERE account_id = %d AND kind = %s AND pending_payload IS NOT NULL AND (sent_payload IS NULL OR pending_payload <> sent_payload OR in_flight_rev IS NOT NULL) AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY CASE WHEN pending_payload = %s AND next_attempt_at IS NULL THEN 0 ELSE 1 END, pending_since ASC, link_id ASC LIMIT %d',
				$this->table,
				$account_id,
				$kind,
				$this->cadence->max_attempts(),
				$this->time( 0 ),
				self::vacate_json(),
				max( 1, $limit )
			),
			'ARRAY_A'
		);

		$due = [];
		foreach ( (array) $rows as $row ) {
			$row['pending_payload'] = self::decode( $row['pending_payload'] );
			$row['sent_payload']    = null === $row['sent_payload'] ? null : self::decode( $row['sent_payload'] );
			$due[]                  = $row;
		}

		return $due;
	}

	/**
	 * Lists, for some links, the warehouses whose zero ({@see Sync_State_Store::record_vacate()}) has not had its first answer:
	 * wanted, not accepted, and never refused or failed. A zero that waits counts, whatever it waits for and whether or not it is
	 * due.
	 *
	 * @since 2.1.26
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,int[]> Link id => warehouse ids; a link without such a zero is absent.
	 */
	public function unanswered_vacates( int $account_id, array $link_ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $link_ids ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The flusher must read fresh.
			$this->db->prepare(
				'SELECT link_id, warehouse_id FROM %i WHERE account_id = %d AND kind = %s AND pending_payload = %s AND (sent_payload IS NULL OR pending_payload <> sent_payload OR in_flight_rev IS NOT NULL) AND attempts = 0 AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->table, $account_id, Sync_State_Store::KIND_STOCK, self::vacate_json() ], $ids )
			),
			'ARRAY_A'
		);

		$warehouses = [];

		foreach ( (array) $rows as $row ) {
			$warehouses[ (int) $row['link_id'] ][] = (int) $row['warehouse_id'];
		}

		return $warehouses;
	}

	/**
	 * Freezes the newest pending payload of a row as the one being sent (one statement, so a save in between cannot split it).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key   Row key: `account_id`, `link_id`, `warehouse_id`, `kind`.
	 * @param string              $token The flusher's account claim.
	 * @return array{payload:array<string,mixed>,rev:int}|null Null when the claim was lost or the row has nothing pending.
	 */
	public function take( array $key, string $token ): ?array {
		if ( ! $this->fenced( $key, $token, 'in_flight_payload = pending_payload, in_flight_rev = pending_rev', [] ) ) {
			return null;
		}

		$row = $this->row( $key, 'in_flight_payload, in_flight_rev' );

		return null === $row || null === $row['in_flight_payload'] ? null : [
			'payload' => self::decode( $row['in_flight_payload'] ),
			'rev'     => (int) $row['in_flight_rev'],
		];
	}

	/**
	 * Records that the marketplace accepted the in-flight revision: it becomes the sent one and the backoff is cleared.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed>      $key   Row key.
	 * @param int                      $rev   The in-flight revision the answer was for.
	 * @param string                   $token The flusher's account claim.
	 * @param array<string,mixed>|null $sent  What the marketplace now holds when it is only part of the in-flight payload (a zero
	 *                                        whose archive is still to come); null = the whole in-flight payload. A part keeps
	 *                                        the row due, so the rest of the intent is resumed by the next run, and keeps
	 *                                        the failure count.
	 * @return bool False when the claim was lost or another revision is in flight.
	 */
	public function acknowledge( array $key, int $rev, string $token, ?array $sent = null ): bool {
		$set  = null === $sent ? 'sent_payload = in_flight_payload' : 'sent_payload = %s';
		$args = null === $sent ? [] : [ (string) json_encode( $sent ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.

		// A part of the intent (the zero of an archive) keeps the failure count: the archive is still unresolved, and clearing the
		// count here would let a refused archive start over on every retry (PR #57 critic finding 2).
		return $this->fenced(
			$key,
			$token,
			$set . ', sent_rev = in_flight_rev, sent_at = %s, ' . ( null === $sent ? 'attempts = 0, ' : '' ) . 'next_attempt_at = NULL, last_error = NULL, in_flight_payload = NULL, in_flight_rev = NULL',
			array_merge( $args, [ $this->time( 0 ) ] ),
			$this->db->prepare( ' AND in_flight_rev = %d', $rev )
		);
	}

	/**
	 * Arms a failed pair again (an explicit retry from the merchant or support): its counted failures and its wait are cleared.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key Row key.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function retry( array $key ): void {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$this->db->prepare( 'UPDATE %i SET attempts = 0, next_attempt_at = NULL WHERE account_id = %d AND link_id = %d AND warehouse_id = %d AND kind = %s', $this->table, (int) $key['account_id'], (int) $key['link_id'], (int) $key['warehouse_id'], (string) $key['kind'] )
		);

		if ( false === $changed ) {
			throw new \RuntimeException( 'The pair could not be armed again: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Arms a pair again only when it is failed; one that is still backing off keeps its wait.
	 *
	 * @since 2.1.25
	 *
	 * @param array<string,mixed> $key Row key.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function retry_failed( array $key ): void {
		if ( (int) ( $this->row( $key, 'attempts' )['attempts'] ?? 0 ) >= $this->cadence->max_attempts() ) {
			$this->retry( $key );
		}
	}

	/**
	 * Postpones a row. The in-flight snapshot is left as it is: after an uncertain outcome the next run sends the newest pending
	 * payload, which with one sender per account and absolute writes is the last one the marketplace applies (spec 5.3).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key           Row key.
	 * @param int                 $seconds       Seconds to wait.
	 * @param string              $error         Reason, shown in diagnostics; '' keeps the last one.
	 * @param bool                $count_attempt False for a wait that is not a failure (a rate limit, spec 4.1 c).
	 * @param string              $token         The flusher's account claim.
	 * @return bool False when the claim was lost.
	 */
	public function defer( array $key, int $seconds, string $error, bool $count_attempt, string $token ): bool {
		$set  = 'next_attempt_at = %s' . ( $count_attempt ? ', attempts = CASE WHEN attempts < 255 THEN attempts + 1 ELSE 255 END' : '' );
		$args = [ $this->time( max( 1, $seconds ) ) ];

		if ( '' !== $error ) {
			$set   .= ', last_error = %s';
			$args[] = $error;
		}

		return $this->fenced( $key, $token, $set, $args );
	}

	/**
	 * Holds a row whose warehouse cannot be written to: it waits and goes to the back of the due list, so that many held rows
	 * never keep the others out of a limited list. Not a failure.
	 *
	 * @since 2.1.26
	 *
	 * @param array<string,mixed> $key     Row key.
	 * @param int                 $seconds Seconds to wait.
	 * @param string              $reason  Reason, shown in diagnostics; '' shows none.
	 * @param string              $token   The flusher's account claim.
	 * @return bool False when the claim was lost.
	 */
	public function hold( array $key, int $seconds, string $reason, string $token ): bool {
		return $this->fenced( $key, $token, "next_attempt_at = %s, pending_since = %s, last_error = NULLIF(%s, '')", [ $this->time( max( 1, $seconds ) ), $this->time( 0 ), $reason ] );
	}

	/**
	 * Drops what a row wants sent, keeping the reason (a card archived on the marketplace takes no more writes).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key   Row key.
	 * @param string              $error Reason.
	 * @param string              $token The flusher's account claim.
	 * @return bool False when the claim was lost.
	 */
	public function drop( array $key, string $error, string $token ): bool {
		return $this->fenced( $key, $token, 'pending_payload = NULL, in_flight_payload = NULL, in_flight_rev = NULL, next_attempt_at = NULL, last_error = %s', [ $error ] );
	}

	/**
	 * Drops a left warehouse's zero because the product is back there. Only while the zero is still what the row wants: a value
	 * moved onto the row since the caller read it stays pending.
	 *
	 * @since 2.1.25
	 *
	 * @param array<string,mixed> $key   Row key.
	 * @param string              $token The flusher's account claim.
	 * @return bool True when the zero was dropped.
	 */
	public function cancel_vacate( array $key, string $token ): bool {
		return $this->fenced( $key, $token, 'pending_payload = NULL, next_attempt_at = NULL', [], $this->db->prepare( ' AND pending_payload = %s', self::vacate_json() ) );
	}

	/**
	 * The pending column of a left warehouse's zero, as {@see Sync_State_Store::record_vacate()} writes it.
	 *
	 * @return string
	 */
	private static function vacate_json(): string {
		return (string) json_encode( Sync_State_Store::VACATE_PAYLOAD ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
	}

	/**
	 * Makes a row due again although its pending payload equals the sent one: the readback found the marketplace holding another
	 * value, so the value must be sent again.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key   Row key.
	 * @param string              $token The account claim of the run.
	 * @return bool False when the claim was lost or the row does not exist.
	 */
	public function require_resend( array $key, string $token ): bool {
		return $this->fenced( $key, $token, 'sent_payload = NULL, next_attempt_at = NULL', [] );
	}

	/**
	 * Retires every row of a link (stock of any warehouse, and the price): what was wanted, what was sent, the readback and the
	 * errors. A link that is tied to another card afterwards starts with a clean slate, so an acknowledgement of the old card can never
	 * stand for the new one. Only the holder of the account claim may do it: an in-flight sender holds that claim, so it cannot
	 * acknowledge into rows that are gone, and a caller that could not take the claim changes nothing.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param int    $link_id    Link id.
	 * @param string $token      The account claim.
	 * @return int Rows retired; 0 also when the claim is not held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function forget_link( int $account_id, int $link_id, string $token ): int {
		$sql = $this->db->prepare(
			'DELETE FROM %i WHERE account_id = %d AND link_id = %d AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %d AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
			$this->table,
			$account_id,
			$link_id,
			$this->accounts,
			$account_id,
			$token,
			$this->time( 0 )
		);

		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( false === $deleted ) {
			throw new \RuntimeException( 'The sync_state rows of a link could not be retired: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return (int) $deleted;
	}

	/**
	 * Stores the last readback of a stock row.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key      Row key.
	 * @param int                 $present  Units on the warehouse.
	 * @param int                 $reserved Units reserved.
	 * @param string              $token    The account claim of the run.
	 * @return bool False when the claim was lost or the row does not exist.
	 */
	public function remember_remote( array $key, int $present, int $reserved, string $token ): bool {
		return $this->fenced( $key, $token, 'remote_present = %d, remote_reserved = %d, remote_read_at = %s', [ $present, $reserved, $this->time( 0 ) ] );
	}

	/**
	 * Stores the readback of a stock pair, creating the row when the pair has none. A row made here carries only the readback:
	 * no pending, in-flight or sent intent, so nothing is queued for the marketplace.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,mixed> $key      Row key.
	 * @param int                 $present  Units on the warehouse.
	 * @param int                 $reserved Units reserved.
	 * @param string              $token    The account claim of the run.
	 * @return bool False when the claim was lost.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public function record_remote( array $key, int $present, int $reserved, string $token ): bool {
		if ( $this->remember_remote( $key, $present, $reserved, $token ) ) {
			return true;
		}

		if ( ! $this->claim_held( (int) $key['account_id'], $token ) ) {
			return false;
		}

		$now = $this->time( 0 );
		$sql = $this->db->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed text and placeholders only.
			'INSERT IGNORE INTO %i (account_id, link_id, warehouse_id, kind, remote_present, remote_reserved, remote_read_at) SELECT %d, %d, %d, %s, %d, %d, %s FROM %i AS claim_owner WHERE claim_owner.account_id = %d AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s',
			[ $this->table, (int) $key['account_id'], (int) $key['link_id'], (int) $key['warehouse_id'], (string) $key['kind'], $present, $reserved, $now, $this->accounts, (int) $key['account_id'], $token, $now ]
		);

		$inserted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( false === $inserted ) {
			throw new \RuntimeException( 'A sync_state write was refused by the database: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		// A row another writer made in between is updated instead.
		return 1 === $inserted || $this->remember_remote( $key, $present, $reserved, $token );
	}

	/**
	 * Stores what an accepted send left on the marketplace: the sent quantity, read now. The reserved units are not known from a
	 * send and stay as they were.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,mixed> $key     Row key.
	 * @param int                 $present Units the marketplace accepted.
	 * @param string              $token   The account claim of the run.
	 * @return bool False when the claim was lost or the row does not exist.
	 */
	public function remember_sent( array $key, int $present, string $token ): bool {
		return $this->fenced( $key, $token, 'remote_present = %d, remote_read_at = %s', [ $present, $this->time( 0 ) ] );
	}

	/**
	 * Forgets what a warehouse held, once its zero was accepted: the screens read a link's newest readback, and a warehouse the
	 * product has left must not stand for the product.
	 *
	 * @since 2.1.25
	 *
	 * @param array<string,mixed> $key   Row key.
	 * @param string              $token The account claim of the run.
	 * @return bool False when the claim was lost or the row does not exist.
	 */
	public function forget_remote( array $key, string $token ): bool {
		return $this->fenced( $key, $token, 'remote_present = NULL, remote_reserved = NULL, remote_read_at = NULL', [] );
	}

	/**
	 * Lists the warehouses where the marketplace may hold units of a link ({@see self::may_hold_stock()}).
	 *
	 * @since 2.1.25
	 *
	 * @param int $account_id Account id.
	 * @param int $link_id    Link id.
	 * @return int[] Marketplace warehouse ids.
	 */
	public function warehouses_with_stock( int $account_id, int $link_id ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read fresh.
			$this->db->prepare( 'SELECT warehouse_id, sent_payload, in_flight_rev, remote_present FROM %i WHERE account_id = %d AND link_id = %d AND kind = %s AND warehouse_id > 0 ORDER BY warehouse_id ASC', $this->table, $account_id, $link_id, Sync_State_Store::KIND_STOCK ),
			'ARRAY_A'
		);

		$warehouses = [];

		foreach ( (array) $rows as $row ) {
			$row['sent_payload'] = null === $row['sent_payload'] ? null : self::decode( $row['sent_payload'] );

			if ( self::may_hold_stock( $row ) ) {
				$warehouses[] = (int) $row['warehouse_id'];
			}
		}

		return $warehouses;
	}

	/**
	 * Tells whether the marketplace may hold units on a stock row's warehouse: a send is unresolved, or the last accepted or the
	 * last read quantity is above zero. A value that was only wanted and never sent says nothing about the marketplace.
	 *
	 * @since 2.1.25
	 *
	 * @param array<string,mixed> $row Row with `sent_payload` (decoded), `in_flight_rev` and `remote_present`.
	 * @return bool
	 */
	public static function may_hold_stock( array $row ): bool {
		return null !== $row['in_flight_rev']
			|| (int) ( $row['sent_payload']['stock'] ?? 0 ) > 0
			|| (int) $row['remote_present'] > 0;
	}

	/**
	 * Returns a row's readback and send columns.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key Row key.
	 * @return array<string,mixed>|null `pending_payload`, `sent_payload` and `in_flight_payload` decoded (null when empty).
	 */
	public function get( array $key ): ?array {
		$row = $this->row( $key, '*' );

		if ( null === $row ) {
			return null;
		}

		foreach ( [ 'pending_payload', 'in_flight_payload', 'sent_payload' ] as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : self::decode( $row[ $column ] );
		}

		return $row;
	}

	/**
	 * Returns the reservation the last readback found, or null when the pair was never read.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $key Row key.
	 * @return int|null
	 */
	public function remote_reserved( array $key ): ?int {
		$row = $this->row( $key, 'remote_reserved' );

		return null === $row || null === $row['remote_reserved'] ? null : (int) $row['remote_reserved'];
	}

	/**
	 * Builds a row key.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id   Account id.
	 * @param int    $link_id      Link id.
	 * @param int    $warehouse_id Warehouse (0 for price).
	 * @param string $kind         Kind.
	 * @return array{account_id:int,link_id:int,warehouse_id:int,kind:string}
	 */
	public static function key( int $account_id, int $link_id, int $warehouse_id, string $kind ): array {
		return [
			'account_id'   => $account_id,
			'link_id'      => $link_id,
			'warehouse_id' => $warehouse_id,
			'kind'         => $kind,
		];
	}

	/**
	 * Runs an UPDATE of one row that only the holder of the account claim may make.
	 *
	 * @param array<string,mixed> $key      Row key.
	 * @param string              $token    Account claim.
	 * @param string              $set      `SET` fragment of fixed text and placeholders.
	 * @param array<int,mixed>    $set_args Values of `$set`.
	 * @param string              $extra    Further `WHERE` conditions, already prepared.
	 * @return bool True when the row changed, or when it already held the values while the claim is held.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	private function fenced( array $key, string $token, string $set, array $set_args, string $extra = '' ): bool {
		$now = $this->time( 0 );
		$sql = $this->db->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- Only fixed fragments and placeholders are concatenated.
			'UPDATE %i SET ' . $set . ' WHERE account_id = %d AND link_id = %d AND warehouse_id = %d AND kind = %s AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %d AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
			array_merge( [ $this->table ], $set_args, [ (int) $key['account_id'], (int) $key['link_id'], (int) $key['warehouse_id'], (string) $key['kind'], $this->accounts, (int) $key['account_id'], $token, $now ] )
		);

		$changed = $this->db->query( $sql . $extra ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( false === $changed ) {
			throw new \RuntimeException( 'A sync_state write was refused by the database: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( 1 === $changed ) {
			return true;
		}

		// MySQL counts a row whose new values equal the old ones as unchanged; then the row and the claim decide.
		return 0 === $changed && '' === $extra && null !== $this->row( $key, '1 AS found' ) && $this->claim_held( (int) $key['account_id'], $token );
	}

	/**
	 * Tells whether the token still owns the account row.
	 *
	 * @param int    $account_id Account id.
	 * @param string $token      Token.
	 * @return bool
	 */
	private function claim_held( int $account_id, string $token ): bool {
		return null !== $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A claim must be read fresh.
			$this->db->prepare( 'SELECT 1 FROM %i WHERE account_id = %d AND claim_token = %s AND claim_until > %s', $this->accounts, $account_id, $token, $this->time( 0 ) )
		);
	}

	/**
	 * Reads columns of one row.
	 *
	 * @param array<string,mixed> $key     Row key.
	 * @param string              $columns Column list (fixed text).
	 * @return array<string,mixed>|null
	 */
	private function row( array $key, string $columns ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read fresh.
			$this->db->prepare( 'SELECT ' . $columns . ' FROM %i WHERE account_id = %d AND link_id = %d AND warehouse_id = %d AND kind = %s', $this->table, (int) $key['account_id'], (int) $key['link_id'], (int) $key['warehouse_id'], (string) $key['kind'] ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column list is fixed text.
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Formats now plus an offset as the UTC datetime the columns hold.
	 *
	 * @param int $offset Seconds.
	 * @return string
	 */
	private function time( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) + $offset );
	}

	/**
	 * Decodes a JSON payload column.
	 *
	 * @param mixed $value Column value.
	 * @return array<string,mixed>
	 */
	private static function decode( $value ): array {
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : [];
	}
}
