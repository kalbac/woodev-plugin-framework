<?php
/**
 * The SQL of the «Заказы» table, run on SQLite against both order stores.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Every test runs twice: once with the orders in the posts table (CPT) and once in the HPOS table, over the same catalogue, and must
 * list the same orders in the same order.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids
 */
final class SiteOrdersReaderTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function storages(): array {
		return [
			'posts table (CPT)' => [ false ],
			'HPOS table'        => [ true ],
		];
	}

	/**
	 * @param bool $hpos Whether the orders live in the HPOS table.
	 * @return Site_Orders_Reader
	 */
	private function reader( bool $hpos ): Site_Orders_Reader {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );

		$shop = new Orders_Shop( $db, $hpos );

		return new Site_Orders_Reader( $db, Sqlite_Tables::table( 'postings' ), $shop->storage() );
	}

	/**
	 * @param Site_Orders_Reader   $reader   Reader.
	 * @param array<string,mixed>  $filters  Filter arguments, in the order of the constructor.
	 * @param int                  $page     Page.
	 * @param int                  $per_page Page size.
	 * @return int[] Order ids in the order listed.
	 */
	private function ids( Site_Orders_Reader $reader, array $filters = [], int $page = 1, int $per_page = 20 ): array {
		$filter = new Site_Orders_Filter( Orders_Shop::ACCOUNT, ...array_values( $filters ) );

		return array_column( $reader->page( $filter, $page, $per_page )['items'], 'order_id' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_only_real_orders_of_the_account_are_listed_newest_first( bool $hpos ): void {
		$page = $this->reader( $hpos )->page( new Site_Orders_Filter( Orders_Shop::ACCOUNT ), 1, 20 );

		$this->assertSame( 4, $page['total'], 'Not the trashed order, the registry row with no order, the vanished order, the refund or the other account\'s order.' );
		$this->assertSame( [ 104, 103, 102, 101 ], array_column( $page['items'], 'order_id' ) );
		$this->assertSame(
			[
				'order_id'     => 104,
				'external_key' => 'P-104',
				'status_raw'   => 'delivering',
				'is_terminal'  => false,
				'claim_until'  => '',
			],
			$page['items'][0]
		);
		$this->assertTrue( $page['items'][1]['is_terminal'], 'A cancelled posting is finished.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_every_sort_orders_the_whole_table_in_both_directions( bool $hpos ): void {
		$reader = $this->reader( $hpos );

		$this->assertSame( [ 101, 102, 103, 104 ], $this->ids( $reader, [ '', '', '', '', '', 'date', 'asc' ] ) );
		$this->assertSame( [ 102, 101, 104, 103 ], $this->ids( $reader, [ '', '', '', '', '', 'total', 'desc' ] ), 'The total is compared as a number: 1500.5, 500, 250, 99.' );
		$this->assertSame( [ 103, 104, 101, 102 ], $this->ids( $reader, [ '', '', '', '', '', 'total', 'asc' ] ) );
		$this->assertSame( [ 104, 103, 102, 101 ], $this->ids( $reader, [ '', '', '', '', '', 'order', 'desc' ] ) );
		$this->assertSame( [ 101, 102, 103, 104 ], $this->ids( $reader, [ '', '', '', '', '', 'posting', 'asc' ] ) );
		$this->assertSame( [ 101, 103, 102, 104 ], $this->ids( $reader, [ '', '', '', '', '', 'status', 'asc' ] ), 'By the marketplace\'s own status: awaiting_packaging, cancelled, delivered, delivering.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_paging_continues_the_sorted_whole_and_the_total_does_not_depend_on_the_page( bool $hpos ): void {
		$reader = $this->reader( $hpos );
		$first  = $reader->page( new Site_Orders_Filter( Orders_Shop::ACCOUNT ), 1, 3 );
		$second = $reader->page( new Site_Orders_Filter( Orders_Shop::ACCOUNT ), 2, 3 );
		$third  = $reader->page( new Site_Orders_Filter( Orders_Shop::ACCOUNT ), 3, 3 );

		$this->assertSame( [ 104, 103, 102 ], array_column( $first['items'], 'order_id' ) );
		$this->assertSame( [ 101 ], array_column( $second['items'], 'order_id' ) );
		$this->assertSame( [], $third['items'] );
		$this->assertSame( [ 4, 4, 4 ], [ $first['total'], $second['total'], $third['total'] ] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_posting_state_the_woocommerce_status_and_the_search_narrow_the_whole_table( bool $hpos ): void {
		$reader = $this->reader( $hpos );

		$this->assertSame( [ 104, 101 ], $this->ids( $reader, [ Site_Orders_Filter::STATE_OPEN ] ) );
		$this->assertSame( [ 103, 102 ], $this->ids( $reader, [ Site_Orders_Filter::STATE_FINISHED ] ) );
		$this->assertSame( [ 104, 101 ], $this->ids( $reader, [ '', 'wc-processing' ] ) );
		$this->assertSame( [ 103 ], $this->ids( $reader, [ '', '', '', '', 'P-103' ] ), 'A part of the posting number is enough.' );
		$this->assertSame( [ 102 ], $this->ids( $reader, [ '', '', '', '', '102' ] ), 'A number is also an order id.' );
		$this->assertSame( [ 103 ], $this->ids( $reader, [ Site_Orders_Filter::STATE_FINISHED, 'wc-cancelled' ] ), 'Filters combine.' );
		$this->assertSame( [], $this->ids( $reader, [ '', '', '', '', 'no such posting' ] ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_date_range_is_compared_with_the_creation_time_in_utc( bool $hpos ): void {
		$reader = $this->reader( $hpos );

		$this->assertSame( [ 103, 102 ], $this->ids( $reader, [ '', '', '2026-11-02 00:00:00', '2026-11-06 23:59:59' ] ) );
		$this->assertSame( [ 104 ], $this->ids( $reader, [ '', '', '2026-11-06 00:00:00' ] ), 'No upper end.' );
		$this->assertSame( [ 102, 101 ], $this->ids( $reader, [ '', '', '', '2026-11-03 09:00:00' ] ), 'The ends are inclusive.' );
	}

	public function test_the_engines_dates_are_the_shops_calendar_days_converted_to_utc(): void {
		Functions\when( 'wp_timezone' )->alias(
			static function (): \DateTimeZone {
				return new \DateTimeZone( 'Europe/Moscow' );
			}
		);

		$reader = $this->reader( false );
		$filter = Site_Orders_Filter::from_query(
			Orders_Shop::ACCOUNT,
			[
				'filters' => [
					'date' => [
						'from' => '2026-11-03',
						'to'   => '2026-11-05',
					],
				],
			]
		);

		$this->assertSame( '2026-11-02 21:00:00', $filter->from(), 'Midnight in Moscow is 21:00 the evening before in UTC.' );
		$this->assertSame( '2026-11-05 20:59:59', $filter->to() );
		// The cancelled order was created at 12:00 UTC on the 5th, 15:00 in Moscow: inside the last day.
		$this->assertSame( [ 103, 102 ], array_column( $reader->page( $filter, 1, 20 )['items'], 'order_id' ) );
	}

	public function test_a_query_the_engine_validated_becomes_a_filter_and_a_stray_value_is_ignored(): void {
		$filter = Site_Orders_Filter::from_query(
			Orders_Shop::ACCOUNT,
			[
				'search'    => 'fallback',
				'sort'      => 'title; DROP TABLE x',
				'direction' => 'sideways',
				'filters'   => [
					'state'     => 'sold',
					'wc_status' => "wc-x'; --",
				],
			]
		);

		$this->assertSame( '', $filter->state() );
		$this->assertSame( '', $filter->wc_status() );
		$this->assertSame( 'fallback', $filter->search(), 'Without the search filter the engine\'s own search box counts.' );

		$db = new Sqlite_Wpdb();

		$this->assertSame( 'o.date_created_gmt DESC, pg.wc_order_id DESC', $filter->order_by( new Site_Orders_Storage( true, 'wp_wc_orders' ), $db ), 'An unknown sort is the date, newest first.' );
		$this->assertSame( 'o.post_date_gmt DESC, pg.wc_order_id DESC', $filter->order_by( new Site_Orders_Storage( false, 'wp_posts' ), $db ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_find_reads_the_registry_rows_of_the_asked_orders_of_the_account_only( bool $hpos ): void {
		$found = $this->reader( $hpos )->find( Orders_Shop::ACCOUNT, [ 101, 105, 108, 201, 999, 104 ] );

		$this->assertSame( [ 101, 104 ], array_keys( $found ), 'Not the trashed order, the refund, the other account\'s order or the unknown one.' );
		$this->assertSame( 'P-101', $found[101]['external_key'] );
		$this->assertSame( [], $this->reader( $hpos )->find( Orders_Shop::ACCOUNT, [] ) );
	}

	public function test_the_two_storages_join_their_own_tables(): void {
		$db = new Sqlite_Wpdb();

		$hpos = new Site_Orders_Storage( true, 'wp_wc_orders' );
		$cpt  = new Site_Orders_Storage( false, 'wp_posts' );

		$this->assertSame( 'INNER JOIN "wp_wc_orders" AS o ON o.id = pg.wc_order_id', $hpos->join( $db ) );
		$this->assertSame( 'INNER JOIN "wp_posts" AS o ON o.ID = pg.wc_order_id', $cpt->join( $db ) );
		$this->assertStringContainsString( "o.type = 'shop_order'", $hpos->live_condition() );
		$this->assertStringContainsString( "o.post_type = 'shop_order'", $cpt->live_condition() );
		$this->assertTrue( $hpos->is_hpos() );
		$this->assertFalse( $cpt->is_hpos() );
	}

	public function test_a_row_id_names_the_account_and_the_order(): void {
		$this->assertSame( '1:104', Site_Orders_Ids::row_id( 1, 104 ) );
		$this->assertSame( 104, Site_Orders_Ids::order_id( '1:104', 1 ) );
		$this->assertNull( Site_Orders_Ids::order_id( '2:104', 1 ), 'Another account\'s row.' );
		$this->assertNull( Site_Orders_Ids::order_id( '1:0', 1 ) );
		$this->assertNull( Site_Orders_Ids::order_id( '1:104;DROP', 1 ) );
		$this->assertNull( Site_Orders_Ids::order_id( '104', 1 ) );
	}
}
