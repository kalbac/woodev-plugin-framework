<?php
/**
 * Unit: the aggregate delivery-status filter's row semantics, proven by exhaustive
 * enumeration against an INDEPENDENT oracle (#839 step 2, #919).
 *
 * A Codex critic blocked #839 step 2 (dropping the duplicate marker-scope part,
 * `4M+2B -> 3M+B`) because its equivalence evidence lived only in a research script
 * (`docs-internal/research/2026-09-25-839-join-growth-evidence/equivalence.php`) that
 * CI never replays — a script nobody runs is not a gate. That script also compares the
 * CURRENT tree against a PROPOSED one, which stops meaning anything the moment the
 * proposal merges: at that point it compares a thing with itself.
 *
 * This file proves something that survives BOTH #839 step 2 and #928's id-query
 * rewrite: the tree {@see Orders_Query} builds (on both datastores) against a predicate
 * written straight from the carrier definitions and the order's own meta, never derived
 * from {@see Orders_Query}'s OUTPUT. One reading is adopted from that class's documented
 * invariant rather than derived mechanically, and it is named where it is taken — see
 * {@see self::provider_satisfies_delivery_status()}. If a future rewrite changes which
 * rows the filter selects, this is the file that turns red.
 *
 * #928: the tree no longer reaches the datastore as `meta_query`; `Orders_Query`
 * resolves it to order ids through the `protected` seam `resolve_order_ids()` and hands
 * them over as `post__in`. This gate therefore substitutes an IN-MEMORY resolver at that
 * seam ({@see self::query_over()}): it walks the tree it is handed against every order
 * of the enumerated universe with the same WP_Meta_Query semantics as before, and the
 * assertion is that the id set `build_args()` puts into `post__in` equals the oracle's
 * set — for every row, fixture, filter and datastore. Drift in the tree (a new provider
 * branch), in what is handed to the seam, or in the empty ⇒ sentinel rule all land here.
 * What this file cannot prove is the SQL the production resolver compiles from the same
 * tree; `ShippingOrdersIdResolverTest` pins its shape and the #928 research probe
 * measured it row for row at database level.
 *
 * The oracle implements WP_Meta_Query / MySQL leaf semantics, including the trap that
 * `NOT IN` does NOT match a row that has no such meta row at all (gotcha
 * `a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all`) — only
 * `NOT EXISTS` makes WP_Meta_Query use a LEFT JOIN. And it binds every provider's
 * condition to that SAME provider's own marker, because #837 defect 2 was exactly a
 * negative clause OR'd across providers with no such binding, which matched every row
 * in the table (gotcha
 * `a-negative-meta-clause-or-ed-across-providers-matches-every-order`).
 *
 * @package Woodev\Tests\Unit
 * @since 2.0.2
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

/**
 * @since 2.0.2
 */
class ShippingOrdersQueryRowSemanticsTest extends TestCase {

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
	 * A registry of $mapped carriers that can report a canonical state (raw values
	 * `M{i}_GO` => in_transit, `M{i}_DONE` => delivered, plus the always-unmapped
	 * `JUNK` the universe also exercises) and $bare ones with no status concept at all.
	 * Mirrors the research harness's `registry_of()` exactly, so a run of
	 * `equivalence.php` and a run of this file describe the same carriers.
	 *
	 * @since 2.0.2
	 */
	private function registry_of( int $mapped, int $bare ): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();

		for ( $i = 1; $i <= $mapped; $i++ ) {
			$registry->register_provider(
				Orders_Provider::create(
					"m{$i}",
					"M{$i}",
					"_m{$i}_marker",
					[ "m{$i}" ],
					[
						'status_meta_key' => "_m{$i}_status",
						'status_map'      => [
							"M{$i}_GO"   => Delivery_Status::IN_TRANSIT,
							"M{$i}_DONE" => Delivery_Status::DELIVERED,
						],
					]
				)
			);
		}

		for ( $i = 1; $i <= $bare; $i++ ) {
			$registry->register_provider(
				Orders_Provider::create( "b{$i}", "B{$i}", "_b{$i}_marker", [ "b{$i}" ] )
			);
		}

		return $registry;
	}

	/**
	 * Builds an Orders_Query pinned to one datastore whose id resolver is this file's
	 * in-memory one: the tree it is handed is walked against every order of `$rows`
	 * (order `i` has id `i + 1`) with {@see self::matches_query()}, and the ids of the
	 * rows that match come back — exactly what the production resolver does against the
	 * meta table, minus the database. It also records what it was handed, so the test
	 * can check the seam receives the registered marker keys.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int,array<string,string>> $rows the universe.
	 * @return Orders_Query&object{resolved:array<int,array{0:string[],1:array<int|string,mixed>}>}
	 */
	private function query_over( Orders_Registry $registry, bool $is_hpos, array $rows ): Orders_Query {
		$walk = function ( array $tree, array $meta ): bool {
			return $this->matches_query( $tree, $meta );
		};

		return new class( $registry, $is_hpos, $rows, $walk ) extends Orders_Query {
			/** @var bool */
			private $hpos;

			/** @var array<int,array<string,string>> */
			private $rows;

			/** @var callable(array,array):bool */
			private $walk;

			/** @var array<int,array{0:string[],1:array<int|string,mixed>}> */
			public $resolved = [];

			public function __construct( ?Orders_Registry $registry, bool $hpos, array $rows, callable $walk ) {
				parent::__construct( $registry );
				$this->hpos = $hpos;
				$this->rows = $rows;
				$this->walk = $walk;
			}

			protected function is_hpos_enabled(): bool {
				return $this->hpos;
			}

			protected function resolve_order_ids( array $marker_keys, array $meta_query ): array {
				$this->resolved[] = [ $marker_keys, $meta_query ];

				$ids = [];

				foreach ( $this->rows as $index => $meta ) {
					if ( ( $this->walk )( $meta_query, $meta ) ) {
						$ids[] = $index + 1;
					}
				}

				return $ids;
			}
		};
	}

	/**
	 * The id set `build_args()` hands the datastore for `$request`: `post__in` when the
	 * resolver found rows, the empty set when it emitted the "matches nothing" sentinel
	 * instead — and never both, never neither.
	 *
	 * @since 2.0.2
	 *
	 * @return int[]
	 */
	private function selected_ids( Orders_Query $query, array $request, bool $is_hpos ): array {
		$args = $query->build_args( $request );

		if ( array_key_exists( 'post__in', $args ) ) {
			$this->assertArrayNotHasKey( 'meta_query', $args, 'with ids in hand nothing else may scope the query' );
			$this->assertArrayNotHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
			$this->assertNotSame( [], $args['post__in'], 'an empty post__in fails OPEN on both datastores' );

			return array_map( 'intval', $args['post__in'] );
		}

		if ( $is_hpos ) {
			$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] ?? null, 'HPOS: no ids => the sentinel meta_query' );
		} else {
			$this->assertArrayNotHasKey( 'meta_query', $args );
			$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] ?? null, 'legacy CPT: no ids => the empty marker-keys var' );
		}

		return [];
	}

	/**
	 * Every possible order over these carriers: marker present/absent x status meta
	 * absent / mapped-to-in-transit / mapped-to-delivered / mapped-to-nothing (`JUNK`),
	 * for each mapped carrier independently, times marker present/absent for each bare
	 * carrier — INCLUDING combinations no real carrier plugin would ever write, such as
	 * a status value present while that same carrier's marker is absent. Those
	 * physically impossible rows are exactly where an unstated assumption in the
	 * builder would first show up, so excluding them would defeat the point.
	 *
	 * ⚠ "Every possible order" is bounded by the shape of this map: one VALUE per meta
	 * key, so it cannot express an order carrying two postmeta rows under the SAME key.
	 * Under SQL such an order satisfies `IN known` and `NOT IN known` at once, and this
	 * oracle's spec has no reading for it. It does not weaken what this file proves —
	 * the redundancy #839 step 2 relies on is structural, every accepted disjunct
	 * implying its own carrier's marker — but do not read the enumeration as literally
	 * exhaustive over all database states.
	 *
	 * ⚠ NARROWED (operator decision 26.09.2026, YAGNI; #928): the universe holds only orders
	 * carrying AT MOST ONE registered carrier's marker (zero or one). Multi-carrier delivery
	 * does not exist in this product, so an order with two markers is outside the filter's
	 * contract. The narrowing is made AHEAD of the cheaper negative-filter form #928 may
	 * introduce — that form does not exist yet and would be correct only under this rule;
	 * the query as it stands is correct on multi-marker orders too. The multi-marker rows
	 * are dropped here EXPLICITLY, so the gate does not have to change when that form
	 * lands. The rule itself is not enforced by the data layer; under `WP_DEBUG`
	 * {@see Orders_Registry::report_multiple_markers()} reports a violating order. The
	 * narrowing is pinned by an assertion in the test, so it cannot silently regress.
	 *
	 * Otherwise mirrors the research harness's `universe()` (which still enumerates the
	 * multi-marker rows).
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,string>>
	 */
	private function universe( int $mapped, int $bare ): array {
		$axes = [];

		for ( $i = 1; $i <= $mapped; $i++ ) {
			$axes[] = [ [ "_m{$i}_marker" => '1' ], [] ];
			$axes[] = [
				[],
				[ "_m{$i}_status" => "M{$i}_GO" ],
				[ "_m{$i}_status" => "M{$i}_DONE" ],
				[ "_m{$i}_status" => 'JUNK' ],
			];
		}

		for ( $i = 1; $i <= $bare; $i++ ) {
			$axes[] = [ [ "_b{$i}_marker" => '1' ], [] ];
		}

		$rows = [ [] ];

		foreach ( $axes as $axis ) {
			$next = [];

			foreach ( $rows as $row ) {
				foreach ( $axis as $choice ) {
					$next[] = array_merge( $row, $choice );
				}
			}

			$rows = $next;
		}

		$marker_keys = [];

		for ( $i = 1; $i <= $mapped; $i++ ) {
			$marker_keys[] = "_m{$i}_marker";
		}

		for ( $i = 1; $i <= $bare; $i++ ) {
			$marker_keys[] = "_b{$i}_marker";
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $meta ) use ( $marker_keys ): bool {
					return count( array_intersect_key( $meta, array_flip( $marker_keys ) ) ) <= 1;
				}
			)
		);
	}

	/**
	 * Evaluates one `meta_query` tree against one order's meta, following WP_Meta_Query
	 * / MySQL leaf semantics. `NOT IN` deliberately does NOT match a row with no such
	 * meta row at all — a missing row produces no row to compare, the same trap
	 * {@see Orders_Query::delivery_status_meta_clauses()}'s own docblock names. Throws
	 * on any compare operator not implemented here, so a future clause shape cannot
	 * pass silently.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int|string,mixed> $tree
	 * @param array<string,string>    $meta key => value for the meta rows that exist.
	 */
	private function matches_query( array $tree, array $meta ): bool {
		$relation = strtoupper( (string) ( $tree['relation'] ?? 'AND' ) );
		$results  = [];

		foreach ( $tree as $key => $node ) {
			if ( 'relation' === $key || ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['key'] ) ) {
				$results[] = $this->matches_leaf( $node, $meta );
				continue;
			}

			$results[] = $this->matches_query( $node, $meta );
		}

		if ( [] === $results ) {
			return true;
		}

		return 'OR' === $relation
			? in_array( true, $results, true )
			: ! in_array( false, $results, true );
	}

	/**
	 * @since 2.0.2
	 *
	 * @param array<string,mixed>  $node
	 * @param array<string,string> $meta
	 */
	private function matches_leaf( array $node, array $meta ): bool {
		$meta_key = $node['key'];
		$exists   = array_key_exists( $meta_key, $meta );
		$value    = $exists ? $meta[ $meta_key ] : null;
		$compare  = strtoupper( (string) ( $node['compare'] ?? '=' ) );

		switch ( $compare ) {
			case 'EXISTS':
				return $exists;

			case 'NOT EXISTS':
				return ! $exists;

			case 'IN':
				return $exists && in_array( $value, (array) $node['value'], true );

			case 'NOT IN':
				// A missing meta row produces NO row to compare, so it does NOT match.
				return $exists && ! in_array( $value, (array) $node['value'], true );

			case '=':
				return $exists && $value === $node['value'];

			default:
				throw new \RuntimeException( "ShippingOrdersQueryRowSemanticsTest: unhandled compare '{$compare}'." );
		}
	}

	/**
	 * The oracle. An order matches the aggregate scope iff it carries at least one
	 * REGISTERED provider's marker — mirrors {@see Orders_Query::meta_query_for_keys()}
	 * (SP-10 spec D10), and then the delivery-status condition on top of it, ANDed —
	 * mirrors {@see Orders_Query::combine_meta_queries()}.
	 *
	 * @since 2.0.2
	 *
	 * @param Orders_Provider[]    $providers
	 * @param array<string,string> $meta
	 */
	private function oracle_matches( array $providers, array $meta, string $canonical, bool $negate ): bool {
		$in_scope = false;

		foreach ( $providers as $provider ) {
			if ( array_key_exists( $provider->get_marker_meta_key(), $meta ) ) {
				$in_scope = true;
				break;
			}
		}

		if ( ! $in_scope ) {
			return false;
		}

		foreach ( $providers as $provider ) {
			if ( $this->provider_satisfies_delivery_status( $provider, $meta, $canonical, $negate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One provider's own delivery-status condition (#837/#836/#839's specification):
	 *
	 * - `unknown` ('is'): a provider with no status concept at all is ALWAYS unknown.
	 *   Otherwise: no status meta at all, or a raw value this provider never mapped to
	 *   ANY canonical state.
	 * - a specific canonical ('is'): this provider's `status_map` maps at least one raw
	 *   value to it, and the order carries one of those raw values on this provider's
	 *   OWN status key. A provider that can never report the canonical contributes
	 *   nothing.
	 * - either ('is not', #836): the negation, taken from the exact clause set
	 *   {@see Orders_Query::delivery_status_meta_clauses()} builds for `$negate`, not
	 *   from a bare boolean flip of the 'is' reading above — "not X" is trivially
	 *   satisfied by "no status at all", which needs the same NOT-EXISTS-OR-NOT-IN
	 *   shape as `unknown` does.
	 *
	 * Every one of the branches above that can be satisfied by an order this provider
	 * does NOT own (no status meta at all ⇒ "always unknown" / "always not X") is bound
	 * to this provider's own marker: #837 defect 2 was exactly this OR'd across
	 * providers unbound, matching every row in the table.
	 *
	 * The two branches that match a DEFINITE, known raw value on this provider's own
	 * status key ('is' a specific canonical, and 'is not unknown') are deliberately
	 * left UNBOUND here, reading {@see Orders_Query::delivery_status_meta_clauses()}'s
	 * own comment as the specification: a value on a provider's OWN status meta key
	 * already implies that provider's order, an invariant no real carrier plugin
	 * violates (a carrier never writes another carrier's meta). This is the one place
	 * this oracle chose a reading rather than deriving one mechanically — recorded here
	 * per #839's requirement that an ambiguous row shape be named, not silently matched
	 * to the builder.
	 *
	 * @since 2.0.2
	 */
	private function provider_satisfies_delivery_status( Orders_Provider $provider, array $meta, string $canonical, bool $negate ): bool {
		$marker_present = array_key_exists( $provider->get_marker_meta_key(), $meta );
		$status_key     = $provider->get_status_meta_key();

		if ( null === $status_key ) {
			// No status concept at all: this provider is ALWAYS unknown.
			$provider_is_unknown = ( Delivery_Status::UNKNOWN === $canonical ) !== $negate;

			return $provider_is_unknown && $marker_present;
		}

		$inverted       = Delivery_Status::invert_status_map( $provider->get_status_map() );
		$status_present = array_key_exists( $status_key, $meta );
		$status_value   = $status_present ? $meta[ $status_key ] : null;

		if ( Delivery_Status::UNKNOWN === $canonical ) {
			$known = [];
			foreach ( $inverted as $raw_values_for_state ) {
				$known = array_merge( $known, $raw_values_for_state );
			}
			$known = array_values( array_unique( $known ) );

			if ( [] === $known ) {
				// Maps nothing to any canonical state: this provider is ALWAYS unknown too.
				return ( ! $negate ) && $marker_present;
			}

			if ( ! $negate ) {
				return $marker_present && ( ! $status_present || ! in_array( $status_value, $known, true ) );
			}

			// "is not unknown": a definite known status — see the method docblock.
			return $status_present && in_array( $status_value, $known, true );
		}

		$raw_values = $inverted[ $canonical ] ?? [];

		if ( ! $negate ) {
			if ( [] === $raw_values ) {
				return false;
			}

			// A definite known status on this provider's own key — see the method docblock.
			return $status_present && in_array( $status_value, $raw_values, true );
		}

		if ( [] === $raw_values ) {
			// Never maps to the canonical => every one of this provider's orders is "not X".
			return $marker_present;
		}

		return $marker_present && ( ! $status_present || ! in_array( $status_value, $raw_values, true ) );
	}

	/**
	 * Fixture x filter x datastore combinations. Three fixtures (12 / 48 / 64 orders after the 26.09.2026
	 * single-marker narrowing, was 16 / 64 / 128),
	 * five filters, two datastores — the same three fixtures the research harness used
	 * for card #839.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,array{0:int,1:int,2:string,3:bool,4:bool,5:string,6:string,7:string}>
	 */
	public function meta_query_matches_oracle_provider(): array {
		$fixtures = [
			'2 carriers (1 mapped, 1 bare)' => [ 1, 1 ],
			'2 carriers (both mapped)'      => [ 2, 0 ],
			'3 carriers (2 mapped, 1 bare)' => [ 2, 1 ],
		];

		$filters = [
			'delivery_status=unknown'        => [ Delivery_Status::UNKNOWN, false ],
			'delivery_status=in_transit'     => [ Delivery_Status::IN_TRANSIT, false ],
			'delivery_status=delivered'      => [ Delivery_Status::DELIVERED, false ],
			'delivery_status_not=in_transit' => [ Delivery_Status::IN_TRANSIT, true ],
			'delivery_status_not=unknown'    => [ Delivery_Status::UNKNOWN, true ],
		];

		$datastores = [
			'HPOS'       => true,
			'legacy CPT' => false,
		];

		$cases = [];

		foreach ( $fixtures as $fixture_label => $split ) {
			[ $mapped, $bare ] = $split;

			foreach ( $filters as $filter_label => $filter ) {
				[ $canonical, $negate ] = $filter;

				foreach ( $datastores as $datastore_label => $is_hpos ) {
					$cases[ "{$fixture_label}, {$filter_label}, {$datastore_label}" ] = [
						$mapped,
						$bare,
						$canonical,
						$negate,
						$is_hpos,
						$fixture_label,
						$filter_label,
						$datastore_label,
					];
				}
			}
		}

		return $cases;
	}

	/**
	 * Builds the universe ONCE per (fixture, filter, datastore) and runs ONE
	 * `build_args()` over it — the in-memory resolver walks the 12-64 rows against the
	 * tree — then compares the selected id set with the oracle's, row by row, so a
	 * mismatch names the order. The 124 orders x 5 filters x 2 datastores this file
	 * covers stay a few seconds.
	 *
	 * @dataProvider meta_query_matches_oracle_provider
	 *
	 * @since 2.0.2
	 */
	public function test_the_selected_ids_match_the_row_semantics_oracle(
		int $mapped,
		int $bare,
		string $canonical,
		bool $negate,
		bool $is_hpos,
		string $fixture_label,
		string $filter_label,
		string $datastore_label
	): void {
		$registry  = $this->registry_of( $mapped, $bare );
		$providers = array_values( $registry->get_providers() );
		$request   = $negate ? [ 'delivery_status_not' => $canonical ] : [ 'delivery_status' => $canonical ];
		$rows      = $this->universe( $mapped, $bare );

		// Operator decision 26.09.2026 (YAGNI) + #928: the checked universe must hold no order
		// with two markers. Measured against the registered providers, not against
		// universe()'s own bookkeeping, so a change to either side turns this red.
		$this->assertNotEmpty( $rows, 'The narrowed universe must not be empty.' );

		foreach ( $rows as $meta ) {
			$markers = 0;

			foreach ( $providers as $provider ) {
				if ( array_key_exists( $provider->get_marker_meta_key(), $meta ) ) {
					++$markers;
				}
			}

			$this->assertLessThanOrEqual(
				1,
				$markers,
				sprintf( 'Multi-marker order in the gate universe (operator decision 26.09.2026, #928): %s', (string) json_encode( $meta ) )
			);
		}

		$query    = $this->query_over( $registry, $is_hpos, $rows );
		$selected = $this->selected_ids( $query, $request, $is_hpos );

		// The seam is handed exactly the registered marker keys (the id query's driver)
		// — a scope narrowed or widened here would be invisible to the walk below.
		$this->assertCount( 1, $query->resolved, 'exactly one id resolution per build' );
		$this->assertSame(
			array_map(
				static function ( Orders_Provider $provider ): string {
					return $provider->get_marker_meta_key();
				},
				$providers
			),
			$query->resolved[0][0]
		);

		foreach ( $rows as $index => $meta ) {
			$expected = $this->oracle_matches( $providers, $meta, $canonical, $negate );
			$actual   = in_array( $index + 1, $selected, true );

			$this->assertSame(
				$expected,
				$actual,
				sprintf(
					"Row-semantics mismatch between the independent oracle and the ids the query selects (#928).\n"
					. "fixture: %s\nfilter: %s\ndatastore: %s\norder id: %d\norder meta: %s\noracle verdict: %s\nquery verdict: %s",
					$fixture_label,
					$filter_label,
					$datastore_label,
					$index + 1,
					[] === $meta ? '(no meta at all)' : (string) json_encode( $meta ),
					$expected ? 'MATCH' : 'no match',
					$actual ? 'selected' : 'not selected'
				)
			);
		}
	}

	/**
	 * The empty ⇒ sentinel rule, proven on a universe where a real filter selects
	 * nothing: one bare carrier, `delivery_status_not=unknown` — a bare carrier is
	 * always unknown, so no order is "not unknown", the tree is the sentinel, the seam
	 * is never asked, and each datastore gets its own "matches nothing" form rather
	 * than an empty `post__in`.
	 *
	 * @since 2.0.2
	 */
	public function test_a_filter_no_order_satisfies_lands_on_the_sentinel_on_both_datastores(): void {
		foreach ( [ true, false ] as $is_hpos ) {
			$registry = $this->registry_of( 0, 1 );
			$rows     = $this->universe( 0, 1 );
			$query    = $this->query_over( $registry, $is_hpos, $rows );

			$this->assertSame( [], $this->selected_ids( $query, [ 'delivery_status_not' => Delivery_Status::UNKNOWN ], $is_hpos ) );
			$this->assertSame( [], $query->resolved, 'a tree that matches nothing is answered without the seam' );
		}
	}

	/**
	 * And the same rule when the seam IS asked and finds nothing: the resolver walks a
	 * universe with no order in scope at all (every row without a marker), returns the
	 * empty set, and `build_args()` must still emit the sentinel, never `post__in => []`.
	 *
	 * @since 2.0.2
	 */
	public function test_an_id_resolution_that_finds_nothing_lands_on_the_sentinel_on_both_datastores(): void {
		foreach ( [ true, false ] as $is_hpos ) {
			$registry = $this->registry_of( 1, 0 );
			$rows     = array_values(
				array_filter(
					$this->universe( 1, 0 ),
					static function ( array $meta ): bool {
						return ! array_key_exists( '_m1_marker', $meta );
					}
				)
			);
			$query    = $this->query_over( $registry, $is_hpos, $rows );

			$this->assertNotEmpty( $rows );
			$this->assertSame( [], $this->selected_ids( $query, [ 'delivery_status' => Delivery_Status::UNKNOWN ], $is_hpos ) );
			$this->assertCount( 1, $query->resolved, 'the seam WAS asked this time' );
		}
	}
}
