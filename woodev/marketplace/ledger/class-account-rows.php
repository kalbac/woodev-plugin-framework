<?php
/**
 * Counting and batch-deleting the rows of one account in an account-keyed table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Every table with an `account_id` column erases an account the same way: a bounded `DELETE … LIMIT`, repeated until it removes
 * nothing, so an account with tens of thousands of rows never holds one long statement. The stores' `delete_account()` and
 * `count_for_account()` are thin wrappers over this class, which keeps the SQL in one place.
 *
 * @since 2.1.5
 */
final class Account_Rows {
	/**
	 * Counts an account's rows.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb  $db         Database.
	 * @param string $table      Full table name (ours, never request input).
	 * @param int    $account_id Account id.
	 * @return int
	 */
	public static function count( \wpdb $db, string $table, int $account_id ): int {
		return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$table} WHERE account_id = %d", $account_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The table name is ours.
	}

	/**
	 * Deletes up to `$limit` rows of an account.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb  $db         Database.
	 * @param string $table      Full table name (ours, never request input).
	 * @param int    $account_id Account id.
	 * @param int    $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public static function delete( \wpdb $db, string $table, int $account_id, int $limit ): int {
		return self::delete_where( $db, $table, 'account_id = %d', [ $account_id ], $limit );
	}

	/**
	 * Deletes up to `$limit` rows that match a condition.
	 *
	 * For a table keyed by something other than `account_id` (the task items belong to a task, which belongs to the account).
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb        $db        Database.
	 * @param string       $table     Full table name (ours, never request input).
	 * @param string       $condition SQL condition with `%d` / `%s` placeholders; ours, never request input.
	 * @param array<mixed> $values    Values of the placeholders.
	 * @param int          $limit     Most rows to delete in this call.
	 * @return int Rows deleted; 0 when nothing matches.
	 * @throws \RuntimeException When the database refuses the statement.
	 */
	public static function delete_where( \wpdb $db, string $table, string $condition, array $values, int $limit ): int {
		$sql     = $db->prepare( "DELETE FROM {$table} WHERE {$condition} LIMIT %d", array_merge( $values, [ max( 1, $limit ) ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name and the condition are ours.
		$deleted = $db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

		if ( false === $deleted ) {
			throw new \RuntimeException( 'The rows of the account could not be deleted.' );
		}

		return (int) $deleted;
	}
}
