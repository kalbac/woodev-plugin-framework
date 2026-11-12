<?php
/**
 * Unit tests for the registry of «Инструменты» tools.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Brain\Monkey\Filters;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Registry;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

final class ToolRegistryTest extends TestCase {
	public function test_keeps_only_tools_and_the_first_tool_of_a_repeated_id(): void {
		$first  = $this->tool( 'rerun', 'Первый' );
		$second = $this->tool( 'rerun', 'Второй' );
		$other  = $this->tool( 'stock', 'Остатки' );

		$registry = new Tool_Registry( [ $first, 'not a tool', $second, $other, null ] );

		$this->assertSame( [ 'rerun', 'stock' ], array_keys( $registry->all() ) );
		$this->assertSame( $first, $registry->get( 'rerun' ) );
		$this->assertSame( $other, $registry->get( 'stock' ) );
		$this->assertNull( $registry->get( 'missing' ) );
	}

	public function test_for_adapter_reads_the_adapter_filter(): void {
		$tool = $this->tool( 'rerun', 'Перенос' );

		Filters\expectApplied( 'woodev_fake_seller_marketplace_tools' )->once()->andReturn( [ $tool ] );

		$this->assertSame( $tool, Tool_Registry::for_adapter( 'fake_seller' )->get( 'rerun' ) );
	}

	public function test_for_adapter_treats_a_filter_that_returns_no_list_as_no_tools(): void {
		Filters\expectApplied( 'woodev_fake_seller_marketplace_tools' )->once()->andReturn( null );

		$this->assertSame( [], Tool_Registry::for_adapter( 'fake_seller' )->all() );
	}

	private function tool( string $id, string $title ): Tool {
		return Tool::create(
			$id,
			$title,
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'plan',
			static fn ( int $account_id ): Tool_Result => Tool_Result::success()
		);
	}
}
