<?php
/**
 * WooCommerce plugin base tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin-alias.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub {}

	class_alias( WP_REST_Controller_Test_Stub::class, 'WP_REST_Controller' );
}

/**
 * Test helper exposing base WordPress plugin construction state.
 */
class Testable_Wordpress_Plugin extends \Woodev_Plugin {

	/**
	 * Whether the WooCommerce Blocks handler initialization path was called.
	 *
	 * @var bool
	 */
	public $blocks_handler_initialized = false;

	/**
	 * No-op dependency initialization for constructor isolation.
	 *
	 * @param array<string,mixed> $dependencies Dependencies configuration.
	 * @return void
	 */
	protected function init_dependencies( $dependencies ) {}

	/**
	 * No-op admin message handler initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_admin_message_handler() {}

	/**
	 * No-op admin notice handler initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_admin_notice_handler() {}

	/**
	 * No-op license handler initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_license_handler() {}

	/**
	 * No-op hook deprecator initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_hook_deprecator() {}

	/**
	 * No-op lifecycle handler initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_lifecycle_handler() {}

	/**
	 * No-op REST API handler initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_rest_api_handler() {}

	/**
	 * Marks any accidental WooCommerce Blocks initialization.
	 *
	 * @return void
	 */
	protected function init_blocks_handler(): void {
		$this->blocks_handler_initialized = true;
	}

	/**
	 * No-op setup wizard initialization for constructor isolation.
	 *
	 * @return void
	 */
	protected function init_setup_wizard_handler() {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Test WordPress Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}
}

/**
 * Test helper exposing protected hook registration.
 */
class Testable_Woocommerce_Plugin extends \Woodev_Woocommerce_Plugin {

	/**
	 * Avoid parent construction in the isolated hook registration test.
	 */
	public function __construct() {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Test WooCommerce Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function handle_features_compatibility(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function add_class_form_wrap_start(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function add_class_form_wrap_end(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @param array<string,mixed> $rows System status rows.
	 * @return array<string,mixed>
	 */
	public function add_system_status_php_information( $rows ) {
		return $rows;
	}

	/**
	 * Exposes WooCommerce hook registration for assertions.
	 *
	 * @return void
	 */
	public function register_woocommerce_hooks(): void {
		$this->add_woocommerce_hooks();
	}
}

/**
 * Class WoocommercePluginTest
 */
class WoocommercePluginTest extends TestCase {

	/**
	 * WordPress-only plugins should not initialize WooCommerce runtime state.
	 */
	public function test_wordpress_plugin_does_not_register_woocommerce_runtime_hooks(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_replace_recursive( $defaults, $args );
			}
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return trailingslashit( dirname( $file ) );
			}
		);
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );

		Actions\expectAdded( 'before_woocommerce_init' )->never();
		foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
			Actions\expectAdded( 'woocommerce_before_settings_' . $tab )->never();
			Actions\expectAdded( 'woocommerce_after_settings_' . $tab )->never();
		}
		Filters\expectAdded( 'woocommerce_system_status_environment_rows' )->never();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		$this->assertFalse( $plugin->blocks_handler_initialized );
	}

	/**
	 * WooCommerce runtime hooks should be owned by the WooCommerce plugin base.
	 */
	public function test_registers_woocommerce_runtime_hooks(): void {
		$plugin = new Testable_Woocommerce_Plugin();

		Actions\expectAdded( 'before_woocommerce_init' )
			->once()
			->with( [ $plugin, 'handle_features_compatibility' ] );

		foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
			Actions\expectAdded( 'woocommerce_before_settings_' . $tab )
				->once()
				->with( [ $plugin, 'add_class_form_wrap_start' ] );

			Actions\expectAdded( 'woocommerce_after_settings_' . $tab )
				->once()
				->with( [ $plugin, 'add_class_form_wrap_end' ] );
		}

		Filters\expectAdded( 'woocommerce_system_status_environment_rows' )
			->once()
			->with( [ $plugin, 'add_system_status_php_information' ] );

		$plugin->register_woocommerce_hooks();
	}
}
