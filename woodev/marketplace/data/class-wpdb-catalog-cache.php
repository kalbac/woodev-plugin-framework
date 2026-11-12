<?php
/**
 * Cache of a marketplace's catalogue answers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Key / payload store over the `catalog_cache` table. The category tree is a few MB of JSON, so it lives in
 * a table row (gzip JSON in a longblob) rather than in `wp_options`. The cache is account-agnostic: a marketplace's
 * categories, types and attribute ids are global, not per cabinet.
 */
final class Wpdb_Catalog_Cache {
	public const KEY_TREE = 'tree';

	private const FETCHED_PREFIX = 'fetched:';
	private const GZIP_MAGIC     = "\x1f\x8b";

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the catalogue cache table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Clock returning a Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the cache.
	 *
	 * @param \wpdb         $db    Database.
	 * @param string        $table Full name of the catalogue cache table.
	 * @param callable|null $clock `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $table;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Deletes the rows an account owns. A key that belongs to one account ends with its id as the last `:` segment
	 * (`warehouses:7`, `fetched:warehouses:7`); the shared keys (`tree`) do not.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return int Rows deleted.
	 */
	public function delete_account( int $account_id ): int {
		$sql     = $this->db->prepare( "DELETE FROM {$this->table} WHERE cache_key LIKE %s", '%' . $this->db->esc_like( ':' . $account_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours.
		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

		if ( false === $deleted ) {
			throw new \RuntimeException( 'The cached rows of the account could not be deleted.' );
		}

		return (int) $deleted;
	}

	/**
	 * Returns the cache key of the attributes of a category.
	 *
	 * The key is made of the descriptor's values taken in the order of its sorted field names, so the same descriptor always
	 * gives the same key whatever order the adapter built it in (a two-id descriptor `{ description_category_id, type_id }` gives
	 * `attributes:<category>:<type>`; a one-id descriptor gives `attributes:<id>`).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,int|string> $category The adapter's category descriptor (`Mapping::remote_category()`).
	 * @return string
	 */
	public static function attributes_key( array $category ): string {
		ksort( $category );

		return 'attributes:' . implode( ':', array_map( 'strval', $category ) );
	}

	/**
	 * Reads a payload.
	 *
	 * @param string $key Cache key.
	 * @return array<mixed>|null Null when there is no row or it cannot be decoded (a damaged row is a miss).
	 */
	public function get( string $key ): ?array {
		$row = $this->row( $key );
		if ( null === $row ) {
			return null;
		}

		return self::decode( (string) $row['payload'] );
	}

	/**
	 * Tells when a key was really fetched. Freshness reads this, never the write revision, which can run ahead of the clock.
	 * It is kept in its own `fetched:{key}` row, written after the data row.
	 *
	 * @param string $key Cache key.
	 * @return int|null Unix time, null when the key was never fetched.
	 */
	public function fetched_at( string $key ): ?int {
		return $this->time_of( self::FETCHED_PREFIX . $key );
	}

	/**
	 * Returns the write revision of a key: the `fetched_at` of its data row, strictly increasing with every successful
	 * conditional write. It is only for {@see self::replace_if_unchanged()}.
	 *
	 * @param string $key Cache key.
	 * @return int|null Revision, null when there is no row.
	 */
	public function revision( string $key ): ?int {
		return $this->time_of( $key );
	}

	/**
	 * Writes a payload, replacing an earlier one.
	 *
	 * @param string       $key     Cache key.
	 * @param array<mixed> $payload Payload.
	 * @return void
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function put( string $key, array $payload ): void {
		$written = $this->db->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[
				'cache_key'  => $key,
				'payload'    => self::encode( $payload ),
				'fetched_at' => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ),
			],
			[ '%s', '%s', '%s' ]
		);

		if ( false === $written ) {
			throw new \RuntimeException( 'The catalogue cache row ' . $key . ' could not be written.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->mark_fetched( $key );
	}

	/**
	 * Writes a payload only if the row is still the one the caller saw before its request: absent when `$seen` is null,
	 * otherwise written at `$seen`. The condition is part of the SQL (an `UPDATE ... WHERE fetched_at = seen`, or an
	 * `INSERT` that the primary key refuses), so a slow older refresh cannot overwrite what a newer one stored meanwhile.
	 *
	 * The revision is `fetched_at`, made strictly increasing: a write is stamped `max( now, seen + 1 )`, so no two
	 * successful writes of a key ever carry the same revision, even inside one second. (The stamp can run a few seconds
	 * ahead of the clock under bursts of writes; it only ever reads as "fresh".)
	 *
	 * @param string       $key     Cache key.
	 * @param array<mixed> $payload Payload.
	 * @param int|null     $seen    {@see self::revision()} read before the request that produced the payload.
	 * @return bool False when another refresh got there first; the caller should read the newer row.
	 * @throws \RuntimeException When the database refuses the write for another reason than a lost race.
	 */
	public function replace_if_unchanged( string $key, array $payload, ?int $seen ): bool {
		$now = (int) call_user_func( $this->clock );
		$row = [
			'payload'    => self::encode( $payload ),
			'fetched_at' => gmdate( 'Y-m-d H:i:s', null === $seen ? $now : max( $now, $seen + 1 ) ),
		];

		if ( null === $seen ) {
			return $this->insert_if_absent( $key, $row ) && $this->mark_fetched( $key );
		}

		$changed = $this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			$row,
			[
				'cache_key'  => $key,
				'fetched_at' => gmdate( 'Y-m-d H:i:s', $seen ),
			],
			[ '%s', '%s' ],
			[ '%s', '%s' ]
		);

		if ( false === $changed ) {
			throw new \RuntimeException( 'The catalogue cache row ' . $key . ' could not be updated.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $changed > 0 && $this->mark_fetched( $key );
	}

	/**
	 * Inserts a row nobody has written yet. A refused insert is a lost race only if the competing row exists; otherwise
	 * the database failed and nothing is cached, which must not look like a finished refresh.
	 *
	 * @param string              $key Cache key.
	 * @param array<string,mixed> $row Payload and `fetched_at`.
	 * @return bool False when another refresh inserted the row first.
	 * @throws \RuntimeException When the insert failed and no row exists.
	 */
	private function insert_if_absent( string $key, array $row ): bool {
		$this->db->suppress_errors( true );
		$written = $this->db->insert( $this->table, [ 'cache_key' => $key ] + $row, [ '%s', '%s', '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->db->suppress_errors( false );

		if ( 1 === $written ) {
			return true;
		}

		if ( null === $this->revision( $key ) ) {
			throw new \RuntimeException( 'The catalogue cache row ' . $key . ' could not be inserted.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return false;
	}

	/**
	 * Records the real fetch time of a key after its data row was written. A failed mark only makes the key look stale,
	 * which costs one extra fetch.
	 *
	 * @param string $key Cache key.
	 * @return bool Always true, so a publication chains on it.
	 */
	private function mark_fetched( string $key ): bool {
		$this->db->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[
				'cache_key'  => self::FETCHED_PREFIX . $key,
				'payload'    => '',
				'fetched_at' => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ),
			],
			[ '%s', '%s', '%s' ]
		);

		return true;
	}

	/**
	 * Reads the time column of a row.
	 *
	 * @param string $row_key Row key.
	 * @return int|null
	 */
	private function time_of( string $row_key ): ?int {
		$row = $this->row( $row_key );
		if ( null === $row ) {
			return null;
		}

		$time = strtotime( (string) $row['fetched_at'] . ' UTC' );

		return false === $time ? null : $time;
	}

	/**
	 * Reads one row.
	 *
	 * @param string $key Cache key.
	 * @return array<string,mixed>|null
	 */
	private function row( string $key ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'SELECT payload, fetched_at FROM %i WHERE cache_key = %s', $this->table, $key ),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Serialises a payload: gzip JSON, plain JSON where zlib is missing.
	 *
	 * @param array<mixed> $payload Payload.
	 * @return string
	 */
	private static function encode( array $payload ): string {
		$json = (string) wp_json_encode( $payload );

		return function_exists( 'gzencode' ) ? (string) gzencode( $json ) : $json;
	}

	/**
	 * Reads a stored payload back; recognises gzip by its magic bytes.
	 *
	 * @param string $stored Stored bytes.
	 * @return array<mixed>|null
	 */
	private static function decode( string $stored ): ?array {
		if ( 0 === strpos( $stored, self::GZIP_MAGIC ) ) {
			// A damaged row is a miss; gzdecode() warns about it, which must not reach the merchant's log.
			$stored = function_exists( 'gzdecode' ) ? (string) @gzdecode( $stored ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$decoded = json_decode( $stored, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
