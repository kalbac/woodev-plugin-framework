<?php
/**
 * Connection seam interface tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-test.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-status.php';

/**
 * Class ConnectionInterfacesTest.
 */
class ConnectionInterfacesTest extends TestCase {

	/**
	 * Both seam interfaces exist and an explicit implementor satisfies instanceof.
	 *
	 * @return void
	 */
	public function test_a_handler_can_implement_the_test_interface() {
		$handler = new class() implements \Woodev_Settings_Connection_Test {
			public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
				return 'ok' === ( $values['token'] ?? '' )
					? \Woodev_Connection_Result::success( 'ok' )
					: \Woodev_Connection_Result::failure( 'bad' );
			}
		};

		$this->assertTrue( interface_exists( 'Woodev_Settings_Connection_Test' ) );
		$this->assertTrue( interface_exists( 'Woodev_Settings_Connection_Status' ) );
		$this->assertInstanceOf( \Woodev_Settings_Connection_Test::class, $handler );
		$this->assertTrue( $handler->test_connection( 'api', [ 'token' => 'ok' ] )->is_success() );
	}
}
