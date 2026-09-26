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
 * This file proves something that survives BOTH #839 step 2 and #919's subquery
 * rewrite: the emitted `meta_query` (on both datastores) against a predicate written
 * straight from the carrier definitions and the order's own meta, never derived from
 * {@see Orders_Query}'s OUTPUT. One reading is adopted from that class's documented
 * invariant rather than derived mechanically, and it is named where it is taken — see
 * {@see self::provider_satisfies_delivery_status()}. If a future rewrite changes which
 * rows the filter selects, this is the file that turns red.
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
	 * Builds an Orders_Query pinned to the HPOS branch, so `meta_query` is emitted
	 * directly instead of the legacy custom query vars.
	 *
	 * @since 2.0.2
	 */
	private function query_with_hpos( Orders_Registry $registry ): Orders_Query {
		return new class( $registry ) extends Orders_Query {
			protected function is_hpos_enabled(): bool {
				return true;
			}
		};
	}

	/**
	 * Builds the `meta_query` tree a given datastore path ultimately produces for
	 * `$request`. On HPOS this is `build_args()['meta_query']` directly; on the legacy
	 * CPT datastore `build_args()` returns the custom query vars instead, so this
	 * routes them through {@see Orders_Registry::translate_marker_keys_query_var()} —
	 * the SAME translation the real `woocommerce_order_data_store_cpt_get_orders_query`
	 * filter calls — rather than hand-reconstructing what that translation does.
	 *
	 * @since 2.0.2
	 *
	 * @param array<string,mixed> $request
	 * @return array<int|string,mixed>
	 */
	private function built_tree( Orders_Registry $registry, array $request, bool $is_hpos ): array {
		if ( $is_hpos ) {
			$args = $this->query_with_hpos( $registry )->build_args( $request );

			return $args['meta_query'];
		}

		$args = ( new Orders_Query( $registry ) )->build_args( $request );

		return $registry->translate_marker_keys_query_var( [], $args )['meta_query'];
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
	 * contract — the cheaper negative-filter form #928 introduces is correct only under that
	 * rule, and the rows it would disagree on are dropped here EXPLICITLY, not left to
	 * fail. The rule itself is not enforced by the data layer; under `WP_DEBUG`
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
	 * Builds the tree and the universe ONCE per (fixture, filter, datastore), then
	 * loops the 12-64 rows against both — not the reverse, so the 124 orders x 5
	 * filters x 2 datastores this file covers stays a few seconds, not a rebuild per
	 * row.
	 *
	 * @dataProvider meta_query_matches_oracle_provider
	 *
	 * @since 2.0.2
	 */
	public function test_meta_query_matches_the_row_semantics_oracle(
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

		$tree = $this->built_tree( $registry, $request, $is_hpos );
		$rows = $this->universe( $mapped, $bare );

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

		foreach ( $rows as $meta ) {
			$expected = $this->oracle_matches( $providers, $meta, $canonical, $negate );
			$actual   = $this->matches_query( $tree, $meta );

			$this->assertSame(
				$expected,
				$actual,
				sprintf(
					"Row-semantics mismatch between the independent oracle and the built meta_query.\n"
					. "fixture: %s\nfilter: %s\ndatastore: %s\norder meta: %s\noracle verdict: %s\nbuilt-query verdict: %s",
					$fixture_label,
					$filter_label,
					$datastore_label,
					[] === $meta ? '(no meta at all)' : (string) json_encode( $meta ),
					$expected ? 'MATCH' : 'no match',
					$actual ? 'MATCH' : 'no match'
				)
			);
		}
	}
}
