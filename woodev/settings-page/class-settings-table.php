<?php
/**
 * Settings-page table descriptor: a fixed list of rows whose cells are the section's own fields.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One row per thing — a delivery service, a payment method — and one column per property, where a cell is a
 * field of the section (a key, a limit), a line of text, a status badge or a «Проверить» button.
 *
 * It is the fixed-rows sibling of the records table: the merchant never adds or removes a row, the plugin
 * declares them. A table only NAMES its fields by id, like {@see Settings_Group}: values, validation, masked
 * secrets and Save stay with the section, so a table is a layout and nothing else. Attach it with
 * {@see Settings_Group::with_table()}.
 *
 * ```php
 * Settings_Table::create( 'Сервис', [ 'status' => 'Статус', 'key' => 'Ключ API', 'check' => '' ] )
 *     ->with_row( 'mapbox', 'Mapbox', [
 *         'status' => Settings_Table::status(),
 *         'key'    => Settings_Table::field( 'mapbox_key' ),
 *         'check'  => Settings_Table::check( 'mapbox', [ 'mapbox_key' ], 'Проверить' ),
 *     ] );
 * ```
 *
 * Immutable: every `with_*()` returns a copy.
 *
 * @since 2.0.26
 */
final class Settings_Table {
	public const CELL_FIELD  = 'field';
	public const CELL_TEXT   = 'text';
	public const CELL_STATUS = 'status';
	public const CELL_CHECK  = 'check';

	/**
	 * @var string header of the first column, which holds each row's label.
	 */
	private string $row_title;

	/**
	 * @var array<string,string> column id => header, in display order (the row label column comes first, unlisted).
	 */
	private array $columns;

	/**
	 * @var array<string,string> column id => help text shown as a tip icon beside the header; a column without an entry has none.
	 */
	private array $column_tips = [];

	/**
	 * @var array<int,array{id:string,label:string,cells:array<string,array<string,mixed>>}> rows in display order.
	 */
	private array $rows = [];

	/**
	 * Use {@see self::create()} instead.
	 *
	 * @param string               $row_title header of the label column.
	 * @param array<string,string> $columns   column id => header.
	 */
	private function __construct( string $row_title, array $columns ) {
		$this->row_title = $row_title;
		$this->columns   = $columns;
	}

	/**
	 * Builds a table with no rows.
	 *
	 * @since 2.0.26
	 *
	 * @param string               $row_title header of the first column, which names each row («Сервис»).
	 * @param array<string,string> $columns   the other columns, column id => header; an empty header is a column with no title (buttons).
	 * @return self
	 */
	public static function create( string $row_title, array $columns ): self {
		return new self( $row_title, array_map( 'strval', $columns ) );
	}

	/**
	 * A copy with a help tip on one column header: the framework's standard «?» icon, hover and keyboard focus show the text.
	 *
	 * Use it for what applies to the whole column («Лимит в сутки»); a hint for one cell is that field's own `tooltip`.
	 * An unknown column id is ignored; an empty text removes the tip.
	 *
	 * @since 2.0.26
	 *
	 * @param string $column_id id of a column given to {@see self::create()}.
	 * @param string $text      the help text, one or two short sentences.
	 * @return self
	 */
	public function with_column_tip( string $column_id, string $text ): self {
		if ( ! isset( $this->columns[ $column_id ] ) ) {
			return $this;
		}

		$copy = clone $this;

		if ( '' === trim( $text ) ) {
			unset( $copy->column_tips[ $column_id ] );
		} else {
			$copy->column_tips[ $column_id ] = $text;
		}

		return $copy;
	}

	/**
	 * A copy with one more row. A row with the same id replaces the earlier one; a cell under an unknown column is dropped.
	 *
	 * @since 2.0.26
	 *
	 * @param string                            $id    row id, unique within the table.
	 * @param string                            $label what the first column shows.
	 * @param array<string,array<string,mixed>> $cells column id => a cell built by {@see self::field()}, {@see self::text()}, {@see self::status()} or {@see self::check()}.
	 * @return self
	 */
	public function with_row( string $id, string $label, array $cells ): self {
		$copy       = clone $this;
		$copy->rows = array_values(
			array_filter(
				$this->rows,
				static function ( array $row ) use ( $id ): bool {
					return $row['id'] !== $id;
				}
			)
		);

		$copy->rows[] = [
			'id'    => $id,
			'label' => $label,
			'cells' => array_intersect_key( $cells, $this->columns ),
		];

		return $copy;
	}

	/**
	 * A cell that holds one of the section's fields, drawn as the field's own control without its label.
	 *
	 * @since 2.0.26
	 *
	 * @param string $setting_id id of a field the section declares.
	 * @param string $prefix     optional short text before the control («коэф.»).
	 * @return array<string,mixed>
	 */
	public static function field( string $setting_id, string $prefix = '' ): array {
		return [
			'type'    => self::CELL_FIELD,
			'setting' => $setting_id,
			'prefix'  => $prefix,
		];
	}

	/**
	 * A cell with a line of text.
	 *
	 * @since 2.0.26
	 *
	 * @param string $text what to show.
	 * @param string $tone `ok` for a green line, `''` for plain text.
	 * @return array<string,mixed>
	 */
	public static function text( string $text, string $tone = '' ): array {
		return [
			'type' => self::CELL_TEXT,
			'text' => $text,
			'tone' => 'ok' === $tone ? 'ok' : '',
		];
	}

	/**
	 * A cell with the state of the row's {@see self::check()} cell: «подключён», «не подключён» or «ошибка».
	 *
	 * @since 2.0.26
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		return [ 'type' => self::CELL_STATUS ];
	}

	/**
	 * A cell with a button that runs the handler's connection test and shows the answer under the row.
	 *
	 * The test is the one a connection section runs ({@see \Woodev_Settings_Connection_Test::test_connection()}),
	 * with the typed-but-unsaved values of `$setting_ids` merged over the stored ones; the id is the one that method
	 * receives. The row counts as connected when every one of those fields is filled (a saved secret counts).
	 *
	 * @since 2.0.26
	 *
	 * @param string   $connection_id id passed to the handler's `test_connection()`.
	 * @param string[] $setting_ids   fields the test reads; only these reach it from the browser.
	 * @param string   $label         the button text.
	 * @return array<string,mixed>
	 */
	public static function check( string $connection_id, array $setting_ids, string $label ): array {
		return [
			'type'       => self::CELL_CHECK,
			'connection' => $connection_id,
			'settings'   => array_values( array_unique( array_map( 'strval', $setting_ids ) ) ),
			'label'      => $label,
		];
	}

	/**
	 * @since 2.0.26
	 * @return string
	 */
	public function get_row_title(): string {
		return $this->row_title;
	}

	/**
	 * @since 2.0.26
	 * @return array<string,string> column id => header.
	 */
	public function get_columns(): array {
		return $this->columns;
	}

	/**
	 * @since 2.0.26
	 * @return array<string,string> column id => header help text, for the columns that have one.
	 */
	public function get_column_tips(): array {
		return $this->column_tips;
	}

	/**
	 * @since 2.0.26
	 * @return array<int,array{id:string,label:string,cells:array<string,array<string,mixed>>}>
	 */
	public function get_rows(): array {
		return $this->rows;
	}

	/**
	 * Every field the table's cells name — the fields and the fields each check reads — without repeats.
	 *
	 * @since 2.0.26
	 *
	 * @return string[]
	 */
	public function get_setting_ids(): array {
		$ids = [];

		foreach ( $this->cells() as $cell ) {
			if ( self::CELL_FIELD === ( $cell['type'] ?? '' ) ) {
				$ids[] = (string) $cell['setting'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The connection id of every check cell.
	 *
	 * @since 2.0.26
	 *
	 * @return string[]
	 */
	public function get_check_ids(): array {
		$ids = [];

		foreach ( $this->cells() as $cell ) {
			if ( self::CELL_CHECK === ( $cell['type'] ?? '' ) ) {
				$ids[] = (string) $cell['connection'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The fields a check reads, or null when no cell of this table is that connection.
	 *
	 * @since 2.0.26
	 *
	 * @param string $connection_id id of the check cell.
	 * @return string[]|null
	 */
	public function find_check( string $connection_id ): ?array {
		foreach ( $this->cells() as $cell ) {
			if ( self::CELL_CHECK === ( $cell['type'] ?? '' ) && $connection_id === $cell['connection'] ) {
				return $cell['settings'];
			}
		}

		return null;
	}

	/**
	 * Every cell of every row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cells(): array {
		$cells = [];

		foreach ( $this->rows as $row ) {
			foreach ( $row['cells'] as $cell ) {
				if ( is_array( $cell ) ) {
					$cells[] = $cell;
				}
			}
		}

		return $cells;
	}
}
