<?php
/**
 * Woodev_Plugin::get_download_id() resolution tests (#916 follow-up).
 *
 * `get_download_id()` is the single source of truth licensing, the updater and the license
 * authority all read (class-plugin.php:1304+). It used to resolve the loader definition by
 * `get_id()` alone, which silently returned 0 the moment a plugin's own `PLUGIN_ID` did not
 * match the `plugin_id` it registered in its loader definition — a real author error this
 * repo's own `tests/_fixtures/woodev-test-payment-gateway` fixture demonstrates: its definition
 * declares `plugin_id => 'woodev-test-payment-gateway'` while the constructed plugin's
 * `PLUGIN_ID` is `'woodev-test-payment-gateway-plugin'`. The mismatch test plugin below
 * reproduces that exact shape.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		/**
		 * Minimal WordPress REST controller stub for isolated unit construction —
		 * Woodev_Plugin::includes() unconditionally requires class-plugin-rest-api-settings.php,
		 * which extends WP_REST_Controller (same stub shape as the other constructor-contract
		 * test files in this suite).
		 */
		class WP_REST_Controller_Download_Id_Test_Stub {

			/** @var string */
			protected $namespace;

			/** @var string */
			protected $rest_base;
		}

		class_alias( WP_REST_Controller_Download_Id_Test_Stub::class, 'WP_REST_Controller' );
	}
}

namespace Woodev\Tests\Unit {

use Brain\Monkey\Functions;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/bootstrap.php';

if ( ! class_exists( 'Woodev_Download_Id_Mismatch_Test_Plugin', false ) ) {

	/**
	 * Minimal Woodev_Plugin subclass whose own PLUGIN_ID deliberately does NOT match the
	 * `plugin_id` its loader definition below declares — the exact real-world shape of
	 * `tests/_fixtures/woodev-test-payment-gateway`.
	 */
	class Woodev_Download_Id_Mismatch_Test_Plugin extends \Woodev_Plugin {

		const PLUGIN_ID = 'download-id-mismatch-test-plugin-real-id';
		const VERSION   = '1.0.0';

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_hook_deprecator() {}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		public function __construct() {
			parent::__construct( self::PLUGIN_ID, self::VERSION );
		}

		public static function instance(): self {
			return self::$instance ??= new self();
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Download Id Mismatch Test Plugin';
		}
	}
}

if ( ! class_exists( 'Woodev_Download_Id_Match_Test_Plugin', false ) ) {

	/**
	 * Control: same shape as {@see Woodev_Download_Id_Mismatch_Test_Plugin}, but its PLUGIN_ID
	 * matches its loader definition's plugin_id — proves the class-based resolution does not
	 * regress the common (no mismatch) case.
	 */
	class Woodev_Download_Id_Match_Test_Plugin extends \Woodev_Plugin {

		const PLUGIN_ID = 'download-id-match-test-plugin';
		const VERSION   = '1.0.0';

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_hook_deprecator() {}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		public function __construct() {
			parent::__construct( self::PLUGIN_ID, self::VERSION );
		}

		public static function instance(): self {
			return self::$instance ??= new self();
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Download Id Match Test Plugin';
		}
	}
}

if ( ! class_exists( 'Woodev_Download_Id_Never_Invoked_Test_Plugin', false ) ) {

	/**
	 * Never invoked through the resolver (no load_plugins() call reaches it) — its class has
	 * no entry in definitions_by_main_class, so get_download_id() must fall back to a
	 * plugin_id lookup. Its PLUGIN_ID matches the plugin_id it registers (the fallback keys by
	 * plugin_id, so it must match for this to resolve at all).
	 */
	class Woodev_Download_Id_Never_Invoked_Test_Plugin extends \Woodev_Plugin {

		const PLUGIN_ID = 'download-id-fallback-test-plugin';
		const VERSION   = '1.0.0';

		protected function init_dependencies( $dependencies ) {}

		protected function init_admin_message_handler() {}

		protected function init_hook_deprecator() {}

		protected function init_rest_api_handler() {}

		protected function init_blocks_handler(): void {}

		public function __construct() {
			parent::__construct( self::PLUGIN_ID, self::VERSION );
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Download Id Fallback Test Plugin';
		}
	}
}

/**
 * @covers \Woodev_Plugin::get_download_id
 */
class PluginDownloadIdResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_bootstrap_singleton();
	}

	protected function tearDown(): void {
		$this->reset_bootstrap_singleton();
		parent::tearDown();
	}

	/**
	 * Resets the real Woodev_Plugin_Bootstrap singleton via reflection.
	 *
	 * @return void
	 */
	private function reset_bootstrap_singleton(): void {
		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}
		$instance->setValue( null, null );
	}

	/**
	 * Stubs the WordPress functions Woodev_Plugin::__construct()'s real init_*() chain and
	 * Framework_Resolver::load_plugins() call when nothing overrides them — same set the
	 * other constructor-contract test files in this suite use.
	 *
	 * @return void
	 */
	private function mock_construction_and_resolver_functions(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_replace_recursive( $defaults, $args );
			}
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return rtrim( dirname( $file ), '/\\' ) . '/';
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
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/test-plugin' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
	}

	/**
	 * Returns a loader definition for the given main_class/plugin_id pair.
	 *
	 * @param string $plugin_id  Loader definition plugin_id.
	 * @param string $main_class Loader definition main_class.
	 * @param int    $download_id Download id.
	 * @return array<string,mixed>
	 */
	private function get_loader_definition( string $plugin_id, string $main_class, int $download_id ): array {
		return [
			'plugin_id'         => $plugin_id,
			'download_id'       => $download_id,
			'plugin_name'       => $plugin_id,
			'plugin_version'    => '1.0.0',
			'framework_version' => '2.0.0',
			'plugin_file'       => __FILE__,
			'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
			'requirements'      => [
				'php'       => '7.4',
				'wordpress' => '6.3',
			],
			'main_class'        => $main_class,
		];
	}

	/**
	 * #916 follow-up (MAJOR): get_download_id() must resolve the correct download id even
	 * though the plugin's own PLUGIN_ID does not match its loader definition's plugin_id —
	 * the exact shape of the woodev-test-payment-gateway fixture (real download id 9002).
	 * The mismatch must also be reported loudly under WP_DEBUG, never silently swallowed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_resolves_despite_a_plugin_id_mismatch(): void {
		define( 'WP_DEBUG', true );
		$this->mock_construction_and_resolver_functions();

		// self::class inside Woodev_Plugin::get_download_id() resolves to the DECLARING class
		// (Woodev_Plugin), not this subclass — self:: is not late-static-bound. Construction
		// itself (the real license handler, left at its default) already reaches
		// get_download_id() once, before this test calls it again explicitly — atLeast(1),
		// not exactly once, is the correct assertion.
		Functions\expect( '_doing_it_wrong' )->atLeast()->once()->with(
			'Woodev_Plugin::get_download_id',
			\Mockery::type( 'string' ),
			'2.0.2'
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( 'download-id-mismatch-test-plugin-WRONG-id', Woodev_Download_Id_Mismatch_Test_Plugin::class, 9002 )
		);

		$bootstrap->load_plugins();

		$plugin = Woodev_Download_Id_Mismatch_Test_Plugin::instance();

		$this->assertNotSame(
			$plugin->get_id(),
			'download-id-mismatch-test-plugin-WRONG-id',
			'sanity check: the fixture plugin_id really must not equal get_id().'
		);
		$this->assertSame( 9002, $plugin->get_download_id() );
	}

	/**
	 * Control: when the plugin's own PLUGIN_ID matches its loader definition's plugin_id,
	 * resolution still works and no mismatch is reported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_resolves_when_plugin_id_matches(): void {
		$this->mock_construction_and_resolver_functions();

		Functions\expect( '_doing_it_wrong' )->never();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( Woodev_Download_Id_Match_Test_Plugin::PLUGIN_ID, Woodev_Download_Id_Match_Test_Plugin::class, 9101 )
		);

		$bootstrap->load_plugins();

		$plugin = Woodev_Download_Id_Match_Test_Plugin::instance();

		$this->assertSame( 9101, $plugin->get_download_id() );
	}

	/**
	 * A plugin instance never invoked through the resolver (definitions_by_main_class has no
	 * entry for its class) must fall back to the plugin_id lookup rather than returning 0.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_falls_back_to_plugin_id_when_never_invoked_through_the_resolver(): void {
		$this->mock_construction_and_resolver_functions();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( Woodev_Download_Id_Never_Invoked_Test_Plugin::PLUGIN_ID, 'Some_Other_Class_Nobody_Constructs', 9202 )
		);

		// Deliberately never call $bootstrap->load_plugins(): the class-based map stays empty.
		$plugin = new Woodev_Download_Id_Never_Invoked_Test_Plugin();

		$this->assertSame( 9202, $plugin->get_download_id() );
	}
}
}
