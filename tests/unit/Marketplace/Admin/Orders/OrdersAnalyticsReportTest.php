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
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Groups;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * Every storage test runs twice, with the orders in the posts table and in the HPOS table, over the catalogue of {@see Analytics_Shop},
 * and must give the same figures. The adapter classifies its codes the way the Ozon one does (packing and handing over wait on the
 * shop; `cancelled` and `not_accepted` are rejections) unless a test says otherwise.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Report
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Query
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets
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
	 * An adapter's classification like Ozon's.
	 *
	 * @return Posting_Status_Groups
	 */
	private static function groups(): Posting_Status_Groups {
		return new class() implements Posting_Status_Groups {
			public function waiting_posting_statuses(): array {
				return [ 'awaiting_packaging', 'awaiting_deliver' ];
			}

			public function rejected_posting_statuses(): array {
				return [ 'cancelled', 'not_accepted' ];
			}
		};
	}

	/**
	 * @param bool                       $hpos     Whether the orders live in the HPOS table.
	 * @param Posting_Status_Names|null  $names    The adapter's status names.
	 * @param bool                       $classify Whether the adapter classifies its codes ({@see self::groups()}); the defaults otherwise.
	 * @param array<int,string>          $accounts Account labels.
	 * @param Posting_Status_Groups|null $explicit Another classification than {@see self::groups()}, when classified.
	 * @param Analytics_Shop|null        $shop     The shop with the test's own rows added; the plain catalogue otherwise.
	 * @return Orders_Analytics_Report
	 */
	private function report( bool $hpos, ?Posting_Status_Names $names = null, bool $classify = true, array $accounts = [ 1 => 'Основной', 2 => 'Второй' ], ?Posting_Status_Groups $explicit = null, ?Analytics_Shop $shop = null ): Orders_Analytics_Report {
		$shop   = $shop ?? new Analytics_Shop( $hpos );
		$groups = $classify ? ( $explicit ?? self::groups() ) : null;

		return new Orders_Analytics_Report(
			new Orders_Analytics_Query( $shop->db, Sqlite_Tables::table( 'postings' ), $shop->storage(), Posting_Status_Sets::rejected( $groups ) ),
			$accounts,
			$names,
			Analytics_Shop::CREATED_VIA,
			static function ( int $id ): string {
				return 'order:' . $id;
			},
			static function ( int $id ): string {
				return 'product:' . $id;
			},
			$groups
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
	public function test_the_totals_count_the_site_days_of_the_period_pending_orders_included( bool $hpos ): void {
		$now = $this->report( $hpos )->build( $this->request() )['totals']['current'];

		$this->assertSame( 7, $now['orders'], '101–105, 107 and 109: 105 is 00:30 on the 1st in Moscow, 106 is 00:30 on the 8th; 107 is pending — the merchant\'s new-order status — and counts; 108 is trashed.' );
		$this->assertSame( 2, $now['cancelled'], '103 is a cancelled order, 109 a posting the marketplace did not accept (its order is still «В обработке»).' );
		$this->assertSame( 0.2857, $now['cancel_share'] );
		$this->assertSame( 5, $now['sold'] );
		$this->assertSame( 2070.0, $now['revenue'], 'Without the cancelled order and the rejected posting; with the pending one.' );
		$this->assertSame( 414.0, $now['avg_order'] );
		$this->assertSame( 9.0, $now['units'], 'Product lines of the sold orders only: no shipping line, nothing of the cancelled or the rejected one.' );
		$this->assertSame( 1.8, $now['units_per_order'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_without_the_adapter_s_classification_only_the_cancelled_order_is_a_cancellation( bool $hpos ): void {
		$answer = $this->report( $hpos, null, false )->build( $this->request() );
		$now    = $answer['totals']['current'];

		$this->assertFalse( $answer['classified'], 'The answer says the classification is unavailable, so the tab does not promise rejections.' );
		$this->assertSame( [], Posting_Status_Sets::rejected( null ), 'No guess from canonical words.' );
		$this->assertSame( 1, $now['cancelled'], 'Only the cancelled order: `not_accepted` is the marketplace\'s code, the framework does not know it.' );
		$this->assertSame( 2470.0, $now['revenue'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_previous_period_is_read_the_same_way_and_compared( bool $hpos ): void {
		$answer = $this->report( $hpos )->build( $this->request() );
		$delta  = $answer['totals']['delta'];

		$this->assertSame( [ 'after' => '2026-10-25', 'before' => '2026-10-31' ], $answer['compare'] );
		$this->assertSame( 2, $answer['totals']['previous']['orders'], '201 and 202; 105 belongs to the current period on the site\'s day.' );
		$this->assertSame( 800.0, $answer['totals']['previous']['revenue'] );
		$this->assertSame( 0.5, $answer['totals']['previous']['cancel_share'] );
		$this->assertSame( [ 'orders', 'revenue', 'cancel_share', 'avg_order', 'units_per_order' ], array_keys( $delta ) );
		$this->assertEqualsWithDelta( 250.0, $delta['orders'], 0.05 );
		$this->assertEqualsWithDelta( 158.8, $delta['revenue'], 0.051 );
		$this->assertEqualsWithDelta( -42.9, $delta['cancel_share'], 0.051 );
		$this->assertEqualsWithDelta( -48.3, $delta['avg_order'], 0.051 );
		$this->assertEqualsWithDelta( -55.0, $delta['units_per_order'], 0.05 );
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
		$this->assertSame( 6, $all['by_account'][0]['current']['orders'] );
		$this->assertSame( 1770.0, $all['by_account'][0]['current']['revenue'] );
		$this->assertSame( 0.3333, $all['by_account'][0]['current']['cancel_share'] );
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
		$this->assertSame( [ '1', '2' ], array_column( $one['options'], 'id' ), 'The filter still offers every account.' );
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
		$this->assertSame( [ 'orders' => 1, 'revenue' => 70.0 ], $days[1]['totals'], 'The pending order.' );
		$this->assertSame( [ 'orders' => 1, 'revenue' => 0.0 ], $days[3]['totals'], 'The rejected posting is an order without revenue.' );
		$this->assertSame( [ 'orders' => 1, 'revenue' => 0.0 ], $days[4]['totals'], 'So is the cancelled order.' );
		$this->assertSame( [ '2' => [ 'orders' => 1, 'revenue' => 300.0 ] ], $days[6]['accounts'], '104 at 23:30 site time is on the 7th.' );
		$this->assertSame( 7, array_sum( array_column( array_column( $days, 'totals' ), 'orders' ) ) );

		$weeks = $this->report( $hpos )->build( $this->request( [ 'interval' => 'week' ] ) )['intervals'];

		$this->assertSame( [ '2026-11-01', '2026-11-02' ], array_column( $weeks, 'start' ), 'The 1st is a Sunday; weeks start on Monday, the first one at the period.' );
		$this->assertSame( [ 2, 5 ], array_column( array_column( $weeks, 'totals' ), 'orders' ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_marketplace_share_of_the_shop_revenue_is_by_created_via( bool $hpos ): void {
		$share = $this->report( $hpos )->build( $this->request() )['share'];

		$this->assertSame( 3570.0, $share['shop_revenue'], 'The marketplace\'s 2070 (its pending order included, the rejected posting not) and the checkout\'s 1500; not the unpaid checkout order, nothing cancelled or outside the period.' );
		$this->assertSame( 2070.0, $share['marketplace_revenue'], 'The same sum as the revenue tile.' );
		$this->assertSame( 0.5798, $share['share'] );
		$this->assertSame( 0.8, $share['previous'], '800 of 1000.' );
		$this->assertEqualsWithDelta( -27.5, $share['delta'], 0.051 );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_with_one_account_the_share_is_that_account_s_part_of_the_shop_revenue( bool $hpos ): void {
		$first  = $this->report( $hpos )->build( $this->request( [ 'account' => 1 ] ) );
		$second = $this->report( $hpos )->build( $this->request( [ 'account' => 2 ] ) );

		$this->assertSame( 3570.0, $first['share']['shop_revenue'], 'The denominator is the whole shop whichever account is chosen.' );
		$this->assertSame( 1770.0, $first['share']['marketplace_revenue'], 'Account 1: orders 101, 102, 105 and the pending 107; not the other account\'s 300.' );
		$this->assertSame( $first['totals']['current']['revenue'], $first['share']['marketplace_revenue'], 'The same sum as the revenue tile of the account.' );
		$this->assertSame( 0.4958, $first['share']['share'], '1770 of 3570, not the marketplace\'s 0.5798.' );
		$this->assertSame( 0.8, $first['share']['previous'], 'The previous period is the account\'s too: 800 of 1000.' );

		$this->assertSame( 3570.0, $second['share']['shop_revenue'] );
		$this->assertSame( 300.0, $second['share']['marketplace_revenue'] );
		$this->assertSame( 0.084, $second['share']['share'] );
		$this->assertSame( 0.0, $second['share']['previous'], 'The account sold nothing in the previous period; the shop did.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_with_every_account_the_share_stays_the_marketplace_s_by_created_via( bool $hpos ): void {
		$shop = new Analytics_Shop( $hpos );
		$shop->order( 120, 'wc-completed', '2026-11-03 10:00:00', 100, [] );
		// An order of the marketplace whose posting is of an account the tab does not list (a disabled one): still the marketplace's.
		$shop->posting( 9, 'P-120', 120, 'delivered', true );

		$share = $this->report( $hpos, null, true, [ 1 => 'Основной', 2 => 'Второй' ], null, $shop )->build( $this->request() )['share'];

		$this->assertSame( 3670.0, $share['shop_revenue'] );
		$this->assertSame( 2170.0, $share['marketplace_revenue'], 'Every account of the marketplace, the unlisted one included.' );

		$one = $this->report( $hpos, null, true, [ 1 => 'Основной', 2 => 'Второй' ], null, $shop )->build( $this->request( [ 'account' => 1 ] ) )['share'];

		$this->assertSame( 1770.0, $one['marketplace_revenue'], 'One account counts its own orders only.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_statuses_now_are_every_posting_of_a_live_order_finished_ones_included( bool $hpos ): void {
		$statuses = $this->report( $hpos )->build( $this->request() )['statuses'];

		$this->assertSame(
			[ 'awaiting_packaging', 'cancelled', 'delivered', 'awaiting_deliver', 'delivering', 'new', 'not_accepted' ],
			array_column( $statuses, 'code' ),
			'The most frequent first, then by code; delivered and cancelled postings count, the trashed order\'s does not.'
		);
		$this->assertSame( [ 2, 2, 2, 1, 1, 1, 1 ], array_column( $statuses, 'count' ) );
		$this->assertSame( [ '1' => 2 ], $statuses[0]['accounts'] );
		$this->assertSame( [ '2' => 1 ], $statuses[3]['accounts'] );
		$this->assertSame( 'Ожидает упаковки', $statuses[0]['label'] );
		$this->assertSame( 'warning', $statuses[0]['tone'] );
		$this->assertSame( 'success', $statuses[2]['tone'] );
	}

	public function test_the_adapter_names_its_own_statuses(): void {
		$names = new class() implements Posting_Status_Names {
			public function posting_status_name( string $status ): string {
				return 'awaiting_packaging' === $status ? 'Собрать заказ' : '';
			}
		};

		$statuses = $this->report( true, $names )->build( $this->request() )['statuses'];

		$this->assertSame( 'Собрать заказ', $statuses[0]['label'] );
		$this->assertSame( 'Ожидает отгрузки', $statuses[3]['label'], 'A status the adapter does not name keeps the shared word.' );
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
	 * A history import makes `first_seen_at` the import day; the date the marketplace gives for the posting is what counts.
	 *
	 * @dataProvider storages
	 */
	public function test_a_posting_is_stuck_from_the_date_the_marketplace_took_it_into_processing_not_from_the_import( bool $hpos ): void {
		$shop = new Analytics_Shop( $hpos );
		// Imported today (07.11 11:00 UTC) but in processing since 05.11 08:00 UTC: 52 hours.
		$shop->order( 110, 'wc-processing', '2026-11-05 08:00:00', 100, [] );
		$shop->posting( 1, 'P-110', 110, 'awaiting_packaging', false, '2026-11-07 11:00:00', '2026-11-05 08:00:00' );
		// Seen on the 1st but in processing only two hours ago, by the marketplace's own word: not stuck.
		$shop->order( 111, 'wc-processing', '2026-11-07 10:00:00', 100, [] );
		$shop->posting( 1, 'P-111', 111, 'awaiting_packaging', false, '2026-11-01 00:00:00', '2026-11-07 10:00:00' );
		// The adapter said nothing: counted from the first sight, as before. Imported today, so not stuck.
		$shop->order( 112, 'wc-processing', '2026-11-05 08:00:00', 100, [] );
		$shop->posting( 1, 'P-112', 112, 'awaiting_packaging', false, '2026-11-07 11:00:00' );

		$stuck = $this->report( $hpos, null, true, [ 1 => 'Основной', 2 => 'Второй' ], null, $shop )->build( $this->request() )['stuck'];

		$this->assertSame( 2, $stuck['count'], 'P-101 (first seen the 1st, no date) and P-110; not P-111 (taken in two hours ago) or P-112 (first seen an hour ago).' );
		$this->assertSame( [ 'P-101', 'P-110' ], array_column( $stuck['items'], 'posting' ), 'The oldest wait first.' );
		$this->assertSame( 52, $stuck['items'][1]['hours'], 'Hours since 05.11 08:00, not since the import.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_without_the_adapter_s_classification_there_is_no_stuck_block_at_all( bool $hpos ): void {
		$answer = $this->report( $hpos, null, false )->build( $this->request() );

		$this->assertNull( Posting_Status_Sets::waiting( null ) );
		$this->assertNull( $answer['stuck'], 'Not «none stuck»: P-101 has waited 146 hours, but only the adapter knows its code waits on the shop.' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_an_adapter_that_classifies_with_empty_lists_is_believed( bool $hpos ): void {
		$none = new class() implements Posting_Status_Groups {
			public function waiting_posting_statuses(): array {
				return [];
			}

			public function rejected_posting_statuses(): array {
				return [];
			}
		};

		$answer = $this->report( $hpos, null, true, [ 1 => 'Основной', 2 => 'Второй' ], $none )->build( $this->request() );

		$this->assertTrue( $answer['classified'] );
		$this->assertSame( 0, $answer['stuck']['count'], 'The adapter says none of its codes waits on the shop: «none stuck» is its answer.' );
		$this->assertSame( [], $answer['stuck']['items'] );
		$this->assertSame( 1, $answer['totals']['current']['cancelled'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_with_the_adapter_s_classification_the_answer_says_so( bool $hpos ): void {
		$this->assertTrue( $this->report( $hpos )->build( $this->request() )['classified'] );
	}

	public function test_an_adapter_s_classification_is_cleaned(): void {
		$groups = new class() implements Posting_Status_Groups {
			public function waiting_posting_statuses(): array {
				return [ 'a', '', 'a', 7 ];
			}

			public function rejected_posting_statuses(): array {
				return [];
			}
		};

		$this->assertSame( [ 'a' ], Posting_Status_Sets::waiting( $groups ) );
		$this->assertSame( [], Posting_Status_Sets::rejected( $groups ), 'An adapter may say none of its codes is a rejection.' );
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
			'The cancelled order\'s and the rejected posting\'s lines, the order outside the period and the checkout\'s order are not counted; a variation is its own row.'
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
		$answer = $this->report( true, null, true, [] )->build( $this->request() );

		$this->assertSame( 0, $answer['totals']['current']['orders'] );
		$this->assertSame( [], $answer['by_account'] );
		$this->assertSame( [], $answer['statuses'] );
		$this->assertSame( 0, $answer['stuck']['count'] );
		$this->assertSame( 3570.0, $answer['share']['shop_revenue'], 'The shop\'s revenue does not depend on the accounts.' );
	}
}
