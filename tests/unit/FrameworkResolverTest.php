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
	 * Legacy register_plugin() flags should map only to early capabilities.
	 */
	public function test_legacy_adapter_maps_specialized_flags_to_capabilities(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$resolver->register_legacy_plugin(
			'2.0.0',
			'Legacy Gateway',
			__FILE__,
			static function (): void {},
			[
				'is_payment_gateway'  => true,
				'load_shipping_method' => true,
				'minimum_wp_version'  => '6.3',
				'minimum_wc_version'  => '7.0',
			]
		);

		$registered   = $resolver->get_registered_plugins();
		$definition   = $registered[0]['definition'];
		$capabilities = $definition->get_capabilities();

		$this->assertSame( \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE, $definition->get_platform() );
		$this->assertContains( \Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY, $capabilities );
		$this->assertContains( \Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_SHIPPING_METHOD, $capabilities );
		$this->assertArrayHasKey( 'is_payment_gateway', $registered[0]['args'] );
		$this->assertArrayHasKey( 'load_shipping_method', $registered[0]['args'] );
	}

	/**
	 * Legacy WooCommerce capability plugins without an explicit WC minimum should keep notice data safe.
	 */
	public function test_legacy_woocommerce_capability_without_minimum_wc_version_keeps_notice_data(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$resolver->register_legacy_plugin(
			'2.0.0',
			'Legacy Gateway',
			__FILE__,
			static function (): void {},
			[
				'is_payment_gateway' => true,
			]
		);

		$registered = $resolver->get_registered_plugins();

		$this->assertSame( '0', $registered[0]['args']['minimum_wc_version'] );
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
	 * H4: Legacy register_plugin() path must also dedupe by plugin_id.
	 */
	public function test_resolver_dedupes_legacy_plugin_registrations_by_plugin_id(): void {
		$resolver = new \Woodev\Framework\Framework_Resolver();

		$resolver->register_legacy_plugin(
			'2.0.0',
			'First Plugin',
			'first-plugin.php',
			static function (): void {},
			[ 'plugin_id' => 'shared-id' ]
		);
		$second = $resolver->register_legacy_plugin(
			'2.0.0',
			'Second Plugin',
			'second-plugin.php',
			static function (): void {},
			[ 'plugin_id' => 'shared-id' ]
		);

		$this->assertFalse( $second );
		$this->assertCount( 1, $resolver->get_registered_plugins() );
		$this->assertCount( 1, $resolver->get_invalid_loader_definitions() );
		$this->assertContains(
			'Duplicate plugin_id: shared-id.',
			$resolver->get_invalid_loader_definitions()[0]['errors']
		);
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
