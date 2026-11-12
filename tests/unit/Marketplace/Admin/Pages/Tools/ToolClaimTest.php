<?php
/**
 * Unit tests for the claim-disabled state of «Инструменты» and the account activity line.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Catalogue;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tools_Module;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
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

		$busy = ( new Tool_Catalogue( $registry, $this->activity( '2026-11-12 00:05:00' ), $this->no_hold() ) )->list( 3 );
		$free = ( new Tool_Catalogue( $registry, $this->activity( '2026-11-11 23:00:00' ), $this->no_hold() ) )->list( 3 );

		$this->assertSame( [ 'rerun', 'check' ], array_column( $busy, 'id' ) );
		$this->assertTrue( $busy[0]['disabled'] );
		$this->assertFalse( $busy[1]['disabled'] );
		$this->assertSame( '', $busy[1]['disabled_reason'] );
		$this->assertFalse( $free[0]['disabled'] );
		$this->assertArrayNotHasKey( 'run', $busy[0] );
	}

	public function test_a_hold_disables_a_remote_tool_before_the_claim_does(): void {
		$held = Tool_Catalogue::state( false, false, 'Лицензия не действует.' );

		$this->assertSame( [ 'disabled' => true, 'reason' => 'Лицензия не действует.' ], $held );
		$this->assertSame( 'Лицензия не действует.', Tool_Catalogue::state( true, true, 'Лицензия не действует.' )['reason'] );
		$this->assertFalse( Tool_Catalogue::state( false, false, null )['disabled'] );
		$this->assertFalse( Tool_Catalogue::state( false, false, '' )['disabled'] );
	}

	public function test_list_and_state_for_agree_a_remote_tool_is_held_and_a_local_tool_is_not(): void {
		$remote   = $this->tool( 'push', false );
		$local    = $this->local_tool( 'migrate' );
		$registry = new Tool_Registry( [ $remote, $local ] );
		$catalog  = new Tool_Catalogue( $registry, $this->activity( null ), static fn ( int $account_id ): ?string => 'Лицензия не действует.' );

		$rows = $catalog->list( 3 );

		$this->assertTrue( $rows[0]['disabled'] );
		$this->assertSame( 'Лицензия не действует.', $rows[0]['disabled_reason'] );
		$this->assertFalse( $rows[1]['disabled'] );
		$this->assertSame( $rows[0]['disabled'], $catalog->state_for( $remote, 3 )['disabled'] );
		$this->assertFalse( $catalog->state_for( $local, 3 )['disabled'] );
	}

	public function test_a_site_tool_asks_the_hold_with_account_zero(): void {
		$asked   = [];
		$site    = Tool::create( 'sweep', 'Чистка', 'Описание', 'Запустить', static fn ( int $id ): string => 'plan', static fn ( int $id ): Tool_Result => Tool_Result::success(), false, true, Tool::SCOPE_SITE );
		$catalog = new Tool_Catalogue(
			new Tool_Registry( [ $site ] ),
			$this->activity( null ),
			static function ( int $account_id ) use ( &$asked ): ?string {
				$asked[] = $account_id;

				return null;
			}
		);

		$catalog->state_for( $site, 9 );

		$this->assertSame( [ 0 ], $asked );
	}

	public function test_remote_hold_gives_the_framework_licence_sentence_when_the_licence_is_not_in_force(): void {
		Functions\when( 'admin_url' )->alias( static fn ( string $path ): string => 'https://shop.test/wp-admin/' . $path );
		$hold = Tools_Module::remote_hold( new License_Gate( static fn (): bool => false ), static fn ( int $account_id ): int => 0 );

		$reason = $hold( 3 );

		$this->assertStringContainsString( 'Лицензия плагина не действует, поэтому нельзя запустить инструмент, который обращается к маркетплейсу.', (string) $reason );
		$this->assertStringContainsString( 'page=woodev-licenses', (string) $reason );
	}

	public function test_remote_hold_holds_a_paused_account_but_not_a_site_tool(): void {
		$hold = Tools_Module::remote_hold( new License_Gate( static fn (): bool => true ), static fn ( int $account_id ): int => 600 );

		$this->assertStringContainsString( 'приостановлена', (string) $hold( 3 ) );
		$this->assertNull( $hold( 0 ) );
	}

	public function test_remote_hold_lets_an_active_account_with_a_valid_licence_go(): void {
		$hold = Tools_Module::remote_hold( new License_Gate( static fn (): bool => true ), static fn ( int $account_id ): int => 0 );

		$this->assertNull( $hold( 3 ) );
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

	private function no_hold(): callable {
		return static fn ( int $account_id ): ?string => null;
	}

	private function local_tool( string $id ): Tool {
		return Tool::create(
			$id,
			$id,
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static fn ( int $account_id ): Tool_Result => Tool_Result::success(),
			false,
			false
		);
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
