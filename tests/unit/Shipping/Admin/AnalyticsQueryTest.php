<?php
/**
 * Unit: the aggregate SQL behind the «Аналитика» tab (#711, spec §4.1).
 *
 * Pins the SHAPE of the statements — no database: the datastore pair (HPOS / legacy), one leg per provider with
 * «first provider wins», the status CASE built from each provider's own `status_map`, every value bound through a numbered
 * placeholder (nothing from a request interpolated), and the carrier filter that keeps the earlier-marker rule.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Woodev\Framework\Shipping\Admin\Orders\{Analytics_Query, Orders_Provider};
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Analytics_Query
 */
final class AnalyticsQueryTest extends TestCase {

	private const SCOPE = [
		'from'     => '2026-09-30 21:00:00',
		'to'       => '2026-10-31 21:00:00',
		'offset'   => 10800,
		'excluded' => [ 'wc-pending', 'trash' ],
	];

	private function wpdb(): object {
		return new class() {
			public $prefix   = 'wp_';
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';

			public $prepared   = [];
			public $rows       = [ [ 'carrier' => 'cdek' ] ];
			public $last_error = '';
			public $fail_with  = '';
			public $suppressed = [];

			public function prepare( $sql, $args ) {
				$this->prepared[] = [ $sql, $args ];

				return 'PREPARED';
			}

			public function suppress_errors( $suppress = true ) {
				$previous           = ! empty( $this->suppressed ) && end( $this->suppressed );
				$this->suppressed[] = $suppress;

				return $previous;
			}

			public function get_results( $query, $output ) {
				if ( '' !== $this->fail_with ) {
					$this->last_error = $this->fail_with;

					return null;
				}

				return 'PREPARED' === $query && 'ARRAY_A' === $output ? $this->rows : [];
			}
		};
	}

	private function cdek(): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek' ],
			[
				'status_meta_key' => '_cdek_status',
				'status_map'      => [
					'DELIVERED' => 'delivered',
					'RETURNED'  => 'returned',
					'BACK'      => 'returning',
					'NOT_DELIV' => 'failed',
					'ACCEPTED'  => 'created',
					'JUNK'      => 'not-a-state',
				],
			]
		);
	}

	private function yandex(): Orders_Provider {
		return Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ] );
	}

	private function query( bool $hpos, ?object $db = null ): Analytics_Query {
		return new Analytics_Query( $db ?? $this->wpdb(), $hpos );
	}

	public function test_hpos_reads_the_hpos_tables_and_columns(): void {
		[ $sql ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( 'wp_wc_orders_meta', $sql );
		$this->assertStringContainsString( 'JOIN wp_wc_orders o', $sql );
		$this->assertStringContainsString( 'wp_wc_order_operational_data op', $sql );
		$this->assertStringContainsString( 'o.total_amount', $sql );
		$this->assertStringContainsString( 'o.date_created_gmt', $sql );
		$this->assertStringContainsString( "o.type = 'shop_order'", $sql );
		$this->assertStringNotContainsString( 'postmeta', $sql );
	}

	public function test_legacy_reads_posts_and_postmeta(): void {
		[ $sql ] = $this->query( false )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( 'JOIN wp_posts o', $sql );
		$this->assertStringContainsString( 'FROM wp_postmeta', $sql );
		$this->assertStringContainsString( "pm.meta_key = '_order_total'", $sql );
		$this->assertStringContainsString( "pm.meta_key = '_order_shipping_tax'", $sql );
		$this->assertStringContainsString( "pm.meta_key = '_date_completed'", $sql );
		$this->assertStringContainsString( 'o.post_date_gmt', $sql );
		$this->assertStringContainsString( "o.post_type = 'shop_order'", $sql );
		$this->assertStringNotContainsString( 'wc_orders', $sql );
	}

	public function test_every_value_is_bound_and_none_is_interpolated(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		foreach ( [ 'cdek', '_cdek_marker', '_cdek_status', 'DELIVERED', 'wc-pending', 'trash', '2026-09-30 21:00:00', '2026-10-31 21:00:00', 10800 ] as $value ) {
			$this->assertContains( $value, $args );
			$this->assertStringNotContainsString( "'" . $value . "'", $sql );
		}

		preg_match_all( '/%(\d+)\$[sd]/', $sql, $found );
		$used = array_map( 'intval', array_unique( $found[1] ) );
		sort( $used );
		$this->assertSame( range( 1, count( $args ) ), $used, 'every argument is used and none is missing' );
	}

	public function test_the_status_case_comes_from_the_providers_own_map(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( "THEN 'delivered'", $sql );
		$this->assertStringContainsString( "THEN 'returned'", $sql );
		$this->assertStringContainsString( "THEN 'failed'", $sql );
		$this->assertStringContainsString( "THEN 'in_progress'", $sql );
		$this->assertSame( 1, substr_count( $sql, "THEN 'cancelled'" ), 'only the framework flag cancels: no raw status is mapped to it' );
		$this->assertStringContainsString( "WHEN t.cancelled = 1 THEN 'cancelled'", $sql );
		$this->assertStringContainsString( "ELSE 'unknown'", $sql );
		$this->assertNotContains( 'JUNK', $args, 'a map entry that is not a canonical state is treated as unmapped' );
		$this->assertContains( 'BACK', $args );
		$this->assertContains( 'RETURNED', $args );
	}

	public function test_a_provider_without_a_status_key_is_always_unknown(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->yandex() ], self::SCOPE );

		$this->assertStringContainsString( 'NULL AS raw_status', $sql );
		$this->assertStringNotContainsString( 'WHEN t.raw_status', $sql );
		$this->assertNotContains( '_yandex_status', $args );
	}

	public function test_one_leg_per_provider_and_the_first_registered_provider_wins(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE );

		$this->assertSame( 1, substr_count( $sql, ' UNION ALL ' ) );
		$this->assertSame( 1, substr_count( $sql, 'NOT EXISTS' ), 'only the second leg skips orders an earlier marker claimed' );
		$this->assertStringContainsString( 'e.meta_key IN (', $sql );
		$this->assertContains( '_cdek_marker', $args );
		$this->assertContains( '_yandex_marker', $args );
	}

	public function test_the_carrier_filter_keeps_the_earlier_marker_rule(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE, 'yandex' );

		$this->assertSame( 0, substr_count( $sql, ' UNION ALL ' ) );
		$this->assertSame( 1, substr_count( $sql, 'NOT EXISTS' ), 'restricting to yandex must not hand it the orders cdek owns' );
		$this->assertNotContains( '_cdek_status', $args );
		$this->assertContains( 'yandex', $args );
	}

	public function test_the_window_is_half_open_and_the_population_excludes_statuses(): void {
		[ $sql ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertMatchesRegularExpression( "/o\\.date_created_gmt >= '%\\d+\\\$s' AND o\\.date_created_gmt < '%\\d+\\\$s'/", $sql );
		$this->assertMatchesRegularExpression( "/o\\.status NOT IN \\( '%\\d+\\\$s', '%\\d+\\\$s' \\)/", $sql );

		[ $open ] = $this->query( true )->day_sql( [ $this->cdek() ], [ 'excluded' => [] ] + self::SCOPE );
		$this->assertStringNotContainsString( 'NOT IN', explode( 'GROUP BY', explode( 'FROM ( SELECT DISTINCT', $open )[1] )[0] );
	}

	public function test_one_statement_feeds_carrier_table_and_chart_with_the_bounded_time_sums(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringEndsWith( 'GROUP BY x.carrier, x.day', $sql );
		$this->assertStringContainsString( 'SUM( x.bucket = \'unknown\' ) AS unknown', $sql );
		$this->assertStringContainsString( 'AS time_sample', $sql );
		$this->assertStringContainsString( 'AS time_seconds', $sql );
		$this->assertStringContainsString( "x.bucket = 'delivered' AND x.diff > 0 AND x.diff <= ", $sql );
		$this->assertContains( Analytics_Query::MAX_DELIVERY_SECONDS, $args );
		$this->assertSame( 15552000, Analytics_Query::MAX_DELIVERY_SECONDS, '180 days' );
	}

	public function test_the_day_statement_groups_by_carrier_and_day(): void {
		[ $sql ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringEndsWith( 'GROUP BY x.carrier, x.day', $sql );
		$this->assertStringContainsString( 'DATE( DATE_ADD( o.date_created_gmt, INTERVAL', $sql );
		$this->assertStringContainsString( 'AS time_sample', $sql );
	}

	/**
	 * #711 perf: the shipping-method statement needs only the carrier, the order id and the order total of the population. Its legs are
	 * SLIM — no status subquery, no cancellation probe, no day, no completion time, no operational-data join — which is two thirds of a
	 * full leg's work at 50 000 orders. The items are then probed by their own `order_id`.
	 */
	public function test_the_method_statement_runs_on_slim_legs_and_the_day_statement_on_full_ones(): void {
		[ $method ] = $this->query( true )->method_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE );
		[ $day ]    = $this->query( true )->day_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE );

		foreach ( [ 'raw_status', 'cancelled', 'wc_order_operational_data', 'TIMESTAMPDIFF', 'DATE_ADD', 'CASE WHEN' ] as $fragment ) {
			$this->assertStringNotContainsString( $fragment, $method, 'a slim leg does not carry ' . $fragment );
			$this->assertStringContainsString( $fragment, $day, 'the full leg does' );
		}

		$this->assertStringContainsString( 'o.total_amount AS total', $method );
		$this->assertSame( 1, substr_count( $method, ' UNION ALL ' ), 'one slim leg per provider' );
		$this->assertSame( 1, substr_count( $method, 'NOT EXISTS' ), 'first registered provider wins in a slim leg too' );
	}

	public function test_the_method_statement_groups_shipping_lines_by_method_and_prices_them_from_cost_and_tax(): void {
		[ $sql ] = $this->query( false )->method_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( "line.order_item_type = 'shipping'", $sql );
		$this->assertStringContainsString( "method.meta_key = 'method_id'", $sql );
		$this->assertStringContainsString( "cost.meta_key = 'cost'", $sql );
		$this->assertStringContainsString( "tax.meta_key = 'total_tax'", $sql );
		$this->assertStringEndsWith( 'GROUP BY m.carrier, m.method_id', $sql );
	}

	public function test_method_revenue_adds_an_order_total_once_per_carrier_method_and_order(): void {
		[ $sql ] = $this->query( true )->method_sql( [ $this->cdek() ], self::SCOPE );

		// The inner statement collapses an order's lines of one method into ONE row carrying the total once…
		$this->assertStringContainsString( 'MAX( x.total ) AS total, COUNT(*) AS line_count', $sql );
		$this->assertStringContainsString( 'GROUP BY x.carrier, method.meta_value, x.order_id', $sql );
		// …and only the outer statement adds the orders up: revenue is never summed over the shipping lines.
		$this->assertStringContainsString( 'SUM( m.total ) AS revenue', $sql );
		$this->assertStringContainsString( 'COUNT(*) AS orders, SUM( m.line_count ) AS line_count', $sql );
		$this->assertStringNotContainsString( 'SUM( x.total )', $sql );
		$this->assertStringNotContainsString( 'COUNT( DISTINCT', $sql );
	}

	public function test_the_handover_statement_reads_the_delivered_orders_status_log_by_its_bound_key(): void {
		[ $sql, $args ] = $this->query( true )->handover_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE );

		$this->assertStringStartsWith( 'SELECT x.carrier, x.status_log FROM (', $sql );
		$this->assertStringContainsString( "WHERE x.bucket = 'delivered' AND x.status_log IS NOT NULL", $sql );
		$this->assertStringContainsString( 'AS status_log', $sql );
		$this->assertContains( '_woodev_delivery_status_log_cdek', $args );
		$this->assertContains( '_woodev_delivery_status_log_yandex', $args );
		$this->assertStringNotContainsString( '_woodev_delivery_status_log', $sql, 'the key is bound, never interpolated' );
	}

	public function test_the_other_statements_do_not_read_the_log(): void {
		[ $sql, $args ] = $this->query( true )->day_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringNotContainsString( 'status_log', $sql );
		$this->assertNotContains( '_woodev_delivery_status_log_cdek', $args );
	}

	public function test_handover_rows_add_up_only_the_logs_that_show_both_ends(): void {
		$db       = $this->wpdb();
		$db->rows = [
			[ 'carrier' => 'cdek', 'status_log' => '[["created",100],["in_transit",1000],["delivered",1000]]' ],
			[ 'carrier' => 'cdek', 'status_log' => '[["created",100],["ready_for_pickup",500],["delivered",1700]]' ],
			[ 'carrier' => 'cdek', 'status_log' => '[["delivered",900]]' ],
			[ 'carrier' => 'cdek', 'status_log' => 'not json' ],
			[ 'carrier' => 'yandex', 'status_log' => '[["in_transit",0],["delivered",86400]]' ],
		];

		$this->assertSame(
			[
				'cdek'   => [ 'sample' => 2, 'seconds' => 1200 ],
				'yandex' => [ 'sample' => 1, 'seconds' => 86400 ],
			],
			$this->query( true, $db )->handover_rows( [ $this->cdek(), $this->yandex() ], self::SCOPE )
		);
	}

	public function test_handover_rows_of_no_provider_run_no_query(): void {
		$db = $this->wpdb();

		$this->assertSame( [], $this->query( true, $db )->handover_rows( [], self::SCOPE ) );
		$this->assertSame( [], $db->prepared );
	}

	public function test_no_provider_in_scope_runs_no_query(): void {
		$db = $this->wpdb();

		$this->assertNull( $this->query( true, $db )->day_sql( [], self::SCOPE ) );
		$this->assertNull( $this->query( true, $db )->day_sql( [ $this->cdek() ], self::SCOPE, 'nobody' ) );
		$this->assertSame( [], $this->query( true, $db )->day_rows( [], self::SCOPE ) );
		$this->assertSame( [], $db->prepared );
	}

	public function test_rows_come_from_one_prepared_statement(): void {
		$db = $this->wpdb();

		$this->assertSame( [ [ 'carrier' => 'cdek' ] ], $this->query( true, $db )->day_rows( [ $this->cdek() ], self::SCOPE ) );
		$this->assertCount( 1, $db->prepared );
	}

	/**
	 * The two `wpdb::prepare()` rules a numbered-placeholder statement lives or dies by, found on the rig (#711): a numbered `%N$s` is
	 * inserted UNQUOTED ("for backward compatibility"), so every string placeholder must carry its own quotes; and prepare() counts placeholder
	 * OCCURRENCES against arguments, so one numbered placeholder used twice raises a `_doing_it_wrong` notice (HTML in the REST body).
	 * No WordPress is loaded here, so the rules are asserted on the template, for every statement and both datastores.
	 *
	 * @dataProvider statements
	 */
	public function test_the_template_obeys_the_wpdb_prepare_placeholder_rules( string $method, bool $hpos ): void {
		[ $sql, $args ] = $this->query( $hpos )->{$method}( [ $this->cdek(), $this->yandex() ], self::SCOPE );

		preg_match_all( '/%(\d+)\$([sd])/', $sql, $found, PREG_OFFSET_CAPTURE );

		$this->assertCount( count( $args ), $found[0], 'one placeholder occurrence per argument' );
		$numbers = array_column( $found[1], 0 );
		$this->assertSame( count( $numbers ), count( array_unique( $numbers ) ), 'no numbered placeholder is used twice' );

		foreach ( $found[0] as $i => [ $placeholder, $offset ] ) {
			$quoted = "'" === $sql[ $offset - 1 ] && "'" === $sql[ $offset + strlen( $placeholder ) ];

			if ( 's' === $found[2][ $i ][0] ) {
				$this->assertTrue( $quoted, $placeholder . ' is a string and must be quoted in the template' );
			} else {
				$this->assertFalse( $quoted, $placeholder . ' is an integer and must not be quoted' );
			}
		}
	}

	public static function statements(): array {
		return [
			'day, HPOS'       => [ 'day_sql', true ],
			'day, legacy'     => [ 'day_sql', false ],
			'method, HPOS'    => [ 'method_sql', true ],
			'method, legacy'  => [ 'method_sql', false ],
			'handover, HPOS'  => [ 'handover_sql', true ],
			'handover, legacy' => [ 'handover_sql', false ],
		];
	}

	public function test_a_failed_statement_is_an_exception_not_an_empty_result_and_errors_are_suppressed_around_it(): void {
		$db            = $this->wpdb();
		$db->fail_with = 'Unknown column x';

		try {
			$this->query( true, $db )->day_rows( [ $this->cdek() ], self::SCOPE );
			$this->fail( 'a database error must not read as «no orders»' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Unknown column x', $e->getMessage() );
			$this->assertStringNotContainsString( 'SELECT', $e->getMessage(), 'the SQL never travels in the message' );
		}

		$this->assertSame( [ true, false ], $db->suppressed, 'wpdb errors (HTML) are suppressed during the call and restored after it' );
	}

	public function test_a_statement_that_could_not_be_prepared_is_a_failure_too(): void {
		$db = $this->wpdb();
		$db->rows = null; // get_results() on an unprepared (empty) query gives null.

		$this->expectException( \RuntimeException::class );

		$this->query( true, $db )->day_rows( [ $this->cdek() ], self::SCOPE );
	}
}
