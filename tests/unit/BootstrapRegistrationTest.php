<?php
/**
 * Bootstrap Registration Test
 *
 * Tests for Woodev_Plugin_Bootstrap plugin registration and version sorting.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * Class BootstrapRegistrationTest
 */
class BootstrapRegistrationTest extends TestCase {

	/**
	 * Reset the singleton instance before each test to prevent test pollution.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset the singleton instance via reflection.
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Tear down: also reset singleton to leave a clean state.
	 */
	protected function tearDown(): void {
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Helper: get the protected registered_plugins array via reflection.
	 *
	 * @param \Woodev_Plugin_Bootstrap $bootstrap The bootstrap instance.
	 * @return array
	 */
	private function get_registered_plugins( \Woodev_Plugin_Bootstrap $bootstrap ): array {
		$reflection = new ReflectionClass( $bootstrap );
		$property   = $reflection->getProperty( 'registered_plugins' );
		$property->setAccessible( true );

		return $property->getValue( $bootstrap );
	}

	/**
	 * Bootstrap::instance() should return a singleton.
	 */
	public function test_instance_returns_singleton(): void {
		Functions\expect( 'add_action' )->twice();

		$instance1 = \Woodev_Plugin_Bootstrap::instance();
		$instance2 = \Woodev_Plugin_Bootstrap::instance();

		$this->assertInstanceOf( \Woodev_Plugin_Bootstrap::class, $instance1 );
		$this->assertSame( $instance1, $instance2, 'instance() should always return the same object' );
	}

	/**
	 * register_plugin() should store the plugin in the registered_plugins array.
	 */
	public function test_register_plugin_stores_plugin(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$callback  = function () {};

		$bootstrap->register_plugin(
			'1.0.0',
			'Test Plugin',
			'/path/to/plugin/test-plugin.php',
			$callback,
			[
				'minimum_wc_version' => '5.0',
				'minimum_wp_version' => '5.9',
			]
		);

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 1, $registered );
		$this->assertSame( '1.0.0', $registered[0]['version'] );
		$this->assertSame( 'Test Plugin', $registered[0]['plugin_name'] );
		$this->assertSame( '/path/to/plugin/test-plugin.php', $registered[0]['path'] );
		$this->assertSame( $callback, $registered[0]['callback'] );
		$this->assertSame( '5.0', $registered[0]['args']['minimum_wc_version'] );
		$this->assertSame( '5.9', $registered[0]['args']['minimum_wp_version'] );
	}

	/**
	 * register_plugin() should accumulate multiple plugins.
	 */
	public function test_register_multiple_plugins(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin( '1.0.0', 'Plugin A', '/path/a.php', function () {} );
		$bootstrap->register_plugin( '2.0.0', 'Plugin B', '/path/b.php', function () {} );
		$bootstrap->register_plugin( '1.5.0', 'Plugin C', '/path/c.php', function () {} );

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 3, $registered );
		$this->assertSame( 'Plugin A', $registered[0]['plugin_name'] );
		$this->assertSame( 'Plugin B', $registered[1]['plugin_name'] );
		$this->assertSame( 'Plugin C', $registered[2]['plugin_name'] );
	}

	/**
	 * register_plugin() with optional args like is_payment_gateway and load_shipping_method.
	 */
	public function test_register_plugin_with_optional_args(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin(
			'1.2.0',
			'Payment Plugin',
			'/path/to/payment.php',
			function () {},
			[
				'is_payment_gateway'  => true,
				'load_shipping_method' => true,
				'minimum_wc_version'  => '6.0',
				'minimum_wp_version'  => '6.0',
			]
		);

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 1, $registered );
		$this->assertTrue( $registered[0]['args']['is_payment_gateway'] );
		$this->assertTrue( $registered[0]['args']['load_shipping_method'] );
	}

	/**
	 * register_plugin() with no optional args should store an empty args array.
	 */
	public function test_register_plugin_with_no_optional_args(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin( '1.0.0', 'Simple Plugin', '/path/simple.php', function () {} );

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 1, $registered );
		$this->assertIsArray( $registered[0]['args'] );
		$this->assertEmpty( $registered[0]['args'] );
	}

	/**
	 * framework_compare() should sort plugins by framework version in descending order.
	 */
	public function test_framework_compare_sorts_descending(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$a = [ 'version' => '1.0.0' ];
		$b = [ 'version' => '2.0.0' ];

		// b > a, so compare should return positive (b sorts first).
		$this->assertGreaterThan( 0, $bootstrap->framework_compare( $a, $b ) );

		// a < b, so compare should return negative (a sorts first).
		$this->assertLessThan( 0, $bootstrap->framework_compare( $b, $a ) );

		// Equal versions.
		$this->assertSame( 0, $bootstrap->framework_compare( $a, $a ) );
	}

	/**
	 * After sorting, the highest framework version plugin should be first.
	 */
	public function test_version_sorting_highest_first(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin( '1.0.0', 'Old Plugin', '/path/old.php', function () {} );
		$bootstrap->register_plugin( '3.0.0', 'Newest Plugin', '/path/newest.php', function () {} );
		$bootstrap->register_plugin( '2.0.0', 'Middle Plugin', '/path/middle.php', function () {} );

		$registered = $this->get_registered_plugins( $bootstrap );

		// Sort using the same comparator that load_plugins() uses.
		usort( $registered, [ $bootstrap, 'framework_compare' ] );

		$this->assertSame( '3.0.0', $registered[0]['version'], 'Highest version should be first after sorting' );
		$this->assertSame( '2.0.0', $registered[1]['version'], 'Middle version should be second after sorting' );
		$this->assertSame( '1.0.0', $registered[2]['version'], 'Lowest version should be last after sorting' );
	}

	/**
	 * Version sorting should handle semver patch versions correctly.
	 */
	public function test_version_sorting_with_patch_versions(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin( '1.4.2', 'Plugin A', '/path/a.php', function () {} );
		$bootstrap->register_plugin( '1.4.10', 'Plugin B', '/path/b.php', function () {} );
		$bootstrap->register_plugin( '1.4.3', 'Plugin C', '/path/c.php', function () {} );

		$registered = $this->get_registered_plugins( $bootstrap );
		usort( $registered, [ $bootstrap, 'framework_compare' ] );

		$this->assertSame( '1.4.10', $registered[0]['version'], '1.4.10 should sort highest' );
		$this->assertSame( '1.4.3', $registered[1]['version'], '1.4.3 should be second' );
		$this->assertSame( '1.4.2', $registered[2]['version'], '1.4.2 should be last' );
	}

	/**
	 * get_framework_version() should return the Woodev_Plugin::VERSION constant
	 * when Woodev_Plugin class exists.
	 */
	public function test_get_framework_version_returns_version_string(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$version   = $bootstrap->get_framework_version();

		// Woodev_Plugin is loaded in the test bootstrap, so VERSION should be available.
		if ( class_exists( 'Woodev_Plugin' ) ) {
			$this->assertSame( \Woodev_Plugin::VERSION, $version );
			$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $version );
		} else {
			// If Woodev_Plugin is not loaded, it should return an empty string.
			$this->assertSame( '', $version );
		}
	}

	/**
	 * Resetting the singleton via reflection should yield a new instance.
	 */
	public function test_singleton_reset_via_reflection(): void {
		Functions\stubs( [ 'add_action' ] );

		$instance1 = \Woodev_Plugin_Bootstrap::instance();

		// Reset singleton.
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$property   = $reflection->getProperty( 'instance' );
				$property->setValue( null, null );

		$instance2 = \Woodev_Plugin_Bootstrap::instance();

		$this->assertNotSame( $instance1, $instance2, 'After reset, a new instance should be created' );
	}

	/**
	 * A freshly created bootstrap instance should have no registered plugins.
	 */
	public function test_new_instance_has_no_registered_plugins(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap  = \Woodev_Plugin_Bootstrap::instance();
		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertIsArray( $registered );
		$this->assertEmpty( $registered, 'A new bootstrap instance should have no registered plugins' );
	}
}
