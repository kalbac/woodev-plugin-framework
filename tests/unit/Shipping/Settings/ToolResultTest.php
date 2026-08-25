<?php
/**
 * Unit tests for Tool_Result — deliberately parallel to, but not reusing,
 * Woodev_Connection_Result (issue #505, D1).
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-tool-result.php';

class ToolResultTest extends TestCase {

	public function test_success_carries_message_and_flag(): void {
		$result = Tool_Result::success( 'Готово' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Готово', $result->get_message() );
		$this->assertSame( [ 'success' => true, 'message' => 'Готово' ], $result->to_array() );
	}

	public function test_success_defaults_to_empty_message(): void {
		$result = Tool_Result::success();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $result->get_message() );
	}

	public function test_failure_carries_message_and_flag(): void {
		$result = Tool_Result::failure( 'Ошибка' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'Ошибка', $result->get_message() );
		$this->assertSame( [ 'success' => false, 'message' => 'Ошибка' ], $result->to_array() );
	}
}
