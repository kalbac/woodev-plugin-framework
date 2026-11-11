<?php
/**
 * The `drain_events` runner: dispatch, retry ladder, fencing, budget.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Jobs\Drain_Events_Runner;
use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `DrainEventsRunnerTest`: handlers by type, the backoff ladder 1, 3, 7, 15, 30 minutes, five
 * attempts then `failed`, a stale runner's result discarded, a disabled account left alone, and the licence gate.
 */
final class DrainEventsRunnerTest extends Sync_Test_Case {
	private const HOOK = 'woodev_fake_seller_drain_events';

	/**
	 * Ledger.
	 *
	 * @var Wpdb_Event_Ledger
	 */
	private $ledger;

	/**
	 * Handlers.
	 *
	 * @var Event_Handler_Registry
	 */
	private $handlers;

	/**
	 * Runner under test.
	 *
	 * @var Drain_Events_Runner
	 */
	private $runner;

	/**
	 * Clock of the runner's budget.
	 *
	 * @var float
	 */
	private $budget_clock = 0.0;

	/**
	 * The enabled account.
	 *
	 * @var int
	 */
	private $on = 0;

	/**
	 * A disabled account.
	 *
	 * @var int
	 */
	private $off = 0;

	/**
	 * Builds the runner over a database with an enabled and a disabled account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->budget_clock = 0.0;
		$this->on           = $this->account_id;
		$this->off          = $this->accounts()->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Второй', false, [ 'cabinet_ref' => 'cabinet-0002', 'api_token' => 'tok-2' ] ) );
		$this->ledger       = $this->ledger();
		$this->handlers     = new Event_Handler_Registry();
		$this->runner       = $this->runner_with( $this->ledger );
	}

	/**
	 * The skeleton accepts every type through the no-op handler.
	 *
	 * @return void
	 */
	public function test_an_event_with_no_registered_handler_completes_through_the_noop_handler(): void {
		$this->store( $this->on, 'a' );

		$this->runner->run( $this->on );

		$this->assertSame( [ 'done' ], $this->column( 'status' ) );
		$this->assertSame( [ 'no handler registered yet for order_created' ], $this->column( 'result' ) );
	}

	/**
	 * A registered handler gets the events of its type, with their payload, and its text is the result.
	 *
	 * @return void
	 */
	public function test_events_go_to_the_handler_of_their_type(): void {
		$seen = [];
		$this->handlers->register(
			'order_changed',
			$this->handler(
				static function ( Inbound_Event $event ) use ( &$seen ): string {
					$seen[] = $event->payload();

					return 'refreshed';
				}
			)
		);
		$this->store( $this->on, 'a', 'order_changed' );
		$this->store( $this->on, 'b', 'order_created' );

		$this->runner->run( (string) $this->on );

		$this->assertSame( [ [ 'k' => 'a' ] ], $seen );
		$this->assertSame( [ 'refreshed', 'no handler registered yet for order_created' ], $this->column( 'result' ) );
	}

	/**
	 * The ladder is 1, 3, 7, 15 minutes between the first four attempts; the fifth failure is final.
	 *
	 * @return void
	 */
	public function test_a_failing_handler_retries_on_the_ladder_and_fails_on_the_fifth_attempt(): void {
		$this->handlers->register(
			'order_created',
			$this->handler(
				static function (): string {
					throw new \RuntimeException( 'the marketplace is down' );
				}
			)
		);
		$this->store( $this->on, 'a' );

		foreach ( [ 60, 180, 420, 900 ] as $delay ) {
			$this->runner->run( $this->on );
			$row = $this->db->rows( 'SELECT status, next_attempt_at, result FROM ' . Sqlite_Tables::table( 'events' ) )[0];

			$this->assertSame( 'retry', $row['status'] );
			$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + $delay ), $row['next_attempt_at'] );
			$this->assertStringContainsString( 'RuntimeException: the marketplace is down', $row['result'] );

			$this->runner->run( $this->on );
			$this->assertSame( 'retry', $this->column( 'status' )[0], 'not due yet: untouched' );

			$this->now += $delay;
		}

		$this->runner->run( $this->on );

		$this->assertSame( [ 'failed' ], $this->column( 'status' ) );
		$this->assertSame( [ '5' ], array_map( 'strval', $this->column( 'attempts' ) ) );
		$this->assertStringContainsString( 'the marketplace is down', (string) $this->column( 'result' )[0] );
	}

	/**
	 * The ladder as data: 1, 3, 7, 15, 30 minutes, and the last step repeats.
	 *
	 * @return void
	 */
	public function test_the_backoff_ladder(): void {
		$this->assertSame( [ 60, 180, 420, 900, 1800, 1800 ], array_map( [ Drain_Events_Runner::class, 'delay_after' ], [ 1, 2, 3, 4, 5, 9 ] ) );
	}

	/**
	 * One failing event does not stop the rest of the batch.
	 *
	 * @return void
	 */
	public function test_one_failure_does_not_stop_the_batch(): void {
		$this->handlers->register(
			'order_changed',
			$this->handler(
				static function (): string {
					throw new \RuntimeException( 'boom' );
				}
			)
		);
		$this->store( $this->on, 'a', 'order_changed' );
		$this->store( $this->on, 'b' );

		$this->runner->run( $this->on );

		$this->assertSame( [ 'retry', 'done' ], $this->column( 'status' ) );
	}

	/**
	 * A disabled or missing account is left alone, silently: its events stay new.
	 *
	 * @return void
	 */
	public function test_a_disabled_or_missing_account_is_not_drained(): void {
		$this->store( $this->off, 'a' );

		$this->runner->run( $this->off );
		$this->runner->run( 99 );

		$this->assertSame( [ 'new' ], $this->column( 'status' ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * Only the asked account's events are worked.
	 *
	 * @return void
	 */
	public function test_other_accounts_events_are_not_touched(): void {
		$this->accounts()->set_enabled( $this->off, true );
		$this->store( $this->off, 'a' );
		$this->store( $this->on, 'b' );

		$this->runner->run( $this->on );

		$this->assertSame( [ 'new', 'done' ], $this->column( 'status' ) );
	}

	/**
	 * A full batch of 50 asks for another run at once.
	 *
	 * @return void
	 */
	public function test_a_full_batch_schedules_another_drain(): void {
		$this->store_many( Drain_Events_Runner::BATCH_SIZE + 3 );

		$this->runner->run( $this->on );

		$this->assertCount( Drain_Events_Runner::BATCH_SIZE, array_keys( $this->column( 'status' ), 'done', true ) );
		$this->assertSame( [ 'single:' . self::HOOK . ':pending' ], $this->scheduler->summary() );
		$this->assertSame( 0, $this->scheduler->actions[0]['delay'] );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->actions[0]['group'] );
	}

	/**
	 * The drain that is running is itself in the queue as «in progress». It must not stop the runner from queueing its
	 * successor, or 53 due events leave 3 waiting for the five-minute sweep.
	 *
	 * @return void
	 */
	public function test_a_full_batch_queues_its_successor_while_the_current_action_is_in_progress(): void {
		$this->scheduler->add( self::HOOK, [ $this->on ], 'async' );
		$this->scheduler->start_running( self::HOOK );
		$this->store_many( Drain_Events_Runner::BATCH_SIZE + 3 );

		$this->runner->run( $this->on );

		$this->assertSame( [ 'async:' . self::HOOK . ':in-progress', 'single:' . self::HOOK . ':pending' ], $this->scheduler->summary() );
	}

	/**
	 * A successor that is already pending is not queued twice.
	 *
	 * @return void
	 */
	public function test_a_pending_successor_is_not_queued_twice(): void {
		$this->scheduler->add( self::HOOK, [ $this->on ], 'async' );
		$this->store_many( Drain_Events_Runner::BATCH_SIZE + 3 );

		$this->runner->run( $this->on );

		$this->assertCount( 1, $this->scheduler->actions );
	}

	/**
	 * Past the 20 s budget the runner starts nothing new and asks for a run after the leases end; the rest are not lost.
	 *
	 * @return void
	 */
	public function test_the_runner_stops_starting_events_after_its_budget(): void {
		$this->handlers->register(
			'order_created',
			$this->handler(
				function (): string {
					$this->budget_clock += Drain_Events_Runner::BUDGET_SECONDS + 1;

					return 'slow';
				}
			)
		);
		$this->store( $this->on, 'a' );
		$this->store( $this->on, 'b' );

		$this->runner->run( $this->on );

		$this->assertSame( [ 'done', 'processing' ], $this->column( 'status' ) );
		$this->assertSame( [ 'single:' . self::HOOK . ':pending' ], $this->scheduler->summary() );
		$this->assertSame( Drain_Events_Runner::REQUEUE_SECONDS, $this->scheduler->actions[0]['delay'] );

		$this->now += Wpdb_Event_Ledger::CLAIM_TTL + 1;
		$this->runner->run( $this->on );

		$this->assertSame( [ 'done', 'done' ], $this->column( 'status' ) );
	}

	/**
	 * A runner that stalls past its lease, is replaced and then resumes, changes nothing. The row keeps the replacement's result.
	 *
	 * @return void
	 */
	public function test_a_stalled_runner_that_resumes_after_replacement_changes_nothing(): void {
		$replacement = $this->runner;
		$stalled     = $this->runner_with( $this->ledger() );
		$calls       = 0;

		$this->handlers->register(
			'order_created',
			$this->handler(
				function () use ( $replacement, &$calls ): string {
					++$calls;

					if ( 1 === $calls ) {
						// The first runner stalls beyond its lease; a replacement claims the row and finishes it.
						$this->now += Wpdb_Event_Ledger::CLAIM_TTL + 1;
						$replacement->run( $this->on );

						return 'stale result';
					}

					return 'fresh result';
				}
			)
		);
		$this->store( $this->on, 'a' );

		$stalled->run( $this->on );

		$this->assertSame( [ 'done' ], $this->column( 'status' ) );
		$this->assertSame( [ 'fresh result' ], $this->column( 'result' ) );
		$this->assertSame( 2, $calls );
	}

	/**
	 * A failure of the ledger itself never escapes the runner.
	 *
	 * @return void
	 */
	public function test_a_broken_ledger_does_not_throw_out_of_the_runner(): void {
		$this->store( $this->on, 'a' );

		$this->runner_with( new Wpdb_Event_Ledger( $this->db, 'wp_no_such_table', $this->clock() ) )->run( $this->on );

		$this->assertSame( [ 'new' ], $this->column( 'status' ) );
	}

	/**
	 * The runner answers both the drain hook and the sweep hook of its plugin.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_drain_and_the_sweep(): void {
		$this->runner->register();

		$this->assertNotFalse( has_action( self::HOOK, [ $this->runner, 'run' ] ) );
		$this->assertNotFalse( has_action( 'woodev_fake_seller_drain_events_sweep', [ $this->runner, 'run' ] ) );
	}

	/**
	 * With the licence out of force the drain claims nothing and calls no handler; the events stay `new` and the drain asks to
	 * run again in an hour. A renewed licence lets the next run process what accumulated.
	 *
	 * @return void
	 */
	public function test_without_a_licence_nothing_is_claimed_and_the_drain_retries_in_an_hour(): void {
		$calls = 0;
		$this->handlers->register(
			'order_created',
			$this->handler(
				static function () use ( &$calls ): string {
					++$calls;

					return 'handled';
				}
			)
		);

		$this->licensed = false;
		$runner         = $this->runner_with(
			$this->ledger,
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			)
		);
		$this->store( $this->on, 'a' );
		$this->store( $this->on, 'b' );

		$runner->run( $this->on );

		$this->assertSame( 0, $calls, 'no handler ran' );
		$this->assertSame( [ 'new', 'new' ], $this->column( 'status' ), 'nothing was claimed or completed' );
		$this->assertSame( [ null, null ], $this->column( 'claim_token' ) );
		$this->assertSame( [ '0', '0' ], array_map( 'strval', $this->column( 'attempts' ) ) );
		$this->assertSame( [ 'single:' . self::HOOK . ':pending' ], $this->scheduler->summary() );
		$this->assertEqualsWithDelta( Drain_Events_Runner::LICENSE_RETRY_SECONDS, $this->scheduler->of( self::HOOK )[0]['delay'], 2 );

		$this->licensed = true;
		$runner->run( $this->on );

		$this->assertSame( 2, $calls );
		$this->assertSame( [ 'done', 'done' ], $this->column( 'status' ) );
	}

	/**
	 * The scheduler establishes the recurring sweep before the immediate drain, once, and reports when only the sweep is left.
	 *
	 * @return void
	 */
	public function test_the_scheduler_puts_the_sweep_first_and_reports_a_missed_drain(): void {
		$scheduler = new Drain_Scheduler( $this->names() );

		$this->assertTrue( $scheduler->schedule( $this->on ) );
		$this->assertTrue( $scheduler->schedule( $this->on ), 'a pending drain already covers the account' );
		$this->assertSame( [ 'recurring:woodev_fake_seller_drain_events_sweep:pending', 'async:' . self::HOOK . ':pending' ], $this->scheduler->summary() );

		$this->scheduler->actions           = [];
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->assertFalse( $scheduler->schedule( $this->on ), 'only the sweep will process the event' );
		$this->assertSame( [ 'recurring:woodev_fake_seller_drain_events_sweep:pending' ], $this->scheduler->summary() );
	}

	/**
	 * When not even the sweep can be established the scheduler throws: nothing is certain to process the event.
	 *
	 * @return void
	 */
	public function test_the_scheduler_throws_when_the_sweep_cannot_be_established(): void {
		$this->scheduler->zero_for['woodev_fake_seller_drain_events_sweep'] = true;

		$this->expectException( \RuntimeException::class );
		( new Drain_Scheduler( $this->names() ) )->schedule( $this->on );
	}

	/**
	 * Runner budget clock: it stands still unless a test moves it.
	 *
	 * @return float
	 */
	public function budget_time(): float {
		return $this->budget_clock;
	}

	/**
	 * The ledger over the test clock.
	 *
	 * @return Wpdb_Event_Ledger
	 */
	private function ledger(): Wpdb_Event_Ledger {
		return new Wpdb_Event_Ledger( $this->db, Sqlite_Tables::table( 'events' ), $this->clock() );
	}

	/**
	 * Builds a runner over a ledger.
	 *
	 * @param Wpdb_Event_Ledger $ledger  Ledger.
	 * @param License_Gate|null $license Licence gate.
	 * @return Drain_Events_Runner
	 */
	private function runner_with( Wpdb_Event_Ledger $ledger, ?License_Gate $license = null ): Drain_Events_Runner {
		return new Drain_Events_Runner(
			$ledger,
			new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ) ),
			$this->handlers,
			new Drain_Scheduler( $this->names() ),
			[ $this, 'budget_time' ],
			$license
		);
	}

	/**
	 * Stores an event of an account.
	 *
	 * @param int    $account Account id.
	 * @param string $key     Dedup key.
	 * @param string $type    Event type.
	 * @return void
	 */
	private function store( int $account, string $key, string $type = 'order_created' ): void {
		$this->ledger->record( new Inbound_Event( $account, $type, $key, [ 'k' => $key ], new \DateTimeImmutable( '@' . self::NOW ), '203.0.113.7', true ) );
	}

	/**
	 * Stores several events of the enabled account.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function store_many( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->store( $this->on, 'k' . $i );
		}
	}

	/**
	 * A handler that does what a closure says.
	 *
	 * @param callable $work Receives the event, returns the result text.
	 * @return Event_Handler
	 */
	private function handler( callable $work ): Event_Handler {
		return new class( $work ) implements Event_Handler {
			/**
			 * Work.
			 *
			 * @var callable
			 */
			private $work;

			/**
			 * Creates the handler.
			 *
			 * @param callable $work Work.
			 */
			public function __construct( callable $work ) {
				$this->work = $work;
			}

			/**
			 * Handles an event.
			 *
			 * @param Inbound_Event $event Event.
			 * @return string
			 */
			public function handle( Inbound_Event $event ): string {
				return (string) call_user_func( $this->work, $event );
			}
		};
	}

	/**
	 * Reads one column of the event rows.
	 *
	 * @param string $column Column.
	 * @return array<int,mixed>
	 */
	private function column( string $column ): array {
		return array_column( $this->db->rows( 'SELECT ' . $column . ' FROM ' . Sqlite_Tables::table( 'events' ) . ' ORDER BY event_id' ), $column );
	}
}
