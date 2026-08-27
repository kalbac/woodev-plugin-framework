<?php
namespace Woodev\Tests\Unit;

/**
 * Structural guard for the integration suite's shared base class (#561).
 *
 * `Woodev\Tests\Integration\TestCase::setUp()` is where cross-test state resets live — the
 * `Shipping_Tools_Registry` reset added in #514/m2, and whatever a later session adds beside
 * it. A test class that extends `WP_UnitTestCase` directly silently opts out of all of them,
 * and the opt-out is invisible: nothing fails, the class just does not get the reset.
 *
 * That is exactly how the one exception arose. It was harmless when written and stayed
 * harmless for months, so nobody re-examined it when the base grew a reset that mattered.
 *
 * This test is deliberately a STRUCTURAL check rather than a convention written in a doc:
 * a rule nothing enforces is a rule that drifts (this repo's own recorded experience with
 * the Serena rule). It lives in the UNIT suite on purpose — it must run even when wp-env is
 * not up, because the failure it catches is one that would otherwise only be noticed by the
 * suite it is about.
 */
class IntegrationSuiteBaseClassTest extends TestCase {

	/** @var string the one class allowed to extend WP_UnitTestCase directly. */
	private const BASE = 'tests/integration/TestCase.php';

	public function test_every_integration_test_class_extends_the_shared_base(): void {
		$offenders = [];

		foreach ( $this->integration_test_files() as $relative => $path ) {
			if ( self::BASE === $relative ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( ! preg_match( '/\bclass\s+(\w+)\s+extends\s+([^\s{]+)/', $source, $m ) ) {
				continue;
			}

			// `TestCase` here is the imported Woodev\Tests\Integration\TestCase; the base
			// itself is excluded above, so an unqualified `TestCase` cannot mean anything
			// else in this directory.
			if ( ! in_array( $m[2], [ 'TestCase', '\Woodev\Tests\Integration\TestCase', 'Woodev\Tests\Integration\TestCase' ], true ) ) {
				$offenders[] = sprintf( '%s (%s extends %s)', $relative, $m[1], $m[2] );
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Integration test classes must extend Woodev\Tests\Integration\TestCase so they get its setUp() resets. '
			. "If a class genuinely needs a bare WP_UnitTestCase, add it to this test's allow-list WITH the reason."
		);
	}

	/**
	 * The control: without it, an empty `$offenders` would also be what a glob that matched
	 * nothing produces — the assertion would pass on a renamed directory.
	 */
	public function test_the_scan_actually_finds_the_integration_suite(): void {
		$files = $this->integration_test_files();

		$this->assertGreaterThan( 10, count( $files ) );
		$this->assertArrayHasKey( self::BASE, $files );
	}

	/**
	 * @return array<string,string> repo-relative path => absolute path.
	 */
	private function integration_test_files(): array {
		$root  = dirname( __DIR__, 2 );
		$dir   = $root . '/tests/integration';
		$files = [];

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative           = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			$files[ $relative ] = $file->getPathname();
		}

		ksort( $files );

		return $files;
	}
}
