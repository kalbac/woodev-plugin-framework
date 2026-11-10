<?php
/**
 * Database event ledger.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps inbound notifications in a table with the columns `event_id`, `account_id` (null allowed), `type`, `dedup_key`,
 * `received_at`, `processed_at`, `status`, `attempts`, `claim_token`, `claim_until`, `next_attempt_at`, `payload`,
 * `result`, `source_ip`, `ip_trusted`, and a unique index on `(account_id, dedup_key)` (the events table).
 *
 * Request time only calls {@see self::record()}: one `INSERT IGNORE`. Runners call {@see self::claim()}, which takes up
 * to a batch of due rows with one `UPDATE` that stamps a fresh token on every row it wins and then reads the batch back
 * by that token, so a partly won batch is exact (spec 6.3, 3.5). `complete()`, `retry()` and `fail()` carry the token in
 * their `WHERE`: a runner whose rows were reclaimed changes 0 rows and learns it lost them.
 *
 * Not final only so that a test can interleave a second claimer between the two halves of {@see self::claim()}.
 *
 * @since 2.0.29
 */
class Wpdb_Event_Ledger implements Event_Ledger {
	use \Woodev\Framework\Marketplace\Ledger\Claimable; // phpcs:ignore Universal.UseStatements.NoLeadingBackslash.LeadingBackslashFound -- The layer scanner reads a trait `use` as an import and wants the full name.

	public const STATUS_NEW        = 'new';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_RETRY      = 'retry';
	public const STATUS_DONE       = 'done';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_IGNORED    = 'ignored';

	/**
	 * Seconds a batch claim lasts before another runner may take the rows over.
	 */
	public const CLAIM_TTL = 120;

	/**
	 * Largest payload stored; a bigger one is kept as its scalar fields plus a marker (spec 3.1).
	 */
	public const PAYLOAD_CAP = 65536;

	/**
	 * Largest payload kept for an event that is stored but never processed. Such rows exist only for diagnostics, and
	 * anyone can create them by posting to the endpoint, so each one stays small.
	 */
	public const IGNORED_PAYLOAD_CAP = 4096;

	private const TEXT_CAP = 1000;

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full table name, prefix included.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Clock: Unix time in seconds.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the ledger.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full table name.
	 * @param callable|null $clock Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $table;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Stores an event; a duplicate (same account and dedup key) is not stored again.
	 *
	 * A database error is told apart from a duplicate by the error state of the database object, never by «0 rows»
	 * (spec 6.2 step 4): both leave nothing inserted.
	 *
	 * @since 2.0.29
	 *
	 * @param Inbound_Event $event Event to store.
	 * @return int|null New row id, or null for a duplicate.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function record( Inbound_Event $event ): ?int {
		$ignored = '' !== $event->ignore_reason();
		$account = $event->account_id();
		$values  = [
			$this->table,
			$event->type(),
			$event->dedup_key(),
			gmdate( 'Y-m-d H:i:s', $event->received_at()->getTimestamp() ),
			$ignored ? self::STATUS_IGNORED : self::STATUS_NEW,
			$this->encode_payload( $event->payload(), $ignored ? self::IGNORED_PAYLOAD_CAP : self::PAYLOAD_CAP ),
			self::clip( $event->ignore_reason() ),
			$event->source_ip(),
			$event->ip_trusted() ? 1 : 0,
		];
		if ( null !== $account ) {
			array_splice( $values, 1, 0, [ $account ] );
		}

		$result = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$this->db->prepare(
				'INSERT IGNORE INTO %i (account_id, type, dedup_key, received_at, status, payload, result, source_ip, ip_trusted) VALUES (' . ( null === $account ? 'NULL' : '%d' ) . ', %s, %s, %s, %s, %s, %s, %s, %d)',
				$values
			)
		);

		if ( false === $result || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'The event could not be stored: ' . self::clip( (string) $this->db->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A log message, not output.
		}

		return 0 === $result ? null : (int) $this->db->insert_id;
	}

	/**
	 * Claims up to `$limit` due events of an account with one token.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to claim.
	 * @return Claimed_Batch The token and the rows read back by that token.
	 */
	public function claim( int $account_id, int $limit ): Claimed_Batch {
		$ids = $this->due_ids( $account_id, $limit );
		if ( [] === $ids ) {
			return new Claimed_Batch( '', [] );
		}

		$token = $this->new_claim_token();

		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement; the ids are cast to integers.
			$this->db->prepare(
				"UPDATE %i SET status = 'processing', claim_token = %s, claim_until = %s, attempts = attempts + 1 WHERE event_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND account_id = %d AND ' . self::due_predicate(),
				$this->table,
				$token,
				$this->claim_clock( self::CLAIM_TTL ),
				$account_id,
				$this->claim_clock( 0 ),
				$this->claim_clock( 0 )
			)
		);

		return new Claimed_Batch( $token, $this->read_claimed( $token ) );
	}

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
	public function complete( int $event_id, string $token, string $result ): bool {
		return $this->update_if_claimed(
			$this->table,
			[ 'event_id' => $event_id ],
			$token,
			"status = 'done', processed_at = %s, next_attempt_at = NULL, result = %s",
			[ $this->claim_clock( 0 ), self::clip( $result ) ],
			true
		);
	}

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
	public function retry( int $event_id, string $token, int $delay, string $error ): bool {
		return $this->update_if_claimed(
			$this->table,
			[ 'event_id' => $event_id ],
			$token,
			"status = 'retry', next_attempt_at = %s, result = %s",
			[ $this->claim_clock( max( 0, $delay ) ), self::clip( $error ) ],
			true
		);
	}

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
	public function fail( int $event_id, string $token, string $error ): bool {
		return $this->update_if_claimed(
			$this->table,
			[ 'event_id' => $event_id ],
			$token,
			"status = 'failed', processed_at = %s, next_attempt_at = NULL, result = %s",
			[ $this->claim_clock( 0 ), self::clip( $error ) ],
			true
		);
	}

	/**
	 * Returns the database to the claim trait.
	 *
	 * @since 2.0.29
	 *
	 * @return \wpdb
	 */
	protected function claim_db(): \wpdb {
		return $this->db;
	}

	/**
	 * Returns the current Unix time to the claim trait.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	protected function claim_now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Selects the ids of up to `$limit` due events, oldest first. The first half of {@see self::claim()}.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most ids.
	 * @return int[]
	 */
	protected function due_ids( int $account_id, int $limit ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$this->db->prepare(
				'SELECT event_id FROM %i WHERE account_id = %d AND ' . self::due_predicate() . ' ORDER BY event_id ASC LIMIT %d',
				$this->table,
				$account_id,
				$this->claim_clock( 0 ),
				$this->claim_clock( 0 ),
				max( 1, $limit )
			),
			'ARRAY_A'
		);

		return array_map(
			static function ( array $row ): int {
				return (int) $row['event_id'];
			},
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * Reads back the rows a token won.
	 *
	 * @param string $token Claim token.
	 * @return Inbound_Event[]
	 */
	private function read_claimed( string $token ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT event_id, account_id, type, dedup_key, received_at, payload, source_ip, ip_trusted, attempts FROM %i WHERE claim_token = %s ORDER BY event_id ASC',
				$this->table,
				$token
			),
			'ARRAY_A'
		);

		return array_map( [ self::class, 'to_event' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Builds an event from a table row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Inbound_Event
	 */
	private static function to_event( array $row ): Inbound_Event {
		$payload  = json_decode( (string) $row['payload'], true );
		$received = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['received_at'], new \DateTimeZone( 'UTC' ) );

		return ( new Inbound_Event(
			null === $row['account_id'] ? null : (int) $row['account_id'],
			(string) $row['type'],
			(string) $row['dedup_key'],
			is_array( $payload ) ? $payload : [],
			false === $received ? new \DateTimeImmutable( '@0' ) : $received,
			(string) $row['source_ip'],
			(bool) $row['ip_trusted'],
			(int) $row['event_id']
		) )->with_attempts( (int) $row['attempts'] );
	}

	/**
	 * The rows a runner may take: new, a retry that is due, or a batch whose runner died (its lease expired).
	 *
	 * @return string `WHERE` fragment with two `%s` placeholders, both the current time.
	 */
	private static function due_predicate(): string {
		return "(status = 'new' OR (status = 'retry' AND next_attempt_at <= %s) OR (status = 'processing' AND claim_until < %s))";
	}

	/**
	 * Encodes a payload for the column, shrinking one over the cap to its scalar fields and a marker.
	 *
	 * @param array<string,mixed> $payload Decoded body.
	 * @param int                 $cap     Largest size in bytes.
	 * @return string
	 */
	private function encode_payload( array $payload, int $cap ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$json  = (string) json_encode( $payload, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.

		if ( strlen( $json ) <= $cap ) {
			return $json;
		}

		$kept                    = array_filter( $payload, 'is_scalar' );
		$kept['_truncated']      = true;
		$kept['_original_bytes'] = strlen( $json );
		$json                    = (string) json_encode( $kept, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.

		return strlen( $json ) <= $cap ? $json : (string) json_encode( [ '_truncated' => true ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
	}

	/**
	 * Cuts a text to the length the result column keeps, without splitting a character.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clip( string $text ): string {
		return 1 === preg_match( '/^.{0,' . self::TEXT_CAP . '}/us', $text, $matches ) ? $matches[0] : '';
	}
}
