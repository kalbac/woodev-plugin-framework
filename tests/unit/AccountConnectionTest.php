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

	public function test_get_connect_url_is_nonced_flagged_and_not_entity_encoded(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args ); // clean & separators.
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		// esc_url_raw (data context) does NOT HTML-entity-encode '&' — model that.
		Functions\when( 'esc_url_raw' )->returnArg();

		$url = ( new \Woodev_Account_Connection() )->get_connect_url();

		$this->assertStringContainsString( 'page=woodev-extensions', $url );
		$this->assertStringContainsString( 'woodev-account-connect=1', $url );
		$this->assertStringContainsString( '_wpnonce=NONCE', $url );
		// Regression: the URL is JSON/JS-consumed, so it must NOT be HTML-entity
		// encoded (gotcha esc-url-raw-for-js-consumed-urls). wp_nonce_url would do this.
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( '&#038;', $url );
	}

	public function test_request_signs_resource_request_with_bearer_and_headers(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $d ) {
				return json_encode( $d );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'auth' => array(
					'access_token'        => 'TOK',
					'access_token_secret' => 'SECRET',
					'url'                 => 'https://woodev.ru',
				),
			)
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"name":"Jane","email":"j@x.dev","avatar":"a"}' );

		$captured = array();
		Functions\when( 'wp_safe_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array( 'url' => $url, 'args' => $args );
				return array();
			}
		);

		$out = ( new \Woodev_Account_Connection() )->request( 'GET', '/oauth/me' );

		$this->assertSame( 'Jane', $out['name'] );
		$this->assertSame( 'https://woodev.ru/wp-json/woodev-account/v1/oauth/me', $captured['url'] );
		$this->assertSame( 'Bearer TOK', $captured['args']['headers']['Authorization'] );
		$this->assertArrayHasKey( 'X-Woodev-Signature', $captured['args']['headers'] );
		$this->assertArrayHasKey( 'X-Woodev-Timestamp', $captured['args']['headers'] );

		// Signature must equal an independent HMAC over the canonical GET payload.
		$ts       = $captured['args']['headers']['X-Woodev-Timestamp'];
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'woodev.ru',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
					'method'      => 'GET',
					'body'        => '',
					'timestamp'   => (string) $ts,
				)
			),
			'SECRET'
		);
		$this->assertSame( $expected, $captured['args']['headers']['X-Woodev-Signature'] );
	}

	public function test_request_returns_wp_error_on_http_failure(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'TOK', 'access_token_secret' => 'S', 'url' => 'https://woodev.ru' ) )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );

		$out = ( new \Woodev_Account_Connection() )->request( 'GET', '/oauth/me' );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_request_without_connection_returns_wp_error(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$out = ( new \Woodev_Account_Connection() )->request( 'GET', '/oauth/me' );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_disconnect_clears_option_even_when_remote_errors(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'TOK', 'access_token_secret' => 'S', 'url' => 'https://woodev.ru' ) )
		);
		// Remote invalidate fails hard.
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );

		// The local option MUST be deleted regardless.
		Functions\expect( 'delete_option' )->once()->with( \Woodev_Account_Connection::OPTION_KEY )->andReturn( true );

		$this->assertTrue( ( new \Woodev_Account_Connection() )->disconnect() );
	}

	public function test_canonical_for_drops_default_ports_keeps_explicit(): void {
		$conn = new \Woodev_Account_Connection();

		// Default https :443 must be omitted (RFC clients omit it from Host).
		$https = $this->call_private(
			$conn,
			'canonical_for',
			array( 'https://woodev.ru:443/wp-json/woodev-account/v1/oauth/me', 'GET', '', '1' )
		);
		$this->assertSame( 'woodev.ru', $https['host'] );

		// Default http :80 must be omitted.
		$http = $this->call_private(
			$conn,
			'canonical_for',
			array( 'http://woodev.ru:80/x', 'GET', '', '1' )
		);
		$this->assertSame( 'woodev.ru', $http['host'] );

		// A non-default port (rig issuer) is kept.
		$rig = $this->call_private(
			$conn,
			'canonical_for',
			array( 'http://localhost:8090/wp-json/woodev-account/v1/oauth/me', 'GET', '', '1' )
		);
		$this->assertSame( 'localhost:8090', $rig['host'] );
	}

	public function test_request_returns_error_when_body_encode_fails(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'TOK', 'access_token_secret' => 'S', 'url' => 'https://woodev.ru' ) )
		);
		// Simulate a non-encodable body (e.g. invalid UTF-8).
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$out = ( new \Woodev_Account_Connection() )->request( 'POST', '/oauth/test', array( 'x' => "\xB1\x31" ) );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'woodev_account_encode_error', $out->get_error_code() );
	}

	public function test_exchange_token_rejects_tokens_without_secret(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// Connector returns an access_token but NO access_token_secret.
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"access_token":"x","site_id":"s"}' );
		Functions\when( 'wp_safe_remote_post' )->justReturn( array() );

		// A broken exchange must NOT persist any connection state.
		Functions\expect( 'update_option' )->never();

		$conn   = new \Woodev_Account_Connection();
		$result = $this->call_private( $conn, 'exchange_token', array( 'sec', 'rtok' ) );

		$this->assertFalse( $result );
	}
}
