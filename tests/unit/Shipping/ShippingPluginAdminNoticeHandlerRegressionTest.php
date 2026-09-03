<?php
/**
 * The issue #758 regression: `Shipping_Plugin::add_delayed_admin_notices()` ->
 * `add_debug_setting_notices()` must not fatal when `is_debug_enabled()` is true (the
 * rig's own condition — `debug_mode` unset, `WP_DEBUG` true) and the subclass no-opped
 * `init_admin_notice_handler()` — exactly the stack the card's fatal reports:
 *
 *   Call to a member function add_admin_notice() on null
 *     in woodev/shipping-method/class-shipping-plugin.php:606
 *   #0 class-shipping-plugin.php(498): Shipping_Plugin->add_debug_setting_notices()
 *   #1 class-wp-hook.php(341): Shipping_Plugin->add_delayed_admin_notices('')
 *   #4 wp-admin/admin-footer.php(78): do_action('admin_footer')
 *
 * Deliberately built against a PURPOSE-BUILT double, not `woodev-realistic-shipping-plugin`
 * (the rig fixture, fixed separately in this same change): the fixture fix alone would make
 * this pass for the wrong reason (no fixture involved here at all), leaving the framework
 * half — {@see \Woodev_Plugin::enforce_admin_notice_handler_contract()} — untested. The
 * double's `__construct()` calls `\Woodev_Plugin::__construct()` directly, skipping
 * `Shipping_Plugin::__construct()`/`Woocommerce_Plugin::__construct()` entirely: the defect
 * and the fix both live in `Woodev_Plugin::__construct()`, and the two subclass
 * constructors' own `includes()`/`add_hooks()` machinery is irrelevant to it.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		/**
		 * Minimal WordPress REST controller stub for isolated unit construction —
		 * same shape as WoocommercePluginTest.php's own stub: Woodev_Plugin::includes()
		 * requires class-plugin-rest-api-settings.php, which extends WP_REST_Controller.
		 */
		class WP_REST_Controller_Test_Stub {

			/** @var string */
			protected $namespace;

			/** @var string */
			protected $rest_base;
		}

		class_alias( WP_REST_Controller_Test_Stub::class, 'WP_REST_Controller' );
	}
}

namespace Woodev\Tests\Unit\Shipping {

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

if ( ! class_exists( __NAMESPACE__ . '\\Regression_Shipping_Plugin_Fixture', false ) ) {

	/**
	 * Reproduces the exact rig shape (issue #758): a `Shipping_Plugin` subclass whose
	 * `init_admin_notice_handler()` is a no-op — precisely what
	 * `woodev-realistic-shipping-plugin` did from 31.05.2026 until this issue — built
	 * through the REAL `Woodev_Plugin::__construct()` chain so
	 * `enforce_admin_notice_handler_contract()` actually runs and this test proves
	 * something. Every other subsystem stays a no-op; none of it participates in the
	 * `add_delayed_admin_notices()` path this file exercises.
	 */
	class Regression_Shipping_Plugin_Fixture extends Shipping_Plugin {

		public function __construct( string $id, string $version ) {
			\Woodev_Plugin::__construct( $id, $version );
		}

		/** The exact defect shape under test (issue #758). */
		protected function init_admin_notice_handler() {}

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_license_handler() {}

		protected function init_hook_deprecator() {}

		protected function init_lifecycle_handler() {}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		/**
		 * Overridden (rather than left at the base implementation) to keep this
		 * fixture out of `Shipping_Method`/`WC_Shipping_Method` entirely: the base
		 * `get_shipping_methods()` asserts `$this->methods` is non-empty, which this
		 * bare double — no shipping methods ever registered — would trip, routing
		 * into `Woocommerce_Plugin::log()` and `wc_get_logger()` for no reason this
		 * regression cares about.
		 *
		 * @return array<int, \Woodev\Framework\Shipping\Shipping_Method>
		 */
		public function get_shipping_methods(): array {
			return [];
		}

		protected function get_shipping_method_classes(): array {
			return [];
		}

		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Regression Shipping Plugin';
		}

		public function get_download_id() {
			return 0;
		}
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_delayed_admin_notices
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_debug_setting_notices
 * @covers \Woodev_Plugin::enforce_admin_notice_handler_contract
 */
class ShippingPluginAdminNoticeHandlerRegressionTest extends TestCase {

	/**
	 * Stubs the WordPress functions `Woodev_Plugin::__construct()`'s real init_*()
	 * chain and `add_delayed_admin_notices()`'s own call tree need — the same set
	 * `AdminNoticeHandlerContractTest`'s `mock_construction_functions()` uses (itself
	 * copied from `WoocommercePluginTest.php`), plus the handful
	 * `Shipping_Plugin::get_settings_url()`/`Woodev_Admin_Notice_Handler::should_display_notice()`
	 * additionally call along this specific path.
	 *
	 * @return void
	 */
	private function mock_construction_functions(): void {
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
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( [] );
		// get_plugin_url() -> plugins_url(): needed since issue #759 made
		// init_license_handler() build a real Woodev_Plugins_License unconditionally
		// (this fixture also no-ops init_license_handler(), so construction now reaches it
		// via enforce_license_handler_contract() the same way it reaches the notice handler).
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/test-plugin' );
		// This fixture no-ops init_admin_notice_handler(), so construction itself trips
		// enforce_admin_notice_handler_contract()'s own _doing_it_wrong() — irrelevant to
		// what this file asserts (that is AdminNoticeHandlerContractTest's job), so it is
		// merely stubbed silent here rather than asserted on.
		Functions\when( '_doing_it_wrong' )->justReturn( null );
	}

	/**
	 * Reads the handler's private `$admin_notices` map — mirrors
	 * `Testable_Platform_Neutral_Admin_Notice_Handler::seed_admin_notices()`'s own
	 * reflection idiom in `PlatformNeutralAdminNoticeTest.php`; there is no public
	 * accessor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function admin_notices_of( \Woodev_Admin_Notice_Handler $handler ): array {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'admin_notices' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return $property->getValue( $handler );
	}

	/**
	 * The exact regression (issue #758). WP_DEBUG cannot be un-defined once set, so
	 * this runs isolated — same discipline as
	 * `PickupHandlerLocationWiringTest.php`'s own WP_DEBUG-dependent tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_add_delayed_admin_notices_does_not_fatal_when_debug_is_on_and_the_handler_was_noopped(): void {
		$this->mock_construction_functions();
		define( 'WP_DEBUG', true );

		$plugin = new Regression_Shipping_Plugin_Fixture( 'notice-regression', '1.0.0' );

		$this->assertTrue(
			$plugin->is_debug_enabled(),
			'precondition: the rig-observed WP_DEBUG fallback (no debug_mode integration option) must be in effect'
		);

		// Pre-fix, this fatals: "Call to a member function add_admin_notice() on null".
		$plugin->add_delayed_admin_notices();

		$handler = $plugin->get_admin_notice_handler();
		$this->assertInstanceOf( \Woodev_Admin_Notice_Handler::class, $handler );

		$notices = $this->admin_notices_of( $handler );
		$this->assertArrayHasKey(
			'debug-in-production',
			$notices,
			'the debug-mode notice must actually register, not merely avoid fataling'
		);
	}
}

}
