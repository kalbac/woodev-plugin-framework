<?php
/**
 * Database throttle store.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
defined( 'ABSPATH' ) || exit;

/**
 * Keeps governor buckets in a table with the columns `account_id`, `bucket`, `state` (JSON), `version`,
 * `disabled_until` (UTC datetime, null for none) and `disabled_reason`, primary key `(account_id, bucket)`.
 *
 * The swap is `UPDATE … SET version = version + 1 … WHERE … AND version = <the version the caller read>`: the version
 * moves on with every write, so the row count tells whether this caller won, whatever the clock says. A bucket is
 * created with `INSERT IGNORE` on the primary key, so two first requests cannot create two rows.
 *
 * @since 2.0.29
 */
final class Wpdb_Throttle_Store implements Throttle_Store {
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
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb  $db    Database.
	 * @param string $table Full table name.
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Deletes a batch of an account's governor buckets.
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
	 * Reads a bucket.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account id as text.
	 * @param string $bucket      Bucket key.
	 * @return Throttle_Row|null
	 */
	public function read( string $account_key, string $bucket ): ?Throttle_Row {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT state, version, disabled_until, disabled_reason FROM %i WHERE account_id = %d AND bucket = %s',
				$this->table,
				self::account_id( $account_key ),
				$bucket
			), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$state = json_decode( (string) $row['state'], true );

		return new Throttle_Row(
			is_array( $state ) ? $state : [],
			(int) $row['version'],
			self::to_timestamp( $row['disabled_until'] ),
			(string) $row['disabled_reason']
		);
	}

	/**
	 * Creates a bucket at version 0 unless it exists.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $account_key Account id as text.
	 * @param string              $bucket      Bucket key.
	 * @param array<string,mixed> $state       Initial state.
	 * @return void
	 */
	public function create( string $account_key, string $bucket, array $state ): void {
		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"INSERT IGNORE INTO %i (account_id, bucket, state, version, disabled_reason) VALUES (%d, %s, %s, 0, '')",
				$this->table,
				self::account_id( $account_key ),
				$bucket,
				(string) json_encode( $state ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
			)
		);
	}

	/**
	 * Replaces a bucket if its version is still the expected one.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $account_key      Account id as text.
	 * @param string              $bucket           Bucket key.
	 * @param int                 $expected_version Version the caller read.
	 * @param array<string,mixed> $state            New state.
	 * @param int|null            $disabled_until   Switch-off time, null for none.
	 * @param string              $disabled_reason  Switch-off reason.
	 * @return bool
	 */
	public function swap( string $account_key, string $bucket, int $expected_version, array $state, ?int $disabled_until, string $disabled_reason ): bool {
		$set  = 'state = %s, version = version + 1, disabled_reason = %s';
		$args = [ (string) json_encode( $state ), $disabled_reason ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The marketplace layer calls no WordPress function.
		if ( null === $disabled_until ) {
			$set .= ', disabled_until = NULL';
		} else {
			$set   .= ', disabled_until = %s';
			$args[] = gmdate( 'Y-m-d H:i:s', $disabled_until );
		}

		array_unshift( $args, $this->table );
		array_push( $args, self::account_id( $account_key ), $bucket, $expected_version );

		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$this->db->prepare( 'UPDATE %i SET ' . $set . ' WHERE account_id = %d AND bucket = %s AND version = %d', $args )
		);

		return 1 === $changed;
	}

	/**
	 * Raises a bucket's switch-off time to at least the given moment with one atomic upsert.
	 *
	 * `GREATEST()` makes the statement a monotonic maximum, so concurrent writers need no version check and none can
	 * lose: the latest deadline wins whatever the order. The deadline is written twice because `VALUES()` is deprecated
	 * in MySQL 8; `COALESCE` covers a row whose time is NULL (`GREATEST` of a NULL is NULL).
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account id as text.
	 * @param string $bucket      Bucket key.
	 * @param int    $until       Unix time to hold until.
	 * @return bool
	 */
	public function raise_until( string $account_key, string $bucket, int $until ): bool {
		$when = gmdate( 'Y-m-d H:i:s', $until );

		$result = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"INSERT INTO %i (account_id, bucket, state, version, disabled_until, disabled_reason) VALUES (%d, %s, '{}', 0, %s, '') ON DUPLICATE KEY UPDATE disabled_until = GREATEST(COALESCE(disabled_until, %s), %s)",
				$this->table,
				self::account_id( $account_key ),
				$bucket,
				$when,
				$when,
				$when
			)
		);

		return false !== $result;
	}

	/**
	 * Converts the account key to the numeric id the table stores.
	 *
	 * @param string $account_key Account id as text.
	 * @return int
	 * @throws \InvalidArgumentException When the key is not a whole number.
	 */
	private static function account_id( string $account_key ): int {
		if ( ! ctype_digit( $account_key ) ) {
			throw new \InvalidArgumentException( 'The throttle store keys its rows by the numeric account id.' );
		}

		return (int) $account_key;
	}

	/**
	 * Converts a UTC datetime column to a Unix time.
	 *
	 * @param mixed $value Column value, null or a `Y-m-d H:i:s` string.
	 * @return int|null
	 */
	private static function to_timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$time = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return false === $time ? null : $time->getTimestamp();
	}
}
