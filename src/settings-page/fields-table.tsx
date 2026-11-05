/**
 * Woodev UI-kit — fields table (Settings_Table, 2.0.26).
 *
 * A fixed list of rows, one per thing the plugin declares (a delivery service, say), whose cells are the
 * section's own fields drawn without their labels, a line of text, a status badge or a «Проверить» button.
 * It is only a layout: every field keeps its edit state, validation, masked-secret behaviour and Save in the
 * section, so nothing here stores a value. Rows are never added or removed — see `RecordsTable` for that.
 *
 * A check cell runs the handler's connection test with the typed-but-unsaved values (the same REST route a
 * connection block uses) and shows the answer under its row, so one logical row stays one visual row.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType } from 'react';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import ControlFieldSource from '../components/control-field';
import FieldTip from '../components/field-tip';
import { isFieldFilled } from './connection-block';
import { testConnection } from './rest';

export interface TableResult {
	success: boolean;
	message: string;
}

/** A cell as `Settings_Page_Registry::build_table_cell()` serialises it. */
export interface TableCell {
	type: 'field' | 'text' | 'status' | 'check';
	setting?: string;
	prefix?: string;
	text?: string;
	tone?: string;
	connection?: string;
	settings?: string[];
	label?: string;
	supports_test?: boolean;
	status?: TableResult;
}

export interface TableRow {
	id: string;
	label: string;
	cells: Record< string, TableCell >;
}

export interface TableSchema {
	row_title: string;
	columns: Array< { id: string; label: string; /** Help text for a tip icon beside the header. */ tooltip?: string } >;
	rows: TableRow[];
}

// `ControlField` is a grandfathered JS module whose JSDoc types `Object` as a return type; props are checked by its own tests.
const ControlField = ControlFieldSource as unknown as ComponentType< Record< string, unknown > >;

type Fields = Record< string, Record< string, unknown > >;
type Values = Record< string, unknown >;
type RowState = 'none' | 'ok' | 'error';

/** The row's check cell, when it has one. */
export const findCheck = ( row: TableRow ): TableCell | undefined => Object.values( row.cells ).find( ( cell ) => 'check' === cell.type );

/**
 * What the status cell says: «не подключён» until every field the check reads is filled, «ошибка» when the
 * latest check failed, «подключён» otherwise. The latest check is the one just run, else the one the server
 * remembered; an edit of a field the check reads drops both.
 */
export function rowState( check: TableCell | undefined, fields: Fields, values: Values, latest: TableResult | null | undefined ): RowState {
	if ( ! check ) {
		return 'none';
	}

	const filled = ( check.settings || [] ).every( ( id ) => ! fields[ id ] || isFieldFilled( fields[ id ], values[ id ] ) );

	if ( ! filled ) {
		return 'none';
	}

	return latest && ! latest.success ? 'error' : 'ok';
}

const STATE_TEXT: Record< RowState, () => string > = {
	none: () => __( '○ Не подключён', 'woodev-plugin-framework' ),
	ok: () => __( '● Подключён', 'woodev-plugin-framework' ),
	error: () => __( '✖ Ошибка', 'woodev-plugin-framework' ),
};

interface FieldsTableProps {
	providerId: string;
	table: TableSchema;
	/** The section's field schemas by setting id. */
	fields: Fields;
	/** The ids `show_if` leaves visible; a field cell outside the list stays empty. Absent: every field is drawn. */
	visibleFieldIds?: string[];
	/** The tab's staged edits. */
	values: Values;
	conditionValues?: Values;
	onFieldChange: ( settingId: string, value: unknown ) => void;
	onFieldRevert: ( settingId: string ) => void;
	showErrors?: boolean;
	serverErrors?: Record< string, string >;
	/** Runs one check; defaults to the REST connection test. The UI-kit gallery passes a stub. */
	onCheck?: ( connection: string, values: Values ) => Promise< TableResult >;
}

/**
 * @param {FieldsTableProps} props component props.
 * @return {JSX.Element} the table.
 */
export default function FieldsTable( { providerId, table, fields, visibleFieldIds, values, conditionValues, onFieldChange, onFieldRevert, showErrors, serverErrors, onCheck }: FieldsTableProps ) {
	// connection id => the latest answer; `null` once an edit made it stale. No entry: the server's remembered answer.
	const [ results, setResults ] = useState< Record< string, TableResult | null > >( {} );
	const [ busy, setBusy ] = useState( '' );
	const columnCount = table.columns.length + 1;

	const latestOf = ( check: TableCell | undefined ): TableResult | null | undefined => {
		if ( ! check || ! check.connection ) {
			return null;
		}
		return check.connection in results ? results[ check.connection ] : check.status;
	};

	/** A changed credential makes the answer about the old one stale — drop it, like a connection block does. */
	const forgetChecksReading = ( settingId: string ) => {
		const stale: Record< string, null > = {};
		table.rows.forEach( ( row ) => {
			const check = findCheck( row );
			if ( check && check.connection && ( check.settings || [] ).includes( settingId ) ) {
				stale[ check.connection ] = null;
			}
		} );
		if ( Object.keys( stale ).length ) {
			setResults( ( prev ) => ( { ...prev, ...stale } ) );
		}
	};

	const run = ( check: TableCell ) => {
		const connection = check.connection || '';
		setBusy( connection );
		setResults( ( prev ) => ( { ...prev, [ connection ]: null } ) );

		( onCheck ? onCheck( connection, values ) : ( testConnection( providerId, connection, values ) as Promise< TableResult > ) )
			.then( ( res: TableResult ) => setResults( ( prev ) => ( { ...prev, [ connection ]: res } ) ) )
			.catch( ( err: { message?: string } ) => setResults( ( prev ) => ( {
				...prev,
				[ connection ]: { success: false, message: ( err && err.message ) || __( 'Ошибка проверки.', 'woodev-plugin-framework' ) },
			} ) ) )
			.finally( () => setBusy( '' ) );
	};

	const renderField = ( row: TableRow, columnLabel: string, cell: TableCell ) => {
		const settingId = cell.setting || '';
		const schema = fields[ settingId ];

		// A field `show_if` hides is not edited (the server drops its value on save): its cell stays empty, so the row keeps its columns.
		if ( ! schema || ( visibleFieldIds && ! visibleFieldIds.includes( settingId ) ) ) {
			return null;
		}

		// A field's own `tooltip` shows as the standard tip icon beside its input. Its `description` stays out: it is HTML
		// meant for a labelled row, and a tip is plain text.
		const tip = String( schema.tooltip || '' );

		return (
			<div className={ `woodev-fields-table__field${ tip ? ' has-tip' : '' }` }>
				{ cell.prefix && <span className="woodev-fields-table__prefix">{ cell.prefix }</span> }
				<ControlField
					settingId={ settingId }
					// The column header names the field, so a cell holds the control alone: no label, description or tip of
					// its own (the tip icon is drawn beside the control below). A stored secret gets the short placeholder and clear link.
					schema={ {
						...schema,
						name: '',
						description: '',
						tooltip: '',
						// The visible label is gone, so the input is named «column: row» — «Ключ API: Mapbox». A cell with a
						// prefix holds a different quantity in the shared column (the coefficient), so the field's own name leads.
						aria_label: `${ cell.prefix ? String( schema.name || columnLabel ) : columnLabel }: ${ row.label }`,
						saved_placeholder: __( '•••••• сохранено', 'woodev-plugin-framework' ),
						clear_label: __( 'Очистить', 'woodev-plugin-framework' ),
						serverError: ( serverErrors || {} )[ settingId ],
					} }
					value={ values[ settingId ] ?? schema.value }
					conditionValues={ conditionValues || values }
					onChange={ ( next: unknown ) => {
						forgetChecksReading( settingId );
						onFieldChange( settingId, next );
					} }
					hasEdit={ Object.prototype.hasOwnProperty.call( values, settingId ) }
					onRevert={ () => {
						forgetChecksReading( settingId );
						onFieldRevert( settingId );
					} }
					showErrors={ !! showErrors }
				/>
				<FieldTip text={ tip } />
			</div>
		);
	};

	const renderCell = ( row: TableRow, columnLabel: string, cell: TableCell | undefined ) => {
		if ( ! cell ) {
			return null;
		}

		switch ( cell.type ) {
			case 'field':
				return renderField( row, columnLabel, cell );
			case 'text':
				return <span className={ `woodev-fields-table__text${ 'ok' === cell.tone ? ' is-ok' : '' }` }>{ cell.text }</span>;
			case 'status': {
				const check = findCheck( row );
				const state = rowState( check, fields, values, latestOf( check ) );
				return <span className={ `woodev-fields-table__status is-${ state }` }>{ STATE_TEXT[ state ]() }</span>;
			}
			case 'check': {
				if ( ! cell.supports_test ) {
					return null;
				}
				const ready = ( cell.settings || [] ).every( ( id ) => ! fields[ id ] || isFieldFilled( fields[ id ], values[ id ] ) );
				return (
					<Button
						variant="secondary"
						isBusy={ busy === cell.connection }
						disabled={ '' !== busy || ! ready }
						onClick={ () => run( cell ) }
					>
						{ cell.label || __( 'Проверить', 'woodev-plugin-framework' ) }
					</Button>
				);
			}
			default:
				return null;
		}
	};

	return (
		<div className="woodev-fields-table">
			<table className="widefat woodev-fields-table__table">
				<thead>
					<tr>
						<th scope="col">{ table.row_title }</th>
						{ table.columns.map( ( column ) => (
							<th key={ column.id } scope="col">
								<span className="woodev-fields-table__head">
									{ column.label || <span className="screen-reader-text">{ __( 'Действия', 'woodev-plugin-framework' ) }</span> }
									<FieldTip text={ column.tooltip } />
								</span>
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ table.rows.map( ( row ) => {
						const answer = latestOf( findCheck( row ) );
						return [
							<tr key={ row.id }>
								<th scope="row" className="woodev-fields-table__row-label">{ row.label }</th>
								{ table.columns.map( ( column ) => (
									<td key={ column.id } className={ `woodev-fields-table__cell is-${ column.id }` }>
										{ renderCell( row, column.label, row.cells[ column.id ] ) }
									</td>
								) ) }
							</tr>,
							answer && answer.message ? (
								<tr key={ `${ row.id }:result` } className="woodev-fields-table__result-row">
									<td colSpan={ columnCount }>
										<span className={ `woodev-fields-table__result is-${ answer.success ? 'ok' : 'error' }` } role="status">
											{ answer.message }
										</span>
									</td>
								</tr>
							) : null,
						];
					} ) }
				</tbody>
			</table>
		</div>
	);
}
