<?php
/**
 * Engine probe for ErrorReporterProcessTest (#1399): does THIS php binary still run shutdown
 * functions after an «Allowed memory size» fatal? Prints SHUTDOWN-RAN from one if it does.
 * Touches nothing of ours, so its answer is a property of the PHP build alone.
 */

register_shutdown_function(
	static function () {
		echo "SHUTDOWN-RAN\n";
	}
);

ini_set( 'memory_limit', '8M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted -- fixture.

echo strlen( str_repeat( 'x', 64 * 1024 * 1024 ) );
