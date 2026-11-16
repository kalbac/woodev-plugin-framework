<?php
/**
 * The daily stock reconcile job and its schedule.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Runner;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Sync\Mirror_Stock_Writer;
use Woodev\Framework\Marketplace\Sync\Stock_Readback;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The reconcile of an account in «marketplace is master» mode: what it writes into the shop, that it never sends it back, its
 * pages and cursor, the budget stop, the gate, and the daily schedule per account.
 */
final class ReconcileStockRunnerTest extends Sync_Test_Case {
	private const HOOK = 'woodev_fake_seller_reconcile_stock';

	/**
	 * Transients of the test, by key.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = [];

	/**
	 * Accounts added besides the test's own, for unique cabinet references.
	 *
	 * @var int
	 */
	private $extra_accounts = 0;

	/**
	 * Stubs the site's time zone and transients.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transients     = [];
		$this->extra_accounts = 0;

		Functions\when( 'wp_timezone' )->alias(
			static function (): \DateTimeZone {
				return new \DateTimeZone( 'Europe/Moscow' );
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
	}

	/**
	 * Builds the runner over the real stores and the marketplace double; the deadline runs on the test clock.
	 *
	 * @return Reconcile_Stock_Runner
	 */
	private function runner(): Reconcile_Stock_Runner {
		return new Reconcile_Stock_Runner(
			$this->job_gate(),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->links(),
			new Stock_Readback( $this->links(), $this->market, $this->intents(), $this->queue(), new Mirror_Stock_Writer(), $this->policy ),
			new Reconcile_Stock_Scheduler( $this->names() ),
			$this->classifier,
			new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() ),
			[ $this, 'log_line' ],
			function (): float {
				return (float) $this->now;
			}
		);
	}

	/**
	 * Puts the account into «marketplace is master» mode.
	 *
	 * @return void
	 */
	private function marketplace_is_master(): void {
		$this->set_account( [ 'stock_mode' => Wpdb_Account_Store::STOCK_MARKETPLACE ] );
	}

	/**
	 * Adds an account of the same marketplace.
	 *
	 * @param string $mode    Stock mode.
	 * @param bool   $enabled Whether it is enabled.
	 * @return int Account id.
	 */
	private function another_account( string $mode, bool $enabled = true ): int {
		return $this->accounts()->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Второй ' . $mode,
				$enabled,
				[
					'cabinet_ref' => 'cabinet-extra-' . ++$this->extra_accounts,
					'api_token'   => 'tok-extra-' . $this->extra_accounts,
				],
				null,
				[
					'warehouse_id' => self::WAREHOUSE,
					'stock_mode'   => $mode,
					'currency'     => 'RUB',
				]
			)
		);
	}

	/**
	 * The continuations the job queued for itself.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function continuations(): array {
		return array_values(
			array_filter(
				$this->scheduler->of( self::HOOK ),
				static function ( array $action ): bool {
					return 3 === count( $action['args'] );
				}
			)
		);
	}

	/**
	 * The daily runs in the queue, by account id.
	 *
	 * @return int[]
	 */
	private function daily_accounts(): array {
		$ids = [];

		foreach ( $this->scheduler->of( self::HOOK ) as $action ) {
			if ( 'recurring' === $action['kind'] ) {
				$ids[] = $action['args'][0];
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Creates linked, stock-synced products.
	 *
	 * @param int $count Number of products.
	 * @return int[] Product ids in link order.
	 */
	private function products_of_the_account( int $count ): array {
		$ids = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->stock_product( 'sku-' . $i, 1000 + $i, 10 );
		}

		return $ids;
	}

	/**
	 * What the marketplace holds, minus what open orders reserved, becomes the shop's stock; nothing is sent back.
	 *
	 * @return void
	 */
	public function test_the_marketplace_stock_is_mirrored_into_the_shop_and_not_echoed(): void {
		$this->marketplace_is_master();
		$this->hook_stock();

		$first  = $this->stock_product( 'a', 101, 10 );
		$second = $this->stock_product( 'b', 102, 6 );

		$this->market->present['a']  = 4;
		$this->market->reserved['a'] = 1;
		$this->market->present['b']  = 6;

		$this->runner()->run( $this->account_id );

		$this->assertSame( 3, $this->wc_stock( $first ), 'present − reserved' );
		$this->assertSame( 6, $this->wc_stock( $second ), 'equal values are not written' );
		$this->assertSame( [], $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'the stock the shop just took is not sent back' );
		$this->assertNull( $this->stock_row( $first ), 'no pending stock is queued for the marketplace' );
		$this->assertSame( [], $this->continuations() );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
	}

	/**
	 * An account that is not in `marketplace` mode any more is not read at all, whatever was queued.
	 *
	 * @return void
	 */
	public function test_an_account_in_another_mode_is_left_alone(): void {
		$product = $this->stock_product( 'a', 101, 10 );
		$this->market->present['a'] = 2;

		foreach ( [ Wpdb_Account_Store::STOCK_SITE, Wpdb_Account_Store::STOCK_OFF ] as $mode ) {
			$this->set_account( [ 'stock_mode' => $mode ] );
			$this->runner()->run( $this->account_id );
		}

		$this->assertSame( [], $this->market->requests );
		$this->assertSame( 10, $this->wc_stock( $product ) );
	}

	/**
	 * A disabled or deleted account ends the job without a read and without a retry.
	 *
	 * @return void
	 */
	public function test_a_disabled_or_missing_account_stops_the_job(): void {
		$this->marketplace_is_master();
		$this->stock_product( 'a', 101, 10 );

		$this->accounts()->set_enabled( $this->account_id, false );
		$this->runner()->run( $this->account_id );
		$this->runner()->run( 999 );

		$this->assertSame( [], $this->market->requests );
		$this->assertSame( [], $this->continuations() );
	}

	/**
	 * Pages are 200 links; the second page is read in the same run, and the whole shop is covered.
	 *
	 * @return void
	 */
	public function test_it_reads_the_links_in_pages_of_two_hundred(): void {
		$this->marketplace_is_master();
		$products = $this->products_of_the_account( 201 );

		foreach ( $products as $index => $product ) {
			$this->market->present[ 'sku-' . ( $index + 1 ) ] = 3;
		}

		$this->runner()->run( $this->account_id );

		$reads = $this->market->sent( Scripted_Stock_Pusher::READ );

		$this->assertCount( 2, $reads, 'one request per page' );
		$this->assertCount( 200, $reads[0] );
		$this->assertSame( [ 'sku-201' ], $reads[1] );
		$this->assertSame( 3, $this->wc_stock( $products[0] ) );
		$this->assertSame( 3, $this->wc_stock( $products[200] ) );
		$this->assertSame( [], $this->continuations() );
	}

	/**
	 * A continuation starts after its cursor: the links before it are not read again.
	 *
	 * @return void
	 */
	public function test_a_continuation_starts_after_its_cursor(): void {
		$this->marketplace_is_master();
		$products = $this->products_of_the_account( 3 );

		$this->market->present['sku-1'] = 1;
		$this->market->present['sku-2'] = 2;
		$this->market->present['sku-3'] = 3;

		$this->runner()->run( $this->account_id, $this->link_id( $products[1] ), 0 );

		$this->assertSame( [ [ 'sku-3' ] ], $this->market->sent( Scripted_Stock_Pusher::READ ) );
		$this->assertSame( 10, $this->wc_stock( $products[0] ) );
		$this->assertSame( 10, $this->wc_stock( $products[1] ) );
		$this->assertSame( 3, $this->wc_stock( $products[2] ) );
	}

	/**
	 * When the run's time is spent the job queues the pass again from the page it was on, and the continuation finishes it.
	 *
	 * @return void
	 */
	public function test_a_spent_budget_queues_the_rest_of_the_pass(): void {
		$this->marketplace_is_master();
		$products = $this->products_of_the_account( 401 );

		foreach ( array_keys( $products ) as $index ) {
			$this->market->present[ 'sku-' . ( $index + 1 ) ] = 2;
		}

		// The second page takes longer than the run has: the run cannot start another request after it.
		$this->market->script = [
			static function () {
				return null;
			},
			function () {
				$this->now += 60;

				return null;
			},
		];

		$this->runner()->run( $this->account_id );

		$last_of_first_page = $this->link_id( $products[199] );
		$continuations      = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, $last_of_first_page, 0 ], $continuations[0]['args'] );
		$this->assertSame( 'woodev-fake-seller', $continuations[0]['group'] );
		$this->assertSame( 2, $this->wc_stock( $products[0] ), 'the first page was written' );

		$this->runner()->run( ...$continuations[0]['args'] );

		$this->assertSame( 2, $this->wc_stock( $products[400] ), 'the continuation reaches the end' );
		$this->assertCount( 1, $this->continuations(), 'and queues nothing more' );
	}

	/**
	 * Action Scheduler refusing the continuation is a recorded failure, not a silent end.
	 *
	 * @return void
	 */
	public function test_a_refused_continuation_is_recorded(): void {
		$this->marketplace_is_master();
		$this->products_of_the_account( 201 );
		$this->scheduler->zero_for[ self::HOOK ] = true;
		$this->market->script                    = [
			function () {
				$this->now += 60;

				return null;
			},
		];

		$this->runner()->run( $this->account_id );

		$rows = $this->db->rows( 'SELECT message FROM ' . Sqlite_Tables::table( 'api_errors' ) . " WHERE code = 'reconcile_stock_failed'" );

		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'could not be continued', (string) $rows[0]['message'] );
	}

	/**
	 * Without a licence nothing is read; the job waits an hour with the same cursor.
	 *
	 * @return void
	 */
	public function test_without_a_licence_nothing_is_read_and_the_job_waits(): void {
		$this->marketplace_is_master();
		$this->stock_product( 'a', 101, 10 );
		$this->licensed = false;

		$this->runner()->run( $this->account_id, 7, 0 );

		$continuations = $this->continuations();

		$this->assertSame( [], $this->market->requests );
		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, 7, 0 ], $continuations[0]['args'] );
		$this->assertEqualsWithDelta( Job_Gate::LICENCE_DELAY, $continuations[0]['delay'], 5 );

		$this->runner()->run( $this->account_id, 7, 0 );
		$this->assertCount( 1, $this->continuations(), 'a waiting continuation is not queued twice' );
	}

	/**
	 * A busy account (another run holds its claim) is retried shortly without a failed attempt.
	 *
	 * @return void
	 */
	public function test_a_busy_account_is_retried_shortly(): void {
		$this->marketplace_is_master();
		$this->stock_product( 'a', 101, 10 );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ) );

		$this->runner()->run( $this->account_id );

		$continuations = $this->continuations();

		$this->assertSame( [], $this->market->requests );
		$this->assertSame( [ $this->account_id, 0, 0 ], $continuations[0]['args'] );
		$this->assertEqualsWithDelta( Job_Gate::BUSY_DELAY, $continuations[0]['delay'], 5 );
	}

	/**
	 * A server error retries on the ladder with a failed attempt counted; the shop is left as it was.
	 *
	 * @return void
	 */
	public function test_a_server_error_retries_with_an_attempt(): void {
		$this->marketplace_is_master();
		$product = $this->stock_product( 'a', 101, 10 );

		$this->market->script = [
			static function () {
				throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );
			},
		];

		$this->runner()->run( $this->account_id );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, 0, 1 ], $continuations[0]['args'] );
		$this->assertSame( 10, $this->wc_stock( $product ) );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
	}

	/**
	 * Registration hooks the job with its three arguments and the schedule check on Action Scheduler's init.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_job_and_the_schedule_check(): void {
		Actions\expectAdded( self::HOOK )->once();
		Actions\expectAdded( 'action_scheduler_init' )->once();

		$this->runner()->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Only an enabled account in `marketplace` mode gets a daily run, in the plugin's group; a repeated check adds nothing.
	 *
	 * @return void
	 */
	public function test_only_marketplace_mode_accounts_get_a_daily_run_once(): void {
		$this->another_account( Wpdb_Account_Store::STOCK_OFF );
		$this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE, false );
		$master = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );

		$this->runner()->ensure_schedules();
		$this->transients = [];
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $master ], $this->daily_accounts() );
		$this->assertCount( 1, $this->scheduler->actions );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->actions[0]['group'] );
	}

	/**
	 * The check runs at most once an hour.
	 *
	 * @return void
	 */
	public function test_the_schedule_check_runs_once_an_hour(): void {
		$this->marketplace_is_master();

		$this->runner()->ensure_schedules();
		$this->marketplace_is_master();
		$second = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id ], $this->daily_accounts(), 'the second check within the hour changed nothing' );
		$this->assertNotContains( $second, $this->daily_accounts() );
		$this->assertSame( [ 'woodev_fake_seller_reconcile_stock_check' ], array_keys( $this->transients ) );
	}

	/**
	 * Switching the account off, to another stock mode, or deleting it removes its daily run on the next check; the other
	 * accounts keep theirs, and a continuation in flight is not touched.
	 *
	 * @return void
	 */
	public function test_a_daily_run_goes_when_its_account_no_longer_needs_it(): void {
		$this->marketplace_is_master();
		$switched_off = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$other_mode   = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$deleted      = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );

		$this->runner()->ensure_schedules();
		$this->assertSame( [ $this->account_id, $switched_off, $other_mode, $deleted ], $this->daily_accounts() );

		( new Reconcile_Stock_Scheduler( $this->names() ) )->continue_later( $switched_off, 5, 0, 30 );

		$this->accounts()->set_enabled( $switched_off, false );
		$this->set_account_of( $other_mode, [ 'stock_mode' => Wpdb_Account_Store::STOCK_SITE ] );
		$this->accounts()->set_enabled( $deleted, false ); // The deletion switches the account off first.
		$token = (string) $this->accounts()->claim( $deleted, Job_Gate::CLAIM_TTL );
		$this->assertTrue( $this->accounts()->delete( $deleted, $token ) );

		$this->transients = [];
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id ], $this->daily_accounts() );
		$this->assertCount( 1, $this->continuations(), 'a continuation in flight finishes' );
	}

	/**
	 * An account switched back on, or into `marketplace` mode, gets its run again.
	 *
	 * @return void
	 */
	public function test_a_daily_run_returns_with_the_mode(): void {
		$this->runner()->ensure_schedules();
		$this->assertSame( [], $this->daily_accounts() );

		$this->marketplace_is_master();
		$this->transients = [];
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id ], $this->daily_accounts() );
	}

	/**
	 * The daily run is due at 04:00 site time, today when that is still ahead, tomorrow when it has passed.
	 *
	 * @return void
	 */
	public function test_the_daily_run_is_due_at_four_in_the_morning_site_time(): void {
		$scheduler = new Reconcile_Stock_Scheduler( $this->names() );

		// 2026-11-08 12:00 UTC is 15:00 in Moscow: 04:00 of the next day, 2026-11-09 01:00 UTC.
		$this->assertSame( 1794186000, $scheduler->next_run( self::NOW ) );
		// 2026-11-08 00:00 UTC is 03:00 in Moscow: 04:00 is an hour ahead.
		$this->assertSame( 1794099600, $scheduler->next_run( 1794096000 ) );
	}

	/**
	 * The plugin may move the hour through the filter; an hour outside the day is brought back into it.
	 *
	 * @return void
	 */
	public function test_the_hour_comes_from_the_filter_within_the_day(): void {
		Filters\expectApplied( 'woodev_fake_seller_reconcile_stock_hour' )->times( 3 )->andReturn( 6, 99, 'x' );

		$scheduler = new Reconcile_Stock_Scheduler( $this->names() );

		$this->assertSame( 6, $scheduler->hour() );
		$this->assertSame( 23, $scheduler->hour() );
		$this->assertSame( Reconcile_Stock_Scheduler::DEFAULT_HOUR, $scheduler->hour() );
	}

	/**
	 * A daily run Action Scheduler refuses is logged, and the next check tries again.
	 *
	 * @return void
	 */
	public function test_a_refused_daily_run_is_logged(): void {
		$this->marketplace_is_master();
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->runner()->ensure_schedules();

		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'reconcile_stock could not be scheduled', $this->log[0] );
	}

	/**
	 * Changes the settings of another account.
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $columns    Columns.
	 * @return void
	 */
	private function set_account_of( int $account_id, array $columns ): void {
		$this->accounts()->update_fields( $account_id, $columns );
	}
}
