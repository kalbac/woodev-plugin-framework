<?php
/**
 * The persistence tests run on every supported PHP version.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use PHPUnit\Framework\TestCase;

/**
 * `ReflectionMethod::invoke()` on a protected method throws before PHP 8.1 unless `setAccessible( true )` was called, and the unit
 * matrix includes PHP 7.4 and 8.0 while a developer's machine runs a newer PHP. A test that forgets the call is green locally and
 * red in CI (it was, for the lifecycle wiring tests), so this scan fails a test file here that invokes a reflected method without
 * either the guard or no reflection at all.
 */
final class ReflectionAccessTest extends TestCase {
	/**
	 * Every file of the persistence tests that invokes a reflected method also makes it accessible for PHP before 8.1.
	 *
	 * @return void
	 */
	public function test_reflected_invocations_are_made_accessible_for_php_before_8_1(): void {
		$files = (array) glob( __DIR__ . '/*.php' );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			if ( __FILE__ === $file ) {
				continue;
			}

			$source = (string) file_get_contents( $file );

			if ( false === strpos( $source, 'ReflectionMethod' ) || false === strpos( $source, '->invoke' ) ) {
				continue;
			}

			$this->assertStringContainsString( 'setAccessible( true )', $source, basename( $file ) . ' invokes a reflected method without setAccessible() for PHP before 8.1' );
			$this->assertStringContainsString( 'PHP_VERSION_ID < 80100', $source, basename( $file ) . ' must guard setAccessible() (deprecated from PHP 8.5)' );
		}
	}
}
