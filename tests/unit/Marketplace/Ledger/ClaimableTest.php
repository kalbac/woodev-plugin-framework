<?php
/**
 * The one claim mechanism of spec 3.5.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Ledger;

use PHPUnit\Framework\TestCase;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * The trait's three promises: only one of two racers wins a free row, a claim that expired can be taken over and the old
 * holder's fenced write then changes 0 rows, and releasing a claim you do not hold changes nothing.
 */
final class ClaimableTest extends TestCase {
	private const KEY = array( 'event_id' => 1 );

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Harness that uses the trait.
	 *
	 * @var object
	 */
	private $claims;

	/**
	 * Clock.
	 *
	 * @var int
	 */
	private $now = 1794139200;

	/**
	 * Opens a database with one row to claim.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = 1794139200;
		$this->db  = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->db->query( "INSERT INTO wp_woodev_fake_events (account_id, type, dedup_key, received_at) VALUES (5, 'T', 'k', '2026-11-08 10:00:00')" );

		$this->claims = new class( $this->db, array( $this, 'clock' ) ) {
			use \Woodev\Framework\Marketplace\Ledger\Claimable;

			/**
			 * Database.
			 *
			 * @var \wpdb
			 */
			private $db;

			/**
			 * Clock.
			 *
			 * @var callable
			 */
			private $clock;

			/**
			 * Creates the harness.
			 *
			 * @param \wpdb    $db    Database.
			 * @param callable $clock Clock.
			 */
			public function __construct( \wpdb $db, callable $clock ) {
				$this->db    = $db;
				$this->clock = $clock;
			}

			/**
			 * Database.
			 *
			 * @return \wpdb
			 */
			protected function claim_db(): \wpdb {
				return $this->db;
			}

			/**
			 * Time.
			 *
			 * @return int
			 */
			protected function claim_now(): int {
				return (int) call_user_func( $this->clock );
			}

			/**
			 * Calls a trait method by name, for the test.
			 *
			 * @param string $method Method.
			 * @param mixed  ...$args Arguments.
			 * @return mixed
			 */
			public function call( string $method, ...$args ) {
				return $this->$method( Sqlite_Schema::EVENTS, ...$args );
			}
		};
	}

	/**
	 * The clock.
	 *
	 * @return int
	 */
	public function clock(): int {
		return $this->now;
	}

	/**
	 * Of two racers, the first wins a free row and the second gets null.
	 *
	 * @return void
	 */
	public function test_only_one_racer_acquires_a_free_row(): void {
		$first  = $this->claims->call( 'acquire', self::KEY, 60 );
		$second = $this->claims->call( 'acquire', self::KEY, 60 );

		$this->assertSame( 32, strlen( (string) $first ) );
		$this->assertNull( $second );
		$this->assertTrue( $this->claims->call( 'holds', self::KEY, $first ) );
		$this->assertFalse( $this->claims->call( 'holds', self::KEY, 'someone-else' ) );
	}

	/**
	 * Nothing to claim: a key that matches no row yields null.
	 *
	 * @return void
	 */
	public function test_a_missing_row_cannot_be_acquired(): void {
		$this->assertNull( $this->claims->call( 'acquire', array( 'event_id' => 99 ), 60 ) );
	}

	/**
	 * An expired claim is taken over, and the old holder then changes 0 rows and no longer holds the row.
	 *
	 * @return void
	 */
	public function test_an_expired_claim_is_taken_over_and_the_old_writer_is_fenced_out(): void {
		$old        = $this->claims->call( 'acquire', self::KEY, 60 );
		$this->now += 61;
		$new        = $this->claims->call( 'acquire', self::KEY, 60 );

		$this->assertNotNull( $new );
		$this->assertFalse( $this->claims->call( 'holds', self::KEY, $old ) );
		$this->assertFalse( $this->claims->call( 'update_if_claimed', self::KEY, $old, 'result = %s', array( 'stale' ), false ), 'a stale write changes 0 rows' );
		$this->assertTrue( $this->claims->call( 'update_if_claimed', self::KEY, $new, 'result = %s', array( 'fresh' ), false ) );
		$this->assertSame( 'fresh', $this->db->rows( 'SELECT result FROM wp_woodev_fake_events' )[0]['result'] );
	}

	/**
	 * Releasing needs the token: a non-owner's release is a no-op, the owner's frees the row for the next racer.
	 *
	 * @return void
	 */
	public function test_release_by_a_non_owner_is_a_no_op(): void {
		$token = $this->claims->call( 'acquire', self::KEY, 60 );

		$this->claims->call( 'release', self::KEY, 'not-the-owner' );
		$this->assertTrue( $this->claims->call( 'holds', self::KEY, $token ) );

		$this->claims->call( 'release', self::KEY, $token );
		$this->assertFalse( $this->claims->call( 'holds', self::KEY, $token ) );
		$this->assertNotNull( $this->claims->call( 'acquire', self::KEY, 60 ) );
	}

	/**
	 * Renewing extends the holder's lease and refuses anyone else.
	 *
	 * @return void
	 */
	public function test_renew_extends_only_the_holders_claim(): void {
		$token = $this->claims->call( 'acquire', self::KEY, 60 );

		$this->assertFalse( $this->claims->call( 'renew', self::KEY, 'not-the-owner', 600 ) );
		$this->assertTrue( $this->claims->call( 'renew', self::KEY, $token, 600 ) );

		$this->now += 120;
		$this->assertTrue( $this->claims->call( 'holds', self::KEY, $token ), 'still held after the first lease would have ended' );
		$this->assertNull( $this->claims->call( 'acquire', self::KEY, 60 ) );
	}
}
