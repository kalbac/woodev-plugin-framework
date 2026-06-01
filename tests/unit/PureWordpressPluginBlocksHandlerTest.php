<?php
/**
 * Failing test for V-5: get_blocks_handler() typed-property trap.
 *
 * Reproduces the bug at woodev/class-plugin.php:71,1018 — a pure-WP plugin
 * subclass that extends Woodev_Plugin and calls get_blocks_handler() triggers
 * TypeError because the property is non-nullable typed but only initialized
 * in Woodev_Woocommerce_Plugin::init_blocks_handler().
 *
 * Test currently FAILS with TypeError. After B-3 fix (nullable property +
 * nullable return type), it PASSES.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

	if ( ! class_exists( 'Woodev_Blocks_Handler', false ) ) {
		class Woodev_Blocks_Handler {
			public function __construct( $plugin = null ) {}
		}
	}

	if ( ! class_exists( 'Woodev_Test_Pure_WP_Plugin', false ) ) {
		abstract class Woodev_Test_Pure_WP_Plugin extends \Woodev_Plugin {
			public function get_file() { return __FILE__; }
			public function get_plugin_name() { return 'pure-wp-test'; }
			public function get_download_id() { return 0; }
			protected function init_dependencies( $dependencies ): void {}
			protected function init_admin_message_handler(): void {}
			protected function init_admin_notice_handler(): void {}
			protected function init_license(): void {}
			protected function init_updater(): void {}
			protected function init_hook_deprecator(): void {}
			protected function init_lifecycle_handler(): void {}
			protected function init_rest_api_handler(): void {}
			protected function init_setup_wizard(): void {}
			protected function init_script_handler(): void {}
			public function init_admin() {}
			protected function init_plugin_compatibility(): void {}
			protected function init_order_compatibility(): void {}
			protected function add_woocommerce_hooks(): void {}
			protected function add_hooks(): void {}
			public function expose_get_blocks_handler() {
				return $this->get_blocks_handler();
			}
		}
	}

	if ( ! class_exists( 'Woodev_Test_Pure_WP_Concrete', false ) ) {
		class Woodev_Test_Pure_WP_Concrete extends Woodev_Test_Pure_WP_Plugin {}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	class PureWordpressPluginBlocksHandlerTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
				if ( is_object( $args ) ) {
					$args = (array) $args;
				}
				return array_merge( $defaults, (array) $args );
			} );
		}

		public function test_get_blocks_handler_does_not_typeerror_for_pure_wp_subclass() {
			$reflection = new \ReflectionClass( \Woodev_Test_Pure_WP_Concrete::class );
			$plugin     = $reflection->newInstanceWithoutConstructor();

			$result = $plugin->expose_get_blocks_handler();

			$this->assertNull( $result, 'Pure WP plugins should get null until they opt in to a blocks handler' );
		}
	}

}
