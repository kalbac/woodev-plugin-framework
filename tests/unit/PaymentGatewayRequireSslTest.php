<?php
/**
 * Payment-gateway plugin `require_ssl` argument tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Payment_Gateway', false ) ) {
		class Require_Ssl_Test_WC_Payment_Gateway_Stub {}

		class_alias( Require_Ssl_Test_WC_Payment_Gateway_Stub::class, 'WC_Payment_Gateway' );
	}
}

namespace Woodev\Tests\Unit {

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/blocks-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub_For_Require_Ssl {

		/** @var string */
		protected $namespace;

		/** @var string */
		protected $rest_base;
	}

	class_alias( WP_REST_Controller_Test_Stub_For_Require_Ssl::class, 'WP_REST_Controller' );
}

/**
 * Runs the production constructor with the heavy protected collaborators stubbed out.
 */
class Testable_Gateway_Plugin_For_Require_Ssl extends \Woodev_Payment_Gateway_Plugin {

	/**
	 * Defers construction to initialize().
	 */
	public function __construct() {}

	/**
	 * Runs the production constructor.
	 *
	 * @param array<string,mixed> $args Plugin arguments.
	 * @return void
	 */
	public function initialize( array $args ): void {
		parent::__construct( 'ssl-test', '1.0.0', $args );
	}

	/**
	 * Exposes the protected `requires_ssl()`.
	 *
	 * @return mixed
	 */
	public function requires_ssl_public() {
		return $this->requires_ssl();
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

	/** @return string */
	protected function get_file() {
		return __FILE__;
	}

	/** @return string */
	public function get_plugin_name() {
		return 'SSL Test Gateway Plugin';
	}

	/** @return int */
	public function get_download_id() {
		return 0;
	}
}

/**
 * Class PaymentGatewayRequireSslTest.
 */
class PaymentGatewayRequireSslTest extends TestCase {

	/**
	 * Defines the WordPress function stubs base plugin construction needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
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
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/ssl-test' );
		Functions\stubs( [ 'add_action', 'add_filter' ] );
	}

	/**
	 * #1316: `(array) false` is `[ false ]`, which is truthy, so a plugin that never
	 * passed `require_ssl` still got the SSL notice for every gateway.
	 *
	 * @dataProvider provider_require_ssl
	 *
	 * @param array<string,mixed> $args     Plugin arguments.
	 * @param bool                $expected Whether the plugin requires SSL.
	 */
	public function test_requires_ssl_follows_the_require_ssl_argument( array $args, bool $expected ): void {
		$plugin = new Testable_Gateway_Plugin_For_Require_Ssl();
		$plugin->initialize( $args );

		$this->assertSame( $expected, (bool) $plugin->requires_ssl_public() );
	}

	/**
	 * Data provider for the require_ssl argument.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provider_require_ssl(): array {
		return [
			'omitted defaults to false' => [ [], false ],
			'explicit false'            => [ [ 'require_ssl' => false ], false ],
			'explicit true'             => [ [ 'require_ssl' => true ], true ],
		];
	}
}

}
