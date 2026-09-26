<?php
/**
 * Card #919 (design phase): does "compute the set of orders whose OWN carrier's
 * status is mapped, and exclude it with one set-subtraction" preserve row
 * semantics, and what would it cost? Decided by exhaustive enumeration (never by
 * reasoning alone, per #919's own requirement), and by counting real JOINs
 * WP_Meta_Query emits for the one-query building block the proposal needs — no
 * database touched, same technique as #839's measure.php/equivalence.php.
 *
 * Reuses the shared harness (wp-harness.php) and mirrors equivalence.php's
 * registry_of()/universe() exactly, so this probe describes the same carriers as
 * the #839 evidence and the s139 gate test.
 */

require_once __DIR__ . '/../2026-09-25-839-join-growth-evidence/wp-harness.php';

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

final class Probe_Query extends Orders_Query {
	private bool $hpos;
	public function __construct( ?Orders_Registry $registry, bool $hpos ) {
		parent::__construct( $registry );
		$this->hpos = $hpos;
	}
	protected function is_hpos_enabled(): bool { return $this->hpos; }
}

/** Mirrors equivalence.php's registry_of() exactly. */
function registry_of( int $mapped, int $bare ): Orders_Registry {
	$registry = Orders_Registry::instance();
	$registry->reset_for_tests();

	for ( $i = 1; $i <= $mapped; $i++ ) {
		$registry->register_provider(
			Orders_Provider::create(
				"m{$i}", "M{$i}", "_m{$i}_marker", [ "m{$i}" ],
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
		$registry->register_provider( Orders_Provider::create( "b{$i}", "B{$i}", "_b{$i}_marker", [ "b{$i}" ] ) );
	}

	return $registry;
}

/** Mirrors equivalence.php's universe() exactly — every marker/status combination, INCLUDING more than one marker present on the same order (the case the proposal's cost model implicitly assumes cannot happen). */
function universe( int $mapped, int $bare ): array {
	$axes = [];
	for ( $i = 1; $i <= $mapped; $i++ ) {
		$axes[] = [ [ "_m{$i}_marker" => '1' ], [] ];
		$axes[] = [ [], [ "_m{$i}_status" => "M{$i}_GO" ], [ "_m{$i}_status" => "M{$i}_DONE" ], [ "_m{$i}_status" => 'JUNK' ] ];
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

	return $rows;
}

/** The SAME leaf-evaluator equivalence.php uses (WP_Meta_Query / MySQL leaf semantics). */
function matches( array $tree, array $meta ): bool {
	$relation = strtoupper( (string) ( $tree['relation'] ?? 'AND' ) );
	$results  = [];

	foreach ( $tree as $k => $node ) {
		if ( 'relation' === $k || ! is_array( $node ) ) {
			continue;
		}
		if ( isset( $node['key'] ) ) {
			$key     = $node['key'];
			$exists  = array_key_exists( $key, $meta );
			$value   = $exists ? $meta[ $key ] : null;
			$compare = strtoupper( (string) ( $node['compare'] ?? '=' ) );
			switch ( $compare ) {
				case 'EXISTS':
					$results[] = $exists;
					break;
				case 'NOT EXISTS':
					$results[] = ! $exists;
					break;
				case 'IN':
					$results[] = $exists && in_array( $value, (array) $node['value'], true );
					break;
				case 'NOT IN':
					$results[] = $exists && ! in_array( $value, (array) $node['value'], true );
					break;
				default:
					throw new RuntimeException( "unhandled compare {$compare}" );
			}
			continue;
		}
		$results[] = matches( $node, $meta );
	}

	if ( [] === $results ) {
		return true;
	}

	return 'OR' === $relation
		? in_array( true, $results, true )
		: ! in_array( false, $results, true );
}

/**
 * Q1/Q3 building block: the meta_query a "mapped ids" precomputation query would
 * run — an OR of one IN(known-for-that-provider) leaf per mapped provider. No
 * NOT, no marker binding: a value on a provider's own status key already implies
 * that provider's order (the SAME invariant the codebase already relies on for
 * the plain `delivery_status=<canonical>` clause — class-orders-query.php's
 * `delivery_status_meta_clauses()`, the un-negated specific-canonical branch).
 */
function mapped_ids_meta_query( Orders_Registry $registry, bool $bind_to_own_marker = false ): array {
	$clauses = [ 'relation' => 'OR' ];
	foreach ( $registry->get_providers() as $provider ) {
		$status_key = $provider->get_status_meta_key();
		if ( null === $status_key ) {
			continue;
		}
		$inverted = Delivery_Status::invert_status_map( $provider->get_status_map() );
		$known    = [];
		foreach ( $inverted as $raw_values_for_state ) {
			$known = array_merge( $known, $raw_values_for_state );
		}
		$known = array_values( array_unique( $known ) );
		if ( [] === $known ) {
			continue;
		}
		$leaf = [ 'key' => $status_key, 'value' => $known, 'compare' => 'IN' ];

		// UNBOUND is cheap only because it leans on the invariant #924 is about: a carrier
		// never writes another carrier's status meta. BOUND is what card #919 requirement 1
		// demands, and it costs more, because each carrier then needs its marker row AND its
		// status row joined simultaneously — an AND cannot share one alias the way an OR can.
		$clauses[] = $bind_to_own_marker
			? [
				'relation' => 'AND',
				[ 'key' => $provider->get_marker_meta_key(), 'compare' => 'EXISTS' ],
				$leaf,
			]
			: $leaf;
	}
	return $clauses;
}

/**
 * The proposed rewrite's semantics for `delivery_status=unknown`, evaluated
 * directly against one order's meta (not through WP_Meta_Query — this is the
 * INDEPENDENT-of-the-builder oracle side, same role equivalence.php's `matches()`
 * plays for the current tree, mirroring the two-query shape Q1 found reachable:
 * FULL_SCOPE (any registered marker present) AND NOT (any provider's OWN status
 * is a KNOWN one).
 */
function proposed_new_matches_unknown( array $providers, array $meta, bool $bind_to_own_marker = false ): bool {
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
		$status_key = $provider->get_status_meta_key();
		if ( null === $status_key || ! array_key_exists( $status_key, $meta ) ) {
			continue;
		}

		// Card #919 requirement 1 demands the exclusion set stay bound to its own carrier's
		// marker STRUCTURALLY. The UNBOUND reading is what the card's prose literally
		// describes; the BOUND one is what a competent implementer would build. Both are
		// measured, because "the card was killed by a straw man" is the first objection a
		// reader should be able to check rather than take on trust.
		if ( $bind_to_own_marker && ! array_key_exists( $provider->get_marker_meta_key(), $meta ) ) {
			continue;
		}

		$inverted = Delivery_Status::invert_status_map( $provider->get_status_map() );
		$known    = [];
		foreach ( $inverted as $raw_values_for_state ) {
			$known = array_merge( $known, $raw_values_for_state );
		}
		if ( in_array( $meta[ $status_key ], array_values( array_unique( $known ) ), true ) ) {
			// This order's OWN carrier reports a known status => excluded from "unknown".
			return false;
		}
	}

	return true;
}

echo "=== Q4: does the exclusion form survive multi-marker orders? ===\n";
echo "(mirrors #839's equivalence.php fixtures)\n\n";

$fixtures = [
	'2 carriers (1 mapped, 1 bare)' => [ 1, 1 ],
	'2 carriers (both mapped)'      => [ 2, 0 ],
	'3 carriers (2 mapped, 1 bare)' => [ 2, 1 ],
];

foreach ( $fixtures as $label => [ $mapped, $bare ] ) {
	$registry  = registry_of( $mapped, $bare );
	$providers = array_values( $registry->get_providers() );
	$old_tree  = ( new Probe_Query( $registry, true ) )->build_args( [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['meta_query'];
	$rows      = universe( $mapped, $bare );

	$only_old = [];
	$only_new = [];
	$multi_marker_mismatches = [];
	$orphan_only_mismatches  = [];

	foreach ( $rows as $row ) {
		$markers_present = 0;
		foreach ( $providers as $p ) {
			if ( array_key_exists( $p->get_marker_meta_key(), $row ) ) {
				++$markers_present;
			}
		}

		$a = matches( $old_tree, $row );
		$b = proposed_new_matches_unknown( $providers, $row );

		if ( $a !== $b ) {
			if ( $a && ! $b ) { $only_old[] = $row; }
			if ( $b && ! $a ) { $only_new[] = $row; }
			// Classify ORPHAN FIRST, then multi-marker. A row carrying a status value on a
			// provider's own key while that provider's marker is ABSENT is physically
			// impossible for a carrier plugin to produce, and it stays impossible however
			// many OTHER markers it carries — so counting such a row as "multi-marker"
			// inflates the realistic-failure count. Measured: doing it the other way round
			// reports 44 multi-marker rows in the 3-carrier fixture where only 36 are pure.
			$has_orphan_status = false;

			foreach ( $providers as $p ) {
				$status_key = $p->get_status_meta_key();

				if ( $status_key && array_key_exists( $status_key, $row ) && ! array_key_exists( $p->get_marker_meta_key(), $row ) ) {
					$has_orphan_status = true;
					break;
				}
			}

			if ( $has_orphan_status ) {
				$orphan_only_mismatches[] = $row;
			} elseif ( $markers_present > 1 ) {
				$multi_marker_mismatches[] = $row;
			}
		}
	}

	$mismatches = count( $only_old ) + count( $only_new );

	printf(
		"%-32s universe=%-4d old-only=%-3d new-only=%-3d  %s  (pure multi-marker: %d, orphan-status: %d, unexplained: %d)\n",
		$label,
		count( $rows ),
		count( $only_old ),
		count( $only_new ),
		( [] === $only_old && [] === $only_new ) ? 'IDENTICAL' : 'DIFFERS',
		count( $multi_marker_mismatches ),
		count( $orphan_only_mismatches ),
		$mismatches - count( $multi_marker_mismatches ) - count( $orphan_only_mismatches )
	);

	foreach ( array_slice( $multi_marker_mismatches, 0, 2 ) as $r ) {
		echo '    multi-marker mismatch, old matched but new did not: ' . json_encode( $r ) . "\n";
	}

	foreach ( array_slice( $orphan_only_mismatches, 0, 2 ) as $r ) {
		echo '    orphan-status mismatch (a status with no marker of its own — physically impossible): ' . json_encode( $r ) . "\n";
	}
}

echo "\n=== Q4b: the FAIR model — exclusion set bound to its own marker (card #919 requirement 1) ===\n";
echo "If the divergence were only an artefact of an unbound exclusion set, this run would come back\n";
echo "IDENTICAL. It does not: the orphan-status class disappears and every remaining mismatch is a\n";
echo "genuine multi-marker order, which is the case the shipped carrier plugins CAN produce.\n\n";

foreach ( $fixtures as $label => [ $mapped, $bare ] ) {
	$registry  = registry_of( $mapped, $bare );
	$providers = array_values( $registry->get_providers() );
	$old_tree  = ( new Probe_Query( $registry, true ) )->build_args( [ 'delivery_status' => Delivery_Status::UNKNOWN ] )['meta_query'];

	$only_old = [];
	$only_new = [];
	$multi    = 0;

	foreach ( universe( $mapped, $bare ) as $row ) {
		$a = matches( $old_tree, $row );
		$b = proposed_new_matches_unknown( $providers, $row, true );

		if ( $a === $b ) {
			continue;
		}

		if ( $a ) {
			$only_old[] = $row;
		} else {
			$only_new[] = $row;
		}

		$markers_present = 0;

		foreach ( $providers as $p ) {
			if ( array_key_exists( $p->get_marker_meta_key(), $row ) ) {
				++$markers_present;
			}
		}

		if ( $markers_present > 1 ) {
			++$multi;
		}
	}

	printf(
		"%-32s old-only=%-3d new-only=%-3d  multi-marker=%-3d single-marker=%d  %s\n",
		$label,
		count( $only_old ),
		count( $only_new ),
		$multi,
		count( $only_old ) + count( $only_new ) - $multi,
		( [] === $only_old && [] === $only_new ) ? 'IDENTICAL' : 'DIFFERS'
	);
}

echo "\n=== Q3: cost of the 'mapped ids' precomputation query (leaf clauses / real JOINs) ===\n";
echo "Both readings are measured: UNBOUND leans on #924's unwritten invariant, BOUND is what card\n";
echo "requirement 1 actually demands. Quoting only the unbound figure understates the idea's cost.\n\n";
echo "| M (mapped carriers) | leaves | JOINs unbound | JOINs BOUND to own marker |\n";
echo "|---|---|---|---|\n";
for ( $m = 1; $m <= 6; $m++ ) {
	$registry = registry_of( $m, 0 );
	$unbound  = mapped_ids_meta_query( $registry );
	$bound    = mapped_ids_meta_query( $registry, true );
	$ju       = join_sql( $unbound );
	$jb       = join_sql( $bound );
	printf(
		"| %d | %d | %d (left=%d inner=%d) | %d (left=%d inner=%d) |\n",
		$m,
		leaf_count( $unbound ),
		$ju['total'], $ju['left'], $ju['inner'],
		$jb['total'], $jb['left'], $jb['inner']
	);
}

echo "\nFor comparison, the s139 (post-#839-step-2) cost of the SAME filter, 3M+B:\n";
for ( $m = 1; $m <= 6; $m++ ) {
	$registry = registry_of( $m, 0 );
	$args     = ( new Probe_Query( $registry, true ) )->build_args( [ 'delivery_status' => Delivery_Status::UNKNOWN ] );
	$j        = join_sql( $args['meta_query'] );
	printf( "M=%d: %d joins (current s139 shape)\n", $m, $j['total'] );
}
