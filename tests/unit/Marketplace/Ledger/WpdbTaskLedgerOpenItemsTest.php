<?php
/**
 * The ledger read behind the activity projection: open task items for a set of links.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Ledger;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * `open_items()` answers, for the rows one screen shows, which links still wait for a task: an item whose own result is pending in a
 * task that is submitted or polling. It runs the ledger's real SQL on SQLite, once for the whole set, and never leaks another
 * account's tasks.
 */
final class WpdbTaskLedgerOpenItemsTest extends TestCase {
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
	 * @var Wpdb_Task_Ledger
	 */
	private $ledger;

	/**
	 * Opens a database with the task tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->ledger = new Wpdb_Task_Ledger(
			$this->db,
			Sqlite_Schema::TASKS,
			Sqlite_Schema::TASK_ITEMS,
			static function (): int {
				return self::NOW;
			}
		);
	}

	/**
	 * Opens a task of an account with the given links.
	 *
	 * @param int               $account_id Account id.
	 * @param array<int,string> $items      Link id => article.
	 * @param string            $kind       Task kind.
	 * @return int Task id.
	 */
	private function open_task( int $account_id, array $items, string $kind = Async_Task::KIND_IMPORT ): int {
		return $this->ledger->open( new Async_Task( $account_id, $kind, null, Async_Task::STATUS_SUBMITTED, count( $items ), new \DateTimeImmutable( '@' . self::NOW ) ), $items );
	}

	/**
	 * A pending item of a submitted task is open; the answer carries the task and the article.
	 *
	 * @return void
	 */
	public function test_a_pending_item_of_a_submitted_task_is_open(): void {
		$task = $this->open_task( 5, array( 11 => 'A-11' ) );

		$this->assertSame(
			array(
				11 => array(
					array(
						'task_id'  => $task,
						'kind'     => Async_Task::KIND_IMPORT,
						'status'   => Async_Task::STATUS_SUBMITTED,
						'offer_id' => 'A-11',
					),
				),
			),
			$this->ledger->open_items( 5, array( 11, 12 ) )
		);
	}

	/**
	 * A polling task still owes its pending items; a finished task, or an item that already has its result, owes nothing.
	 *
	 * @return void
	 */
	public function test_only_unfinished_work_is_open(): void {
		$polling = $this->open_task( 5, array( 21 => 'A-21', 22 => 'A-22' ) );
		$this->ledger->record_poll( $polling, Async_Task::STATUS_POLLING );
		$this->ledger->settle_item( $polling, 22, Task_Ledger::ITEM_IMPORTED );

		$done = $this->open_task( 5, array( 23 => 'A-23' ) );
		$this->ledger->record_poll( $done, Async_Task::STATUS_DONE );

		$open = $this->ledger->open_items( 5, array( 21, 22, 23 ) );

		$this->assertSame( array( 21 ), array_keys( $open ) );
		$this->assertSame( Async_Task::STATUS_POLLING, $open[21][0]['status'] );
	}

	/**
	 * Another account's task for the same link id is never returned: an account scopes the read.
	 *
	 * @return void
	 */
	public function test_another_accounts_task_is_not_returned(): void {
		$this->open_task( 6, array( 31 => 'B-31' ) );

		$this->assertSame( array(), $this->ledger->open_items( 5, array( 31 ) ) );
		$this->assertArrayHasKey( 31, $this->ledger->open_items( 6, array( 31 ) ) );
	}

	/**
	 * A link in two tasks lists both, oldest task first; several links come back from one read.
	 *
	 * @return void
	 */
	public function test_a_link_in_two_tasks_lists_both_oldest_first(): void {
		$first  = $this->open_task( 5, array( 41 => 'A-41', 42 => 'A-42' ) );
		$second = $this->open_task( 5, array( 41 => 'A-41' ), Async_Task::KIND_IMPORT );

		$open = $this->ledger->open_items( 5, array( 41, 42 ) );

		$this->assertSame( array( $first, $second ), array_column( $open[41], 'task_id' ) );
		$this->assertSame( array( $first ), array_column( $open[42], 'task_id' ) );
	}

	/**
	 * No ids, or only ids that are not links, ask the database nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_set_reads_nothing(): void {
		$this->open_task( 5, array( 51 => 'A-51' ) );
		$before = count( $this->db->statements );

		$this->assertSame( array(), $this->ledger->open_items( 5, array() ) );
		$this->assertSame( array(), $this->ledger->open_items( 5, array( 0, -0 ) ) );
		$this->assertSame( $before, count( $this->db->statements ), 'an empty set sends no statement' );
	}

	/**
	 * The read changes nothing: the same call twice gives the same answer and the tables are untouched.
	 *
	 * @return void
	 */
	public function test_the_read_changes_nothing(): void {
		$task = $this->open_task( 5, array( 61 => 'A-61' ) );
		$one  = $this->ledger->open_items( 5, array( 61 ) );
		$two  = $this->ledger->open_items( 5, array( 61, 61 ) );

		$this->assertSame( $one, $two );
		$this->assertSame( 'pending', $this->ledger->items( $task )[61]['status'] );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->ledger->get( $task )->status() );
	}
}
