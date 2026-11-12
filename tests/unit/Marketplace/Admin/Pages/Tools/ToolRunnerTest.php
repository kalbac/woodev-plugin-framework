<?php
/**
 * Unit tests for the order of a confirmed «Инструменты» run around the account claim.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Runner;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

final class ToolRunnerTest extends TestCase {
	public function test_an_unknown_tool_fails_without_touching_the_claim(): void {
		$calls  = [];
		$runner = $this->runner( [], $calls, null );

		$result = $runner->run( 'missing', 3 );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'Такого инструмента нет.', $result->get_message() );
		$this->assertSame( [], $calls );
	}

	public function test_a_tool_without_a_claim_runs_without_taking_one(): void {
		$calls  = [];
		$runner = $this->runner( [ $this->tool( 'check', false, $calls ) ], $calls, null );

		$result = $runner->run( 'check', 3 );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 'run:check:3' ], $calls );
	}

	public function test_a_claim_tool_does_not_run_while_another_run_holds_the_claim(): void {
		$calls  = [];
		$runner = $this->runner( [ $this->tool( 'rerun', true, $calls ) ], $calls, null );

		$result = $runner->run( 'rerun', 3 );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'По аккаунту уже идёт фоновая работа. Запустите инструмент, когда она закончится.', $result->get_message() );
		$this->assertSame( [ 'take:3' ], $calls );
	}

	public function test_a_claim_tool_runs_between_taking_and_releasing_the_claim(): void {
		$calls  = [];
		$runner = $this->runner( [ $this->tool( 'rerun', true, $calls ) ], $calls, new Claim_Fence( 'wp_fake_accounts', 'token' ) );

		$result = $runner->run( 'rerun', 3 );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 'take:3', 'run:rerun:3', 'release:3:token' ], $calls );
	}

	public function test_a_claim_tool_receives_the_fence_and_a_renew_that_extends_the_claim(): void {
		$calls = [];
		$seen  = [];
		$tool  = Tool::create(
			'rerun',
			'Перенос',
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static function ( int $account_id, ?Claim_Fence $fence, ?callable $renew ) use ( &$seen ): Tool_Result {
				$seen = [ $account_id, $fence instanceof Claim_Fence ? $fence->token() : null, null === $renew ? null : $renew() ];

				return Tool_Result::success();
			},
			true
		);

		$this->runner( [ $tool ], $calls, new Claim_Fence( 'wp_fake_accounts', 'token' ) )->run( 'rerun', 3 );

		$this->assertSame( [ 3, 'token', true ], $seen );
		$this->assertSame( [ 'take:3', 'renew:3:token', 'release:3:token' ], $calls );
	}

	public function test_a_tool_without_a_claim_gets_no_fence_and_no_renew(): void {
		$calls = [];
		$seen  = [ 'unset' ];
		$tool  = Tool::create(
			'check',
			'Проверка',
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static function ( int $account_id, ?Claim_Fence $fence, ?callable $renew ) use ( &$seen ): Tool_Result {
				$seen = [ $fence, $renew ];

				return Tool_Result::success();
			}
		);

		$this->runner( [ $tool ], $calls, null )->run( 'check', 3 );

		$this->assertSame( [ null, null ], $seen );
		$this->assertSame( [], $calls );
	}

	public function test_a_site_tool_runs_with_account_zero_and_never_takes_a_claim(): void {
		$calls = [];
		$tool  = Tool::create(
			'migrate',
			'Миграция',
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static function ( int $account_id ) use ( &$calls ): Tool_Result {
				$calls[] = 'run:' . $account_id;

				return Tool_Result::success();
			},
			true,
			false,
			Tool::SCOPE_SITE
		);

		$this->runner( [ $tool ], $calls, new Claim_Fence( 'wp_fake_accounts', 'token' ) )->run( 'migrate', 0 );

		$this->assertSame( [ 'run:0' ], $calls );
	}

	public function test_the_claim_is_released_and_the_merchant_gets_a_sentence_when_the_tool_throws(): void {
		$calls  = [];
		$tool   = Tool::create(
			'rerun',
			'Перенос',
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static function ( int $account_id ) use ( &$calls ): Tool_Result {
				$calls[] = 'run:rerun:' . $account_id;

				throw new \RuntimeException( 'boom secret-key-123' );
			},
			true
		);
		$runner = $this->runner( [ $tool ], $calls, new Claim_Fence( 'wp_fake_accounts', 'token' ) );

		$result = $runner->run( 'rerun', 3 );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'Инструмент остановился из-за ошибки. Попробуйте ещё раз позже.', $result->get_message() );
		$this->assertStringNotContainsString( 'secret-key-123', $result->get_message() );
		$this->assertSame( [ 'take:3', 'run:rerun:3', 'release:3:token' ], $calls );
	}

	/**
	 * @param Tool[]     $tools  Tools of the page.
	 * @param string[]   $calls  Records every call, in order (by reference).
	 * @param Claim_Fence|null $fence What taking the claim gives back; null when another run holds it.
	 */
	private function runner( array $tools, array &$calls, ?Claim_Fence $fence ): Tool_Runner {
		return new Tool_Runner(
			new Tool_Registry( $tools ),
			static function ( int $account_id ) use ( &$calls, $fence ): ?Claim_Fence {
				$calls[] = 'take:' . $account_id;

				return $fence;
			},
			static function ( int $account_id, Claim_Fence $claim ) use ( &$calls ): void {
				$calls[] = 'release:' . $account_id . ':' . $claim->token();
			},
			static function ( int $account_id, Claim_Fence $claim ) use ( &$calls ): bool {
				$calls[] = 'renew:' . $account_id . ':' . $claim->token();

				return true;
			}
		);
	}

	/**
	 * A tool that records its run in the shared call log.
	 *
	 * @param string   $id         Tool id.
	 * @param bool     $takes_claim Whether it takes the claim.
	 * @param string[] $calls      The shared call log (by reference).
	 */
	private function tool( string $id, bool $takes_claim, array &$calls ): Tool {
		return Tool::create(
			$id,
			$id,
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static function ( int $account_id ) use ( &$calls, $id ): Tool_Result {
				$calls[] = 'run:' . $id . ':' . $account_id;

				return Tool_Result::success();
			},
			$takes_claim
		);
	}
}
