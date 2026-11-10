<?php
/**
 * The event ledger and the claim mechanism: record, claim, fence.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Ledger;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the ledger's real SQL on SQLite with a clock the test moves. What this proves is spec 3.5 and 6.3: a claim is a
 * fence (an old owner changes 0 rows), a batch is read back by token, and a database error is not a duplicate.
 */
final class WpdbEventLedgerTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Ledger under test.
	 *
	 * @var Wpdb_Event_Ledger
	 */
	private $ledger;

	/**
	 * Current time of the ledger's clock.
	 *
	 * @var int
	 */
	private $now = self::NOW;

	/**
	 * Opens a database with the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = self::NOW;
		$this->db  = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->ledger = new Wpdb_Event_Ledger( $this->db, Sqlite_Schema::EVENTS, array( $this, 'clock' ) );
	}

	/**
	 * The clock the ledger reads.
	 *
	 * @return int
	 */
	public function clock(): int {
		return $this->now;
	}

	/**
	 * Builds an event.
	 *
	 * @param int|null            $account Account id.
	 * @param string              $key     Dedup key.
	 * @param array<string,mixed> $payload Payload.
	 * @return Inbound_Event
	 */
	private function event( ?int $account, string $key, array $payload = array( 'a' => 1 ) ): Inbound_Event {
		return new Inbound_Event( $account, 'TYPE_NEW_POSTING', $key, $payload, new \DateTimeImmutable( '@' . self::NOW ), '195.34.21.5', true );
	}

	/**
	 * Reads one column of the event rows.
	 *
	 * @param string $column Column.
	 * @return array<int,mixed>
	 */
	private function column( string $column ): array {
		return array_column( $this->db->rows( 'SELECT ' . $column . ' FROM ' . Sqlite_Schema::EVENTS . ' ORDER BY event_id' ), $column );
	}

	/**
	 * The same notification twice is stored once; the second record says «duplicate» with null.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_not_stored_again(): void {
		$first  = $this->ledger->record( $this->event( 5, 'k1' ) );
		$second = $this->ledger->record( $this->event( 5, 'k1' ) );

		$this->assertSame( 1, $first );
		$this->assertNull( $second );
		$this->assertCount( 1, $this->column( 'event_id' ) );
	}

	/**
	 * The unique key is per account: two cabinets with the same key keep both events.
	 *
	 * @return void
	 */
	public function test_the_same_key_in_two_accounts_is_two_events(): void {
		$this->assertNotNull( $this->ledger->record( $this->event( 5, 'k1' ) ) );
		$this->assertNotNull( $this->ledger->record( $this->event( 6, 'k1' ) ) );
	}

	/**
	 * A failed insert throws, so the endpoint can answer 500; it is not mistaken for a duplicate.
	 *
	 * @return void
	 */
	public function test_a_database_error_throws_and_is_not_a_duplicate(): void {
		$broken = new Wpdb_Event_Ledger( $this->db, 'wp_no_such_table', array( $this, 'clock' ) );

		$this->expectException( \RuntimeException::class );
		$broken->record( $this->event( 5, 'k1' ) );
	}

	/**
	 * An ignored event is stored with the reason and is never offered to a runner. Without an account it is stored too.
	 *
	 * @return void
	 */
	public function test_an_ignored_event_is_kept_for_diagnostics_and_never_claimed(): void {
		$this->ledger->record( $this->event( null, 'k1' )->with_ignore_reason( 'unknown account' ) );
		$this->ledger->record( $this->event( 5, 'k2' )->with_ignore_reason( 'invalid json' ) );

		$this->assertSame( array( 'ignored', 'ignored' ), $this->column( 'status' ) );
		$this->assertSame( array( 'unknown account', 'invalid json' ), $this->column( 'result' ) );
		$this->assertTrue( $this->ledger->claim( 5, 10 )->is_empty() );
	}

	/**
	 * The payload round-trips, and the claimed event carries its row id, the attempt count and the address flags.
	 *
	 * @return void
	 */
	public function test_a_claimed_event_carries_what_was_stored(): void {
		$this->ledger->record(
			$this->event(
				5,
				'k1',
				array(
					'posting_number' => '123-1',
					'items'          => array( 1, 2 ),
				)
			)
		);

		$batch = $this->ledger->claim( 5, 10 );
		$event = $batch->events()[0];

		$this->assertSame( 32, strlen( $batch->token() ) );
		$this->assertSame( 1, $event->event_id() );
		$this->assertSame( 1, $event->attempts() );
		$this->assertSame(
			array(
				'posting_number' => '123-1',
				'items'          => array( 1, 2 ),
			),
			$event->payload()
		);
		$this->assertSame( '195.34.21.5', $event->source_ip() );
		$this->assertTrue( $event->ip_trusted() );
		$this->assertSame( self::NOW, $event->received_at()->getTimestamp() );
	}

	/**
	 * A batch is the oldest due events, at most the limit, of the asked account only.
	 *
	 * @return void
	 */
	public function test_claim_takes_the_oldest_due_events_of_one_account(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$this->ledger->record( $this->event( 6, 'b' ) );
		$this->ledger->record( $this->event( 5, 'c' ) );
		$this->ledger->record( $this->event( 5, 'd' ) );

		$batch = $this->ledger->claim( 5, 2 );

		$this->assertSame( array( 1, 3 ), array_map( 'intval', array_map( array( $this, 'id_of' ), $batch->events() ) ) );
	}

	/**
	 * Returns an event's row id.
	 *
	 * @param Inbound_Event $event Event.
	 * @return int
	 */
	public function id_of( Inbound_Event $event ): int {
		return (int) $event->event_id();
	}

	/**
	 * A row that is claimed and still leased is not offered again.
	 *
	 * @return void
	 */
	public function test_a_leased_row_is_not_claimed_twice(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );

		$this->assertCount( 1, $this->ledger->claim( 5, 10 )->events() );
		$this->assertTrue( $this->ledger->claim( 5, 10 )->is_empty() );
	}

	/**
	 * A runner that died leaves `processing` rows; once the lease is over, the next runner takes them and the attempt
	 * count goes up.
	 *
	 * @return void
	 */
	public function test_an_expired_lease_is_taken_over(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$this->ledger->claim( 5, 10 );

		$this->now += Wpdb_Event_Ledger::CLAIM_TTL + 1;
		$again      = $this->ledger->claim( 5, 10 );

		$this->assertCount( 1, $again->events() );
		$this->assertSame( 2, $again->events()[0]->attempts() );
	}

	/**
	 * Spec 6.3 test: A selects rows 1 and 2, B wins row 1 first, A processes only row 2.
	 *
	 * @return void
	 */
	public function test_partially_overlapping_claims_split_the_rows_exactly(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$this->ledger->record( $this->event( 5, 'b' ) );

		$other  = new Wpdb_Event_Ledger( $this->db, Sqlite_Schema::EVENTS, array( $this, 'clock' ) );
		$racing = new class( $this->db, Sqlite_Schema::EVENTS, array( $this, 'clock' ), $other ) extends Wpdb_Event_Ledger {
			/**
			 * What B won, kept for the assertion.
			 *
			 * @var \Woodev\Framework\Marketplace\Ledger\Claimed_Batch|null
			 */
			public $rival_batch;

			/**
			 * The rival ledger.
			 *
			 * @var Wpdb_Event_Ledger
			 */
			private $rival;

			/**
			 * Creates the interleaving ledger.
			 *
			 * @param \wpdb             $db    Database.
			 * @param string            $table Table.
			 * @param callable          $clock Clock.
			 * @param Wpdb_Event_Ledger $rival Ledger of the rival runner.
			 */
			public function __construct( \wpdb $db, string $table, callable $clock, Wpdb_Event_Ledger $rival ) {
				parent::__construct( $db, $table, $clock );
				$this->rival = $rival;
			}

			/**
			 * A selects both ids; then B claims row 1 before A's update runs.
			 *
			 * @param int $account_id Account id.
			 * @param int $limit      Limit.
			 * @return int[]
			 */
			protected function due_ids( int $account_id, int $limit ): array {
				$ids               = parent::due_ids( $account_id, $limit );
				$this->rival_batch = $this->rival->claim( $account_id, 1 );

				return $ids;
			}
		};

		$won = $racing->claim( 5, 2 );

		$this->assertSame( array( 1 ), array_map( array( $this, 'id_of' ), $racing->rival_batch->events() ) );
		$this->assertSame( array( 2 ), array_map( array( $this, 'id_of' ), $won->events() ), 'A processes only the row it actually won' );
		$this->assertNotSame( $racing->rival_batch->token(), $won->token() );
	}

	/**
	 * Spec 6.3 test: an old owner's completion after reclamation changes nothing.
	 *
	 * @return void
	 */
	public function test_an_old_owner_cannot_complete_a_reclaimed_row(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$old = $this->ledger->claim( 5, 10 );

		$this->now += Wpdb_Event_Ledger::CLAIM_TTL + 1;
		$new        = $this->ledger->claim( 5, 10 );

		$this->assertFalse( $this->ledger->complete( 1, $old->token(), 'late' ), 'the old token owns nothing' );
		$this->assertFalse( $this->ledger->retry( 1, $old->token(), 60, 'late' ) );
		$this->assertFalse( $this->ledger->fail( 1, $old->token(), 'late' ) );
		$this->assertSame( array( 'processing' ), $this->column( 'status' ) );

		$this->assertTrue( $this->ledger->complete( 1, $new->token(), 'ok' ) );
		$this->assertSame( array( 'done' ), $this->column( 'status' ) );
		$this->assertSame( array( 'ok' ), $this->column( 'result' ) );
		$this->assertSame( array( null ), $this->column( 'claim_token' ), 'a finished row holds no claim' );
	}

	/**
	 * A retry waits for its time; before it, the row is not due, after it, it is claimed again.
	 *
	 * @return void
	 */
	public function test_a_retry_is_due_only_after_its_delay(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$batch = $this->ledger->claim( 5, 10 );

		$this->assertTrue( $this->ledger->retry( 1, $batch->token(), 180, 'boom' ) );
		$this->assertSame( array( 'retry' ), $this->column( 'status' ) );
		$this->assertTrue( $this->ledger->claim( 5, 10 )->is_empty() );

		$this->now += 181;
		$this->assertCount( 1, $this->ledger->claim( 5, 10 )->events() );
	}

	/**
	 * A failed event is final: never claimed again, with its text kept.
	 *
	 * @return void
	 */
	public function test_a_failed_event_is_final(): void {
		$this->ledger->record( $this->event( 5, 'a' ) );
		$batch = $this->ledger->claim( 5, 10 );

		$this->assertTrue( $this->ledger->fail( 1, $batch->token(), 'gave up' ) );

		$this->now += 100000;
		$this->assertTrue( $this->ledger->claim( 5, 10 )->is_empty() );
		$this->assertSame( array( 'gave up' ), $this->column( 'result' ) );
	}

	/**
	 * A payload over the cap is stored as its scalar fields plus a marker (spec 3.1).
	 *
	 * @return void
	 */
	public function test_an_oversize_payload_is_stored_truncated(): void {
		$items = array_fill(
			0,
			5000,
			array(
				'product_id' => 123456789,
				'sku'        => 987654321,
				'updated_at' => '2026-11-08T10:00:00Z',
			)
		);
		$this->ledger->record(
			$this->event(
				5,
				'big',
				array(
					'message_type' => 'TYPE_STOCKS_CHANGED',
					'items'        => $items,
				)
			)
		);

		$payload = $this->ledger->claim( 5, 1 )->events()[0]->payload();

		$this->assertSame( 'TYPE_STOCKS_CHANGED', $payload['message_type'] );
		$this->assertTrue( $payload['_truncated'] );
		$this->assertGreaterThan( Wpdb_Event_Ledger::PAYLOAD_CAP, $payload['_original_bytes'] );
		$this->assertArrayNotHasKey( 'items', $payload );
	}

	/**
	 * A stored-but-never-processed event keeps a small payload: anyone can create such rows by posting to the endpoint.
	 *
	 * @return void
	 */
	public function test_an_ignored_event_keeps_only_a_small_payload(): void {
		$this->ledger->record( $this->event( null, 'k1', array( 'raw' => str_repeat( 'x', 10000 ) ) )->with_ignore_reason( 'unknown account' ) );

		$stored = (string) $this->column( 'payload' )[0];

		$this->assertLessThanOrEqual( Wpdb_Event_Ledger::IGNORED_PAYLOAD_CAP, strlen( $stored ) );
		$this->assertStringContainsString( '_truncated', $stored );
	}
}
