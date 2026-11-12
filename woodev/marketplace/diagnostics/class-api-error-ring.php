<?php
/**
 * Ring buffer of failed marketplace calls.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Diagnostics;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
defined( 'ABSPATH' ) || exit;

/**
 * Writer for the table of the last failed calls of the site (`CAPACITY` rows). It feeds the diagnostics panel and the
 * support report, and it replaces the WooCommerce log pages that crashed on shops with large logs.
 *
 * The table has the columns `error_id` (auto increment), `account_id` (null for a site-wide line), `path`,
 * `http_status`, `code`, `message`, `trace_id`, `occurred_at` (UTC) and `context` (the job name). After every insert the
 * oldest rows beyond `CAPACITY` are deleted, so the ring never grows.
 *
 * The caller redacts secrets from the message first: the ring stores exactly what it is given, cut to the column size.
 *
 * @since 2.0.29
 */
final class Api_Error_Ring {
	public const CAPACITY       = 200;
	public const MESSAGE_LENGTH = 2000;

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
	 * Creates the writer.
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
	 * Deletes a batch of the failed calls recorded for an account.
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
	 * Writes one failed call and trims the ring.
	 *
	 * @since 2.0.29
	 *
	 * @param int|null $account_id  Account the call belonged to, null for none.
	 * @param string   $path        Method path.
	 * @param int      $http_status HTTP status, 0 when there was no answer.
	 * @param string   $code        Marketplace error code, or a plugin line such as `claim_lost`.
	 * @param string   $message     Message, secrets already removed.
	 * @param string   $trace_id    Marketplace trace id for support, '' for none.
	 * @param string   $context     Job name.
	 * @return void
	 */
	public function record( ?int $account_id, string $path, int $http_status, string $code, string $message, string $trace_id = '', string $context = '' ): void {
		$inserted = $this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[
				'account_id'  => $account_id,
				'path'        => substr( $path, 0, 191 ),
				'http_status' => max( 0, $http_status ),
				'code'        => substr( $code, 0, 64 ),
				'message'     => mb_substr( $message, 0, self::MESSAGE_LENGTH ),
				'trace_id'    => substr( $trace_id, 0, 64 ),
				'occurred_at' => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ),
				'context'     => substr( $context, 0, 64 ),
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false !== $inserted ) {
			$this->trim();
		}
	}

	/**
	 * Deletes the rows older than the newest `CAPACITY`.
	 *
	 * @return void
	 */
	private function trim(): void {
		$oldest_kept = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'SELECT error_id FROM %i ORDER BY error_id DESC LIMIT 1 OFFSET %d', $this->table, self::CAPACITY - 1 )
		);

		if ( null === $oldest_kept ) {
			return;
		}

		$this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( 'DELETE FROM %i WHERE error_id < %d', $this->table, (int) $oldest_kept )
		);
	}
}
