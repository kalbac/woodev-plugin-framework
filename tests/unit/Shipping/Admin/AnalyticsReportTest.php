<?php
/**
 * Unit: the report the analytics route answers with (#711).
 *
 * The query is a Mockery double returning aggregate rows, so this pins the joining of the two periods per carrier, the
 * store-timezone → GMT window (both days inclusive), the carrier ordering and omission of empty carriers, the shipping-method
 * table and the coverage figures.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Analytics_Query, Analytics_Report, Orders_Provider};
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Analytics_Report
 */
final class AnalyticsReportTest extends TestCase {

	private function providers(): array {
		return [
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ),
			Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ] ),
			Orders_Provider::create( 'ozon', 'Ozon', '_ozon_marker', [ 'ozon' ] ),
		];
	}

	private function request( array $overrides = [] ): array {
		return $overrides + [
			'after'          => '2026-10-01',
			'before'         => '2026-10-03',
			'compare_after'  => '',
			'compare_before' => '',
			'interval'       => 'auto',
			'carrier'        => null,
			'week_start'     => 1,
			'excluded'       => [ 'trash' ],
			'timezone'       => new \DateTimeZone( 'Europe/Moscow' ),
		];
	}

	private function carrier_row( string $carrier, int $orders, float $revenue, int $delivered = 0, int $time_sample = 0, int $time_seconds = 0 ): array {
		return [
			'carrier'       => $carrier,
			'orders'        => (string) $orders,
			'revenue'       => (string) $revenue,
			'shipping_paid' => '100',
			'delivered'     => (string) $delivered,
			'time_sample'   => (string) $time_sample,
			'time_seconds'  => (string) $time_seconds,
		];
	}

	private function report( Analytics_Query $query ): Analytics_Report {
		return new Analytics_Report( $query, $this->providers(), static fn( string $id ): string => 'Метод ' . $id );
	}

	public function test_the_window_is_the_store_days_both_inclusive_as_a_half_open_gmt_range(): void {
		$scopes = [];
		$query  = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->andReturnUsing(
			static function ( $providers, $scope ) use ( &$scopes ) {
				$scopes[] = $scope;

				return [];
			}
		);
		$query->shouldReceive( 'day_rows' )->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->andReturn( [] );

		$this->report( $query )->build( $this->request() );

		// Moscow is UTC+3 all year: 2026-10-01 00:00 local = 2026-09-30 21:00 GMT; the end is 2026-10-04 00:00 local, exclusive.
		$this->assertSame( '2026-09-30 21:00:00', $scopes[0]['from'] );
		$this->assertSame( '2026-10-03 21:00:00', $scopes[0]['to'] );
		$this->assertSame( 10800, $scopes[0]['offset'] );
		$this->assertSame( [ 'trash' ], $scopes[0]['excluded'] );
	}

	public function test_carriers_follow_registry_order_and_empty_ones_are_left_out(): void {
		$query = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->andReturn( [ $this->carrier_row( 'yandex', 3, 300 ), $this->carrier_row( 'cdek', 7, 700, 4, 2, 4 * 86400 ) ] );
		$query->shouldReceive( 'day_rows' )->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->andReturn( [] );

		$report = $this->report( $query )->build( $this->request() );

		$this->assertSame( [ 'cdek', 'yandex' ], array_column( $report['carriers'], 'id' ) );
		$this->assertSame( 'СДЭК', $report['carriers'][0]['label'] );
		$this->assertSame( 10, $report['totals']['current']['orders'] );
		$this->assertSame( 1000.0, $report['totals']['current']['revenue'] );
		$this->assertNull( $report['totals']['previous'] );
		$this->assertNull( $report['compare'] );
		$this->assertNull( $report['previous_intervals'] );
		$this->assertSame( [ 'delivered' => 4, 'time_sample' => 2 ], $report['coverage'] );
		$this->assertSame( 2.0, $report['carriers'][0]['current']['avg_days_to_delivery'] );
	}

	public function test_a_previous_period_is_read_with_its_own_window_and_joined_per_carrier(): void {
		$scopes = [];
		$query  = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->twice()->andReturnUsing(
			function ( $providers, $scope ) use ( &$scopes ) {
				$scopes[] = $scope['from'];

				return 1 === count( $scopes )
					? [ $this->carrier_row( 'cdek', 12, 1200 ) ]
					: [ $this->carrier_row( 'cdek', 10, 1000 ), $this->carrier_row( 'ozon', 5, 500 ) ];
			}
		);
		$query->shouldReceive( 'day_rows' )->twice()->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->once()->andReturn( [] );

		$report = $this->report( $query )->build( $this->request( [ 'compare_after' => '2026-09-28', 'compare_before' => '2026-09-30' ] ) );

		$this->assertSame( [ '2026-09-30 21:00:00', '2026-09-27 21:00:00' ], $scopes );
		$this->assertSame( [ 'after' => '2026-09-28', 'before' => '2026-09-30' ], $report['compare'] );
		$this->assertSame( [ 'cdek', 'ozon' ], array_column( $report['carriers'], 'id' ), 'a carrier present only in the previous period is still listed' );
		$this->assertSame( 20.0, $report['carriers'][0]['delta']['orders'] );
		$this->assertSame( 0, $report['carriers'][1]['current']['orders'] );
		$this->assertSame( -100.0, $report['carriers'][1]['delta']['orders'], 'a drop to zero is a -100 change' );
		$this->assertSame( 15, $report['totals']['previous']['orders'] );
		$this->assertCount( 3, $report['previous_intervals'] );
	}

	public function test_the_carrier_filter_reaches_every_query(): void {
		$query = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->with( Mockery::any(), Mockery::any(), 'ozon' )->once()->andReturn( [] );
		$query->shouldReceive( 'day_rows' )->with( Mockery::any(), Mockery::any(), 'ozon' )->once()->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->with( Mockery::any(), Mockery::any(), 'ozon' )->once()->andReturn( [] );

		$report = $this->report( $query )->build( $this->request( [ 'carrier' => 'ozon' ] ) );

		$this->assertSame( [], $report['carriers'] );
	}

	public function test_methods_are_labelled_and_sorted_busiest_first(): void {
		$query = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->andReturn( [] );
		$query->shouldReceive( 'day_rows' )->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->andReturn(
			[
				[ 'carrier' => 'cdek', 'method_id' => 'cdek_pickup', 'orders' => '2', 'line_count' => '2', 'revenue' => '10000.5', 'shipping_paid' => '200.456' ],
				[ 'carrier' => 'cdek', 'method_id' => 'cdek_door', 'orders' => '9', 'line_count' => '10', 'revenue' => '30000', 'shipping_paid' => '900' ],
			]
		);

		$methods = $this->report( $query )->build( $this->request() )['methods'];

		$this->assertSame( [ 'cdek_door', 'cdek_pickup' ], array_column( $methods, 'method_id' ) );
		$this->assertSame( 'Метод cdek_door', $methods[0]['label'] );
		$this->assertSame( 10, $methods[0]['lines'] );
		$this->assertSame( 200.46, $methods[1]['shipping_paid'] );
		$this->assertSame( 30000.0, $methods[0]['revenue'] );
		$this->assertSame( 10000.5, $methods[1]['revenue'] );
	}

	public function test_the_interval_is_picked_from_the_range_and_the_range_is_echoed(): void {
		$query = Mockery::mock( Analytics_Query::class );
		$query->shouldReceive( 'carrier_rows' )->andReturn( [] );
		$query->shouldReceive( 'day_rows' )->andReturn( [] );
		$query->shouldReceive( 'method_rows' )->andReturn( [] );

		$report = $this->report( $query )->build( $this->request( [ 'before' => '2026-12-31' ] ) );

		$this->assertSame( [ 'after' => '2026-10-01', 'before' => '2026-12-31', 'interval' => 'week' ], $report['range'] );
		$this->assertSame( '2026-10-01', $report['intervals'][0]['start'] );
	}
}
