<?php
/**
 * Unit: how fast the aggregate delivery-status filter grows in JOINs (#839).
 *
 * The orders page scopes its rows by OR-ing one clause per registered carrier, and
 * #837 defect 2 forced every NEGATIVE clause to be bound to that carrier's own marker
 * key as well. Both halves enumerate the carriers, so the query grows linearly in the
 * carrier count — and nothing in the suite noticed, because every other test here
 * asserts the SHAPE of the built `meta_query` and a shape looks the same at any size.
 *
 * It was noticed the expensive way instead. In s128 the integration suite hung: MySQL
 * sat in `Sending data` for over four minutes on ONE query carrying TWELVE
 * `LEFT JOIN wp_postmeta` at four registered carriers, two with a status map and two
 * without. Killing PHP does not stop that query; it keeps running, holds the metadata
 * lock, and the next run then hangs on `DROP TABLE wp_users`.
 *
 * So this file pins the SIZE, which no other test does. It counts the leaf clauses the
 * builder emits, because a leaf clause is what becomes a join:
 *
 *   - on HPOS the correspondence is exact — WooCommerce's own meta-query builder emits
 *     one join per leaf, measured for card #839 by capturing the real SQL through
 *     `woocommerce_orders_table_query_clauses` with two carriers registered (8 leaves,
 *     8 joins for `delivery_status=unknown`);
 *   - on the legacy CPT datastore `WP_Meta_Query` emits one join per leaf too, except
 *     that it shares one alias between sibling clauses of an OR whose comparison is
 *     "positive" (`IN` among them, `EXISTS` NOT among them), so the positive filters
 *     cost slightly less there. Verified for #839 by running the real `WP_Meta_Query`
 *     over the built tree with a stub `$wpdb`, no database involved: the s128 fixture
 *     reproduces its observed twelve exactly.
 *
 * The numbers below are therefore a budget, not a description. When a change moves one
 * of them the diff has to say so out loud — which is the whole point, since the last
 * two changes to these builders each added a join per carrier without anyone noticing.
 *
 * ⚠ Never turn this file into an integration test by registering four carriers and
 * actually RUNNING the aggregate on the CPT datastore. That is what wedged the shared
 * test database in s128, and recovering it needs `KILL <id>` inside MySQL.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

class ShippingOrdersQueryJoinGrowthTest extends TestCase {

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
	 * Builds an Orders_Query pinned to the HPOS branch, so `meta_query` is emitted
	 * directly. See ShippingOrdersQueryTest's class docblock for why this subclass is
	 * needed at all.
	 */
	private function query_with_hpos( Orders_Registry $registry ): Orders_Query {
		return new class( $registry ) extends Orders_Query {
			protected function is_hpos_enabled(): bool {
				return true;
			}
		};
	}

	/**
	 * Counts LEAF clauses in a `meta_query` tree — every node carrying a `key`, at any
	 * nesting depth. One leaf is one join (see the class docblock).
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

	private function leaves_for( int $mapped, int $bare, array $request ): int {
		$registry = $this->registry_of( $mapped, $bare );

		return $this->leaf_count( $this->query_with_hpos( $registry )->build_args( $request )['meta_query'] );
	}

	/**
	 * The measured budget. `M` carriers own a usable status map, `B` own no status
	 * concept, and `N = M + B`:
	 *
	 *     no filter                         N
	 *     delivery_status=unknown           4M + 2B
	 *     delivery_status=<canonical>       N + M          (N + 1 when M is 0)
	 *     delivery_status_not=<canonical>   4M + 2B
	 *     delivery_status_not=unknown       N + M          (N + 1 when M is 0)
	 *
	 * The scope contributes `N`, and it is the term that pays for the marker join a
	 * second time: every clause the negative filters build already binds its own
	 * provider's marker. #839 is about whether that term can go.
	 *
	 * @return array<string,array{0:int,1:int,2:array<string,mixed>,3:int}>
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
			'all mapped' => static function ( int $n ): array {
				return [ $n, 0 ];
			},
			'none mapped' => static function ( int $n ): array {
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
					$expected = $this->expected_leaves( $mapped, $bare, $request_label );

					$cases[ "{$request_label}, {$split_label}, N={$n}" ] = [ $mapped, $bare, $request, $expected ];
				}
			}
		}

		return $cases;
	}

	/** The budget as a formula, so the provider cannot drift from the docblock above. */
	private function expected_leaves( int $mapped, int $bare, string $request_label ): int {
		$scope = $mapped + $bare;

		switch ( $request_label ) {
			case 'no filter':
				return $scope;

			case 'delivery_status=unknown':
			case 'delivery_status_not=in_transit':
				return ( 4 * $mapped ) + ( 2 * $bare );

			case 'delivery_status=in_transit':
			case 'delivery_status_not=unknown':
				// No carrier can report it => the NO_MATCH sentinel, one leaf.
				return 0 === $mapped ? $scope + 1 : $scope + $mapped;
		}

		throw new \LogicException( "no budget for {$request_label}" );
	}

	/**
	 * @dataProvider growth_law_provider
	 *
	 * @param array<string,mixed> $request
	 */
	public function test_the_delivery_status_filter_stays_within_its_measured_join_budget( int $mapped, int $bare, array $request, int $expected ): void {
		$this->assertSame(
			$expected,
			$this->leaves_for( $mapped, $bare, $request ),
			'The aggregate filter changed size. One leaf clause is one JOIN, so this is a performance contract, '
			. 'not a shape detail — say so in the change, and re-measure (#839).'
		);
	}

	/**
	 * The anchored number, kept as its own test because it is the one that was observed
	 * in the wild rather than derived: four carriers, two with a status map and two
	 * without, `delivery_status=unknown`. s128 saw TWELVE `LEFT JOIN wp_postmeta` on the
	 * legacy CPT datastore and MySQL sat in `Sending data` for over four minutes.
	 */
	public function test_the_s128_fixture_that_wedged_mysql_still_costs_twelve_joins(): void {
		$this->assertSame(
			12,
			$this->leaves_for( 2, 2, [ 'delivery_status' => Delivery_Status::UNKNOWN ] ),
			'This is the exact query that hung the integration suite in s128. If the number went DOWN, '
			. 'that is the #839 fix and this test should record the new one; if it went UP, stop.'
		);
	}

	/**
	 * Growth is linear, and the per-carrier slope is what matters: at four joins per
	 * carrier the `unknown` filter is already past what MySQL will plan sanely, so the
	 * slope is the thing a change has to move. Asserted as a slope rather than as a
	 * list of totals so it keeps meaning if the constant term ever changes.
	 */
	public function test_the_unknown_filter_costs_exactly_four_joins_per_mapped_carrier(): void {
		$slopes = [];

		for ( $n = 1; $n <= 6; $n++ ) {
			$slopes[ $n ] = $this->leaves_for( $n, 0, [ 'delivery_status' => Delivery_Status::UNKNOWN ] );
		}

		foreach ( range( 2, 6 ) as $n ) {
			$this->assertSame(
				4,
				$slopes[ $n ] - $slopes[ $n - 1 ],
				"Each additional carrier with a status map costs four more joins (at N={$n}). "
				. 'Two of the four are the marker, joined once by the scope and once by the binding (#839).'
			);
		}
	}

	/**
	 * The same slope for the `is not` rule (#836), which reuses the `unknown` shape —
	 * pinned separately because it reaches it through the other branch of the builder
	 * and could drift on its own.
	 */
	public function test_the_is_not_filter_costs_exactly_four_joins_per_mapped_carrier(): void {
		$previous = $this->leaves_for( 1, 0, [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] );

		foreach ( range( 2, 6 ) as $n ) {
			$current = $this->leaves_for( $n, 0, [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] );

			$this->assertSame( 4, $current - $previous, "`is not` grew by something other than four at N={$n} (#839)." );

			$previous = $current;
		}
	}

	/**
	 * A carrier with NO status concept still costs TWO joins under `unknown` — its
	 * marker in the scope, and its marker again standing in for "always unknown". That
	 * is the cheapest possible carrier and it is still paying the marker twice, which
	 * is the clearest statement of what #839 is about.
	 */
	public function test_a_carrier_with_no_status_concept_still_pays_for_its_marker_twice(): void {
		$this->assertSame(
			2,
			$this->leaves_for( 0, 1, [ 'delivery_status' => Delivery_Status::UNKNOWN ] ),
			'One carrier with no status concept: the scope joins its marker and the filter joins it again.'
		);

		$this->assertSame(
			1,
			$this->leaves_for( 0, 1, [] ),
			'Without a status filter the same carrier costs ONE join, which is the duplicate made visible.'
		);
	}

	/**
	 * The legacy-CPT path must carry the same budget, because it rebuilds the same parts
	 * through the same {@see Orders_Query::combine_meta_queries()}. Pinned so the two
	 * datastore paths cannot diverge in COST while still agreeing on meaning.
	 */
	public function test_the_legacy_cpt_path_carries_the_same_budget(): void {
		$registry = $this->registry_of( 2, 2 );

		$args = ( new Orders_Query( $registry ) )->build_args( [ 'delivery_status' => Delivery_Status::UNKNOWN ] );

		$this->assertArrayNotHasKey( 'meta_query', $args, 'The CPT path must never emit meta_query.' );

		$parts = [
			Orders_Query::meta_query_for_keys( (array) ( $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] ?? [] ) ),
			Orders_Query::meta_query_for_clauses( (array) $args[ Orders_Query::QUERY_VAR_STATUS_CLAUSES ] ),
		];

		$this->assertSame(
			12,
			$this->leaf_count( Orders_Query::combine_meta_queries( $parts ) ),
			'The legacy CPT translation must cost what HPOS costs — s128 measured twelve joins on exactly this fixture.'
		);
	}
}
