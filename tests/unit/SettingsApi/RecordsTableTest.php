<?php
/** Tests for the generic records-table settings control: registration, schema and server-side row checks. */
namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\{Field_Schema, Records_Table};
use Woodev\Tests\Unit\TestCase;

final class RecordsTableTest extends TestCase {

	public const COLUMNS = [
		[ 'id' => 'name', 'type' => 'text', 'label' => 'Название', 'required' => true ],
		[ 'id' => 'address', 'type' => 'location-picker', 'label' => 'Адрес', 'required' => true ],
		[ 'id' => 'is_main', 'type' => 'main-radio', 'label' => 'Основной' ],
	];

	private const PLACE = '{"key":"dadata:1","provider_id":"dadata","level":"address","country":"RU","label":"г Москва, Цветной б-р, д 22"}';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( $defaults, $args ) );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.test/wp-json/' . $path );
	}

	/**
	 * @param callable|null $row_validator the consumer's row check.
	 */
	private function handler( ?callable $row_validator = null ): \Woodev_Abstract_Settings {
		return new class( $row_validator ) extends \Woodev_Abstract_Settings {
			private $row_validator;

			public function __construct( ?callable $row_validator ) {
				$this->row_validator = $row_validator;
				parent::__construct( 'records_test' );
			}

			protected function register_settings() {
				$this->register_setting( 'warehouses', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Склады', 'default' => '' ] );
				$this->register_control( 'warehouses', \Woodev_Control::TYPE_RECORDS_TABLE, [
					'columns'       => RecordsTableTest::COLUMNS,
					'row_validator' => $this->row_validator,
					'add_label'     => 'Добавить склад',
					'empty_text'    => 'Складов пока нет.',
				] );
				$this->register_setting( 'hide_settlement', \Woodev_Setting::TYPE_BOOLEAN, [
					'default'     => false,
					'disabled_if' => [ 'setting' => 'warehouses', 'operator' => 'count', 'comparison' => '>', 'value' => 1 ],
				] );
				$this->register_control( 'hide_settlement', \Woodev_Control::TYPE_TOGGLE, [] );
			}
		};
	}

	private function error_for( \Woodev_Abstract_Settings $handler, string $value ): ?string {
		return $handler->get_setting( 'warehouses' )->get_validation_error( $value );
	}

	private function row( string $name, bool $main, string $address = self::PLACE ): array {
		return [ 'name' => $name, 'address' => $address, 'is_main' => $main ];
	}

	public function test_control_type_is_registered_and_schema_carries_the_columns_and_texts(): void {
		$handler = $this->handler();
		$this->assertContains( 'records-table', $handler->get_control_types() );

		$schema = Field_Schema::from_handler( $handler )['warehouses'];

		$this->assertSame( 'records-table', $schema['controlType'] );
		$this->assertSame( [ 'name', 'address', 'is_main' ], array_column( $schema['columns'], 'id' ) );
		$this->assertSame( 'address', $schema['columns'][1]['level'] );
		$this->assertSame( 'Добавить склад', $schema['add_label'] );
		$this->assertSame( 'Складов пока нет.', $schema['empty_text'] );
	}

	public function test_count_disabled_if_uses_effective_table_rows_before_save(): void {
		$handler = $this->handler();
		$two     = json_encode( [ $this->row( 'one', false ), $this->row( 'two', false ) ] );
		$one     = json_encode( [ $this->row( 'one', false ) ] );

		$this->assertArrayNotHasKey( 'hide_settlement', $handler->filter_visible_values( [ 'warehouses' => $two, 'hide_settlement' => true ] ) );
		$this->assertSame( [ 'warehouses' => $one, 'hide_settlement' => true ], $handler->filter_visible_values( [ 'warehouses' => $one, 'hide_settlement' => true ] ) );
	}

	public function test_registration_is_refused_without_columns_or_for_a_non_string_setting(): void {
		Functions\expect( '_doing_it_wrong' )->twice();

		$handler = $this->handler();
		$handler->register_setting( 'no_columns', \Woodev_Setting::TYPE_STRING );
		$this->assertFalse( $handler->register_control( 'no_columns', 'records-table', [] ) );

		$handler->register_setting( 'a_number', \Woodev_Setting::TYPE_INTEGER );
		$this->assertFalse( $handler->register_control( 'a_number', 'records-table', [ 'columns' => self::COLUMNS ] ) );
	}

	public function test_a_duplicate_column_id_or_unknown_column_type_is_refused(): void {
		$control = new \Woodev_Control();

		foreach ( [
			[ [ 'id' => 'a', 'type' => 'text' ], [ 'id' => 'a', 'type' => 'text' ] ],
			[ [ 'id' => 'a', 'type' => 'select' ] ],
		] as $columns ) {
			try {
				$control->set_columns( $columns );
				$this->fail( 'set_columns() accepted a bad definition' );
			} catch ( \Woodev_Plugin_Exception $exception ) {
				$this->assertSame( [], $control->get_columns() );
			}
		}
	}

	/**
	 * #1603: only an address column keeps a `provider`, normalised like a registry id; a column
	 * without one — or any other column type — carries an empty string. An id nobody registered is
	 * kept here (the registry is not built yet at schema time) and refused by the search route.
	 */
	public function test_only_an_address_column_keeps_its_provider(): void {
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );

		$control = new \Woodev_Control();
		$control->set_columns( [
			[ 'id' => 'name', 'type' => 'text', 'provider' => 'dadata' ],
			[ 'id' => 'address', 'type' => 'location-picker', 'provider' => ' DaData ' ],
			[ 'id' => 'other', 'type' => 'location-picker' ],
			[ 'id' => 'junk', 'type' => 'location-picker', 'provider' => [ 'dadata' ] ],
		] );

		$this->assertSame( [ '', 'dadata', '', '' ], array_column( $control->get_columns(), 'provider' ) );
	}

	public function test_no_rows_is_valid_in_both_spellings(): void {
		$handler = $this->handler();

		$this->assertNull( $this->error_for( $handler, '' ) );
		$this->assertNull( $this->error_for( $handler, '[]' ) );
		$this->assertSame( [], Records_Table::decode( '' ) );
	}

	public function test_a_well_formed_table_with_one_main_row_is_valid(): void {
		$rows = [ $this->row( 'Основной склад', true ), $this->row( 'Склад на Ленина', false ) ];

		$this->assertNull( $this->error_for( $this->handler(), (string) json_encode( $rows ) ) );
	}

	public function test_an_unreadable_value_is_refused(): void {
		$handler = $this->handler();

		foreach ( [ 'not json', '{"a":1}', '[1,2]', '"text"', '{}', '{"0":{"name":"x"}}', '[["x"]]' ] as $value ) {
			$this->assertSame( 'Не удалось прочитать таблицу. Обновите страницу и повторите.', $this->error_for( $handler, $value ), $value );
		}
	}

	public function test_a_required_cell_must_be_filled_and_the_message_names_the_row(): void {
		$rows = [ $this->row( 'Основной склад', true ), $this->row( '  ', false ) ];

		$this->assertSame( 'Строка 2: Заполните «Название».', $this->error_for( $this->handler(), (string) json_encode( $rows ) ) );
	}

	public function test_an_address_must_be_a_picked_record(): void {
		$rows = [ $this->row( 'Склад', true, 'ул Ленина, просто текст' ) ];

		$this->assertSame( 'Строка 1: Выберите «Адрес» из подсказок.', $this->error_for( $this->handler(), (string) json_encode( $rows ) ) );
	}

	public function test_an_address_the_location_reader_would_refuse_is_refused(): void {
		$handler = $this->handler();

		foreach ( [
			'{"label":"arbitrary POST text"}',
			'{"key":"dadata:1","provider_id":"other","level":"address","country":"RU","label":"x"}',
			'{"key":"dadata:1","provider_id":"dadata","level":"address","country":"Russia","label":"x"}',
			'{"key":"dadata:1","provider_id":"dadata","level":"address","country":"RU"}',
		] as $address ) {
			$this->assertSame(
				'Строка 1: Выберите «Адрес» из подсказок.',
				$this->error_for( $handler, (string) json_encode( [ $this->row( 'A', true, $address ) ] ) ),
				$address
			);
		}
	}

	public function test_a_required_table_with_no_rows_is_empty_however_the_empty_list_is_spelled(): void {
		$handler = $this->handler();
		$handler->register_setting( 'needed', \Woodev_Setting::TYPE_STRING, [ 'required' => true ] );
		$handler->register_control( 'needed', \Woodev_Control::TYPE_RECORDS_TABLE, [ 'columns' => self::COLUMNS ] );

		foreach ( [ '', '[]', '[ ]', " [\n] " ] as $empty ) {
			$this->assertSame( 'Обязательное поле.', $handler->get_setting( 'needed' )->get_validation_error( $empty ), json_encode( $empty ) );
		}

		$this->assertNull( $handler->get_setting( 'needed' )->get_validation_error( (string) json_encode( [ $this->row( 'A', true ) ] ) ) );
	}

	public function test_exactly_one_row_must_be_main(): void {
		$handler = $this->handler();
		$none    = [ $this->row( 'A', false ), $this->row( 'B', false ) ];
		$two     = [ $this->row( 'A', true ), $this->row( 'B', true ) ];

		$this->assertSame( 'Отметьте ровно одну строку в колонке «Основной».', $this->error_for( $handler, (string) json_encode( $none ) ) );
		$this->assertSame( 'Отметьте ровно одну строку в колонке «Основной».', $this->error_for( $handler, (string) json_encode( $two ) ) );
	}

	public function test_the_consumer_hook_sees_the_row_and_its_message_reaches_the_merchant(): void {
		$seen    = [];
		$handler = $this->handler(
			static function ( array $row, int $index ) use ( &$seen ) {
				$seen[] = [ $index, $row['name'] ];
				return 'Ленина' === $row['name'] ? 'Не удалось определить координаты адреса.' : true;
			}
		);

		$rows = [ $this->row( 'Основной', true ), $this->row( 'Ленина', false ) ];

		$this->assertSame( 'Строка 2: Не удалось определить координаты адреса.', $this->error_for( $handler, (string) json_encode( $rows ) ) );
		$this->assertSame( [ [ 0, 'Основной' ], [ 1, 'Ленина' ] ], $seen );
	}

	public function test_a_hook_returning_anything_but_true_or_a_message_rejects_with_the_stock_text(): void {
		$handler = $this->handler( static fn( array $row, int $index ) => 1 );

		$this->assertSame( 'Строка 1: Неверное значение.', $this->error_for( $handler, (string) json_encode( [ $this->row( 'A', true ) ] ) ) );
	}

	public function test_a_row_keeps_keys_the_columns_do_not_declare_for_the_consumer(): void {
		$rows    = [ $this->row( 'A', true ) + [ 'id' => 7 ] ];
		$decoded = Records_Table::decode( (string) json_encode( $rows ) );

		$this->assertSame( 7, $decoded[0]['id'] );
	}
}
