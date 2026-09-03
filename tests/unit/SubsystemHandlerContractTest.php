<?php
/**
 * Woodev_Plugin::__construct() license- and lifecycle-handler contract tests (issue #759).
 *
 * Same defect shape as #758's admin-notice-handler contract, found twice more by the
 * coordinator's grep while #758 was in flight: `get_license_instance()` is dereferenced 13
 * times and `get_lifecycle_handler()` 2 times across `woodev/`, both with no null check, and
 * `woodev-realistic-shipping-plugin` no-opped both `init_license_handler()` and
 * `init_lifecycle_handler()`. {@see \Woodev_Plugin::enforce_license_handler_contract()} and
 * {@see \Woodev_Plugin::enforce_lifecycle_handler_contract()} close them the same way #758
 * closed the notice handler: report via `_doing_it_wrong()` under `WP_DEBUG` and build the
 * default anyway.
 *
 * A new file rather than an extension of `AdminNoticeHandlerContractTest.php`: that file's
 * docblock, `@covers` annotation and base fixture are scoped to the notice-handler contract,
 * and its base fixture no-opped BOTH `init_license_handler()` and `init_lifecycle_handler()`
 * purely to isolate notice-handler construction — mixing three subsystems' worth of "which
 * one is no-opped on purpose vs. as scaffolding" into one file would make each test harder to
 * read, not easier. That file's base fixture was updated separately (this issue) to stop
 * no-opping license/lifecycle into null, since a null there now trips the two contracts added
 * here and would make its own `_doing_it_wrong()` assertions non-deterministic.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		/**
		 * Minimal WordPress REST controller stub for isolated unit construction — same
		 * shape as WoocommercePluginTest.php's and AdminNoticeHandlerContractTest.php's own
		 * stub: Woodev_Plugin::includes() requires class-plugin-rest-api-settings.php, which
		 * extends WP_REST_Controller. Guarded so only one of these test files declares it.
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

if ( ! class_exists( 'Woodev_Subsystem_Contract_Test_Plugin_Base', false ) ) {

	/**
	 * Minimal Woodev_Plugin subclass isolating construction from every init_*() subsystem
	 * that carries no construction contract of its own (dependencies, admin message handler,
	 * hook deprecator, REST API handler, blocks handler) — same no-op set
	 * AdminNoticeHandlerContractTest.php's own base uses for the same reason.
	 *
	 * Unlike that file's base, `init_admin_notice_handler()`, `init_license_handler()` and
	 * `init_lifecycle_handler()` are deliberately left at their real Woodev_Plugin defaults
	 * here: this file's concrete subclasses override exactly ONE of {license, lifecycle} per
	 * test, and leaving the other two at their real (trivial) defaults means their own
	 * contract checks pass silently instead of adding unrelated `_doing_it_wrong()` calls that
	 * every test in this file would otherwise have to account for.
	 */
	abstract class Woodev_Subsystem_Contract_Test_Plugin_Base extends \Woodev_Plugin {

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_hook_deprecator() {}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Subsystem Contract Test Plugin';
		}

		public function get_download_id() {
			return 0;
		}
	}

	/**
	 * A minimal stand-in for a plugin-supplied license handler — used only to prove
	 * {@see \Woodev_Plugin::enforce_license_handler_contract()}'s null-check preserves a
	 * subclass's own instance rather than replacing it. Deliberately NOT a
	 * Woodev_Plugins_License: that class's constructor has its own heavy side effects
	 * (REST controller boot, license-store reads) irrelevant to the guard being tested here.
	 */
	class Woodev_Subsystem_Contract_Test_Custom_License {}

	/**
	 * The defect shape from issue #759: init_license_handler() is a no-op, exactly as
	 * woodev-realistic-shipping-plugin's was.
	 */
	class Woodev_License_Contract_Test_Plugin_Noop extends Woodev_Subsystem_Contract_Test_Plugin_Base {
		protected function init_license_handler() {}
	}

	/**
	 * A well-behaved plugin: init_license_handler() left at its Woodev_Plugin default, which
	 * builds a real Woodev_Plugins_License.
	 */
	class Woodev_License_Contract_Test_Plugin_Normal extends Woodev_Subsystem_Contract_Test_Plugin_Base {}

	/**
	 * A plugin that built its OWN license handler — init_license_handler()'s existing
	 * `if ( ! $this->license )` guard, and enforce_license_handler_contract()'s matching null
	 * check, must both leave this instance alone.
	 */
	class Woodev_License_Contract_Test_Plugin_Custom extends Woodev_Subsystem_Contract_Test_Plugin_Base {
		protected function init_license_handler() {
			$this->license = new Woodev_Subsystem_Contract_Test_Custom_License();
		}
	}

	/**
	 * The defect shape from issue #759: init_lifecycle_handler() is a no-op, exactly as
	 * woodev-realistic-shipping-plugin's was.
	 */
	class Woodev_Lifecycle_Contract_Test_Plugin_Noop extends Woodev_Subsystem_Contract_Test_Plugin_Base {
		protected function init_lifecycle_handler() {}
	}

	/**
	 * A well-behaved plugin: init_lifecycle_handler() left at its Woodev_Plugin default,
	 * which builds a real Woodev_Lifecycle.
	 */
	class Woodev_Lifecycle_Contract_Test_Plugin_Normal extends Woodev_Subsystem_Contract_Test_Plugin_Base {}

	/**
	 * A plugin that built its OWN lifecycle handler — enforce_lifecycle_handler_contract()'s
	 * null check must leave this instance alone, the same way it does for license.
	 */
	class Woodev_Lifecycle_Contract_Test_Plugin_Custom extends Woodev_Subsystem_Contract_Test_Plugin_Base {
		protected function init_lifecycle_handler() {
			$this->lifecycle_handler = new Woodev_Subsystem_Contract_Test_Custom_License();
		}
	}
}

/**
 * Stubs the WordPress functions Woodev_Plugin::__construct()'s real init_*() chain calls
 * when nothing overrides it — same set AdminNoticeHandlerContractTest.php's own
 * mock_wordpress_plugin_construction_functions() uses, already proven sufficient for the same
 * constructor. `add_action`/`apply_filters`/`do_action` need no stub: Brain\Monkey emulates a
 * real (if minimal) hook system for those once Monkey\setUp() runs.
 *
 * @return void
 */
function mock_subsystem_contract_construction_functions(): void {
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
	// get_plugin_url() -> plugins_url(): only the "normal" (real default) plugin
	// variants below actually reach Woodev_Plugins_License::__construct(), but stubbing
	// it unconditionally here keeps this helper identical in shape to the others.
	Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/test-plugin' );
}

/**
 * @covers \Woodev_Plugin::enforce_license_handler_contract
 */
class LicenseHandlerContractTest extends TestCase {

	/**
	 * A no-opped init_license_handler() must not leave construction with a null license, and
	 * the violation must be reported under WP_DEBUG.
	 *
	 * WP_DEBUG cannot be un-defined once set, so this runs isolated (same discipline as
	 * AdminNoticeHandlerContractTest.php's own WP_DEBUG-dependent test).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noop_subclass_still_ends_construction_with_a_working_handler_and_is_reported(): void {
		mock_subsystem_contract_construction_functions();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->once()->with(
			'Woodev_Plugin::init_license_handler',
			Mockery::type( 'string' ),
			'2.0.2'
		);

		$plugin = new Woodev_License_Contract_Test_Plugin_Noop( 'license-contract-noop', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Plugins_License::class,
			$plugin->get_license_instance(),
			'a no-opped init_license_handler() must still end construction with a working license handler'
		);
	}

	/**
	 * A normal plugin — init_license_handler() left at its default — must get a working
	 * handler and never trip the contract check.
	 */
	public function test_normal_plugin_constructs_with_a_handler_and_no_report(): void {
		mock_subsystem_contract_construction_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Woodev_License_Contract_Test_Plugin_Normal( 'license-contract-normal', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Plugins_License::class,
			$plugin->get_license_instance()
		);
	}

	/**
	 * A plugin that built its OWN license handler must keep that exact instance: neither
	 * init_license_handler()'s own `if ( ! $this->license )` guard nor
	 * enforce_license_handler_contract()'s matching null check may replace it. This is the
	 * case a careless "always rebuild the default" implementation breaks.
	 */
	public function test_custom_license_handler_is_not_replaced_and_no_report(): void {
		mock_subsystem_contract_construction_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Woodev_License_Contract_Test_Plugin_Custom( 'license-contract-custom', '1.0.0' );

		$this->assertInstanceOf(
			Woodev_Subsystem_Contract_Test_Custom_License::class,
			$plugin->get_license_instance(),
			'a plugin-supplied license handler must survive enforce_license_handler_contract() unchanged'
		);
	}
}

/**
 * @covers \Woodev_Plugin::enforce_lifecycle_handler_contract
 */
class LifecycleHandlerContractTest extends TestCase {

	/**
	 * A no-opped init_lifecycle_handler() must not leave construction with a null lifecycle
	 * handler, and the violation must be reported under WP_DEBUG.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noop_subclass_still_ends_construction_with_a_working_handler_and_is_reported(): void {
		mock_subsystem_contract_construction_functions();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->once()->with(
			'Woodev_Plugin::init_lifecycle_handler',
			Mockery::type( 'string' ),
			'2.0.2'
		);

		$plugin = new Woodev_Lifecycle_Contract_Test_Plugin_Noop( 'lifecycle-contract-noop', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Lifecycle::class,
			$plugin->get_lifecycle_handler(),
			'a no-opped init_lifecycle_handler() must still end construction with a working lifecycle handler'
		);
	}

	/**
	 * A normal plugin — init_lifecycle_handler() left at its default — must get a working
	 * handler and never trip the contract check.
	 */
	public function test_normal_plugin_constructs_with_a_handler_and_no_report(): void {
		mock_subsystem_contract_construction_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Woodev_Lifecycle_Contract_Test_Plugin_Normal( 'lifecycle-contract-normal', '1.0.0' );

		$this->assertInstanceOf(
			\Woodev_Lifecycle::class,
			$plugin->get_lifecycle_handler()
		);
	}

	/**
	 * A plugin that built its OWN lifecycle handler must keep that exact instance —
	 * enforce_lifecycle_handler_contract()'s null check must not replace it.
	 */
	public function test_custom_lifecycle_handler_is_not_replaced_and_no_report(): void {
		mock_subsystem_contract_construction_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Woodev_Lifecycle_Contract_Test_Plugin_Custom( 'lifecycle-contract-custom', '1.0.0' );

		$this->assertInstanceOf(
			Woodev_Subsystem_Contract_Test_Custom_License::class,
			$plugin->get_lifecycle_handler(),
			'a plugin-supplied lifecycle handler must survive enforce_lifecycle_handler_contract() unchanged'
		);
	}
}

}
