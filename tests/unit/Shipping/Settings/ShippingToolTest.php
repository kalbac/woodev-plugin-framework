<?php
/**
 * Unit tests for Shipping_Tool — the «Инструменты» descriptor (issue #505, D1).
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-tool-result.php';

class ShippingToolTest extends TestCase {

	public function test_to_array_never_includes_the_callback(): void {
		$tool = Shipping_Tool::create(
			'noop',
			'Название',
			'Описание',
			'Кнопка',
			static fn( array $args ): Tool_Result => Tool_Result::success()
		);

		$data = $tool->to_array();

		$this->assertArrayNotHasKey( 'callback', $data );
		$this->assertSame(
			[
				'id'          => 'noop',
				'name'        => 'Название',
				'desc'        => 'Описание',
				'button'      => 'Кнопка',
				'disabled'    => false,
				'status_text' => '',
			],
			$data
		);
	}

	public function test_to_array_includes_selector_only_when_declared(): void {
		$without_selector = Shipping_Tool::create( 'a', 'A', '', 'Btn', static fn( array $args ): Tool_Result => Tool_Result::success() );
		$this->assertArrayNotHasKey( 'selector', $without_selector->to_array() );

		$selector    = [
			'description' => 'Провайдер',
			'name'        => 'provider_id',
			'placeholder' => '',
			'options'     => [ [ 'value' => 'dadata', 'label' => 'DaData' ] ],
			'default'     => 'dadata',
		];
		$with_selector = Shipping_Tool::create( 'b', 'B', '', 'Btn', static fn( array $args ): Tool_Result => Tool_Result::success(), false, '', $selector );

		$this->assertSame( $selector, $with_selector->to_array()['selector'] );
	}

	public function test_disabled_and_status_text_are_exposed(): void {
		$tool = Shipping_Tool::create(
			'noop',
			'A',
			'',
			'Btn',
			static fn( array $args ): Tool_Result => Tool_Result::success(),
			true,
			'Недоступно сейчас'
		);

		$this->assertTrue( $tool->is_disabled() );
		$this->assertSame( 'Недоступно сейчас', $tool->get_status_text() );
		$this->assertTrue( $tool->to_array()['disabled'] );
		$this->assertSame( 'Недоступно сейчас', $tool->to_array()['status_text'] );
	}

	public function test_selector_names_empty_without_a_selector(): void {
		$tool = Shipping_Tool::create( 'noop', 'A', '', 'Btn', static fn( array $args ): Tool_Result => Tool_Result::success() );

		$this->assertSame( [], $tool->get_selector_names() );
	}

	public function test_selector_names_carries_the_selector_field_name(): void {
		$selector = [
			'name'    => 'provider_id',
			'options' => [],
		];
		$tool     = Shipping_Tool::create( 'noop', 'A', '', 'Btn', static fn( array $args ): Tool_Result => Tool_Result::success(), false, '', $selector );

		$this->assertSame( [ 'provider_id' ], $tool->get_selector_names() );
	}

	public function test_callback_is_invocable_and_receives_its_args(): void {
		$received = null;
		$tool     = Shipping_Tool::create(
			'noop',
			'A',
			'',
			'Btn',
			static function ( array $args ) use ( &$received ): Tool_Result {
				$received = $args;

				return Tool_Result::success( 'ok' );
			}
		);

		$result = ( $tool->get_callback() )( [ 'provider_id' => 'dadata' ] );

		$this->assertSame( [ 'provider_id' => 'dadata' ], $received );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'ok', $result->get_message() );
	}
}
