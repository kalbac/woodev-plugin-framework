<?php
/**
 * Unit tests for Woodev_REST_API_Account — the disconnect route's capability gate
 * and that the handler clears connection state.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-purchases.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-installer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-account.php';

/**
 * @covers \Woodev_REST_API_Account
 */
final class RestApiAccountTest extends TestCase {

	public function test_permissions_allow_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( ( new \Woodev_REST_API_Account() )->check_permissions() );
	}

	public function test_permissions_deny_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		$result = ( new \Woodev_REST_API_Account() )->check_permissions();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_disconnect_clears_and_returns_disconnected(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
		Functions\when( 'untrailingslashit' )->alias( static function ( $u ) { return rtrim( (string) $u, '/' ); } );
		Functions\when( 'get_option' )->justReturn( false ); // not connected → no remote call.
		Functions\when( 'rest_ensure_response' )->returnArg();

		// The handler must clear the option.
		Functions\expect( 'delete_option' )->once()->with( \Woodev_Account_Connection::OPTION_KEY )->andReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$response = ( new \Woodev_REST_API_Account() )->handle_disconnect();

		$this->assertFalse( $response['connected'] );
	}

	/**
	 * Stubs a connected state + the signed-transport HTTP layer so the internal
	 * Woodev_Account_Connection::request() runs against a canned connector body.
	 *
	 * @param string $body The connector response body the transport returns.
	 * @param int    $code The HTTP status the transport reports.
	 * @return void
	 */
	private function stub_connected_transport( string $body, int $code = 200 ): void {
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}

		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
		Functions\when( 'untrailingslashit' )->alias( static function ( $u ) { return rtrim( (string) $u, '/' ); } );
		Functions\when( 'wp_parse_url' )->alias( static function ( $u ) { return parse_url( (string) $u ); } );
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		// Connected auth state (request() reads get_option(OPTION_KEY)['auth']).
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'tok', 'access_token_secret' => 'sec' ) )
		);
	}

	public function test_purchases_not_connected_returns_empty_without_network(): void {
		Functions\when( 'get_option' )->justReturn( false ); // not connected.
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\expect( 'wp_safe_remote_request' )->never();
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
	}

	public function test_purchases_success_normalizes_and_caches(): void {
		$this->stub_connected_transport(
			'{"purchases":[{"download_id":127940,"slug":"wb","title":"WB","icon":"https://woodev.ru/i.jpg","date":"2024-03-15 10:23:45"}]}'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( 127940, $response['purchases'][0]['id'] );
		$this->assertSame( 'WB', $response['purchases'][0]['title'] );
		$this->assertSame( array( 127940 ), $response['purchased'] );
	}

	public function test_purchases_malformed_payload_marks_stale_uncached(): void {
		// 200 but no "purchases" key → format failure, not "owns nothing".
		$this->stub_connected_transport( '{"unexpected":true}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
		$this->assertTrue( $response['stale'] );
	}

	public function test_purchases_transport_error_marks_stale_uncached(): void {
		$this->stub_connected_transport( '', 500 ); // request() returns WP_Error on non-200.
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) { return $thing instanceof \WP_Error; }
		);
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertTrue( $response['stale'] );
		$this->assertSame( array(), $response['purchases'] );
	}

	public function test_purchases_nonarray_purchases_marks_stale_uncached(): void {
		// 200 with a present-but-non-array "purchases" (e.g. null) is a bad reply,
		// not "owns nothing" — must be stale and uncached (no cache poisoning).
		$this->stub_connected_transport( '{"purchases":null}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
		$this->assertTrue( $response['stale'] );
	}

	public function test_purchases_empty_but_valid_is_cached(): void {
		$this->stub_connected_transport( '{"purchases":[]}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
		$this->assertArrayNotHasKey( 'stale', $response );
	}

	public function test_install_permissions_require_install_plugins(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) { return 'install_plugins' === $cap; }
		);

		$this->assertTrue( ( new \Woodev_REST_API_Account() )->check_install_permissions() );
	}

	public function test_install_permissions_deny_without_install_plugins(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		$result = ( new \Woodev_REST_API_Account() )->check_install_permissions();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_install_returns_error_when_not_connected(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
		Functions\when( 'is_wp_error' )->alias( static function ( $t ) { return $t instanceof \WP_Error; } );
		Functions\when( 'get_option' )->justReturn( false ); // not connected → no upgrader reached.

		$request = new class() {
			public function get_param( $key ) {
				return 'download_id' === $key ? 21 : null;
			}
		};

		$result = ( new \Woodev_REST_API_Account() )->handle_install( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_install_not_connected', $result->get_error_code() );
	}
}
