<?php
/**
 * Renders results/*.jsonl as the README's markdown tables.  php summarize.php results/*.jsonl
 * Cell = total page cost in ms (HPOS: main + count queries; CPT: the one SQL_CALC_FOUND_ROWS statement;
 * forms X: + the id query). `>30s` = hit the 30 s statement limit (`*` = inferred from a smaller point).
 */
$rows = [];
foreach ( array_slice( $argv, 1 ) as $f ) {
	foreach ( file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $l ) {
		$d = json_decode( $l, true );
		$rows[ implode( '|', [ $d['size'], $d['store'], $d['filter'], $d['M'], $d['B'], $d['form'] ] ) ] = $d;
	}
}
$cell = static function ( ?array $d, bool $ids = false ): string {
	if ( ! $d ) { return '–'; }
	if ( ! empty( $d['timeout'] ) ) { return '>30s' . ( ! empty( $d['inferred'] ) ? '*' : '' ); }
	if ( isset( $d['note'] ) && ! isset( $d['ms_total'] ) ) { return 'n/a'; }
	$v = $d['ms_total'] ?? $d['ms_main'] ?? null;
	if ( null === $v ) { return '?'; }
	$s = $v >= 1000 ? sprintf( '%.1fs', $v / 1000 ) : rtrim( rtrim( sprintf( '%.1f', $v ), '0' ), '.' ) . ' ms';
	return $s . ( $ids && isset( $d['ids_n'] ) ? " (n=" . $d['ids_n'] . ')' : '' ) . ( ( $d['agree'] ?? '' ) === 'DIFF' ? ' ⚠DIFF' : '' );
};
$sizes = $stores = $filters = $cfgs = [];
foreach ( $rows as $d ) {
	$sizes[ $d['size'] ] = 1; $stores[ $d['store'] ] = 1; if ( 'none' !== $d['filter'] ) { $filters[ $d['filter'] ] = 1; }
	$cfgs[ $d['M'] . ':' . $d['B'] ] = [ $d['M'], $d['B'] ];
}
ksort( $sizes );
uasort( $cfgs, static fn( $a, $b ) => [ $a[1], $a[0] ] <=> [ $b[1], $b[0] ] );
foreach ( array_keys( $stores ) as $store ) {
	foreach ( array_keys( $filters ) as $filter ) {
		foreach ( array_keys( $sizes ) as $size ) {
			echo "\n#### {$store} · `{$filter}` · {$size} orders\n\n| M:B | scope only (no filter) | current | H `NOT IN` + scope joins | H full (no joins) | X excl. list | X incl. list |\n|---|---|---|---|---|---|---|\n";
			foreach ( $cfgs as $k => [ $M, $B ] ) {
				$g = static fn( $form, $flt ) => $rows[ "$size|$store|$flt|$M|$B|$form" ] ?? null;
				printf( "| %d:%d | %s | %s | %s | %s | %s | %s |\n", $M, $B,
					$cell( $g( 'scope_only', 'none' ) ), $cell( $g( 'current', $filter ) ), $cell( $g( 'h_notin', $filter ) ),
					$cell( $g( 'h_full', $filter ) ), $cell( $g( 'x_excl', $filter ), true ), $cell( $g( 'x_incl', $filter ), true ) );
			}
		}
	}
}
