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

		$response = ( new \Woodev_REST_API_Account() )->handle_disconnect();

		$this->assertFalse( $response['connected'] );
	}
}
