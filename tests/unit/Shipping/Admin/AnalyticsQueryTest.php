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

			public $prepared = [];
			public $rows     = [ [ 'carrier' => 'cdek' ] ];

			public function prepare( $sql, $args ) {
				$this->prepared[] = [ $sql, $args ];

				return 'PREPARED';
			}

			public function get_results( $query, $output ) {
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
		[ $sql ] = $this->query( true )->carrier_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( 'wp_wc_orders_meta', $sql );
		$this->assertStringContainsString( 'JOIN wp_wc_orders o', $sql );
		$this->assertStringContainsString( 'wp_wc_order_operational_data op', $sql );
		$this->assertStringContainsString( 'o.total_amount', $sql );
		$this->assertStringContainsString( 'o.date_created_gmt', $sql );
		$this->assertStringContainsString( "o.type = 'shop_order'", $sql );
		$this->assertStringNotContainsString( 'postmeta', $sql );
	}

	public function test_legacy_reads_posts_and_postmeta(): void {
		[ $sql ] = $this->query( false )->carrier_sql( [ $this->cdek() ], self::SCOPE );

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
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->cdek() ], self::SCOPE );

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
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->cdek() ], self::SCOPE );

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
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->yandex() ], self::SCOPE );

		$this->assertStringContainsString( 'NULL AS raw_status', $sql );
		$this->assertStringNotContainsString( 'WHEN t.raw_status', $sql );
		$this->assertNotContains( '_yandex_status', $args );
	}

	public function test_one_leg_per_provider_and_the_first_registered_provider_wins(): void {
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE );

		$this->assertSame( 1, substr_count( $sql, ' UNION ALL ' ) );
		$this->assertSame( 1, substr_count( $sql, 'NOT EXISTS' ), 'only the second leg skips orders an earlier marker claimed' );
		$this->assertStringContainsString( 'e.meta_key IN (', $sql );
		$this->assertContains( '_cdek_marker', $args );
		$this->assertContains( '_yandex_marker', $args );
	}

	public function test_the_carrier_filter_keeps_the_earlier_marker_rule(): void {
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->cdek(), $this->yandex() ], self::SCOPE, 'yandex' );

		$this->assertSame( 0, substr_count( $sql, ' UNION ALL ' ) );
		$this->assertSame( 1, substr_count( $sql, 'NOT EXISTS' ), 'restricting to yandex must not hand it the orders cdek owns' );
		$this->assertNotContains( '_cdek_status', $args );
		$this->assertContains( 'yandex', $args );
	}

	public function test_the_window_is_half_open_and_the_population_excludes_statuses(): void {
		[ $sql ] = $this->query( true )->carrier_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertMatchesRegularExpression( '/o\.date_created_gmt >= %\d+\$s AND o\.date_created_gmt < %\d+\$s/', $sql );
		$this->assertMatchesRegularExpression( '/o\.status NOT IN \( %\d+\$s, %\d+\$s \)/', $sql );

		[ $open ] = $this->query( true )->carrier_sql( [ $this->cdek() ], [ 'excluded' => [] ] + self::SCOPE );
		$this->assertStringNotContainsString( 'NOT IN', explode( 'GROUP BY', explode( 'FROM ( SELECT DISTINCT', $open )[1] )[0] );
	}

	public function test_the_carrier_statement_groups_by_carrier_with_the_bounded_time_sums(): void {
		[ $sql, $args ] = $this->query( true )->carrier_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringEndsWith( 'GROUP BY x.carrier', $sql );
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
		$this->assertStringContainsString( "x.bucket IN ( 'delivered', 'returned', 'failed' )", $sql );
	}

	public function test_the_method_statement_groups_shipping_lines_by_method_and_prices_them_from_cost_and_tax(): void {
		[ $sql ] = $this->query( false )->method_sql( [ $this->cdek() ], self::SCOPE );

		$this->assertStringContainsString( "line.order_item_type = 'shipping'", $sql );
		$this->assertStringContainsString( "method.meta_key = 'method_id'", $sql );
		$this->assertStringContainsString( "cost.meta_key = 'cost'", $sql );
		$this->assertStringContainsString( "tax.meta_key = 'total_tax'", $sql );
		$this->assertStringEndsWith( 'GROUP BY x.carrier, method.meta_value', $sql );
	}

	public function test_no_provider_in_scope_runs_no_query(): void {
		$db = $this->wpdb();

		$this->assertNull( $this->query( true, $db )->carrier_sql( [], self::SCOPE ) );
		$this->assertNull( $this->query( true, $db )->day_sql( [ $this->cdek() ], self::SCOPE, 'nobody' ) );
		$this->assertSame( [], $this->query( true, $db )->carrier_rows( [], self::SCOPE ) );
		$this->assertSame( [], $db->prepared );
	}

	public function test_rows_come_from_one_prepared_statement(): void {
		$db = $this->wpdb();

		$this->assertSame( [ [ 'carrier' => 'cdek' ] ], $this->query( true, $db )->carrier_rows( [ $this->cdek() ], self::SCOPE ) );
		$this->assertCount( 1, $db->prepared );
	}
}
