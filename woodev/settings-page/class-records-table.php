<?php
/**
 * Server-side reading and checking of a records-table value.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

use Woodev\Framework\Shipping\Location\Location_Record;

defined( 'ABSPATH' ) || exit;

/**
 * Decodes the JSON list a `Woodev_Control::TYPE_RECORDS_TABLE` setting stores and checks it against the
 * control's columns.
 *
 * The browser is not trusted: the same rules the table enforces while the merchant types (required cells,
 * one main row, a readable address) are re-checked here on Save, then the consumer's own row check runs.
 * A consumer reads its saved rows with {@see self::decode()}.
 *
 * @since 2.0.26
 */
final class Records_Table {
	/**
	 * Reads a stored value as a list of rows.
	 *
	 * @since 2.0.26
	 *
	 * @param mixed $value the setting value — a JSON string, `''` for no rows.
	 * @return array<int,array<string,mixed>>|null the rows (empty for `''`), or null when the value is not a JSON list of objects.
	 */
	public static function decode( $value ): ?array {

		if ( ! is_string( $value ) ) {
			return null;
		}

		if ( '' === trim( $value ) ) {
			return [];
		}

		// Objects stay objects on this first pass, so a JSON object root (`{"0":{…}}`) or an array row
		// (`[[…]]`) is told apart from the list of objects the table stores.
		$parsed = json_decode( $value );

		if ( ! is_array( $parsed ) || array_filter( $parsed, static fn( $row ): bool => ! $row instanceof \stdClass ) ) {
			return null;
		}

		return json_decode( $value, true );
	}

	/**
	 * Finds the first thing wrong with a value, in the order the merchant reads the table.
	 *
	 * @since 2.0.26
	 *
	 * @param \Woodev_Control $control the records-table control.
	 * @param mixed           $value   the submitted value.
	 * @return string|null a Russian message, or null when the whole table is acceptable.
	 */
	public static function get_error( \Woodev_Control $control, $value ): ?string {

		$rows = self::decode( $value );

		if ( null === $rows ) {
			return __( 'Не удалось прочитать таблицу. Обновите страницу и повторите.', 'woodev-plugin-framework' );
		}

		foreach ( $rows as $index => $row ) {

			$error = self::get_cell_error( $control->get_columns(), $row ) ?? $control->get_row_error( $row, $index );

			if ( null !== $error ) {
				/* translators: 1: 1-based row number, 2: what is wrong with it. */
				return sprintf( __( 'Строка %1$d: %2$s', 'woodev-plugin-framework' ), $index + 1, $error );
			}
		}

		return self::get_main_error( $control->get_columns(), $rows );
	}

	/**
	 * Checks the cells of one row against the columns.
	 *
	 * @param array<int,array<string,mixed>> $columns the control's columns.
	 * @param array<string,mixed>            $row     the decoded row.
	 * @return string|null the message, or null when every cell is fine.
	 */
	private static function get_cell_error( array $columns, array $row ): ?string {

		foreach ( $columns as $column ) {

			$cell = $row[ $column['id'] ] ?? '';

			if ( \Woodev_Control::COLUMN_MAIN_RADIO === $column['type'] ) {
				if ( ! is_bool( $cell ) && '' !== $cell ) {
					return __( 'Неверное значение.', 'woodev-plugin-framework' );
				}
				continue;
			}

			if ( ! is_string( $cell ) ) {
				return __( 'Неверное значение.', 'woodev-plugin-framework' );
			}

			$is_empty = '' === trim( $cell );

			if ( $is_empty && ! $column['required'] ) {
				continue;
			}

			// An address is picked, never typed: empty and unreadable are the same problem for the merchant.
			if ( \Woodev_Control::COLUMN_LOCATION_PICKER === $column['type'] && ! self::is_location_record( $cell ) ) {
				/* translators: %s: column title. */
				return sprintf( __( 'Выберите «%s» из подсказок.', 'woodev-plugin-framework' ), $column['label'] );
			}

			if ( $is_empty ) {
				/* translators: %s: column title. */
				return sprintf( __( 'Заполните «%s».', 'woodev-plugin-framework' ), $column['label'] );
			}
		}

		return null;
	}

	/**
	 * Whether a location cell holds what the picker stores: a record the framework's own location reader
	 * accepts, with a label to show. Coordinates and other consumer needs are the row validator's business.
	 *
	 * @param string $cell the cell text.
	 * @return bool
	 */
	private static function is_location_record( string $cell ): bool {

		$record = json_decode( $cell, true );

		if ( ! is_array( $record ) || ! is_string( $record['label'] ?? null ) || '' === $record['label'] ) {
			return false;
		}

		try {
			Location_Record::from_array( $record );
		} catch ( \InvalidArgumentException $exception ) {
			return false;
		}

		return true;
	}

	/**
	 * A table with a main-radio column has exactly one main row while it has rows.
	 *
	 * @param array<int,array<string,mixed>> $columns the control's columns.
	 * @param array<int,array<string,mixed>> $rows    the decoded rows.
	 * @return string|null the message, or null when the rule holds (or there is no main column).
	 */
	private static function get_main_error( array $columns, array $rows ): ?string {

		foreach ( $columns as $column ) {

			if ( \Woodev_Control::COLUMN_MAIN_RADIO !== $column['type'] || [] === $rows ) {
				continue;
			}

			$main_rows = array_filter( $rows, static fn( array $row ): bool => true === ( $row[ $column['id'] ] ?? false ) );

			if ( 1 !== count( $main_rows ) ) {
				/* translators: %s: column title. */
				return sprintf( __( 'Отметьте ровно одну строку в колонке «%s».', 'woodev-plugin-framework' ), $column['label'] );
			}
		}

		return null;
	}
}
