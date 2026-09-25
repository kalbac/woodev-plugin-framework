<?php
/**
 * Card #839 phase 2, the equivalence question, decided by exhaustive enumeration
 * instead of reasoning: does dropping the marker SCOPE part change which rows the
 * delivery-status filter selects? No database — the meta_query tree is evaluated
 * directly against every possible order shape.
 */

require_once __DIR__ . '/wp-harness.php';

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

/**
 * Evaluates one meta_query tree against one order's meta.
 * Leaf semantics follow WP_Meta_Query / MySQL, INCLUDING the trap that `NOT IN`
 * does not match a row with no such meta row at all (gotcha
 * a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all).
 *
 * @param array<int|string,mixed> $tree
 * @param array<string,string>    $meta key => value for the meta rows that exist.
 */
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
					// A missing meta row produces NO row to compare, so it does NOT match.
					$results[] = $exists && ! in_array( $value, (array) $node['value'], true );
					break;
				case '=':
					$results[] = $exists && $value === $node['value'];
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

/** Two carriers: one with a status map, one with none — plus a third for the 3-carrier run. */
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

/** Every possible order over these carriers: marker present/absent x status absent/mapped/unmapped. */
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

$cases = [
	'delivery_status=unknown'        => [ 'delivery_status' => Delivery_Status::UNKNOWN ],
	'delivery_status=in_transit'     => [ 'delivery_status' => Delivery_Status::IN_TRANSIT ],
	'delivery_status=delivered'      => [ 'delivery_status' => Delivery_Status::DELIVERED ],
	'delivery_status_not=in_transit' => [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ],
	'delivery_status_not=unknown'    => [ 'delivery_status_not' => Delivery_Status::UNKNOWN ],
];

$fixtures = [
	'2 carriers (1 mapped, 1 bare)' => [ 1, 1 ],
	'2 carriers (both mapped)'      => [ 2, 0 ],
	'3 carriers (2 mapped, 1 bare)' => [ 2, 1 ],
];

foreach ( $fixtures as $fixture_label => [ $mapped, $bare ] ) {
	echo "\n=== {$fixture_label} ===\n";
	$rows = universe( $mapped, $bare );
	echo 'universe: ' . count( $rows ) . " possible orders\n";

	foreach ( $cases as $label => $request ) {
		$registry = registry_of( $mapped, $bare );
		$cpt      = ( new Probe_Query( $registry, false ) )->build_args( $request );

		$clauses = $cpt[ Orders_Query::QUERY_VAR_STATUS_CLAUSES ] ?? null;
		if ( null === $clauses ) {
			echo "  {$label}: no status clauses emitted (filter not applied)\n";
			continue;
		}

		$keys = array_map(
			static fn( Orders_Provider $p ): string => $p->get_marker_meta_key(),
			array_values( $registry->get_providers() )
		);

		$status_part = Orders_Query::meta_query_for_clauses( $clauses );
		$old         = Orders_Query::combine_meta_queries( [ Orders_Query::meta_query_for_keys( $keys ), $status_part ] );

		$registry_hpos = registry_of( $mapped, $bare );
		$new           = ( new Probe_Query( $registry_hpos, true ) )->build_args( $request )['meta_query'];

		$only_old = [];
		$only_new = [];

		foreach ( $rows as $row ) {
			$a = matches( $old, $row );
			$b = matches( $new, $row );
			if ( $a && ! $b ) { $only_old[] = $row; }
			if ( $b && ! $a ) { $only_new[] = $row; }
		}

		printf(
			"  %-32s old-only=%d  new-only=%d  %s\n",
			$label,
			count( $only_old ),
			count( $only_new ),
			( [] === $only_old && [] === $only_new ) ? 'IDENTICAL' : 'DIFFERS'
		);

		foreach ( array_slice( $only_old, 0, 3 ) as $r ) {
			echo '      only OLD matched: ' . ( [] === $r ? '(no meta at all)' : json_encode( $r ) ) . "\n";
		}
		foreach ( array_slice( $only_new, 0, 3 ) as $r ) {
			echo '      only NEW matched: ' . ( [] === $r ? '(no meta at all)' : json_encode( $r ) ) . "\n";
		}
	}
}
