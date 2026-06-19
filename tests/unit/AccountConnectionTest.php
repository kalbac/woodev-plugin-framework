<?php
/**
 * Unit tests for Woodev_Account_Connection — state, canonical-field derivation,
 * connect-URL, and get_account() shape. Network/redirect paths are rig-verified.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';

/**
 * @covers \Woodev_Account_Connection
 */
final class AccountConnectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( (string) $url );
			}
		);
	}

	/** Invokes a private method via reflection. */
	private function call_private( $object, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invokeArgs( $object, $args );
	}

	public function test_canonical_for_pretty_permalink_url(): void {
		$conn      = new \Woodev_Account_Connection();
		$canonical = $this->call_private(
			$conn,
			'canonical_for',
			array( 'http://localhost:8090/wp-json/woodev-account/v1/oauth/me', 'GET', '', '1750000000' )
		);

		$this->assertSame( 'localhost:8090', $canonical['host'] );
		$this->assertSame( '/wp-json/woodev-account/v1/oauth/me', $canonical['request_uri'] );
		$this->assertSame( 'GET', $canonical['method'] );
		$this->assertSame( '', $canonical['body'] );
		$this->assertSame( '1750000000', $canonical['timestamp'] );
	}

	public function test_canonical_for_plain_permalink_url_keeps_query(): void {
		$conn      = new \Woodev_Account_Connection();
		$canonical = $this->call_private(
			$conn,
			'canonical_for',
			array( 'https://woodev.ru/index.php?rest_route=/woodev-account/v1/oauth/me', 'get', '', '1' )
		);

		$this->assertSame( 'woodev.ru', $canonical['host'] );
		$this->assertSame( '/index.php?rest_route=/woodev-account/v1/oauth/me', $canonical['request_uri'] );
		$this->assertSame( 'GET', $canonical['method'] );
	}

	public function test_is_connected_reflects_stored_token(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertFalse( ( new \Woodev_Account_Connection() )->is_connected() );

		Functions\when( 'get_option' )->justReturn(
			array(
				'auth'           => array( 'access_token' => 'tok', 'access_token_secret' => 's', 'url' => 'https://woodev.ru' ),
				'auth_user_data' => array( 'name' => 'Jane', 'email' => 'j@x.dev', 'avatar' => 'https://x/a.png' ),
			)
		);
		$this->assertTrue( ( new \Woodev_Account_Connection() )->is_connected() );
	}

	public function test_get_account_disconnected_shape(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$account = ( new \Woodev_Account_Connection() )->get_account();

		$this->assertFalse( $account['connected'] );
		$this->assertSame( '', $account['name'] );
		$this->assertSame( '', $account['avatar'] );
	}

	public function test_get_account_connected_shape(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'auth'           => array( 'access_token' => 'tok', 'access_token_secret' => 's', 'url' => 'https://woodev.ru' ),
				'auth_user_data' => array( 'name' => 'Jane', 'email' => 'j@x.dev', 'avatar' => 'https://x/a.png' ),
			)
		);

		$account = ( new \Woodev_Account_Connection() )->get_account();

		$this->assertTrue( $account['connected'] );
		$this->assertSame( 'Jane', $account['name'] );
		$this->assertSame( 'j@x.dev', $account['email'] );
		$this->assertSame( 'https://x/a.png', $account['avatar'] );
		$this->assertSame( 'https://woodev.ru', $account['url'] );
	}

	public function test_get_connect_url_is_nonced_and_flagged(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url, $action ) {
				return $url . '&_wpnonce=' . md5( (string) $action );
			}
		);

		$url = ( new \Woodev_Account_Connection() )->get_connect_url();

		$this->assertStringContainsString( 'page=woodev-extensions', $url );
		$this->assertStringContainsString( 'woodev-account-connect=1', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}
}
