<?php
/**
 * Label tasks and label documents on SQLite.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * The two tables a label touches: `tasks` (rows of `kind = label`) and `documents` (the body, a blob).
 */
final class WpdbLabelStoreTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Store.
	 *
	 * @var Wpdb_Label_Store
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var int
	 */
	private $now = self::NOW;

	/**
	 * Boots the stubs and the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->now = self::NOW;
		$this->db  = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->store = new Wpdb_Label_Store(
			$this->db,
			Sqlite_Tables::table( 'tasks' ),
			Sqlite_Tables::table( 'documents' ),
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A label task is stored `submitted` with the keys it was made for, and read back.
	 *
	 * @return void
	 */
	public function test_a_task_is_stored_and_read_back(): void {
		$id = $this->store->add_task( 7, 'remote-1', [ 'A-1', 'A-2' ] );

		$task = $this->store->task( $id );

		$this->assertSame( 7, $task['account_id'] );
		$this->assertSame( Async_Task::KIND_LABEL, $task['kind'] );
		$this->assertSame( 'remote-1', $task['remote_task_id'] );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $task['status'] );
		$this->assertSame( self::NOW, $task['submitted_at'] );
		$this->assertSame( [ 'A-1', 'A-2' ], $task['result'][ Wpdb_Label_Store::RESULT_EXTERNAL_KEYS ] );
		$this->assertNull( $this->store->task( $id + 1 ) );
	}

	/**
	 * A task of another kind is not a label task.
	 *
	 * @return void
	 */
	public function test_another_kind_of_task_is_not_read_as_a_label(): void {
		$this->db->insert(
			Sqlite_Tables::table( 'tasks' ),
			[
				'account_id'   => 7,
				'kind'         => Async_Task::KIND_IMPORT,
				'submitted_at' => '2026-11-08 12:00:00',
			]
		);

		$this->assertNull( $this->store->task( (int) $this->db->insert_id ) );
	}

	/**
	 * A failed read is an exception, not an absent task.
	 *
	 * @return void
	 */
	public function test_a_failed_read_is_not_taken_for_an_absent_task(): void {
		$this->db->fail_reads = true;

		$this->expectException( Persistence_Exception::class );

		$this->store->task( 1 );
	}

	/**
	 * A poll is counted while the task is open and moves it to `polling`; a finished task is not touched.
	 *
	 * @return void
	 */
	public function test_polls_are_counted_only_while_the_task_is_open(): void {
		$id = $this->store->add_task( 7, 'remote-1', [ 'A-1' ] );

		$this->store->touch_polled( $id );
		$this->store->touch_polled( $id );
		$this->assertSame( 2, $this->store->task( $id )['poll_count'] );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->store->task( $id )['status'] );

		$this->store->finish_task( $id, Async_Task::STATUS_FAILED, [ 'message' => 'x' ] );
		$this->store->touch_polled( $id );
		$this->assertSame( 2, $this->store->task( $id )['poll_count'] );
	}

	/**
	 * Finishing is fenced by the task's status: the loser of two runners changes nothing.
	 *
	 * @return void
	 */
	public function test_a_finished_task_is_left_as_it_is(): void {
		$id = $this->store->add_task( 7, 'remote-1', [ 'A-1' ] );

		$this->assertTrue( $this->store->finish_task( $id, Async_Task::STATUS_DONE, [ Wpdb_Label_Store::RESULT_MESSAGE => 'first' ] ) );
		$this->assertFalse( $this->store->finish_task( $id, Async_Task::STATUS_FAILED, [ Wpdb_Label_Store::RESULT_MESSAGE => 'second' ] ) );

		$task = $this->store->task( $id );
		$this->assertSame( Async_Task::STATUS_DONE, $task['status'] );
		$this->assertSame( 'first', $task['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );
	}

	/**
	 * Publishing stores the body and ends the task together; the body is a blob in the database.
	 *
	 * @return void
	 */
	public function test_publishing_stores_the_body_and_ends_the_task(): void {
		$id = $this->store->add_task( 7, 'remote-1', [ 'A-1' ] );

		$document_id = $this->store->publish_document( $id, 7, [ 'A-1' ], 'application/pdf', "%PDF-1.4\x00\xff", [ Wpdb_Label_Store::RESULT_ANSWER => [ 'status' => 'ok' ] ] );

		$this->assertNotNull( $document_id );
		$this->assertSame( Async_Task::STATUS_DONE, $this->store->task( $id )['status'] );
		$this->assertSame( $document_id, $this->store->document_of_task( $id ) );

		$document = $this->store->document( $document_id );
		$this->assertSame( "%PDF-1.4\x00\xff", $document['body'] );
		$this->assertSame( 'application/pdf', $document['content_type'] );
		$this->assertSame( [ 'A-1' ], $document['external_keys'] );
		$this->assertSame( 7, $document['account_id'] );
		$this->assertNull( $this->store->document( $document_id + 1 ) );
	}

	/**
	 * A poll that lost the status fence (the task expired or finished meanwhile) leaves no document behind.
	 *
	 * @return void
	 */
	public function test_a_losing_publish_leaves_no_document(): void {
		$id = $this->store->add_task( 7, 'remote-1', [ 'A-1' ] );
		$this->store->finish_task( $id, Async_Task::STATUS_EXPIRED, [] );

		$this->assertNull( $this->store->publish_document( $id, 7, [ 'A-1' ], 'application/pdf', 'body', [] ) );
		$this->assertSame( [], $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'documents' ) ) );
		$this->assertNull( $this->store->document_of_task( $id ) );
	}

	/**
	 * A refused document write leaves the task open and nothing stored.
	 *
	 * @return void
	 */
	public function test_a_refused_document_write_leaves_the_task_open(): void {
		$id                        = $this->store->add_task( 7, 'remote-1', [ 'A-1' ] );
		$this->db->refuse_inserts = true;

		try {
			$this->store->publish_document( $id, 7, [ 'A-1' ], 'application/pdf', 'body', [] );
			$this->fail( 'The refused write must throw.' );
		} catch ( Persistence_Exception $exception ) {
			$this->assertStringContainsString( 'a label document', $exception->getMessage() );
		}

		$this->db->refuse_inserts = false;
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->store->task( $id )['status'] );
		$this->assertSame( [], $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'documents' ) ) );
	}

	/**
	 * Housekeeping expires a task that is still open after a day, and keeps it until it is a week old.
	 *
	 * @return void
	 */
	public function test_prune_expires_a_task_open_for_a_day(): void {
		$open       = $this->store->add_task( 7, 'open', [ 'A' ] );
		$this->now += 2 * DAY_IN_SECONDS;
		$recent     = $this->store->add_task( 7, 'recent', [ 'B' ] );

		$this->store->prune();

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->store->task( $open )['status'] );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->store->task( $recent )['status'], 'a task of today is still open' );
	}

	/**
	 * Housekeeping deletes documents and finished tasks older than a week, and the open task of that age with them; recent rows
	 * stay.
	 *
	 * @return void
	 */
	public function test_prune_deletes_week_old_rows(): void {
		$old_open   = $this->store->add_task( 7, 'old-open', [ 'A' ] );
		$old_done   = $this->store->add_task( 7, 'old-done', [ 'B' ] );
		$old_doc    = (int) $this->store->publish_document( $old_done, 7, [ 'B' ], 'application/pdf', 'old', [] );
		$this->now += 8 * DAY_IN_SECONDS;
		$fresh      = $this->store->add_task( 7, 'fresh', [ 'C' ] );
		$fresh_doc  = (int) $this->store->publish_document( $fresh, 7, [ 'C' ], 'application/pdf', 'fresh', [] );

		$this->assertSame( 1, $this->store->prune(), 'one document deleted' );

		$this->assertNull( $this->store->document( $old_doc ) );
		$this->assertNotNull( $this->store->document( $fresh_doc ) );
		$this->assertNull( $this->store->task( $old_done ), 'a finished task of last week is deleted' );
		$this->assertNull( $this->store->task( $old_open ), 'an open task of last week was expired, then deleted' );
		$this->assertNotNull( $this->store->task( $fresh ) );
	}
}
