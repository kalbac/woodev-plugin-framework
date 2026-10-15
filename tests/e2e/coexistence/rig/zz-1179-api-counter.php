<?php
/**
 * Plugin Name: #1179 outbound HTTP counter
 * Description: Rig-only. Appends one line per outbound WordPress HTTP request to wp-content/uploads/1179-api-calls.log, so the
 *              two-carrier matrix specs can COUNT the carrier API calls a run really made. Installed and removed by the specs.
 *
 * A line is `<unix time> <METHOD> <host><path>` — the query string is dropped, so no token or address ever lands in the file.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) {
		$parts = wp_parse_url( (string) $url );
		$line  = sprintf( "%d %s %s%s\n", time(), strtoupper( (string) ( $args['method'] ?? 'GET' ) ), $parts['host'] ?? '?', $parts['path'] ?? '' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( WP_CONTENT_DIR . '/uploads/1179-api-calls.log', $line, FILE_APPEND | LOCK_EX );
	},
	10,
	5
);
