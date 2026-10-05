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
	class WP_REST_Controller_Test_Stub {

		/** @var string */
		protected $namespace;

		/** @var string */
		protected $rest_base;
	}

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
	 * Runs the production constructor for feature-default tests.
	 *
	 * @param string              $id Plugin ID.
	 * @param array<string,mixed> $args Plugin arguments.
	 * @return void
	 */
	public function initialize( string $id, array $args = [] ): void {
		parent::__construct( $id, '1.0.0', $args );
	}

	/** @return void */
	protected function init_dependencies( $dependencies ) {}
	/** @return void */
	protected function init_admin_message_handler() {}
	/** @return void */
	protected function init_admin_notice_handler() {}
	/** @return void */
	protected function init_license_handler() {}
	/** @return void */
	protected function init_hook_deprecator() {}
	/** @return void */
	protected function init_lifecycle_handler() {}
	/** @return void */
	protected function init_translation_handler(): void {}
	/** @return void */
	protected function init_cron_handler(): void {}
	/** @return void */
	protected function init_rest_api_handler() {}
	/** @return void */
	protected function init_blocks_handler(): void {}
	/** @return void */
	protected function init_setup_wizard_handler(): void {}
	/** @return void */
	protected function init_competitor_handler() {}
	/** @return void */
	protected function init_settings_page(): void {}

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
		// includes()/load_updater() gate on the filterable wp_doing_cron() (B-3,
		// s8-p0); like is_admin it always exists on a real WP (>= 4.8) and must be
		// stubbed here for the real includes() to run in unit context.
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'is_multisite' )->justReturn( false );
		// get_plugin_url() -> plugins_url(): needed since issue #759 made
		// init_license_handler() build a real Woodev_Plugins_License unconditionally.
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/test-plugin' );
	}

	/**
	 * Registered loader definitions supply the same feature flags consumed by plugin instances.
	 *
	 * @return void
	 */
	public function test_constructed_plugin_uses_loader_feature_defaults(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );

		$reflection = new \ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}
		$instance->setValue( null, null );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		foreach ( [ 'shipping' => true, 'plain' => false ] as $id => $blocks_compatible ) {
			$definition = [
				'plugin_id'         => 'feature-' . $id,
				'download_id'       => 9901,
				'plugin_name'       => 'Feature ' . $id,
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
				'requirements'      => [ 'php' => '7.4', 'wordpress' => '6.3', 'woocommerce' => '7.0' ],
				'callback'          => static function (): void {},
			];
			if ( 'shipping' === $id ) {
				$definition['type'] = 'shipping';
			}
			$this->assertTrue( $bootstrap->register_loader_definition( $definition ) );

			$plugin = new Testable_Woocommerce_Plugin();
			$plugin->initialize( 'feature-' . $id );
			$this->assertSame( $blocks_compatible, $plugin->get_supported_features()['blocks']['cart'] );
			$this->assertSame( $blocks_compatible, $plugin->get_supported_features()['blocks']['checkout'] );
		}
	}

	/**
	 * The loader definition is authoritative when constructor features disagree.
	 */
	public function test_loader_features_win_and_report_disagreeing_constructor_features(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );
		Functions\expect( '_doing_it_wrong' )->once();
		$this->register_feature_definition( 'feature-precedence', true );

		$plugin = new Testable_Woocommerce_Plugin();
		$plugin->initialize( 'feature-precedence', [ 'supported_features' => [ 'blocks' => [ 'cart' => false, 'checkout' => false ] ] ] );

		$this->assertTrue( $plugin->get_supported_features()['blocks']['cart'] );
		$this->assertTrue( $plugin->get_supported_features()['blocks']['checkout'] );
	}

	/**
	 * Matching constructor features do not emit a disagreement notice.
	 */
	public function test_matching_constructor_features_do_not_report(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );
		Functions\expect( '_doing_it_wrong' )->never();
		$this->register_feature_definition( 'feature-agreement', true );

		$plugin = new Testable_Woocommerce_Plugin();
		$plugin->initialize( 'feature-agreement', [ 'supported_features' => [ 'hpos' => false, 'blocks' => [ 'cart' => true, 'checkout' => true ] ] ] );

		$this->assertTrue( $plugin->get_supported_features()['blocks']['cart'] );
	}

	/**
	 * Unregistered plugins retain constructor features, including explicit false values.
	 */
	public function test_unregistered_plugin_honours_constructor_features(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );
		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = new Testable_Woocommerce_Plugin();
		$plugin->initialize( 'feature-unregistered', [ 'supported_features' => [ 'hpos' => true, 'blocks' => [ 'cart' => true, 'checkout' => false ] ] ] );

		$this->assertTrue( $plugin->get_supported_features()['hpos'] );
		$this->assertTrue( $plugin->get_supported_features()['blocks']['cart'] );
		$this->assertFalse( $plugin->get_supported_features()['blocks']['checkout'] );
	}

	/**
	 * WooCommerce plugin construction degrades safely when the bootstrap class is absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constructor_without_bootstrap_keeps_constructor_features(): void {
		$this->assertFalse( class_exists( 'Woodev_Plugin_Bootstrap', false ) );
		spl_autoload_register(
			static function ( string $class ): void {
				if ( 'Woodev_Plugin_Bootstrap' === $class ) {
					throw new \Error( 'The bootstrap class is intentionally unavailable in this process.' );
				}
			},
			true,
			true
		);

		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );

		$plugin = new Testable_Woocommerce_Plugin();
		try {
			$plugin->initialize( 'feature-without-bootstrap', [ 'supported_features' => [ 'blocks' => [ 'cart' => true, 'checkout' => false ] ] ] );
		} catch ( \Error $error ) {
			$this->fail( 'Construction should not call a missing bootstrap class: ' . $error->getMessage() );
		}

		$this->assertTrue( $plugin->get_supported_features()['blocks']['cart'] );
		$this->assertFalse( $plugin->get_supported_features()['blocks']['checkout'] );
	}

	/**
	 * An explicit false in a registered definition survives construction.
	 */
	public function test_constructed_plugin_keeps_definition_explicit_false(): void {
		$this->mock_wordpress_plugin_construction_functions();
		Functions\stubs( [ 'add_action', 'add_filter' ] );
		Functions\expect( '_doing_it_wrong' )->never();
		$this->register_feature_definition( 'feature-explicit-false', true, false );

		$plugin = new Testable_Woocommerce_Plugin();
		$plugin->initialize( 'feature-explicit-false' );

		$this->assertFalse( $plugin->get_supported_features()['blocks']['cart'] );
		$this->assertFalse( $plugin->get_supported_features()['blocks']['checkout'] );
	}

	/**
	 * Registers a WooCommerce loader definition for feature constructor tests.
	 *
	 * @param string $id Plugin id.
	 * @param bool   $shipping Whether the definition has shipping type.
	 * @param bool   $blocks_compatible Optional explicit block compatibility.
	 * @return void
	 */
	private function register_feature_definition( string $id, bool $shipping, bool $blocks_compatible = true ): void {
		$reflection = new \ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}
		$instance->setValue( null, null );

		$definition = [
			'plugin_id'         => $id,
			'download_id'       => 9902,
			'plugin_name'       => $id,
			'plugin_version'    => '1.0.0',
			'framework_version' => '2.0.0',
			'plugin_file'       => __FILE__,
			'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
			'requirements'      => [ 'php' => '7.4', 'wordpress' => '6.3', 'woocommerce' => '7.0' ],
			'callback'          => static function (): void {},
		];
		if ( $shipping ) {
			$definition['type'] = 'shipping';
		}
		if ( ! $blocks_compatible ) {
			$definition['supported_features'] = [ 'blocks' => [ 'cart' => false, 'checkout' => false ] ];
		}

		\Woodev_Plugin_Bootstrap::instance()->register_loader_definition( $definition );
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
	 * frontend_enqueue_scripts() must register the generic modal shell (D-13): framework-side,
	 * exactly once, resolving under the FRAMEWORK's own assets path — the same directory
	 * `get_framework_assets_url()` derives from `get_framework_file()` (hardcoded to
	 * `class-plugin.php`'s own location) — never a shipping-module or other subsystem path.
	 * `Pickup_Handler` (see PickupHandlerTest) only ever DECLARES `woodev-modal` as a script
	 * dependency; this is the one and only place that registers the handle.
	 *
	 * @return void
	 */
	public function test_frontend_enqueue_scripts_registers_the_generic_modal_handle(): void {
		$this->mock_wordpress_plugin_construction_functions();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		// Echoes $file's own directory (normalized, anchored at "/woodev/") back into the URL
		// instead of discarding it, so the assertions below can tell "resolved from
		// class-plugin.php's own directory" apart from any other subsystem's asset root.
		Functions\when( 'plugins_url' )->alias(
			static function ( $path, $file ) {
				$normalized = str_replace( '\\', '/', (string) $file );
				$marker     = strpos( $normalized, '/woodev/' );
				$relative   = false !== $marker ? substr( $normalized, $marker ) : $normalized;

				return 'https://example.test/wp-content/plugins/x' . dirname( $relative ) . $path;
			}
		);

		// Recorded as a LIST, not keyed by handle: a keyed map would let a second
		// wp_register_script( 'woodev-modal', ... ) overwrite the first and go unnoticed, while
		// D-13 requires the handle to be registered exactly once framework-side.
		$calls = [];
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $src, $deps, $ver ) use ( &$calls ) {
				$calls[] = [ 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver ];
			}
		);
		// The method also registers the modal's chrome stylesheet (D-13) — irrelevant to this
		// test's assertions, but the function must still be mocked or Brain Monkey errors out.
		Functions\when( 'wp_register_style' )->justReturn( true );

		$plugin->frontend_enqueue_scripts();

		$registered = [];

		foreach ( $calls as $call ) {
			$registered[ $call['handle'] ] = $call;
		}

		$this->assertCount(
			1,
			array_filter( $calls, static fn( array $call ): bool => 'woodev-modal' === $call['handle'] ),
			'The generic modal handle must be registered exactly once.'
		);
		$this->assertArrayHasKey( 'woodev-modal', $registered );
		$this->assertStringContainsString(
			'/woodev/assets/js/frontend/woodev-modal.js',
			$registered['woodev-modal']['src']
		);
		$this->assertStringNotContainsString( 'shipping-method', $registered['woodev-modal']['src'] );
		// Vanilla ES5, no jQuery/Backbone — see woodev-modal.js's own docblock. Do not copy
		// `jquery` in by reflex; the modal genuinely has zero script dependencies.
		$this->assertSame( [], $registered['woodev-modal']['deps'] );
		// Must be versioned via get_assets_version() — which busts the cache under
		// SCRIPT_DEBUG/WP_DEBUG — not the bare self::VERSION constant, which only changes on a
		// framework release and left `woodev-modal.js` edits invisible to a browser that had
		// already loaded the page (gotcha modal-script-versioned-by-version-constant-not-filemtime).
		// The test plugin's own version ('1.0.0') intentionally differs from the framework's
		// \Woodev_Plugin::VERSION ('2.0.1') so a regression to the bare constant is caught here.
		$this->assertSame( $plugin->get_assets_version(), $registered['woodev-modal']['ver'] );
		$this->assertNotSame( \Woodev_Plugin::VERSION, $registered['woodev-modal']['ver'] );
	}

	/**
	 * frontend_enqueue_scripts() must register the generic modal's CHROME STYLESHEET (D-13) the
	 * same way it registers the script: framework-side, exactly once, resolving under the
	 * FRAMEWORK's own assets path — never `shipping-method/`. `Pickup_Handler` (see
	 * PickupHandlerTest) only ever DECLARES `woodev-modal` as a style dependency; this is the
	 * one and only place that registers the handle. Unlike the script (versioned by
	 * `get_assets_version()`), the style is versioned by its own `filemtime()` — a CSS-only
	 * tweak must bust the browser cache without a framework version bump (gotcha
	 * wp-scripts-css-enqueue-version-by-mtime).
	 *
	 * @return void
	 */
	public function test_frontend_enqueue_scripts_registers_the_generic_modal_style_handle(): void {
		$this->mock_wordpress_plugin_construction_functions();

		$plugin = new Testable_Wordpress_Plugin( 'test-wordpress-plugin', '1.0.0' );

		Functions\when( 'plugins_url' )->alias(
			static function ( $path, $file ) {
				$normalized = str_replace( '\\', '/', (string) $file );
				$marker     = strpos( $normalized, '/woodev/' );
				$relative   = false !== $marker ? substr( $normalized, $marker ) : $normalized;

				return 'https://example.test/wp-content/plugins/x' . dirname( $relative ) . $path;
			}
		);

		// Recorded as a LIST, not keyed by handle: a keyed map would let a second
		// wp_register_style( 'woodev-modal', ... ) overwrite the first and go unnoticed, while
		// D-13 requires the handle to be registered exactly once framework-side.
		$calls = [];
		Functions\when( 'wp_register_style' )->alias(
			static function ( $handle, $src, $deps, $ver ) use ( &$calls ) {
				$calls[] = [ 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver ];
			}
		);
		// The method also registers the modal's script handle — irrelevant to this test's
		// assertions, but the function must still be mocked or Brain Monkey errors out.
		Functions\when( 'wp_register_script' )->justReturn( true );

		$plugin->frontend_enqueue_scripts();

		$registered = [];

		foreach ( $calls as $call ) {
			$registered[ $call['handle'] ] = $call;
		}

		$this->assertCount(
			1,
			array_filter( $calls, static fn( array $call ): bool => 'woodev-modal' === $call['handle'] ),
			'The generic modal style handle must be registered exactly once.'
		);
		$this->assertArrayHasKey( 'woodev-modal', $registered );
		$this->assertStringContainsString(
			'/woodev/assets/css/frontend/woodev-modal.css',
			$registered['woodev-modal']['src']
		);
		$this->assertStringNotContainsString( 'shipping-method', $registered['woodev-modal']['src'] );
		$this->assertSame( [], $registered['woodev-modal']['deps'] );

		$style_path = dirname( __DIR__, 2 ) . '/woodev/assets/css/frontend/woodev-modal.css';
		$this->assertFileExists( $style_path, 'The chrome stylesheet created by this task must exist on disk.' );
		$this->assertSame(
			(string) filemtime( $style_path ),
			$registered['woodev-modal']['ver'],
			'Must be versioned by the stylesheet\'s own filemtime, not self::VERSION.'
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
	 * Late WooCommerce runtime hooks should be owned by Woocommerce_Plugin, not the platform-neutral base.
	 */
	public function test_registers_woocommerce_runtime_hooks(): void {
		$plugin = new Testable_Woocommerce_Plugin();

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
		if ( PHP_VERSION_ID < 80100 ) {
			$register->setAccessible( true );
		}
		$register->invoke( $plugin );
	}
}
