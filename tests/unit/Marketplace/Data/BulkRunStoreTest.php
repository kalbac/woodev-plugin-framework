<?php
/**
 * The bulk run row: one per account, started conditionally, saved only by the tick that owns it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the store's real SQL on SQLite over the schema's own columns.
 *
 * @covers \Woodev\Framework\Marketplace\Data\Bulk_Run_Store
 * @covers \Woodev\Framework\Marketplace\Data\Bulk_Run
 */
final class BulkRunStoreTest extends TestCase {
	private const ACCOUNT = 7;
	private const NOW     = 1794139200;

	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * @var Bulk_Run_Store
	 */
	private $store;

	/**
	 * @var int
	 */
	private $now = self::NOW;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->now   = self::NOW;
		$this->db    = new Sqlite_Wpdb();
		$this->store = new Bulk_Run_Store(
			$this->db,
			Sqlite_Tables::table( 'bulk_runs' ),
			function (): int {
				return $this->now;
			}
		);
		Sqlite_Tables::install( $this->db );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function start( string $run_id = 'r1', int $account = self::ACCOUNT ): bool {
		return $this->store->start( $account, $run_id, 'sync_stock_on', [ 'warehouse' => 5 ], [ 'search' => '', 'filters' => [ 'state' => 'unlinked' ] ], 3, 12345 );
	}

	private function current(): Bulk_Run {
		$run = $this->store->find( self::ACCOUNT );
		$this->assertNotNull( $run );

		return $run;
	}

	public function test_a_start_stores_what_the_run_needs_and_no_list_of_ids(): void {
		$this->assertTrue( $this->start() );

		$run = $this->current();

		$this->assertTrue( $run->is_running() );
		$this->assertSame( 'r1', $run->run_id() );
		$this->assertSame( 'sync_stock_on', $run->action() );
		$this->assertSame( [ 'warehouse' => 5 ], $run->payload() );
		$this->assertSame( [ 'search' => '', 'filters' => [ 'state' => 'unlinked' ] ], $run->filter() );
		$this->assertSame( 3, $run->user_id() );
		$this->assertSame( [], $run->cursor() );
		$this->assertSame( 12345, $run->counts()['products_total'] );
		$this->assertSame( 0, $run->counts()['offers_accepted'] );
		$this->assertFalse( $run->cancel_requested() );
		$this->assertSame( self::NOW, $run->started_at() );
		$this->assertSame( 0, $run->finished_at() );
	}

	public function test_a_start_while_a_run_is_going_is_refused_and_changes_nothing(): void {
		$this->start( 'r1' );

		$this->assertFalse( $this->start( 'r2' ) );
		$this->assertSame( 'r1', $this->current()->run_id() );
	}

	public function test_a_start_right_after_a_finished_run_succeeds_and_replaces_its_result(): void {
		$this->start( 'r1' );
		$this->store->take_tick( self::ACCOUNT, 'r1', 'tick-a' );
		$this->assertTrue( $this->store->finish( self::ACCOUNT, 'r1', 'tick-a', Bulk_Run::DONE, [ 'parent' => 9, 'child' => 0 ], [ 'offers_accepted' => 40 ], [] ) );

		$this->assertTrue( $this->start( 'r2' ) );

		$run = $this->current();
		$this->assertSame( 'r2', $run->run_id() );
		$this->assertTrue( $run->is_running() );
		$this->assertSame( 0, $run->counts()['offers_accepted'], 'The new run starts from zero.' );
		$this->assertSame( [], $run->cursor() );
		$this->assertSame( 0, $run->finished_at() );
	}

	public function test_an_account_with_no_run_has_none_and_runs_of_two_accounts_are_independent(): void {
		$this->assertNull( $this->store->find( self::ACCOUNT ) );

		$this->assertTrue( $this->start( 'r1', self::ACCOUNT ) );
		$this->assertTrue( $this->start( 'r2', 8 ) );
		$this->assertSame( [ self::ACCOUNT, 8 ], $this->store->running_accounts() );
	}

	public function test_a_save_is_fenced_by_the_token_of_the_tick_that_owns_the_run(): void {
		$this->start();
		$this->store->take_tick( self::ACCOUNT, 'r1', 'tick-a' );

		$this->assertTrue( $this->store->save( self::ACCOUNT, 'r1', 'tick-a', [ 'parent' => 4, 'child' => 2 ], [ 'products_done' => 3 ], [] ) );
		$this->assertSame( [ 'parent' => 4, 'child' => 2 ], $this->current()->cursor() );

		// Tick B takes the lock after A lost its connection and writes its own token.
		$this->assertTrue( $this->store->take_tick( self::ACCOUNT, 'r1', 'tick-b' ) );
		$this->assertTrue( $this->store->save( self::ACCOUNT, 'r1', 'tick-b', [ 'parent' => 8, 'child' => 0 ], [ 'products_done' => 6 ], [] ) );

		$this->assertFalse( $this->store->save( self::ACCOUNT, 'r1', 'tick-a', [ 'parent' => 1, 'child' => 0 ], [ 'products_done' => 1 ], [] ), 'A tick that lost the run saves nothing.' );
		$this->assertFalse( $this->store->finish( self::ACCOUNT, 'r1', 'tick-a', Bulk_Run::DONE, [], [], [] ), 'And does not finish it.' );
		$this->assertSame( [ 'parent' => 8, 'child' => 0 ], $this->current()->cursor(), 'What B saved is not overwritten.' );
		$this->assertSame( 6, $this->current()->counts()['products_done'] );
		$this->assertTrue( $this->current()->is_running() );
	}

	public function test_a_tick_of_another_run_cannot_take_the_token(): void {
		$this->start( 'r1' );

		$this->assertFalse( $this->store->take_tick( self::ACCOUNT, 'old-run', 'tick-a' ) );
		$this->assertFalse( $this->store->save( self::ACCOUNT, 'old-run', 'tick-a', [], [], [] ) );
	}

	public function test_cancel_is_written_only_by_the_stop_and_a_tick_save_does_not_erase_it(): void {
		$this->start();
		$this->store->take_tick( self::ACCOUNT, 'r1', 'tick-a' );

		$this->assertTrue( $this->store->request_cancel( self::ACCOUNT, 'r1' ) );
		$this->assertTrue( $this->store->save( self::ACCOUNT, 'r1', 'tick-a', [ 'parent' => 4, 'child' => 0 ], [ 'products_done' => 3 ], [] ), 'The tick saves its part while the stop arrives.' );

		$this->assertTrue( $this->store->is_cancel_requested( self::ACCOUNT, 'r1' ), 'The save did not touch the cancel flag.' );
		$this->assertTrue( $this->current()->cancel_requested() );
	}

	public function test_a_stop_for_a_run_that_is_not_the_running_one_changes_nothing(): void {
		$this->start( 'r1' );
		$this->store->take_tick( self::ACCOUNT, 'r1', 'tick-a' );
		$this->store->finish( self::ACCOUNT, 'r1', 'tick-a', Bulk_Run::DONE, [], [], [] );
		$this->start( 'r2' );

		$this->assertFalse( $this->store->request_cancel( self::ACCOUNT, 'r1' ), 'A stop confirmed for the finished R1 does not reach R2.' );
		$this->assertFalse( $this->store->end_under_lock( self::ACCOUNT, 'r1', Bulk_Run::CANCELLED ) );
		$this->assertTrue( $this->current()->is_running() );
		$this->assertFalse( $this->current()->cancel_requested() );
	}

	public function test_a_run_ended_under_the_lock_records_the_state_and_the_time(): void {
		$this->start();
		$this->now = self::NOW + 90;

		$this->assertTrue( $this->store->end_under_lock( self::ACCOUNT, 'r1', Bulk_Run::CANCELLED ) );

		$run = $this->current();
		$this->assertSame( Bulk_Run::CANCELLED, $run->state() );
		$this->assertSame( self::NOW + 90, $run->finished_at() );
		$this->assertFalse( $this->store->end_under_lock( self::ACCOUNT, 'r1', Bulk_Run::STOPPED ), 'A terminal state is final.' );
	}

	public function test_the_report_survives_a_round_trip_with_cyrillic_kept_as_is(): void {
		$this->start();
		$this->store->take_tick( self::ACCOUNT, 'r1', 'tick-a' );
		$group = [
			'kind'      => 'skipped',
			'reason'    => 'не отправлялся на маркетплейс',
			'count'     => 12,
			'fix_label' => '',
			'fix_url'   => '',
			'examples'  => [ 'Платье' ],
		];

		$this->store->save( self::ACCOUNT, 'r1', 'tick-a', [], [], [ $group ] );

		$this->assertSame( [ $group ], $this->current()->report() );
		$stored = $this->db->get_var( 'SELECT report FROM ' . Sqlite_Tables::table( 'bulk_runs' ) );
		$this->assertStringContainsString( 'не отправлялся', (string) $stored, 'The column stays small: no \\u escapes.' );
	}

	public function test_the_account_deletion_removes_the_row_in_batches(): void {
		$this->start( 'r1', self::ACCOUNT );
		$this->start( 'r2', 8 );

		$this->assertSame( 1, $this->store->delete_account( self::ACCOUNT, 100 ) );
		$this->assertSame( 0, $this->store->delete_account( self::ACCOUNT, 100 ) );
		$this->assertNull( $this->store->find( self::ACCOUNT ) );
		$this->assertNotNull( $this->store->find( 8 ), 'Another account keeps its run.' );
	}
}
