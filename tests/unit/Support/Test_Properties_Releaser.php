<?php
/**
 * Frees what a finished unit test still holds in its own properties.
 *
 * @package Woodev\Tests\Unit\Support
 */

namespace Woodev\Tests\Unit\Support;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;

/**
 * Drops every property a test class declares, right after the test has run (#1842).
 *
 * PHPUnit builds the whole suite first and keeps every test object alive until the run ends, so
 * whatever a test left in `$this->shop`, `$this->db` or `$this->plugin` (a fake database, a fake
 * shop, a mock tree) stays in memory for ~10 800 tests in a row. On PHP 7.4 that was ~50 MB for
 * the marketplace tests alone and put the whole run within a few MB of its memory limit. The
 * test is over by the time this runs, and `setUp()` rebuilds everything the next test needs, so
 * nothing is lost by letting go.
 *
 * Registered in `phpunit.xml` as a listener, not as a base-class `tearDown()`, because tests
 * extend PHPUnit's `TestCase` directly as often as they extend ours — one place covers them all.
 *
 * Only the properties DECLARED on test classes are touched; PHPUnit's own (`$name`, `$data`,
 * the dependency bookkeeping) are not, and neither are static ones (those belong to the class,
 * not to this one test).
 */
final class Test_Properties_Releaser implements TestListener {

	use TestListenerDefaultImplementation;

	/**
	 * Property names to drop, per declaring class, filled the first time a test class is seen.
	 *
	 * @var array<string, string[]>
	 */
	private static $names = [];

	/**
	 * A test has ended: drops its own properties.
	 *
	 * @param Test  $test Test that ended.
	 * @param float $time Time it took.
	 *
	 * @return void
	 */
	public function endTest( Test $test, float $time ): void {
		if ( ! $test instanceof TestCase ) {
			return;
		}

		$class = new \ReflectionClass( $test );

		while ( $class && TestCase::class !== $class->getName() && ! $class->isInternal() ) {
			$declaring = $class->getName();

			if ( ! isset( self::$names[ $declaring ] ) ) {
				self::$names[ $declaring ] = [];

				foreach ( $class->getProperties() as $property ) {
					if ( ! $property->isStatic() && $property->getDeclaringClass()->getName() === $declaring ) {
						self::$names[ $declaring ][] = $property->getName();
					}
				}
			}

			\Closure::bind(
				static function ( $object, array $names ): void {
					foreach ( $names as $name ) {
						unset( $object->$name );
					}
				},
				null,
				$declaring
			)( $test, self::$names[ $declaring ] );

			$class = $class->getParentClass();
		}
	}
}
