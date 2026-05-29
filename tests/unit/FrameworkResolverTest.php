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
