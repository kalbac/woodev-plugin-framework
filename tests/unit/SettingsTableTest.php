<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Settings\{Settings_Group, Settings_Section, Settings_Table};

/**
 * The fixed-rows settings table: what a row keeps, what a group and a section learn from it.
 */
class SettingsTableTest extends TestCase {

	private function table(): Settings_Table {
		return Settings_Table::create( 'Сервис', [ 'status' => 'Статус', 'key' => 'Ключ API', 'limit' => 'Лимит', 'check' => '' ] )
			->with_row(
				'line',
				'По прямой',
				[
					'status' => Settings_Table::text( '● работает', 'ok' ),
					'limit'  => Settings_Table::field( 'coefficient', 'коэф.' ),
				]
			)
			->with_row(
				'mapbox',
				'Mapbox',
				[
					'status' => Settings_Table::status(),
					'key'    => Settings_Table::field( 'mapbox_key' ),
					'limit'  => Settings_Table::field( 'mapbox_limit' ),
					'check'  => Settings_Table::check( 'mapbox', [ 'mapbox_key' ], 'Проверить' ),
					'ghost'  => Settings_Table::text( 'no such column' ),
				]
			);
	}

	public function test_a_row_keeps_only_the_cells_of_declared_columns(): void {
		$rows = $this->table()->get_rows();

		$this->assertSame( [ 'line', 'mapbox' ], array_column( $rows, 'id' ) );
		$this->assertSame( [ 'status', 'key', 'limit', 'check' ], array_keys( $rows[1]['cells'] ) );
	}

	public function test_a_row_with_a_known_id_replaces_the_earlier_one(): void {
		$table = $this->table()->with_row( 'line', 'Прямая', [ 'status' => Settings_Table::text( 'x' ) ] );

		$this->assertSame( [ 'mapbox', 'line' ], array_column( $table->get_rows(), 'id' ) );
		$this->assertSame( 'Прямая', $table->get_rows()[1]['label'] );
	}

	public function test_with_row_returns_a_copy(): void {
		$base = Settings_Table::create( 'Сервис', [ 'status' => 'Статус' ] );
		$base->with_row( 'a', 'A', [] );

		$this->assertSame( [], $base->get_rows() );
	}

	public function test_it_lists_the_fields_and_the_checks_its_cells_name(): void {
		$table = $this->table();

		$this->assertSame( [ 'coefficient', 'mapbox_key', 'mapbox_limit' ], $table->get_setting_ids() );
		$this->assertSame( [ 'mapbox' ], $table->get_check_ids() );
		$this->assertSame( [ 'mapbox_key' ], $table->find_check( 'mapbox' ) );
		$this->assertNull( $table->find_check( 'google' ) );
	}

	public function test_a_text_tone_other_than_ok_is_plain(): void {
		$this->assertSame( '', Settings_Table::text( 'x', 'danger' )['tone'] );
		$this->assertSame( 'ok', Settings_Table::text( 'x', 'ok' )['tone'] );
	}

	public function test_a_group_takes_the_table_fields_as_its_members(): void {
		$group = Settings_Group::create( 'services', 'Сервисы' )->with_fields( [ 'extra' ] )->with_table( $this->table() );

		$this->assertSame( [ 'extra', 'coefficient', 'mapbox_key', 'mapbox_limit' ], $group->get_setting_ids() );
		$this->assertInstanceOf( Settings_Table::class, $group->get_table() );
		$this->assertNull( Settings_Group::create( 'plain', 'Plain' )->get_table() );
	}

	public function test_a_section_reports_the_checks_of_its_group_tables(): void {
		$section = Settings_Section::create( 'services', 'Сервисы', [ 'coefficient', 'mapbox_key', 'mapbox_limit' ] )
			->with_groups( [ Settings_Group::create( 'services', 'Сервисы' )->with_table( $this->table() ), Settings_Group::create( 'other', 'Other' ) ] );

		$this->assertSame( [ 'mapbox' ], $section->get_table_check_ids() );
		$this->assertSame( [ 'mapbox_key' ], $section->find_table_check( 'mapbox' ) );
		$this->assertNull( $section->find_table_check( 'unknown' ) );
		$this->assertSame( [], Settings_Section::create( 'plain', 'Plain', [] )->get_table_check_ids() );
	}
}
