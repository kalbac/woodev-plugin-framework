<?php
/**
 * Unit: how the aggregate delivery-status filter grows in JOINs (#839), re-pinned after
 * #928 moved the scope and every meta-based filter out of the main query.
 *
 * The orders page scopes its rows by OR-ing one clause per registered carrier, and
 * #837 defect 2 forced every NEGATIVE clause to be bound to that carrier's own marker
 * key as well. Handed to the datastore as a `meta_query`, each of those clauses was one
 * JOIN on the meta table with no key in its `ON`, and nothing in the suite noticed the
 * growth, because every other test asserts the SHAPE of the built tree and a shape looks
 * the same at any size.
 *
 * It was noticed the expensive way instead. In s128 the integration suite hung: MySQL
 * sat in `Sending data` for over four minutes on ONE query carrying TWELVE
 * `LEFT JOIN wp_postmeta` at four registered carriers, two with a status map and two
 * without. #839 step 2 brought that to eight. Then the #928 measurement showed the real
 * law — every un-predicated join multiplies the row count by the order's meta density,
 * `~d^N` — and that the UNFILTERED page at four carriers already paid 11.7 s per
 * 10 000 orders on the dev rig's own density. So #928 took the joins out entirely: the
 * tree is resolved to ids by one flat statement ({@see Orders_Id_Resolver}) and reaches
 * the main query as `post__in`.
 *
 * This file therefore pins TWO sizes, and both are a budget, not a description:
 *
 *   - the MAIN query carries NO meta join at all — its `meta_query` is absent, or the
 *     one-leaf "matches nothing" sentinel. If the scope OR of marker `EXISTS` clauses
 *     ever comes back into the main query, this is the file that goes red;
 *   - the ID query carries no JOIN either, and the number of correlated subqueries it
 *     carries follows the old leaf law — `N` scope keys folded into the driver's `IN`,
 *     then `3M + B` for `unknown` / `is not <canonical>`, `M` for `<canonical>` /
 *     `is not unknown`, `0` with no filter. Each subquery is one index lookup per driver
 *     row, so the cost is linear in `N`, but a change that moves one of these numbers
 *     still has to say so out loud.
 *
 * ⚠ Never turn this file into an integration test by registering four carriers and
 * actually RUNNING the aggregate on the CPT datastore. That is what wedged the shared
 * test database in s128, and recovering it needs `KILL <id>` inside MySQL.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Id_Resolver;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

class ShippingOrdersQueryJoinGrowthTest extends TestCase {

	/** What the stubbed resolver hands back, so `post__in` is observable. */
	public const STUB_IDS = [ 101, 102 ];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'    => 'Pending',
				'wc-processing' => 'Processing',
				'wc-cancelled'  => 'Cancelled',
				'wc-failed'     => 'Failed',
			]
		);
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

	/**
	 * A registry of $mapped carriers that can report a canonical state plus $bare ones
	 * with no status concept at all — the two kinds behave differently in every branch
	 * of the builder, and the s128 fixture was two of each.
	 */
	private function registry_of( int $mapped, int $bare ): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();

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

	/**
	 * An Orders_Query pinned to one datastore whose id resolver is stubbed (the real one
	 * needs `$wpdb`) and which records the marker keys and tree it was handed, so the id
	 * query can be compiled here from exactly that input.
	 *
	 * @return Orders_Query&object{resolved:array<int,array{0:string[],1:array<int|string,mixed>}>}
	 */
	private function query_on( bool $hpos, Orders_Registry $registry ): Orders_Query {
		return new class( $registry, $hpos ) extends Orders_Query {
			/** @var bool */
			private $hpos;

			/** @var array<int,array{0:string[],1:array<int|string,mixed>}> */
			public $resolved = [];

			public function __construct( ?Orders_Registry $registry, bool $hpos ) {
				parent::__construct( $registry );
				$this->hpos = $hpos;
			}

			protected function is_hpos_enabled(): bool {
				return $this->hpos;
			}

			protected function resolve_order_ids( array $marker_keys, array $meta_query ): array {
				$this->resolved[] = [ $marker_keys, $meta_query ];

				return ShippingOrdersQueryJoinGrowthTest::STUB_IDS;
			}
		};
	}

	/**
	 * Counts LEAF clauses in a `meta_query` tree — every node carrying a `key`, at any
	 * nesting depth. In the MAIN query one leaf is one join (see the class docblock).
	 *
	 * @param array<int|string,mixed> $tree
	 */
	private function leaf_count( array $tree ): int {
		$leaves = 0;

		foreach ( $tree as $key => $node ) {
			if ( 'relation' === $key || ! is_array( $node ) ) {
				continue;
			}

			$leaves += isset( $node['key'] ) ? 1 : $this->leaf_count( $node );
		}

		return $leaves;
	}

	/**
	 * Both sizes for one (fixture, request, datastore): the leaf count of whatever
	 * `meta_query` the MAIN query still carries (0 when none), and the id query's SQL
	 * as {@see Orders_Id_Resolver} compiles it from what the query handed the seam
	 * (null when the tree matched nothing and no id query was asked for).
	 *
	 * @return array{main_leaves:int,post__in:bool,id_sql:?string}
	 */
	private function sizes_for( int $mapped, int $bare, array $request, bool $hpos = true ): array {
		$registry = $this->registry_of( $mapped, $bare );
		$query    = $this->query_on( $hpos, $registry );
		$args     = $query->build_args( $request );

		$id_sql = null;

		if ( [] !== $query->resolved ) {
			[ $keys, $tree ] = $query->resolved[0];

			$id_sql = ( new Orders_Id_Resolver( new OrdersIdResolverFakeWpdb(), $hpos ) )->compile( $keys, $tree );
		}

		return [
			'main_leaves' => isset( $args['meta_query'] ) ? $this->leaf_count( (array) $args['meta_query'] ) : 0,
			'post__in'    => array_key_exists( 'post__in', $args ),
			'id_sql'      => $id_sql,
		];
	}

	private function joins( string $sql ): int {
		return preg_match_all( '/\bJOIN\b/i', $sql );
	}

	private function subqueries( string $sql ): int {
		return preg_match_all( '/EXISTS \(SELECT 1 FROM /', $sql );
	}

	/**
	 * The measured budget, RE-PINNED for #928. `M` carriers own a usable status map, `B`
	 * own no status concept, and `N = M + B`. The MAIN query: zero meta joins, always,
	 * except the one-leaf sentinel when the tree matches nothing. The ID query, in
	 * correlated subqueries (the `N` scope keys are the driver's `IN` list, not counted):
	 *
	 *     no filter                         0               (was N joins in the main query)
	 *     delivery_status=unknown           3M + B          (was 3M + B joins; 4M + 2B before #839;
	 *                                                        0 when M is 0 — see below)
	 *     delivery_status=<canonical>       M               (was N + M joins; sentinel when M is 0)
	 *     delivery_status_not=<canonical>   3M + B          (was 3M + B joins; 0 when M is 0)
	 *     delivery_status_not=unknown       M               (was N + M joins; sentinel when M is 0)
	 *
	 * With no mapped carrier at all, the `unknown` / `is not <canonical>` filter part is
	 * every bare carrier's marker `EXISTS` OR-ed — byte for byte the scope part — and
	 * {@see Orders_Id_Resolver::compile()} folds a part identical to the scope into the
	 * driver predicate, so it costs nothing: the filter IS the scope there.
	 *
	 * @return array<string,array{0:int,1:int,2:array<string,mixed>,3:string}>
	 */
	public function growth_law_provider(): array {
		$cases = [];

		$requests = [
			'no filter'                      => [],
			'delivery_status=unknown'        => [ 'delivery_status' => Delivery_Status::UNKNOWN ],
			'delivery_status=in_transit'     => [ 'delivery_status' => Delivery_Status::IN_TRANSIT ],
			'delivery_status_not=in_transit' => [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ],
			'delivery_status_not=unknown'    => [ 'delivery_status_not' => Delivery_Status::UNKNOWN ],
		];

		$splits = [
			'all mapped'    => static function ( int $n ): array {
				return [ $n, 0 ];
			},
			'none mapped'   => static function ( int $n ): array {
				return [ 0, $n ];
			},
			'half and half' => static function ( int $n ): array {
				return [ intdiv( $n, 2 ), $n - intdiv( $n, 2 ) ];
			},
		];

		foreach ( $splits as $split_label => $split ) {
			for ( $n = 1; $n <= 6; $n++ ) {
				list( $mapped, $bare ) = $split( $n );

				foreach ( $requests as $request_label => $request ) {
					$cases[ "{$request_label}, {$split_label}, N={$n}" ] = [ $mapped, $bare, $request, $request_label ];
				}
			}
		}

		return $cases;
	}

	/** The id-query budget as a formula, so the provider cannot drift from the docblock above. `null` = the sentinel, no id query. */
	private function expected_subqueries( int $mapped, int $bare, string $request_label ): ?int {
		switch ( $request_label ) {
			case 'no filter':
				return 0;

			case 'delivery_status=unknown':
			case 'delivery_status_not=in_transit':
				// No mapped carrier => the filter part IS the scope part and folds into the driver.
				return 0 === $mapped ? 0 : ( 3 * $mapped ) + $bare;

			case 'delivery_status=in_transit':
			case 'delivery_status_not=unknown':
				// No carrier can report it => the NO_MATCH sentinel, answered without an id query.
				return 0 === $mapped ? null : $mapped;
		}

		throw new \LogicException( "no budget for {$request_label}" );
	}

	/**
	 * @dataProvider growth_law_provider
	 *
	 * @param array<string,mixed> $request
	 */
	public function test_the_main_query_carries_no_meta_join_and_the_id_query_stays_within_its_budget( int $mapped, int $bare, array $request, string $request_label ): void {
		$sizes    = $this->sizes_for( $mapped, $bare, $request );
		$expected = $this->expected_subqueries( $mapped, $bare, $request_label );

		if ( null === $expected ) {
			$this->assertSame( 1, $sizes['main_leaves'], 'a tree that matches nothing is the one-leaf sentinel in the main query' );
			$this->assertFalse( $sizes['post__in'] );
			$this->assertNull( $sizes['id_sql'], 'and no id query is asked for' );

			return;
		}

		$this->assertSame(
			0,
			$sizes['main_leaves'],
			'The MAIN query carries a meta_query again. Every OR-ed marker EXISTS there is one un-predicated join and the '
			. 'page costs ~d^N in the carrier count (#928) — the scope and the filters belong in the id query, as post__in.'
		);
		$this->assertTrue( $sizes['post__in'] );
		$this->assertNotNull( $sizes['id_sql'] );
		$this->assertSame( 0, $this->joins( (string) $sizes['id_sql'] ), 'The id query must never join (#928).' );
		$this->assertSame(
			$expected,
			$this->subqueries( (string) $sizes['id_sql'] ),
			'The id query changed size. One correlated subquery is one index lookup per order in scope, so this is a '
			. 'performance contract, not a shape detail — say so in the change, and re-measure (#839, #928).'
		);
	}

	/**
	 * The anchored number, kept as its own test because it is the one that was observed
	 * in the wild rather than derived: four carriers, two with a status map and two
	 * without, `delivery_status=unknown`. s128 saw TWELVE `LEFT JOIN wp_postmeta` on the
	 * legacy CPT datastore and MySQL sat in `Sending data` for over four minutes; #839
	 * step 2 brought it to eight; #928 brought the main query to ZERO and the id query
	 * to eight keyed subqueries and no join.
	 */
	public function test_the_s128_fixture_that_wedged_mysql_now_costs_zero_joins(): void {
		$sizes = $this->sizes_for( 2, 2, [ 'delivery_status' => Delivery_Status::UNKNOWN ] );

		$this->assertSame( 0, $sizes['main_leaves'], 'This is the exact query that hung the integration suite in s128 (#928: no meta join in the main query).' );
		$this->assertSame( 0, $this->joins( (string) $sizes['id_sql'] ) );
		$this->assertSame( 8, $this->subqueries( (string) $sizes['id_sql'] ), 'eight keyed subqueries, one per leaf (was eight joins after #839, twelve before)' );
	}

	/**
	 * Growth is linear, and the per-carrier slope is what matters: three subqueries per
	 * mapped carrier for `unknown` — the marker binding plus the two status halves.
	 * Asserted as a slope rather than as a list of totals so it keeps meaning if the
	 * constant term ever changes.
	 */
	public function test_the_unknown_filter_costs_exactly_three_subqueries_per_mapped_carrier(): void {
		$slopes = [];

		for ( $n = 1; $n <= 6; $n++ ) {
			$slopes[ $n ] = $this->subqueries( (string) $this->sizes_for( $n, 0, [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['id_sql'] );
		}

		foreach ( range( 2, 6 ) as $n ) {
			$this->assertSame( 3, $slopes[ $n ] - $slopes[ $n - 1 ], "Each additional carrier with a status map costs three more subqueries (at N={$n})." );
		}
	}

	/**
	 * The same slope for the `is not` rule (#836), which reuses the `unknown` shape —
	 * pinned separately because it reaches it through the other branch of the builder
	 * and could drift on its own.
	 */
	public function test_the_is_not_filter_costs_exactly_three_subqueries_per_mapped_carrier(): void {
		$previous = $this->subqueries( (string) $this->sizes_for( 1, 0, [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] )['id_sql'] );

		foreach ( range( 2, 6 ) as $n ) {
			$current = $this->subqueries( (string) $this->sizes_for( $n, 0, [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] )['id_sql'] );

			$this->assertSame( 3, $current - $previous, "`is not` grew by something other than three at N={$n}." );

			$previous = $current;
		}
	}

	/**
	 * A carrier with NO status concept costs exactly ONE subquery under `unknown` beside
	 * a mapped carrier — its own marker standing in for "always unknown" — and NOTHING
	 * on its own (the filter part is then the scope itself) or without a filter, where
	 * its marker is only the driver's `IN` entry.
	 */
	public function test_a_carrier_with_no_status_concept_pays_one_subquery_under_unknown_and_none_without_a_filter(): void {
		$beside_a_mapped_one = $this->subqueries( (string) $this->sizes_for( 1, 1, [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['id_sql'] );
		$mapped_alone        = $this->subqueries( (string) $this->sizes_for( 1, 0, [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['id_sql'] );

		$this->assertSame( 1, $beside_a_mapped_one - $mapped_alone );
		$this->assertSame( 0, $this->subqueries( (string) $this->sizes_for( 0, 1, [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['id_sql'] ) );
		$this->assertSame( 0, $this->subqueries( (string) $this->sizes_for( 0, 1, [] )['id_sql'] ) );
	}

	/**
	 * The legacy-CPT path must carry the same budget: the same tree reaches the same
	 * resolver, only the table differs. Pinned so the two datastore paths cannot diverge
	 * in COST while still agreeing on meaning.
	 */
	public function test_the_legacy_cpt_path_carries_the_same_budget(): void {
		$hpos = $this->sizes_for( 2, 2, [ 'delivery_status' => Delivery_Status::UNKNOWN ], true );
		$cpt  = $this->sizes_for( 2, 2, [ 'delivery_status' => Delivery_Status::UNKNOWN ], false );

		$this->assertSame( 0, $cpt['main_leaves'], 'The CPT path must never emit meta_query (its presence alone drops the whole filter there).' );
		$this->assertTrue( $cpt['post__in'] );
		$this->assertSame( 0, $this->joins( (string) $cpt['id_sql'] ) );
		$this->assertSame( 8, $this->subqueries( (string) $cpt['id_sql'] ) );
		$this->assertSame(
			$cpt['id_sql'],
			str_replace( [ 'wp_wc_orders_meta', 'order_id' ], [ 'wp_postmeta', 'post_id' ], (string) $hpos['id_sql'] ),
			'same statement, other table'
		);
	}
}
