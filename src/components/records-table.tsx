/**
 * Woodev UI-kit — records table (F1, #1598).
 *
 * A settings control for a list of records the merchant adds, edits and deletes — warehouses, connected
 * services, anything with a few cells per row. The control knows nothing about its records: the consumer
 * declares the columns (`Woodev_Control::set_columns()` → `schema.columns`) and checks each row on Save
 * (`set_row_validator()`, message shown under the table by `ControlField`).
 *
 * VALUE: a JSON list of rows keyed by column id (`''` for no rows), like the location-picker's JSON string.
 * Keys no column declares (a consumer's own `id`) are carried through every edit untouched.
 *
 * CELLS: `text`; `location-picker` (the settings-page `LocationPickerField`, storing the picked record's JSON
 * string); `location-country` (a country derived from a picker record, with a form-only choice); `main-radio` (a boolean, exactly one row carries it while rows exist — the first row added is
 * main, and the main row cannot be deleted while others remain).
 *
 * EDITING happens in a form under the table (add and edit are the same form); its «Сохранить» checks the
 * required cells before the row reaches the page's own unsaved state. The whole list is persisted by the
 * page's Save, where the server re-checks everything (`Records_Table::get_error()`).
 *
 * Country columns keep rows sorted by country name; paging, bulk actions and import are not part of this control.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import LocationPickerField, { parseStoredRecord } from './location-picker-field';
import type { LocationLevel } from './location-picker';
import { parseRecordRows } from './record-rows';
import type { RecordRow } from './record-rows';

export { parseRecordRows } from './record-rows';
export type { RecordRow } from './record-rows';

export interface RecordColumn {
	id: string;
	type: 'text' | 'location-picker' | 'location-country' | 'main-radio';
	label: string;
	required?: boolean;
	/** Location-picker cells only: the level a search runs at. */
	level?: LocationLevel;
	/** Location-picker cells only: the suggestion source this cell always searches (e.g. `dadata`). */
	provider?: string;
	placeholder?: string;
	/** Country columns derive their value from this location-picker column. */
	of?: string;
	options?: Array<{ code: string; name: string }>;
	in_scope?: string[];
	/** Server-resolved new-row country and names, including stored countries no longer offered. */
	default?: string;
	names?: Record<string, string>;
}

/** The stored form of a list of rows. */
export function serializeRecordRows( rows: RecordRow[] ): string {
	return rows.length ? JSON.stringify( rows ) : '';
}

const cellText = ( row: RecordRow, column: RecordColumn ): string => {
	const cell = row[ column.id ];

	return 'string' === typeof cell ? cell : '';
};

/** A country is read from the referenced record, never from a separate stored cell. */
const recordCountry = ( row: RecordRow, column: RecordColumn ): string => {
	try {
		const record = JSON.parse( String( row[ column.of || '' ] || '' ) );
		return 'string' === typeof record?.country ? record.country : '';
	} catch ( error ) {
		return '';
	}
};

const countryName = ( column: RecordColumn, code: string ): string => column.names?.[ code ] || code;

/** Stable within a country; a table without country columns keeps its original order. */
const sortRows = ( rows: RecordRow[], columns: RecordColumn[] ): RecordRow[] => {
	const column = columns.find( ( candidate ) => 'location-country' === candidate.type );
	if ( ! column ) {
		return rows;
	}

	return [ ...rows ].sort( ( a, b ) => {
		const first = countryName( column, recordCountry( a, column ) );
		const second = countryName( column, recordCountry( b, column ) );
		return first.localeCompare( second );
	} );
};

const isMain = ( row: RecordRow, column: RecordColumn ): boolean => true === row[ column.id ];

const findMainColumn = ( columns: RecordColumn[] ): RecordColumn | undefined => columns.find( ( column ) => 'main-radio' === column.type );

/** What is wrong with one row's cells, or `null` — the client twin of `Records_Table::get_cell_error()`. */
export function getRowError( columns: RecordColumn[], row: RecordRow ): string | null {
	for ( const column of columns ) {
		if ( 'main-radio' === column.type || 'location-country' === column.type ) {
			continue;
		}

		const text = cellText( row, column );
		const isEmpty = '' === text.trim();

		if ( isEmpty && ! column.required ) {
			continue;
		}

		// An address is picked, never typed: empty and unreadable are the same problem for the merchant.
		if ( 'location-picker' === column.type && 'ok' !== parseStoredRecord( text ).state ) {
			/* translators: %s: column title. */
			return sprintf( __( 'Выберите «%s» из подсказок.', 'woodev-plugin-framework' ), column.label );
		}

		if ( isEmpty ) {
			/* translators: %s: column title. */
			return sprintf( __( 'Заполните «%s».', 'woodev-plugin-framework' ), column.label );
		}
	}

	return null;
}

/** The whole table's error for the page's Save gate — rows first, then the one-main rule. */
export function validateRecordsValue( value: unknown, columns: RecordColumn[] ): string | null {
	const rows = parseRecordRows( value );

	for ( let index = 0; index < rows.length; index++ ) {
		const error = getRowError( columns, rows[ index ] );

		if ( error ) {
			/* translators: 1: 1-based row number, 2: what is wrong with it. */
			return sprintf( __( 'Строка %1$d: %2$s', 'woodev-plugin-framework' ), index + 1, error );
		}
	}

	const main = findMainColumn( columns );

	if ( main && rows.length && 1 !== rows.filter( ( row ) => isMain( row, main ) ).length ) {
		/* translators: %s: column title. */
		return sprintf( __( 'Отметьте ровно одну строку в колонке «%s».', 'woodev-plugin-framework' ), main.label );
	}

	return null;
}

interface Draft {
	/** Position in the list, or `null` for a row being added. */
	index: number | null;
	row: RecordRow;
	/** Form-only countries are kept out of the stored row. */
	countries: Record<string, string>;
}

export interface RecordsTableProps {
	/** The stored JSON list, or `''`. */
	value: string;
	columns: RecordColumn[];
	/** Group name for the main radios — the setting id keeps two tables on one page apart. */
	name: string;
	/** Store country location cells search in (`schema.country`). */
	country?: string;
	addLabel?: string;
	emptyText?: string;
	disabled?: boolean;
	onChange: ( value: string ) => void;
}

/**
 * @param {RecordsTableProps} props component props.
 * @return {JSX.Element} the table, its «add» button and, while one is open, the row form.
 */
export default function RecordsTable( { value, columns, name, country = '', addLabel, emptyText, disabled = false, onChange }: RecordsTableProps ) {
	const rows = sortRows( parseRecordRows( value ), columns );
	const countryColumns = columns.filter( ( column ) => 'location-country' === column.type );
	const mainColumn = findMainColumn( columns );
	const [ draft, setDraft ] = useState<Draft | null>( null );
	const [ draftError, setDraftError ] = useState<string | null>( null );
	// Row actions wait while a form is open: a delete would shift the row the form is editing.
	const rowsLocked = disabled || null !== draft;

	const openForm = ( next: Omit<Draft, 'countries'> | null ) => {
		const countries: Record<string, string> = {};
		if ( next ) {
			for ( const column of countryColumns ) {
				const storedCountry = recordCountry( next.row, column );
				const options = column.options || [];
				const selected = 1 === options.length ? options[ 0 ].code : storedCountry || column.default || '';
				countries[ column.id ] = selected;
				if ( storedCountry && storedCountry !== selected ) {
					next.row[ column.of || '' ] = '';
				}
			}
		}
		setDraft( next ? { ...next, countries } : null );
		setDraftError( null );
	};

	const addRow = () => {
		const row: RecordRow = {};
		if ( mainColumn && ! rows.length ) {
			row[ mainColumn.id ] = true; // The first row is main.
		}
		openForm( { index: null, row } );
	};

	/** Makes `index` the only main row. */
	const withMain = ( list: RecordRow[], index: number ): RecordRow[] => {
		if ( ! mainColumn ) {
			return list;
		}
		return list.map( ( row, i ) => ( { ...row, [ mainColumn.id ]: i === index } ) );
	};

	const saveDraft = () => {
		// A form left open while the table became disabled must not hand a change to the page.
		if ( ! draft || disabled ) {
			return;
		}

		const error = getRowError( columns, draft.row );
		if ( error ) {
			setDraftError( error );
			return;
		}

		const next = null === draft.index ? [ ...rows, draft.row ] : rows.map( ( row, i ) => ( i === draft.index ? draft.row : row ) );
		const savedIndex = null === draft.index ? next.length - 1 : draft.index;
		const becameMain = mainColumn && isMain( draft.row, mainColumn );

		onChange( serializeRecordRows( sortRows( becameMain ? withMain( next, savedIndex ) : next, columns ) ) );
		openForm( null );
	};

	const deleteRow = ( index: number ) => {
		onChange( serializeRecordRows( rows.filter( ( _row, i ) => i !== index ) ) );
	};

	const cell = ( row: RecordRow, column: RecordColumn, index: number ) => {
		if ( 'location-country' === column.type ) {
			const code = recordCountry( row, column );
			return (
				<>
					{ countryName( column, code ) }
					{ code && ! ( column.in_scope || [] ).includes( code ) && (
						<div>{ __( 'страна не входит в доставку — на оформлении не показывается', 'woodev-plugin-framework' ) }</div>
					) }
				</>
			);
		}

		if ( 'main-radio' === column.type ) {
			return (
				<input
					type="radio"
					name={ name + '-' + column.id }
					checked={ isMain( row, column ) }
					disabled={ rowsLocked }
					aria-label={ `${ column.label }, ${ index + 1 }` }
					onChange={ () => onChange( serializeRecordRows( withMain( rows, index ) ) ) }
				/>
			);
		}

		const text = cellText( row, column );

		if ( 'location-picker' === column.type && '' !== text ) {
			const stored = parseStoredRecord( text );
			return 'ok' === stored.state ? stored.label : __( 'Некорректное сохранённое значение — выберите заново', 'woodev-plugin-framework' );
		}

		return text;
	};

	/** The main row stays while others remain: deleting it would leave the table without one. */
	const canDelete = ( row: RecordRow ): boolean => ! mainColumn || ! isMain( row, mainColumn ) || 1 === rows.length;

	const formCell = ( column: RecordColumn ) => {
		if ( ! draft ) {
			return null;
		}

		const setCell = ( cellValue: unknown ) => setDraft( { ...draft, row: { ...draft.row, [ column.id ]: cellValue } } );

		if ( 'location-country' === column.type ) {
			const options = column.options || [];
			const selected = draft.countries[ column.id ];
			const changeCountry = ( code: string ) => {
				const row = { ...draft.row };
				if ( recordCountry( row, column ) && recordCountry( row, column ) !== code ) {
					row[ column.of || '' ] = '';
				}
				setDraft( { ...draft, row, countries: { ...draft.countries, [ column.id ]: code } } );
			};

			if ( options.length <= 1 ) {
				return (
					<div>
						<span className="woodev-records__form-label">{ column.label }</span>
						{ countryName( column, selected ) }
					</div>
				);
			}

			const choices = options.map( ( option ) => ( { value: option.code, label: option.name, disabled: false } ) );
			if ( selected && ! options.some( ( option ) => option.code === selected ) ) {
				choices.unshift( { value: selected, label: countryName( column, selected ), disabled: true } );
			}

			return (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ column.label }
					value={ selected }
					options={ choices }
					disabled={ disabled }
					onChange={ changeCountry }
				/>
			);
		}

		if ( 'main-radio' === column.type ) {
			// The main row hands the role over only by another row taking it; the very first row is main.
			const locked = null === draft.index ? ! rows.length : isMain( rows[ draft.index ], column );
			return (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ column.label }
					checked={ isMain( draft.row, column ) }
					disabled={ disabled || locked }
					onChange={ setCell }
				/>
			);
		}

		if ( 'location-picker' === column.type ) {
			const countryColumn = countryColumns.find( ( candidate ) => candidate.of === column.id );
			const pickerCountry = countryColumn ? draft.countries[ countryColumn.id ] : country;
			return (
				<div className="woodev-records__form-field">
					<span className="woodev-records__form-label">{ column.label }</span>
					<LocationPickerField
						value={ cellText( draft.row, column ) }
						country={ pickerCountry }
						level={ column.level || 'address' }
						provider={ column.provider || '' }
						providerPinned={ !! column.provider }
						placeholder={ column.placeholder }
						disabled={ disabled || !! ( countryColumn && ! countryColumn.options?.some( ( option ) => option.code === pickerCountry ) ) }
						onChange={ setCell }
					/>
				</div>
			);
		}

		return (
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ column.label }
				placeholder={ column.placeholder || '' }
				value={ cellText( draft.row, column ) }
				disabled={ disabled }
				onChange={ setCell }
			/>
		);
	};

	return (
		<div className="woodev-records">
			{ rows.length > 0 ? (
				<div className="woodev-records__scroll">
					<table className="widefat woodev-records__table">
						<thead>
							<tr>
								{ columns.map( ( column ) => <th key={ column.id } scope="col">{ column.label }</th> ) }
								<th scope="col"><span className="screen-reader-text">{ __( 'Действия', 'woodev-plugin-framework' ) }</span></th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row, index ) => (
								<tr key={ index }>
									{ columns.map( ( column ) => <td key={ column.id }>{ cell( row, column, index ) }</td> ) }
									<td className="woodev-records__actions">
										<Button
											variant="tertiary"
											disabled={ rowsLocked }
											aria-label={ `${ __( 'Изменить', 'woodev-plugin-framework' ) }, ${ index + 1 }` }
											onClick={ () => openForm( { index, row: { ...row } } ) }
										>
											{ __( 'Изменить', 'woodev-plugin-framework' ) }
										</Button>
										<Button
											variant="tertiary"
											isDestructive
											disabled={ rowsLocked || ! canDelete( row ) }
											title={ canDelete( row ) ? undefined : __( 'Сначала отметьте основной другую строку', 'woodev-plugin-framework' ) }
											aria-label={ `${ __( 'Удалить', 'woodev-plugin-framework' ) }, ${ index + 1 }` }
											onClick={ () => deleteRow( index ) }
										>
											{ __( 'Удалить', 'woodev-plugin-framework' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) : (
				<p className="woodev-records__empty">{ emptyText || __( 'Записей пока нет.', 'woodev-plugin-framework' ) }</p>
			) }

			{ draft ? (
				<div className="woodev-records__form" role="group" aria-label={ null === draft.index ? ( addLabel || __( 'Добавить', 'woodev-plugin-framework' ) ) : __( 'Изменить', 'woodev-plugin-framework' ) }>
					{ [ ...countryColumns, ...columns.filter( ( column ) => 'location-country' !== column.type ) ].map( ( column ) => <div key={ column.id }>{ formCell( column ) }</div> ) }
					{ draftError && <div className="woodev-field__error" role="alert">{ draftError }</div> }
					<div className="woodev-records__form-actions">
						<Button variant="tertiary" onClick={ () => openForm( null ) }>{ __( 'Отмена', 'woodev-plugin-framework' ) }</Button>
						<Button variant="primary" disabled={ disabled } onClick={ saveDraft }>{ __( 'Сохранить', 'woodev-plugin-framework' ) }</Button>
					</div>
				</div>
			) : (
				<Button variant="secondary" disabled={ disabled } onClick={ addRow }>{ addLabel || __( 'Добавить', 'woodev-plugin-framework' ) }</Button>
			) }
		</div>
	);
}
