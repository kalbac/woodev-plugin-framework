<?php
/**
 * Woodev_Connection_Result value-object tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';

/**
 * Class ConnectionResultTest.
 */
class ConnectionResultTest extends TestCase {

	/**
	 * The success factory yields a successful result carrying its message.
	 *
	 * @return void
	 */
	public function test_success_factory() {
		$r = \Woodev_Connection_Result::success( 'Подключено' );
		$this->assertTrue( $r->is_success() );
		$this->assertSame( 'Подключено', $r->get_message() );
	}

	/**
	 * The failure factory yields an unsuccessful result carrying its message.
	 *
	 * @return void
	 */
	public function test_failure_factory() {
		$r = \Woodev_Connection_Result::failure( 'Неверный токен' );
		$this->assertFalse( $r->is_success() );
		$this->assertSame( 'Неверный токен', $r->get_message() );
	}

	/**
	 * The REST payload shape carries the success flag and message.
	 *
	 * @return void
	 */
	public function test_to_array_shape() {
		$r = \Woodev_Connection_Result::failure( 'X' );
		$this->assertSame( [ 'success' => false, 'message' => 'X' ], $r->to_array() );
	}
}
