<?php
/**
 * The price queue reads its interval and attempt cap from the cadence the adapter gives it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Price;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * D2 of the marketplace design. `due()` runs its real SQL on SQLite. The rows are fixed; the cadence changes, and with it the answer:
 * the 360 s / 5 attempts pair is the one the Ozon adapter passes in (donor parity), the others are values of a made-up marketplace.
 */
final class PriceSyncQueueCadenceTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Opens a database with one account and five price pairs in different states.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->db->query( 'INSERT INTO ' . Sqlite_Schema::ACCOUNTS . ' (account_id) VALUES (5)' );

		// 1: never sent. 2: sent 100 s ago. 3: sent 400 s ago. 4: five counted failures. 5: two counted failures.
		$this->seed( 1, null, 0, null );
		$this->seed( 2, self::NOW - 100, 0, null );
		$this->seed( 3, self::NOW - 400, 0, null );
		$this->seed( 4, null, 5, 1 );
		$this->seed( 5, null, 2, 1 );
	}

	/**
	 * Inserts a created link with price sync on and its price pair.
	 *
	 * @param int      $link_id   Link id.
	 * @param int|null $sent_at   Unix time of the last accepted send, null = never sent.
	 * @param int      $attempts  Counted failures of the current revision.
	 * @param int|null $in_flight Revision of the unresolved send, if any.
	 * @return void
	 */
	private function seed( int $link_id, ?int $sent_at, int $attempts, ?int $in_flight ): void {
		$this->db->query( 'INSERT INTO ' . Sqlite_Schema::LINKS . " (link_id, account_id, kind, card_state, sync_price) VALUES ({$link_id}, 5, 'offer', 'created', 1)" );

		$pending_since = gmdate( 'Y-m-d H:i:s', self::NOW - 1000 + $link_id );
		$sent_payload  = null === $sent_at ? 'NULL' : "'" . '{"price":"10"}' . "'";
		$sent_time     = null === $sent_at ? 'NULL' : "'" . gmdate( 'Y-m-d H:i:s', $sent_at ) . "'";
		$in_flight_rev = null === $in_flight ? 'NULL' : (string) $in_flight;

		$this->db->query(
			'INSERT INTO ' . Sqlite_Schema::SYNC_STATE . ' (account_id, link_id, warehouse_id, kind, pending_payload, pending_rev, pending_since, in_flight_rev, sent_payload, sent_at, attempts)'
			. " VALUES (5, {$link_id}, 0, 'price', '{\"price\":\"20\"}', 1, '{$pending_since}', {$in_flight_rev}, {$sent_payload}, {$sent_time}, {$attempts})"
		);
	}

	/**
	 * Builds the queue with a cadence.
	 *
	 * @param Sync_Cadence $cadence Cadence.
	 * @return Price_Sync_Queue
	 */
	private function queue( Sync_Cadence $cadence ): Price_Sync_Queue {
		return new Price_Sync_Queue(
			$this->db,
			Sqlite_Schema::SYNC_STATE,
			Sqlite_Schema::LINKS,
			Sqlite_Schema::ACCOUNTS,
			$cadence,
			static function (): int {
				return self::NOW;
			}
		);
	}

	/**
	 * Returns the link ids due under a cadence.
	 *
	 * @param Sync_Cadence $cadence Cadence.
	 * @return int[]
	 */
	private function due( Sync_Cadence $cadence ): array {
		$ids = array();
		foreach ( $this->queue( $cadence )->due( 5, 50 ) as $intent ) {
			$ids[] = $intent->link_id();
		}

		return $ids;
	}

	/**
	 * The pair the Ozon adapter passes in: a send 100 s ago still waits, one 400 s ago does not, five failures park the pair.
	 *
	 * @return void
	 */
	public function test_donor_parity_with_the_ozon_values(): void {
		$this->assertSame( array( 1, 3, 5 ), $this->due( new Sync_Cadence( 360, 5 ) ) );
	}

	/**
	 * No minimum interval: the pair sent 100 s ago is due as well.
	 *
	 * @return void
	 */
	public function test_without_a_minimum_interval_a_recent_send_is_due(): void {
		$this->assertSame( array( 1, 2, 3, 5 ), $this->due( new Sync_Cadence( 0, 5 ) ) );
	}

	/**
	 * A longer interval holds back the pair sent 400 s ago too.
	 *
	 * @return void
	 */
	public function test_a_longer_interval_holds_back_older_sends(): void {
		$this->assertSame( array( 1, 5 ), $this->due( new Sync_Cadence( 3600, 5 ) ) );
	}

	/**
	 * The attempt cap decides which failed pairs are still tried: two failures park the pair at a cap of two, and five are tried at six.
	 *
	 * @return void
	 */
	public function test_the_attempt_cap_decides_which_failed_pairs_are_tried(): void {
		$this->assertSame( array( 1, 3 ), $this->due( new Sync_Cadence( 360, 2 ) ) );
		$this->assertSame( array( 1, 3, 4, 5 ), $this->due( new Sync_Cadence( 360, 6 ) ) );
	}

	/**
	 * The runners wait out the same interval the queue enforces, so the queue hands the cadence back.
	 *
	 * @return void
	 */
	public function test_the_queue_hands_the_cadence_back(): void {
		$cadence = new Sync_Cadence( 360, 5 );

		$this->assertSame( $cadence, $this->queue( $cadence )->cadence() );
	}
}
