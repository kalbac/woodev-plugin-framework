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
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/blocks-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-plugin.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub {}

	class_alias( WP_REST_Controller_Test_Stub::class, 'WP_REST_Controller' );
}

if ( ! interface_exists( 'WC_Logger_Interface', false ) ) {
	/**
	 * Minimal WooCommerce logger interface stub for isolated unit tests.
	 */
	interface WC_Logger_Interface {

		/**
		 * Adds a log entry.
		 *
		 * @param string $handle Log handle.
		 * @param string $message Log message.
		 * @return void
		 */
		public function add( $handle, $message );
	}
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
	 * Whether WooCommerce system status row generation was called.
	 *
	 * @var bool
	 */
	public $system_status_information_added = false;

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
	 * Marks any accidental WooCommerce system status row generation.
	 *
	 * @param array<string,mixed> $rows System status rows.
	 * @return array<string,mixed>
	 */
	public function add_system_status_php_information( $rows ) {
		$this->system_status_information_added = true;

		return $rows;
	}

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
	 * Gets the plugin path.
	 *
	 * @return string
	 */
	public function get_plugin_path() {
		return __DIR__;
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
class Testable_Woocommerce_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

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
	 * Gets the plugin path.
	 *
	 * @return string
	 */
	public function get_plugin_path() {
		return __DIR__;
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

}

/**
 * Class WoocommercePluginTest
 */
class WoocommercePluginTest extends TestCase {

	/**
	 * Defines WordPress function stubs required by base plugin construction.
	 *
	 * @return void
	 */
	private function mock_wordpress_plugin_construction_functions(): void {
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
	 * WordPress-only plugins should not initialize WooCommerce runtime state.
	 */
	public function test_wordpress_plugin_does_not_register_woocommerce_runtime_hooks(): void {
		$this->mock_wordpress_plugin_construction_functions();

		Actions\expectAdded( 'before_woocommerce_init' )->never();
		foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
			Actions\expectAdded( 'woocommerce_before_settings_' . $tab )->never();
			Actions\expectAdded( 'woocommerce_after_settings_' . $tab )->never();
		}
		Filters\expectAdded( 'woocommerce_system_status_environment_rows' )->never();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		$this->assertFalse( $plugin->blocks_handler_initialized );
		$this->assertFalse( $plugin->system_status_information_added );
	}

	/**
	 * Pure WordPress plugin construction should not request the WooCommerce logger.
	 *
	 * @return void
	 */
	public function test_wordpress_plugin_construction_does_not_request_woocommerce_logger(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\expect( 'wc_get_logger' )->never();

		new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );
	}

	/**
	 * WooCommerce plugin logging should write to the WooCommerce logger.
	 *
	 * @return void
	 */
	public function test_woocommerce_plugin_log_uses_woocommerce_logger(): void {
		$logger = Mockery::mock( 'WC_Logger_Interface' );
		$logger->shouldReceive( 'add' )
			->once()
			->with( null, 'WooCommerce message' );

		Functions\expect( 'wc_get_logger' )
			->once()
			->andReturn( $logger );

		$plugin = new Testable_Woocommerce_Plugin();

		$plugin->log( 'WooCommerce message' );
	}

	/**
	 * Pure WordPress plugin load_template should not request WooCommerce template loading.
	 *
	 * @return void
	 */
	public function test_wordpress_plugin_load_template_does_not_request_woocommerce_template_loader(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\expect( 'wc_get_template' )->never();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		$plugin->load_template( 'admin/test.php' );
	}

	/**
	 * Pure WordPress plugin feature compatibility should not declare WooCommerce features.
	 *
	 * @return void
	 */
	public function test_wordpress_plugin_feature_compatibility_is_runtime_neutral(): void {
		$this->mock_wordpress_plugin_construction_functions();
		$this->reset_woocommerce_features_util_stub();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		$plugin->handle_features_compatibility();

		$this->assertFalse( $plugin->is_hpos_compatible() );
		$this->assertSame( [], \Automattic\WooCommerce\Utilities\FeaturesUtil::$declared );
	}

	/**
	 * WooCommerce plugin feature compatibility should declare WooCommerce features.
	 *
	 * @return void
	 */
	public function test_woocommerce_plugin_feature_compatibility_declares_woocommerce_features(): void {
		$this->mock_wordpress_plugin_construction_functions();
		$this->reset_woocommerce_features_util_stub();

		$plugin = new Testable_Woocommerce_Plugin();

		$supported_features = new \ReflectionProperty( \Woodev\Framework\Woocommerce_Plugin::class, 'supported_features' );
		$supported_features->setValue(
			$plugin,
			[
				'hpos'   => false,
				'blocks' => [
					'cart'     => true,
					'checkout' => false,
				],
			]
		);

		$blocks_handler = Mockery::mock( \Woodev_Blocks_Handler::class );
		$blocks_handler->shouldReceive( 'is_cart_block_compatible' )->once()->andReturn( true );
		$blocks_handler->shouldReceive( 'is_checkout_block_compatible' )->once()->andReturn( false );

		$blocks_handler_property = new \ReflectionProperty( \Woodev_Plugin::class, 'blocks_handler' );
		$blocks_handler_property->setValue( $plugin, $blocks_handler );

		$plugin->handle_features_compatibility();

		$this->assertSame(
			[
				[ 'custom_order_tables', $plugin->get_plugin_file(), false ],
				[ 'cart_checkout_blocks', $plugin->get_plugin_file(), false ],
			],
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$declared
		);
	}

	/**
	 * Specialized WooCommerce plugin bases should inherit WooCommerce platform behavior.
	 *
	 * @return void
	 */
	public function test_specialized_woocommerce_plugin_bases_extend_woocommerce_plugin_base(): void {
		$this->assertTrue( is_subclass_of( \Woodev_Payment_Gateway_Plugin::class, \Woodev\Framework\Woocommerce_Plugin::class ) );
		$this->assertTrue( is_subclass_of( \Woodev\Framework\Shipping\Shipping_Plugin::class, \Woodev\Framework\Woocommerce_Plugin::class ) );
	}

	/**
	 * WooCommerce plugin template loading should use the WooCommerce template loader.
	 *
	 * @return void
	 */
	public function test_woocommerce_plugin_load_template_uses_woocommerce_template_loader(): void {
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' ) . '/';
			}
		);
		Functions\expect( 'wc_get_template' )
			->once()
			->with( 'admin/test.php', [ 'id' => 123 ], 'theme/path', __DIR__ . '/templates/' );

		$plugin = new Testable_Woocommerce_Plugin();

		$plugin->load_template( 'admin/test.php', [ 'id' => 123 ], 'theme/path' );
	}

	/**
	 * WooCommerce runtime hooks should be owned by Woocommerce_Plugin, not the platform-neutral base.
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

		// register_woocommerce_hooks() is private and now runs from Woocommerce_Plugin construction.
		$register = new \ReflectionMethod( \Woodev\Framework\Woocommerce_Plugin::class, 'register_woocommerce_hooks' );
		$register->invoke( $plugin );
	}
}
