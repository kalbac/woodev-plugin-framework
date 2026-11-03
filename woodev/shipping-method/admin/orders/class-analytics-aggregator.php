<?php
/**
 * Delivery analytics — pure arithmetic over the aggregate rows
 *
 * @since 2.0.24
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Analytics_Aggregator' ) ) :

	/**
	 * Turns the rows {@see Analytics_Query} reads into the numbers the «Аналитика» tab shows (#711).
	 *
	 * Everything here is arithmetic over AGGREGATE rows — one per carrier, one per carrier and day — never
	 * over orders, and it touches neither the database nor WordPress, so the whole class is unit-testable.
	 * The definitions it implements are the spec's §2: the shares use `resolved` (delivered + returned +
	 * failed) as the denominator, a ratio over nothing is `null`, never `0`, and a change against a zero or
	 * missing previous value is `null` too (a percentage of nothing is not a number).
	 *
	 * @since 2.0.24
	 */
	final class Analytics_Aggregator {

		/**
		 * Seconds in a calendar day (a constant of ours: WordPress' `DAY_IN_SECONDS` is not loaded in unit tests).
		 *
		 * @var int
		 */
		private const SECONDS_PER_DAY = 86400;

		/**
		 * The delivery-outcome buckets a row counts, in display order.
		 *
		 * @var string[]
		 */
		public const BUCKETS = [ 'in_progress', 'delivered', 'returned', 'failed', 'cancelled', 'unknown' ];

		/**
		 * The metrics a change against the previous period is reported for.
		 *
		 * @var string[]
		 */
		private const DELTA_KEYS = [ 'orders', 'revenue', 'shipping_paid', 'avg_order', 'avg_shipping', 'delivered_share', 'returned_share', 'avg_days_to_delivery' ];

		/**
		 * The numeric columns a carrier row carries (everything {@see self::sum_rows()} adds up).
		 *
		 * @var string[]
		 */
		private const ROW_KEYS = [ 'orders', 'revenue', 'shipping_paid', 'in_progress', 'delivered', 'returned', 'failed', 'cancelled', 'unknown', 'time_sample', 'time_seconds' ];

		/**
		 * A carrier row with nothing in it.
		 *
		 * @since 2.0.24
		 *
		 * @return array<string,int|float>
		 */
		public static function empty_row(): array {
			return array_fill_keys( self::ROW_KEYS, 0 );
		}

		/**
		 * Casts one raw database row (strings) to the numeric carrier row, ignoring anything it does not know.
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,mixed> $raw row as the driver returned it.
		 * @return array<string,int|float>
		 */
		public static function normalize_row( array $raw ): array {
			$row = self::empty_row();

			foreach ( self::ROW_KEYS as $key ) {
				if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
					$row[ $key ] = in_array( $key, [ 'revenue', 'shipping_paid' ], true ) ? (float) $raw[ $key ] : (int) $raw[ $key ];
				}
			}

			return $row;
		}

		/**
		 * Casts one raw per-day row: its `carrier` and `day` kept, every count and sum cast like {@see self::normalize_row()}.
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,mixed> $raw row as the driver returned it.
		 * @return array<string,int|float|string>
		 */
		public static function normalize_day_row( array $raw ): array {
			return [
				'carrier' => (string) ( $raw['carrier'] ?? '' ),
				'day'     => (string) ( $raw['day'] ?? '' ),
			] + self::normalize_row( $raw );
		}

		/**
		 * The carrier rows: the per-day rows added up per carrier. The query makes ONE pass over the orders, at the grain of a carrier and
		 * a day; the carrier table and the totals are those rows summed here (a few hundred aggregate rows, never orders).
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array<string,int|float|string>> $day_rows normalized per-day rows.
		 * @return array<string,array<string,int|float>> carrier id => row.
		 */
		public static function carrier_rows( array $day_rows ): array {
			$rows = [];

			foreach ( $day_rows as $day_row ) {
				$carrier          = (string) $day_row['carrier'];
				$rows[ $carrier ] = self::sum_rows( [ $rows[ $carrier ] ?? self::empty_row(), $day_row ] );
			}

			return $rows;
		}

		/**
		 * Adds carrier rows together — the totals row. Orders are counted once per carrier by the query, so the
		 * rows are additive.
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array<string,int|float>> $rows normalized carrier rows.
		 * @return array<string,int|float>
		 */
		public static function sum_rows( array $rows ): array {
			$sum = self::empty_row();

			foreach ( $rows as $row ) {
				foreach ( self::ROW_KEYS as $key ) {
					$sum[ $key ] += $row[ $key ] ?? 0;
				}
			}

			return $sum;
		}

		/**
		 * The metrics of one carrier row (or of the totals row).
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,int|float> $row normalized row.
		 * @return array<string,mixed> the `Metrics` object of the REST contract.
		 */
		public static function metrics( array $row ): array {
			$orders   = (int) $row['orders'];
			$resolved = (int) ( $row['delivered'] + $row['returned'] + $row['failed'] );
			$days     = $row['time_sample'] > 0 ? $row['time_seconds'] / $row['time_sample'] / self::SECONDS_PER_DAY : null;

			return [
				'orders'               => $orders,
				'revenue'              => self::money( $row['revenue'] ),
				'shipping_paid'        => self::money( $row['shipping_paid'] ),
				'avg_order'            => self::ratio( $row['revenue'], $orders ),
				'avg_shipping'         => self::ratio( $row['shipping_paid'], $orders ),
				'shipping_share'       => self::ratio( $row['shipping_paid'], $row['revenue'], 4 ),
				'outcomes'             => self::outcomes( $row ),
				'resolved'             => $resolved,
				'delivered_share'      => self::ratio( $row['delivered'], $resolved, 4 ),
				'returned_share'       => self::ratio( $row['returned'], $resolved, 4 ),
				'failed_share'         => self::ratio( $row['failed'], $resolved, 4 ),
				'avg_days_to_delivery' => null === $days ? null : round( $days, 1 ),
				'time_sample'          => (int) $row['time_sample'],
			];
		}

		/**
		 * Percent change of every delta metric, current against previous.
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,mixed>      $current  metrics of the current period.
		 * @param array<string,mixed>|null $previous metrics of the previous period, or null.
		 * @return array<string,float|null> metric => percent; null when there is nothing to compare with.
		 */
		public static function deltas( array $current, ?array $previous ): array {
			$deltas = [];

			foreach ( self::DELTA_KEYS as $key ) {
				$deltas[ $key ] = null === $previous ? null : self::percent_change( $current[ $key ] ?? null, $previous[ $key ] ?? null );
			}

			return $deltas;
		}

		/**
		 * Picks the chart interval: the requested one, or — for `auto` — day up to a month, week up to six months, else month.
		 *
		 * @since 2.0.24
		 *
		 * @param string $requested `day`, `week`, `month` or `auto`.
		 * @param int    $days      number of calendar days in the range.
		 * @return string `day`, `week` or `month`.
		 */
		public static function pick_interval( string $requested, int $days ): string {
			if ( in_array( $requested, [ 'day', 'week', 'month' ], true ) ) {
				return $requested;
			}

			if ( $days <= 31 ) {
				return 'day';
			}

			return $days <= 180 ? 'week' : 'month';
		}

		/**
		 * The number of calendar days from `$after` to `$before`, both inclusive.
		 *
		 * @since 2.0.24
		 *
		 * @param string $after  `Y-m-d`.
		 * @param string $before `Y-m-d`.
		 * @return int
		 */
		public static function days_in_range( string $after, string $before ): int {
			$from = new \DateTimeImmutable( $after . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			$to   = new \DateTimeImmutable( $before . ' 00:00:00', new \DateTimeZone( 'UTC' ) );

			return (int) $from->diff( $to )->format( '%a' ) + 1;
		}

		/**
		 * Rolls the per-day rows up into chart intervals, zero-filling the empty ones.
		 *
		 * A loop over DAYS of the range and over aggregate rows — never over orders. A row whose day falls
		 * just outside the range (the fixed site offset of the day bucketing, see {@see Analytics_Query})
		 * is clamped to the nearest edge so the series still add up to the carrier totals.
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array<string,mixed>> $day_rows   normalized per-day rows ({@see self::normalize_day_row()}).
		 * @param string                         $after      first day, `Y-m-d`.
		 * @param string                         $before     last day, `Y-m-d`.
		 * @param string                         $interval   `day`, `week` or `month`.
		 * @param int                            $week_start day the week starts on, 0 (Sunday) to 6.
		 * @return array<int,array<string,mixed>> `[ start, end, totals, carriers ]` per interval, oldest first.
		 */
		public static function intervals( array $day_rows, string $after, string $before, string $interval, int $week_start ): array {
			$buckets = self::empty_buckets( $after, $before, $interval, $week_start );

			foreach ( $day_rows as $row ) {
				$day = min( max( (string) $row['day'], $after ), $before );
				$key = self::bucket_start( $day, $interval, $week_start );

				if ( ! isset( $buckets[ $key ] ) ) {
					continue;
				}

				$buckets[ $key ]['totals']                                = self::add_series( $buckets[ $key ]['totals'], $row );
				$carrier                                                  = (string) $row['carrier'];
				$buckets[ $key ]['carriers'][ $carrier ]                  = self::add_series( $buckets[ $key ]['carriers'][ $carrier ] ?? self::empty_series(), $row );
			}

			return array_values( array_map( [ self::class, 'round_bucket' ], $buckets ) );
		}

		/**
		 * One chart point's zero value.
		 *
		 * @since 2.0.24
		 *
		 * @return array<string,int|float>
		 */
		public static function empty_series(): array {
			return [
				'orders'        => 0,
				'revenue'       => 0.0,
				'shipping_paid' => 0.0,
				'delivered'     => 0,
				'resolved'      => 0,
			];
		}

		/**
		 * The outcome counts of a row, in {@see self::BUCKETS} order.
		 *
		 * @param array<string,int|float> $row normalized row.
		 * @return array<string,int>
		 */
		private static function outcomes( array $row ): array {
			$outcomes = [];

			foreach ( self::BUCKETS as $bucket ) {
				$outcomes[ $bucket ] = (int) $row[ $bucket ];
			}

			return $outcomes;
		}

		/**
		 * Percent change, or null when either side is missing or the previous one is zero.
		 *
		 * @param int|float|null $current  current value.
		 * @param int|float|null $previous previous value.
		 * @return float|null
		 */
		private static function percent_change( $current, $previous ): ?float {
			if ( null === $current || null === $previous || 0.0 === (float) $previous ) {
				return null;
			}

			return round( ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100, 1 );
		}

		/**
		 * `$numerator / $denominator` rounded, or null over nothing.
		 *
		 * @param int|float $numerator   numerator.
		 * @param int|float $denominator denominator.
		 * @param int       $precision   decimals.
		 * @return float|null
		 */
		private static function ratio( $numerator, $denominator, int $precision = 2 ): ?float {
			return $denominator > 0 ? round( $numerator / $denominator, $precision ) : null;
		}

		/**
		 * A money amount as the JSON carries it: two decimals.
		 *
		 * @param int|float $amount amount.
		 * @return float
		 */
		private static function money( $amount ): float {
			return round( (float) $amount, 2 );
		}

		/**
		 * Every interval of the range with a zero series, keyed by its first calendar day.
		 *
		 * @param string $after      first day.
		 * @param string $before     last day.
		 * @param string $interval   `day`, `week` or `month`.
		 * @param int    $week_start day the week starts on.
		 * @return array<string,array<string,mixed>>
		 */
		private static function empty_buckets( string $after, string $before, string $interval, int $week_start ): array {
			$buckets = [];
			$day     = new \DateTimeImmutable( $after . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			$last    = new \DateTimeImmutable( $before . ' 00:00:00', new \DateTimeZone( 'UTC' ) );

			while ( $day <= $last ) {
				$key = self::bucket_start( $day->format( 'Y-m-d' ), $interval, $week_start );

				if ( ! isset( $buckets[ $key ] ) ) {
					$buckets[ $key ] = [
						'start'    => max( $key, $after ),
						'end'      => $day->format( 'Y-m-d' ),
						'totals'   => self::empty_series(),
						'carriers' => [],
					];
				}

				$buckets[ $key ]['end'] = $day->format( 'Y-m-d' );
				$day                    = $day->modify( '+1 day' );
			}

			return $buckets;
		}

		/**
		 * The first calendar day of the interval a day belongs to.
		 *
		 * @param string $day        `Y-m-d`.
		 * @param string $interval   `day`, `week` or `month`.
		 * @param int    $week_start day the week starts on.
		 * @return string `Y-m-d`.
		 */
		private static function bucket_start( string $day, string $interval, int $week_start ): string {
			$date = new \DateTimeImmutable( $day . ' 00:00:00', new \DateTimeZone( 'UTC' ) );

			if ( 'month' === $interval ) {
				return $date->format( 'Y-m-01' );
			}

			if ( 'week' === $interval ) {
				$back = ( (int) $date->format( 'w' ) - $week_start + 7 ) % 7;

				return $date->modify( '-' . $back . ' days' )->format( 'Y-m-d' );
			}

			return $day;
		}

		/**
		 * Adds one day row to a chart series.
		 *
		 * @param array<string,int|float> $series series so far.
		 * @param array<string,mixed>     $row    day row.
		 * @return array<string,int|float>
		 */
		private static function add_series( array $series, array $row ): array {
			$series['orders']        += (int) $row['orders'];
			$series['revenue']       += (float) $row['revenue'];
			$series['shipping_paid'] += (float) $row['shipping_paid'];
			$series['delivered']     += (int) $row['delivered'];
			$series['resolved']      += (int) ( $row['delivered'] + $row['returned'] + $row['failed'] );

			return $series;
		}

		/**
		 * Rounds the money of one interval for the JSON.
		 *
		 * @param array<string,mixed> $bucket interval.
		 * @return array<string,mixed>
		 */
		private static function round_bucket( array $bucket ): array {
			$bucket['totals'] = self::round_series( $bucket['totals'] );

			foreach ( $bucket['carriers'] as $carrier => $series ) {
				$bucket['carriers'][ $carrier ] = self::round_series( $series );
			}

			return $bucket;
		}

		/**
		 * Rounds the money of one series.
		 *
		 * @param array<string,int|float> $series series.
		 * @return array<string,int|float>
		 */
		private static function round_series( array $series ): array {
			$series['revenue']       = self::money( $series['revenue'] );
			$series['shipping_paid'] = self::money( $series['shipping_paid'] );

			return $series;
		}
	}

endif;
