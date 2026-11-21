<?php
/**
 * The «Аналитика» tab of «Заказы маркетплейсов»: the SQL on SQLite against both order stores, and the arithmetic over it.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Query;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Report;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * Every storage test runs twice, with the orders in the posts table and in the HPOS table, over the catalogue of {@see Analytics_Shop},
 * and must give the same figures.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Report
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Query
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage
 */
final class OrdersAnalyticsReportTest extends TestCase {
	/**
	 * «Now» of the catalogue: 07.11.2026 12:00 UTC.
	 */
	private const NOW = 1794052800;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
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
	 * @param bool                      $hpos  Whether the orders live in the HPOS table.
	 * @param Posting_Status_Names|null $names The adapter's status names.
	 * @return Orders_Analytics_Report
	 */
	private function report( bool $hpos, ?Posting_Status_Names $names = null ): Orders_Analytics_Report {
		$shop = new Analytics_Shop( $hpos );

		return new Orders_Analytics_Report(
			new Orders_Analytics_Query( $shop->db, Sqlite_Tables::table( 'postings' ), $shop->storage() ),
			[
				1 => 'Основной',
				2 => 'Второй',
			],
			$names,
			Analytics_Shop::CREATED_VIA,
			static function ( int $id ): string {
				return 'order:' . $id;
			},
			static function ( int $id ): string {
				return 'product:' . $id;
			}
		);
	}

	/**
	 * @param array<string,mixed> $overrides Request keys to change.
	 * @return array<string,mixed>
	 */
	private function request( array $overrides = [] ): array {
		return array_merge(
			[
				'after'          => '2026-11-01',
				'before'         => '2026-11-07',
				'compare_after'  => '2026-10-25',
				'compare_before' => '2026-10-31',
				'interval'       => 'auto',
				'account'        => null,
				'week_start'     => 1,
				'timezone'       => new \DateTimeZone( 'Europe/Moscow' ),
				'now'            => self::NOW,
				'stuck_hours'    => Orders_Analytics_Report::STUCK_HOURS,
			],
			$overrides
		);
	}

	public function test_the_clock_of_the_catalogue_is_the_one_its_table_says(): void {
		$this->assertSame( '2026-11-07 12:00:00', gmdate( 'Y-m-d H:i:s', self::NOW ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_totals_count_the_site_days_of_the_period_and_leave_out_what_is_not_a_sale_attempt( bool $hpos ): void {
		$answer = $this->report( $hpos )->build( $this->request() );
		$now    = $answer['totals']['current'];

		$this->assertSame( 5, $now['orders'], '101–105: 105 is 00:30 on the 1st in Moscow, 106 is 00:30 on the 8th; 107 is pending and 108 trashed.' );
		$this->assertSame( 1, $now['cancelled'] );
		$this->assertSame( 0.2, $now['cancel_share'] );
		$this->assertSame( 4, $now['sold'] );
		$this->assertSame( 2000.0, $now['revenue'], 'Without the cancelled order.' );
		$this->assertSame( 500.0, $now['avg_order'] );
		$this->assertSame( 9.0, $now['units'], 'Product lines of the sold orders only: no shipping line, nothing of the cancelled order.' );
		$this->assertSame( 2.25, $now['units_per_order'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_previous_period_is_read_the_same_way_and_compared( bool $hpos ): void {
		$answer = $this->report( $hpos )->build( $this->request() );

		$this->assertSame( [ 'after' => '2026-10-25', 'before' => '2026-10-31' ], $answer['compare'] );
		$this->assertSame( 2, $answer['totals']['previous']['orders'], '201 and 202; 105 belongs to the current period on the site\'s day.' );
		$this->assertSame( 800.0, $answer['totals']['previous']['revenue'] );
		$this->assertSame( 0.5, $answer['totals']['previous']['cancel_share'] );
		$this->assertSame(
			[
				'orders'          => 150.0,
				'revenue'         => 150.0,
				'cancel_share'    => -60.0,
				'avg_order'       => -37.5,
				'units_per_order' => -43.8,
			],
			$answer['totals']['delta']
		);
	}

	/**
	 * @dataProvider storages
	 */
	public function test_without_a_previous_period_there_is_nothing_to_compare_with( bool $hpos ): void {
		$answer = $this->report( $hpos )->build( $this->request( [ 'compare_after' => '', 'compare_before' => '' ] ) );

		$this->assertNull( $answer['compare'] );
		$this->assertNull( $answer['totals']['previous'] );
		$this->assertSame( [ null ], array_values( array_unique( $answer['totals']['delta'] ) ) );
		$this->assertNull( $answer['share']['previous'] );
		$this->assertNull( $answer['share']['delta'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_each_account_has_its_own_row_and_the_account_filter_narrows_everything( bool $hpos ): void {
		$report = $this->report( $hpos );
		$all    = $report->build( $this->request() );

		$this->assertSame( [ '1', '2' ], array_column( $all['by_account'], 'id' ) );
		$this->assertSame( [ 'Основной', 'Второй' ], array_column( $all['by_account'], 'label' ) );
		$this->assertSame( 4, $all['by_account'][0]['current']['orders'] );
		$this->assertSame( 1700.0, $all['by_account'][0]['current']['revenue'] );
		$this->assertSame( 0.25, $all['by_account'][0]['current']['cancel_share'] );
		$this->assertSame( 300.0, $all['by_account'][1]['current']['revenue'] );
		$this->assertSame( 0.0, $all['by_account'][1]['current']['cancel_share'] );
		$this->assertNull( $all['by_account'][1]['previous']['avg_order'], 'Nothing sold before: no average.' );

		$one = $report->build( $this->request( [ 'account' => 2 ] ) );

		$this->assertSame( '2', $one['account'] );
		$this->assertSame( 1, $one['totals']['current']['orders'] );
		$this->assertSame( [ '2' ], array_column( $one['by_account'], 'id' ) );
		$this->assertSame( [ 'awaiting_deliver' ], array_column( $one['statuses'], 'code' ) );
		$this->assertSame( [ 'Худи — L' ], array_column( $one['products']['top'], 'label' ) );
		$this->assertSame( 0, $one['products']['unlinked']['names'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_chart_is_by_site_day_and_account_and_adds_up_to_the_totals( bool $hpos ): void {
		$answer = $this->report( $hpos )->build( $this->request() );
		$days   = $answer['intervals'];

		$this->assertSame( 'day', $answer['range']['interval'], 'Up to a month: by day.' );
		$this->assertCount( 7, $days );
		$this->assertSame( [ 'start' => '2026-11-01', 'end' => '2026-11-01' ], [ 'start' => $days[0]['start'], 'end' => $days[0]['end'] ] );
		$this->assertSame( [ 'orders' => 2, 'revenue' => 1200.0 ], $days[0]['totals'], '101 and 105 (00:30 site time).' );
		$this->assertSame( [ '1' => [ 'orders' => 2, 'revenue' => 1200.0 ] ], $days[0]['accounts'] );
		$this->assertSame( [ 'orders' => 1, 'revenue' => 0.0 ], $days[4]['totals'], 'The cancelled order is an order without revenue.' );
		$this->assertSame( [ '2' => [ 'orders' => 1, 'revenue' => 300.0 ] ], $days[6]['accounts'], '104 at 23:30 site time is on the 7th.' );
		$this->assertSame( 5, array_sum( array_column( array_column( $days, 'totals' ), 'orders' ) ) );

		$weeks = $this->report( $hpos )->build( $this->request( [ 'interval' => 'week' ] ) )['intervals'];

		$this->assertSame( [ '2026-11-01', '2026-11-02' ], array_column( $weeks, 'start' ), 'The 1st is a Sunday; weeks start on Monday, the first one at the period.' );
		$this->assertSame( [ 2, 3 ], array_column( array_column( $weeks, 'totals' ), 'orders' ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_marketplace_share_of_the_shop_revenue_is_by_created_via( bool $hpos ): void {
		$share = $this->report( $hpos )->build( $this->request() )['share'];

		$this->assertSame( 3500.0, $share['shop_revenue'], 'The marketplace\'s 2000 and the checkout\'s 1500; nothing cancelled, pending or outside the period.' );
		$this->assertSame( 2000.0, $share['marketplace_revenue'] );
		$this->assertSame( 0.5714, $share['share'] );
		$this->assertSame( 0.8, $share['previous'], '800 of 1000.' );
		$this->assertEqualsWithDelta( -28.6, $share['delta'], 0.05 );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_statuses_now_are_the_open_postings_of_live_orders_by_account( bool $hpos ): void {
		$statuses = $this->report( $hpos )->build( $this->request() )['statuses'];

		$this->assertSame( [ 'awaiting_packaging', 'awaiting_deliver', 'delivering', 'new' ], array_column( $statuses, 'code' ), 'The most frequent first, then by code; the trashed order\'s posting is not counted, finished ones neither.' );
		$this->assertSame( [ 2, 1, 1, 1 ], array_column( $statuses, 'count' ) );
		$this->assertSame( [ '1' => 2 ], $statuses[0]['accounts'] );
		$this->assertSame( [ '2' => 1 ], $statuses[1]['accounts'] );
		$this->assertSame( 'Ожидает упаковки', $statuses[0]['label'] );
		$this->assertSame( 'warning', $statuses[0]['tone'] );
	}

	public function test_the_adapter_names_its_own_statuses(): void {
		$names = new class() implements Posting_Status_Names {
			public function posting_status_name( string $status ): string {
				return 'awaiting_packaging' === $status ? 'Собрать заказ' : '';
			}
		};

		$statuses = $this->report( true, $names )->build( $this->request() )['statuses'];

		$this->assertSame( 'Собрать заказ', $statuses[0]['label'] );
		$this->assertSame( 'Ожидает отгрузки', $statuses[1]['label'], 'A status the adapter does not name keeps the shared word.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_a_posting_waiting_for_packing_or_shipping_longer_than_the_threshold_is_stuck( bool $hpos ): void {
		$stuck = $this->report( $hpos )->build( $this->request() )['stuck'];

		$this->assertSame( 24, $stuck['threshold_hours'] );
		$this->assertSame( 1, $stuck['count'], '101 since the 1st; 104 waits 6 hours; 106 is not even seen yet; 105 is on its way; 108 is trashed.' );
		$this->assertSame(
			[
				'order_id'  => 101,
				'order_url' => 'order:101',
				'posting'   => 'P-101',
				'account'   => 'Основной',
				'status'    => 'Ожидает упаковки',
				'hours'     => 146,
			],
			$stuck['items'][0]
		);

		$exact = $this->report( $hpos )->build( $this->request( [ 'stuck_hours' => 6 ] ) )['stuck'];

		$this->assertSame( 1, $exact['count'], '104 has waited exactly 6 hours: not longer than 6.' );

		$shorter = $this->report( $hpos )->build( $this->request( [ 'stuck_hours' => 5 ] ) )['stuck'];

		$this->assertSame( 2, $shorter['count'] );
		$this->assertSame( [ 'P-101', 'P-104' ], array_column( $shorter['items'], 'posting' ), 'The oldest first.' );
		$this->assertSame( 6, $shorter['items'][1]['hours'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_products_of_the_sold_orders_and_the_items_sold_without_a_shop_product( bool $hpos ): void {
		$products = $this->report( $hpos )->build( $this->request() )['products'];
		$top      = [];

		foreach ( $products['top'] as $row ) {
			$top[ $row['key'] ] = [ $row['label'], $row['url'], $row['quantity'], $row['revenue'] ];
		}

		ksort( $top );

		$this->assertSame(
			[
				'11-0'  => [ 'Футболка', 'product:11', 2.0, 1000.0 ],
				'12-0'  => [ 'Чашка', 'product:12', 1.0, 300.0 ],
				'13-14' => [ 'Худи — L', 'product:13', 3.0, 300.0 ],
			],
			$top,
			'The cancelled order\'s line, the order outside the period and the checkout\'s order are not counted; a variation is its own row.'
		);
		$this->assertSame(
			[
				'items'    => [
					[
						'label'    => 'Кружка',
						'quantity' => 3.0,
						'revenue'  => 400.0,
					],
				],
				'names'    => 1,
				'quantity' => 3.0,
				'revenue'  => 400.0,
			],
			$products['unlinked'],
			'Lines without a shop product are summed by their name.'
		);
	}

	public function test_a_period_boundary_is_the_site_midnight_in_gmt(): void {
		$scope = Orders_Analytics_Report::scope( '2026-11-01', '2026-11-07', new \DateTimeZone( 'Europe/Moscow' ), [ 1 ] );

		$this->assertSame( '2026-10-31 21:00:00', $scope['from'] );
		$this->assertSame( '2026-11-07 21:00:00', $scope['to'], 'Exclusive: the start of the day after the last one.' );
		$this->assertSame( 10800, $scope['offset'] );
	}

	public function test_a_ratio_over_nothing_is_null_never_zero(): void {
		$metrics = Orders_Analytics_Report::metrics(
			[
				'orders'    => 0,
				'cancelled' => 0,
				'sold'      => 0,
				'revenue'   => 0.0,
				'units'     => 0.0,
			]
		);

		$this->assertNull( $metrics['cancel_share'] );
		$this->assertNull( $metrics['avg_order'] );
		$this->assertNull( $metrics['units_per_order'] );
	}

	public function test_no_account_reads_nothing_and_still_answers(): void {
		$shop   = new Analytics_Shop( true );
		$report = new Orders_Analytics_Report(
			new Orders_Analytics_Query( $shop->db, Sqlite_Tables::table( 'postings' ), $shop->storage() ),
			[],
			null,
			Analytics_Shop::CREATED_VIA,
			'strval',
			'strval'
		);
		$answer = $report->build( $this->request() );

		$this->assertSame( 0, $answer['totals']['current']['orders'] );
		$this->assertSame( [], $answer['by_account'] );
		$this->assertSame( [], $answer['statuses'] );
		$this->assertSame( 0, $answer['stuck']['count'] );
		$this->assertSame( 3500.0, $answer['share']['shop_revenue'], 'The shop\'s revenue does not depend on the accounts.' );
	}
}
