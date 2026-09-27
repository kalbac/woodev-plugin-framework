<?php
/**
 * Turns a run.php JSONL into the markdown tables of the README.
 *   php summarize.php results/r1-10k.jsonl [results/r2-100k.jsonl …]
 */

$rows = [];
foreach ( array_slice( $argv, 1 ) as $file ) {
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$rows[] = json_decode( $line, true );
	}
}

$sizes = array_values( array_unique( array_column( $rows, 'size' ) ) );
sort( $sizes );

$fmt = static fn( $v ) => null === $v ? '—' : ( is_float( $v ) || is_int( $v ) ? ( $v >= 1000 ? round( $v / 1000, 2 ) . ' s' : $v . ' ms' ) : (string) $v );

foreach ( $sizes as $size ) {
	foreach ( [ 'hpos', 'cpt' ] as $store ) {
		foreach ( [ 'request' => 'one page request (id query + main + count)', 'page_load' => 'one page load (rows + N carrier counts + scope count = N+2 resolver calls)', 'badge' => 'the menu badge (1 + N)' ] as $kind => $title ) {
			$sel = array_filter( $rows, static fn( $r ) => $r['size'] === $size && $r['store'] === $store && $r['kind'] === $kind );
			if ( ! $sel ) {
				continue;
			}
			echo "\n#### " . strtoupper( $store ) . " · {$size} orders · {$title}\n\n";
			echo "| scenario | mode | id query | ids | main | count | **total** | ok |\n|---|---|---|---|---|---|---|---|\n";
			foreach ( $sel as $r ) {
				printf( "| %s | %s | %s | %s | %s | %s | **%s** | %s |\n", $r['scenario'], $r['mode'], $fmt( $r['idq_ms'] ?? null ), $r['ids_n'] ?? '—', $fmt( $r['main_ms'] ?? null ), $fmt( $r['count_ms'] ?? null ), $fmt( $r['total_ms'] ?? null ), ( $r['agree'] ?? true ) ? '=' : 'DIFF' );
			}
		}
		$sel = array_filter( $rows, static fn( $r ) => $r['size'] === $size && $r['store'] === $store && 'carrier_counts_b' === $r['kind'] );
		if ( $sel ) {
			echo "\n#### " . strtoupper( $store ) . " · {$size} orders · candidate B (all carrier counts, ONE GROUP BY)\n\n| scenario | form | time | counts equal N calls |\n|---|---|---|---|\n";
			foreach ( $sel as $r ) {
				printf( "| %s | %s | %s | %s |\n", $r['scenario'], $r['mode'], $fmt( $r['total_ms'] ?? null ), null === ( $r['agree'] ?? null ) ? 'ERR ' . ( $r['error'] ?? '' ) : ( $r['agree'] ? 'yes' : 'NO' ) );
			}
		}
	}
}
