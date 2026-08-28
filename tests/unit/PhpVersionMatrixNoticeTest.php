<?php
/**
 * Unit tests for the PHP-version divergence gate in `bin/php-version-matrix.php` (#609).
 *
 * The gate exists because three different PHP versions are in play and none of
 * them was announced anywhere: the local interpreter (8.5), the CI matrix
 * (7.4-8.3) and `composer.json`'s `config.platform.php` pin (8.1). A green local
 * run is evidence about the local version only.
 *
 * The notice is printed by `bin/check-php-version.php` from a composer script,
 * NOT from `tests/bootstrap.php`: PHPUnit runs some tests in separate processes
 * and parses the child's output as the result, so writing there turned 36
 * unrelated tests into errors. Measured while building this gate.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/bin/php-version-matrix.php';

/**
 * @covers ::woodev_php_version_notice
 * @covers ::woodev_ci_php_matrix
 */
final class PhpVersionMatrixNoticeTest extends TestCase {

	/**
	 * The silent case: the running version is one CI actually runs.
	 *
	 * @return void
	 */
	public function test_a_version_inside_the_matrix_says_nothing(): void {
		$this->assertSame( '', woodev_php_version_notice( '8.1', [ '7.4', '8.0', '8.1' ], '8.1' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_version_outside_the_matrix_names_the_matrix_and_the_card(): void {
		$notice = woodev_php_version_notice( '8.5', [ '7.4', '8.0', '8.1' ], '' );

		$this->assertStringContainsString( 'PHP 8.5 is NOT in the CI matrix', $notice );
		$this->assertStringContainsString( '7.4, 8.0, 8.1', $notice );
		$this->assertStringContainsString( '#609', $notice );
	}

	/**
	 * The composer pin is a SECOND divergence and is reported separately — it is
	 * about which versions the dependencies were resolved for, not which version
	 * CI runs.
	 *
	 * @return void
	 */
	public function test_a_diverging_composer_platform_pin_is_reported_too(): void {
		$notice = woodev_php_version_notice( '8.5', [ '7.4', '8.3' ], '8.1' );

		$this->assertStringContainsString( 'config.platform.php to 8.1', $notice );
	}

	/**
	 * The pin is NOT reported when it matches what is running: nothing diverged.
	 *
	 * @return void
	 */
	public function test_a_matching_composer_platform_pin_is_not_reported(): void {
		$notice = woodev_php_version_notice( '8.5', [ '7.4', '8.3' ], '8.5' );

		$this->assertStringNotContainsString( 'config.platform.php', $notice );
		$this->assertStringContainsString( 'NOT in the CI matrix', $notice );
	}

	/**
	 * An unreadable or matrix-less workflow must make the gate silent rather than
	 * shout about an empty matrix on every single test run.
	 *
	 * @return void
	 */
	public function test_an_unreadable_matrix_makes_the_gate_silent(): void {
		$this->assertSame( [], woodev_ci_php_matrix( __DIR__ . '/there-is-no-such-workflow.yml' ) );
		$this->assertSame( '', woodev_php_version_notice( '8.5', [], '8.1' ) );
	}

	/**
	 * The regression this test exists for: `ci.yml` carries TWO `php:` matrices
	 * and the FIRST one in reading order (`PHP Compat`) omits 8.1. Reading only
	 * the first reported a matrix CI does not have — observed while building this
	 * gate, before the union was added.
	 *
	 * @return void
	 */
	public function test_the_matrix_is_the_union_of_every_php_list_in_the_workflow(): void {
		$workflow = tempnam( sys_get_temp_dir(), 'woodev-ci-' );

		file_put_contents(
			$workflow,
			"jobs:\n"
			. "  compat:\n"
			. "    strategy:\n"
			. "      matrix:\n"
			. "        php: ['7.4', '8.0', '8.2', '8.3']\n"
			. "  unit:\n"
			. "    strategy:\n"
			. "      matrix:\n"
			. "        php: ['7.4', '8.0', '8.1', '8.2', '8.3']\n"
		);

		$matrix = woodev_ci_php_matrix( $workflow );

		unlink( $workflow );

		$this->assertSame( [ '7.4', '8.0', '8.1', '8.2', '8.3' ], $matrix );
	}

	/**
	 * The gate must describe THIS repository, not a fixture: the real workflow
	 * has to yield a non-empty, sorted matrix, or the check is silently dead.
	 *
	 * @return void
	 */
	public function test_the_real_workflow_yields_a_non_empty_matrix(): void {
		$matrix = woodev_ci_php_matrix( dirname( __DIR__, 2 ) . '/.github/workflows/ci.yml' );

		$this->assertNotEmpty( $matrix, 'the gate cannot compare against a matrix it failed to read' );
		$this->assertContains( '8.1', $matrix, 'the platform-pinned version must be in the matrix CI runs' );

		$sorted = $matrix;
		usort( $sorted, 'version_compare' );

		$this->assertSame( $sorted, $matrix );
	}
}
