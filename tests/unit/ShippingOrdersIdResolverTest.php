<?php
/**
 * Unit: the SHAPE of the id query {@see Orders_Id_Resolver} compiles, per datastore (#928).
 *
 * The reason this file exists is the `d^N` measurement in
 * `docs-internal/research/2026-09-26-928-form-measurement/`: handed to the datastore as a
 * `meta_query`, every OR-ed marker `EXISTS` clause becomes its own JOIN on the meta table
 * with NO key in its `ON` (`ON order = id`; the `meta_key` test sits in `WHERE`), so an
 * order with `d` meta rows costs `~d^N` row combinations for `N` carriers — 11.7 s per
 * 10 000 orders on the UNFILTERED page at four carriers. The id query must therefore
 * carry no join at all, and every subquery it carries must be predicated on the order
 * id AND the meta key AND, where the clause has one, the value. That is what is pinned
 * here, on the real SQL text, without a database:
 *
 *   - the whole statement, byte for byte, for the smallest real case per datastore;
 *   - the structural law for the s128 fixture (four carriers, `delivery_status=unknown`):
 *     zero `JOIN`, one correlated subquery per leaf, every one of them keyed;
 *   - the scope part is folded into the driver predicate rather than emitted again;
 *   - what the compiler refuses, so a new clause shape cannot be compiled by accident.
 *
 * ⚠ What this file does NOT prove is that the SQL returns the rows the tree means — the
 * unit tier has no database. That equivalence is proven one level up by
 * `ShippingOrdersQueryRowSemanticsTest` (the tree against an independent oracle, through
 * the same resolver seam) and was measured at database level by the research probe.
 *
 * @package Woodev\Tests\Unit
 * @since 2.0.2
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Id_Resolver;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

/**
 * @since 2.0.2
 */
class ShippingOrdersIdResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'wc_get_order_statuses' )->justReturn( [ 'wc-processing' => 'Processing' ] );
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( $value ): bool {
				return is_bool( $value ) ? $value : ( 'yes' === $value || 1 === $value || 'true' === $value || '1' === $value );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/** The same carriers the join-growth gate registers: $mapped with a status map, $bare without. */
	private function registry_of( int $mapped, int $bare ): Orders_Registry {
		$registry = Orders_Registry::instance();

		for ( $i = 1; $i <= $mapped; $i++ ) {
			$registry->register_provider(
				Orders_Provider::create(
					"mapped{$i}",
					"Mapped {$i}",
					"_mapped{$i}_marker",
					[ "mapped{$i}" ],
					[
						'status_meta_key' => "_mapped{$i}_status",
						'status_map'      => [
							"M{$i}_ACCEPTED" => Delivery_Status::IN_TRANSIT,
							"M{$i}_DONE"     => Delivery_Status::DELIVERED,
						],
					]
				)
			);
		}

		for ( $i = 1; $i <= $bare; $i++ ) {
			$registry->register_provider(
				Orders_Provider::create( "bare{$i}", "Bare {$i}", "_bare{$i}_marker", [ "bare{$i}" ] )
			);
		}

		return $registry;
	}

	/** The marker keys {@see Orders_Query::build_scope()} would hand the resolver for the aggregate. */
	private function marker_keys( Orders_Registry $registry ): array {
		return array_map(
			static function ( Orders_Provider $provider ): string {
				return $provider->get_marker_meta_key();
			},
			array_values( $registry->get_providers() )
		);
	}

	/** Compiles the aggregate's id query for $request on the given datastore. */
	private function sql_for( Orders_Registry $registry, array $request, bool $hpos ): string {
		$tree = ( new Orders_Query( $registry ) )->build_meta_query( $request );

		return ( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), $hpos ) )->compile( $this->marker_keys( $registry ), $tree );
	}

	private function subquery_count( string $sql ): int {
		return preg_match_all( '/EXISTS \(SELECT 1 FROM /', $sql );
	}

	// ----- the statement, byte for byte -----

	/**
	 * The smallest real filter, HPOS: one mapped carrier, `delivery_status=unknown`. The
	 * driver is the marker key on `wc_orders_meta.order_id`; the scope part is NOT emitted
	 * again; the status pair is two subqueries, each keyed on the driver's order id and
	 * the status key, the `NOT IN` one carrying its values; the whole is ANDed with the one
	 * `NOT EXISTS` on the framework's cancellation marker (#1037).
	 */
	public function test_hpos_unknown_for_one_mapped_carrier_compiles_to_the_pinned_statement(): void {
		$registry = $this->registry_of( 1, 0 );

		$this->assertSame(
			'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\')'
			. ' AND ((EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_marker\')'
			. ' AND (NOT EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_status\')'
			. ' OR EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_status\''
			. ' AND m.meta_value NOT IN (\'M1_ACCEPTED\',\'M1_DONE\'))))'
			. ' AND NOT EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_woodev_shipment_cancelled_at\'))',
			$this->sql_for( $registry, [ 'delivery_status' => Delivery_Status::UNKNOWN ], true )
		);
	}

	/**
	 * The same tree on the legacy CPT datastore differs ONLY in the table and its id
	 * column — `postmeta.post_id` — because the tree is identical on both.
	 */
	public function test_legacy_cpt_compiles_the_same_statement_against_postmeta(): void {
		$registry = $this->registry_of( 1, 0 );
		$request  = [ 'delivery_status' => Delivery_Status::UNKNOWN ];

		$hpos = $this->sql_for( $registry, $request, true );
		$cpt  = $this->sql_for( $registry, $request, false );

		$this->assertStringStartsWith( 'SELECT DISTINCT mk.post_id FROM wp_postmeta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\')', $cpt );
		$this->assertSame(
			$cpt,
			str_replace( [ 'wp_wc_orders_meta', 'order_id' ], [ 'wp_postmeta', 'post_id' ], $hpos )
		);
	}

	/** No filter at all: the driver alone — the unfiltered page costs one index range, not `N` joins. */
	public function test_no_filter_is_the_driver_alone(): void {
		$registry = $this->registry_of( 2, 2 );

		$this->assertSame(
			'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\',\'_mapped2_marker\',\'_bare1_marker\',\'_bare2_marker\')',
			$this->sql_for( $registry, [], true )
		);
	}

	// ----- the structural law: zero joins, one keyed subquery per leaf -----

	/**
	 * The s128 fixture — four carriers, two of each kind, `delivery_status=unknown` —
	 * that sat in MySQL `Sending data` for over four minutes as twelve joins: no join
	 * at all now, eight correlated subqueries (`3M + B`), every one of them predicated
	 * on the driver's order id AND a meta key.
	 */
	public function test_the_s128_fixture_compiles_to_zero_joins_and_eight_keyed_subqueries(): void {
		$registry = $this->registry_of( 2, 2 );
		$sql      = $this->sql_for( $registry, [ 'delivery_status' => Delivery_Status::UNKNOWN ], true );

		$this->assertSame( 0, preg_match_all( '/\bJOIN\b/i', $sql ), "The id query must never join: {$sql}" );
		$this->assertSame( 9, $this->subquery_count( $sql ) );
		$this->assertSame(
			9,
			preg_match_all( '/SELECT 1 FROM wp_wc_orders_meta AS m WHERE m\.order_id = mk\.order_id AND m\.meta_key = \'_(?:[a-z0-9]+_(?:marker|status)|woodev_shipment_cancelled_at)\'/', $sql ),
			'every subquery is keyed on the order id and a meta key — never an un-predicated scan'
		);
		$this->assertSame( 2, preg_match_all( '/m\.meta_value NOT IN \(\'M[12]_ACCEPTED\',\'M[12]_DONE\'\)/', $sql ), 'the NOT IN subqueries carry their values' );
	}

	/**
	 * The `d^N` term is gone: the number of subqueries is linear in the carrier count,
	 * `3M + B + 1` for `unknown`, and there is still not one JOIN at six carriers.
	 */
	public function test_subqueries_grow_linearly_and_joins_stay_at_zero(): void {
		foreach ( range( 1, 6 ) as $n ) {
			Orders_Registry::instance()->reset_for_tests();
			$registry = $this->registry_of( $n, 0 );
			$sql      = $this->sql_for( $registry, [ 'delivery_status' => Delivery_Status::UNKNOWN ], true );

			$this->assertSame( 0, preg_match_all( '/\bJOIN\b/i', $sql ), "no JOIN at N={$n}" );
			$this->assertSame( ( 3 * $n ) + 1, $this->subquery_count( $sql ), "3M + B + 1 subqueries at N={$n}" );
		}
	}

	/**
	 * The scope part is folded into the driver predicate under the `AND` root; the
	 * marker `EXISTS` subqueries that remain are the BINDINGS inside each provider's
	 * clause, one per mapped carrier, not the scope emitted a second time.
	 */
	public function test_the_scope_part_is_the_driver_and_is_not_emitted_again(): void {
		$registry = $this->registry_of( 2, 1 );
		$sql      = $this->sql_for( $registry, [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ], true );

		$this->assertStringStartsWith( 'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\',\'_mapped2_marker\',\'_bare1_marker\') AND (', $sql );
		// `not in_transit`: mapped => AND( marker, OR( NOT EXISTS, NOT IN ) ) = 3 leaves; bare => marker EXISTS = 1; plus the cancellation leaf (#1037).
		$this->assertSame( 8, $this->subquery_count( $sql ) );
		$this->assertSame( 3, preg_match_all( '/EXISTS \(SELECT 1 FROM wp_wc_orders_meta AS m WHERE m\.order_id = mk\.order_id AND m\.meta_key = \'_[a-z0-9]+_marker\'\)/', $sql ), 'one marker subquery per provider clause — the bindings, not a second scope' );
	}

	/**
	 * When every carrier in scope lacks a tracking concept, the `has_tracking=false` filter
	 * part is byte for byte the scope part — each provider's "never has a tracking number" is
	 * its marker `EXISTS` — and folds into the driver as well: the filter IS the scope.
	 * (The delivery-status filters no longer fold since #1037 — they carry the cancellation leaf.)
	 */
	public function test_a_filter_part_identical_to_the_scope_folds_into_the_driver_too(): void {
		$registry = $this->registry_of( 0, 2 );

		$this->assertSame(
			'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_bare1_marker\',\'_bare2_marker\')',
			$this->sql_for( $registry, [ 'has_tracking' => false ], true )
		);
	}

	/**
	 * Under an `OR` root the scope is NOT dropped — dropping a disjunct would change
	 * the meaning. `compile()` only folds it under an `AND` root or when the tree IS
	 * the scope. (The builder never produces an OR root with the scope inside; this pins
	 * the compiler's own rule.)
	 */
	public function test_the_scope_is_not_folded_under_an_or_root(): void {
		$scope = Orders_Query::meta_query_for_keys( [ '_a_marker' ] );
		$tree  = [
			'relation' => 'OR',
			$scope,
			[
				'key'     => '_a_tracking',
				'compare' => 'EXISTS',
			],
		];

		$sql = ( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true ) )->compile( [ '_a_marker' ], $tree );

		$this->assertSame( 2, $this->subquery_count( $sql ) );
		$this->assertStringContainsString( "m.meta_key = '_a_marker') OR EXISTS", $sql );
	}

	// ----- every compare the builder emits, and nothing else -----

	/**
	 * `=` / `!=` (the export filter, #860) and `IN` (positive delivery status) each
	 * become an `EXISTS` keyed on the order id, the key and the value, with WP_Meta_Query's
	 * row semantics: a missing row never satisfies `!=` or `NOT IN`.
	 */
	public function test_value_compares_are_keyed_on_key_and_value(): void {
		$resolver = new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true );
		$keys     = [ '_a_marker' ];

		$exported = $resolver->compile(
			$keys,
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( $keys ),
				[
					[
						'key'     => '_a_carrier_order_id',
						'value'   => '',
						'compare' => '!=',
					],
				],
			]
		);
		$this->assertStringEndsWith( "AND EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = '_a_carrier_order_id' AND m.meta_value != '')", $exported );

		$in = $resolver->compile(
			$keys,
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( $keys ),
				[
					[
						'key'     => '_a_status',
						'value'   => [ 'GO', "O'HARE" ],
						'compare' => 'IN',
					],
				],
			]
		);
		$this->assertStringEndsWith( "m.meta_key = '_a_status' AND m.meta_value IN ('GO','O\\'HARE'))", $in, 'every value goes through $wpdb->prepare()' );
	}

	public function test_an_unhandled_compare_is_refused_not_guessed(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "unhandled compare 'LIKE'" );

		( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true ) )->compile(
			[ '_a_marker' ],
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( [ '_a_marker' ] ),
				[
					[
						'key'     => '_a_status',
						'value'   => 'x%',
						'compare' => 'LIKE',
					],
				],
			]
		);
	}

	public function test_an_in_with_no_values_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true ) )->compile(
			[ '_a_marker' ],
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( [ '_a_marker' ] ),
				[
					[
						'key'     => '_a_status',
						'value'   => [],
						'compare' => 'IN',
					],
				],
			]
		);
	}

	public function test_an_empty_scope_is_refused_by_compile_and_answered_empty_by_resolve(): void {
		$wpdb     = new OrdersIdResolverFakeWpdb( [ 1, 2 ] );
		$resolver = new Orders_Id_Resolver( $wpdb, true );

		$this->assertSame( [], $resolver->resolve( [], Orders_Query::NO_MATCH_META_QUERY ) );
		$this->assertSame( [], $wpdb->queries, 'nothing is asked of the database for an empty scope' );

		$this->expectException( \InvalidArgumentException::class );
		$resolver->compile( [], Orders_Query::NO_MATCH_META_QUERY );
	}

	// ----- the order-status leaf (#843, «Любое») -----

	/** The aggregate's id query for a `match=any` request of delivery status `unknown` plus order status `wc-processing`. */
	private function any_sql( bool $hpos, array $extra = [] ): string {
		return $this->sql_for(
			$this->registry_of( 1, 0 ),
			array_merge(
				[
					'match'           => 'any',
					'delivery_status' => Delivery_Status::UNKNOWN,
					'status'          => [ 'processing' ],
				],
				$extra
			),
			$hpos
		);
	}

	/**
	 * HPOS: the order status is one more disjunct of the OR, compiled against
	 * `wc_orders.status`, correlated on the driver's order id — an index lookup by primary
	 * key per driver row, like every meta leaf; the meta leaves keep their shape untouched.
	 */
	public function test_hpos_the_order_status_leaf_compiles_against_the_wc_orders_status_column(): void {
		$this->assertSame(
			'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\')'
			. ' AND (((EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_marker\')'
			. ' AND (NOT EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_status\')'
			. ' OR EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_mapped1_status\''
			. ' AND m.meta_value NOT IN (\'M1_ACCEPTED\',\'M1_DONE\'))))'
			. ' AND NOT EXISTS (SELECT 1 FROM wp_wc_orders_meta AS m WHERE m.order_id = mk.order_id AND m.meta_key = \'_woodev_shipment_cancelled_at\'))'
			. ' OR EXISTS (SELECT 1 FROM wp_wc_orders AS o WHERE o.id = mk.order_id AND o.status IN (\'wc-processing\')))',
			$this->any_sql( true )
		);
	}

	/** Legacy CPT: the same leaf against `posts.post_status`, correlated on `posts.ID = postmeta.post_id`. */
	public function test_cpt_the_order_status_leaf_compiles_against_the_posts_post_status_column(): void {
		$sql = $this->any_sql( false );

		$this->assertStringStartsWith( 'SELECT DISTINCT mk.post_id FROM wp_postmeta AS mk WHERE mk.meta_key IN (\'_mapped1_marker\') AND ((', $sql );
		$this->assertStringEndsWith(
			' OR EXISTS (SELECT 1 FROM wp_posts AS o WHERE o.ID = mk.post_id AND o.post_status IN (\'wc-processing\')))',
			$sql
		);
		$this->assertStringNotContainsString( 'wc_orders', $sql, 'the CPT statement never touches the HPOS tables' );
	}

	/** The rest of the statement is the meta-only one, on each datastore: only the table names differ. */
	public function test_the_meta_leaves_around_the_order_status_leaf_are_unchanged(): void {
		$hpos = $this->any_sql( true );
		$cpt  = $this->any_sql( false );

		$this->assertSame(
			$cpt,
			str_replace(
				[ 'wp_wc_orders_meta', 'order_id', 'wp_wc_orders AS o', 'o.id', 'o.status' ],
				[ 'wp_postmeta', 'post_id', 'wp_posts AS o', 'o.ID', 'o.post_status' ],
				$hpos
			)
		);
	}

	/** Several statuses → one `IN`, every value through `$wpdb->prepare()`; the leaf is one keyed subquery, never a join. */
	public function test_the_order_status_leaf_takes_a_list_quotes_it_and_adds_one_subquery_and_no_join(): void {
		$resolver = new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true );
		$keys     = [ '_a_marker' ];
		$sql      = $resolver->compile(
			$keys,
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( $keys ),
				[
					'relation' => 'OR',
					[
						[
							'key'     => '_a_tracking',
							'compare' => 'EXISTS',
						],
					],
					[ [ Orders_Id_Resolver::ORDER_STATUS_LEAF => [ 'wc-on-hold', "wc-o'hare" ] ] ],
				],
			]
		);

		$this->assertSame( 0, preg_match_all( '/\bJOIN\b/i', $sql ) );
		$this->assertSame( 2, $this->subquery_count( $sql ), 'one for the tracking leaf, one for the status leaf' );
		$this->assertStringEndsWith( "o.status IN ('wc-on-hold','wc-o\\'hare')))", $sql );
	}

	/** A single status leaf as the whole filter part is not wrapped in an `OR` of one. */
	public function test_an_order_status_leaf_alone_under_the_and_root_is_a_plain_conjunct(): void {
		$resolver = new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true );
		$keys     = [ '_a_marker' ];

		$this->assertSame(
			'SELECT DISTINCT mk.order_id FROM wp_wc_orders_meta AS mk WHERE mk.meta_key IN (\'_a_marker\')'
			. ' AND EXISTS (SELECT 1 FROM wp_wc_orders AS o WHERE o.id = mk.order_id AND o.status IN (\'wc-pending\'))',
			$resolver->compile(
				$keys,
				[
					'relation' => 'AND',
					Orders_Query::meta_query_for_keys( $keys ),
					[ [ Orders_Id_Resolver::ORDER_STATUS_LEAF => [ 'wc-pending' ] ] ],
				]
			)
		);
	}

	public function test_an_order_status_leaf_with_no_statuses_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), true ) )->compile(
			[ '_a_marker' ],
			[
				'relation' => 'AND',
				Orders_Query::meta_query_for_keys( [ '_a_marker' ] ),
				[ [ Orders_Id_Resolver::ORDER_STATUS_LEAF => [] ] ],
			]
		);
	}

	// ----- resolve(): what comes back -----

	public function test_resolve_runs_the_compiled_statement_once_and_returns_distinct_positive_ints(): void {
		$wpdb     = new OrdersIdResolverFakeWpdb( [ '7', 7, '0', '', null, '12', 3 ] );
		$resolver = new Orders_Id_Resolver( $wpdb, false );
		$keys     = [ '_a_marker' ];

		$ids = $resolver->resolve( $keys, Orders_Query::meta_query_for_keys( $keys ) );

		$this->assertSame( [ 7, 12, 3 ], $ids );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertSame( $resolver->compile( $keys, Orders_Query::meta_query_for_keys( $keys ) ), $wpdb->queries[0] );
	}

	// ----- a failed id query (#936) -----

	/**
	 * A statement that errors still yields the empty list — the caller turns it into the
	 * «matches nothing» sentinel, never into every order — but it is now logged, so a
	 * broken query is told apart from an honest empty result.
	 */
	public function test_a_failed_id_query_is_logged_and_still_fails_closed(): void {
		$wpdb = new OrdersIdResolverFakeWpdb( [ 1, 2, 3 ] );
		$wpdb->fail_with( "Unknown column 'mk.order_id' in 'field list'" );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				\Mockery::on(
					static function ( $message ) use ( &$captured ): bool {
						$captured = $message;

						return true;
					}
				)
			);

		$keys = [ '_a_marker' ];

		$this->assertSame( [], ( new Orders_Id_Resolver( $wpdb, true ) )->resolve( $keys, Orders_Query::meta_query_for_keys( $keys ) ) );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringStartsWith( '[woodev] ', $captured );
		$this->assertStringContainsString( "Unknown column 'mk.order_id' in 'field list'", $captured );
	}

	public function test_a_successful_id_query_writes_nothing_to_the_log(): void {
		Functions\expect( 'error_log' )->never();

		$keys = [ '_a_marker' ];

		$this->assertSame( [ 4 ], ( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb( [ 4 ] ), false ) )->resolve( $keys, Orders_Query::meta_query_for_keys( $keys ) ) );
		$this->assertSame( [], ( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb( [] ), false ) )->resolve( $keys, Orders_Query::meta_query_for_keys( $keys ) ), 'an honest empty result is not an error' );
	}
}
