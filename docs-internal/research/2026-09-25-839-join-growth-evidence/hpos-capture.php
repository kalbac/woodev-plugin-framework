<?php
/**
 * Card #839 phase 1, anchor: the REAL HPOS SQL for the aggregate delivery-status
 * filter, two carriers only (inside the card's hard limit; s128 measured this
 * envelope at 24-106 ms). Captured through woocommerce_orders_table_query_clauses.
 */

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

$registry = Orders_Registry::instance();
$registry->reset_for_tests();

foreach ( [ 1, 2 ] as $i ) {
	$registry->register_provider(
		Orders_Provider::create(
			"mapped{$i}", "Mapped {$i}", "_mapped{$i}_marker", [ "mapped{$i}" ],
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

$joins = [];

add_filter(
	'woocommerce_orders_table_query_clauses',
	function ( $clauses ) use ( &$joins ) {
		$joins[] = (string) ( $clauses['join'] ?? '' );
		return $clauses;
	},
	999
);

$query = new Orders_Query( $registry );

$cases = [
	'no filter'                      => [],
	'delivery_status=unknown'        => [ 'delivery_status' => Delivery_Status::UNKNOWN ],
	'delivery_status=in_transit'     => [ 'delivery_status' => Delivery_Status::IN_TRANSIT ],
	'delivery_status_not=in_transit' => [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ],
	'delivery_status_not=unknown'    => [ 'delivery_status_not' => Delivery_Status::UNKNOWN ],
];

echo "HPOS, 2 carriers, both with a status map. Real SQL via woocommerce_orders_table_query_clauses.\n\n";

foreach ( $cases as $label => $request ) {
	$args  = $query->build_args( $request );
	$joins = [];

	$start  = microtime( true );
	$result = wc_get_orders( $args );
	$ms     = ( microtime( true ) - $start ) * 1000;

	$join  = $joins[0] ?? '';
	$left  = preg_match_all( '/\bLEFT\s+JOIN\b/i', $join );
	$inner = preg_match_all( '/\bINNER\s+JOIN\b/i', $join );
	$total = is_object( $result ) && isset( $result->total ) ? $result->total : -1;

	printf(
		"%-32s LEFT=%d INNER=%d total_joins=%d  rows=%d  %.0f ms\n",
		$label, $left, $inner, $left + $inner, $total, $ms
	);
	echo '    ' . trim( preg_replace( '/\s+/', ' ', $join ) ) . "\n\n";
}
