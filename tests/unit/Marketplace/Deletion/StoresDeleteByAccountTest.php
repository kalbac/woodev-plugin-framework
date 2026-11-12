<?php
/**
 * Every store deletes one account's rows and no other account's.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Deletion\Pinned_Offer_Eraser;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Governor\Wpdb_Throttle_Store;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs each store's real SQL on SQLite over the schema's own columns. Two accounts have rows in every table; deleting the first
 * leaves the second untouched, a batch limit is obeyed, and the child tables (link ids, task items) go with their parents.
 */
final class StoresDeleteByAccountTest extends TestCase {
	private const GONE = 7;
	private const KEPT = 8;
	private const NOW  = '2026-11-12 10:00:00';

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Opens the database and gives both accounts rows in every table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->db->define( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT)' );
		$this->db->postmeta = 'wp_postmeta';

		foreach ( [ self::GONE, self::KEPT ] as $account ) {
			$this->seed( $account );
		}
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
	 * Links go with their remote ids; the other account keeps both.
	 *
	 * @return void
	 */
	public function test_links_are_deleted_with_their_remote_ids(): void {
		$store = new Wpdb_Link_Store( $this->db, Sqlite_Tables::table( 'links' ), Sqlite_Tables::table( 'link_ids' ) );

		$this->assertSame( 3, $store->count_for_account( self::GONE ) );
		$this->drain( [ $store, 'delete_account' ], self::GONE );

		$this->assertSame( 0, $store->count_for_account( self::GONE ) );
		$this->assertSame( 0, $this->rows_of( 'link_ids', self::GONE ) );
		$this->assertSame( 3, $store->count_for_account( self::KEPT ) );
		$this->assertSame( 3, $this->rows_of( 'link_ids', self::KEPT ) );
	}

	/**
	 * A batch deletes no more than its limit.
	 *
	 * @return void
	 */
	public function test_a_batch_obeys_its_limit(): void {
		$store = new Wpdb_Link_Store( $this->db, Sqlite_Tables::table( 'links' ), Sqlite_Tables::table( 'link_ids' ) );

		$this->assertSame( 2, $store->delete_account( self::GONE, 2 ), 'two of the three remote ids' );
		$this->assertSame( 3, $store->count_for_account( self::GONE ), 'no link yet: the ids go first' );
		$this->assertSame( 1, $store->delete_account( self::GONE, 2 ) );
		$this->assertSame( 2, $store->delete_account( self::GONE, 2 ), 'then the links' );
		$this->assertSame( 1, $store->count_for_account( self::GONE ) );
	}

	/**
	 * Tasks go with their items; the count is the tasks.
	 *
	 * @return void
	 */
	public function test_tasks_are_deleted_with_their_items(): void {
		$store = new Wpdb_Task_Ledger( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'task_items' ) );

		$this->assertSame( 2, $store->count_for_account( self::GONE ) );
		$this->drain( [ $store, 'delete_account' ], self::GONE );

		$this->assertSame( 0, $store->count_for_account( self::GONE ) );
		$this->assertSame( 2, $store->count_for_account( self::KEPT ) );
		$this->assertSame( 2, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'task_items' ) ), 'the other account keeps its items, this one has none' );
	}

	/**
	 * Postings, documents, and the tables that have only an account column.
	 *
	 * @return void
	 */
	public function test_each_other_store_deletes_only_its_account(): void {
		$registry  = new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) );
		$documents = new Wpdb_Label_Store( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'documents' ) );

		$this->assertSame( 2, $registry->count_for_account( self::GONE ) );
		$this->assertSame( 1, $documents->count_for_account( self::GONE ) );

		$stores = [
			'postings'   => $registry,
			'documents'  => $documents,
			'sync_state' => new Sync_State_Store( $this->db, Sqlite_Tables::table( 'sync_state' ) ),
			'throttle'   => new Wpdb_Throttle_Store( $this->db, Sqlite_Tables::table( 'throttle' ) ),
			'api_errors' => new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ) ),
			'events'     => new Wpdb_Event_Ledger( $this->db, Sqlite_Tables::table( 'events' ) ),
		];

		foreach ( $stores as $table => $store ) {
			$this->assertGreaterThan( 0, $this->drain( [ $store, 'delete_account' ], self::GONE ), $table );
			$this->assertSame( 0, $this->rows_of( $table, self::GONE ), $table . ': the account is gone' );
			$this->assertSame( 1, min( 1, $this->rows_of( $table, self::KEPT ) ), $table . ': the other account keeps its rows' );
		}
	}

	/**
	 * The cached rows of an account end with its id; the shared tree and another account's rows stay.
	 *
	 * @return void
	 */
	public function test_cached_rows_of_the_account_are_deleted_and_the_shared_ones_stay(): void {
		$cache = new Wpdb_Catalog_Cache( $this->db, Sqlite_Tables::table( 'catalog_cache' ) );

		$this->assertSame( 2, $cache->delete_account( self::GONE ) );

		$keys = $this->db->get_col( 'SELECT cache_key FROM ' . Sqlite_Tables::table( 'catalog_cache' ) . ' ORDER BY cache_key' );
		$this->assertSame( [ 'fetched:warehouses:8', 'tree', 'warehouses:78', 'warehouses:8' ], $keys, 'account 78 is not account 8 nor 7' );
	}

	/**
	 * Only the account's pinned articles go, whatever product holds them.
	 *
	 * @return void
	 */
	public function test_pinned_articles_of_the_account_are_deleted(): void {
		$eraser = new Pinned_Offer_Eraser( $this->db, 'fake' );

		$this->assertSame( 1, $eraser->erase( self::GONE, 5 ) );
		$this->assertSame( 0, $eraser->erase( self::GONE, 5 ) );
		$this->assertSame( 2, (int) $this->db->get_var( 'SELECT COUNT(*) FROM wp_postmeta' ), 'the other account and an unrelated key stay' );
	}

	/**
	 * A statement the database refuses is an error, not «nothing left».
	 *
	 * @return void
	 */
	public function test_a_refused_delete_throws(): void {
		$store = new Sync_State_Store( $this->db, 'wp_no_such_table' );

		$this->expectException( \RuntimeException::class );
		$store->delete_account( self::GONE, 10 );
	}

	/**
	 * Calls a store's batch delete until it removes nothing.
	 *
	 * @param callable $delete     `function ( int $account_id, int $limit ): int`.
	 * @param int      $account_id Account id.
	 * @return int Rows deleted in all.
	 */
	private function drain( callable $delete, int $account_id ): int {
		$total = 0;

		do {
			$removed = (int) call_user_func( $delete, $account_id, 2 );
			$total  += $removed;
		} while ( $removed > 0 );

		return $total;
	}

	/**
	 * Counts an account's rows in a table by name.
	 *
	 * @param string $table      Table name without prefix.
	 * @param int    $account_id Account id.
	 * @return int
	 */
	private function rows_of( string $table, int $account_id ): int {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( $table ) . ' WHERE account_id = ' . $account_id );
	}

	/**
	 * Gives an account rows in every table.
	 *
	 * @param int $account Account id.
	 * @return void
	 */
	private function seed( int $account ): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'links' ), [ 'account_id' => $account, 'wc_product_id' => $account * 100 + $i, 'offer_id' => "A{$account}-{$i}" ] );
			$link_id = (int) $this->db->insert_id;
			$this->db->insert( Sqlite_Tables::table( 'link_ids' ), [ 'link_id' => $link_id, 'account_id' => $account, 'id_name' => 'product_id', 'id_value' => (string) $link_id ] );
			$this->db->insert( Sqlite_Tables::table( 'sync_state' ), [ 'account_id' => $account, 'link_id' => $link_id, 'kind' => 'stock' ] );
		}

		for ( $i = 1; $i <= 2; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'tasks' ), [ 'account_id' => $account, 'kind' => 'import', 'submitted_at' => self::NOW ] );
			$task_id = (int) $this->db->insert_id;
			$this->db->insert( Sqlite_Tables::table( 'task_items' ), [ 'task_id' => $task_id, 'link_id' => $i ] );
			$this->db->insert( Sqlite_Tables::table( 'postings' ), [ 'account_id' => $account, 'external_key' => "P{$i}", 'first_seen_at' => self::NOW ] );
			$this->db->insert( Sqlite_Tables::table( 'throttle' ), [ 'account_id' => $account, 'bucket' => "b{$i}" ] );
			$this->db->insert( Sqlite_Tables::table( 'api_errors' ), [ 'account_id' => $account, 'occurred_at' => self::NOW ] );
			$this->db->insert( Sqlite_Tables::table( 'events' ), [ 'account_id' => $account, 'type' => 't', 'dedup_key' => "k{$i}", 'received_at' => self::NOW ] );
		}

		$this->db->insert( Sqlite_Tables::table( 'documents' ), [ 'account_id' => $account, 'created_at' => self::NOW ] );
		$this->db->insert( 'wp_postmeta', [ 'post_id' => 1, 'meta_key' => "_woodev_fake_offer_id_{$account}", 'meta_value' => 'X' ] );

		foreach ( [ "warehouses:{$account}", "fetched:warehouses:{$account}" ] as $key ) {
			$this->db->insert( Sqlite_Tables::table( 'catalog_cache' ), [ 'cache_key' => $key, 'fetched_at' => self::NOW ] );
		}

		if ( self::KEPT === $account ) {
			$this->db->insert( 'wp_postmeta', [ 'post_id' => 1, 'meta_key' => '_unrelated_7', 'meta_value' => 'X' ] );
			$this->db->insert( Sqlite_Tables::table( 'catalog_cache' ), [ 'cache_key' => 'tree', 'fetched_at' => self::NOW ] );
			$this->db->insert( Sqlite_Tables::table( 'catalog_cache' ), [ 'cache_key' => 'warehouses:78', 'fetched_at' => self::NOW ] );
		}
	}
}
