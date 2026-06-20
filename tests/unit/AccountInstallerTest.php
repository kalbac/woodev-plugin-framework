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

	protected function run_upgrader( string $package ) {
		$this->ran = $package;
		return $this->result;
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
		// Default: filters return the passed default value.
		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
	}

	/* ---- is_trusted_package_url ---- */

	public function test_trusts_store_host(): void {
		$this->assertTrue(
			\Woodev_Account_Installer::is_trusted_package_url( 'https://woodev.ru/index.php?eddfile=7:21:0&token=abc' )
		);
	}

	public function test_trusts_http_store_host(): void {
		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'http://woodev.ru/index.php?x=1' ) );
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

	public function test_allowed_hosts_filter_admits_rig_host(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) {
			if ( 'woodev_account_install_allowed_hosts' === $tag ) {
				return array( 'woodev.ru', 'localhost' );
			}
			return $value;
		} );

		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'http://localhost:8090/index.php?eddfile=1' ) );
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
	}
}
