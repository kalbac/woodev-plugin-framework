<?php
/**
 * The answer of the «Аналитика» tab of «Заказы маркетплейсов».
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Groups;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text;
use Woodev\Framework\Shipping\Admin\Orders\Analytics_Aggregator;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the aggregate rows of {@see Orders_Analytics_Query} into the figures the tab shows: the tiles with the previous period, the
 * chart by account, the table by account, the marketplace statuses now, the stuck postings, the products and the marketplace's
 * share of the shop's revenue. Arithmetic over aggregate rows only; the clock, the links and the names come in from outside.
 *
 * A ratio over nothing is `null`, never `0`, and so is a change against a zero or missing previous value.
 *
 * @since 2.1.13
 */
final class Orders_Analytics_Report {
	/**
	 * Hours a posting may wait for packing or shipping before it counts as stuck; filter `woodev_marketplace_stuck_posting_hours`.
	 */
	public const STUCK_HOURS = 24;

	/**
	 * Most products and unlinked items listed.
	 */
	public const TOP_PRODUCTS = 10;

	/**
	 * Metrics a change against the previous period is given for.
	 */
	private const DELTA_KEYS = [ 'orders', 'revenue', 'cancel_share', 'avg_order', 'units_per_order' ];

	/**
	 * @var Orders_Analytics_Query
	 */
	private $query;

	/**
	 * Account labels by id, in the order the filter offers them.
	 *
	 * @var array<int,string>
	 */
	private $accounts;

	/**
	 * @var Posting_Status_Names|null
	 */
	private $names;

	/**
	 * The marketplace's statuses of a posting that waits on the shop; null when the adapter does not classify its codes.
	 *
	 * @var string[]|null
	 */
	private $waiting;

	/**
	 * @var string
	 */
	private $created_via;

	/**
	 * @var callable
	 */
	private $order_url;

	/**
	 * @var callable
	 */
	private $product_url;

	/**
	 * Creates the report.
	 *
	 * @since 2.1.13
	 *
	 * @param Orders_Analytics_Query     $query       The reads.
	 * @param array<int,string>          $accounts    Account labels by id: the accounts the tab may show.
	 * @param Posting_Status_Names|null  $names       The adapter's names of its statuses.
	 * @param string                     $created_via The `created_via` of the marketplace's orders.
	 * @param callable                   $order_url   `function ( int $order_id ): string`, the order's edit link or '' when the user may not edit it.
	 * @param callable                   $product_url `function ( int $product_id ): string`, the product's edit link or ''.
	 * @param Posting_Status_Groups|null $groups      The adapter's waiting statuses; the framework's defaults without it.
	 */
	public function __construct( Orders_Analytics_Query $query, array $accounts, ?Posting_Status_Names $names, string $created_via, callable $order_url, callable $product_url, ?Posting_Status_Groups $groups = null ) {
		$this->query       = $query;
		$this->accounts    = $accounts;
		$this->names       = $names;
		$this->waiting     = Posting_Status_Sets::waiting( $groups );
		$this->created_via = $created_via;
		$this->order_url   = $order_url;
		$this->product_url = $product_url;
	}

	/**
	 * Builds the answer.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $request {
	 *     The validated request.
	 *
	 *     @type string        $after          First day, `Y-m-d`.
	 *     @type string        $before         Last day, `Y-m-d`.
	 *     @type string        $compare_after  First day of the previous period, or ''.
	 *     @type string        $compare_before Last day of the previous period, or ''.
	 *     @type string        $interval       `day`, `week`, `month` or `auto`.
	 *     @type int|null      $account        One account id, or null for every account.
	 *     @type int           $week_start     Day the week starts on, 0 (Sunday) to 6.
	 *     @type \DateTimeZone $timezone       The site's timezone.
	 *     @type int           $now            Unix time «now».
	 *     @type int           $stuck_hours    The stuck threshold in hours.
	 * }
	 * @return array<string,mixed>
	 */
	public function build( array $request ): array {
		$accounts = null === $request['account'] ? array_keys( $this->accounts ) : [ (int) $request['account'] ];
		$interval = Analytics_Aggregator::pick_interval( (string) $request['interval'], Analytics_Aggregator::days_in_range( $request['after'], $request['before'] ) );
		$current  = $this->read_period( $request['after'], $request['before'], $request, $accounts );
		$compare  = '' !== $request['compare_after'] && '' !== $request['compare_before'];
		$previous = $compare ? $this->read_period( $request['compare_after'], $request['compare_before'], $request, $accounts ) : null;

		return [
			'range'      => [
				'after'    => $request['after'],
				'before'   => $request['before'],
				'interval' => $interval,
			],
			'compare'    => $compare ? [
				'after'  => $request['compare_after'],
				'before' => $request['compare_before'],
			] : null,
			'account'    => null === $request['account'] ? 'all' : (string) $request['account'],
			'accounts'   => $this->account_list( $accounts ),
			'options'    => $this->account_list( array_keys( $this->accounts ) ),
			'totals'     => self::comparison( self::sum( $current['accounts'] ), null === $previous ? null : self::sum( $previous['accounts'] ) ),
			'by_account' => $this->by_account( $accounts, $current, $previous ),
			'intervals'  => self::intervals( $current['days'], $request['after'], $request['before'], $interval, (int) $request['week_start'] ),
			'share'      => self::share( $current['shop'], null === $previous ? null : $previous['shop'] ),
			'statuses'   => $this->statuses( $this->query->status_rows( $accounts ) ),
			// Whether the adapter classifies its codes: without it there is no stuck block and only cancelled orders are cancellations.
			'classified' => null !== $this->waiting,
			'stuck'      => null === $this->waiting ? null : $this->stuck( $accounts, $this->waiting, (int) $request['stuck_hours'], (int) $request['now'] ),
			'products'   => $this->products( $this->query->product_rows( $this->scope( $request['after'], $request['before'], $request['timezone'], $accounts ) ) ),
		];
	}

	/**
	 * The metrics of one account row (or of the totals).
	 *
	 * @since 2.1.13
	 *
	 * @param array{orders:int,cancelled:int,sold:int,revenue:float,units:float} $row Sums.
	 * @return array<string,mixed>
	 */
	public static function metrics( array $row ): array {
		return [
			'orders'          => (int) $row['orders'],
			'cancelled'       => (int) $row['cancelled'],
			'cancel_share'    => self::ratio( $row['cancelled'], $row['orders'], 4 ),
			'sold'            => (int) $row['sold'],
			'revenue'         => round( (float) $row['revenue'], 2 ),
			'avg_order'       => self::ratio( $row['revenue'], $row['sold'], 2 ),
			'units'           => round( (float) $row['units'], 2 ),
			'units_per_order' => self::ratio( $row['units'], $row['sold'], 2 ),
		];
	}

	/**
	 * The half-open GMT window of two site days, both inclusive, with the offset that puts an order on its site day.
	 *
	 * One fixed offset for the whole window (the one at its midpoint), as the delivery tab does: the shops run on Russian time zones,
	 * which have no daylight saving; in a zone that has, an order near midnight around a change lands on the neighbouring day.
	 *
	 * @since 2.1.13
	 *
	 * @param string        $after    First day.
	 * @param string        $before   Last day.
	 * @param \DateTimeZone $zone     Site timezone.
	 * @param int[]         $accounts Account ids.
	 * @return array{from:string,to:string,offset:int,accounts:int[]}
	 */
	public static function scope( string $after, string $before, \DateTimeZone $zone, array $accounts ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $after . ' 00:00:00', $zone );
		$end   = ( new \DateTimeImmutable( $before . ' 00:00:00', $zone ) )->modify( '+1 day' );
		$mid   = $start->setTimestamp( (int) ( ( $start->getTimestamp() + $end->getTimestamp() ) / 2 ) );

		return [
			'from'     => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to'       => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'offset'   => $zone->getOffset( $mid ),
			'accounts' => array_values( $accounts ),
		];
	}

	/**
	 * The chart points: orders and revenue per interval, in total and per account, zero-filled.
	 *
	 * @since 2.1.13
	 *
	 * @param array<int,array<string,mixed>> $days       Day rows.
	 * @param string                         $after      First day.
	 * @param string                         $before     Last day.
	 * @param string                         $interval   `day`, `week` or `month`.
	 * @param int                            $week_start Day the week starts on.
	 * @return array<int,array<string,mixed>> `{ start, end, totals: { orders, revenue }, accounts: { id: { orders, revenue } } }`.
	 */
	public static function intervals( array $days, string $after, string $before, string $interval, int $week_start ): array {
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
					'accounts' => [],
				];
			}

			$buckets[ $key ]['end'] = $day->format( 'Y-m-d' );
			$day                    = $day->modify( '+1 day' );
		}

		foreach ( $days as $row ) {
			// A day just outside the range (the fixed offset of the bucketing) goes to the nearest edge, so the series add up.
			$key = self::bucket_start( min( max( (string) $row['day'], $after ), $before ), $interval, $week_start );

			if ( ! isset( $buckets[ $key ] ) ) {
				continue;
			}

			$account                                   = (string) $row['account'];
			$buckets[ $key ]['totals']                 = self::add_series( $buckets[ $key ]['totals'], $row );
			$buckets[ $key ]['accounts'][ $account ] = self::add_series( $buckets[ $key ]['accounts'][ $account ] ?? self::empty_series(), $row );
		}

		return array_values( $buckets );
	}

	/**
	 * Reads one period: the sums per account, the day rows and the shop's revenue.
	 *
	 * @param string              $after    First day.
	 * @param string              $before   Last day.
	 * @param array<string,mixed> $request  The request.
	 * @param int[]               $accounts Account ids.
	 * @return array{accounts:array<int,array<string,int|float>>,days:array<int,array<string,mixed>>,shop:array{shop:float,marketplace:float}}
	 */
	private function read_period( string $after, string $before, array $request, array $accounts ): array {
		$scope = self::scope( $after, $before, $request['timezone'], $accounts );
		$days  = $this->query->day_rows( $scope );
		$units = $this->query->unit_rows( $scope );
		$sums  = [];

		foreach ( $accounts as $account ) {
			$sums[ $account ] = self::empty_row();
		}

		foreach ( $days as $row ) {
			$account = (int) $row['account'];

			if ( ! isset( $sums[ $account ] ) ) {
				continue;
			}

			foreach ( [ 'orders', 'cancelled', 'sold', 'revenue' ] as $key ) {
				$sums[ $account ][ $key ] += $row[ $key ];
			}
		}

		foreach ( $units as $account => $count ) {
			if ( isset( $sums[ $account ] ) ) {
				$sums[ $account ]['units'] = $count;
			}
		}

		return [
			'accounts' => $sums,
			'days'     => $days,
			// One account selected: its own part of the shop's revenue; every account: the marketplace's.
			'shop'     => $this->query->shop_revenue( $scope, $this->created_via, null === $request['account'] ? null : (int) $request['account'] ),
		];
	}

	/**
	 * The table by account.
	 *
	 * @param int[]                    $accounts Account ids.
	 * @param array<string,mixed>      $current  Current period.
	 * @param array<string,mixed>|null $previous Previous period.
	 * @return array<int,array<string,mixed>>
	 */
	private function by_account( array $accounts, array $current, ?array $previous ): array {
		$rows = [];

		foreach ( $accounts as $account ) {
			$rows[] = [
				'id'    => (string) $account,
				'label' => $this->account_label( $account ),
			] + self::comparison( $current['accounts'][ $account ], null === $previous ? null : $previous['accounts'][ $account ] );
		}

		return $rows;
	}

	/**
	 * The marketplace statuses of the open postings now, the most frequent first.
	 *
	 * @param array<int,array{account:int,code:string,count:int}> $rows Status rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function statuses( array $rows ): array {
		$codes = [];

		foreach ( $rows as $row ) {
			$code = $row['code'];

			if ( ! isset( $codes[ $code ] ) ) {
				$codes[ $code ] = [
					'code'     => $code,
					'label'    => Posting_Status_Text::label( $code, $this->names ),
					'tone'     => Posting_Status_Text::tone( $code ),
					'count'    => 0,
					'accounts' => [],
				];
			}

			$codes[ $code ]['count']                                  += $row['count'];
			$codes[ $code ]['accounts'][ (string) $row['account'] ] = $row['count'];
		}

		$list = array_values( $codes );

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'] ?: strcmp( $a['code'], $b['code'] );
			}
		);

		return $list;
	}

	/**
	 * The stuck postings: how many and the oldest, with how long each has waited since the marketplace took it into processing (the
	 * moment the shop first saw it when the adapter does not say).
	 *
	 * @param int[]    $accounts Account ids.
	 * @param string[] $waiting  The adapter's waiting statuses.
	 * @param int      $hours    Threshold in hours.
	 * @param int      $now      Unix time.
	 * @return array<string,mixed>
	 */
	private function stuck( array $accounts, array $waiting, int $hours, int $now ): array {
		$hours = max( 1, $hours );
		$found = $this->query->stuck( $accounts, $waiting, gmdate( 'Y-m-d H:i:s', $now - $hours * 3600 ) );
		$items = [];

		foreach ( $found['items'] as $item ) {
			$seen    = strtotime( $item['waiting_since'] . ' UTC' );
			$items[] = [
				'order_id'  => $item['order_id'],
				'order_url' => (string) call_user_func( $this->order_url, $item['order_id'] ),
				'posting'   => $item['posting'],
				'account'   => $this->account_label( $item['account'] ),
				'status'    => Posting_Status_Text::label( $item['status'], $this->names ),
				'hours'     => false === $seen ? null : max( 0, intdiv( $now - $seen, 3600 ) ),
			];
		}

		return [
			'threshold_hours' => $hours,
			'count'           => $found['count'],
			'items'           => $items,
		];
	}

	/**
	 * The products: the best sellers by units and by revenue in one list (the tab sorts it), and the items sold without a shop
	 * product.
	 *
	 * @param array<int,array{product_id:int,variation_id:int,name:string,quantity:float,revenue:float}> $rows Product rows.
	 * @return array<string,mixed>
	 */
	private function products( array $rows ): array {
		$linked   = [];
		$unlinked = [];

		foreach ( $rows as $row ) {
			if ( $row['product_id'] > 0 ) {
				$linked[] = $row;
			} else {
				$unlinked[] = $row;
			}
		}

		$by_units   = self::top( $linked, 'quantity' );
		$by_revenue = self::top( $linked, 'revenue' );
		$top        = [];

		foreach ( array_merge( $by_units, $by_revenue ) as $row ) {
			$key         = $row['product_id'] . '-' . $row['variation_id'];
			$top[ $key ] = [
				'key'      => $key,
				'label'    => $row['name'],
				'url'      => (string) call_user_func( $this->product_url, $row['product_id'] ),
				'quantity' => round( $row['quantity'], 2 ),
				'revenue'  => round( $row['revenue'], 2 ),
			];
		}

		return [
			'top'      => array_values( $top ),
			'unlinked' => [
				'items'    => array_map(
					static function ( array $row ): array {
						return [
							'label'    => $row['name'],
							'quantity' => round( $row['quantity'], 2 ),
							'revenue'  => round( $row['revenue'], 2 ),
						];
					},
					self::top( $unlinked, 'quantity' )
				),
				'names'    => count( $unlinked ),
				'quantity' => round( array_sum( array_column( $unlinked, 'quantity' ) ), 2 ),
				'revenue'  => round( array_sum( array_column( $unlinked, 'revenue' ) ), 2 ),
			],
		];
	}

	/**
	 * The marketplace's share of the shop's revenue, with the previous period's. With one account selected the marketplace part is that
	 * account's, so the share is the account's.
	 *
	 * @param array{shop:float,marketplace:float}      $current  Current period.
	 * @param array{shop:float,marketplace:float}|null $previous Previous period.
	 * @return array<string,mixed>
	 */
	private static function share( array $current, ?array $previous ): array {
		$now    = self::ratio( $current['marketplace'], $current['shop'], 4 );
		$before = null === $previous ? null : self::ratio( $previous['marketplace'], $previous['shop'], 4 );

		return [
			'shop_revenue'        => round( $current['shop'], 2 ),
			'marketplace_revenue' => round( $current['marketplace'], 2 ),
			'share'               => $now,
			'previous'            => $before,
			'delta'               => null === $previous ? null : self::percent_change( $now, $before ),
		];
	}

	/**
	 * Metrics of a period with the previous one and the changes.
	 *
	 * @param array<string,int|float>      $current  Current sums.
	 * @param array<string,int|float>|null $previous Previous sums.
	 * @return array{current:array<string,mixed>,previous:array<string,mixed>|null,delta:array<string,float|null>}
	 */
	private static function comparison( array $current, ?array $previous ): array {
		$now    = self::metrics( $current );
		$before = null === $previous ? null : self::metrics( $previous );
		$delta  = [];

		foreach ( self::DELTA_KEYS as $key ) {
			$delta[ $key ] = null === $before ? null : self::percent_change( $now[ $key ], $before[ $key ] );
		}

		return [
			'current'  => $now,
			'previous' => $before,
			'delta'    => $delta,
		];
	}

	/**
	 * Adds account sums together.
	 *
	 * @param array<int,array<string,int|float>> $rows Sums by account.
	 * @return array<string,int|float>
	 */
	private static function sum( array $rows ): array {
		$sum = self::empty_row();

		foreach ( $rows as $row ) {
			foreach ( $sum as $key => $value ) {
				$sum[ $key ] = $value + $row[ $key ];
			}
		}

		return $sum;
	}

	/**
	 * @return array{orders:int,cancelled:int,sold:int,revenue:float,units:float}
	 */
	private static function empty_row(): array {
		return [
			'orders'    => 0,
			'cancelled' => 0,
			'sold'      => 0,
			'revenue'   => 0.0,
			'units'     => 0.0,
		];
	}

	/**
	 * The rows with the largest value of a key, at most {@see self::TOP_PRODUCTS}; ties by name.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $key  `quantity` or `revenue`.
	 * @return array<int,array<string,mixed>>
	 */
	private static function top( array $rows, string $key ): array {
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $key ): int {
				return $b[ $key ] <=> $a[ $key ] ?: strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return array_slice( $rows, 0, self::TOP_PRODUCTS );
	}

	/**
	 * @param int[] $accounts Account ids.
	 * @return array<int,array{id:string,label:string}>
	 */
	private function account_list( array $accounts ): array {
		$list = [];

		foreach ( $accounts as $account ) {
			$list[] = [
				'id'    => (string) $account,
				'label' => $this->account_label( $account ),
			];
		}

		return $list;
	}

	/**
	 * @param int $account Account id.
	 * @return string
	 */
	private function account_label( int $account ): string {
		return $this->accounts[ $account ] ?? '#' . $account;
	}

	/**
	 * @return array{orders:int,revenue:float}
	 */
	private static function empty_series(): array {
		return [
			'orders'  => 0,
			'revenue' => 0.0,
		];
	}

	/**
	 * @param array{orders:int,revenue:float} $series Series so far.
	 * @param array<string,mixed>             $row    Day row.
	 * @return array{orders:int,revenue:float}
	 */
	private static function add_series( array $series, array $row ): array {
		return [
			'orders'  => $series['orders'] + (int) $row['orders'],
			'revenue' => round( $series['revenue'] + (float) $row['revenue'], 2 ),
		];
	}

	/**
	 * The first day of the interval a day belongs to.
	 *
	 * @param string $day        `Y-m-d`.
	 * @param string $interval   `day`, `week` or `month`.
	 * @param int    $week_start Day the week starts on.
	 * @return string
	 */
	private static function bucket_start( string $day, string $interval, int $week_start ): string {
		$date = new \DateTimeImmutable( $day . ' 00:00:00', new \DateTimeZone( 'UTC' ) );

		if ( 'month' === $interval ) {
			return $date->format( 'Y-m-01' );
		}

		if ( 'week' === $interval ) {
			return $date->modify( '-' . ( ( (int) $date->format( 'w' ) - $week_start + 7 ) % 7 ) . ' days' )->format( 'Y-m-d' );
		}

		return $day;
	}

	/**
	 * `$numerator / $denominator` rounded, or null over nothing.
	 *
	 * @param int|float $numerator   Numerator.
	 * @param int|float $denominator Denominator.
	 * @param int       $precision   Decimals.
	 * @return float|null
	 */
	private static function ratio( $numerator, $denominator, int $precision ): ?float {
		return $denominator > 0 ? round( $numerator / $denominator, $precision ) : null;
	}

	/**
	 * Percent change, or null when either side is missing or the previous one is zero.
	 *
	 * @param int|float|null $current  Current value.
	 * @param int|float|null $previous Previous value.
	 * @return float|null
	 */
	private static function percent_change( $current, $previous ): ?float {
		if ( null === $current || null === $previous || 0.0 === (float) $previous ) {
			return null;
		}

		return round( ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100, 1 );
	}
}
