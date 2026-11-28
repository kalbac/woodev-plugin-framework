<?php
/**
 * The stock transaction inside a test's own transaction.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Sync\Stock_Transaction;

/**
 * Shared by the integration tests of the order store. The WordPress test case runs every test in one transaction, and a
 * `START TRANSACTION` inside it would commit what the test made so far. A savepoint gives the code under test the same
 * all-or-nothing step on the same server without ending the outer one; everything else is {@see Stock_Transaction}'s own.
 */
trait Savepoint_Stock {
	private function savepoint_stock_transaction(): Stock_Transaction {
		global $wpdb;

		return new class( $wpdb ) extends Stock_Transaction {
			protected function begin(): bool {
				return $this->statement( 'SAVEPOINT woodev_stock' );
			}

			protected function commit(): bool {
				return $this->statement( 'RELEASE SAVEPOINT woodev_stock' );
			}

			protected function rollback(): void {
				$this->statement( 'ROLLBACK TO SAVEPOINT woodev_stock' );
			}

			private function statement( string $sql ): bool {
				global $wpdb;

				return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed text.
			}
		};
	}
}
