<?php
/**
 * Plugin Name: Bulk run measurement (#1795 scale test)
 * Description: Appends one JSON line per bulk run tick: wall time, peak memory, query count, the run's counters before and after.
 *
 * Copy to wp-content/mu-plugins/ for the rig phase and remove afterwards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks are registered by the plugin on `plugins_loaded`/`init`; attach on `init` late, to every bulk_run hook that exists then.
 */
add_action(
	'init',
	static function (): void {
		global $wp_filter;

		$file = WP_CONTENT_DIR . '/bulk-run-ticks.jsonl';

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 1 !== preg_match( '/^woodev_[a-z0-9_]+_bulk_run$/', (string) $hook ) ) {
				continue;
			}

			$state = [];

			add_action(
				$hook,
				static function ( $account_id = 0, $run_id = '' ) use ( &$state ): void {
					global $wpdb;

					if ( 0 === (int) $account_id ) {
						return; // The recovery sweep.
					}

					$state = [
						'time'    => microtime( true ),
						'queries' => (int) $wpdb->num_queries,
						'before'  => bulk_run_measure_counts( (int) $account_id ),
					];
				},
				1,
				2
			);

			add_action(
				$hook,
				static function ( $account_id = 0, $run_id = '' ) use ( &$state, $file ): void {
					global $wpdb;

					if ( [] === $state ) {
						return;
					}

					$after = bulk_run_measure_counts( (int) $account_id );
					$line  = [
						'run_id'        => (string) $run_id,
						'wall_seconds'  => round( microtime( true ) - $state['time'], 3 ),
						'peak_memory_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
						'queries'       => (int) $wpdb->num_queries - $state['queries'],
						'state'         => $after['state'],
						'offers'        => $after['offers'] - $state['before']['offers'],
						'products'      => $after['products'] - $state['before']['products'],
					];

					file_put_contents( $file, wp_json_encode( $line ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$state = [];
				},
				PHP_INT_MAX,
				2
			);
		}
	},
	PHP_INT_MAX
);

/**
 * Reads the counters of the account's run, straight from the table (the first `…_bulk_runs` table that holds the account).
 *
 * @param int $account_id Account.
 * @return array{state:string,offers:int,products:int}
 */
function bulk_run_measure_counts( int $account_id ): array {
	global $wpdb;

	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woodev_' ) . '%\_bulk\_runs' ) );

	foreach ( $tables as $table ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT state, counts FROM %i WHERE account_id = %d', $table, $account_id ), ARRAY_A ); // phpcs:ignore

		if ( is_array( $row ) ) {
			$counts = (array) json_decode( (string) $row['counts'], true );

			return [
				'state'    => (string) $row['state'],
				'offers'   => (int) ( $counts['offers_accepted'] ?? 0 ) + (int) ( $counts['offers_skipped'] ?? 0 ) + (int) ( $counts['offers_failed'] ?? 0 ),
				'products' => (int) ( $counts['products_done'] ?? 0 ),
			];
		}
	}

	return [ 'state' => '', 'offers' => 0, 'products' => 0 ];
}
