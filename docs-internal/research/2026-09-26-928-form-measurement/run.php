<?php
/**
 * #928 wave 2 — the measurement driver. Replayable; parameterised by environment variables.
 *
 *   docker cp docs-internal/research/2026-09-26-928-form-measurement wp-env-woodev-plugin-framework-5fd870b7-cli-1:/tmp/f928
 *   docker exec -e SIZES=1000,10000 -e CONFIGS=2:0,4:2 wp-env-woodev-plugin-framework-5fd870b7-cli-1 \
 *       wp eval-file /tmp/f928/run.php
 *
 *   SIZES    orders per datastore                     default 1000
 *   CONFIGS  M:B pairs (M mapped carriers, B bare)    default 2:0,4:0,6:0,2:2,4:2,6:2
 *   FILTERS  unknown,not_in_transit,not_delivered     default unknown,not_in_transit
 *   STORES   hpos,cpt                                 default hpos,cpt
 *   FORMS    current,h_notin,h_notexists,h_full,x_excl,x_incl,scope_only  default: all but scope_only
 *   RUNS     timed runs after one warm-up             default 5
 *   OUT      JSONL path                               default /tmp/f928/results.jsonl
 *   UNRELATED  unrelated meta rows per order, 1..20   default 20 (density sensitivity: 5, 10)
 *   SEED     0 => reuse already-seeded probe928 (base + carriers must match SIZES/CONFIGS)
 *
 * Writes ONLY to the throwaway database probe928. Drop it afterwards: `DROP DATABASE probe928`.
 */

require_once __DIR__ . '/lib.php';

use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use function Woodev\Research928\{ connect, create_schema, seed_base, seed_carriers, registry_of, page_args, filter_spec, capture, h_fragment, h_full_fragment, x_excl_sql, x_incl_sql, timed, explain, join_counts, q };

$sizes   = array_map( 'intval', explode( ',', getenv( 'SIZES' ) ?: '1000' ) );
$configs = array_map( static fn( $c ) => array_map( 'intval', explode( ':', $c ) ), explode( ',', getenv( 'CONFIGS' ) ?: '2:0,4:0,6:0,2:2,4:2,6:2' ) );
$filters = explode( ',', getenv( 'FILTERS' ) ?: 'unknown,not_in_transit' );
$stores  = explode( ',', getenv( 'STORES' ) ?: 'hpos,cpt' );
$forms   = explode( ',', getenv( 'FORMS' ) ?: 'current,h_notin,h_notexists,h_full,x_excl,x_incl' );
$runs    = (int) ( getenv( 'RUNS' ) ?: 5 );
$out     = getenv( 'OUT' ) ?: __DIR__ . '/results.jsonl';
$reseed  = '0' !== getenv( 'SEED' );

$root = connect( null );
if ( $reseed ) {
	create_schema( $root, __DIR__ . '/ddl.sql' );
}
$m = connect();

$emit = static function ( array $row ) use ( $out ) {
	file_put_contents( $out, json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
	fwrite( STDERR, sprintf( "%-6s %-4s %-16s %-11s n=%-6d M%dB%d  main=%s count=%s idq=%s ids=%s found=%s%s\n",
		'', $row['store'], $row['filter'], $row['form'], $row['size'], $row['M'], $row['B'],
		$row['ms_main'] ?? '-', $row['ms_count'] ?? '-', $row['ms_idq'] ?? '-', $row['ids_n'] ?? '-', $row['found'] ?? '-',
		! empty( $row['timeout'] ) ? '  TIMEOUT' : ( ! empty( $row['error'] ) ? '  ERR ' . $row['error'] : '' ) ) );
};

/** Runs a captured query pair; returns the measurement fragment. */
$run = static function ( string $store, array $cap ) use ( $m, $runs ): array {
	$main = timed( $m, $cap['main'], $runs );
	$res  = [
		'ms_main'  => $main['ms'] ? round( $main['ms'], 1 ) : null,
		'runs_main' => $main['runs'],
		'timeout'  => $main['timeout'],
		'error'    => $main['error'],
		'joins'    => join_counts( $cap['main'] ),
		'explain'  => explain( $m, $cap['main'] ),
		'sql_len'  => strlen( $cap['main'] ),
		'build_ms' => round( $cap['build_ms'], 1 ),
	];
	if ( $main['error'] ) {
		return $res;
	}
	if ( 'hpos' === $store ) {
		$c              = timed( $m, $cap['count'], $runs, true );
		$res['ms_count'] = $c['ms'] ? round( $c['ms'], 1 ) : null;
		$res['found']    = $c['scalar'];
		$res['explain_count'] = explain( $m, $cap['count'] );
		$res['timeout'] = $res['timeout'] || $c['timeout'];
		$res['error']   = $c['error'];
		$res['ms_total'] = ( $res['ms_main'] ?? 0 ) + ( $res['ms_count'] ?? 0 );
	} else {
		// SQL_CALC_FOUND_ROWS: the main statement already paid for the count; FOUND_ROWS() is free.
		$m->query( no_cache_free( $cap['main'] ) );
		$res['found']    = $m->query( 'SELECT FOUND_ROWS()' )->fetch_row()[0];
		$res['ms_total'] = $res['ms_main'];
	}
	return $res;
};
function no_cache_free( string $sql ): string { return \Woodev\Research928\no_cache( $sql ); }

/** First page ids of a captured main query (for the cross-form agreement check). */
$page_ids = static function ( array $cap ) use ( $m ): ?array {
	$r = $m->query( $cap['main'] );
	if ( false === $r ) {
		return null;
	}
	$ids = [];
	while ( $row = $r->fetch_row() ) {
		$ids[] = (int) $row[0];
	}
	return $ids;
};

$timed_out = []; $scope_to = [];
foreach ( $sizes as $size ) {
	if ( $reseed ) {
		$t0 = microtime( true );
		seed_base( $m, $size );
		fwrite( STDERR, sprintf( "== seeded base %d orders x2 datastores in %.1fs\n", $size, microtime( true ) - $t0 ) );
	}
	foreach ( $configs as [ $M, $B ] ) {
		$stat = [];
		if ( $reseed ) {
			$stat = seed_carriers( $m, $size, $M, $B );
			fwrite( STDERR, '== carriers ' . json_encode( $stat ) . "\n" );
		}
		$registry = registry_of( $M, $B );
		$marker_keys = [];
		for ( $i = 1; $i <= $M; $i++ ) { $marker_keys[] = "_m{$i}_marker"; }
		for ( $i = 1; $i <= $B; $i++ ) { $marker_keys[] = "_b{$i}_marker"; }

		foreach ( $stores as $store ) {
			if ( in_array( 'scope_only', $forms, true ) ) {
				$cap = capture( $store, page_args( $store, $registry, [] ) );
				$res = $run( $store, $cap );
				$emit( [ 'size' => $size, 'M' => $M, 'B' => $B, 'store' => $store, 'filter' => 'none', 'form' => 'scope_only' ] + $res );
				if ( ! empty( $res['timeout'] ) ) {
					$scope_to[ $store ][] = [ $size, $M + $B ];
				}
			}
			// Every form that keeps the marker-scope meta_query pays AT LEAST what scope_only pays, so once that
			// alone blows the statement limit at (size, N), it does at any larger size / carrier count too.
			$scope_dead = false;
			foreach ( $scope_to[ $store ] ?? [] as [ $ds, $dn ] ) {
				$scope_dead = $scope_dead || ( $size >= $ds && $M + $B >= $dn );
			}
			$inferred = static fn( array $base, string $form ) => $base + [ 'form' => $form, 'timeout' => true, 'inferred' => true, 'note' => 'carries the marker-scope joins; scope_only alone timed out at this or a smaller (orders, carriers) point' ];
			foreach ( $filters as $filter ) {
				[ $request, $raw ] = filter_spec( $filter, $M );
				$base = [ 'size' => $size, 'M' => $M, 'B' => $B, 'store' => $store, 'filter' => $filter ];
				$ids_ref = null; $found_ref = null; $ref_kind = 'current';

				$tkey = "$M:$B:$store:$filter";
				if ( in_array( 'current', $forms, true ) && $scope_dead ) {
					$emit( $inferred( $base, 'current' ) );
				} elseif ( in_array( 'current', $forms, true ) && ! empty( $timed_out[ $tkey ] ) ) {
					// A smaller table already blew the statement limit; the cost only grows with rows.
					$emit( $base + [ 'form' => 'current', 'timeout' => true, 'inferred' => true, 'note' => "timed out at {$timed_out[$tkey]} orders; not re-run" ] );
				} elseif ( in_array( 'current', $forms, true ) ) {
					$cap = capture( $store, page_args( $store, $registry, $request ) );
					$res = $run( $store, $cap );
					$ids_ref = ! empty( $res['timeout'] ) ? null : $page_ids( $cap );
					$found_ref = $res['found'] ?? null;
					$emit( $base + [ 'form' => 'current' ] + $res );
					if ( ! empty( $res['timeout'] ) ) { $timed_out[ $tkey ] = $size; }
					if ( 'not_delivered' !== $filter && 2 === $M && 0 === $B && 1000 === $size ) {
						@mkdir( __DIR__ . '/sql', 0777, true );
						file_put_contents( __DIR__ . "/sql/{$store}-{$filter}-current-M{$M}B{$B}.sql", $cap['main'] . "\n\n" . ( $cap['count'] ?? '' ) . "\n" );
					}
				}

				foreach ( [ 'h_notin' => 'notin', 'h_notexists' => 'notexists' ] as $form => $variant ) {
					if ( ! in_array( $form, $forms, true ) ) { continue; }
					if ( $scope_dead ) { $emit( $inferred( $base, $form ) ); continue; }
					$frag = h_fragment( $store, $variant, $raw );
					$cap  = capture( $store, page_args( $store, $registry, [] ), [ 'where_hpos' => $frag, 'where_cpt' => $frag ] );
					$res  = $run( $store, $cap );
					if ( null === $found_ref && isset( $res['found'] ) ) { $found_ref = $res['found']; $ref_kind = 'h_notin'; }
					$res['agree'] = ( null !== $found_ref && ( $res['found'] ?? null ) == $found_ref ) ? $ref_kind : ( null === $found_ref ? null : 'DIFF' );
					$emit( $base + [ 'form' => $form ] + $res );
					if ( 2 === $M && 0 === $B && 1000 === $size ) {
						file_put_contents( __DIR__ . "/sql/{$store}-{$filter}-{$form}-M{$M}B{$B}.sql", $cap['main'] . "\n\n" . ( $cap['count'] ?? '' ) . "\n" );
					}
				}

				if ( in_array( 'h_full', $forms, true ) ) {
					$frag = h_full_fragment( $store, $marker_keys, $raw );
					$args = page_args( $store, $registry, [] );
					unset( $args['meta_query'], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
					$cap  = capture( $store, $args, [ 'where_hpos' => $frag, 'where_cpt' => $frag ] );
					$res  = $run( $store, $cap );
					if ( null === $found_ref && isset( $res['found'] ) ) { $found_ref = $res['found']; $ref_kind = 'h_full'; }
					$res['agree'] = ( null !== $found_ref && ( $res['found'] ?? null ) == $found_ref ) ? $ref_kind : ( null === $found_ref ? null : 'DIFF' );
					$emit( $base + [ 'form' => 'h_full' ] + $res );
					if ( 2 === $M && 0 === $B && 1000 === $size ) {
						file_put_contents( __DIR__ . "/sql/{$store}-{$filter}-h_full-M{$M}B{$B}.sql", $cap['main'] . "\n\n" . ( $cap['count'] ?? '' ) . "\n" );
					}
				}

				foreach ( [ 'x_excl', 'x_incl' ] as $form ) {
					if ( ! in_array( $form, $forms, true ) ) { continue; }
					if ( 'x_excl' === $form && $scope_dead ) { $emit( $inferred( $base, $form ) ); continue; }
					$idsql = 'x_excl' === $form ? x_excl_sql( $store, $raw ) : x_incl_sql( $store, $marker_keys, $raw );
					$idq   = timed( $m, $idsql, $runs );
					$ids   = [];
					if ( ! $idq['error'] ) {
						$r = $m->query( $idsql );
						while ( $row = $r->fetch_row() ) { $ids[] = (int) $row[0]; }
					}
					$row = $base + [ 'form' => $form, 'ms_idq' => $idq['ms'] ? round( $idq['ms'], 1 ) : null, 'ids_n' => count( $ids ), 'explain_idq' => explain( $m, $idsql ) ];
					if ( $idq['error'] ) {
						$emit( $row + [ 'error' => $idq['error'], 'timeout' => $idq['timeout'] ] );
						continue;
					}
					$args = page_args( $store, $registry, [] );
					if ( 'x_excl' === $form ) {
						$args['exclude'] = $ids;
					} else {
						unset( $args['meta_query'], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
						if ( [] === $ids ) {
							$emit( $row + [ 'note' => 'empty include set — post__in=[] would fail OPEN, needs NO_MATCH handling' ] );
							continue;
						}
						$args['post__in'] = $ids;
					}
					$cap = capture( $store, $args );
					if ( 2 === $M && 0 === $B && 1000 === $size ) {
						// keep the sample readable: show only the first five ids of the literal list.
						$short = preg_replace_callback( '/\((\d+(?:,\d+){6,})\)/', static fn( $mm ) => '(' . implode( ',', array_slice( explode( ',', $mm[1] ), 0, 5 ) ) . ',… ' . count( explode( ',', $mm[1] ) ) . ' ids)', $cap['main'] . "\n\n" . ( $cap['count'] ?? '' ) );
						file_put_contents( __DIR__ . "/sql/{$store}-{$filter}-{$form}-M{$M}B{$B}.sql", $short . "\n" );
					}
					$res = $run( $store, $cap );
					$res['ms_total'] = round( ( $res['ms_total'] ?? 0 ) + ( $row['ms_idq'] ?? 0 ), 1 );
					$res['agree']    = ( null !== $found_ref && ( $res['found'] ?? null ) == $found_ref ) ? $ref_kind : ( null === $found_ref ? null : 'DIFF' );
					unset( $res['explain'] ); // the main query is a trivial NOT IN / IN list; keep the id-query plan instead.
					$res['explain_main'] = explain( $m, $cap['main'] );
					$emit( $row + $res );
				}
			}
		}
	}
}
fwrite( STDERR, "== done\n" );
