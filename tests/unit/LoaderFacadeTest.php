<?php
/**
 * Woodev_Loader entry facade tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/woodev/loader.php';

/**
 * @covers \Woodev_Loader
 */
final class LoaderFacadeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_bootstrap_singleton();

		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}
	}

	protected function tearDown(): void {
		$this->reset_bootstrap_singleton();
		parent::tearDown();
	}

	public function test_register_forwards_a_valid_definition_to_the_bootstrap(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/woodev-loader-facade-fixture.php';

		$result = \Woodev_Loader::register(
			$plugin_file,
			[
				'plugin_id'         => 'loader-facade-fixture',
				'plugin_name'       => 'Loader Facade Fixture',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Loader_Facade_Fixture_Plugin',
			]
		);

		$this->assertTrue( $result, 'facade should forward a valid definition and report success' );

		// The definition should have landed in the bootstrap, with plugin_file injected.
		$registered = $this->get_registered_plugins();
		$ids        = array_map(
			static function ( array $plugin ): string {
				return $plugin['definition']->get_plugin_id();
			},
			$registered
		);

		$this->assertContains( 'loader-facade-fixture', $ids );
	}

	public function test_register_returns_false_when_no_bootstrap_is_reachable(): void {
		// Only meaningful when no framework dir override exists; otherwise the override
		// always resolves a readable bootstrap and the unreadable branch cannot be exercised.
		if ( defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			$this->markTestSkipped( 'WOODEV_FRAMEWORK_DIR is defined; unreadable branch is unreachable.' );
		}

		$plugin_file = sys_get_temp_dir() . '/woodev-nonexistent-' . uniqid( '', true ) . '/plugin.php';

		$result = \Woodev_Loader::register(
			$plugin_file,
			[
				'plugin_id'         => 'x',
				'plugin_name'       => 'X',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'X_Plugin',
			]
		);

		$this->assertFalse( $result );
	}

	/**
	 * Resets the bootstrap singleton via reflection to isolate tests.
	 *
	 * @return void
	 */
	private function reset_bootstrap_singleton(): void {
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );

		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}

		$instance->setValue( null, null );
	}

	/**
	 * Reads the protected registered_plugins array from the live bootstrap.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_registered_plugins(): array {
		$bootstrap  = \Woodev_Plugin_Bootstrap::instance();
		$reflection = new ReflectionClass( $bootstrap );
		$property   = $reflection->getProperty( 'registered_plugins' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return (array) $property->getValue( $bootstrap );
	}
}
