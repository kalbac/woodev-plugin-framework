<?php
/**
 * Delivery analytics — the report the REST route answers with
 *
 * @since 2.0.24
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Analytics_Report' ) ) :

	/**
	 * Builds the whole answer of `GET /shipping/orders/analytics` (#711) from {@see Analytics_Query} rows and
	 * {@see Analytics_Aggregator} arithmetic: the carrier table, the totals, the shipping-method table, the chart
	 * intervals and the comparison with the previous period.
	 *
	 * It owns the part the query and the arithmetic do not: turning the merchant's calendar days into the
	 * half-open GMT window (store timezone, both days inclusive) and joining the two periods per carrier. The
	 * request has been validated by {@see \Woodev\Framework\Shipping\Rest_Api\Analytics_Controller} before it gets here.
	 *
	 * @since 2.0.24
	 */
	final class Analytics_Report {
		/**
		 * Reads the aggregates.
		 *
		 * @var Analytics_Query
		 */
		private $query;

		/**
		 * Registered providers, in registry order.
		 *
		 * @var Orders_Provider[]
		 */
		private $providers;

		/**
		 * Resolves a shipping method id to the label the merchant reads.
		 *
		 * @var callable
		 */
		private $method_label;

		/**
		 * Constructor.
		 *
		 * @since 2.0.24
		 *
		 * @param Analytics_Query   $query        aggregate reader.
		 * @param Orders_Provider[] $providers    registered providers, in registry order.
		 * @param callable          $method_label `fn( string $method_id ): string`.
		 */
		public function __construct( Analytics_Query $query, array $providers, callable $method_label ) {
			$this->query        = $query;
			$this->providers    = $providers;
			$this->method_label = $method_label;
		}

		/**
		 * Builds the report.
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,mixed> $request {
		 *     The validated request.
		 *
		 *     @type string            $after          first day, `Y-m-d`.
		 *     @type string            $before         last day, `Y-m-d`.
		 *     @type string|null       $compare_after  previous period's first day, or null.
		 *     @type string|null       $compare_before previous period's last day, or null.
		 *     @type string            $interval       `day`, `week`, `month` or `auto`.
		 *     @type string|null       $carrier        one provider id, or null for all.
		 *     @type int               $week_start     day the week starts on, 0–6.
		 *     @type string[]          $excluded       order statuses outside the population.
		 *     @type \DateTimeZone     $timezone       the store timezone.
		 * }
		 * @return array<string,mixed> everything of the REST response except `currency`, `generated_at` and `cached`.
		 */
		public function build( array $request ): array {
			$interval = Analytics_Aggregator::pick_interval( (string) $request['interval'], Analytics_Aggregator::days_in_range( $request['after'], $request['before'] ) );
			$current  = $this->read_period( $request['after'], $request['before'], $request, true );
			$previous = $this->has_compare( $request ) ? $this->read_period( $request['compare_after'], $request['compare_before'], $request, false ) : null;

			return [
				'range'              => [
					'after'    => $request['after'],
					'before'   => $request['before'],
					'interval' => $interval,
				],
				'compare'            => null === $previous ? null : [
					'after'  => $request['compare_after'],
					'before' => $request['compare_before'],
				],
				'totals'             => $this->totals( $current, $previous ),
				'carriers'           => $this->carriers( $current, $previous ),
				'methods'            => $this->methods( $current['methods'] ),
				'intervals'          => Analytics_Aggregator::intervals( $current['days'], $request['after'], $request['before'], $interval, (int) $request['week_start'] ),
				'previous_intervals' => null === $previous ? null : Analytics_Aggregator::intervals( $previous['days'], $request['compare_after'], $request['compare_before'], $interval, (int) $request['week_start'] ),
				'coverage'           => $this->coverage( $current ),
			];
		}

		/**
		 * Whether the request carries a previous period.
		 *
		 * @param array<string,mixed> $request the request.
		 * @return bool
		 */
		private function has_compare( array $request ): bool {
			return ! empty( $request['compare_after'] ) && ! empty( $request['compare_before'] );
		}

		/**
		 * Reads one period: its carrier rows (by carrier id), day rows and — for the current period only — shipping-method rows.
		 *
		 * @param string              $after        first day.
		 * @param string              $before       last day.
		 * @param array<string,mixed> $request      the request.
		 * @param bool                $with_methods whether to read the shipping-method table (the previous period has none).
		 * @return array{carriers:array<string,array<string,int|float>>,days:array<int,array<string,mixed>>,methods:array<int,array<string,mixed>>}
		 */
		private function read_period( string $after, string $before, array $request, bool $with_methods ): array {
			$scope = $this->scope( $after, $before, $request );
			$only  = $request['carrier'] ?? null;

			$carriers = [];
			foreach ( $this->query->carrier_rows( $this->providers, $scope, $only ) as $raw ) {
				$carriers[ (string) $raw['carrier'] ] = Analytics_Aggregator::normalize_row( $raw );
			}

			return [
				'carriers' => $carriers,
				'days'     => $this->query->day_rows( $this->providers, $scope, $only ),
				'methods'  => $with_methods ? $this->query->method_rows( $this->providers, $scope, $only ) : [],
			];
		}

		/**
		 * The half-open GMT window of two store-timezone calendar days, both inclusive.
		 *
		 * The day bucketing uses ONE fixed UTC offset for the whole window (the offset at its midpoint): an order within
		 * an hour of midnight on a daylight-saving change can land on the neighbouring day (spec §8 F5).
		 *
		 * @param string              $after   first day.
		 * @param string              $before  last day.
		 * @param array<string,mixed> $request the request.
		 * @return array{from:string,to:string,offset:int,excluded:string[]}
		 */
		private function scope( string $after, string $before, array $request ): array {
			$zone  = $request['timezone'];
			$utc   = new \DateTimeZone( 'UTC' );
			$start = new \DateTimeImmutable( $after . ' 00:00:00', $zone );
			$end   = ( new \DateTimeImmutable( $before . ' 00:00:00', $zone ) )->modify( '+1 day' );
			$mid   = $start->setTimestamp( (int) ( ( $start->getTimestamp() + $end->getTimestamp() ) / 2 ) );

			return [
				'from'     => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'to'       => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'offset'   => $zone->getOffset( $mid ),
				'excluded' => (array) $request['excluded'],
			];
		}

		/**
		 * The totals: the carriers added up, with the previous period and the change.
		 *
		 * @param array<string,mixed>      $current  current period.
		 * @param array<string,mixed>|null $previous previous period, or null.
		 * @return array<string,mixed>
		 */
		private function totals( array $current, ?array $previous ): array {
			$now  = Analytics_Aggregator::metrics( Analytics_Aggregator::sum_rows( $current['carriers'] ) );
			$prev = null === $previous ? null : Analytics_Aggregator::metrics( Analytics_Aggregator::sum_rows( $previous['carriers'] ) );

			return [
				'current'  => $now,
				'previous' => $prev,
				'delta'    => Analytics_Aggregator::deltas( $now, $prev ),
			];
		}

		/**
		 * The carrier table, in registry order; a carrier with no orders in either period is left out.
		 *
		 * @param array<string,mixed>      $current  current period.
		 * @param array<string,mixed>|null $previous previous period, or null.
		 * @return array<int,array<string,mixed>>
		 */
		private function carriers( array $current, ?array $previous ): array {
			$rows = [];

			foreach ( $this->providers as $provider ) {
				$id   = $provider->get_id();
				$now  = $current['carriers'][ $id ] ?? null;
				$prev = $previous['carriers'][ $id ] ?? null;

				if ( ( null === $now || ! $now['orders'] ) && ( null === $prev || ! $prev['orders'] ) ) {
					continue;
				}

				$now_metrics  = Analytics_Aggregator::metrics( $now ?? Analytics_Aggregator::empty_row() );
				$prev_metrics = null === $previous ? null : Analytics_Aggregator::metrics( $prev ?? Analytics_Aggregator::empty_row() );

				$rows[] = [
					'id'       => $id,
					'label'    => $provider->get_label(),
					'current'  => $now_metrics,
					'previous' => $prev_metrics,
					'delta'    => Analytics_Aggregator::deltas( $now_metrics, $prev_metrics ),
				];
			}

			return $rows;
		}

		/**
		 * The shipping-method table: the query rows with their labels, busiest first.
		 *
		 * @param array<int,array<string,mixed>> $rows raw method rows.
		 * @return array<int,array<string,mixed>>
		 */
		private function methods( array $rows ): array {
			$methods = [];

			foreach ( $rows as $row ) {
				$method_id = (string) $row['method_id'];

				$methods[] = [
					'method_id'     => $method_id,
					'carrier_id'    => (string) $row['carrier'],
					'label'         => (string) call_user_func( $this->method_label, $method_id ),
					'orders'        => (int) $row['orders'],
					'lines'         => (int) $row['line_count'],
					'shipping_paid' => round( (float) $row['shipping_paid'], 2 ),
				];
			}

			usort(
				$methods,
				static function ( array $a, array $b ): int {
					return $b['orders'] <=> $a['orders'];
				}
			);

			return $methods;
		}

		/**
		 * How many delivered orders there are and how many of them the time metric could use.
		 *
		 * @param array<string,mixed> $current current period.
		 * @return array{delivered:int,time_sample:int}
		 */
		private function coverage( array $current ): array {
			$sum = Analytics_Aggregator::sum_rows( $current['carriers'] );

			return [
				'delivered'   => (int) $sum['delivered'],
				'time_sample' => (int) $sum['time_sample'],
			];
		}
	}

endif;
