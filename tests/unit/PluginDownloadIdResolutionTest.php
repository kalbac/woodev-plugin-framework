<?php
/**
 * Woodev_Plugin::get_download_id() resolution tests (#916 follow-up).
 *
 * `get_download_id()` is the single source of truth licensing, the updater and the license
 * authority all read (class-plugin.php:1304+). It used to resolve the loader definition by
 * `get_id()` alone, which silently returned 0 the moment a plugin's own `PLUGIN_ID` did not
 * match the `plugin_id` it registered in its loader definition — a real author error this
 * repo's own `tests/_fixtures/woodev-test-payment-gateway` fixture demonstrated by accident
 * until it was aligned (#916 round 2): its definition declared `plugin_id =>
 * 'woodev-test-payment-gateway'` while the constructed plugin's `PLUGIN_ID` was
 * `'woodev-test-payment-gateway-plugin'`. The mismatch test plugin below reproduces that exact
 * shape deliberately, as regression coverage for the case the fixture no longer exhibits.
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

		/**
		 * Exposes the protected loader-definition resolver for focused regression tests.
		 *
		 * @param string $plugin_id Plugin id.
		 * @return \Woodev\Framework\Framework_Plugin_Loader_Definition|null
		 */
		public function resolve_definition_for_test( string $plugin_id ): ?\Woodev\Framework\Framework_Plugin_Loader_Definition {
			return $this->resolve_loader_definition( $plugin_id );
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

if ( ! class_exists( 'Woodev_Download_Id_Child_Test_Plugin', false ) ) {

	/**
	 * A CHILD of the match test plugin with its own PLUGIN_ID. Never invoked through the
	 * resolver, so its class has no exact-class entry — but its PARENT has (once the parent's
	 * loader definition has been through load_plugins()), which makes the ancestor lookup hit
	 * a DIFFERENT definition than the plugin_id lookup does. That is the only shape that can
	 * tell the plugin_id -> ancestor order apart from the swapped one.
	 */
	class Woodev_Download_Id_Child_Test_Plugin extends Woodev_Download_Id_Match_Test_Plugin {

		const PLUGIN_ID = 'download-id-child-test-plugin';

		public function __construct() {
			// Skip the parent's constructor: it would pass the PARENT's PLUGIN_ID.
			\Woodev_Plugin::__construct( self::PLUGIN_ID, self::VERSION );
		}

		public function get_plugin_name() {
			return 'Download Id Child Test Plugin';
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
	 * the shape the woodev-test-payment-gateway fixture used to exhibit by accident (real
	 * download id 9002). #916 round 2: a mismatch that still resolves (a non-zero result) is
	 * NOT reported — the class lookup already did its job, so there is nothing to warn about;
	 * only a resolution that returns 0 is (see {@see self::test_get_download_id_reports_a_true_zero_result_only_once()}).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_resolves_despite_a_plugin_id_mismatch(): void {
		define( 'WP_DEBUG', true );
		$this->mock_construction_and_resolver_functions();

		Functions\expect( '_doing_it_wrong' )->never();

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

	/**
	 * #943: the lookup order is class -> plugin_id -> ancestor. An ancestor match means some
	 * OTHER plugin's main_class is a parent of this class; a plugin's own exact plugin_id
	 * registration must never lose to it (#916 round 2). The resolver level pins each lookup
	 * on its own; only here, at the plugin level, does a swap of the last two `??` operands
	 * show — the child's plugin_id definition (9502) and its parent's main-class definition
	 * (9501) both resolve, and the plugin_id one must win.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_prefers_the_plugin_id_match_over_an_ancestor_class_match(): void {
		$this->mock_construction_and_resolver_functions();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		// The parent is invoked through the resolver, which records it in the main-class map.
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( Woodev_Download_Id_Match_Test_Plugin::PLUGIN_ID, Woodev_Download_Id_Match_Test_Plugin::class, 9501 )
		);
		$bootstrap->load_plugins();

		// The child's own definition is registered AFTER load_plugins(), under a main class
		// nobody constructs: it exists only in the plugin_id map, never in the class map.
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( Woodev_Download_Id_Child_Test_Plugin::PLUGIN_ID, 'Some_Other_Class_Nobody_Constructs', 9502 )
		);

		$plugin = new Woodev_Download_Id_Child_Test_Plugin();

		$this->assertNull(
			$bootstrap->get_loader_definition_for_class( Woodev_Download_Id_Child_Test_Plugin::class ),
			'sanity check: the exact-class lookup must miss for the child.'
		);
		$this->assertSame(
			9501,
			$bootstrap->get_loader_definition_for_class_ancestor( Woodev_Download_Id_Child_Test_Plugin::class )->get_download_id(),
			'sanity check: the ancestor lookup must resolve to the PARENT definition.'
		);
		$this->assertSame( 9502, $plugin->get_download_id(), 'The plugin_id match must win over the ancestor match.' );
	}

	/**
	 * The plugin-level resolver reaches an ancestor-class definition when exact and id lookups miss.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_loader_definition_uses_ancestor_class_lookup(): void {
		$this->mock_construction_and_resolver_functions();
		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_loader_definition(
			$this->get_loader_definition( Woodev_Download_Id_Match_Test_Plugin::PLUGIN_ID, Woodev_Download_Id_Match_Test_Plugin::class, 9601 )
		);
		$bootstrap->load_plugins();

		$plugin = ( new ReflectionClass( Woodev_Download_Id_Child_Test_Plugin::class ) )->newInstanceWithoutConstructor();
		$definition = $plugin->resolve_definition_for_test( 'unregistered-child-id' );

		$this->assertInstanceOf( \Woodev\Framework\Framework_Plugin_Loader_Definition::class, $definition );
		$this->assertSame( 9601, $definition->get_download_id() );
	}

	/**
	 * #916 round 2: a genuine resolution failure — nothing registered for either the class
	 * or the plugin id, a true 0 result — is reported via `_doing_it_wrong()`, but only ONCE
	 * per plugin instance per request: `get_download_id()` runs from the constructor (which
	 * itself already reaches it once through the real license handler, left at its default),
	 * so an unconditional report would print the notice on every call for the request's
	 * lifetime instead of once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_download_id_reports_a_true_zero_result_only_once(): void {
		define( 'WP_DEBUG', true );
		$this->mock_construction_and_resolver_functions();

		Functions\expect( '_doing_it_wrong' )->once()->with(
			'Woodev_Plugin::get_download_id',
			\Mockery::type( 'string' ),
			'2.0.2'
		);

		// Deliberately never registered anywhere: both the class and plugin_id lookups miss.
		$plugin = new Woodev_Download_Id_Never_Invoked_Test_Plugin();

		$this->assertSame( 0, $plugin->get_download_id() );
		$this->assertSame( 0, $plugin->get_download_id() );
	}
}
}
