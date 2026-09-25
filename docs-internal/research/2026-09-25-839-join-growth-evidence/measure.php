<?php
/**
 * Phase 1 of card #839: measure the LEFT JOIN growth law of the aggregate
 * delivery-status filter WITHOUT executing a single query.
 */

require_once __DIR__ . '/wp-harness.php';

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

/** Orders_Query with the datastore branch pinned, exactly as the unit suite does. */
final class Probe_Query extends Orders_Query {
	private bool $hpos;
	public function __construct( ?Orders_Registry $registry, bool $hpos ) {
		parent::__construct( $registry );
		$this->hpos = $hpos;
	}
	protected function is_hpos_enabled(): bool { return $this->hpos; }
}

/**
 * @param int $with    carriers that own a status_meta_key AND a status_map.
 * @param int $without carriers with no status concept at all.
 */
function registry_of( int $with, int $without ): Orders_Registry {
	$registry = Orders_Registry::instance();
	$registry->reset_for_tests();

	for ( $i = 1; $i <= $with; $i++ ) {
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
					'tracking_meta_key' => "_mapped{$i}_tracking",
				]
			)
		);
	}

	for ( $i = 1; $i <= $without; $i++ ) {
		$registry->register_provider(
			Orders_Provider::create( "bare{$i}", "Bare {$i}", "_bare{$i}_marker", [ "bare{$i}" ] )
		);
	}

	return $registry;
}

/** @return array{leaves:int,left:int,inner:int,total:int} */
function measure( int $with, int $without, array $request ): array {
	$registry = registry_of( $with, $without );
	$args     = ( new Probe_Query( $registry, true ) )->build_args( $request );
	$mq       = $args['meta_query'];
	$j        = join_sql( $mq );

	return [
		'leaves' => leaf_count( $mq ),
		'left'   => $j['left'],
		'inner'  => $j['inner'],
		'total'  => $j['total'],
	];
}

$requests = [
	'no filter'                => [],
	'delivery_status=unknown'  => [ 'delivery_status' => Delivery_Status::UNKNOWN ],
	'delivery_status=in_transit' => [ 'delivery_status' => Delivery_Status::IN_TRANSIT ],
	'delivery_status_not=in_transit' => [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ],
	'delivery_status_not=unknown' => [ 'delivery_status_not' => Delivery_Status::UNKNOWN ],
];

$shapes = [
	'ALL carriers HAVE a status map'      => static fn( int $n ): array => [ $n, 0 ],
	'NO carrier has a status map'         => static fn( int $n ): array => [ 0, $n ],
	'HALF and HALF (the s128 fixture)'    => static fn( int $n ): array => [ intdiv( $n, 2 ), $n - intdiv( $n, 2 ) ],
];

foreach ( $shapes as $shape_label => $split ) {
	echo "\n### {$shape_label}\n\n";
	$head = "| N | " . implode( ' | ', array_keys( $requests ) ) . " |";
	echo $head . "\n";
	echo '|' . str_repeat( '---|', count( $requests ) + 1 ) . "\n";

	for ( $n = 1; $n <= 6; $n++ ) {
		[ $with, $without ] = $split( $n );
		$cells = [];
		foreach ( $requests as $req ) {
			$m = measure( $with, $without, $req );
			$cells[] = sprintf( '%d / %d', $m['leaves'], $m['total'] );
		}
		echo "| {$n} (map={$with}, bare={$without}) | " . implode( ' | ', $cells ) . " |\n";
	}
}

echo "\nCells are `leaf clauses / real JOINs emitted by WP_Meta_Query`.\n";
