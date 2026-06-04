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
		$instance->setValue( null, null );
	}

	/**
	 * Tear down: also reset singleton to leave a clean state.
	 */
	protected function tearDown(): void {
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
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

		return $property->getValue( $bootstrap );
	}

	/**
	 * Helper: build an explicit Platform v2 loader definition with overrides.
	 *
	 * @param string              $plugin_id         Unique plugin id.
	 * @param string              $plugin_name       Plugin name.
	 * @param string              $framework_version Framework version.
	 * @param array<string,mixed> $overrides         Definition overrides.
	 * @return array<string,mixed>
	 */
	private function loader_definition( string $plugin_id, string $plugin_name, string $framework_version, array $overrides = [] ): array {
		return array_merge(
			[
				'plugin_id'         => $plugin_id,
				'plugin_name'       => $plugin_name,
				'plugin_version'    => '1.0.0',
				'framework_version' => $framework_version,
				'plugin_file'       => '/path/' . $plugin_id . '.php',
				'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'callback'          => static function (): void {},
			],
			$overrides
		);
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
	 * register_loader_definition() should store the plugin in the registered_plugins array.
	 */
	public function test_register_loader_definition_stores_plugin(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$callback  = function () {};

		$bootstrap->register_loader_definition(
			$this->loader_definition(
				'test-plugin',
				'Test Plugin',
				'1.0.0',
				[
					'plugin_file' => '/path/to/plugin/test-plugin.php',
					'platform'    => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'callback'    => $callback,
				]
			)
		);

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 1, $registered );
		$this->assertSame( '1.0.0', $registered[0]['version'] );
		$this->assertSame( 'Test Plugin', $registered[0]['plugin_name'] );
		$this->assertSame( '/path/to/plugin/test-plugin.php', $registered[0]['path'] );
		$this->assertSame( $callback, $registered[0]['callback'] );
		$this->assertSame( '7.0', $registered[0]['args']['minimum_wc_version'] );
		$this->assertSame( '6.3', $registered[0]['args']['minimum_wp_version'] );
	}

	/**
	 * WooCommerce feature compatibility should be wired before plugins_loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_loader_definition_wires_early_woocommerce_feature_compatibility(): void {
		$registered_hooks = [];
		if ( ! defined( 'WC_VERSION' ) ) {
			define( 'WC_VERSION', '7.6.0' );
		}

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$registered_hooks ): void {
				$registered_hooks[] = [ $hook, $callback ];
			}
		);

		$this->reset_woocommerce_features_util_stub();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->loader_definition(
				'wc-feature-plugin',
				'WC Feature Plugin',
				'2.0.0',
				[
					'plugin_file'        => '/path/to/plugin/wc-feature-plugin.php',
					'platform'           => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements'       => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'supported_features' => [
						'hpos'   => true,
						'blocks' => [
							'cart'     => true,
							'checkout' => false,
						],
					],
				]
			)
		);

		$early_hooks = array_values(
			array_filter(
				$registered_hooks,
				static function ( array $hook ): bool {
					return 'before_woocommerce_init' === $hook[0];
				}
			)
		);

		$this->assertCount( 1, $early_hooks );
		$this->assertIsCallable( $early_hooks[0][1] );

		$early_hooks[0][1]();

		$this->assertSame(
			[
				[ 'custom_order_tables', '/path/to/plugin/wc-feature-plugin.php', true ],
				[ 'cart_checkout_blocks', '/path/to/plugin/wc-feature-plugin.php', false ],
			],
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$declared
		);
	}

	/**
	 * HPOS compatibility declarations should match the runtime WC >= 7.6 guard.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_loader_definition_requires_wc_76_for_hpos_compatibility(): void {
		$registered_hooks = [];
		define( 'WC_VERSION', '7.5.0' );

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$registered_hooks ): void {
				$registered_hooks[] = [ $hook, $callback ];
			}
		);

		$this->reset_woocommerce_features_util_stub();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->loader_definition(
				'wc-feature-plugin',
				'WC Feature Plugin',
				'2.0.0',
				[
					'plugin_file'        => '/path/to/plugin/wc-feature-plugin.php',
					'platform'           => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements'       => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'supported_features' => [
						'hpos'   => true,
						'blocks' => [
							'cart'     => true,
							'checkout' => true,
						],
					],
				]
			)
		);

		$early_hooks = array_values(
			array_filter(
				$registered_hooks,
				static function ( array $hook ): bool {
					return 'before_woocommerce_init' === $hook[0];
				}
			)
		);

		$this->assertCount( 1, $early_hooks );
		$early_hooks[0][1]();

		$this->assertSame(
			[
				[ 'custom_order_tables', '/path/to/plugin/wc-feature-plugin.php', false ],
				[ 'cart_checkout_blocks', '/path/to/plugin/wc-feature-plugin.php', true ],
			],
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$declared
		);
	}

	/**
	 * Pure-WordPress plugins should never wire WooCommerce feature compatibility.
	 */
	public function test_register_loader_definition_skips_woocommerce_feature_compatibility_for_wordpress_platform(): void {
		$registered_hooks = [];

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$registered_hooks ): void {
				$registered_hooks[] = [ $hook, $callback ];
			}
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->loader_definition(
				'wp-only-plugin',
				'WP Only Plugin',
				'2.0.0',
				[
					'plugin_file'  => '/path/to/plugin/wp-only-plugin.php',
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
					'requirements' => [
						'php'       => '7.4',
						'wordpress' => '6.3',
					],
				]
			)
		);

		$early_hooks = array_values(
			array_filter(
				$registered_hooks,
				static function ( array $hook ): bool {
					return 'before_woocommerce_init' === $hook[0];
				}
			)
		);

		$this->assertCount( 0, $early_hooks, 'A pure-WordPress plugin must not wire WooCommerce feature compatibility' );
	}

	/**
	 * Defines a WooCommerce FeaturesUtil stub for isolated feature declaration tests.
	 *
	 * @return void
	 */
	private function reset_woocommerce_features_util_stub(): void {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false ) ) {
			eval( 'namespace Automattic\\WooCommerce\\Utilities; class FeaturesUtil { public static $declared = []; public static function declare_compatibility( $feature, $plugin_file, $compatible ) { self::$declared[] = [ $feature, $plugin_file, $compatible ]; } }' );
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::$declared = [];
	}

	/**
	 * register_loader_definition() should accumulate multiple plugins.
	 */
	public function test_register_multiple_plugins(): void {
		Functions\stubs( [ 'add_action' ] );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-a', 'Plugin A', '1.0.0' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-b', 'Plugin B', '2.0.0' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-c', 'Plugin C', '1.5.0' ) );

		$registered = $this->get_registered_plugins( $bootstrap );

		$this->assertCount( 3, $registered );
		$this->assertSame( 'Plugin A', $registered[0]['plugin_name'] );
		$this->assertSame( 'Plugin B', $registered[1]['plugin_name'] );
		$this->assertSame( 'Plugin C', $registered[2]['plugin_name'] );
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

		$bootstrap->register_loader_definition( $this->loader_definition( 'old-plugin', 'Old Plugin', '1.0.0' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'newest-plugin', 'Newest Plugin', '3.0.0' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'middle-plugin', 'Middle Plugin', '2.0.0' ) );

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

		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-a', 'Plugin A', '1.4.2' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-b', 'Plugin B', '1.4.10' ) );
		$bootstrap->register_loader_definition( $this->loader_definition( 'plugin-c', 'Plugin C', '1.4.3' ) );

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
		$loaded    = class_exists( 'Woodev_Plugin', false );
		$version   = $bootstrap->get_framework_version();

		if ( $loaded ) {
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
