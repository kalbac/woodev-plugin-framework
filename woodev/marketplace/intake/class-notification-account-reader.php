<?php
/**
 * Reads accounts for the notification intake.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the account a notification belongs to and stores the registration state of its URL. It touches only the columns the
 * intake needs and never opens the secrets, so a request from the marketplace costs one indexed read.
 *
 * Every read throws {@see Account_Read_Exception} when the database reports an error, so «no such account» (null) always means
 * the database answered.
 *
 * @since 2.0.30
 */
final class Notification_Account_Reader {
	private const COLUMNS = 'account_id, external_id, is_enabled, seller_id, stock_mode, price_direction, orders_enabled, import_enabled, notification_token, notification_state';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the accounts table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Creates the reader.
	 *
	 * @since 2.0.30
	 *
	 * @param \wpdb  $db    Database.
	 * @param string $table Full name of the accounts table.
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Finds an account by id.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @return Notification_Account|null
	 * @throws Account_Read_Exception When the database refused the read.
	 */
	public function find( int $account_id ): ?Notification_Account {
		return $this->one(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE account_id = %d', $this->table, $account_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- COLUMNS is a constant.
		);
	}

	/**
	 * Finds the account a notification URL names: the id must exist and the token must match.
	 *
	 * @since 2.0.30
	 *
	 * @param int    $account_id Account id from the URL.
	 * @param string $token      Token from the URL.
	 * @return Notification_Account|null
	 * @throws Account_Read_Exception When the database refused the read.
	 */
	public function find_by_token( int $account_id, string $token ): ?Notification_Account {
		$account = $this->find( $account_id );

		return null !== $account && '' !== $account->token() && hash_equals( $account->token(), $token ) ? $account : null;
	}

	/**
	 * Finds the account a notification without a token belongs to, from the cabinet its body names.
	 *
	 * The cabinet identifier is unique and is tried first. A seller id that several accounts share is ambiguous and matches
	 * none, so an event is never filed under the wrong cabinet.
	 *
	 * @since 2.0.30
	 *
	 * @param string $owner The cabinet identifier or the seller id the body names.
	 * @return Notification_Account|null
	 * @throws Account_Read_Exception When the database refused the read.
	 */
	public function find_by_owner( string $owner ): ?Notification_Account {
		if ( '' === $owner ) {
			return null;
		}

		$by_identifier = $this->one(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE external_id = %s', $this->table, $owner ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- COLUMNS is a constant.
		);

		if ( null !== $by_identifier || ! ctype_digit( $owner ) ) {
			return $by_identifier;
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE seller_id = %d LIMIT 2', $this->table, (int) $owner ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- COLUMNS is a constant.
			'ARRAY_A'
		);
		$this->assert_read_succeeded();

		return is_array( $rows ) && 1 === count( $rows ) ? new Notification_Account( $rows[0] ) : null;
	}

	/**
	 * Lists every account.
	 *
	 * @since 2.0.30
	 *
	 * @return Notification_Account[]
	 * @throws Account_Read_Exception When the database refused the read.
	 */
	public function all(): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY account_id ASC', $this->table ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- COLUMNS is a constant.
			'ARRAY_A'
		);
		$this->assert_read_succeeded();

		return array_map(
			static function ( array $row ): Notification_Account {
				return new Notification_Account( $row );
			},
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * Stores the registration state of the account's URL (what the marketplace last said about it, and when it was asked).
	 *
	 * @since 2.0.30
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $state      State.
	 * @return void
	 */
	public function save_state( int $account_id, array $state ): void {
		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'UPDATE %i SET notification_state = %s WHERE account_id = %d',
				$this->table,
				(string) wp_json_encode( $state ),
				$account_id
			)
		);
	}

	/**
	 * Runs a query that returns at most one account.
	 *
	 * @param string $sql Prepared statement.
	 * @return Notification_Account|null
	 * @throws Account_Read_Exception When the database refused the read.
	 */
	private function one( string $sql ): ?Notification_Account {
		$row = $this->db->get_row( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller.
		$this->assert_read_succeeded();

		return is_array( $row ) ? new Notification_Account( $row ) : null;
	}

	/**
	 * Tells a failed read from an empty one. `wpdb` returns null for both, so a transient connection error would otherwise read
	 * as «no such account» and a valid notification would be filed as unknown and never processed.
	 *
	 * @return void
	 * @throws Account_Read_Exception When the last query failed.
	 */
	private function assert_read_succeeded(): void {
		$error = (string) $this->db->last_error;

		if ( '' !== $error ) {
			throw new Account_Read_Exception( 'The account could not be read: ' . $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A log message, not output.
		}
	}
}
