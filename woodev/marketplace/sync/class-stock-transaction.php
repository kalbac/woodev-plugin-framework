<?php
/**
 * One database transaction around a stock write and its accounting.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Couples a WooCommerce stock write with the accounting row that records it. Both are rows of the same database, so one
 * transaction makes them one fact: a process that dies between the two writes never commits, the server rolls both back when
 * the connection drops, and the retry starts from the state before the line. Everything WooCommerce writes inside — the product
 * save, the lookup table, the `sync_state` intent recorded by its stock hook — goes with them.
 *
 * Limits: tables on MyISAM ignore transactions (WordPress creates InnoDB tables by default); `START TRANSACTION` commits a
 * transaction the caller had open, so this is used only from background jobs, which open none.
 *
 * Not final: a test replaces it to show what happens without the transaction.
 *
 * @since 2.1.0
 */
class Stock_Transaction {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Creates the wrapper.
	 *
	 * @since 2.1.0
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Runs work in a transaction: commits when it returns, rolls back and rethrows when it throws.
	 *
	 * @since 2.1.0
	 *
	 * @param callable $work    `function (): mixed`.
	 * @param int[]    $touched Product ids whose caches are dropped after a rollback, so this process reads the stock again.
	 * @return mixed What the work returned.
	 * @throws \RuntimeException When the database refused to begin or commit.
	 * @throws \Throwable What the work threw.
	 */
	public function run( callable $work, array $touched = [] ) {
		if ( false === $this->db->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A transaction statement.
			throw new \RuntimeException( 'The stock transaction could not begin: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		try {
			$result = call_user_func( $work );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A transaction statement.
			self::forget( $touched );

			throw $e;
		}

		if ( false === $this->db->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A transaction statement.
			self::forget( $touched );

			throw new \RuntimeException( 'The stock transaction could not be committed: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $result;
	}

	/**
	 * Drops the cached stock of products a rolled-back transaction had written.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return void
	 */
	private static function forget( array $product_ids ): void {
		foreach ( $product_ids as $product_id ) {
			wp_cache_delete( (int) $product_id, 'post_meta' );
			clean_post_cache( (int) $product_id );
			\WC_Cache_Helper::invalidate_cache_group( 'product_' . (int) $product_id );
		}
	}
}
