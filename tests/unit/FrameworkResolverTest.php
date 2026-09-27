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
 * Test helper: registered as a loader definition's main_class, never invoked directly.
 * `get_loader_definition_for_class()` must resolve a SUBCLASS of this by walking up.
 */
class Resolver_Definition_Lookup_Base {

	/** @return void */
	public static function instance(): void {}
}

/**
 * Test helper: never itself registered as a main_class — only its parent is.
 */
class Resolver_Definition_Lookup_Child extends Resolver_Definition_Lookup_Base {
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
					'download_id'       => 9101,
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
					'download_id'       => 9102,
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
				'download_id'       => 9910,
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
	 * #916: two plugins claiming the same download id — the first (in resolver order)
	 * is invoked, the second is quarantined instead of invoked, and the quarantine entry
	 * names the holder.
	 */
	public function test_resolver_quarantines_second_plugin_with_same_download_id(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$a_loaded = false;
		$b_loaded = false;

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
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 500,
					'plugin_name' => 'Download Id Plugin A',
					'callback'    => static function () use ( &$a_loaded ): void {
						$a_loaded = true;
					},
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 500,
					'plugin_name' => 'Download Id Plugin B',
					'callback'    => static function () use ( &$b_loaded ): void {
						$b_loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $a_loaded, 'The first plugin to claim a download id must be invoked.' );
		$this->assertFalse( $b_loaded, 'The second plugin claiming an already-taken download id must not be invoked.' );
		$this->assertCount( 1, $resolver->get_active_plugins() );

		$quarantined = $resolver->get_quarantined_download_id_plugins();
		$this->assertCount( 1, $quarantined );
		$this->assertSame( 'Download Id Plugin B', $quarantined[0]['plugin_name'] );
		$this->assertSame( 'Download Id Plugin A', $quarantined[0]['claimed_by'] );
	}

	/**
	 * #916: plugins with DIFFERENT download ids must both be invoked — the guard is keyed
	 * by download id, never by plugin_id or registration order alone.
	 */
	public function test_resolver_invokes_both_plugins_with_different_download_ids(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();
		$a_loaded = false;
		$b_loaded = false;

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
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 501,
					'plugin_name' => 'Download Id Plugin A',
					'callback'    => static function () use ( &$a_loaded ): void {
						$a_loaded = true;
					},
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 502,
					'plugin_name' => 'Download Id Plugin B',
					'callback'    => static function () use ( &$b_loaded ): void {
						$b_loaded = true;
					},
				]
			)
		);

		$resolver->load_plugins();

		$this->assertTrue( $a_loaded );
		$this->assertTrue( $b_loaded );
		$this->assertCount( 2, $resolver->get_active_plugins() );
		$this->assertEmpty( $resolver->get_quarantined_download_id_plugins() );
	}

	/**
	 * #916: the rendered admin notice must name both the refused plugin and the plugin
	 * already holding its download id.
	 */
	public function test_render_update_notices_names_both_plugins_in_the_download_id_collision(): void {
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
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 503,
					'plugin_name' => 'Download Id Plugin A',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 503,
					'plugin_name' => 'Download Id Plugin B',
				]
			)
		);

		$resolver->load_plugins();

		ob_start();
		$resolver->render_update_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Download Id Plugin B', $output, 'The notice must name the refused plugin.' );
		$this->assertStringContainsString( 'Download Id Plugin A', $output, 'The notice must name the plugin already holding the id.' );

		// is_admin() is false here, so the self-heal never ran and nothing was actually
		// deactivated — the notice must say so, not claim a deactivation that never happened (#916).
		$this->assertStringContainsString( 'не запущен', $output );
		$this->assertStringNotContainsString( 'Плагин отключён', $output );
	}

	/**
	 * #916 blocker: `load_plugins()` runs on `plugins_loaded`, which on a real wp-admin request
	 * fires BEFORE `wp-admin/includes/plugin.php` is loaded. Calling `deactivate_plugins()`
	 * synchronously here would fatal every admin page once two active plugins share a download
	 * id. The self-heal must instead be REGISTERED for `admin_init` (where WordPress has already
	 * required that file for every admin request) — never invoked directly from `load_plugins()`.
	 *
	 * Brain Monkey defines a harmless `deactivate_plugins()` stub, so a test that only asserts
	 * "`deactivate_plugins` was called once" cannot tell a safe deferred call from an unsafe
	 * synchronous one — it would pass either way. This test instead captures what `add_action()`
	 * is called with and asserts the `admin_init` registration explicitly.
	 */
	public function test_resolver_deactivates_quarantined_plugin_in_admin_context(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );

		$admin_init_callbacks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$admin_init_callbacks ): bool {
				if ( 'admin_init' === $hook ) {
					$admin_init_callbacks[] = $callback;
				}

				return true;
			}
		);

		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 504,
					'plugin_name' => 'Download Id Plugin A',
					'plugin_file' => '/plugins/download-id-a/download-id-a.php',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 504,
					'plugin_name' => 'Download Id Plugin B',
					'plugin_file' => '/plugins/download-id-b/download-id-b.php',
				]
			)
		);

		$resolver->load_plugins();

		$this->assertCount(
			1,
			$admin_init_callbacks,
			'The self-heal must be registered for admin_init, not run synchronously on plugins_loaded.'
		);
		$this->assertFalse(
			$resolver->get_quarantined_download_id_plugins()[0]['deactivated'],
			'Nothing is actually deactivated yet — only registered for admin_init.'
		);

		Functions\expect( 'deactivate_plugins' )->once()->with( 'download-id-b/download-id-b.php', false, false );

		// Simulates WordPress firing admin_init later in the same request.
		( $admin_init_callbacks[0] )();

		$this->assertTrue(
			$resolver->get_quarantined_download_id_plugins()[0]['deactivated'],
			'The quarantine entry must record that the plugin was actually deactivated.'
		);

		ob_start();
		$resolver->render_update_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Плагин отключён', $output );
		$this->assertStringNotContainsString( 'не запущен', $output );
	}

	/**
	 * #916 multisite: a network-active duplicate must never be network-deactivated from a
	 * subsite screen — only from the network admin. `is_network_admin()` and
	 * `is_plugin_active_for_network()` are themselves `wp-admin/includes/plugin.php` functions,
	 * so this check also lives inside the deferred admin_init callback, never in load_plugins().
	 */
	public function test_resolver_self_heal_never_network_deactivates_from_a_subsite_screen(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$admin_init_callbacks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$admin_init_callbacks ): bool {
				if ( 'admin_init' === $hook ) {
					$admin_init_callbacks[] = $callback;
				}

				return true;
			}
		);

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 506,
					'plugin_name' => 'Download Id Plugin A',
					'plugin_file' => '/plugins/download-id-a/download-id-a.php',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 506,
					'plugin_name' => 'Download Id Plugin B',
					'plugin_file' => '/plugins/download-id-b/download-id-b.php',
				]
			)
		);

		Functions\expect( 'deactivate_plugins' )->never();

		$resolver->load_plugins();

		( $admin_init_callbacks[0] )();

		$this->assertFalse( $resolver->get_quarantined_download_id_plugins()[0]['deactivated'] );
	}

	/**
	 * #916 multisite: the SAME network-active duplicate IS self-healed from the network admin,
	 * with network_wide passed explicitly (true) rather than left for deactivate_plugins() to infer.
	 */
	public function test_resolver_self_heal_network_deactivates_a_network_active_duplicate_from_network_admin(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( true );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$admin_init_callbacks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$admin_init_callbacks ): bool {
				if ( 'admin_init' === $hook ) {
					$admin_init_callbacks[] = $callback;
				}

				return true;
			}
		);

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 507,
					'plugin_name' => 'Download Id Plugin A',
					'plugin_file' => '/plugins/download-id-a/download-id-a.php',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 507,
					'plugin_name' => 'Download Id Plugin B',
					'plugin_file' => '/plugins/download-id-b/download-id-b.php',
				]
			)
		);

		Functions\expect( 'deactivate_plugins' )->once()->with( 'download-id-b/download-id-b.php', false, true );

		$resolver->load_plugins();

		( $admin_init_callbacks[0] )();

		$this->assertTrue( $resolver->get_quarantined_download_id_plugins()[0]['deactivated'] );
	}

	/**
	 * #916: outside an admin, non-AJAX, capable-user context, a load-time collision is only
	 * skipped — never physically deactivated (an anonymous front-end request or a cron/AJAX
	 * tick must not silently change the site's active plugins).
	 */
	public function test_resolver_does_not_deactivate_quarantined_plugin_outside_admin_context(): void {
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
		Functions\expect( 'deactivate_plugins' )->never();

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-a',
					'download_id' => 505,
					'plugin_name' => 'Download Id Plugin A',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'download-id-plugin-b',
					'download_id' => 505,
					'plugin_name' => 'Download Id Plugin B',
				]
			)
		);

		$resolver->load_plugins();

		$this->assertCount( 1, $resolver->get_quarantined_download_id_plugins() );
	}

	/**
	 * #916: `guard_activated_plugin()` (hooked to WordPress's `activated_plugin`) must
	 * deactivate a just-activated plugin whose download id is already claimed by a plugin
	 * loaded earlier in the SAME request, and must queue the flashed notice.
	 */
	public function test_guard_activated_plugin_deactivates_a_duplicate_of_an_already_loaded_plugin(): void {
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
					'plugin_id'   => 'guard-plugin-a',
					'download_id' => 700,
					'plugin_name' => 'Guard Plugin A',
					'plugin_file' => '/plugins/guard-a/guard-a.php',
				]
			)
		);
		$resolver->load_plugins();

		// Simulates activate_plugin() including guard-b's file AFTER plugins_loaded already ran.
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'guard-plugin-b',
					'download_id' => 700,
					'plugin_name' => 'Guard Plugin B',
					'plugin_file' => '/plugins/guard-b/guard-b.php',
				]
			)
		);

		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\expect( 'deactivate_plugins' )->once()->with( 'guard-b/guard-b.php' );
		Functions\expect( 'set_transient' )->once()->with(
			\Woodev\Framework\Framework_Resolver::ACTIVATION_GUARD_NOTICE_TRANSIENT . '1',
			\Mockery::type( 'string' ),
			60
		);

		$resolver->guard_activated_plugin( 'guard-b/guard-b.php' );
	}

	/**
	 * #916: activating a plugin with a UNIQUE download id must never deactivate it or queue
	 * a notice.
	 */
	public function test_guard_activated_plugin_does_nothing_for_a_unique_download_id(): void {
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
					'plugin_id'   => 'guard-plugin-a',
					'download_id' => 701,
					'plugin_name' => 'Guard Plugin A',
					'plugin_file' => '/plugins/guard-a/guard-a.php',
				]
			)
		);
		$resolver->load_plugins();

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'guard-plugin-c',
					'download_id' => 900,
					'plugin_name' => 'Guard Plugin C',
					'plugin_file' => '/plugins/guard-c/guard-c.php',
				]
			)
		);

		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'set_transient' )->never();

		$resolver->guard_activated_plugin( 'guard-c/guard-c.php' );
	}

	/**
	 * #916: bulk-in-one-request case. Two plugins that are BOTH inactive before the request
	 * and get activated together must still be caught: the first activation in the batch
	 * claims the id, the second is deactivated.
	 */
	public function test_guard_activated_plugin_quarantines_the_second_of_two_bulk_activated_duplicates(): void {
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

		// Nothing of ours is active yet when this request's plugins_loaded fired.
		$resolver->load_plugins();

		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'bulk-plugin-a',
					'download_id' => 800,
					'plugin_name' => 'Bulk Plugin A',
					'plugin_file' => '/plugins/bulk-a/bulk-a.php',
				]
			)
		);
		$resolver->register_loader_definition(
			$this->get_loader_definition(
				[
					'plugin_id'   => 'bulk-plugin-b',
					'download_id' => 800,
					'plugin_name' => 'Bulk Plugin B',
					'plugin_file' => '/plugins/bulk-b/bulk-b.php',
				]
			)
		);

		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\expect( 'deactivate_plugins' )->once()->with( 'bulk-b/bulk-b.php' );
		Functions\expect( 'set_transient' )->once();

		$resolver->guard_activated_plugin( 'bulk-a/bulk-a.php' );
		$resolver->guard_activated_plugin( 'bulk-b/bulk-b.php' );
	}

	/**
	 * #916: `render_activation_guard_notice()` reads, prints and clears the flashed
	 * transient exactly once — the same single-use pattern
	 * {@see \Woodev_Account_Connection::render_connect_notice()} uses.
	 */
	public function test_render_activation_guard_notice_prints_and_clears_the_flashed_message(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->justReturn( 'Невозможно активировать «B»: он использует тот же идентификатор лицензии, что и «A». Плагин отключён. Обратитесь к автору плагина.' );
		Functions\expect( 'delete_transient' )->once()->with( \Woodev\Framework\Framework_Resolver::ACTIVATION_GUARD_NOTICE_TRANSIENT . '7' );

		ob_start();
		$resolver->render_activation_guard_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Плагин отключён', $output );
		$this->assertStringContainsString( '<div class="error">', $output );
	}

	/**
	 * #916 follow-up: `get_loader_definition_for_class()` is the PRIMARY lookup
	 * `Woodev_Plugin::get_download_id()` reads through — recorded in `invoke_plugin()` at
	 * load time, keyed by the definition's own `main_class`, regardless of a mismatched
	 * `plugin_id`. An exact class match must resolve without walking any ancestors.
	 */
	public function test_get_loader_definition_for_class_resolves_an_exact_main_class_match(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition( [ 'main_class' => Resolver_Definition_Lookup_Base::class ] )
		);

		$resolver->load_plugins();

		$this->assertSame(
			$resolver->get_loader_definition_for_plugin_id( 'test-plugin' ),
			$resolver->get_loader_definition_for_class( Resolver_Definition_Lookup_Base::class )
		);
	}

	/**
	 * #916 follow-up: a plugin instance whose own class is a SUBCLASS of the registered
	 * `main_class` must still resolve — the lookup walks up to the nearest registered ancestor.
	 */
	public function test_get_loader_definition_for_class_walks_up_to_a_registered_ancestor(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$resolver->register_loader_definition(
			$this->get_loader_definition( [ 'main_class' => Resolver_Definition_Lookup_Base::class ] )
		);

		$resolver->load_plugins();

		$this->assertSame(
			$resolver->get_loader_definition_for_plugin_id( 'test-plugin' ),
			$resolver->get_loader_definition_for_class( Resolver_Definition_Lookup_Child::class )
		);
	}

	/**
	 * #916 follow-up: a class never invoked through this resolver has no mapping — the caller
	 * ({@see \Woodev_Plugin::get_download_id()}) is the one that falls back to plugin id, not this.
	 */
	public function test_get_loader_definition_for_class_returns_null_for_an_unregistered_class(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$this->assertNull( $resolver->get_loader_definition_for_class( 'Some_Never_Registered_Class' ) );
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
				'download_id'       => 9999,
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
