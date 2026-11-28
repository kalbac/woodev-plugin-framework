<?php
/**
 * A posting status with two axes in the «Заказы» table's filter.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Rows;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * A code such as «confirm/waiting» is a value the records engine accepts, and choosing it finds its orders: the engine takes no
 * `/` in a value, so the whole table failed on such a marketplace.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Rows
 */
final class PostingStatusValueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	public function test_the_facet_value_is_one_the_engine_takes_and_reads_back_to_the_stored_code(): void {
		$rows  = new Site_Orders_Rows( [], '__return_true', '__return_false' );
		$value = $rows->posting_status_options( [ 'confirm/canceled_by_missed_call' => 2 ] )[0]['value'];

		$this->assertSame( 1, preg_match( Records_Definition::VALUE_PATTERN, $value ) );
		$this->assertSame(
			'confirm/canceled_by_missed_call',
			Site_Orders_Filter::from_query( 1, [ 'filters' => [ 'posting_status' => $value ] ] )->posting_status()
		);
		$this->assertSame( 'delivering', Site_Orders_Filter::posting_status_value( 'delivering' ) );
	}

	public function test_the_reader_finds_the_orders_of_such_a_code(): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$shop  = new Orders_Shop( $db, true );
		$table = Sqlite_Tables::table( 'postings' );
		$db->query( "UPDATE {$table} SET status_raw = 'new/waiting' WHERE external_key = 'P-102'" );

		$filter = Site_Orders_Filter::from_query( Orders_Shop::ACCOUNT, [ 'filters' => [ 'posting_status' => 'new::waiting' ] ] );
		$page   = ( new Site_Orders_Reader( $db, $table, $shop->storage() ) )->page( $filter, 1, 20 );

		$this->assertSame( [ 102 ], array_column( $page['items'], 'order_id' ) );
	}
}
