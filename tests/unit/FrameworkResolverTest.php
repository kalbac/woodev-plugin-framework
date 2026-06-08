<?php
/**
 * Framework resolver tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-resolver.php';

use Brain\Monkey\Functions;

/**
 * Test helper for main_class-only resolver definitions.
 */
class Resolver_Main_Class_Only_Plugin {

	/** @var bool Whether instance() was called. */
	public static bool $loaded = false;

	/**
	 * Marks the helper as loaded.
	 *
	 * @return void
	 */
	public static function instance(): void {
		self::$loaded = true;
	}
}

/**
 * Test helper exposing protected resolver methods.
 */
class Resolver_Testable_Framework_Resolver extends \Woodev\Framework\Framework_Resolver {

	/** @var list<string> Plugin files used for path resolution. */
	public array $path_requests = [];

	/** @var string|null WooCommerce version used for resolver assertions. */
	public ?string $wc_version = null;

	/**
	 * Exposes early capability class loading for assertions.
	 *
	 * @param array<string,mixed> $plugin Registered plugin.
	 * @return void
	 */
	public function load_early_classes_for_test( array $plugin, array $framework_plugin = [] ): void {
		$this->load_early_capability_classes( $plugin, $framework_plugin );
	}

	/**
	 * Returns a predictable plugin path for resolver assertions.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public function get_plugin_path( string $file ): string {
		$this->path_requests[] = $file;

		return dirname( __DIR__, 2 );
	}

	/**
	 * Gets the test WooCommerce version.
	 *
	 * @return string|null
	 */
	protected function get_wc_version(): ?string {
		return $this->wc_version;
	}
}

/**
 * Class FrameworkResolverTest
 */
class FrameworkResolverTest extends TestCase {

	/**
	 * Explicit WordPress loader definitions should be accepted and normalized.
	 */
	public function test_registers_explicit_wordpress_loader_definition(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$accepted = $resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
					'requirements' => [
						'php'       => '7.4',
						'wordpress' => '6.3',
					],
				]
			)
		);

		$registered = $resolver->get_registered_plugins();

		$this->assertTrue( $accepted );
		$this->assertCount( 1, $registered );
		$this->assertSame( 'test-plugin', $registered[0]['definition']->get_plugin_id() );
		$this->assertSame( 'wordpress', $registered[0]['definition']->get_platform() );
		$this->assertEmpty( $resolver->get_invalid_loader_definitions() );
	}

	/**
	 * Invalid loader definitions should be recorded without throwing broad fatals.
	 */
	public function test_records_invalid_loader_definition_errors(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$accepted = $resolver->register_loader_definition(
			[
				'plugin_id'         => 'broken-plugin',
				'plugin_name'       => 'Broken Plugin',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => 'payment_gateway',
				'requirements'      => [
					'php' => '7.4',
				],
			]
		);

		$invalid = $resolver->get_invalid_loader_definitions();

		$this->assertFalse( $accepted );
		$this->assertCount( 1, $invalid );
		$this->assertContains( 'Loader definition requires at least one of main_class or callback.', $invalid[0]['errors'] );
		$this->assertContains( 'Unsupported loader definition platform: payment_gateway.', $invalid[0]['errors'] );
		$this->assertContains( 'Missing required loader requirement: wordpress.', $invalid[0]['errors'] );
	}

	/**
	 * Payment and shipping capabilities are early WooCommerce hints, not standalone platforms.
	 */
	public function test_rejects_specialized_capabilities_on_wordpress_platform(): void {
		$errors     = [];
		$definition = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			$this->get_loader_definition(
				[
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
					'requirements' => [
						'php'       => '7.4',
						'wordpress' => '6.3',
					],
					'capabilities' => [ \Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY ],
				]
			),
			$errors
		);

		$this->assertNull( $definition );
		$this->assertContains( 'Payment gateway capability requires the woocommerce platform.', $errors );
	}

	/**
	 * EDD loader definitions are reserved for a future spec and rejected in v2.0.
	 */
	public function test_rejects_reserved_edd_platform(): void {
		$errors     = [];
		$definition = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			$this->get_loader_definition(
				[
					'platform' => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_EDD,
				]
			),
			$errors
		);

		$this->assertNull( $definition );
		$this->assertContains( 'EDD loader definitions are reserved and unsupported in Platform v2.0.', $errors );
	}

	/**
	 * Pure WordPress loaders should not require WooCommerce when callbacks run.
	 */
	public function test_loads_wordpress_definition_without_woocommerce_requirement(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$loaded   = false;

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
					'requirements' => [
						'php'       => '7.4',
						'wordpress' => '6.3',
					],
					'callback'     => static function () use ( &$loaded ): void {
						$loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $loaded );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertEmpty( $resolver->get_incompatible_wc_version_plugins() );
	}

	/**
	 * WooCommerce loader definitions should be skipped when WooCommerce is unavailable.
	 */
	public function test_skips_woocommerce_definition_when_woocommerce_is_unavailable(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$loaded   = false;

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'callback'     => static function () use ( &$loaded ): void {
						$loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertFalse( $loaded );
		$this->assertCount( 1, $resolver->get_incompatible_wc_version_plugins() );
	}

	/**
	 * Explicit main_class-only definitions should use the class instance() bootstrap path.
	 */
	public function test_loads_main_class_only_definition_with_instance_method(): void {
		$resolver                              = new \Woodev\Framework\Framework_Resolver();
		Resolver_Main_Class_Only_Plugin::$loaded = false;

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'callback'   => null,
					'main_class' => Resolver_Main_Class_Only_Plugin::class,
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( Resolver_Main_Class_Only_Plugin::$loaded );
	}

	/**
	 * Missing main_class-only definitions should be recorded as invalid instead of silently no-oping.
	 */
	public function test_records_missing_main_class_definition_during_load(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'callback'   => null,
					'main_class' => 'Resolver_Missing_Main_Class_Plugin',
				]
			)
		);

		$resolver->load_plugins();

		$invalid = $resolver->get_invalid_loader_definitions();

		$this->assertCount( 1, $invalid );
		$this->assertContains(
			'Loader definition main_class does not exist: Resolver_Missing_Main_Class_Plugin.',
			$invalid[0]['errors']
		);
		$this->assertEmpty( $resolver->get_active_plugins() );
	}

	/**
	 * PHP requirements should be enforced before plugin callbacks run.
	 */
	public function test_skips_definition_when_php_requirement_fails(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$loaded   = false;

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'requirements' => [
						'php'       => '99.0',
						'wordpress' => '6.3',
					],
					'callback'     => static function () use ( &$loaded ): void {
						$loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertFalse( $loaded );
		$this->assertCount( 1, $resolver->get_incompatible_php_version_plugins() );
	}

	/**
	 * Specialized WooCommerce capabilities should make the WooCommerce base available first.
	 */
	public function test_specialized_capabilities_load_woocommerce_base_dependency(): void {
		$errors     = [];
		$definition = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			$this->get_loader_definition(
				[
					'plugin_file'  => dirname( __DIR__, 2 ) . '/woodev-test-plugin.php',
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'capabilities' => [
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY,
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_SHIPPING_METHOD,
					],
				]
			),
			$errors
		);

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);

		$resolver = new Resolver_Testable_Framework_Resolver();
		$resolver->load_early_classes_for_test( $definition->to_legacy_plugin() );

		$this->assertSame( [], $errors );
		$this->assertTrue( class_exists( \Woodev\Framework\Woocommerce_Plugin::class, false ) );
		$this->assertTrue( class_exists( \Woodev_Payment_Gateway_Plugin::class, false ) );
		$this->assertTrue( class_exists( \Woodev\Framework\Shipping\Shipping_Plugin::class, false ) );
	}

	/**
	 * WooCommerce plugin capability should preload only the WooCommerce base/helper classes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerce_plugin_capability_loads_only_woocommerce_base_dependency(): void {
		require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

		$errors     = [];
		$definition = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			$this->get_loader_definition(
				[
					'plugin_file'  => dirname( __DIR__, 2 ) . '/woodev-test-plugin.php',
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'capabilities' => [
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_WOOCOMMERCE_PLUGIN,
					],
				]
			),
			$errors
		);

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);

		$resolver = new Resolver_Testable_Framework_Resolver();
		$resolver->load_early_classes_for_test( $definition->to_legacy_plugin() );

		$this->assertSame( [], $errors );
		$this->assertTrue( class_exists( \Woodev\Framework\Woocommerce_Plugin::class, false ) );
		$this->assertTrue( class_exists( \Woodev\Framework\Woocommerce_Helper::class, false ) );
		$this->assertFalse( class_exists( \Woodev_Payment_Gateway_Plugin::class, false ) );
		$this->assertFalse( class_exists( \Woodev\Framework\Shipping\Shipping_Plugin::class, false ) );
	}

	/**
	 * Specialized base classes should be available before the plugin callback runs.
	 */
	public function test_specialized_child_classes_can_be_declared_inside_callback(): void {
		$resolver = new Resolver_Testable_Framework_Resolver();
		$loaded   = false;

		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_file'  => dirname( __DIR__, 2 ) . '/woodev-test-plugin.php',
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'capabilities' => [
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY,
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_SHIPPING_METHOD,
					],
					'callback'     => static function () use ( &$loaded ): void {
						if ( ! class_exists( 'Resolver_Callback_Payment_Plugin', false ) ) {
							eval( 'abstract class Resolver_Callback_Payment_Plugin extends \\Woodev_Payment_Gateway_Plugin {}' );
						}

						if ( ! class_exists( 'Resolver_Callback_Shipping_Plugin', false ) ) {
							eval( 'abstract class Resolver_Callback_Shipping_Plugin extends \\Woodev\\Framework\\Shipping\\Shipping_Plugin {}' );
						}

						$loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $loaded );
		$this->assertTrue( is_subclass_of( 'Resolver_Callback_Payment_Plugin', \Woodev_Payment_Gateway_Plugin::class ) );
		$this->assertTrue( is_subclass_of( 'Resolver_Callback_Shipping_Plugin', \Woodev\Framework\Shipping\Shipping_Plugin::class ) );
	}

	/**
	 * Early capability classes should load from the selected framework copy.
	 */
	public function test_specialized_capabilities_use_selected_framework_path(): void {
		$errors     = [];
		$definition = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			$this->get_loader_definition(
				[
					'plugin_file'  => 'lower-version-plugin.php',
					'platform'     => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
					'requirements' => [
						'php'         => '7.4',
						'wordpress'   => '6.3',
						'woocommerce' => '7.0',
					],
					'capabilities' => [
						\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY,
					],
				]
			),
			$errors
		);

		$resolver = new Resolver_Testable_Framework_Resolver();
		$resolver->load_early_classes_for_test(
			$definition->to_legacy_plugin(),
			[
				'path' => 'selected-framework-plugin.php',
			]
		);

		$this->assertSame( [], $errors );
		$this->assertSame( [ 'selected-framework-plugin.php' ], $resolver->path_requests );
	}

	/**
	 * H2: Resolver must work without Woodev_Plugin_Bootstrap loaded. The injected
	 * callback should be wired to admin_notices when an incompatible plugin is
	 * registered, instead of referencing the legacy bootstrap singleton.
	 */
	public function test_resolver_wires_injected_update_notice_callback_without_bootstrap_dependency(): void {
		$injected_renderer = static function (): void {};
		$resolver          = new \Woodev\Framework\Framework_Resolver( $injected_renderer );
		$captured          = null;

		// Prove the test does not rely on composer autoloading Woodev_Plugin_Bootstrap.
		// The resolver must not reference that class at all.
		$bootstrap_loaded_during_test = false;
		$resolver_class               = new \ReflectionClass( $resolver );

		foreach ( $resolver_class->getMethods() as $method ) {
			$file = $method->getFileName();
			if ( ! $file ) {
				continue;
			}
			$source = file_get_contents( $file );
			if ( false !== strpos( $source, 'Woodev_Plugin_Bootstrap' ) ) {
				$bootstrap_loaded_during_test = true;
				break;
			}
		}

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$captured ): void {
				if ( 'admin_notices' === $hook ) {
					$captured = $callback;
				}
			}
		);
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'requirements' => [
						'php'       => '99.0',
						'wordpress' => '6.3',
					],
				]
			)
		);

		$resolver->load_plugins();

		$this->assertFalse(
			$bootstrap_loaded_during_test,
			'Resolver source code must not reference Woodev_Plugin_Bootstrap to keep the minimal-resolver boundary intact.'
		);
		$this->assertCount( 1, $resolver->get_incompatible_php_version_plugins() );
		$this->assertSame( $injected_renderer, $captured, 'Resolver must wire the injected renderer to admin_notices, not a bootstrap singleton.' );
	}

	/**
	 * H3: load_plugins() must be idempotent so long-running processes (WP-Cron,
	 * Action Scheduler) do not double-run callbacks.
	 */
	public function test_load_plugins_is_idempotent(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$call_count = 0;

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'callback' => static function () use ( &$call_count ): void {
						++$call_count;
					},
				]
			)
		);

		$resolver->load_plugins();
		$resolver->load_plugins();

		$this->assertSame( 1, $call_count );
		$this->assertCount( 1, $resolver->get_active_plugins() );
	}

	/**
	 * H4: Two registrations with the same plugin_id must be deduped: the first
	 * wins, the second is recorded in invalid_loader_definitions.
	 */
	public function test_resolver_dedupes_loader_definitions_by_plugin_id(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$first  = $resolver->register_loader_definition(
			$this->get_loader_definition( [ 'plugin_name' => 'First' ] )
		);
		$second = $resolver->register_loader_definition(
			$this->get_loader_definition( [ 'plugin_name' => 'Second' ] )
		);

		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertCount( 1, $resolver->get_registered_plugins() );
		$this->assertCount( 1, $resolver->get_invalid_loader_definitions() );
		$this->assertContains(
			'Duplicate plugin_id: test-plugin.',
			$resolver->get_invalid_loader_definitions()[0]['errors']
		);
	}

	/**
	 * L-2: Multi-version framework arbitration. When two plugins register
	 * with different framework versions, the highest-version copy is
	 * selected and the lower-version plugin is loaded via
	 * `require_once` from the higher-version path. Sorting uses
	 * `version_compare` so '2.10.0' > '2.9.0' (numeric segment
	 * comparison, not lexical).
	 */

	/**
	 * L-2: Multi-version framework arbitration. When two plugins register
	 * with different framework versions, the highest-version copy is
	 * selected and the lower-version plugin is loaded via
	 * `require_once` from the higher-version path. Sorting uses
	 * `version_compare` so '2.10.0' > '2.9.0' (numeric segment
	 * comparison, not lexical).
	 */
	public function test_multi_version_arbitration_picks_highest_version(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$low_loaded  = false;
		$high_loaded = false;

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		// Register lower first; the resolver must still pick the higher version.
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'         => 'low-plugin',
					'plugin_name'       => 'Low Version Plugin',
					'framework_version' => '2.0.0',
					'callback'          => static function () use ( &$low_loaded ): void {
						$low_loaded = true;
					},
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'         => 'high-plugin',
					'plugin_name'       => 'High Version Plugin',
					'framework_version' => '2.10.0',
					'callback'          => static function () use ( &$high_loaded ): void {
						$high_loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $high_loaded, 'Highest-version plugin must run its callback.' );
		$this->assertTrue( $low_loaded, 'Lower-version plugin must still run (its framework is older but still compatible).' );
		$active = $resolver->get_active_plugins();
		$this->assertCount( 2, $active );
		$this->assertSame( 'High Version Plugin', $active[0]['plugin_name'] );
	}

	/**
	 * Explicit definitions must preserve the selected framework backwards-compatible window.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_explicit_definition_backwards_compatible_window_blocks_too_old_frameworks(): void {
		$resolver    = new \Woodev\Framework\Framework_Resolver();
		$low_loaded  = false;
		$high_loaded = false;

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'         => 'low-plugin',
					'plugin_name'       => 'Low Version Plugin',
					'framework_version' => '1.9.0',
					'callback'          => static function () use ( &$low_loaded ): void {
						$low_loaded = true;
					},
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'             => 'high-plugin',
					'plugin_name'           => 'High Version Plugin',
					'framework_version'     => '2.2.0',
					'backwards_compatible' => '2.0.0',
					'callback'              => static function () use ( &$high_loaded ): void {
						$high_loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $high_loaded );
		$this->assertFalse( $low_loaded );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertCount( 1, $resolver->get_incompatible_framework_plugins() );
		$this->assertSame( 'Low Version Plugin', $resolver->get_incompatible_framework_plugins()[0]['plugin_name'] );
	}

	/**
	 * fails_wordpress_requirement() must enforce the WordPress minimum from the
	 * explicit definition's `requirements.wordpress`, and the resolved notice
	 * data must expose it as `minimum_wp_version` for the admin notices.
	 */
	public function test_fails_wordpress_requirement_enforces_definition_wordpress_minimum(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$loaded   = false;

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'    => 'wp-versioned-plugin',
					'plugin_name'  => 'WP-Versioned Plugin',
					'requirements' => [
						'php'       => '7.4',
						'wordpress' => '99.0',
					],
					'callback'     => static function () use ( &$loaded ): void {
						$loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertFalse( $loaded );
		$this->assertCount( 1, $resolver->get_incompatible_wp_version_plugins() );
		$this->assertSame( '99.0', $resolver->get_incompatible_wp_version_plugins()[0]['args']['minimum_wp_version'] );
	}

	/**
	 * L-2: Resolver boundary negative assertion. The resolver must
	 * not own runtime platform behavior — specifically, it must not
	 * `add_action` for `plugins_loaded`, `admin_init`, or any other
	 * WP lifecycle hook. Those are bootstrap concerns. The resolver
	 * only fires `woodev_plugins_loaded` (its own internal action).
	 */
	public function test_resolver_does_not_wire_wordpress_lifecycle_hooks(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$registered_hooks = [];

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$registered_hooks ): void {
				$registered_hooks[] = $hook;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'         => 'no-wp-hooks-plugin',
					'plugin_name'       => 'No WP Hooks Plugin',
				]
			)
		);
		$resolver->load_plugins();
		$resolver->maybe_deactivate_framework_plugins();

		$this->assertNotContains( 'plugins_loaded', $registered_hooks );
		$this->assertNotContains( 'admin_init', $registered_hooks );
	}

	/**
	 * L-2: Bootstrap delegation chain. The bootstrap singleton must
	 * reflect resolver state (registered, active, incompatible lists)
	 * after each operation, and its register_loader_definition() entry
	 * point must route to the resolver and surface the same results.
	 *
	 * Runs in a separate process so the Woodev_Plugin_Bootstrap
	 * singleton does not leak from other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bootstrap_delegates_register_and_load_to_resolver(): void {
		require_once dirname( __DIR__, 2 ) . '/woodev/bootstrap.php';

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$accepted = $bootstrap->register_loader_definition(
			[
				'plugin_id'         => 'boot-test-plugin',
				'plugin_name'       => 'Boot Test Plugin',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'BootTestPlugin',
				'callback'          => static function (): void {},
			]
		);

		$this->assertTrue( $accepted );
		$this->assertEmpty( $bootstrap->get_invalid_loader_definitions() );
		// Bootstrap exposes reflected state via sync_resolver_state().
		$reflection = new \ReflectionClass( $bootstrap );
		$registered_prop = $reflection->getProperty( 'registered_plugins' );
		if ( PHP_VERSION_ID < 80100 ) {
			$registered_prop->setAccessible( true );
		}
		$this->assertCount( 1, $registered_prop->getValue( $bootstrap ) );
	}

	/**
	 * Returns a valid loader definition with optional overrides.
	 *
	 * @param array<string,mixed> $overrides Definition overrides.
	 * @return array<string,mixed>
	 */
	private function get_loader_definition( array $overrides = [] ): array {
		return array_merge(
			[
				'plugin_id'         => 'test-plugin',
				'plugin_name'       => 'Test Plugin',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Woodev_Test_Plugin',
				'callback'          => static function (): void {},
			],
			$overrides
		);
	}
}
