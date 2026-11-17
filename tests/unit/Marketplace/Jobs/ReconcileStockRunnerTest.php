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
use Woodev\Framework\Marketplace\Jobs\Account_Job;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Runner;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Mirror_Stock_Writer;
use Woodev\Framework\Marketplace\Sync\Stock_Readback;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Paged_Stock_Pusher;
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
	private function runner( ?Stock_Pusher $pusher = null, ?callable $on_applied = null ): Reconcile_Stock_Runner {
		return new Reconcile_Stock_Runner(
			$this->job_gate(),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->links(),
			new Stock_Readback( $this->links(), $pusher ?? $this->market, $this->intents(), $this->queue(), new Mirror_Stock_Writer(), $this->policy, $on_applied, $this->clock() ),
			new Reconcile_Stock_Scheduler( $this->names(), $this->clock() ),
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
					return count( $action['args'] ) >= 3;
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
		$this->assertNull( $this->stock_row( $first )['pending_payload'], 'no pending stock is queued for the marketplace' );
		$this->assertSame( [], $this->continuations() );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
	}

	/**
	 * «Site is master»: the daily read only refreshes what the marketplace holds on the existing row; the shop is not written and
	 * nothing is sent. An account that syncs no stock is not read at all.
	 *
	 * @return void
	 */
	public function test_site_mode_only_refreshes_the_remote_numbers(): void {
		$product = $this->stock_product( 'a', 101, 10 );
		$this->hook_stock();
		$this->set_stock( $product, 7 );
		$this->flush();
		$before                 = $this->stock_row( $product );
		$this->market->requests = [];

		$this->market->present['a']  = 2;
		$this->market->reserved['a'] = 1;

		$this->runner()->run( $this->account_id );

		$row = $this->stock_row( $product );

		$this->assertSame( [ [ Scripted_Stock_Pusher::READ, self::WAREHOUSE, [ 'a' ] ] ], $this->market->requests, 'one read, no push' );
		$this->assertSame( 2, (int) $row['remote_present'] );
		$this->assertSame( 1, (int) $row['remote_reserved'] );
		$this->assertSame( 7, $this->wc_stock( $product ), 'the shop is not written' );
		$this->assertSame( $before['pending_payload'], $row['pending_payload'], 'nothing new is queued for the marketplace' );
		$this->assertSame( $before['sent_rev'], $row['sent_rev'] );

		$this->market->requests = [];
		$this->set_account( [ 'stock_mode' => Wpdb_Account_Store::STOCK_OFF ] );
		$this->runner()->run( $this->account_id );

		$this->assertSame( [], $this->market->requests );
	}

	/**
	 * A link that has no sync row yet gets one with only the readback on it — in both modes — and nothing is queued to be sent.
	 *
	 * @return void
	 */
	public function test_a_fresh_link_gets_the_remote_numbers_and_no_send_in_both_modes(): void {
		foreach ( [ Wpdb_Account_Store::STOCK_MARKETPLACE, Wpdb_Account_Store::STOCK_SITE ] as $index => $mode ) {
			$this->set_account( [ 'stock_mode' => $mode ] );

			$product = $this->stock_product( 'fresh-' . $mode, 700 + $index, 10 );
			$this->hook_stock();
			$this->assertNull( $this->stock_row( $product ), 'no row before the first reconcile' );

			$this->market->present[ 'fresh-' . $mode ]  = 4;
			$this->market->reserved[ 'fresh-' . $mode ] = 1;
			$this->market->requests                     = [];

			$this->runner()->run( $this->account_id );

			$row = $this->stock_row( $product );

			$this->assertNotNull( $row, $mode );
			$this->assertSame( 4, (int) $row['remote_present'], $mode );
			$this->assertSame( 1, (int) $row['remote_reserved'], $mode );
			$this->assertNotNull( $row['remote_read_at'], $mode );
			$this->assertNull( $row['pending_payload'], $mode );
			$this->assertNull( $row['sent_payload'], $mode );
			$this->assertSame( [], $this->queue()->due( $this->account_id, Sync_State_Store::KIND_STOCK, 10 ), $mode . ': nothing to send' );
			$this->assertSame( [], $this->market->sent( Scripted_Stock_Pusher::PUSH ), $mode );
		}
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
	 * When the run's time is spent after a full page, the pass continues after that page — it is not read again — and the
	 * continuation finishes it.
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

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, $this->link_id( $products[399] ), 0 ], $continuations[0]['args'], 'after the page that used the budget' );
		$this->assertSame( 'woodev-fake-seller', $continuations[0]['group'] );
		$this->assertSame( 2, $this->wc_stock( $products[399] ), 'the second page was written' );
		$this->assertSame( 10, $this->wc_stock( $products[400] ) );

		$this->runner()->run( ...$continuations[0]['args'] );

		$this->assertSame( 2, $this->wc_stock( $products[400] ), 'the continuation reaches the end' );
		$this->assertCount( 1, $this->continuations(), 'and queues nothing more' );
		$this->assertCount( 3, $this->market->sent( Scripted_Stock_Pusher::READ ), 'every page was read once' );
	}

	/**
	 * A full page that is slow on every invocation finishes: its links were all read, so the next run starts after it.
	 *
	 * @return void
	 */
	public function test_a_consistently_slow_full_page_does_not_repeat_forever(): void {
		$this->marketplace_is_master();
		$products = $this->products_of_the_account( 200 );

		foreach ( array_keys( $products ) as $index ) {
			$this->market->present[ 'sku-' . ( $index + 1 ) ] = 5;
		}

		$slow = function () {
			$this->now += 60;

			return null;
		};

		$this->market->script = [ $slow, $slow ];
		$this->runner()->run( $this->account_id );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, $this->link_id( $products[199] ), 0 ], $continuations[0]['args'] );

		$this->runner()->run( ...$continuations[0]['args'] );

		$this->assertCount( 1, $this->continuations(), 'the pass is over' );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::READ ), 'and the page was read once' );
		$this->assertSame( 5, $this->wc_stock( $products[199] ) );
	}

	/**
	 * A short page with two warehouses: when the first group spends the time, the second is not lost — the continuation starts at
	 * it and reads nothing that was already read.
	 *
	 * @return void
	 */
	public function test_a_short_two_warehouse_page_resumes_at_the_unread_group(): void {
		$this->marketplace_is_master();
		$first  = $this->stock_product( 'a', 101, 10 );
		$second = $this->stock_product( 'b', 102, 10, [ 'warehouse_id' => 33 ] );

		$this->market->present['a'] = 1;
		$this->market->present['b'] = 2;
		$this->market->script       = [
			function () {
				$this->now += 60;

				return null;
			},
		];

		$this->runner()->run( $this->account_id );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, $this->link_id( $second ) - 1, 0 ], $continuations[0]['args'] );
		$this->assertSame( 1, $this->wc_stock( $first ) );
		$this->assertSame( 10, $this->wc_stock( $second ) );

		$this->runner()->run( ...$continuations[0]['args'] );

		$this->assertSame( 2, $this->wc_stock( $second ) );
		$this->assertSame( [ [ 'a' ], [ 'b' ] ], $this->market->sent( Scripted_Stock_Pusher::READ ), 'each group was read once' );
		$this->assertCount( 1, $this->continuations() );
	}

	/**
	 * The request budget ends inside a page: the group after the last request is read by the continuation, not skipped and not
	 * read again by every run.
	 *
	 * @return void
	 */
	public function test_the_request_budget_ending_inside_a_page_resumes_at_the_unread_group(): void {
		$this->marketplace_is_master();
		$products = [];

		for ( $i = 1; $i <= Reconcile_Stock_Runner::BUDGET_REQUESTS + 1; $i++ ) {
			$products[]                    = $this->stock_product( 'w' . $i, 2000 + $i, 10, [ 'warehouse_id' => 100 + $i ] );
			$this->market->present[ 'w' . $i ] = 3;
		}

		$this->runner()->run( $this->account_id );

		$last          = $products[ Reconcile_Stock_Runner::BUDGET_REQUESTS ];
		$continuations = $this->continuations();

		$this->assertCount( Reconcile_Stock_Runner::BUDGET_REQUESTS, $this->market->sent( Scripted_Stock_Pusher::READ ) );
		$this->assertSame( [ $this->account_id, $this->link_id( $last ) - 1, 0 ], $continuations[0]['args'] );

		$this->runner()->run( ...$continuations[0]['args'] );

		$this->assertSame( 3, $this->wc_stock( $last ) );
		$this->assertCount( Reconcile_Stock_Runner::BUDGET_REQUESTS + 1, $this->market->sent( Scripted_Stock_Pusher::READ ) );
		$this->assertCount( 1, $this->continuations() );
	}

	/**
	 * The HTTP requests a read may send are reserved BEFORE it starts: after nineteen single-request reads a read that may send
	 * twenty is not started, and the continuation sends it — no run sends more than its budget.
	 *
	 * @return void
	 */
	public function test_a_read_that_may_exceed_the_http_budget_is_not_started(): void {
		$this->marketplace_is_master();

		for ( $i = 1; $i < Reconcile_Stock_Runner::BUDGET_REQUESTS; $i++ ) { // Nineteen warehouses of one link each.
			$this->stock_product( 'one-' . $i, 3000 + $i, 10, [ 'warehouse_id' => 100 + $i ] );
			$this->market->present[ 'one-' . $i ] = 3;
		}

		$big = [];

		for ( $i = 1; $i <= Reconcile_Stock_Runner::BUDGET_REQUESTS; $i++ ) { // One warehouse of twenty links: twenty HTTP requests.
			$big[]                                = $this->stock_product( 'big-' . $i, 4000 + $i, 10, [ 'warehouse_id' => 999 ] );
			$this->market->present[ 'big-' . $i ] = 6;
		}

		$paged = new Paged_Stock_Pusher( $this->market, 1 );

		$this->runner( $paged )->run( $this->account_id );

		$continuations = $this->continuations();

		$this->assertCount( Reconcile_Stock_Runner::BUDGET_REQUESTS - 1, $this->market->sent( Scripted_Stock_Pusher::READ ), 'the twenty-request read did not start' );
		$this->assertSame( Reconcile_Stock_Runner::BUDGET_REQUESTS - 1, $paged->total, 'nineteen HTTP requests in this run' );
		$this->assertSame( [ $this->account_id, $this->link_id( $big[0] ) - 1, 0 ], $continuations[0]['args'] );
		$this->assertSame( 10, $this->wc_stock( $big[0] ) );

		$paged->total = 0;
		$this->runner( $paged )->run( ...$continuations[0]['args'] );

		$this->assertSame( Reconcile_Stock_Runner::BUDGET_REQUESTS, $paged->total, 'the continuation sends the twenty' );
		$this->assertSame( 6, $this->wc_stock( $big[0] ) );
		$this->assertCount( 1, $this->continuations(), 'and the pass ends' );
	}

	/**
	 * A read whose bound is larger than the whole budget is not starved: it runs first in a run, alone.
	 *
	 * @return void
	 */
	public function test_a_read_larger_than_the_whole_budget_runs_first_in_a_run(): void {
		$this->marketplace_is_master();
		$products = [];

		for ( $i = 1; $i <= 2; $i++ ) {
			$products[]                        = $this->stock_product( 'p' . $i, 5000 + $i, 10 );
			$this->market->present[ 'p' . $i ] = 2;
		}

		$paged = new Paged_Stock_Pusher( $this->market, Reconcile_Stock_Runner::BUDGET_REQUESTS ); // Bound 40 for the two links.

		$this->runner( $paged )->run( $this->account_id );

		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::READ ) );
		$this->assertSame( 2, $this->wc_stock( $products[0] ) );
		$this->assertSame( [], $this->continuations() );
	}

	/**
	 * The day's continuations are capped: once they are spent the pass stops with a recorded text instead of going on.
	 *
	 * @return void
	 */
	public function test_the_days_continuations_are_capped(): void {
		$this->marketplace_is_master();
		$this->products_of_the_account( 201 );
		$this->market->script = [
			function () {
				$this->now += 60;

				return null;
			},
		];
		$this->transients[ self::HOOK . '_cont_' . $this->account_id . '_' . $this->window( $this->now ) ] = Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY;

		$this->runner()->run( $this->account_id );

		$rows = $this->db->rows( 'SELECT message FROM ' . Sqlite_Tables::table( 'api_errors' ) . " WHERE code = 'reconcile_stock_failed'" );

		$this->assertSame( [], $this->continuations() );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'continuations were already used today', (string) $rows[0]['message'] );
	}

	/**
	 * Every continuation is counted, a waiting duplicate is not.
	 *
	 * @return void
	 */
	public function test_continuations_are_counted_per_account_and_day(): void {
		$scheduler = new Reconcile_Stock_Scheduler( $this->names(), $this->clock() );

		for ( $cursor = 1; $cursor <= Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY; $cursor++ ) {
			$this->assertTrue( $scheduler->continue_later( $this->account_id, $cursor, 0, 30 ) );
		}

		$this->assertTrue( $scheduler->continue_later( $this->account_id, 1, 0, 30 ), 'a waiting duplicate costs nothing' );
		$this->assertFalse( $scheduler->continue_later( $this->account_id, 999, 0, 30 ), 'the sixty-first is refused' );
		$this->assertSame( Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY, $scheduler->continuations_today( $this->account_id ) );
		$this->assertSame( 0, $scheduler->continuations_today( $this->account_id + 1 ) );
	}

	/**
	 * The allowance is a window of the SITE day counted from the hour of the daily run, the schedule's own boundary: with the run at
	 * 01:00 in Moscow, an allowance spent at 23:00 is still spent at 00:30 and is fresh again when the 01:00 run comes.
	 *
	 * @return void
	 */
	public function test_the_allowance_resets_with_the_daily_run_across_local_midnight(): void {
		Filters\expectApplied( 'woodev_fake_seller_reconcile_stock_hour' )->zeroOrMoreTimes()->andReturn( 1 );

		$now       = gmmktime( 20, 0, 0, 11, 16, 2026 ); // 23:00 on 16 November in Moscow.
		$scheduler = new Reconcile_Stock_Scheduler(
			$this->names(),
			static function () use ( &$now ): int {
				return $now;
			}
		);

		for ( $cursor = 1; $cursor <= Reconcile_Stock_Scheduler::MAX_CONTINUATIONS_PER_DAY; $cursor++ ) {
			$this->assertTrue( $scheduler->continue_later( $this->account_id, $cursor, 0, 30 ) );
		}

		$this->assertFalse( $scheduler->continue_later( $this->account_id, 1001, 0, 30 ), 'spent at 23:00' );

		$now = gmmktime( 21, 30, 0, 11, 16, 2026 ); // 00:30 on 17 November, still the window of the 16th's run.
		$this->assertFalse( $scheduler->continue_later( $this->account_id, 1002, 0, 30 ) );

		$now = gmmktime( 22, 0, 0, 11, 16, 2026 ); // 01:00 on 17 November: the daily run opens a fresh allowance.
		$this->assertSame( 0, $scheduler->continuations_today( $this->account_id ) );
		$this->assertTrue( $scheduler->continue_later( $this->account_id, 1003, 0, 30 ) );
	}

	/**
	 * In «marketplace is master» mode the listener hears about every link that was set from the marketplace or found equal, with
	 * the moment before its read and the resolved warehouse; «site is master» tells it nothing.
	 *
	 * @return void
	 */
	public function test_the_applied_listener_hears_changed_and_unchanged_links(): void {
		$this->marketplace_is_master();
		$changed   = $this->stock_product( 'a', 101, 10 );
		$unchanged = $this->stock_product( 'b', 102, 6, [ 'warehouse_id' => 33 ] );

		$this->market->present['a'] = 4;
		$this->market->present['b'] = 6;

		$heard    = [];
		$listener = static function ( $account, $link, int $started, int $warehouse ) use ( &$heard ): void {
			$heard[] = [ $account->id(), $link->wc_product_id(), $started, $warehouse ];
		};

		$this->runner( null, $listener )->run( $this->account_id );

		$this->assertSame(
			[
				[ $this->account_id, $changed, self::NOW, self::WAREHOUSE ], // The account's warehouse, resolved: the link has no override.
				[ $this->account_id, $unchanged, self::NOW, 33 ],
			],
			$heard
		);

		$heard = [];
		$this->set_account( [ 'stock_mode' => Wpdb_Account_Store::STOCK_SITE ] );
		$this->runner( null, $listener )->run( $this->account_id );

		$this->assertSame( [], $heard );
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
	 * A pass that has waited as often as the ceiling allows queues no further continuation; the next daily run starts again.
	 *
	 * @return void
	 */
	public function test_a_wait_past_the_ceiling_ends_the_pass(): void {
		$this->marketplace_is_master();
		$this->stock_product( 'a', 101, 10 );
		$this->licensed = false;

		$this->runner()->run( $this->account_id, 7, 0, Account_Job::MAX_PUT_OFFS );

		$this->assertSame( [], $this->market->requests );
		$this->assertSame( [], $this->continuations() );
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
		$this->assertSame( [ $this->account_id, 7, 0, 1 ], $continuations[0]['args'], 'the wait is counted apart from the attempts' );
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
	 * Every enabled account that syncs stock gets a daily run, whatever its mode, in the plugin's group; an account that is
	 * switched off or syncs no stock gets none; a repeated check adds nothing.
	 *
	 * @return void
	 */
	public function test_every_enabled_stock_sync_account_gets_a_daily_run_once(): void {
		$this->another_account( Wpdb_Account_Store::STOCK_OFF );
		$this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE, false );
		$master = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );

		$this->runner()->ensure_schedules();
		$this->transients = [];
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id, $master ], $this->daily_accounts(), 'the site-mode account and the marketplace-mode one' );
		$this->assertCount( 2, $this->scheduler->actions );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->actions[0]['group'] );
	}

	/**
	 * The check runs at most once an hour.
	 *
	 * @return void
	 */
	public function test_the_schedule_check_runs_once_an_hour(): void {
		$this->runner()->ensure_schedules();
		$second = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id ], $this->daily_accounts(), 'the second check within the hour changed nothing' );
		$this->assertNotContains( $second, $this->daily_accounts() );
		$this->assertSame( [ 'woodev_fake_seller_reconcile_stock_check' ], array_keys( $this->transients ) );
	}

	/**
	 * Switching the account off, turning its stock sync off, or deleting it removes its daily run on the next check; the other
	 * accounts keep theirs, and a continuation in flight is not touched.
	 *
	 * @return void
	 */
	public function test_a_daily_run_goes_when_its_account_no_longer_needs_it(): void {
		$switched_off = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$sync_off     = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );
		$deleted      = $this->another_account( Wpdb_Account_Store::STOCK_MARKETPLACE );

		$this->runner()->ensure_schedules();
		$this->assertSame( [ $this->account_id, $switched_off, $sync_off, $deleted ], $this->daily_accounts() );

		( new Reconcile_Stock_Scheduler( $this->names() ) )->continue_later( $switched_off, 5, 0, 30 );

		$this->accounts()->set_enabled( $switched_off, false );
		$this->set_account_of( $sync_off, [ 'stock_mode' => Wpdb_Account_Store::STOCK_OFF ] );
		$this->accounts()->set_enabled( $deleted, false ); // The deletion switches the account off first.
		$token = (string) $this->accounts()->claim( $deleted, Job_Gate::CLAIM_TTL );
		$this->assertTrue( $this->accounts()->delete( $deleted, $token ) );

		$this->transients = [];
		$this->runner()->ensure_schedules();

		$this->assertSame( [ $this->account_id ], $this->daily_accounts() );
		$this->assertCount( 1, $this->continuations(), 'a continuation in flight finishes' );
	}

	/**
	 * An account whose stock sync is turned back on gets its run again.
	 *
	 * @return void
	 */
	public function test_a_daily_run_returns_with_the_stock_sync(): void {
		$this->set_account( [ 'stock_mode' => Wpdb_Account_Store::STOCK_OFF ] );
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
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->runner()->ensure_schedules();

		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'reconcile_stock could not be scheduled', $this->log[0] );
	}

	/**
	 * The allowance window the scheduler names at a moment, with the daily run at the default hour in Moscow.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function window( int $time ): string {
		return ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( new \DateTimeZone( 'Europe/Moscow' ) )->modify( '-' . Reconcile_Stock_Scheduler::DEFAULT_HOUR . ' hours' )->format( 'Ymd' );
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
