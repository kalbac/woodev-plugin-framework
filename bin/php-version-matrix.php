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
	 * `$unparsed` is what keeps this honest. The matrix is read by regex, so a
	 * `php:` key in a shape it does not understand means the list may be
	 * INCOMPLETE — and an incomplete list can only ever produce a FALSE "not in
	 * the matrix", never a false "you are fine": an unread matrix can only add
	 * versions. So the two cases are treated differently on purpose:
	 *
	 * - running version IS in what was read → silent, and correct regardless of
	 *   how much went unread;
	 * - running version is NOT in what was read, and something went unread → say
	 *   so, and do NOT assert it is outside CI;
	 * - nothing was read at all but `php:` keys exist → that is the loudest case,
	 *   because it means this check has quietly stopped working.
	 *
	 * @since 2.0.2
	 * @since 2.0.2 Added `$unparsed`; the notice no longer asserts a verdict it
	 *              cannot support.
	 *
	 * @param string   $running  The running `MAJOR.MINOR`, e.g. `'8.5'`.
	 * @param string[] $matrix   The versions CI runs, as `MAJOR.MINOR` strings.
	 * @param string   $platform The `config.platform.php` pin, or `''` when unset.
	 * @param int      $unparsed How many `php:` keys could not be read at all.
	 *
	 * @return string The notice, or `''` when there is nothing to say.
	 */
	function woodev_php_version_notice( string $running, array $matrix, string $platform = '', int $unparsed = 0 ): string {

		if ( [] !== $matrix && in_array( $running, $matrix, true ) ) {
			return '';
		}

		if ( [] === $matrix ) {
			if ( $unparsed < 1 ) {
				return '';
			}

			return sprintf(
				"  CI matrix UNREADABLE: %d `php:` key(s) in ci.yml are in a shape this check\n"
				. "  cannot parse, and no version list was recovered. The PHP-version gate is\n"
				. "  not protecting you right now — read the workflow yourself.\n"
				. "  Issue #609.\n",
				$unparsed
			);
		}

		$notice = $unparsed > 0
			? sprintf(
				"  PHP %s is not in the part of the CI matrix this check could read (%s),\n"
				. "  but %d further `php:` key(s) in ci.yml are in a shape it cannot parse — so\n"
				. "  this is NOT a verdict. Check the workflow before trusting either answer.\n",
				$running,
				implode( ', ', $matrix ),
				$unparsed
			)
			: sprintf(
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
	 * ONLY the inline `php: [ ... ]` form is understood. That is deliberate — this
	 * is a regex, not a YAML parser, and a partial answer here is worse than none:
	 * a `php:` key written as a multi-line list, a `*anchor` alias, a
	 * `${{ … }}` expression or a `matrix.include` entry would silently drop
	 * versions and the notice would then confidently name a matrix CI does not
	 * have. So anything not recognised is COUNTED rather than skipped — see
	 * {@see woodev_ci_php_unparsed_matrices()} — and the notice says so out loud.
	 *
	 * @since 2.0.2
	 * @since 2.0.2 Unrecognised `php:` forms are reported instead of ignored.
	 *
	 * @param string $workflow Path to `ci.yml`.
	 *
	 * @return string[] The matrix versions, sorted, or `[]` when unreadable.
	 */
	function woodev_ci_php_matrix( string $workflow ): array {

		if ( ! is_readable( $workflow ) ) {
			return [];
		}

		if ( ! preg_match_all( '/^\s*-?\s*php:\s*\[([^\]]+)\]/m', (string) file_get_contents( $workflow ), $matches ) ) {
			return [];
		}

		preg_match_all( '/(\d+\.\d+)/', implode( ',', $matches[1] ), $versions );

		$matrix = array_values( array_unique( $versions[1] ) );

		usort( $matrix, 'version_compare' );

		return $matrix;
	}
}

if ( ! function_exists( 'woodev_ci_php_unparsed_matrices' ) ) {

	/**
	 * Counts `php:` keys in the workflow that {@see woodev_ci_php_matrix()} cannot
	 * read — a multi-line list, a `*anchor` alias, a `${{ … }}` expression, a
	 * `matrix.include` entry, anything that is not an inline `[ … ]`.
	 *
	 * This exists so the check can fail LOUDLY instead of quietly under-reporting.
	 * The whole point of #609's gate is that a number nobody can trust is worse
	 * than no number, and a regex that silently skips half a matrix produces
	 * exactly that.
	 *
	 * @since 2.0.2
	 *
	 * @param string $workflow Path to `ci.yml`.
	 *
	 * @return int How many `php:` keys were found in a shape this cannot parse.
	 */
	function woodev_ci_php_unparsed_matrices( string $workflow ): int {

		if ( ! is_readable( $workflow ) ) {
			return 0;
		}

		if ( ! preg_match_all( '/^\s*-?\s*php:[ \t]*(.*)$/m', (string) file_get_contents( $workflow ), $matches ) ) {
			return 0;
		}

		$unparsed = 0;

		foreach ( $matches[1] as $value ) {
			$value = trim( $value );

			// An inline list is the one form woodev_ci_php_matrix() understands.
			if ( '' !== $value && '[' === $value[0] && false !== strpos( $value, ']' ) ) {
				continue;
			}

			// A trailing comment on its own is not a value at all.
			if ( '' !== $value && '#' === $value[0] ) {
				continue;
			}

			++$unparsed;
		}

		return $unparsed;
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
