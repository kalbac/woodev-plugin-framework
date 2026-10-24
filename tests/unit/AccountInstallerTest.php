<?php
/**
 * Unit tests for Woodev_Account_Installer — the SSRF package-URL guard and the
 * install orchestration (connection + ownership reply + trust check), with the
 * live WordPress upgrader stubbed out via a protected seam.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-installer.php';

/**
 * Installer whose upgrader step is captured instead of run.
 */
class Spy_Account_Installer extends \Woodev_Account_Installer {

	/** @var string|null The package handed to the upgrader, or null if never reached. */
	public $ran = null;

	/** @var true|\WP_Error The canned upgrader result. */
	public $result = true;

	/** @var bool Whether the install should overwrite the refused plugin folder. */
	public $overwrite = false;

	/** @var array<int,array<string,mixed>> Resolver records refused by framework compatibility. */
	public $incompatible_plugins = [];

	/** @var array<int,array<string,mixed>> Legacy tombstone records. */
	public $legacy_plugins = [];

	protected function get_incompatible_framework_plugins(): array {
		return $this->incompatible_plugins;
	}

	protected function get_mixed_fleet_incompatible_plugins(): array {
		return $this->legacy_plugins;
	}

	protected function run_upgrader( string $package, bool $overwrite = false, int $download_id = 0 ) {
		$this->ran = $package;
		$this->overwrite = $overwrite;
		return $this->result;
	}
}

/** Installer test seam for upgrader error and response behavior. */
class Testable_Account_Installer extends \Woodev_Account_Installer {
	/** @var bool Whether to expose diagnostic detail in the response. */
	public $debug_enabled = false;

	public function extract_error( $upgrader, $skin ): string {
		return $this->get_upgrader_error( $upgrader, $skin );
	}

	public function create_error( int $download_id, string $error ): \WP_Error {
		return $this->create_install_error( $download_id, $error );
	}

	public function log_error( string $package, string $error ): void {
		$this->log_install_error( $package, $error );
	}

	protected function is_debug_enabled_for_download( int $download_id ): bool {
		return $this->debug_enabled;
	}
}

/** Installer exposing the production debug lookup without overriding it. */
class Debug_Lookup_Account_Installer extends \Woodev_Account_Installer {
	public function debug_enabled_for_download( int $download_id ): bool {
		return $this->is_debug_enabled_for_download( $download_id );
	}
}

/**
 * @covers \Woodev_Account_Installer
 */
final class AccountInstallerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static function ( $u ) { return parse_url( (string) $u ); } );
		Functions\when( 'untrailingslashit' )->alias( static function ( $u ) { return rtrim( (string) $u, '/' ); } );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static function ( $value ) { return strip_tags( (string) $value ); } );
		// Default: filters return the passed default value.
		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
	}

	/* ---- is_trusted_package_url ---- */

	public function test_trusts_store_host(): void {
		$this->assertTrue(
			\Woodev_Account_Installer::is_trusted_package_url( 'https://woodev.ru/index.php?eddfile=7:21:0&token=abc' )
		);
	}

	public function test_rejects_http_when_store_is_https(): void {
		// Default store base is https → a cleartext package URL must be rejected.
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'http://woodev.ru/index.php?x=1' ) );
	}

	public function test_allows_http_when_store_base_is_http(): void {
		// Local rig: an http store base accepts an http package on an allowed host.
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) {
			if ( 'woodev_account_api_url' === $tag ) {
				return 'http://localhost:8090';
			}
			if ( 'woodev_account_install_allowed_hosts' === $tag ) {
				return array( 'localhost' );
			}
			return $value;
		} );

		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'http://localhost:8090/index.php?eddfile=1' ) );
	}

	public function test_rejects_foreign_host(): void {
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'https://evil.example/package.zip' ) );
	}

	public function test_rejects_userinfo_smuggled_host(): void {
		// Host parses to evil.example; the store host is only in the userinfo.
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'https://woodev.ru@evil.example/p.zip' ) );
	}

	public function test_rejects_credentials_on_store_host(): void {
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'https://user:pass@woodev.ru/p.zip' ) );
	}

	public function test_rejects_non_http_schemes(): void {
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'ftp://woodev.ru/p.zip' ) );
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'file:///etc/passwd' ) );
	}

	public function test_rejects_empty(): void {
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( '' ) );
	}

	public function test_allowed_hosts_filter_admits_extra_host(): void {
		// The filter admits an additional host (here over https, so the transport
		// pin is satisfied); the rig's http case is covered separately.
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) {
			if ( 'woodev_account_install_allowed_hosts' === $tag ) {
				return array( 'woodev.ru', 'cdn.woodev.ru' );
			}
			return $value;
		} );

		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'https://cdn.woodev.ru/p.zip' ) );
	}

	/* ---- install() orchestration ---- */

	/** Stubs a connected state + a canned connector body for the signed transport. */
	private function stub_connected_transport( string $body, int $code = 200 ): void {
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'tok', 'access_token_secret' => 'sec' ) )
		);
	}

	public function test_install_rejects_invalid_id(): void {
		Functions\when( 'is_wp_error' )->alias( static function ( $t ) { return $t instanceof \WP_Error; } );

		$result = ( new Spy_Account_Installer() )->install( 0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_install_invalid', $result->get_error_code() );
	}

	public function test_incompatible_plugin_debug_lookup_uses_the_v2_option(): void {
		$plugin_id   = 'debug-lookup-test';
		$download_id = 48151;
		$definition  = \Woodev\Framework\Framework_Plugin_Loader_Definition::from_array(
			[
				'plugin_id'         => $plugin_id,
				'download_id'       => $download_id,
				'plugin_name'       => 'Debug Lookup Test',
				'plugin_version'    => '1.0.0',
				'framework_version' => '1.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'requirements'      => [ 'php' => '7.4', 'wordpress' => '6.3' ],
				'callback'          => static function (): void {},
			]
		);
		$this->assertInstanceOf( \Woodev\Framework\Framework_Plugin_Loader_Definition::class, $definition );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$property  = new \ReflectionProperty( \Woodev_Plugin_Bootstrap::class, 'incompatible_framework_plugins' );
		$property->setAccessible( true );
		$property->setValue( $bootstrap, [ [ 'definition' => $definition ] ] );
		$active_plugins = new \ReflectionProperty( \Woodev_Plugin_Bootstrap::class, 'active_plugins' );
		$active_plugins->setAccessible( true );
		$active_plugins->setValue( $bootstrap, [] );

		$requested_options = [];
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) use ( &$requested_options ): string {
				$requested_options[] = $option;
				return 'yes';
			}
		);

		$this->assertTrue( ( new Debug_Lookup_Account_Installer() )->debug_enabled_for_download( $download_id ) );
		$this->assertSame( [ 'woodev_debug_lookup_test_advanced_enable_debug' ], $requested_options );
	}

	public function test_install_rejects_when_not_connected(): void {
		Functions\when( 'is_wp_error' )->alias( static function ( $t ) { return $t instanceof \WP_Error; } );
		Functions\when( 'get_option' )->justReturn( false );

		$result = ( new Spy_Account_Installer() )->install( 21 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_install_not_connected', $result->get_error_code() );
	}

	public function test_install_returns_transport_error(): void {
		$this->stub_connected_transport( '', 500 );
		Functions\when( 'is_wp_error' )->alias( static function ( $t ) { return $t instanceof \WP_Error; } );

		$installer = new Spy_Account_Installer();
		$result    = $installer->install( 21 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $installer->ran, 'The upgrader must not run on a transport error.' );
	}

	public function test_install_rejects_untrusted_package(): void {
		$this->stub_connected_transport( '{"package":"https://evil.example/p.zip"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$installer = new Spy_Account_Installer();
		$result    = $installer->install( 21 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_install_bad_package', $result->get_error_code() );
		$this->assertNull( $installer->ran, 'An untrusted package URL must never reach the upgrader.' );
	}

	public function test_install_happy_path_runs_upgrader_without_activation(): void {
		$this->stub_connected_transport( '{"package":"https://woodev.ru/index.php?eddfile=7:21:0&token=abc"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$installer = new Spy_Account_Installer();
		$result    = $installer->install( 21 );

		$this->assertTrue( $result );
		$this->assertSame( 'https://woodev.ru/index.php?eddfile=7:21:0&token=abc', $installer->ran );
		$this->assertFalse( $installer->overwrite, 'A first-time install must not delete an existing directory.' );
	}

	public function test_install_overwrites_a_plugin_refused_by_the_framework(): void {
		$this->stub_connected_transport( '{"package":"https://woodev.ru/index.php?eddfile=7:21:0&token=abc"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$definition = new class() {
			public function get_download_id(): int { return 21; }
		};
		$installer = new Spy_Account_Installer();
		$installer->incompatible_plugins = [ [ 'definition' => $definition ] ];

		$this->assertTrue( $installer->install( 21 ) );
		$this->assertTrue( $installer->overwrite, 'The refused product should install with overwrite enabled.' );
	}

	public function test_install_overwrites_a_v1_plugin_recorded_by_the_tombstone(): void {
		$this->stub_connected_transport( '{"package":"https://woodev.ru/index.php?eddfile=7:8772:0&token=abc"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$installer = new Spy_Account_Installer();
		$installer->legacy_plugins = [
			[
				'plugin_name' => 'EMS',
				'path'        => '/var/www/wp-content/plugins/woodev-russian-post/woodev-russian-post.php',
			],
		];

		$this->assertTrue( $installer->install( 8772 ) );
		$this->assertTrue( $installer->overwrite, 'The tombstoned v1 product should install with overwrite enabled.' );
	}

	public function test_renamed_v1_plugin_folder_is_not_offered_or_overwritten(): void {
		$legacy_plugins = [
			[
				'plugin_name' => 'EMS',
				'path'        => '/var/www/wp-content/plugins/woodev-russian-post-1.2.6/woodev-russian-post.php',
			],
		];

		$this->assertSame( [], \Woodev_Installed_Plugins::download_ids_from_mixed_fleet_records( $legacy_plugins ) );

		$this->stub_connected_transport( '{"package":"https://woodev.ru/index.php?eddfile=7:8772:0&token=abc"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$installer                 = new Spy_Account_Installer();
		$installer->legacy_plugins = $legacy_plugins;

		$this->assertTrue( $installer->install( 8772 ) );
		$this->assertFalse( $installer->overwrite, 'A renamed v1 folder must not trigger a destructive overwrite.' );
	}

	public function test_wp_error_from_download_is_logged_without_signed_query_parameters(): void {
		$download_error = new \WP_Error(
			'download_failed',
			'Download failed.',
			'403 from https://woodev.ru/index.php?token=secret&signature=signed'
		);
		$logger = new class() {
			public $message = '';
			public $context = [];
			public function error( $message, $context ): void {
				$this->message = $message;
				$this->context = $context;
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$installer = new Testable_Account_Installer();
		$detail    = $installer->extract_error( (object) [ 'result' => [] ], new class( $download_error ) {
			private $message;
			public function __construct( \WP_Error $error ) {
				$this->message = $error->get_error_message() . ' ' . $error->get_error_data();
			}
			public function get_upgrade_messages(): array { return [ $this->message ]; }
		} );
		$installer->log_error( 'https://woodev.ru/index.php?token=secret&signature=signed', $detail );

		$this->assertStringContainsString( '403', $logger->message );
		$this->assertStringContainsString( 'https://woodev.ru/index.php', $logger->message );
		$this->assertStringNotContainsString( 'token=', $logger->message );
		$this->assertStringNotContainsString( 'signature=', $logger->message );
		$this->assertSame( 'woodev-account-installer', $logger->context['source'] );
	}

	public function test_upgrader_cause_uses_the_last_skin_message(): void {
		$installer = new Testable_Account_Installer();
		$skin      = new class() {
			public function get_upgrade_messages(): array {
				return [ 'Downloading package…', 'Unpacking…', 'Installation failed: archive is invalid.' ];
			}
		};

		$this->assertSame(
			'Installation failed: archive is invalid.',
			$installer->extract_error( (object) [ 'result' => [] ], $skin )
		);
	}

	public function test_install_error_includes_cause_only_when_debug_is_enabled(): void {
		$installer = new Testable_Account_Installer();
		$installer->debug_enabled = true;
		$detail = $installer->create_error( 21, 'Download failed. 403 Forbidden.' );
		$this->assertStringContainsString( '403 Forbidden', $detail->get_error_message() );

		$installer->debug_enabled = false;
		$generic = $installer->create_error( 21, 'Download failed. 403 Forbidden.' );
		$this->assertSame( 'Не удалось установить плагин.', $generic->get_error_message() );
	}
}
