<?php
/**
 * The stock and price intents of `sync_state`.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
defined( 'ABSPATH' ) || exit;

/**
 * Writes the «what WooCommerce wants on the marketplace» side of a `sync_state` row (spec 3.1): the complete normalised
 * `pending_payload` and its `pending_rev`. Only the payload columns are touched; the in-flight and sent columns belong to the
 * single per-account flusher (cards W3-1 / W3-2).
 *
 * {@see self::record()} is idempotent: writing the payload a row already holds as pending changes nothing, so a retried writer
 * cannot inflate the revision. A different payload replaces the pending one and bumps the revision (collapse keeps the newest).
 *
 * @since 2.0.29
 */
final class Sync_State_Store {
	public const KIND_STOCK = 'stock';
	public const KIND_PRICE = 'price';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Clock: Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full table name.
	 * @param callable|null $clock `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $table;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Deletes a batch of an account's stock and price send state.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		return Account_Rows::delete( $this->db, $this->table, $account_id, $limit );
	}

	/**
	 * Records a pending payload for a pair.
	 *
	 * @since 2.0.29
	 *
	 * @param int                 $account_id   Account id.
	 * @param int                 $link_id      Link id.
	 * @param int                 $warehouse_id Warehouse (0 for price).
	 * @param string              $kind         `KIND_STOCK` or `KIND_PRICE`.
	 * @param array<string,mixed> $payload      Complete payload.
	 * @return bool True when the row now holds the payload as pending; false when another writer won the revision (nothing was
	 *              stored: the caller reloads its data and tries again, or reports the intent as not stored).
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function record( int $account_id, int $link_id, int $warehouse_id, string $kind, array $payload ): bool {
		$json = (string) json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
		$key  = [
			'account_id'   => $account_id,
			'link_id'      => $link_id,
			'warehouse_id' => $warehouse_id,
			'kind'         => $kind,
		];
		$row  = $this->pending( $key );

		if ( null !== $row && $json === $row['pending_payload'] ) {
			return true;
		}

		$now  = gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) );
		$rev  = $this->newest_rev( $account_id, $link_id, $kind ) + 1;
		$done = null === $row
			? $this->db->insert(
				$this->table,
				$key + [
					'pending_payload' => $json,
					'pending_rev'     => $rev,
					'pending_since'   => $now,
				]
			)
			: $this->db->update(
				$this->table,
				[
					'pending_payload' => $json,
					'pending_rev'     => $rev,
					'pending_since'   => $now,
					'attempts'        => 0, // A new value is a new try, also for a pair that had failed (spec 4.1 d).
				],
				$key + [ 'pending_rev' => (int) $row['pending_rev'] ]
			);

		if ( false === $done ) {
			// Two writers inserting the first row at once: the loser's INSERT fails on the primary key. That is a lost write, not a
			// database fault, when the row is there now.
			if ( null === $row && null !== $this->pending( $key ) ) {
				return false;
			}

			throw new \RuntimeException( 'The sync intent could not be stored: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		// The update is keyed on the revision this call read (spec 3.5): zero rows means another writer stored a newer revision in
		// between, so this payload is NOT pending. Saying true here would let the caller report an intent that was never stored.
		return 0 !== $done;
	}

	/**
	 * Moves a pending payload from a retired pair (another warehouse) into this pair, unless this pair already holds a pending payload
	 * that is newer: an intent recorded here after the source one is never overwritten (critic PR #53 round 2, finding 11). The
	 * order is the pending revision, not `pending_since` (one second): {@see self::record()} numbers the intents of a link across
	 * its warehouse rows, so the source replaces the destination only when its revision is the higher one, whether or not either
	 * was already sent (card #55, PR #57 critic finding 1). One
	 * statement each for the insert and the conditional update, so a save in between cannot be lost. Called before the source is
	 * retired, so a death between the two leaves the payload in both places, never in none (finding 12); repeating it is a no-op.
	 *
	 * @since 2.0.29
	 *
	 * @param int                 $account_id   Account id.
	 * @param int                 $link_id      Link id.
	 * @param int                 $warehouse_id Destination warehouse.
	 * @param string              $kind         Kind.
	 * @param array<string,mixed> $payload      The source's pending payload.
	 * @param string              $since        The source's `pending_since` (UTC datetime).
	 * @param int                 $rev          The source's `pending_rev`.
	 * @return void
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function move_in( int $account_id, int $link_id, int $warehouse_id, string $kind, array $payload, string $since, int $rev ): void {
		$json     = (string) json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
		$inserted = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'INSERT IGNORE INTO %i (account_id, link_id, warehouse_id, kind, pending_payload, pending_rev, pending_since) VALUES (%d, %d, %d, %s, %s, %d, %s)',
				$this->table,
				$account_id,
				$link_id,
				$warehouse_id,
				$kind,
				$json,
				$rev,
				$since
			)
		);

		$updated = 1 === $inserted ? 0 : $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'UPDATE %i SET pending_payload = %s, pending_rev = CASE WHEN pending_rev + 1 > %d THEN pending_rev + 1 ELSE %d END, pending_since = %s, attempts = 0 WHERE account_id = %d AND link_id = %d AND warehouse_id = %d AND kind = %s AND (pending_payload IS NULL OR pending_rev < %d)',
				$this->table,
				$json,
				$rev,
				$rev,
				$since,
				$account_id,
				$link_id,
				$warehouse_id,
				$kind,
				$rev
			)
		);

		if ( false === $inserted || false === $updated ) {
			throw new \RuntimeException( 'The moved sync intent could not be stored: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Reads the pending payload of a pair.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id   Account id.
	 * @param int    $link_id      Link id.
	 * @param int    $warehouse_id Warehouse (0 for price).
	 * @param string $kind         Kind.
	 * @return array{payload:array<string,mixed>,rev:int}|null
	 */
	public function pending_of( int $account_id, int $link_id, int $warehouse_id, string $kind ): ?array {
		$row = $this->pending(
			[
				'account_id'   => $account_id,
				'link_id'      => $link_id,
				'warehouse_id' => $warehouse_id,
				'kind'         => $kind,
			]
		);

		if ( null === $row ) {
			return null;
		}

		$payload = json_decode( (string) $row['pending_payload'], true );

		return [
			'payload' => is_array( $payload ) ? $payload : [],
			'rev'     => (int) $row['pending_rev'],
		];
	}

	/**
	 * Returns the highest pending revision among all rows (warehouses) of a link and kind.
	 *
	 * A new intent takes the next number after it, so revisions order the intents of one link across its warehouse rows, which
	 * a one-second `pending_since` cannot (card #55, PR #57 critic).
	 *
	 * @param int    $account_id Account id.
	 * @param int    $link_id    Link id.
	 * @param string $kind       Kind.
	 * @return int 0 when the link has no row.
	 */
	private function newest_rev( int $account_id, int $link_id, string $kind ): int {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'SELECT MAX(pending_rev) FROM %i WHERE account_id = %d AND link_id = %d AND kind = %s',
				$this->table,
				$account_id,
				$link_id,
				$kind
			)
		);
	}

	/**
	 * Reads what the screens show about links: what the marketplace holds and the last trouble. Read only.
	 *
	 * @since 2.0.29
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,array{stock:array<string,mixed>|null,price:array<string,mixed>|null}> By link id; a link with no row is absent. `stock`:
	 *         `present`, `reserved`, `read_at` (null until a readback), `last_error`, `next_attempt_at` (Unix time or null). `price`: `sent`
	 *         (the payload the marketplace accepted, or null), `pending`, `last_error`, `next_attempt_at`.
	 */
	public function overview( int $account_id, array $link_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$sql  = 'SELECT link_id, kind, remote_present, remote_reserved, remote_read_at, pending_payload, sent_payload, last_error, next_attempt_at FROM %i WHERE account_id = %d AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY remote_read_at ASC'; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
		$rows = $this->db->get_results( $this->db->prepare( $sql, array_merge( [ $this->table, $account_id ], $ids ) ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
		$out  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$link_id         = (int) $row['link_id'];
			$out[ $link_id ] = $out[ $link_id ] ?? [
				'stock' => null,
				'price' => null,
			];

			if ( self::KIND_STOCK === $row['kind'] ) {
				$out[ $link_id ]['stock'] = [
					'present'         => null === $row['remote_present'] ? null : (int) $row['remote_present'],
					'reserved'        => null === $row['remote_reserved'] ? null : (int) $row['remote_reserved'],
					'read_at'         => null === $row['remote_read_at'] ? null : (string) $row['remote_read_at'],
					'last_error'      => (string) $row['last_error'],
					'next_attempt_at' => self::timestamp( $row['next_attempt_at'] ),
				];
			} elseif ( self::KIND_PRICE === $row['kind'] ) {
				$out[ $link_id ]['price'] = [
					'sent'            => self::decoded( $row['sent_payload'] ),
					'pending'         => self::decoded( $row['pending_payload'] ),
					'last_error'      => (string) $row['last_error'],
					'next_attempt_at' => self::timestamp( $row['next_attempt_at'] ),
				];
			}
		}

		return $out;
	}

	/**
	 * Decodes a stored JSON payload.
	 *
	 * @param mixed $json Column value.
	 * @return array<string,mixed>|null
	 */
	private static function decoded( $json ): ?array {
		$value = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Converts a stored UTC time to a Unix time.
	 *
	 * @param mixed $value Column value.
	 * @return int|null
	 */
	private static function timestamp( $value ): ?int {
		$time = is_string( $value ) && '' !== $value ? strtotime( $value . ' UTC' ) : false;

		return false === $time ? null : $time;
	}

	/**
	 * Reads the pending columns of a row.
	 *
	 * @param array<string,int|string> $key Primary key.
	 * @return array<string,mixed>|null
	 */
	private function pending( array $key ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$this->db->prepare(
				'SELECT pending_payload, pending_rev FROM %i WHERE account_id = %d AND link_id = %d AND warehouse_id = %d AND kind = %s',
				$this->table,
				$key['account_id'],
				$key['link_id'],
				$key['warehouse_id'],
				$key['kind']
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}
}
