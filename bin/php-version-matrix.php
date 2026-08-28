<?php
/**
 * The PHP-version divergence check (#609) — pure functions, no side effects on
 * include, so both the CLI reporter next to it and the unit tests can use them.
 *
 * Three different PHP versions are in play in this repository and none of them
 * used to be announced anywhere: the local interpreter (8.5 on the maintainer's
 * machine), the CI matrix (7.4-8.3) and `composer.json`'s `config.platform.php`
 * pin (8.1, which is what dependencies are RESOLVED for). A locally green
 * `composer check` is therefore evidence about the local version only.
 *
 * That is not hypothetical. On PHP 8.5 `new ReflectionProperty( $object, $prop )`
 * throws for a private property declared only on a PARENT class, so a test
 * written exactly the way every other test here is written failed locally for a
 * reason that does not exist anywhere in CI. The reverse direction — locally
 * green, red in CI — is the same gap and costs more.
 *
 * Deliberately NOT emitted from `tests/bootstrap.php`: PHPUnit runs some tests in
 * separate processes and parses the child's output as the result, so anything
 * written there turns unrelated tests into errors. Measured — 36 of them.
 *
 * @package Woodev\Tools
 */

if ( ! function_exists( 'woodev_php_version_notice' ) ) {

	/**
	 * Builds the notice shown when the running PHP is not one CI will ever run.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $running  The running `MAJOR.MINOR`, e.g. `'8.5'`.
	 * @param string[] $matrix   The versions CI runs, as `MAJOR.MINOR` strings.
	 * @param string   $platform The `config.platform.php` pin, or `''` when unset.
	 *
	 * @return string The notice, or `''` when there is nothing to say.
	 */
	function woodev_php_version_notice( string $running, array $matrix, string $platform = '' ): string {

		if ( [] === $matrix || in_array( $running, $matrix, true ) ) {
			return '';
		}

		$notice = sprintf(
			"  PHP %s is NOT in the CI matrix (%s).\n"
			. "  A green run here is evidence about %s only — trust CI, not this machine.\n",
			$running,
			implode( ', ', $matrix ),
			$running
		);

		if ( '' !== $platform && $platform !== $running ) {
			$notice .= sprintf(
				"  composer.json pins config.platform.php to %s, so dependencies were resolved\n"
				. "  for %s and are running on %s.\n",
				$platform,
				$platform,
				$running
			);
		}

		return $notice . "  Issue #609.\n";
	}
}

if ( ! function_exists( 'woodev_ci_php_matrix' ) ) {

	/**
	 * Reads the PHP versions CI actually runs from the workflow itself, so this
	 * check can never drift from the matrix it is checking against.
	 *
	 * The UNION of every `php:` matrix in the file, not the first one: `ci.yml`
	 * carries two that differ, and the first in reading order is `PHP Compat`,
	 * whose list omits 8.1 — the very version `composer.json` pins. Reading only
	 * the first reported a matrix CI does not have.
	 *
	 * @since 2.0.2
	 *
	 * @param string $workflow Path to `ci.yml`.
	 *
	 * @return string[] The matrix versions, sorted, or `[]` when unreadable.
	 */
	function woodev_ci_php_matrix( string $workflow ): array {

		if ( ! is_readable( $workflow ) ) {
			return [];
		}

		if ( ! preg_match_all( '/^\s*php:\s*\[([^\]]+)\]/m', (string) file_get_contents( $workflow ), $matches ) ) {
			return [];
		}

		preg_match_all( '/(\d+\.\d+)/', implode( ',', $matches[1] ), $versions );

		$matrix = array_values( array_unique( $versions[1] ) );

		usort( $matrix, 'version_compare' );

		return $matrix;
	}
}

if ( ! function_exists( 'woodev_composer_platform_php' ) ) {

	/**
	 * Reads `config.platform.php` out of `composer.json`.
	 *
	 * @since 2.0.2
	 *
	 * @param string $composer Path to `composer.json`.
	 *
	 * @return string The pinned version, or `''` when absent or unreadable.
	 */
	function woodev_composer_platform_php( string $composer ): string {

		if ( ! is_readable( $composer ) ) {
			return '';
		}

		$config = json_decode( (string) file_get_contents( $composer ), true );

		return is_array( $config ) ? (string) ( $config['config']['platform']['php'] ?? '' ) : '';
	}
}
