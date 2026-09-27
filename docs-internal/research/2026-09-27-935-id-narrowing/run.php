<?php
/**
 * #935 — does narrowing the resolver's id query (order status, created-date) or counting carriers with one GROUP BY
 * win materially? Replayable measurement driver, parameterised by environment variables. Runs INSIDE the wp-env
 * `cli` container (`wp eval-file`), against the throwaway database `probe935` ONLY.
 *
 *   C=$(scripts/machine/rig-container.sh cli)
 *   docker cp docs-internal/research/2026-09-27-935-id-narrowing $C:/tmp/f935
 *   docker exec -e SIZE=10000 -e OUT=/tmp/f935/r.jsonl $C wp eval-file /tmp/f935/run.php
 *   docker cp $C:/tmp/f935/r.jsonl /tmp/r935.jsonl && php summarize.php /tmp/r935.jsonl
 *   docker exec "$(scripts/machine/rig-container.sh mysql)" mariadb -uroot -ppassword -e 'DROP DATABASE probe935'
 *
 *   SIZE       orders per datastore                  default 10000
 *   CONFIG     M:B (M mapped carriers, B bare)       default 4:0
 *   STORES     hpos,cpt                              default hpos,cpt
 *   MODES      today,a_exists,a_join,a_drive,a_in,…  default today,a_exists,a_join (see lib.php narrowed_id_sql)
 *   PAGELOAD   0 => only the single-request rows     default 1
 *   SCENARIOS  comma list of scenario names          default all (see scenarios())
 *   RUNS       timed runs after one warm-up          default 5
 *   OUT        JSONL path                            default /tmp/f935/results.jsonl
 *   UNRELATED  unrelated meta rows per order 1..20   default 20
 *   SEED       0 => reuse an already-seeded probe935 default 1
 */

require_once __DIR__ . '/lib.php';

use Woodev\Framework\Shipping\Admin\Orders\Orders_Id_Resolver;
use function Woodev\Research935\{ connect, create_schema, seed_base, seed_carriers, registry_of, capture, timed, explain, q, no_cache };
use Woodev\Research935\Probe_Query;

$size    = (int) ( getenv( 'SIZE' ) ?: 10000 );
$config  = array_map( 'intval', explode( ':', getenv( 'CONFIG' ) ?: '4:0' ) );
$stores  = explode( ',', getenv( 'STORES' ) ?: 'hpos,cpt' );
$modes   = explode( ',', getenv( 'MODES' ) ?: 'today,a_exists,a_join' );
$runs    = (int) ( getenv( 'RUNS' ) ?: 5 );
$out     = getenv( 'OUT' ) ?: __DIR__ . '/results.jsonl';
$reseed  = '0' !== getenv( 'SEED' );

$root = connect( null );
if ( $reseed ) {
	create_schema( $root, __DIR__ . '/ddl.sql' );
}
$m = connect();
Probe_Query::$m    = $m;
Probe_Query::$runs = $runs;

if ( $reseed ) {
	$t0 = microtime( true );
	seed_base( $m, $size );
	$stat = seed_carriers( $m, $size, $config[0], $config[1] );
	fwrite( STDERR, sprintf( "== seeded %d orders x2 datastores in %.1fs %s\n", $size, microtime( true ) - $t0, json_encode( $stat ) ) );
}

[ $M, $B ] = $config;
$registry  = registry_of( $M, $B );
$providers = [];
for ( $i = 1; $i <= $M; $i++ ) { $providers[] = "m{$i}"; }
for ( $i = 1; $i <= $B; $i++ ) { $providers[] = "b{$i}"; }

/** Seeded created-dates span 2023-01-01 .. 2025-12-31 evenly; the windows below sit in the middle. */
function scenarios( string $store ): array {
	$narrow = 'hpos' === $store ? 'Name123' : 'Order 123'; // a handful of hits (order id 123, 1230-1239, …)
	$broad  = 'hpos' === $store ? 'Surname' : 'Order';     // every order matches
	return [
		'none'            => [],
		'status_common'   => [ 'status' => [ 'processing' ] ],                    // ~15 % of orders
		'status_rare'     => [ 'status' => [ 'cancelled', 'failed' ] ],           // ~5 %
		'period_1w'       => [ 'after' => '2024-07-01', 'before' => '2024-07-07' ],
		'period_30d'      => [ 'after' => '2024-06-01', 'before' => '2024-06-30' ],
		'status_period'   => [ 'status' => [ 'processing' ], 'after' => '2024-07-01', 'before' => '2024-07-07' ],
		'period_6m'       => [ 'after' => '2024-01-01', 'before' => '2024-06-30' ],  // ~16 % of the store
		'period_1y'       => [ 'after' => '2024-01-01', 'before' => '2024-12-31' ],  // ~33 %
		'period_all'      => [ 'after' => '2023-01-01', 'before' => '2025-12-31' ],  // everything
		'search_narrow'   => [ 'search' => $narrow ],
		'search_broad'    => [ 'search' => $broad ],
	];
}
$only = getenv( 'SCENARIOS' ) ? explode( ',', getenv( 'SCENARIOS' ) ) : null;

/** What candidate A may push into the id query for a request (match=all only — these scenarios never use `any`). */
function narrow_for( array $request ): array {
	$statuses = [];
	if ( ! empty( $request['status'] ) ) {
		foreach ( (array) $request['status'] as $s ) {
			$statuses[] = 0 === strpos( $s, 'wc-' ) ? $s : 'wc-' . $s;
		}
	} else {
		$statuses = array_keys( array_diff_key( wc_get_order_statuses(), array_flip( [ 'wc-cancelled', 'wc-failed' ] ) ) );
	}
	$day = static fn( string $d, int $n ) => gmdate( 'Y-m-d', strtotime( "$d $n day" ) );
	return [
		'statuses' => $statuses,
		'from'     => ! empty( $request['after'] ) ? $day( $request['after'], -1 ) : null,
		'to'       => ! empty( $request['before'] ) ? $day( $request['before'], 1 ) : null,
	];
}

$emit = static function ( array $row ) use ( $out ) {
	file_put_contents( $out, json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
	fwrite( STDERR, sprintf( "%-12s %-4s %-14s %-9s idq=%-8s ids=%-6s main=%-8s count=%-8s total=%-8s found=%s\n",
		$row['kind'], $row['store'], $row['scenario'] ?? '-', $row['mode'] ?? '-', $row['idq_ms'] ?? '-', $row['ids_n'] ?? '-',
		$row['main_ms'] ?? '-', $row['count_ms'] ?? '-', $row['total_ms'] ?? '-', $row['found'] ?? '-' ) );
};

/**
 * One request end to end: id query (through the seam) → real `build_args()` → real datastore SQL → run on probe935.
 *
 * @return array<string,mixed>
 */
$run_request = static function ( Probe_Query $q, string $store, array $request ) use ( $m, $runs ): array {
	$q->id_calls = [];
	$q->narrow   = narrow_for( $request );
	$args        = $q->build_args( $request );
	$cap         = capture( $store, $args );
	$main        = timed( $m, $cap['main'], $runs );
	if ( $main['error'] ) {
		return [ 'error' => $main['error'], 'idq_ms' => array_sum( array_column( $q->id_calls, 'ms' ) ) ];
	}
	$res = [
		'idq_ms'   => round( array_sum( array_column( $q->id_calls, 'ms' ) ), 1 ),
		'ids_n'    => (int) array_sum( array_column( $q->id_calls, 'n' ) ),
		'ids_bytes' => (int) array_sum( array_column( $q->id_calls, 'bytes' ) ),
		'idq_sql_len' => (int) array_sum( array_column( $q->id_calls, 'sql_len' ) ),
		'main_ms'  => round( $main['ms'], 1 ),
		'sql_len'  => strlen( $cap['main'] ),
		'build_ms' => round( $cap['build_ms'], 1 ),
	];
	if ( 'hpos' === $store ) {
		$c              = timed( $m, $cap['count'], $runs, true );
		$res['count_ms'] = round( $c['ms'], 1 );
		$res['found']    = (int) $c['scalar'];
	} else {
		$m->query( no_cache( $cap['main'] ) );
		$res['count_ms'] = 0.0; // SQL_CALC_FOUND_ROWS: the main statement already paid for the count.
		$res['found']    = (int) $m->query( 'SELECT FOUND_ROWS()' )->fetch_row()[0];
	}
	$res['total_ms'] = round( $res['idq_ms'] + $res['main_ms'] + $res['count_ms'], 1 );
	$ids = [];
	$r   = $m->query( $cap['main'] );
	while ( $row = $r->fetch_row() ) { $ids[] = (int) $row[0]; }
	$res['page_ids'] = md5( implode( ',', $ids ) );
	$res['page_n']   = count( $ids );
	return $res;
};

/** The REST controller's request sequence for one page load: rows + N carrier counts + the scope count(s). */
$sequence_page = static function ( array $request, array $providers ): array {
	$reqs = [ 'page' => $request ];
	foreach ( $providers as $p ) {
		$reqs[ "carrier:$p" ] = array_merge( $request, [ 'carrier' => $p, 'page' => 1, 'per_page' => 1 ] );
	}
	$all = $request;
	unset( $all['is_exported'] );
	// `build_scope_counts()`: «Все» reuses the page's total when the request has no is_exported; «Новые» is one more query.
	$reqs['scope:new'] = array_merge( $all, [ 'is_exported' => false, 'page' => 1, 'per_page' => 1 ] );
	return $reqs;
};

/** The menu badge's sequence (`Orders_Registry::query_new_order_counts()`): the aggregate + one per carrier. */
$sequence_badge = static function ( array $providers ): array {
	$reqs = [ 'all' => [ 'carrier' => 'all', 'is_exported' => false, 'per_page' => 1 ] ];
	foreach ( $providers as $p ) {
		$reqs[ "carrier:$p" ] = [ 'carrier' => $p, 'is_exported' => false, 'per_page' => 1 ];
	}
	return $reqs;
};

/** Candidate B (harness form): every carrier's count in ONE statement, GROUP BY marker key WITH ROLLUP. */
$carrier_counts_b = static function ( Probe_Query $q, string $store, array $request, array $providers, bool $join ) use ( $m, $runs ): array {
	global $wpdb;
	$hpos  = 'hpos' === $store;
	$table = $hpos ? 'wp_wc_orders_meta' : 'wp_postmeta';
	$idc   = $hpos ? 'order_id' : 'post_id';
	$ors   = [];
	$q->mode = 'today';
	foreach ( $providers as $p ) {
		$q->id_calls = [];
		$q->capture_only = true;
		$q->build_args( array_merge( $request, [ 'carrier' => $p, 'page' => 1, 'per_page' => 1 ] ) );
		$q->capture_only = false;
		$call = $q->id_calls[0] ?? null;
		if ( ! $call ) {
			continue;
		}
		$sql   = ( new Orders_Id_Resolver( $wpdb, $hpos ) )->compile( $call['keys'], $call['tree'] );
		$ors[] = '(' . substr( $sql, strpos( $sql, ' WHERE ' ) + 7 ) . ')';
	}
	$n   = narrow_for( $request );
	$o   = $hpos ? [ 'wp_wc_orders', 'id', 'status', 'date_created_gmt' ] : [ 'wp_posts', 'ID', 'post_status', 'post_date' ];
	$pre = [ "o.{$o[2]} IN ('" . implode( "','", array_map( 'esc_sql', $n['statuses'] ) ) . "')" ];
	// EXACT day bounds (UTC site) — a count must not be a superset. WooCommerce's own tz handling is NOT replicated.
	if ( ! empty( $request['after'] ) ) { $pre[] = "o.{$o[3]} >= '{$request['after']} 00:00:00'"; }
	if ( ! empty( $request['before'] ) ) { $pre[] = "o.{$o[3]} <= '{$request['before']} 23:59:59'"; }
	$sql = "SELECT mk.meta_key, COUNT(DISTINCT mk.{$idc}) FROM {$table} AS mk JOIN {$o[0]} AS o ON o.{$o[1]} = mk.{$idc} WHERE (" . implode( ' OR ', $ors ) . ') AND ' . implode( ' AND ', $pre ) . ' GROUP BY mk.meta_key WITH ROLLUP';
	if ( ! $join ) {
		$sql = "SELECT mk.meta_key, COUNT(DISTINCT mk.{$idc}) FROM {$table} AS mk WHERE (" . implode( ' OR ', $ors ) . ") AND EXISTS (SELECT 1 FROM {$o[0]} AS o WHERE o.{$o[1]} = mk.{$idc} AND " . implode( ' AND ', $pre ) . ') GROUP BY mk.meta_key WITH ROLLUP';
	}
	$t = timed( $m, $sql, $runs );
	if ( $t['error'] ) {
		return [ 'error' => $t['error'], 'sql' => substr( $sql, 0, 300 ) ];
	}
	$counts = [];
	$r      = $m->query( $sql );
	while ( $row = $r->fetch_row() ) {
		$counts[ null === $row[0] ? 'all' : $row[0] ] = (int) $row[1];
	}
	return [ 'ms' => round( $t['ms'], 1 ), 'counts' => $counts, 'sql_len' => strlen( $sql ), 'explain' => explain( $m, $sql ) ];
};

foreach ( $stores as $store ) {
	$hpos = 'hpos' === $store;
	foreach ( scenarios( $store ) as $name => $request ) {
		if ( $only && ! in_array( $name, $only, true ) ) { continue; }
		$ref_page = null;
		$ref_seq  = [];
		foreach ( $modes as $mode ) {
			$q       = new Probe_Query( $registry, $hpos );
			$q->mode = $mode;
			$base    = [ 'store' => $store, 'scenario' => $name, 'mode' => $mode, 'size' => $size ];

			// (1) the single page request.
			$res = $run_request( $q, $store, $request );
			if ( null === $ref_page ) { $ref_page = $res; }
			$emit( $base + [ 'kind' => 'request' ] + $res + [ 'agree' => ( $res['found'] ?? null ) === ( $ref_page['found'] ?? null ) && ( $res['page_ids'] ?? null ) === ( $ref_page['page_ids'] ?? null ) ] );

			if ( '0' === getenv( 'PAGELOAD' ) ) { continue; }

			// (2) one whole page load: rows + N carrier counts + scope count (N + 2 resolver calls).
			$tot = [ 'idq_ms' => 0.0, 'main_ms' => 0.0, 'count_ms' => 0.0, 'ids_n' => 0, 'ids_bytes' => 0, 'calls' => 0 ];
			$founds = [];
			foreach ( $sequence_page( $request, $providers ) as $label => $req ) {
				$r = $run_request( $q, $store, $req );
				foreach ( [ 'idq_ms', 'main_ms', 'count_ms', 'ids_n', 'ids_bytes' ] as $k ) { $tot[ $k ] += $r[ $k ] ?? 0; }
				$tot['calls']++;
				$founds[ $label ] = $r['found'] ?? null;
			}
			$ref_seq = $ref_seq ?: $founds;
			$tot['total_ms'] = round( $tot['idq_ms'] + $tot['main_ms'] + $tot['count_ms'], 1 );
			foreach ( [ 'idq_ms', 'main_ms', 'count_ms' ] as $k ) { $tot[ $k ] = round( $tot[ $k ], 1 ); }
			$emit( $base + [ 'kind' => 'page_load', 'founds' => $founds, 'agree' => $founds === $ref_seq ] + $tot );
		}

		// (3) the badge's 1 + N (today's form only; is_exported=false is a scope the narrowing does not touch).
		if ( [] === $request && '0' !== getenv( 'PAGELOAD' ) ) {
			foreach ( $modes as $mode ) {
				$q = new Probe_Query( $registry, $hpos );
				$q->mode = $mode;
				$tot = [ 'idq_ms' => 0.0, 'main_ms' => 0.0, 'count_ms' => 0.0, 'ids_n' => 0, 'calls' => 0 ];
				foreach ( $sequence_badge( $providers ) as $req ) {
					$r = $run_request( $q, $store, $req );
					foreach ( [ 'idq_ms', 'main_ms', 'count_ms', 'ids_n' ] as $k ) { $tot[ $k ] += $r[ $k ] ?? 0; }
					$tot['calls']++;
				}
				$tot['total_ms'] = round( $tot['idq_ms'] + $tot['main_ms'] + $tot['count_ms'], 1 );
				$emit( [ 'store' => $store, 'scenario' => $name, 'mode' => $mode, 'size' => $size, 'kind' => 'badge' ] + $tot );
			}
		}

		// (4) candidate B — carrier counts by ONE GROUP BY (not for search: WooCommerce's search stays in the main query).
		if ( '0' !== getenv( 'PAGELOAD' ) && ! isset( $request['search'] ) && in_array( 'B', explode( ',', getenv( 'CANDIDATES' ) ?: 'A,B' ), true ) ) {
			$q = new Probe_Query( $registry, $hpos );
			foreach ( [ 'b_exists' => false, 'b_join' => true ] as $bmode => $join ) {
				$b = $carrier_counts_b( $q, $store, $request, $providers, $join );
				// reference: the N per-carrier `found` from today's sequence.
				$exp = [];
				foreach ( $providers as $p ) { $exp[ "_{$p}_marker" ] = $ref_seq[ "carrier:$p" ] ?? null; }
				$ok = isset( $b['counts'] ) ? array_map( static fn( $k ) => $b['counts'][ $k ] ?? 0, array_combine( array_keys( $exp ), array_keys( $exp ) ) ) == $exp : null;
				$emit( [ 'store' => $store, 'scenario' => $name, 'mode' => $bmode, 'size' => $size, 'kind' => 'carrier_counts_b', 'total_ms' => $b['ms'] ?? null, 'agree' => $ok ] + $b );
			}
		}
	}
}
fwrite( STDERR, "== done\n" );
