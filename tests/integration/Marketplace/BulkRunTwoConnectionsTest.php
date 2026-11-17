<?php
/**
 * The bulk run's exclusion on a real MySQL: the named lock on two real connections, and the reconnect fence (#1795, E2).
 *
 * The unit suite scripts the lock (SQLite has no `GET_LOCK`); what only a server can show is here. Each «tick» below runs on a
 * `wpdb` of its own — its own connection, so its own lock and its own `CONNECTION_ID()` — and a third connection kills a server
 * connection while the PHP that owned it carries on, which is exactly what `wpdb`'s error-2006 reconnect does to a tick.
 *
 * The tables are created and dropped through these connections, outside the WordPress test case's transaction: a lock and a row
 * written by another connection are invisible to a transaction that has not committed.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lock;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Worker;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Tests\Integration\TestCase;

class BulkRunTwoConnectionsTest extends TestCase {
	private const ADAPTER = 'itestbulk';

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * Tick A's connection and tick B's, and the one that kills.
	 *
	 * @var \wpdb[]
	 */
	private $conn = [];

	/**
	 * @var int
	 */
	private $account_id = 0;

	/**
	 * Parts the scripted workers have been asked for, by who asked.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $cursors = [ 'a' => [], 'b' => [] ];

	protected function setUp(): void {
		$this->schema  = new Schema( self::ADAPTER );
		$this->cursors = [ 'a' => [], 'b' => [] ];

		parent::setUp();

		// The WordPress test case turns CREATE / DROP TABLE into temporary-table statements for every `wpdb`: the tables must be real.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		global $wpdb;

		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			$this->conn[ $name ] = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			// A fresh `wpdb` has an empty prefix; the tables are named with the test installation's own (`$wpdb->prefix`, as `table()` does).
			$this->conn[ $name ]->prefix = $wpdb->prefix;
		}

		$this->drop_tables();
		$charset = $this->conn['a']->get_charset_collate();

		foreach ( [ 'accounts', 'bulk_runs' ] as $table ) {
			$statement = $this->schema->statements( $wpdb->prefix, $charset )[ $this->table( $table ) ];
			$this->assertNotFalse( $this->conn['a']->query( $statement ), 'Created ' . $table );
		}

		$this->account_id = $this->accounts( 'a' )->save( new Account( 0, self::ADAPTER, 'Основной', true, [ 'cabinet' => 'c-1', 'token' => 't-1' ], null, [] ) );
	}

	protected function tearDown(): void {
		// PHPUnit keeps a finished test's object alive: close the connections, or a lock one of them still holds is held for the next
		// test (the account id is 1 again after the tables are recreated).
		foreach ( $this->conn as $connection ) {
			$connection->close();
		}

		$this->conn = [];

		as_unschedule_all_actions( $this->hook() );

		parent::tearDown();

		$this->drop_tables();
	}

	private function table( string $name ): string {
		global $wpdb;

		return $this->schema->table_name( $name, $wpdb->prefix );
	}

	private function drop_tables(): void {
		global $wpdb;

		foreach ( [ 'accounts', 'bulk_runs' ] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}

	private function hook(): string {
		return ( new Job_Names( 'itest-bulk' ) )->hook( Bulk_Run_Scheduler::JOB );
	}

	private function accounts( string $name ): Wpdb_Account_Store {
		return new Wpdb_Account_Store( $this->conn[ $name ], $this->table( 'accounts' ), new Secret_Cipher( 'integration-salt' ), self::ADAPTER, new Credential_Fields( 'cabinet', [ 'token' ] ) );
	}

	private function store( string $name ): Bulk_Run_Store {
		return new Bulk_Run_Store( $this->conn[ $name ], $this->table( 'bulk_runs' ) );
	}

	private function lock( string $name ): Bulk_Run_Lock {
		return new Bulk_Run_Lock( $this->conn[ $name ], $this->table( 'bulk_runs' ) );
	}

	/**
	 * A tick on one connection.
	 *
	 * @param string          $name   `a` or `b`.
	 * @param Bulk_Run_Worker $worker The work.
	 * @param float           $step   Seconds the clock moves per reading: past the budget, a tick works one part.
	 * @return Bulk_Run_Runner
	 */
	private function runner( string $name, Bulk_Run_Worker $worker, float $step = 0.0 ): Bulk_Run_Runner {
		$seconds = 0.0;

		return new Bulk_Run_Runner(
			$this->store( $name ),
			$this->lock( $name ),
			new Bulk_Run_Scheduler( new Job_Names( 'itest-bulk' ) ),
			$this->accounts( $name ),
			self::ADAPTER,
			$worker,
			null,
			static function () use ( &$seconds, $step ): float {
				$seconds += $step;

				return $seconds;
			}
		);
	}

	/**
	 * A worker that answers from a list and runs a callback inside each part.
	 *
	 * @param string        $who    `a` or `b`: whose cursors to record.
	 * @param callable|null $during `function ( int $call, callable $owns ): void`, inside the part.
	 * @param bool[]        $done   Whether each part finishes the run; a part with no entry does not.
	 * @return Bulk_Run_Worker
	 */
	private function worker( string $who, ?callable $during = null, array $done = [] ): Bulk_Run_Worker {
		$test = $this;

		return new class( $test, $who, $during, $done ) implements Bulk_Run_Worker {
			private $test;
			private $who;
			private $during;
			private $done;
			private $calls = 0;

			public function __construct( $test, string $who, ?callable $during, array $done ) {
				$this->test   = $test;
				$this->who    = $who;
				$this->during = $during;
				$this->done   = $done;
			}

			public function refusal( Account $account, Bulk_Run $run ): ?string {
				return null;
			}

			public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
				++$this->calls;
				$this->test->record( $this->who, $cursor );

				if ( null !== $this->during ) {
					call_user_func( $this->during, $this->calls, $owns );
				}

				return [
					'cursor'   => [ 'parent' => $this->calls * 10, 'child' => 0 ],
					'finished' => $this->done[ $this->calls - 1 ] ?? false,
					'counts'   => [ 'products_done' => 1, 'offers_accepted' => 2, 'offers_skipped' => 0, 'offers_failed' => 0 ],
					'groups'   => [],
					'busy'     => 0,
				];
			}
		};
	}

	/**
	 * @param string              $who    Who asked.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return void
	 */
	public function record( string $who, array $cursor ): void {
		$this->cursors[ $who ][] = $cursor;
	}

	private function start(): string {
		$run_id = $this->runner( 'a', $this->worker( 'a' ) )->start( $this->account_id, 'sync_stock_on', [], [ 'search' => '', 'filters' => [] ], 1, 100 );
		$this->assertNotNull( $run_id );

		return (string) $run_id;
	}

	private function current(): Bulk_Run {
		$run = $this->store( 'c' )->find( $this->account_id );
		$this->assertNotNull( $run );

		return $run;
	}

	public function test_a_tick_that_starts_after_another_took_the_lock_exits_without_reading_the_row_or_writing_a_product(): void {
		$run_id = $this->start();

		$this->assertTrue( $this->lock( 'a' )->acquire( $this->account_id ) );
		$this->assertFalse( $this->lock( 'b' )->acquire( $this->account_id ), 'MySQL grants a name to one connection.' );

		$this->runner( 'b', $this->worker( 'b' ) )->tick( $this->account_id, $run_id );

		$this->assertSame( [], $this->cursors['b'], 'No part was planned.' );
		$this->assertTrue( $this->current()->is_running() );
		$this->assertNull( $this->current()->cursor()['stopped'] ?? null );

		$this->lock( 'a' )->release( $this->account_id );
		$this->assertTrue( $this->lock( 'b' )->acquire( $this->account_id ), 'Once A released, the lock is free.' );
		$this->lock( 'b' )->release( $this->account_id );
	}

	public function test_the_lock_of_one_account_does_not_hold_another_account(): void {
		$this->assertTrue( $this->lock( 'a' )->acquire( 1 ) );
		$this->assertTrue( $this->lock( 'b' )->acquire( 2 ) );
		$this->assertFalse( $this->lock( 'b' )->acquire( 1 ) );
	}

	public function test_a_tick_whose_connection_closes_frees_the_lock_and_the_next_tick_continues_from_the_last_saved_cursor(): void {
		$run_id = $this->start();

		// Tick A takes the lock, writes its token, saves the first part, and its process dies: the connection closes.
		$this->assertTrue( $this->lock( 'a' )->acquire( $this->account_id ) );
		$this->assertTrue( $this->store( 'a' )->take_tick( $this->account_id, $run_id, 'tick-of-a' ) );
		$this->assertTrue( $this->store( 'a' )->save( $this->account_id, $run_id, 'tick-of-a', [ 'parent' => 40, 'child' => 7 ], [ 'products_done' => 4 ], [] ) );
		$this->assertFalse( $this->lock( 'b' )->acquire( $this->account_id ), 'Held while A lives.' );
		$this->conn['a']->close();

		// `close()` returns when the client has hung up; the server frees the lock a moment later, and `GET_LOCK( name, 0 )` does not wait.
		$this->assertTrue( $this->wait_until_free( 'b' ), 'MySQL freed A\'s lock when it saw the connection close.' );

		$this->runner( 'b', $this->worker( 'b', null, [ true ] ) )->tick( $this->account_id, $run_id );

		$this->assertSame( [ [ 'parent' => 40, 'child' => 7 ] ], $this->cursors['b'], 'B goes on from what A saved, not from the start.' );
		$this->assertSame( Bulk_Run::DONE, $this->current()->state() );
		$this->assertSame( 5, $this->current()->counts()['products_done'], 'A\'s four and B\'s one.' );
	}

	public function test_stop_while_a_tick_holds_the_lock_sets_the_flag_and_the_tick_finishes_cancelled_after_its_part(): void {
		$run_id = $this->start();
		$runner = $this->runner( 'b', $this->worker( 'b' ) );
		$answer = null;

		$worker = $this->worker(
			'a',
			function () use ( $runner, $run_id, &$answer ): void {
				// The merchant presses «Остановить» on another request, another connection, while tick A works.
				$answer = $runner->stop( $this->account_id, $run_id );
			}
		);

		$this->runner( 'a', $worker )->tick( $this->account_id, $run_id );

		$this->assertSame( Bulk_Run_Runner::STOP_REQUESTED, $answer );
		$this->assertSame( Bulk_Run::CANCELLED, $this->current()->state() );
		$this->assertSame( 1, $this->current()->counts()['products_done'], 'The part that was running was saved.' );
	}

	public function test_stop_with_the_lock_free_finishes_the_run_cancelled_at_once(): void {
		$run_id = $this->start();

		$this->assertSame( Bulk_Run_Runner::STOP_CANCELLED, $this->runner( 'b', $this->worker( 'b' ) )->stop( $this->account_id, $run_id ) );
		$this->assertSame( Bulk_Run::CANCELLED, $this->current()->state() );
		$this->assertTrue( $this->lock( 'a' )->acquire( $this->account_id ), 'The stop released the lock.' );
	}

	/**
	 * The mandatory reconnect fence (r3 critic). While tick A's PHP carries on, its server connection is killed from a third
	 * connection; `wpdb` reconnects on A's next query, and that query runs on a new connection that holds no lock. Tick B takes the
	 * lock and writes its token and its progress.
	 *
	 * @param bool $writes_a_product Whether A reaches a product write before its save (and so meets the ownership check) or goes
	 *                               straight to its save (and so meets the token fence).
	 * @return void
	 */
	private function reconnect_fence( bool $writes_a_product ): void {
		$run_id      = $this->start();
		$a_part      = 0;
		$a_asked_for = null;

		$worker_a = $this->worker(
			'a',
			function ( int $call, callable $owns ) use ( $run_id, $writes_a_product, &$a_part, &$a_asked_for ): void {
				$a_part = $call;

				// Kill A's server connection from C while A's PHP continues.
				$id = (int) $this->conn['a']->get_var( 'SELECT CONNECTION_ID()' );
				$this->assertGreaterThan( 0, $id );
				$this->conn['c']->query( 'KILL ' . $id );
				$this->assertTrue( $this->wait_until_free( 'b' ), 'MySQL freed A\'s lock when it dropped the connection.' );

				// Tick B takes the lock, writes its token, saves its progress, and is still working.
				$this->assertTrue( $this->lock( 'b' )->acquire( $this->account_id ) );
				$this->assertTrue( $this->store( 'b' )->take_tick( $this->account_id, $run_id, 'tick-of-b' ) );
				$this->assertTrue( $this->store( 'b' )->save( $this->account_id, $run_id, 'tick-of-b', [ 'parent' => 77, 'child' => 0 ], [ 'products_done' => 9, 'offers_accepted' => 18 ], [] ) );

				if ( $writes_a_product ) {
					// A's next product write asks the ownership check first: wpdb reconnects on this very query, so the answer comes
					// from a new connection that holds nothing.
					$a_asked_for = $owns();
					$this->assertFalse( $a_asked_for, 'The product write is refused.' );
					throw new Bulk_Run_Lost();
				}
			}
		);

		$this->runner( 'a', $worker_a )->tick( $this->account_id, $run_id );

		$run = $this->current();
		$this->assertTrue( $run->is_running(), 'A finished nothing.' );
		$this->assertSame( [ 'parent' => 77, 'child' => 0 ], $run->cursor(), 'What B saved is not overwritten.' );
		$this->assertSame( 9, $run->counts()['products_done'] );
		$this->assertSame( 18, $run->counts()['offers_accepted'] );
		$this->assertSame( 1, $a_part, 'A worked one part and no more.' );
		$this->assertCount( 1, as_get_scheduled_actions( [ 'hook' => $this->hook(), 'status' => \ActionScheduler_Store::STATUS_PENDING, 'args' => [ $this->account_id, $run_id ] ], 'ids' ), 'A queued no tick of its own: only the start\'s first tick waits.' );
		$this->assertFalse( $this->lock( 'c' )->acquire( $this->account_id ), 'B still holds the lock; A\'s release on its new connection released nothing of B\'s.' );
	}

	public function test_the_reconnect_fence_refuses_the_next_product_write_of_a_tick_that_lost_its_connection(): void {
		$this->reconnect_fence( true );
	}

	public function test_the_reconnect_fence_makes_the_next_save_of_a_tick_that_lost_its_connection_change_no_row_and_queue_no_tick(): void {
		$this->reconnect_fence( false );
	}

	/**
	 * `KILL` and a client-side close are both asynchronous on the server: the lock is gone once the server finished tearing the
	 * connection down. Polls up to five seconds.
	 *
	 * @param string $name Connection that asks.
	 * @return bool
	 */
	private function wait_until_free( string $name ): bool {
		for ( $i = 0; $i < 50; $i++ ) {
			if ( $this->lock( $name )->acquire( $this->account_id ) ) {
				$this->lock( $name )->release( $this->account_id );

				return true;
			}

			usleep( 100000 );
		}

		return false;
	}
}
