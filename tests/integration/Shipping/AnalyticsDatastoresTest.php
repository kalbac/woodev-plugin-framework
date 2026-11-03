<?php
/**
 * Integration: the delivery analytics route (#711) against REAL orders on BOTH WooCommerce order datastores — HPOS and the
 * legacy CPT — in one run.
 *
 * The unit tier pins the SHAPE of the SQL; nothing there executes it. Here the real statements run through the real
 * `$wpdb->prepare()` against orders created with WooCommerce's own API, and every figure the route answers — carrier rows, totals,
 * the shipping-method table, the chart's days — is compared with an ORACLE computed in PHP from the specs the orders were created
 * from, never from the query's own output.
 *
 * The universe covers what the SQL has to get right: three providers (two with a status map, one without a status key), the full
 * outcome vocabulary and an unmapped raw value, a framework-recorded cancellation, an order with NO status meta, two shipping lines of
 * ONE method (revenue must not be added twice), one order with two DIFFERENT methods, orders carrying two carrier markers («first
 * registered wins»), a virtual order with no shipping line, an order no carrier owns, WooCommerce statuses outside the population, and
 * orders at the very edges of the range in the SITE timezone (Moscow, UTC+3 — so the GMT window is not the calendar day).
 *
 * Parity with the orders list (spec §7): for the same range the analytics order count equals the list route's `total`.
 *
 * Datastores in one process, as {@see OrdersIdResolverDatastoresTest} does it: the `woocommerce_custom_orders_table_enabled` option is
 * flipped (data sync off, so an order lives in exactly one datastore) and the framework's own `is_hpos_enabled()` is asserted to agree.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.24
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Order\{Delivery_Status, Shipment_Cancellation};
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class AnalyticsDatastoresTest extends TestCase {

	private const AFTER  = '2026-10-10';
	private const BEFORE = '2026-10-12';

	/** Outcome bucket of each canonical state, as the spec defines it (§2.3). */
	private const BUCKET_OF = [
		Delivery_Status::PENDING          => 'in_progress',
		Delivery_Status::CREATED          => 'in_progress',
		Delivery_Status::IN_TRANSIT       => 'in_progress',
		Delivery_Status::READY_FOR_PICKUP => 'in_progress',
		Delivery_Status::DELIVERED        => 'delivered',
		Delivery_Status::RETURNING        => 'returned',
		Delivery_Status::RETURNED         => 'returned',
		Delivery_Status::FAILED           => 'failed',
		Delivery_Status::CANCELLED        => 'cancelled',
	];

	/** The WooCommerce statuses outside the population. */
	private const EXCLUDED = [ 'pending', 'failed', 'cancelled', 'refunded' ];

	/** @var array<string,array{marker:string,status_key:?string,map:array<string,string>}> in registry order. */
	private $providers = [
		'alpha' => [
			'marker'     => '_woodev_it_alpha_marker',
			'status_key' => '_woodev_it_alpha_status',
			'map'        => [
				'A_NEW'  => Delivery_Status::CREATED,
				'A_GO'   => Delivery_Status::IN_TRANSIT,
				'A_DONE' => Delivery_Status::DELIVERED,
				'A_RET'  => Delivery_Status::RETURNING,
				'A_BACK' => Delivery_Status::RETURNED,
				'A_FAIL' => Delivery_Status::FAILED,
			],
		],
		'beta'  => [
			'marker'     => '_woodev_it_beta_marker',
			'status_key' => '_woodev_it_beta_status',
			'map'        => [
				'B_GO'   => Delivery_Status::IN_TRANSIT,
				'B_DONE' => Delivery_Status::DELIVERED,
			],
		],
		'gamma' => [
			'marker'     => '_woodev_it_gamma_marker',
			'status_key' => null,
			'map'        => [],
		],
	];

	/** @var array<int,array<string,mixed>> the specs the orders were created from, with their ids once seeded. */
	private $seeded = [];

	/**
	 * Three providers and a fresh REST server; the site is in Moscow (UTC+3, no daylight saving).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option( 'timezone_string', 'Europe/Moscow' );

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();

		foreach ( $this->providers as $id => $definition ) {
			$args = null === $definition['status_key'] ? [] : [
				'status_meta_key' => $definition['status_key'],
				'status_map'      => $definition['map'],
			];
			$registry->register_provider( Orders_Provider::create( $id, ucfirst( $id ), $definition['marker'], [ $id . '_door', $id . '_pickup' ], $args ) );
		}

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/** @return array<string,array{0:bool}> */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * The orders. `markers` are provider ids in the order the order carries them; `raw` is written under the status key of the provider that OWNS the order;
	 * `lines` are `[ method_id, cost, tax ]`; `at` is the site-local creation time.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function universe(): array {
		return [
			// alpha, in range, edges and the time metric (completed two days after it was created).
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_DONE', 'wc' => 'completed', 'at' => '2026-10-10 00:00:00', 'completed' => '2026-10-12 00:00:00', 'total' => 1000, 'lines' => [ [ 'alpha_door', 100, 20 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'on-hold', 'at' => '2026-10-11 12:00:00', 'total' => 2000, 'lines' => [ [ 'alpha_door', 200, 0 ] ] ],
			// Two lines of ONE method: its revenue is the order's total once, not twice.
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_BACK', 'wc' => 'completed', 'at' => '2026-10-12 23:59:59', 'total' => 3000, 'lines' => [ [ 'alpha_pickup', 300, 60 ], [ 'alpha_pickup', 100, 20 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_RET', 'wc' => 'processing', 'at' => '2026-10-10 09:30:00', 'total' => 450, 'lines' => [ [ 'alpha_door', 45, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_FAIL', 'wc' => 'processing', 'at' => '2026-10-11 08:00:00', 'total' => 700, 'lines' => [ [ 'alpha_door', 70, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_NEW', 'wc' => 'processing', 'at' => '2026-10-11 18:00:00', 'total' => 640, 'lines' => [ [ 'alpha_door', 64, 0 ] ] ],
			// An unmapped raw value, and no status meta at all: both «unknown».
			[ 'markers' => [ 'alpha' ], 'raw' => 'ZZZ', 'wc' => 'processing', 'at' => '2026-10-10 20:00:00', 'total' => 800, 'lines' => [ [ 'alpha_door', 80, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => null, 'wc' => 'processing', 'at' => '2026-10-12 06:00:00', 'total' => 900, 'lines' => [ [ 'alpha_pickup', 90, 0 ] ] ],
			// The framework recorded a cancellation: «cancelled» whatever the raw status says.
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'cancelled' => true, 'wc' => 'processing', 'at' => '2026-10-11 07:00:00', 'total' => 1100, 'lines' => [ [ 'alpha_door', 110, 0 ] ] ],
			// A virtual order: a marker, no shipping line.
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'processing', 'at' => '2026-10-11 10:00:00', 'total' => 300, 'lines' => [] ],
			// Day edges in the SITE timezone: one second before the range and the first second after it.
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_DONE', 'wc' => 'processing', 'at' => '2026-10-09 23:59:59', 'total' => 5000, 'lines' => [ [ 'alpha_door', 500, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'processing', 'at' => '2026-10-13 00:00:00', 'total' => 6000, 'lines' => [ [ 'alpha_door', 600, 0 ] ] ],
			// WooCommerce statuses outside the population.
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'cancelled', 'at' => '2026-10-11 11:00:00', 'total' => 7000, 'lines' => [ [ 'alpha_door', 700, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'pending', 'at' => '2026-10-11 11:30:00', 'total' => 7100, 'lines' => [ [ 'alpha_door', 710, 0 ] ] ],
			[ 'markers' => [ 'alpha' ], 'raw' => 'A_GO', 'wc' => 'refunded', 'at' => '2026-10-11 11:45:00', 'total' => 7200, 'lines' => [ [ 'alpha_door', 720, 0 ] ] ],
			// beta: a delivered order, and one with two DIFFERENT methods (counts in both method rows).
			[ 'markers' => [ 'beta' ], 'raw' => 'B_DONE', 'wc' => 'processing', 'at' => '2026-10-11 09:00:00', 'total' => 4000, 'lines' => [ [ 'beta_door', 400, 80 ] ] ],
			[ 'markers' => [ 'beta' ], 'raw' => 'B_GO', 'wc' => 'processing', 'at' => '2026-10-12 10:00:00', 'total' => 1500, 'lines' => [ [ 'beta_door', 150, 0 ], [ 'beta_pickup', 50, 0 ] ] ],
			// gamma has no status key: everything is «unknown».
			[ 'markers' => [ 'gamma' ], 'raw' => null, 'wc' => 'processing', 'at' => '2026-10-10 15:00:00', 'total' => 1200, 'lines' => [ [ 'gamma_door', 120, 0 ] ] ],
			// Two markers: the first REGISTERED provider owns the order, whichever marker was written first.
			[ 'markers' => [ 'gamma', 'alpha' ], 'raw' => 'A_GO', 'wc' => 'processing', 'at' => '2026-10-11 15:00:00', 'total' => 600, 'lines' => [ [ 'alpha_door', 60, 0 ] ] ],
			[ 'markers' => [ 'beta', 'gamma' ], 'raw' => 'B_DONE', 'wc' => 'processing', 'at' => '2026-10-12 12:00:00', 'total' => 650, 'lines' => [ [ 'beta_door', 65, 0 ] ] ],
			// No carrier owns it.
			[ 'markers' => [], 'raw' => null, 'wc' => 'processing', 'at' => '2026-10-11 13:00:00', 'total' => 9999, 'lines' => [ [ 'flat_rate', 5, 0 ] ] ],
		];
	}

	/**
	 * Creates the universe through WooCommerce's own API.
	 *
	 * @return void
	 */
	private function seed(): void {
		foreach ( $this->universe() as $spec ) {
			$order = wc_create_order();

			foreach ( $spec['markers'] as $provider_id ) {
				$order->update_meta_data( $this->providers[ $provider_id ]['marker'], '1' );
			}

			$owner = $this->owner( $spec['markers'] );

			if ( null !== $owner && null !== $spec['raw'] && null !== $this->providers[ $owner ]['status_key'] ) {
				$order->update_meta_data( $this->providers[ $owner ]['status_key'], $spec['raw'] );
			}

			if ( ! empty( $spec['cancelled'] ) ) {
				$order->update_meta_data( Shipment_Cancellation::CANCELLED_AT_META, '1790000000' );
			}

			$this->add_shipping_lines( $order, $spec['lines'] );

			$order->set_total( $spec['total'] );
			$order->set_status( $spec['wc'] );
			$order->set_date_created( $this->timestamp( $spec['at'] ) );

			if ( isset( $spec['completed'] ) ) {
				$order->set_date_completed( $this->timestamp( $spec['completed'] ) );
			}

			$order->save();

			$this->seeded[] = $spec + [ 'id' => $order->get_id() ];
		}
	}

	/**
	 * @param \WC_Order                        $order order.
	 * @param array<int,array{0:string,1:int,2:int}> $lines `[ method_id, cost, tax ]`.
	 * @return void
	 */
	private function add_shipping_lines( \WC_Order $order, array $lines ): void {
		foreach ( $lines as [ $method, $cost, $tax ] ) {
			$item = new \WC_Order_Item_Shipping();
			$item->set_method_id( $method );
			$item->set_method_title( $method );
			$item->set_total( $cost );
			$item->set_taxes( [ 'total' => [ 1 => $tax ] ] );
			$order->add_item( $item );
		}

		$order->set_shipping_total( array_sum( array_column( $lines, 1 ) ) );
		$order->set_shipping_tax( array_sum( array_column( $lines, 2 ) ) );
	}

	/**
	 * @param string $local a store-local `Y-m-d H:i:s`.
	 * @return int
	 */
	private function timestamp( string $local ): int {
		return ( new \DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// The oracle: everything below is computed from the specs, in PHP.
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * The orders in the population for a range, each with its owning provider and outcome bucket.
	 *
	 * @param string      $after  first day.
	 * @param string      $before last day.
	 * @param string|null $only   one provider id, or null.
	 * @return array<int,array<string,mixed>>
	 */
	private function population( string $after, string $before, ?string $only = null ): array {
		$from = $this->timestamp( $after . ' 00:00:00' );
		$to   = $this->timestamp( $before . ' 00:00:00' ) + DAY_IN_SECONDS; // exclusive; no daylight saving in Moscow.
		$rows = [];

		foreach ( $this->seeded as $spec ) {
			$at    = $this->timestamp( $spec['at'] );
			$owner = $this->owner( $spec['markers'] );

			if ( null === $owner || in_array( $spec['wc'], self::EXCLUDED, true ) || $at < $from || $at >= $to ) {
				continue;
			}

			if ( null !== $only && $only !== $owner ) {
				continue;
			}

			$rows[] = $spec + [
				'owner'  => $owner,
				'bucket' => $this->bucket( $owner, $spec ),
				'day'    => substr( $spec['at'], 0, 10 ),
			];
		}

		return $rows;
	}

	/**
	 * The first REGISTERED provider among an order's markers.
	 *
	 * @param string[] $markers provider ids.
	 * @return string|null
	 */
	private function owner( array $markers ): ?string {
		foreach ( array_keys( $this->providers ) as $id ) {
			if ( in_array( $id, $markers, true ) ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * @param string              $owner provider id.
	 * @param array<string,mixed> $spec  order spec.
	 * @return string
	 */
	private function bucket( string $owner, array $spec ): string {
		if ( ! empty( $spec['cancelled'] ) ) {
			return 'cancelled';
		}

		$status_key = $this->providers[ $owner ]['status_key'];
		$raw        = $spec['raw'];

		if ( null === $status_key || null === $raw || ! isset( $this->providers[ $owner ]['map'][ $raw ] ) ) {
			return 'unknown';
		}

		return self::BUCKET_OF[ $this->providers[ $owner ]['map'][ $raw ] ];
	}

	/**
	 * @param array<int,array<string,mixed>> $orders population.
	 * @return array<string,int|float>
	 */
	private function expected_carrier( array $orders ): array {
		$row = [
			'orders'        => 0,
			'revenue'       => 0.0,
			'shipping_paid' => 0.0,
			'in_progress'   => 0,
			'delivered'     => 0,
			'returned'      => 0,
			'failed'        => 0,
			'cancelled'     => 0,
			'unknown'       => 0,
			'time_sample'   => 0,
			'time_seconds'  => 0,
		];

		foreach ( $orders as $order ) {
			++$row['orders'];
			++$row[ $order['bucket'] ];
			$row['revenue']       += $order['total'];
			$row['shipping_paid'] += array_sum( array_column( $order['lines'], 1 ) ) + array_sum( array_column( $order['lines'], 2 ) );

			if ( 'delivered' === $order['bucket'] && isset( $order['completed'] ) ) {
				++$row['time_sample'];
				$row['time_seconds'] += $this->timestamp( $order['completed'] ) - $this->timestamp( $order['at'] );
			}
		}

		return $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $orders population.
	 * @return array<string,array<string,int|float>> `carrier|method` => orders, lines, revenue, shipping_paid.
	 */
	private function expected_methods( array $orders ): array {
		$methods = [];

		foreach ( $orders as $order ) {
			$seen = [];

			foreach ( $order['lines'] as [ $method, $cost, $tax ] ) {
				$key = $order['owner'] . '|' . $method;

				$methods[ $key ] = $methods[ $key ] ?? [
					'orders'        => 0,
					'lines'         => 0,
					'revenue'       => 0.0,
					'shipping_paid' => 0.0,
				];

				++$methods[ $key ]['lines'];
				$methods[ $key ]['shipping_paid'] += $cost + $tax;

				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					++$methods[ $key ]['orders'];
					$methods[ $key ]['revenue'] += $order['total'];
				}
			}
		}

		return $methods;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// The route.
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @param array<string,string> $params query.
	 * @return array<string,mixed> the response data (the test fails on anything but a 200).
	 */
	private function analytics( array $params = [] ): array {
		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders/analytics' );
		$request->set_query_params( $params + [ 'after' => self::AFTER, 'before' => self::BEFORE, 'interval' => 'day', 'refresh' => '1' ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'the analytics route must answer: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// The tests.
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @dataProvider datastore_provider
	 */
	public function test_the_carrier_table_equals_an_independent_count( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		$report = $this->analytics();

		$this->assertSame( [ 'alpha', 'beta', 'gamma' ], array_column( $report['carriers'], 'id' ), 'registry order, every carrier with orders' );

		foreach ( $report['carriers'] as $carrier ) {
			$expected = $this->expected_carrier( $this->population( self::AFTER, self::BEFORE, $carrier['id'] ) );
			$actual   = $carrier['current'];

			$this->assertSame( $expected['orders'], $actual['orders'], $carrier['id'] . ' orders' );
			$this->assertEqualsWithDelta( $expected['revenue'], $actual['revenue'], 0.005, $carrier['id'] . ' revenue' );
			$this->assertEqualsWithDelta( $expected['shipping_paid'], $actual['shipping_paid'], 0.005, $carrier['id'] . ' shipping paid' );

			foreach ( [ 'in_progress', 'delivered', 'returned', 'failed', 'cancelled', 'unknown' ] as $bucket ) {
				$this->assertSame( $expected[ $bucket ], $actual['outcomes'][ $bucket ], $carrier['id'] . ' ' . $bucket );
			}

			$this->assertSame( $expected['time_sample'], $actual['time_sample'], $carrier['id'] . ' time sample' );
		}
	}

	/**
	 * @dataProvider datastore_provider
	 */
	public function test_totals_and_the_time_metric_equal_an_independent_count( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		$report   = $this->analytics();
		$expected = $this->expected_carrier( $this->population( self::AFTER, self::BEFORE ) );
		$totals   = $report['totals']['current'];

		$this->assertSame( $expected['orders'], $totals['orders'] );
		$this->assertEqualsWithDelta( $expected['revenue'], $totals['revenue'], 0.005 );
		$this->assertEqualsWithDelta( $expected['shipping_paid'], $totals['shipping_paid'], 0.005 );
		$this->assertSame( $expected['delivered'] + $expected['returned'] + $expected['failed'], $totals['resolved'] );

		// One delivered order carries a completion date two days after it was created.
		$this->assertSame( 1, $totals['time_sample'] );
		$this->assertEqualsWithDelta( 2.0, $totals['avg_days_to_delivery'], 0.05 );
		$this->assertNull( $report['totals']['previous'] );
	}

	/**
	 * @dataProvider datastore_provider
	 */
	public function test_the_method_table_adds_each_order_once_per_method_and_equals_an_independent_count( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		$report   = $this->analytics();
		$expected = $this->expected_methods( $this->population( self::AFTER, self::BEFORE ) );
		$actual   = [];

		foreach ( $report['methods'] as $method ) {
			$actual[ $method['carrier_id'] . '|' . $method['method_id'] ] = $method;
		}

		$this->assertEqualsCanonicalizing( array_keys( $expected ), array_keys( $actual ), 'the same carrier × method rows' );

		foreach ( $expected as $key => $row ) {
			$this->assertSame( $row['orders'], $actual[ $key ]['orders'], $key . ' orders' );
			$this->assertSame( $row['lines'], $actual[ $key ]['lines'], $key . ' lines' );
			$this->assertEqualsWithDelta( $row['revenue'], $actual[ $key ]['revenue'], 0.005, $key . ' revenue' );
			$this->assertEqualsWithDelta( $row['shipping_paid'], $actual[ $key ]['shipping_paid'], 0.005, $key . ' shipping paid' );
		}

		// The order with two lines of one method: 3000 once, never 6000.
		$this->assertEqualsWithDelta( 3900.0, $actual['alpha|alpha_pickup']['revenue'], 0.005, 'alpha_pickup = 3000 (two lines, once) + 900 (one line)' );
		$this->assertSame( 3, $actual['alpha|alpha_pickup']['lines'] );
		$this->assertSame( 2, $actual['alpha|alpha_pickup']['orders'] );
	}

	/**
	 * @dataProvider datastore_provider
	 */
	public function test_the_chart_days_follow_the_site_timezone_and_equal_an_independent_count( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		$report     = $this->analytics();
		$population = $this->population( self::AFTER, self::BEFORE );

		$this->assertSame( [ '2026-10-10', '2026-10-11', '2026-10-12' ], array_column( $report['intervals'], 'start' ) );

		foreach ( $report['intervals'] as $interval ) {
			$day   = array_filter( $population, static fn( array $order ): bool => $order['day'] === $interval['start'] );
			$total = array_sum( array_column( $day, 'total' ) );

			$this->assertSame( count( $day ), $interval['totals']['orders'], $interval['start'] . ' orders' );
			$this->assertEqualsWithDelta( $total, $interval['totals']['revenue'], 0.005, $interval['start'] . ' revenue' );

			foreach ( [ 'alpha', 'beta', 'gamma' ] as $carrier ) {
				$mine = array_filter( $day, static fn( array $order ): bool => $order['owner'] === $carrier );

				$this->assertSame( count( $mine ), $interval['carriers'][ $carrier ]['orders'] ?? 0, $interval['start'] . ' ' . $carrier );
			}
		}
	}

	/**
	 * @dataProvider datastore_provider
	 */
	public function test_the_carrier_filter_keeps_first_registered_wins( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		foreach ( [ 'beta', 'gamma' ] as $only ) {
			$report   = $this->analytics( [ 'carrier' => $only ] );
			$expected = $this->expected_carrier( $this->population( self::AFTER, self::BEFORE, $only ) );

			$this->assertSame( [ $only ], array_column( $report['carriers'], 'id' ) );
			$this->assertSame( $expected['orders'], $report['totals']['current']['orders'], $only . ' orders' );
			$this->assertEqualsWithDelta( $expected['revenue'], $report['totals']['current']['revenue'], 0.005, $only . ' revenue' );
		}

		// gamma is registered last: the orders it shares with alpha or beta are theirs, so it keeps only its own.
		$this->assertSame( 1, $this->analytics( [ 'carrier' => 'gamma' ] )['totals']['current']['orders'] );
	}

	/**
	 * The previous period is read with its own window — here the single day AFTER the range, which holds exactly one order (alpha, 6000).
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_the_previous_period_is_its_own_window_and_deltas_follow( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->seed();

		$report  = $this->analytics( [ 'compare_after' => '2026-10-13', 'compare_before' => '2026-10-13' ] );
		$current = $report['totals']['current']['orders'];

		$this->assertSame( 1, $report['totals']['previous']['orders'] );
		$this->assertEqualsWithDelta( 6000.0, $report['totals']['previous']['revenue'], 0.005 );
		$this->assertEqualsWithDelta( round( ( $current - 1 ) * 100, 1 ), $report['totals']['delta']['orders'], 0.05 );
		$this->assertSame( [ '2026-10-13' ], array_column( $report['previous_intervals'], 'start' ) );
	}

	/**
	 * Spec §7: for the same range the analytics order count equals the orders list's `total` — scope all, then each carrier tab.
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_the_analytics_count_equals_the_orders_list_total_for_the_same_range( bool $hpos ): void {
		// The list's delivery-status filter reports (under WP_DEBUG) an order that carries two providers' markers; the universe has two on purpose.
		$this->setExpectedIncorrectUsage( 'Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Query::delivery_status_meta_clauses' );

		$this->use_datastore( $hpos );
		$this->seed();

		$report = $this->analytics();

		$this->assertSame( $report['totals']['current']['orders'], $this->list_total( 'all' ), 'all carriers' );

		$alpha = $this->analytics( [ 'carrier' => 'alpha' ] );
		$this->assertSame( $alpha['totals']['current']['orders'], $this->list_total( 'alpha' ), 'alpha, the first registered: its tab and its analytics agree' );
	}

	/**
	 * The orders list over the same range, with the statuses the analytics population holds.
	 *
	 * @param string $carrier `all` or a provider id.
	 * @return int
	 */
	private function list_total( string $carrier ): int {
		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_query_params(
			[
				'carrier'  => $carrier,
				'after'    => self::AFTER,
				'before'   => self::BEFORE,
				'status'   => [ 'wc-processing', 'wc-on-hold', 'wc-completed' ],
				'per_page' => 100,
			]
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		return (int) $response->get_data()['total'];
	}
}
