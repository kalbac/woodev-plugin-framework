<?php
/**
 * The stock queue reads its attempt cap from the cadence the adapter gives it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Stock;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * D3 of the marketplace design. `due()` runs its real SQL on SQLite over pairs that failed 0, 2 and 5 times; the cap decides which of
 * them are still selected. A cap of five with no minimum interval is what the Ozon adapter passes in for stock (donor parity).
 */
final class SyncStateQueueCadenceTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Opens a database with three stock pairs that failed 0, 2 and 5 times.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );

		foreach ( array( 1 => 0, 2 => 2, 3 => 5 ) as $link_id => $attempts ) {
			$this->db->query(
				'INSERT INTO ' . Sqlite_Schema::SYNC_STATE . ' (account_id, link_id, warehouse_id, kind, pending_payload, pending_rev, pending_since, attempts)'
				. " VALUES (5, {$link_id}, 9, 'stock', '{\"stock\":3}', 1, '2026-11-09 10:0{$link_id}:00', {$attempts})"
			);
		}
	}

	/**
	 * Returns the link ids the queue selects under a cadence.
	 *
	 * @param Sync_Cadence $cadence Cadence.
	 * @return int[]
	 */
	private function due( Sync_Cadence $cadence ): array {
		$queue = new Sync_State_Queue(
			$this->db,
			Sqlite_Schema::SYNC_STATE,
			Sqlite_Schema::ACCOUNTS,
			$cadence,
			static function (): int {
				return self::NOW;
			}
		);

		return array_map( 'intval', array_column( $queue->due( 5, Sync_State_Store::KIND_STOCK, 50 ), 'link_id' ) );
	}

	/**
	 * Donor parity: five counted failures park a pair.
	 *
	 * @return void
	 */
	public function test_donor_parity_with_the_ozon_values(): void {
		$this->assertSame( array( 1, 2 ), $this->due( new Sync_Cadence( 0, 5 ) ) );
	}

	/**
	 * A lower cap parks the pair that failed twice; a higher one tries the pair that failed five times.
	 *
	 * @return void
	 */
	public function test_the_cap_decides_which_failed_pairs_are_tried(): void {
		$this->assertSame( array( 1 ), $this->due( new Sync_Cadence( 0, 2 ) ) );
		$this->assertSame( array( 1, 2, 3 ), $this->due( new Sync_Cadence( 0, 6 ) ) );
	}

	/**
	 * The flusher counts its attempts against the same cap, so the queue hands the cadence back.
	 *
	 * @return void
	 */
	public function test_the_queue_hands_the_cadence_back(): void {
		$cadence = new Sync_Cadence( 0, 5 );
		$queue   = new Sync_State_Queue( $this->db, Sqlite_Schema::SYNC_STATE, Sqlite_Schema::ACCOUNTS, $cadence );

		$this->assertSame( $cadence, $queue->cadence() );
		$this->assertSame( 5, $queue->cadence()->max_attempts() );
	}
}
