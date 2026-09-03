<?php
/**
 * Woodev_Plugin::__construct() admin-notice-handler contract tests (issue #758).
 *
 * `get_admin_notice_handler()` is dereferenced 17 times across `woodev/` with no null
 * check, so a subclass whose `init_admin_notice_handler()` is a no-op used to leave
 * `$admin_notice_handler` null — a state the constructor itself never intended to allow,
 * since it calls `init_admin_notice_handler()` unconditionally. That is exactly what
 * `woodev-realistic-shipping-plugin` did from 31.05.2026 until this issue, and once it
 * became a live rig plugin (#734/#735) it fataled every admin page on the site.
 *
 * {@see \Woodev_Plugin::enforce_admin_notice_handler_contract()} closes it: report the
 * violation via `_doing_it_wrong()` under `WP_DEBUG` and build the default handler
 * anyway. These tests cover the two constructor outcomes directly; the exact regression
 * stack (`add_delayed_admin_notices()` -> `add_debug_setting_notices()`) is covered
 * separately in `Shipping\ShippingPluginAdminNoticeHandlerRegressionTest`, against a
 * purpose-built double rather than the rig fixture, per the card's own instruction.
 *
 * @package Woodev\Tests\Unit
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

namespace Woodev\Tests\Unit {

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

if ( ! class_exists( 'Woodev_Notice_Contract_Test_Plugin_Base', false ) ) {

	/**
	 * Minimal Woodev_Plugin subclass isolating construction from every init_*()
	 * subsystem EXCEPT init_admin_notice_handler(), which each concrete subclass below
	 * decides for itself — the exact seam these tests exercise. No-op set mirrors
	 * WoocommercePluginTest.php's own Testable_Wordpress_Plugin, already proven to let
	 * the real Woodev_Plugin::__construct() run to completion in this suite.
	 *
	 * init_license_handler() and init_lifecycle_handler() stop short of a true no-op: since
	 * issue #759 gave both subsystems the same construction contract this file's own subject
	 * enforces, leaving either null here would trip THAT contract's `_doing_it_wrong()` call
	 * on every test in this file, making the exact-call-count assertions below
	 * non-deterministic. A direct property assignment satisfies each contract's null check
	 * without pulling in either subsystem's real (heavier) default construction — irrelevant
	 * to what this file tests. See `SubsystemHandlerContractTest.php` for the license/lifecycle
	 * contracts themselves.
	 */
	abstract class Woodev_Notice_Contract_Test_Plugin_Base extends \Woodev_Plugin {

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_license_handler() {
			$this->license = new \stdClass();
		}

		protected function init_hook_deprecator() {}

		protected function init_lifecycle_handler() {
			$this->lifecycle_handler = new \stdClass();
		}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Notice Contract Test Plugin';
		}

		public function get_download_id() {
			return 0;
		}
	}

	/**
	 * The defect shape from issue #758: init_admin_notice_handler() is a no-op, exactly
	 * as woodev-realistic-shipping-plugin's was.
	 */
	class Woodev_Notice_Contract_Test_Plugin_Noop extends Woodev_Notice_Contract_Test_Plugin_Base {
		protected function init_admin_notice_handler() {}
	}

	/**
	 * A well-behaved plugin: init_admin_notice_handler() left at its Woodev_Plugin
	 * default, which builds a real Woodev_Admin_Notice_Handler.
	 */
	class Woodev_Notice_Contract_Test_Plugin_Normal extends Woodev_Notice_Contract_Test_Plugin_Base {}
}

/**
 * @covers \Woodev_Plugin::enforce_admin_notice_handler_contract
 */
class AdminNoticeHandlerContractTest extends TestCase {

	/**
	 * Stubs the WordPress functions Woodev_Plugin::__construct()'s real init_*() chain
	 * calls when nothing overrides it — copied from WoocommercePluginTest.php's own
	 * mock_wordpress_plugin_construction_functions(), which already proves this set
	 * sufficient for the same constructor.
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
	}

	/**
	 * A no-opped init_admin_notice_handler() must not leave construction with a null
	 * handler, and the violation must be reported under WP_DEBUG.
	 *
	 * WP_DEBUG cannot be un-defined once set, so this runs isolated (same discipline
	 * as PickupHandlerLocationWiringTest.php's own WP_DEBUG-dependent tests).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noop_subclass_still_ends_construction_with_a_working_handler_and_is_reported(): void {
		$this->mock_construction_functions();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->once()->with(
			'Woodev_Plugin::init_admin_notice_handler',
			Mockery::type( 'string' ),
			'2.0.2'
		);

		$plugin = new Woodev_Notice_Contract_Test_Plugin_Noop( 'notice-contract-noop', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Admin_Notice_Handler::class,
			$plugin->get_admin_notice_handler(),
			'a no-opped init_admin_notice_handler() must still end construction with a working handler'
		);
	}

	/**
	 * A normal plugin — init_admin_notice_handler() left at its default — must get a
	 * working handler and never trip the contract check.
	 */
	public function test_normal_plugin_constructs_with_a_handler_and_no_report(): void {
		$this->mock_construction_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Woodev_Notice_Contract_Test_Plugin_Normal( 'notice-contract-normal', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Admin_Notice_Handler::class,
			$plugin->get_admin_notice_handler()
		);
	}
}

}
