<?php
/**
 * Integration: the REAL SQL of {@see Orders_Id_Resolver} against REAL orders on BOTH
 * WooCommerce order datastores — HPOS and the legacy CPT — in one run (#936).
 *
 * Why this file exists. The unit tier cannot see the compiled SQL: `ShippingOrdersQueryRowSemanticsTest`
 * substitutes the `resolve_order_ids()` seam with an in-memory walk of the tree, and
 * `ShippingOrdersIdResolverTest` only pins the statement's SHAPE. So the `$marker_keys`
 * driver, the folding of the scope part into it, every `EXISTS` / `NOT EXISTS` leaf and the
 * order-status leaf (`wc_orders.status` vs `posts.post_status`) were never executed against
 * a database by a gate. Here they are: a small universe of orders is seeded through the
 * WooCommerce API, a space of requests is run through the real `Orders_Query` →
 * `Orders_Id_Resolver` seam, and the id set that comes back is compared with an oracle
 * computed in PHP from the seeded data — never from the query's own output.
 *
 * How both datastores run in one process. WooCommerce picks the datastore per request from
 * the `woocommerce_custom_orders_table_enabled` option: `WC_Data_Store::load( 'order' )`
 * applies the `woocommerce_order_data_store` filter every time an order object is built,
 * and `OrderUtil::custom_orders_table_usage_is_enabled()` (what
 * `Woodev_Plugin_Compatibility::is_hpos_enabled()` and so `Orders_Query` read) reads the
 * option on every call. Flipping the option (data sync off, so each order lives in exactly
 * one datastore) therefore swaps the datastore cleanly with no reboot; the data provider
 * runs the whole test once per datastore and {@see self::use_datastore()} PROVES the flip
 * took by locating each seeded order in the table it was meant for. The option is rolled
 * back with the test's transaction. Both datastores' tables exist in the test database
 * already (WooCommerce creates them on install), so nothing DDL-shaped runs here.
 *
 * The oracle is written from the carriers' definitions and the order's own data, the same
 * specification the unit oracle uses (`ShippingOrdersQueryRowSemanticsTest`) — narrowed to
 * the orders a real product can carry (at most one marker). Mutation-checked: dropping the
 * order-status leaf's `IN`, or swapping an `EXISTS` for a `NOT EXISTS`, turns it red.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Tests\Integration\TestCase;

class OrdersIdResolverDatastoresTest extends TestCase {

	private const M1_MARKER   = '_woodev_test_m1_marker';
	private const M1_STATUS   = '_woodev_test_m1_status';
	private const M1_TRACKING = '_woodev_test_m1_tracking';
	private const M1_PICKUP   = '_woodev_test_m1_pickup';
	private const B1_MARKER   = '_woodev_test_b1_marker';

	/** Every N-th request also runs through a real `wc_get_orders()` (native status arg, sentinel path). */
	private const END_TO_END_STRIDE = 12;

	/** @var bool the datastore this run seeded into. */
	private $hpos = false;

	/**
	 * One mapped carrier with every concept an advanced filter can ask about (status map,
	 * tracking key, pickup-point key) and one bare carrier without any.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create(
				'm1',
				'M1',
				self::M1_MARKER,
				[ 'm1' ],
				[
					'status_meta_key'       => self::M1_STATUS,
					'status_map'            => [
						'M1_GO'   => Delivery_Status::IN_TRANSIT,
						'M1_DONE' => Delivery_Status::DELIVERED,
					],
					'tracking_meta_key'     => self::M1_TRACKING,
					'pickup_point_meta_key' => self::M1_PICKUP,
				]
			)
		);
		$registry->register_provider( Orders_Provider::create( 'b1', 'B1', self::B1_MARKER, [ 'b1' ] ) );
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
	 * Selects the datastore for everything created and queried after this call, with data
	 * sync off so an order lives in exactly one of them.
	 *
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->hpos = $hpos;

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * Every order over the two carriers that carries AT MOST ONE marker (operator decision
	 * 26.09.2026, #928): the mapped carrier's marker x delivery status (absent / in transit
	 * / delivered / one nothing maps) x tracking x pickup point x order status
	 * (pending / processing / cancelled) — impossible combinations included on purpose —
	 * then the bare carrier's marker across four order statuses (failed too), two failed
	 * mapped-carrier orders, and orders with NO marker: some carrying the mapped carrier's
	 * own keys (delivery status / tracking / pickup) without its marker, which must never
	 * leave the scope, and a plain one.
	 *
	 * @return array<int,array{marker:?string,delivery:?string,tracking:bool,pickup:bool,order_status:string}>
	 */
	private function universe(): array {
		$rows = [];

		foreach ( [ null, 'M1_GO', 'M1_DONE', 'JUNK' ] as $delivery ) {
			foreach ( [ false, true ] as $tracking ) {
				foreach ( [ false, true ] as $pickup ) {
					foreach ( [ 'wc-pending', 'wc-processing', 'wc-cancelled' ] as $order_status ) {
						$rows[] = compact( 'delivery', 'tracking', 'pickup', 'order_status' ) + [ 'marker' => 'm1' ];
					}
				}
			}
		}

		foreach ( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ] as $order_status ) {
			$rows[] = [
				'marker'       => 'b1',
				'delivery'     => null,
				'tracking'     => false,
				'pickup'       => false,
				'order_status' => $order_status,
			];
		}

		$rows[] = [
			'marker'       => 'm1',
			'delivery'     => 'M1_GO',
			'tracking'     => true,
			'pickup'       => false,
			'order_status' => 'wc-failed',
		];
		$rows[] = [
			'marker'       => 'm1',
			'delivery'     => null,
			'tracking'     => false,
			'pickup'       => true,
			'order_status' => 'wc-failed',
		];

		$rows[] = [
			'marker'       => null,
			'delivery'     => 'M1_GO',
			'tracking'     => true,
			'pickup'       => true,
			'order_status' => 'wc-processing',
		];
		$rows[] = [
			'marker'       => null,
			'delivery'     => 'JUNK',
			'tracking'     => false,
			'pickup'       => true,
			'order_status' => 'wc-pending',
		];
		$rows[] = [
			'marker'       => null,
			'delivery'     => null,
			'tracking'     => false,
			'pickup'       => false,
			'order_status' => 'wc-processing',
		];

		return $rows;
	}

	/**
	 * Creates the universe through the WooCommerce API on the selected datastore.
	 *
	 * @return array<int,array<string,mixed>> the seeded rows keyed by REAL order id.
	 */
	private function seed(): array {
		global $wpdb;

		$seeded = [];

		foreach ( $this->universe() as $row ) {
			$order = wc_create_order();
			$order->set_status( substr( $row['order_status'], 3 ) );

			if ( null !== $row['marker'] ) {
				$order->update_meta_data( 'm1' === $row['marker'] ? self::M1_MARKER : self::B1_MARKER, '1' );
			}

			if ( null !== $row['delivery'] ) {
				$order->update_meta_data( self::M1_STATUS, $row['delivery'] );
			}

			if ( $row['tracking'] ) {
				$order->update_meta_data( self::M1_TRACKING, 'T1' );
			}

			if ( $row['pickup'] ) {
				$order->update_meta_data( self::M1_PICKUP, 'P1' );
			}

			$order->save();

			$seeded[ $order->get_id() ] = $row;
		}

		// The flip must have REALLY put the orders in the datastore under test: every one
		// of them is in that datastore's order table and absent from the other's.
		$in_hpos = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wc_orders" ) );
		$in_cpt  = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order'" ) );
		$ids     = array_keys( $seeded );

		if ( $this->hpos ) {
			$this->assertSame( [], array_diff( $ids, $in_hpos ), 'HPOS: every seeded order is in wc_orders' );
			$this->assertSame( [], array_intersect( $ids, $in_cpt ), 'HPOS: no seeded order is a shop_order post' );
		} else {
			$this->assertSame( [], array_diff( $ids, $in_cpt ), 'CPT: every seeded order is a shop_order post' );
			$this->assertSame( [], array_intersect( $ids, $in_hpos ), 'CPT: no seeded order is in wc_orders' );
		}

		return $seeded;
	}

	/**
	 * The request space: `match` (all / any) x delivery status (none / is unknown / is in
	 * transit / is not in transit) x tracking (none / yes / no) x pickup point (none / yes /
	 * no) x order status (none / is / is several / is not / is nothing real) x carrier
	 * (aggregate / the mapped one). `per_page` is wide enough for the whole universe.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function requests(): array {
		$deliveries = [
			[],
			[ 'delivery_status' => Delivery_Status::UNKNOWN ],
			[ 'delivery_status' => Delivery_Status::IN_TRANSIT ],
			[ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ],
		];
		$trackings  = [ [], [ 'has_tracking' => true ], [ 'has_tracking' => false ] ];
		$pickups    = [ [], [ 'has_pickup_point' => true ], [ 'has_pickup_point' => false ] ];
		$statuses   = [
			[],
			[ 'status' => [ 'processing' ] ],
			[ 'status' => [ 'wc-pending', 'cancelled' ] ],
			[ 'status_not' => [ 'processing' ] ],
			[ 'status' => [ 'nonsense' ] ],
		];
		$carriers   = [ [], [ 'carrier' => 'm1' ] ];

		$requests = [];

		foreach ( [ 'all', 'any' ] as $match ) {
			foreach ( $carriers as $carrier ) {
				foreach ( $deliveries as $delivery ) {
					foreach ( $trackings as $tracking ) {
						foreach ( $pickups as $pickup ) {
							foreach ( $statuses as $status ) {
								$requests[] = array_merge( [ 'match' => $match, 'per_page' => 100 ], $carrier, $delivery, $tracking, $pickup, $status );
							}
						}
					}
				}
			}
		}

		return $requests;
	}

	/**
	 * Whether the mapped carrier's own delivery-status condition holds (#836 / #837 / #839).
	 *
	 * @param array<string,mixed> $row       one universe row.
	 * @param string              $canonical `unknown` or `in_transit` (the only ones requested).
	 * @param bool                $negate    true => "is not".
	 */
	private function m1_delivery_holds( array $row, string $canonical, bool $negate ): bool {
		$marker   = 'm1' === $row['marker'];
		$present  = null !== $row['delivery'];
		$mapped   = in_array( $row['delivery'], [ 'M1_GO', 'M1_DONE' ], true );
		$in_trans = 'M1_GO' === $row['delivery'];

		if ( Delivery_Status::UNKNOWN === $canonical ) {
			// A mapped carrier is unknown when its marker is there and the status is absent or maps to nothing.
			return $negate ? $mapped : ( $marker && ( ! $present || ! $mapped ) );
		}

		return $negate ? ( $marker && ! $in_trans ) : $in_trans;
	}

	/**
	 * The oracle: whether one seeded order belongs to the page a request asks for. An order
	 * is selected iff it is in scope (carries the marker of a carrier the request covers)
	 * AND satisfies the advanced filters — all of them under `all`, at least one under
	 * `any` when two or more were asked for — AND, when no order-status filter was asked,
	 * is in the default view (not cancelled / failed).
	 *
	 * @param array<string,mixed> $request the request.
	 * @param array<string,mixed> $row     one universe row.
	 */
	private function oracle_selects( array $request, array $row ): bool {
		// The aggregate covers both carriers; `carrier=m1` only the mapped one.
		$covers_b1 = ! isset( $request['carrier'] );

		$in_scope = 'm1' === $row['marker'] || ( 'b1' === $row['marker'] && $covers_b1 );

		if ( ! $in_scope ) {
			return false;
		}

		$filters   = [];
		$canonical = $request['delivery_status'] ?? $request['delivery_status_not'] ?? null;

		if ( null !== $canonical ) {
			$negate = ! isset( $request['delivery_status'] );

			// A bare carrier is ALWAYS unknown: `is unknown` holds, `is not unknown` never, and `is not <other>` always.
			$b1_holds = $covers_b1 && 'b1' === $row['marker']
				&& ( Delivery_Status::UNKNOWN === $canonical ? ! $negate : $negate );

			$filters[] = $b1_holds || ( 'm1' === $row['marker'] && $this->m1_delivery_holds( $row, $canonical, $negate ) );
		}

		foreach ( [ 'has_tracking' => 'tracking', 'has_pickup_point' => 'pickup' ] as $arg => $field ) {
			if ( ! array_key_exists( $arg, $request ) ) {
				continue;
			}

			$wanted = (bool) $request[ $arg ];

			// The bare carrier has no such key: "without" holds for all its orders, "with" for none.
			$filters[] = 'b1' === $row['marker']
				? ! $wanted
				: ( $wanted ? $row[ $field ] : ! $row[ $field ] );
		}

		$valid     = array_keys( wc_get_order_statuses() );
		$normalise = static function ( array $values ): array {
			return array_map(
				static function ( string $value ): string {
					return 0 === strpos( $value, 'wc-' ) ? $value : 'wc-' . $value;
				},
				$values
			);
		};

		if ( isset( $request['status'] ) ) {
			$filters[] = in_array( $row['order_status'], array_intersect( $normalise( $request['status'] ), $valid ), true );
		} elseif ( isset( $request['status_not'] ) ) {
			$filters[] = in_array( $row['order_status'], array_diff( $valid, $normalise( $request['status_not'] ) ), true );
		}

		if ( ! isset( $request['status'] ) && ! isset( $request['status_not'] ) && in_array( $row['order_status'], [ 'wc-cancelled', 'wc-failed' ], true ) ) {
			return false;
		}

		$is_any = 'any' === $request['match'] && count( $filters ) >= 2;

		return $is_any ? in_array( true, $filters, true ) : ! in_array( false, $filters, true );
	}

	/**
	 * The ids a request selects through the REAL seam: what `Orders_Id_Resolver` returned
	 * (`post__in`, or none => the sentinel), narrowed by the native `status` arg exactly as
	 * `wc_get_orders()` narrows it after `post__in`.
	 *
	 * @param array<string,mixed>             $args   `build_args()` of the request.
	 * @param array<int,array<string,mixed>>  $seeded the seeded rows by id.
	 * @return int[] sorted.
	 */
	private function resolver_ids( array $args, array $seeded ): array {
		$ids = array_key_exists( 'post__in', $args ) ? array_map( 'intval', $args['post__in'] ) : [];

		$this->assertSame( [], array_diff( $ids, array_keys( $seeded ) ), 'the resolver returned an id that is not a seeded order' );

		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $seeded, $args ): bool {
					return in_array( $seeded[ $id ]['order_status'], $args['status'], true );
				}
			)
		);

		sort( $ids );

		return $ids;
	}

	/**
	 * @dataProvider datastore_provider
	 *
	 * @param bool $hpos which datastore this run seeds and queries.
	 * @return void
	 */
	public function test_the_resolver_selects_exactly_the_oracles_orders_on_the_datastore( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$seeded   = $this->seed();
		$query    = new Orders_Query( Orders_Registry::instance() );
		$requests = $this->requests();

		$this->assertCount( 720, $requests );
		$this->assertCount( 57, $seeded );

		$mismatches = [];
		$non_empty  = 0;
		$narrowing  = 0;
		$differing  = 0;
		$strided    = 0;

		foreach ( $requests as $index => $request ) {
			$expected = [];

			foreach ( $seeded as $id => $row ) {
				if ( $this->oracle_selects( $request, $row ) ) {
					$expected[] = $id;
				}
			}

			sort( $expected );

			$args   = $query->build_args( $request );
			$actual = $this->resolver_ids( $args, $seeded );

			if ( $expected !== $actual ) {
				$mismatches[] = sprintf( "request %s\n  expected %s\n  actual   %s", (string) wp_json_encode( $request ), implode( ',', $expected ), implode( ',', $actual ) );
			}

			$non_empty += [] === $expected ? 0 : 1;
			$narrowing += ( [] !== $expected && count( $expected ) < count( $seeded ) ) ? 1 : 0;

			if ( 'any' === $request['match'] ) {
				$as_all = $this->resolver_ids( $query->build_args( array_merge( $request, [ 'match' => 'all' ] ) ), $seeded );

				$differing += $as_all === $actual ? 0 : 1;
			}

			// A slice of the space also goes through the real `wc_get_orders()` — the native
			// `status` arg and the «matches nothing» sentinel of THIS datastore included.
			if ( 0 === $index % self::END_TO_END_STRIDE ) {
				++$strided;

				$result = wc_get_orders( $args );
				$found  = array_map(
					static function ( \WC_Order $order ): int {
						return (int) $order->get_id();
					},
					$result->orders
				);

				sort( $found );

				if ( $expected !== $found ) {
					$mismatches[] = sprintf( "wc_get_orders() for request %s\n  expected %s\n  actual   %s", (string) wp_json_encode( $request ), implode( ',', $expected ), implode( ',', $found ) );
				}
			}
		}

		$this->assertSame(
			[],
			array_slice( $mismatches, 0, 5 ),
			sprintf( "%d of %d requests select different orders than the oracle on %s (first shown).", count( $mismatches ), count( $requests ) + $strided, $hpos ? 'HPOS' : 'legacy CPT' )
		);

		// The gate must not pass vacuously: most requests select something, most of those
		// something-but-not-everything, and «Любое» must differ from «Все» over much of the space.
		$this->assertGreaterThan( 500, $non_empty );
		$this->assertGreaterThan( 400, $narrowing );
		$this->assertGreaterThan( 100, $differing );
	}
}
