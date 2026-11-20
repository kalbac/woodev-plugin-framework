<?php
/**
 * Unit tests for the marketplace's status and the account of the posting an order was made from.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * `Order_Registry::status_of_order()` and `account_of_order()` run their real SQL on SQLite over the schema's own columns.
 */
final class OrderRegistryStatusTest extends TestCase {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Opens the database and registers one posting with an order.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->db->insert(
			Sqlite_Tables::table( 'postings' ),
			[
				'account_id'    => 1,
				'external_key'  => 'P-1',
				'wc_order_id'   => 700,
				'status_raw'    => 'awaiting_deliver',
				'first_seen_at' => '2026-11-20 10:00:00',
			]
		);
	}

	public function test_the_status_of_a_registered_order_is_read(): void {
		$this->assertSame( 'awaiting_deliver', $this->registry()->status_of_order( 700 ) );
	}

	public function test_an_order_without_a_posting_has_no_status(): void {
		$this->assertSame( '', $this->registry()->status_of_order( 701 ) );
	}

	public function test_the_account_of_a_registered_order_is_read(): void {
		$this->assertSame( 1, $this->registry()->account_of_order( 700 ) );
	}

	public function test_an_order_without_a_posting_has_no_account(): void {
		$this->assertSame( 0, $this->registry()->account_of_order( 701 ) );
	}

	/**
	 * Builds the registry over the test table.
	 *
	 * @return Order_Registry
	 */
	private function registry(): Order_Registry {
		return new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) );
	}
}
