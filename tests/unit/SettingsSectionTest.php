<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Settings\Settings_Section;


class SettingsSectionTest extends TestCase {

	public function test_create_exposes_id_label_and_setting_ids(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'api_key', 'mode' ] );

		$this->assertSame( 'general', $section->get_id() );
		$this->assertSame( 'Общие', $section->get_label() );
		$this->assertSame( [ 'api_key', 'mode' ], $section->get_setting_ids() );
	}

	public function test_setting_ids_are_reindexed(): void {
		$section = Settings_Section::create( 'x', 'X', [ 2 => 'a', 5 => 'b' ] );

		$this->assertSame( [ 'a', 'b' ], $section->get_setting_ids() );
	}

	public function test_section_defaults_to_non_connection(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'a' ] );

		$this->assertFalse( $section->is_connection() );
		$this->assertSame( '', $section->get_action_label() );
	}

	public function test_connection_section_carries_action_label(): void {
		$section = Settings_Section::create(
			'api', 'Подключение', [ 'token' ], 'Креды API.', true, 'Проверить'
		);

		$this->assertTrue( $section->is_connection() );
		$this->assertSame( 'Проверить', $section->get_action_label() );
	}

	public function test_section_defaults_to_non_tools(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'a' ] );

		$this->assertFalse( $section->is_tools() );
		$this->assertSame( [], $section->get_tools() );
	}

	public function test_tools_section_carries_tool_descriptors(): void {
		$tool    = new class() {
			public function to_array(): array {
				return [ 'id' => 'noop' ];
			}
		};
		$section = Settings_Section::create(
			'tools', 'Инструменты', [], 'Действия.', false, '', true, [ $tool ]
		);

		$this->assertTrue( $section->is_tools() );
		$this->assertSame( [ $tool ], $section->get_tools() );
	}

	public function test_tools_are_reindexed(): void {
		$section = Settings_Section::create(
			'tools', 'Инструменты', [], '', false, '', true, [ 5 => 'a', 9 => 'b' ]
		);

		$this->assertSame( [ 'a', 'b' ], $section->get_tools() );
	}
}
