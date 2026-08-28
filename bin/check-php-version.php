<?php
/**
 * Prints the PHP-version divergence notice (#609), then exits 0 either way.
 *
 * Wired into `composer check` and `composer test:unit` so the divergence is
 * announced where a human is already looking, without writing anything into the
 * PHPUnit process itself — see `bin/php-version-matrix.php` for why that matters.
 *
 * Never fails the build: running a version outside the matrix is the
 * maintainer's deliberate choice, not an error. It just must not be silent.
 *
 * @package Woodev\Tools
 */

require_once __DIR__ . '/php-version-matrix.php';

$root = dirname( __DIR__ );

$notice = woodev_php_version_notice(
	PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
	woodev_ci_php_matrix( $root . '/.github/workflows/ci.yml' ),
	woodev_composer_platform_php( $root . '/composer.json' )
);

if ( '' !== $notice ) {
	fwrite( STDOUT, PHP_EOL . $notice . PHP_EOL );
}

exit( 0 );
