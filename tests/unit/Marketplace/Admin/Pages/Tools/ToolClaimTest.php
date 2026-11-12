<?php
/**
 * Unit tests for the claim-disabled state of «Инструменты» and the account activity line.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Mockery;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Catalogue;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Registry;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

final class ToolClaimTest extends TestCase {
	private const NOW = 1794441600; // 2026-11-12T00:00:00Z.

	public function test_only_a_claim_taking_tool_is_disabled_while_the_account_is_busy(): void {
		$this->assertSame( [ 'disabled' => false, 'reason' => '' ], Tool_Catalogue::state( false, false ) );
		$this->assertSame( [ 'disabled' => false, 'reason' => '' ], Tool_Catalogue::state( false, true ) );
		$this->assertSame( [ 'disabled' => false, 'reason' => '' ], Tool_Catalogue::state( true, false ) );

		$busy = Tool_Catalogue::state( true, true );

		$this->assertTrue( $busy['disabled'] );
		$this->assertSame( 'Сейчас по аккаунту идёт фоновая работа. Инструмент станет доступен, когда она закончится.', $busy['reason'] );
	}

	public function test_list_disables_the_claim_tool_only_when_a_run_holds_the_claim(): void {
		$registry = new Tool_Registry( [ $this->tool( 'rerun', true ), $this->tool( 'check', false ) ] );

		$busy = ( new Tool_Catalogue( $registry, $this->activity( '2026-11-12 00:05:00' ) ) )->list( 3 );
		$free = ( new Tool_Catalogue( $registry, $this->activity( '2026-11-11 23:00:00' ) ) )->list( 3 );

		$this->assertSame( [ 'rerun', 'check' ], array_column( $busy, 'id' ) );
		$this->assertTrue( $busy[0]['disabled'] );
		$this->assertFalse( $busy[1]['disabled'] );
		$this->assertSame( '', $busy[1]['disabled_reason'] );
		$this->assertFalse( $free[0]['disabled'] );
		$this->assertArrayNotHasKey( 'run', $busy[0] );
	}

	public function test_a_claim_whose_time_has_passed_does_not_count(): void {
		$this->assertFalse( Tool_Activity::is_held( null, self::NOW ) );
		$this->assertFalse( Tool_Activity::is_held( '', self::NOW ) );
		$this->assertFalse( Tool_Activity::is_held( '2026-11-11 23:59:59', self::NOW ) );
		$this->assertFalse( Tool_Activity::is_held( 'not a date', self::NOW ) );
		$this->assertTrue( Tool_Activity::is_held( '2026-11-12 00:00:01', self::NOW ) );
	}

	public function test_account_line_is_active_with_no_blocked_action_while_a_claim_is_held(): void {
		$line = $this->activity( '2026-11-12 00:05:00' )->account( 3 );

		$this->assertTrue( $line['active'] );
		$this->assertSame( [], $line['blocked_actions'] );
		$this->assertNotSame( '', $line['text'] );
	}

	public function test_account_line_is_idle_when_no_claim_is_held(): void {
		$line = $this->activity( null )->account( 3 );

		$this->assertFalse( $line['active'] );
		$this->assertSame( '', $line['text'] );
	}

	private function activity( ?string $claim_until ): Tool_Activity {
		$db = Mockery::mock( \wpdb::class );
		$db->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$db->shouldReceive( 'get_var' )->andReturn( $claim_until );

		return new Tool_Activity( $db, 'wp_fake_accounts', static fn (): int => self::NOW );
	}

	private function tool( string $id, bool $takes_claim ): Tool {
		return Tool::create(
			$id,
			$id,
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static fn ( int $account_id ): Tool_Result => Tool_Result::success(),
			$takes_claim
		);
	}
}
