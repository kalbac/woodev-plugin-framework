<?php
/**
 * The small values of linking and of the catalogue refresh.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Flows\Link_Resolve_Exception;
use Woodev\Framework\Marketplace\Flows\Refresh_Result;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Refresh_Result
 * @covers \Woodev\Framework\Marketplace\Flows\Link_Resolve_Exception
 */
final class CatalogValuesTest extends TestCase {
	/**
	 * Boots the translation stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A result says what a pass did.
	 *
	 * @return void
	 */
	public function test_a_refresh_result_reports_the_pass(): void {
		$done = new Refresh_Result( 4, [ 'attributes:11:22' => 'нет ответа' ], true );

		$this->assertSame( 4, $done->refreshed() );
		$this->assertSame( [ 'attributes:11:22' => 'нет ответа' ], $done->failures() );
		$this->assertTrue( $done->is_complete() );
		$this->assertFalse( ( new Refresh_Result( 0, [], false ) )->is_complete() );
	}

	/**
	 * The two refusals carry their reason and a text for the merchant, and name no marketplace.
	 *
	 * @return void
	 */
	public function test_a_resolve_refusal_carries_its_reason_and_a_text(): void {
		$foreign = Link_Resolve_Exception::foreign_items();
		$cursor  = Link_Resolve_Exception::broken_cursor();

		$this->assertSame( Link_Resolve_Exception::FOREIGN_ITEMS, $foreign->reason() );
		$this->assertSame( Link_Resolve_Exception::BROKEN_CURSOR, $cursor->reason() );
		$this->assertNotSame( $foreign->getMessage(), $cursor->getMessage() );
		$this->assertNotSame( '', $foreign->getMessage() );
	}
}
