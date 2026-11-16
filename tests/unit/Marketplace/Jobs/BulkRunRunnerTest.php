<?php
/**
 * The lifecycle of a bulk run: start, tick, stop, recovery, and the fences against a tick that lost the run.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Scripted_Bulk_Run_Lock;
use Woodev\Tests\Unit\Marketplace\Support\Scripted_Bulk_Run_Worker;

/**
 * The named lock is a script here (SQLite has no `GET_LOCK`); the two-connection integration test proves the real one.
 *
 * @covers \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner
 * @covers \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler
 */
final class BulkRunRunnerTest extends Export_Test_Case {
	private const HOOK = 'woodev_fake_seller_bulk_run';

	/**
	 * @var Bulk_Run_Store
	 */
	private $runs;

	/**
	 * @var Scripted_Bulk_Run_Lock
	 */
	private $lock;

	/**
	 * @var Scripted_Bulk_Run_Worker
	 */
	private $worker;

	/**
	 * Seconds the clock moves on every reading.
	 *
	 * @var float
	 */
	private $step = 0.0;

	/**
	 * @var float
	 */
	private $seconds = 0.0;

	/**
	 * Tokens handed out, in order.
	 *
	 * @var int
	 */
	private $tokens = 0;

	/**
	 * Users the runner acted as.
	 *
	 * @var int[]
	 */
	private $users = [];

	protected function setUp(): void {
		parent::setUp();

		$this->runs    = new Bulk_Run_Store( $this->db, Sqlite_Tables::table( 'bulk_runs' ), [ $this, 'clock_now' ] );
		$this->lock    = new Scripted_Bulk_Run_Lock();
		$this->worker  = new Scripted_Bulk_Run_Worker();
		$this->step    = 0.0;
		$this->seconds = 0.0;
		$this->tokens  = 0;
		$this->users   = [];

		Functions\when( 'wp_set_current_user' )->alias(
			function ( $id ): void {
				$this->users[] = (int) $id;
			}
		);
	}

	/**
	 * @return int
	 */
	public function clock_now(): int {
		return $this->now;
	}

	/**
	 * @param callable|null $log Log.
	 * @return Bulk_Run_Runner
	 */
	private function runner( ?callable $log = null ): Bulk_Run_Runner {
		return new Bulk_Run_Runner(
			$this->runs,
			$this->lock,
			new Bulk_Run_Scheduler( $this->names() ),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->worker,
			$log,
			function (): float {
				$this->seconds += $this->step;

				return $this->seconds;
			},
			function (): string {
				return str_pad( (string) ++$this->tokens, 32, '0', STR_PAD_LEFT );
			}
		);
	}

	/**
	 * @return string The run id.
	 */
	private function start( ?Bulk_Run_Runner $runner = null ): string {
		$run_id = ( $runner ?? $this->runner() )->start( $this->account_id, 'sync_stock_on', [], [ 'search' => '', 'filters' => [] ], 3, 100 );
		$this->assertNotNull( $run_id );

		return (string) $run_id;
	}

	private function current(): Bulk_Run {
		$run = $this->runs->find( $this->account_id );
		$this->assertNotNull( $run );

		return $run;
	}

	/**
	 * @return int Ticks waiting in the queue.
	 */
	private function queued_ticks(): int {
		return count( $this->scheduler->of( self::HOOK ) );
	}

	public function test_a_start_stores_the_run_and_queues_its_first_tick(): void {
		$run_id = $this->start();

		$this->assertTrue( $this->current()->is_running() );
		$this->assertSame( [ [ $this->account_id, $run_id ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ) );
	}

	public function test_a_start_while_a_run_is_going_is_refused(): void {
		$this->start();

		$this->assertNull( $this->runner()->start( $this->account_id, 'send_stock', [], [ 'search' => '', 'filters' => [] ], 3, 5 ) );
		$this->assertSame( 'sync_stock_on', $this->current()->action(), 'The running one is untouched.' );
	}

	public function test_a_start_right_after_a_finished_run_succeeds_at_once(): void {
		$runner = $this->runner();
		$first  = $this->start( $runner );

		$runner->tick( $this->account_id, $first );
		$this->assertSame( Bulk_Run::DONE, $this->current()->state() );

		$second = $this->start( $runner );

		$this->assertNotSame( $first, $second );
		$this->assertTrue( $this->current()->is_running() );
	}

	public function test_a_start_that_cannot_queue_its_tick_ends_the_run_and_says_so(): void {
		$this->scheduler->zero_for[ self::HOOK ] = true;
		$runner                                  = $this->runner();

		try {
			$runner->start( $this->account_id, 'sync_stock_on', [], [ 'search' => '', 'filters' => [] ], 3, 100 );
			$this->fail( 'Action Scheduler refused the tick.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( Bulk_Run::STOPPED, $this->current()->state() );
		}

		unset( $this->scheduler->zero_for[ self::HOOK ] );
		$this->assertNotNull( $runner->start( $this->account_id, 'sync_stock_on', [], [ 'search' => '', 'filters' => [] ], 3, 100 ), 'A run that never queued does not block the next one.' );
	}

	public function test_a_tick_works_the_parts_merges_counts_and_the_report_and_finishes_done(): void {
		$this->worker->parts = [
			[
				'groups' => [ [ 'kind' => 'skipped', 'reason' => 'нет цены', 'count' => 1, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'A' ] ] ],
			],
			[
				'groups'   => [ [ 'kind' => 'skipped', 'reason' => 'нет цены', 'count' => 2, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'B' ] ] ],
				'finished' => true,
			],
		];
		$run_id              = $this->start();

		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( Bulk_Run::DONE, $run->state() );
		$this->assertSame( 4, $run->counts()['products_done'] );
		$this->assertSame( 6, $run->counts()['offers_accepted'] );
		$this->assertSame( 2, $run->counts()['offers_skipped'] );
		$this->assertSame( 100, $run->counts()['products_total'], 'The estimate is kept.' );
		$this->assertSame( 3, $run->report()[0]['count'] );
		$this->assertSame( [ 'A', 'B' ], $run->report()[0]['examples'] );
		$this->assertSame( 1, $this->lock->releases );
		$this->assertSame( 1, $this->queued_ticks(), 'Only the start queued a tick: a finished run queues none.' );
	}

	public function test_a_tick_acts_as_the_user_who_started_the_run(): void {
		$run_id = $this->start();

		$this->runner()->tick( $this->account_id, $run_id );

		$this->assertSame( [ 3 ], $this->users );
	}

	public function test_a_tick_that_starts_after_another_took_the_lock_exits_before_it_reads_the_row_or_writes_a_product(): void {
		$run_id = $this->start();
		$before = count( $this->db->statements );

		$this->lock->taken_by_other = true;
		$this->runner()->tick( $this->account_id, $run_id );

		$this->assertSame( [], $this->worker->cursors, 'No product was touched.' );
		$this->assertSame( $before, count( $this->db->statements ), 'The row was not even read.' );
		$this->assertSame( 0, $this->lock->releases, 'It releases nothing it never took.' );
		$this->assertTrue( $this->current()->is_running() );
	}

	public function test_a_spent_budget_saves_the_part_and_queues_the_next_tick_that_goes_on_from_the_saved_cursor(): void {
		$this->step          = Bulk_Run_Runner::BUDGET_SECONDS + 1;
		$this->worker->parts = [ [], [] ];
		$run_id              = $this->start();

		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertTrue( $run->is_running() );
		$this->assertSame( [ 'parent' => 10, 'child' => 0 ], $run->cursor() );
		$this->assertSame( 2, $run->counts()['products_done'] );
		$this->assertSame( 2, $this->queued_ticks(), 'The start and the continuation.' );

		$this->runner()->tick( $this->account_id, $run_id );

		$this->assertSame( [ [], [ 'parent' => 10, 'child' => 0 ] ], $this->worker->cursors, 'The second tick continues from what the first saved.' );
		$this->assertSame( 4, $this->current()->counts()['products_done'] );
	}

	public function test_a_tick_of_an_old_run_does_nothing(): void {
		$runner = $this->runner();
		$old    = $this->start( $runner );

		$runner->tick( $this->account_id, $old );
		$new = $this->start( $runner );
		$runner->tick( $this->account_id, $old );

		$this->assertSame( 1, count( $this->worker->cursors ), 'Only the first tick worked.' );
		$this->assertSame( $new, $this->current()->run_id() );
		$this->assertTrue( $this->current()->is_running() );
	}

	public function test_stop_with_the_lock_free_finishes_the_run_cancelled(): void {
		$run_id = $this->start();

		$this->assertSame( Bulk_Run_Runner::STOP_CANCELLED, $this->runner()->stop( $this->account_id, $run_id ) );

		$this->assertSame( Bulk_Run::CANCELLED, $this->current()->state() );
		$this->assertSame( 1, $this->lock->releases );

		$this->runner()->tick( $this->account_id, $run_id );
		$this->assertSame( [], $this->worker->cursors, 'A queued tick finds a finished run.' );
	}

	public function test_stop_with_the_lock_held_sets_cancel_and_the_tick_finishes_cancelled_after_its_part(): void {
		$this->worker->parts = [ [], [] ];
		$run_id              = $this->start();
		$runner = $this->runner();

		$this->worker->during = function () use ( $runner, $run_id ): void {
			// The merchant presses «Остановить» while the tick works: the lock is held, so the stop only sets the flag.
			$this->lock->taken_by_other = true;
			$this->assertSame( Bulk_Run_Runner::STOP_REQUESTED, $runner->stop( $this->account_id, $run_id ) );
			$this->lock->taken_by_other = false;
		};

		$runner->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( Bulk_Run::CANCELLED, $run->state() );
		$this->assertSame( 2, $run->counts()['products_done'], 'The part that was running is counted and saved.' );
		$this->assertSame( 1, count( $this->worker->cursors ), 'No second part.' );
	}

	public function test_a_cancel_that_arrives_during_the_save_of_a_part_survives_it(): void {
		$this->step = 0.0;
		$run_id     = $this->start();
		$runner     = $this->runner();

		$this->worker->parts  = [ [ 'finished' => false ], [ 'finished' => false ] ];
		$this->worker->during = function ( int $call ) use ( $run_id ): void {
			if ( 1 === $call ) {
				$this->runs->request_cancel( $this->account_id, $run_id );
			}
		};

		$runner->tick( $this->account_id, $run_id );

		$this->assertSame( Bulk_Run::CANCELLED, $this->current()->state(), 'The save of the first part did not erase the flag.' );
		$this->assertCount( 1, $this->worker->cursors );
	}

	public function test_a_stop_confirmed_for_a_run_that_finished_after_a_new_one_started_does_not_stop_the_new_one(): void {
		$runner = $this->runner();
		$first  = $this->start( $runner );

		$runner->tick( $this->account_id, $first );
		$second = $this->start( $runner );

		// Two admins: the second confirms a stop for R1 after R1 finished and R2 started.
		$this->assertSame( Bulk_Run_Runner::STOP_GONE, $runner->stop( $this->account_id, $first ) );
		$this->lock->taken_by_other = true;
		$this->assertSame( Bulk_Run_Runner::STOP_GONE, $runner->stop( $this->account_id, $first ), 'The same with a tick working.' );

		$run = $this->current();
		$this->assertSame( $second, $run->run_id() );
		$this->assertTrue( $run->is_running() );
		$this->assertFalse( $run->cancel_requested() );
	}

	public function test_a_run_whose_next_tick_was_lost_is_recovered_by_the_activity_check(): void {
		$run_id = $this->start();
		$runner = $this->runner();

		$this->scheduler->actions = []; // A crash between the save and the enqueue.

		$runner->recover( $this->account_id );

		$this->assertSame( [ [ $this->account_id, $run_id ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ) );
		$this->assertSame( 1, $this->lock->releases, 'The probe of the lock is released at once.' );
	}

	public function test_the_recovery_queues_nothing_while_a_tick_waits_or_works(): void {
		$run_id = $this->start();
		$runner = $this->runner();

		$runner->recover( $this->account_id );
		$this->assertSame( 1, $this->queued_ticks(), 'A tick waits already.' );

		$this->scheduler->actions   = [];
		$this->lock->taken_by_other = true;
		$runner->recover( $this->account_id );
		$this->assertSame( 0, $this->queued_ticks(), 'A tick that holds the lock queues the next one itself.' );

		$this->lock->taken_by_other = false;
		$runner->tick( $this->account_id, $run_id );
		$this->scheduler->actions = [];
		$runner->recover( $this->account_id );
		$this->assertSame( 0, $this->queued_ticks(), 'A finished run needs none.' );
	}

	public function test_the_sweep_recovers_every_running_account(): void {
		$run_id = $this->start();

		$this->scheduler->actions = [];
		$this->runner()->tick( 0, '' );

		$this->assertSame( [ [ $this->account_id, $run_id ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ) );
		$this->assertSame( [], $this->worker->cursors, 'The sweep works nothing itself.' );
	}

	public function test_the_sweep_exists_once_and_recurs(): void {
		$scheduler = new Bulk_Run_Scheduler( $this->names() );

		$scheduler->ensure_sweep();
		$scheduler->ensure_sweep();

		$sweeps = $this->scheduler->of( self::HOOK );
		$this->assertCount( 1, $sweeps );
		$this->assertSame( [ 0, '' ], $sweeps[0]['args'] );
		$this->assertSame( 'recurring', $sweeps[0]['kind'] );
	}

	public function test_a_refusal_of_the_worker_ends_the_run_stopped_with_its_sentence(): void {
		$run_id = $this->start();

		$this->worker->refusal = 'Лицензия не действует.';
		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( Bulk_Run::STOPPED, $run->state() );
		$this->assertSame( 'Лицензия не действует.', $run->cursor()['stopped'] );
		$this->assertSame( [], $this->worker->cursors, 'No part was worked.' );
	}

	public function test_a_run_of_a_disabled_account_stops(): void {
		$run_id = $this->start();
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->runner()->tick( $this->account_id, $run_id );

		$this->assertSame( Bulk_Run::STOPPED, $this->current()->state() );
		$this->assertSame( [], $this->worker->cursors );
	}

	public function test_a_busy_part_saves_nothing_and_the_same_part_is_tried_again_later(): void {
		$run_id = $this->start();

		$this->worker->parts = [ [ 'busy' => 30, 'finished' => false ] ];
		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertTrue( $run->is_running() );
		$this->assertSame( [], $run->cursor() );
		$this->assertSame( 0, $run->counts()['offers_failed'], 'A busy answer is not a failure.' );
		$this->assertSame( 2, $this->queued_ticks() );
		$this->assertSame( 30, $this->scheduler->of( self::HOOK )[1]['delay'] );
	}

	public function test_a_tick_that_lost_the_lock_before_a_part_writes_nothing_and_queues_nothing(): void {
		$run_id = $this->start();

		$this->lock->held_script = [ false ];
		$this->runner()->tick( $this->account_id, $run_id );

		$this->assertSame( [], $this->worker->cursors );
		$this->assertSame( 1, $this->queued_ticks(), 'No next tick.' );
		$this->assertSame( [], $this->current()->cursor() );
	}

	public function test_a_tick_that_loses_the_lock_inside_a_part_stops_without_a_save_or_a_next_tick(): void {
		$run_id = $this->start();

		// Asked once before the part, then false inside it, at the first product write.
		$this->lock->held_script = [ true, false ];
		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertTrue( $run->is_running() );
		$this->assertSame( 0, $run->counts()['products_done'] );
		$this->assertSame( 1, $this->queued_ticks() );
	}

	public function test_a_save_that_changes_no_row_stops_the_tick_and_does_not_overwrite_the_new_owner(): void {
		$this->step          = Bulk_Run_Runner::BUDGET_SECONDS + 1;
		$this->worker->parts = [ [], [] ];
		$run_id              = $this->start();

		// After A lost its connection, tick B took the lock and wrote its token and its progress while A's part was still running.
		$this->worker->during = function () use ( $run_id ): void {
			$this->runs->take_tick( $this->account_id, $run_id, 'tick-of-b' );
			$this->runs->save( $this->account_id, $run_id, 'tick-of-b', [ 'parent' => 99, 'child' => 0 ], [ 'products_done' => 50 ], [] );
		};

		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( [ 'parent' => 99, 'child' => 0 ], $run->cursor(), 'What B saved stands.' );
		$this->assertSame( 50, $run->counts()['products_done'] );
		$this->assertTrue( $run->is_running() );
		$this->assertSame( 1, $this->queued_ticks(), 'A queues no tick.' );
	}

	public function test_an_error_in_a_later_part_ends_the_run_with_what_the_earlier_parts_saved(): void {
		$run_id = $this->start();

		$this->worker->parts     = [ [ 'groups' => [ [ 'kind' => 'skipped', 'reason' => 'нет цены', 'count' => 1, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'A' ] ] ] ], [] ];
		$this->worker->throws    = new \RuntimeException( 'database gone' );
		$this->worker->throws_at = 2;
		$this->runner()->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( Bulk_Run::STOPPED, $run->state() );
		$this->assertSame( 3, $run->counts()['offers_accepted'], 'Part 1 was saved and stays counted.' );
		$this->assertSame( 2, $run->counts()['products_done'] );
		$this->assertSame( 10, $run->cursor()['parent'], 'The cursor of part 1, not the one the tick started from.' );
		$this->assertSame( 'нет цены', $run->report()[0]['reason'], 'And its skip reasons.' );
		$this->assertArrayHasKey( 'stopped', $run->cursor() );
	}

	public function test_an_error_in_a_part_stops_the_run_with_a_sentence_and_logs_it(): void {
		$run_id = $this->start();
		$lines  = [];

		$this->worker->throws = new \RuntimeException( 'database gone' );
		$this->runner(
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		)->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertSame( Bulk_Run::STOPPED, $run->state() );
		$this->assertStringContainsString( 'внутренней ошибки', $run->cursor()['stopped'] );
		$this->assertStringContainsString( 'database gone', $lines[0] );
		$this->assertSame( 1, $this->lock->releases );
	}
}
