<?php
/**
 * Unit tests for the «Инструменты» tool descriptor.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

final class ToolTest extends TestCase {
	public function test_describe_exposes_the_presentation_and_never_the_callbacks(): void {
		$tool = Tool::create(
			'rerun',
			'Перенос данных',
			'Переносит данные заново.',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static fn ( int $account_id ): Tool_Result => Tool_Result::success( 'done' ),
			true
		);

		$this->assertSame(
			[
				'id'          => 'rerun',
				'title'       => 'Перенос данных',
				'description' => 'Переносит данные заново.',
				'button'      => 'Запустить',
				'takes_claim' => true,
			],
			$tool->describe()
		);
		$this->assertSame( 'rerun', $tool->id() );
		$this->assertTrue( $tool->takes_claim() );
	}

	public function test_a_tool_is_remote_unless_it_says_otherwise(): void {
		$this->assertTrue( $this->tool( [] )->is_remote() );
	}

	public function test_a_local_tool_opts_out_of_the_remote_gate(): void {
		$this->assertFalse( $this->tool( [ false, false ] )->is_remote() );
	}

	public function test_a_local_tool_can_still_take_the_claim(): void {
		$tool = $this->tool( [ true, false ] );

		$this->assertTrue( $tool->takes_claim() );
		$this->assertFalse( $tool->is_remote() );
	}

	public function test_summary_and_run_pass_the_chosen_account_to_their_callbacks(): void {
		$seen = [];
		$tool = Tool::create(
			'rerun',
			'Перенос',
			'Описание',
			'Запустить',
			static function ( int $account_id ) use ( &$seen ): string {
				$seen[] = 'plan:' . $account_id;

				return 'Будет перенесено 3 аккаунта.';
			},
			static function ( int $account_id ) use ( &$seen ): Tool_Result {
				$seen[] = 'run:' . $account_id;

				return Tool_Result::failure( 'Не удалось.' );
			}
		);

		$this->assertSame( 'Будет перенесено 3 аккаунта.', $tool->summary( 7 ) );
		$result = $tool->run( 7 );

		$this->assertSame( [ 'plan:7', 'run:7' ], $seen );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'Не удалось.', $result->get_message() );
		$this->assertFalse( $tool->takes_claim() );
	}

	/**
	 * A tool with the trailing `create()` arguments given: `[ takes_claim, remote ]`.
	 *
	 * @param array<int,mixed> $flags Arguments after the run callback.
	 */
	private function tool( array $flags ): Tool {
		return Tool::create(
			'rerun',
			'Перенос',
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static fn ( int $account_id ): Tool_Result => Tool_Result::success(),
			...$flags
		);
	}
}
