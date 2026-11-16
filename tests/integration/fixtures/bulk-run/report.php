<?php
/**
 * Prints the ticks of the last run and its final counters (#1795, E2 scale test).
 *
 * Usage: `wp eval-file report.php <plugin id> <account id>`
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$plugin_id = isset( $args[0] ) ? (string) $args[0] : '';
$account   = isset( $args[1] ) ? (int) $args[1] : 0;

if ( '' === $plugin_id || $account <= 0 ) {
	WP_CLI::error( 'Usage: wp eval-file report.php <plugin id> <account id>' );
}

$file = WP_CONTENT_DIR . '/bulk-run-ticks.jsonl';
$rows = [];

foreach ( is_readable( $file ) ? (array) file( $file, FILE_IGNORE_NEW_LINES ) : [] as $line ) {
	$rows[] = (array) json_decode( (string) $line, true );
}

if ( [] === $rows ) {
	WP_CLI::warning( 'No ticks recorded: is the mu-plugin installed?' );
} else {
	WP_CLI\Utils\format_items( 'table', $rows, [ 'run_id', 'state', 'wall_seconds', 'peak_memory_mb', 'queries', 'products', 'offers' ] );

	$over = array_filter(
		$rows,
		static function ( array $row ): bool {
			return (float) $row['wall_seconds'] >= 20.0;
		}
	);
	$mem  = array_column( $rows, 'peak_memory_mb' );
	WP_CLI::log( sprintf( 'Ticks: %d; over the 20 s budget: %d; peak memory %s…%s MB (flat when the two are close).', count( $rows ), count( $over ), min( $mem ), max( $mem ) ) );
}

$table        = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woodev_' ) . '%\_bulk\_runs' ) );

foreach ( $table as $name ) {
	$run = $wpdb->get_row( $wpdb->prepare( 'SELECT action, state, counts, report FROM %i WHERE account_id = %d', $name, $account ), ARRAY_A ); // phpcs:ignore

	if ( is_array( $run ) ) {
		WP_CLI::log( $name . ': ' . $run['action'] . ' → ' . $run['state'] . "\n" . $run['counts'] . "\n" . $run['report'] );
	}
}

