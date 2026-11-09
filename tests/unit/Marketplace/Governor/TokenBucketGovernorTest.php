<?php
/**
 * The token-bucket governor over the database store.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Governor;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Governor\Request_Governor;
use Woodev\Framework\Marketplace\Governor\Throttle_Store;
use Woodev\Framework\Marketplace\Governor\Token_Bucket_Governor;
use Woodev\Framework\Marketplace\Governor\Wpdb_Throttle_Store;
use Woodev\Tests\Unit\Marketplace\Support\Failing_Cooldown_Throttle_Store;
use Woodev\Tests\Unit\Marketplace\Support\Interleaving_Throttle_Store;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Every number comes from a `Bucket_Spec`; the clock is a variable the test moves.
 */
final class TokenBucketGovernorTest extends TestCase {
	/**
	 * Current time of the test clock.
	 *
	 * @var float
	 */
	private $now = 1000.0;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Real store over SQLite.
	 *
	 * @var Wpdb_Throttle_Store
	 */
	private $store;

	/**
	 * Opens a database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = 1000.0;
		$this->db  = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->store = new Wpdb_Throttle_Store( $this->db, Sqlite_Schema::THROTTLE );
	}

	/**
	 * Builds a governor over a store, with the test clock.
	 *
	 * @param Throttle_Store|null $store Store; the real one when null.
	 * @return Token_Bucket_Governor
	 */
	private function governor( ?Throttle_Store $store = null ): Token_Bucket_Governor {
		return new Token_Bucket_Governor(
			$store ?? $this->store,
			array(
				new Bucket_Spec( 'default', 50, 1, 50 ),
				new Bucket_Spec( 'one_a_second', 1, 1, 1 ),
				new Bucket_Spec( 'items', 30000, 60, 30000, Bucket_Spec::COST_ITEMS ),
				new Bucket_Spec( 'stocks', 80, 60, 80 ),
			),
			function (): float {
				return $this->now;
			}
		);
	}

	/**
	 * It is a `Request_Governor`, so the layer's runners can use it unchanged.
	 *
	 * @return void
	 */
	public function test_it_is_a_request_governor(): void {
		$this->assertInstanceOf( Request_Governor::class, $this->governor() );
	}

	/**
	 * A full bucket lets `burst` requests through and then says how long to wait.
	 *
	 * @return void
	 */
	public function test_a_full_bucket_lets_the_burst_through_then_asks_to_wait(): void {
		$governor = $this->governor();

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame( 0, $governor->acquire( '7', 'default' ), "request {$i}" );
		}

		$this->assertSame( 1, $governor->acquire( '7', 'default' ), 'the 51st request in the same instant waits one second at most' );
	}

	/**
	 * Tokens come back with time, at limit / period per second.
	 *
	 * @return void
	 */
	public function test_tokens_refill_with_time(): void {
		$governor = $this->governor();
		for ( $i = 0; $i < 50; $i++ ) {
			$governor->acquire( '7', 'default' );
		}

		$this->now += 0.1; // Five tokens.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 0, $governor->acquire( '7', 'default' ), "refilled token {$i}" );
		}
		$this->assertGreaterThan( 0, $governor->acquire( '7', 'default' ) );
	}

	/**
	 * Two acquisitions inside one second must not both succeed on a one-a-second bucket (`/v1/notification/*`).
	 *
	 * @return void
	 */
	public function test_two_acquisitions_inside_one_second_do_not_both_succeed(): void {
		$governor = $this->governor();

		$this->assertSame( 0, $governor->acquire( '7', 'one_a_second' ) );
		$this->now += 0.4;
		$this->assertSame( 1, $governor->acquire( '7', 'one_a_second' ), 'the same second: wait' );
		$this->now += 0.6;
		$this->assertSame( 0, $governor->acquire( '7', 'one_a_second' ), 'a second later: go' );
	}

	/**
	 * The concurrent case: another run takes the last token between this run's read and its swap. The swap is lost, the
	 * governor re-reads, sees an empty bucket and tells the caller to wait; exactly one token was spent in all.
	 *
	 * @return void
	 */
	public function test_a_lost_swap_is_retried_and_the_last_token_is_spent_once(): void {
		$rival = $this->governor();
		$store = new Interleaving_Throttle_Store(
			$this->store,
			function () use ( $rival ): void {
				$this->assertSame( 0, $rival->acquire( '7', 'one_a_second' ), 'the rival wins the last token' );
			}
		);

		$wait = $this->governor( $store )->acquire( '7', 'one_a_second' );

		$this->assertSame( 1, $wait, 'the loser must not go' );
		$this->assertSame( 1, $store->swaps, 'the swap was lost once; the re-read showed an empty bucket, so no second swap was tried' );
		$this->assertSame( 0.0, (float) $this->store->read( '7', 'one_a_second' )->state()['tokens'], 'one token existed, one was spent' );
	}

	/**
	 * When the competing write leaves a token behind, the retry succeeds.
	 *
	 * @return void
	 */
	public function test_a_lost_swap_that_leaves_tokens_is_retried_to_success(): void {
		$rival = $this->governor();
		$store = new Interleaving_Throttle_Store(
			$this->store,
			function () use ( $rival ): void {
				$rival->acquire( '7', 'default' );
			}
		);

		$this->assertSame( 0, $this->governor( $store )->acquire( '7', 'default' ) );
		$this->assertEquals( 48.0, $this->store->read( '7', 'default' )->state()['tokens'], 'both took one of 50' );
	}

	/**
	 * Endless contention costs three attempts and ends in a one-second wait, never in a free pass.
	 *
	 * @return void
	 */
	public function test_endless_contention_ends_in_a_short_wait_after_three_attempts(): void {
		$rival = $this->governor();
		$store = new Interleaving_Throttle_Store(
			$this->store,
			function () use ( $rival ): void {
				$rival->acquire( '7', 'default' );
			},
			PHP_INT_MAX
		);

		$this->assertSame( Token_Bucket_Governor::CONTENTION_WAIT, $this->governor( $store )->acquire( '7', 'default' ) );
		$this->assertSame( Token_Bucket_Governor::MAX_ATTEMPTS, $store->swaps );
	}

	/**
	 * An items bucket charges by item count, and says when a big batch fits.
	 *
	 * @return void
	 */
	public function test_an_items_bucket_charges_the_item_count(): void {
		$governor = $this->governor();

		$this->assertSame( 0, $governor->acquire( '7', 'items', 20000 ) );
		$this->assertSame( 20, $governor->acquire( '7', 'items', 20000 ), '10 000 items missing at 500 a second' );
		$this->now += 20.0;
		$this->assertSame( 0, $governor->acquire( '7', 'items', 20000 ) );
	}

	/**
	 * A batch bigger than the bucket goes out when the bucket is full, instead of waiting for a level it never reaches.
	 *
	 * @return void
	 */
	public function test_a_cost_above_the_capacity_is_lowered_to_the_capacity(): void {
		$governor = $this->governor();

		$this->assertSame( 0, $governor->acquire( '7', 'items', 90000 ) );
		$this->assertGreaterThan( 0, $governor->acquire( '7', 'items', 1 ), 'it emptied the bucket' );
	}

	/**
	 * The stock bucket: 80 a minute, then the wait to the next token (0.75 s).
	 *
	 * @return void
	 */
	public function test_the_stocks_bucket_allows_eighty_a_minute(): void {
		$governor = $this->governor();
		for ( $i = 0; $i < 80; $i++ ) {
			$this->assertSame( 0, $governor->acquire( '7', 'stocks' ) );
		}

		$this->assertSame( 1, $governor->acquire( '7', 'stocks' ) );
		$this->now += 60.0;
		$this->assertSame( 0, $governor->acquire( '7', 'stocks' ) );
	}

	/**
	 * Accounts do not share buckets.
	 *
	 * @return void
	 */
	public function test_accounts_have_their_own_buckets(): void {
		$governor = $this->governor();
		$governor->acquire( '1', 'one_a_second' );

		$this->assertSame( 1, $governor->acquire( '1', 'one_a_second' ) );
		$this->assertSame( 0, $governor->acquire( '2', 'one_a_second' ) );
	}

	/**
	 * A 429 with `Retry-After: n` empties the bucket for n seconds.
	 *
	 * @return void
	 */
	public function test_a_429_empties_the_bucket_for_the_retry_after_time(): void {
		$governor = $this->governor();

		$governor->report_rate_limited( '7', 'default', 3 );

		$this->assertSame( 3, $governor->acquire( '7', 'default' ) );
		$this->now += 2.0;
		$this->assertSame( 1, $governor->acquire( '7', 'default' ) );
		$this->now += 1.0;
		$this->assertSame( 0, $governor->acquire( '7', 'default' ), 'the block is over and the bucket has refilled' );
	}

	/**
	 * Review finding 2: two answers in flight can arrive out of order. A later 429 asking for 1 second must not shorten
	 * the 60 seconds an earlier one asked for.
	 *
	 * @return void
	 */
	public function test_a_later_shorter_429_never_shortens_the_cooldown(): void {
		$governor = $this->governor();

		$governor->report_rate_limited( '7', 'default', 60 );
		$this->now += 1.0;
		$governor->report_rate_limited( '7', 'default', 1 );
		$this->now += 1.0;

		$this->assertSame( 58, $governor->acquire( '7', 'default' ), 'the first deadline (t + 60) still stands at t + 2' );
		$this->now += 58.0;
		$this->assertSame( 0, $governor->acquire( '7', 'default' ) );
	}

	/**
	 * A later 429 that asks for more extends the cooldown.
	 *
	 * @return void
	 */
	public function test_a_later_longer_429_extends_the_cooldown(): void {
		$governor = $this->governor();

		$governor->report_rate_limited( '7', 'default', 5 );
		$this->now += 1.0;
		$governor->report_rate_limited( '7', 'default', 30 );

		$this->assertSame( 30, $governor->acquire( '7', 'default' ) );
	}

	/**
	 * Review finding 3: other runs finish requests (and record their quota) between the 429 handler's read and its swap.
	 * The hold lives in a row only 429 reports write, so that traffic cannot make it lose; it is stored and every run sees it.
	 *
	 * @return void
	 */
	public function test_token_traffic_during_the_429_feedback_does_not_lose_the_cooldown(): void {
		$rival    = $this->governor();
		$store    = new Interleaving_Throttle_Store(
			$this->store,
			function () use ( $rival ): void {
				$rival->record_success( '7', 'default', 40, 'abcd1234' );
			},
			PHP_INT_MAX
		);
		$governor = $this->governor( $store );

		$stored = $governor->apply_rate_limit( '7', 'default', 60 );

		$this->assertTrue( $stored );
		$this->assertSame( 60, $this->governor()->acquire( '7', 'default' ), 'another process sees the hold' );
	}

	/**
	 * Review finding 3, the critic's scenario: ten competing 429 reports, each a different, shorter deadline, land
	 * around this report's 60 seconds. The hold is one atomic maximum, so it cannot lose: a separately built governor
	 * (another Action Scheduler run) waits the full time, and still waits the remainder 14 seconds later.
	 *
	 * @return void
	 */
	public function test_ten_competing_429_reports_cannot_shorten_the_hold_other_governors_see(): void {
		$rival = $this->governor();
		$store = new Interleaving_Throttle_Store(
			$this->store,
			static function () use ( $rival ): void {
				for ( $seconds = 1; $seconds <= 10; $seconds++ ) {
					$rival->apply_rate_limit( '7', 'default', $seconds );
				}
			}
		);

		$stored = $this->governor( $store )->apply_rate_limit( '7', 'default', 60 );
		for ( $seconds = 1; $seconds <= 10; $seconds++ ) {
			$rival->apply_rate_limit( '7', 'default', $seconds ); // The competitors that land after the long report.
		}

		$this->assertTrue( $stored );
		$this->assertSame( 60, $this->governor()->acquire( '7', 'default' ), 'another governor waits the full 60 seconds' );
		$this->now += 14.0;
		$this->assertSame( 46, $this->governor()->acquire( '7', 'default' ), 'and the remainder 14 seconds later' );
	}

	/**
	 * The same ten reports arriving after the long one change nothing.
	 *
	 * @return void
	 */
	public function test_ten_shorter_reports_after_the_long_one_change_nothing(): void {
		$this->governor()->apply_rate_limit( '7', 'default', 60 );
		for ( $seconds = 1; $seconds <= 10; $seconds++ ) {
			$this->governor()->apply_rate_limit( '7', 'default', $seconds );
		}

		$this->assertSame( 60, $this->governor()->acquire( '7', 'default' ) );
	}

	/**
	 * The hold is written by one statement, with no version check, in the monotonic-maximum form (the log keeps the
	 * MySQL / MariaDB text; the SQLite double translates it when it runs).
	 *
	 * @return void
	 */
	public function test_the_hold_is_one_atomic_maximum_statement(): void {
		$this->governor()->apply_rate_limit( '7', 'default', 60 );

		$writes = array_values(
			array_filter(
				$this->db->statements,
				static function ( string $sql ): bool {
					return false !== strpos( $sql, 'c:default' );
				}
			)
		);
		$this->assertCount( 1, $writes, 'one statement, no read-modify-write loop' );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE disabled_until = GREATEST(COALESCE(disabled_until', $writes[0], 'the MySQL / MariaDB form; the SQLite double runs it as ON CONFLICT … MAX()' );
		$this->assertStringNotContainsString( 'version =', $writes[0] );
	}

	/**
	 * When the database refuses the write, the caller is told, so it can record it.
	 *
	 * @return void
	 */
	public function test_a_refused_hold_write_is_reported_to_the_caller(): void {
		$governor = $this->governor( new Failing_Cooldown_Throttle_Store( $this->store ) );

		$this->assertFalse( $governor->apply_rate_limit( '7', 'default', 60 ) );
	}

	/**
	 * A switched-off key is reported for 24 hours, answers every acquire with the rest of that time, and touches no other key.
	 *
	 * @return void
	 */
	public function test_a_switched_off_key_stays_off_for_a_day(): void {
		$governor = $this->governor();
		$governor->report_disabled( '7', 'default', 'retired:/v2/posting/fbs/package-label' );

		$this->assertTrue( $governor->is_disabled( '7', 'default' ) );
		$this->assertFalse( $governor->is_disabled( '7', 'stocks' ), 'another bucket is not affected' );
		$this->assertFalse( $governor->is_disabled( '8', 'default' ), 'another account is not affected' );
		$this->assertSame( 86400, $governor->acquire( '7', 'default' ) );
		$this->assertSame( 'retired:/v2/posting/fbs/package-label', $this->store->read( '7', 'default' )->disabled_reason() );

		$this->now += 86399.0;
		$this->assertTrue( $governor->is_disabled( '7', 'default' ) );
		$this->now += 2.0;
		$this->assertFalse( $governor->is_disabled( '7', 'default' ) );
		$this->assertSame( 0, $governor->acquire( '7', 'default' ) );
	}

	/**
	 * A key without a description (one method's key) can be switched off and asked about.
	 *
	 * @return void
	 */
	public function test_a_key_without_a_description_can_be_switched_off(): void {
		$governor = $this->governor();

		$this->assertFalse( $governor->is_disabled( '7', 'm:0123456789abcdef' ) );
		$governor->report_disabled( '7', 'm:0123456789abcdef', 'retired:/x' );

		$this->assertTrue( $governor->is_disabled( '7', 'm:0123456789abcdef' ) );
		$this->assertSame( 86400, $governor->disabled_for( '7', 'm:0123456789abcdef' ) );
		$this->assertSame( 0, $governor->disabled_for( '7', 'default' ) );
	}

	/**
	 * The reason is cut to the 64 characters of the column.
	 *
	 * @return void
	 */
	public function test_the_reason_is_cut_to_the_column_size(): void {
		$governor = $this->governor();
		$governor->report_disabled( '7', 'default', str_repeat( 'x', 100 ) );

		$this->assertSame( 64, strlen( $this->store->read( '7', 'default' )->disabled_reason() ) );
	}

	/**
	 * A success records the quota Ozon reported and remembers the method as one that worked.
	 *
	 * @return void
	 */
	public function test_a_success_records_the_quota_and_the_method(): void {
		$governor = $this->governor();

		$this->assertNull( $governor->remaining( '7', 'default' ) );
		$this->assertFalse( $governor->has_succeeded( '7', 'default', 'abcd1234' ) );

		$governor->record_success( '7', 'default', 49, 'abcd1234' );
		$governor->record_success( '7', 'default', 48, 'abcd1234' );
		$governor->record_success( '7', 'default', 47, 'ffff0000' );

		$this->assertSame( 47, $governor->remaining( '7', 'default' ) );
		$this->assertTrue( $governor->has_succeeded( '7', 'default', 'abcd1234' ) );
		$this->assertTrue( $governor->has_succeeded( '7', 'default', 'ffff0000' ) );
		$this->assertFalse( $governor->has_succeeded( '7', 'default', 'deadbeef' ) );
		$this->assertFalse( $governor->has_succeeded( '7', 'stocks', 'abcd1234' ), 'remembered per bucket' );
		$this->assertCount( 2, $this->store->read( '7', 'default' )->state()['answered'], 'a tag is listed once' );
	}

	/**
	 * Recording a success does not spend or refill tokens.
	 *
	 * @return void
	 */
	public function test_recording_a_success_leaves_the_tokens_alone(): void {
		$governor = $this->governor();
		$governor->acquire( '7', 'one_a_second' );

		$governor->record_success( '7', 'one_a_second', 0, 'abcd1234' );

		$this->assertSame( 1, $governor->acquire( '7', 'one_a_second' ) );
	}

	/**
	 * Asking a bucket nobody described is a programming error.
	 *
	 * @return void
	 */
	public function test_an_unknown_bucket_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->governor()->acquire( '7', 'nope' );
	}
}
