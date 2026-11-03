<?php
/**
 * Unit: the arithmetic behind the «Аналитика» tab (#711, spec §2 and §4).
 *
 * Pure PHP over aggregate rows: the outcome shares and their `resolved` denominator, null-safety of every ratio
 * and change, the interval choice, and the day → week / month roll-up with its zero fill and edge clamping.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Woodev\Framework\Shipping\Admin\Orders\Analytics_Aggregator;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Analytics_Aggregator
 */
final class AnalyticsAggregatorTest extends TestCase {

	private function row( array $overrides = [] ): array {
		return Analytics_Aggregator::normalize_row(
			$overrides + [
				'orders'        => '10',
				'revenue'       => '10000.50',
				'shipping_paid' => '1500',
				'delivered'     => '6',
				'returned'      => '2',
				'failed'        => '2',
				'in_progress'   => '0',
			]
		);
	}

	public function test_normalize_row_casts_strings_and_ignores_unknown_columns(): void {
		$row = Analytics_Aggregator::normalize_row( [ 'orders' => '3', 'revenue' => '12.5', 'carrier' => 'cdek', 'junk' => 'x' ] );

		$this->assertSame( 3, $row['orders'] );
		$this->assertSame( 12.5, $row['revenue'] );
		$this->assertArrayNotHasKey( 'carrier', $row );
		$this->assertSame( 0, $row['delivered'] );
	}

	public function test_shares_use_resolved_as_the_denominator(): void {
		$metrics = Analytics_Aggregator::metrics( $this->row( [ 'in_progress' => '5', 'cancelled' => '3', 'unknown' => '4' ] ) );

		$this->assertSame( 10, $metrics['resolved'] );
		$this->assertSame( 0.6, $metrics['delivered_share'] );
		$this->assertSame( 0.2, $metrics['returned_share'] );
		$this->assertSame( 0.2, $metrics['failed_share'] );
		$this->assertSame( 5, $metrics['outcomes']['in_progress'] );
		$this->assertSame( [ 'in_progress', 'delivered', 'returned', 'failed', 'cancelled', 'unknown' ], array_keys( $metrics['outcomes'] ) );
	}

	public function test_money_metrics_divide_by_orders_and_revenue(): void {
		$metrics = Analytics_Aggregator::metrics( $this->row() );

		$this->assertSame( 10000.5, $metrics['revenue'] );
		$this->assertSame( 1000.05, $metrics['avg_order'] );
		$this->assertSame( 150.0, $metrics['avg_shipping'] );
		$this->assertSame( 0.15, $metrics['shipping_share'] );
	}

	public function test_ratios_over_nothing_are_null_never_zero(): void {
		$metrics = Analytics_Aggregator::metrics( Analytics_Aggregator::empty_row() );

		foreach ( [ 'avg_order', 'avg_shipping', 'shipping_share', 'delivered_share', 'returned_share', 'failed_share', 'avg_days_to_delivery' ] as $key ) {
			$this->assertNull( $metrics[ $key ], $key );
		}
		$this->assertSame( 0, $metrics['orders'] );
		$this->assertSame( 0, $metrics['resolved'] );
	}

	public function test_time_metric_averages_the_sample_in_days(): void {
		$metrics = Analytics_Aggregator::metrics( $this->row( [ 'time_sample' => '2', 'time_seconds' => (string) ( 3 * 86400 + 5 * 86400 ) ] ) );

		$this->assertSame( 4.0, $metrics['avg_days_to_delivery'] );
		$this->assertSame( 2, $metrics['time_sample'] );
	}

	public function test_the_exact_handover_metric_averages_its_own_sample_apart_from_the_substitute(): void {
		$metrics = Analytics_Aggregator::metrics(
			$this->row( [ 'time_sample' => '4', 'time_seconds' => (string) ( 4 * 10 * 86400 ), 'handover_sample' => '2', 'handover_seconds' => (string) ( 2 * 3 * 86400 + 86400 ) ] )
		);

		$this->assertSame( 10.0, $metrics['avg_days_to_delivery'] );
		$this->assertSame( 3.5, $metrics['avg_days_handover_to_delivery'] );
		$this->assertSame( 2, $metrics['handover_sample'] );
	}

	public function test_the_exact_handover_metric_is_null_without_a_sample(): void {
		$metrics = Analytics_Aggregator::metrics( $this->row() );

		$this->assertNull( $metrics['avg_days_handover_to_delivery'] );
		$this->assertSame( 0, $metrics['handover_sample'] );
	}

	public function test_sum_rows_adds_every_column(): void {
		$sum = Analytics_Aggregator::sum_rows( [ $this->row(), $this->row( [ 'orders' => '5', 'revenue' => '100' ] ) ] );

		$this->assertSame( 15, $sum['orders'] );
		$this->assertSame( 10100.5, $sum['revenue'] );
		$this->assertSame( 12, $sum['delivered'] );
	}

	public function test_deltas_are_percent_changes_and_null_without_a_usable_previous(): void {
		$now  = Analytics_Aggregator::metrics( $this->row( [ 'orders' => '12' ] ) );
		$prev = Analytics_Aggregator::metrics( $this->row( [ 'orders' => '10' ] ) );

		$this->assertSame( 20.0, Analytics_Aggregator::deltas( $now, $prev )['orders'] );
		$this->assertNull( Analytics_Aggregator::deltas( $now, null )['orders'] );

		$zero = Analytics_Aggregator::metrics( Analytics_Aggregator::empty_row() );
		$this->assertNull( Analytics_Aggregator::deltas( $now, $zero )['orders'], 'a change against zero is not a number' );
		$this->assertNull( Analytics_Aggregator::deltas( $now, $zero )['avg_days_to_delivery'] );
	}

	/**
	 * @dataProvider interval_cases
	 */
	public function test_pick_interval( string $requested, int $days, string $expected ): void {
		$this->assertSame( $expected, Analytics_Aggregator::pick_interval( $requested, $days ) );
	}

	public static function interval_cases(): array {
		return [
			'auto, one month'       => [ 'auto', 31, 'day' ],
			'auto, over a month'    => [ 'auto', 32, 'week' ],
			'auto, six months'      => [ 'auto', 180, 'week' ],
			'auto, over six months' => [ 'auto', 181, 'month' ],
			'explicit wins'         => [ 'month', 3, 'month' ],
			'junk falls to auto'    => [ 'hour', 10, 'day' ],
		];
	}

	public function test_days_in_range_is_inclusive(): void {
		$this->assertSame( 1, Analytics_Aggregator::days_in_range( '2026-10-05', '2026-10-05' ) );
		$this->assertSame( 31, Analytics_Aggregator::days_in_range( '2026-10-01', '2026-10-31' ) );
	}

	private function day( string $carrier, string $day, int $orders, float $revenue = 0.0 ): array {
		return [
			'carrier'       => $carrier,
			'day'           => $day,
			'orders'        => $orders,
			'revenue'       => $revenue,
			'shipping_paid' => 0.0,
			'delivered'     => 0,
			'returned'      => 0,
			'failed'        => 0,
		];
	}

	public function test_day_intervals_zero_fill_the_whole_range(): void {
		$intervals = Analytics_Aggregator::intervals( [ $this->day( 'cdek', '2026-10-02', 3 ) ], '2026-10-01', '2026-10-03', 'day', 1 );

		$this->assertCount( 3, $intervals );
		$this->assertSame( [ 0, 3, 0 ], array_column( array_column( $intervals, 'totals' ), 'orders' ) );
		$this->assertSame( [], $intervals[0]['carriers'] );
		$this->assertSame( 3, $intervals[1]['carriers']['cdek']['orders'] );
	}

	public function test_weeks_start_on_the_configured_day_and_clip_to_the_range(): void {
		// 2026-10-07 is a Wednesday. Monday-based weeks: Sep 28 - Oct 4, Oct 5 - Oct 11.
		$rows      = [ $this->day( 'cdek', '2026-10-01', 1 ), $this->day( 'cdek', '2026-10-06', 2 ), $this->day( 'yandex', '2026-10-07', 4 ) ];
		$intervals = Analytics_Aggregator::intervals( $rows, '2026-10-01', '2026-10-09', 'week', 1 );

		$this->assertSame( [ '2026-10-01', '2026-10-05' ], array_column( $intervals, 'start' ) );
		$this->assertSame( [ '2026-10-04', '2026-10-09' ], array_column( $intervals, 'end' ) );
		$this->assertSame( 1, $intervals[0]['totals']['orders'] );
		$this->assertSame( 6, $intervals[1]['totals']['orders'] );
		$this->assertSame( 2, $intervals[1]['carriers']['cdek']['orders'] );
		$this->assertSame( 4, $intervals[1]['carriers']['yandex']['orders'] );

		$sunday = Analytics_Aggregator::intervals( $rows, '2026-10-01', '2026-10-09', 'week', 0 );
		$this->assertSame( [ '2026-10-01', '2026-10-04' ], array_column( $sunday, 'start' ), 'Sunday-based weeks split differently' );
	}

	public function test_months_roll_up_calendar_months(): void {
		$rows      = [ $this->day( 'cdek', '2026-09-30', 1, 10.0 ), $this->day( 'cdek', '2026-10-01', 2, 20.555 ) ];
		$intervals = Analytics_Aggregator::intervals( $rows, '2026-09-15', '2026-10-20', 'month', 1 );

		$this->assertSame( [ '2026-09-15', '2026-10-01' ], array_column( $intervals, 'start' ) );
		$this->assertSame( [ '2026-09-30', '2026-10-20' ], array_column( $intervals, 'end' ) );
		$this->assertSame( 20.56, $intervals[1]['totals']['revenue'] );
	}

	public function test_a_day_just_outside_the_range_is_clamped_so_totals_still_add_up(): void {
		$rows      = [ $this->day( 'cdek', '2026-09-30', 2 ), $this->day( 'cdek', '2026-10-04', 5 ) ];
		$intervals = Analytics_Aggregator::intervals( $rows, '2026-10-01', '2026-10-03', 'day', 1 );

		$this->assertSame( 2, $intervals[0]['totals']['orders'] );
		$this->assertSame( 5, $intervals[2]['totals']['orders'] );
		$this->assertSame( 7, array_sum( array_column( array_column( $intervals, 'totals' ), 'orders' ) ) );
	}

	public function test_the_carrier_rows_are_the_per_day_rows_added_up(): void {
		$days = array_map(
			[ Analytics_Aggregator::class, 'normalize_day_row' ],
			[
				[ 'carrier' => 'cdek', 'day' => '2026-10-01', 'orders' => '3', 'revenue' => '300.5', 'delivered' => '1', 'time_sample' => '1', 'time_seconds' => '86400' ],
				[ 'carrier' => 'yandex', 'day' => '2026-10-01', 'orders' => '2', 'revenue' => '200', 'returned' => '2' ],
				[ 'carrier' => 'cdek', 'day' => '2026-10-02', 'orders' => '4', 'revenue' => '400', 'delivered' => '2', 'failed' => '1', 'time_sample' => '1', 'time_seconds' => '259200' ],
			]
		);

		$carriers = Analytics_Aggregator::carrier_rows( $days );

		$this->assertSame( [ 'cdek', 'yandex' ], array_keys( $carriers ) );
		$this->assertSame( 7, $carriers['cdek']['orders'] );
		$this->assertSame( 700.5, $carriers['cdek']['revenue'] );
		$this->assertSame( 3, $carriers['cdek']['delivered'] );
		$this->assertSame( 2, $carriers['cdek']['time_sample'] );
		$this->assertSame( 345600, $carriers['cdek']['time_seconds'] );
		$this->assertSame( 2, $carriers['yandex']['returned'] );
		$this->assertSame( [], Analytics_Aggregator::carrier_rows( [] ) );
	}

	public function test_a_day_row_keeps_its_carrier_and_day_and_casts_the_rest(): void {
		$row = Analytics_Aggregator::normalize_day_row( [ 'carrier' => 'cdek', 'day' => '2026-10-01', 'orders' => '3', 'revenue' => '12.5', 'junk' => 'x' ] );

		$this->assertSame( 'cdek', $row['carrier'] );
		$this->assertSame( '2026-10-01', $row['day'] );
		$this->assertSame( 3, $row['orders'] );
		$this->assertSame( 12.5, $row['revenue'] );
		$this->assertArrayNotHasKey( 'junk', $row );
	}

	public function test_a_chart_point_counts_resolved_as_delivered_returned_and_failed(): void {
		$row       = Analytics_Aggregator::normalize_day_row( [ 'carrier' => 'cdek', 'day' => '2026-10-01', 'orders' => '9', 'delivered' => '2', 'returned' => '1', 'failed' => '1', 'in_progress' => '5' ] );
		$intervals = Analytics_Aggregator::intervals( [ $row ], '2026-10-01', '2026-10-01', 'day', 1 );

		$this->assertSame( 4, $intervals[0]['totals']['resolved'] );
		$this->assertSame( 2, $intervals[0]['totals']['delivered'] );
	}
}
