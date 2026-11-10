/**
 * Turns the definition and the rows on screen into what WooCommerce's `TableCard` takes: headers, and one line of
 * cells per row.
 *
 * `TableCard` has no notion of nested rows and gives a `<tr>` no attributes, so an expanded row's children are
 * ordinary lines placed right after it, marked by an indented first cell, and "loading", "failed" and "show more"
 * are lines too. Two utility columns may lead the declared ones: the expand buttons (when the definition declares
 * children) and the selection checkboxes (when bulk actions are available).
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { Button, Spinner } from '@wordpress/components';
import { cellText, renderCell } from './cells';
import { MESSAGE_KEYS, firstMessage, joinLabel, message } from './messages';
import type { Selection } from './selection';
import { STYLES } from './styles';
import type { AdminRecord, Column, Definition, Messages } from './types';
import type { ChildrenState } from './use-records';
import type { TableCell, TableHeader } from './wc-runtime';

/** Header keys of the utility columns; a column id is a slug and cannot start with an underscore. */
export const EXPAND_COLUMN = '_expand';
export const SELECT_COLUMN = '_select';

export interface TableLine {
	/** Stable across reads, so the row's DOM (and keyboard focus inside it) survives a re-render. */
	key: string;
	cells: TableCell[];
}

export interface TableModelInput {
	definition: Definition;
	messages: Messages;
	rows: AdminRecord[];
	children: Record< string, ChildrenState >;
	expanded: string[];
	/** `null` when the table has no selection column. */
	selection: Selection | null;
	onToggle: ( parentId: string ) => void;
	onMoreChildren: ( parentId: string ) => void;
	renderActions: ( row: AdminRecord, rowKey: string ) => ReactNode;
}

const EMPTY_CELL: TableCell = { display: null, value: '' };

function cell( display: ReactNode ): TableCell {
	return { display, value: '' };
}

function rowName( definition: Definition, row: AdminRecord ): string {
	return cellText( definition.columns.map( ( column ) => row.cells[ column.id ] ) );
}

/** Rows a checkbox can select: the row itself and, for an expanded parent, its loaded children. */
function selectableFamily( row: AdminRecord, children: ChildrenState | undefined ): string[] {
	return [ row, ...( children?.rows ?? [] ) ]
		.filter( ( member ) => member.selectable )
		.map( ( member ) => member.id );
}

/**
 * Ids of every row on screen: top-level rows and the loaded children of expanded ones.
 *
 * @param rows     Top-level rows.
 * @param children Child rows by parent id.
 */
export function visibleRowIds( rows: AdminRecord[], children: Record< string, ChildrenState > ): string[] {
	return rows.flatMap( ( row ) => [ row.id, ...( children[ row.id ]?.rows ?? [] ).map( ( child ) => child.id ) ] );
}

function selectableIds( input: TableModelInput ): string[] {
	return input.rows.flatMap( ( row ) => selectableFamily( row, input.children[ row.id ] ) );
}

interface ExpandButtonProps {
	isOpen: boolean;
	label: string;
	onClick: () => void;
}

function ExpandButton( { isOpen, label, onClick }: ExpandButtonProps ) {
	return (
		<button
			type="button"
			className="button-link woodev-records__expand"
			aria-expanded={ isOpen }
			aria-label={ label }
			onClick={ onClick }
		>
			<span aria-hidden="true">{ isOpen ? '▾' : '▸' }</span>
		</button>
	);
}

interface CheckboxProps {
	label: string;
	checked: boolean;
	indeterminate?: boolean;
	disabled?: boolean;
	onChange: ( checked: boolean ) => void;
}

function Checkbox( { label, checked, indeterminate = false, disabled = false, onChange }: CheckboxProps ) {
	const markMixed = ( input: HTMLInputElement | null ) => {
		if ( input ) {
			input.indeterminate = indeterminate;
		}
	};

	return (
		<input
			type="checkbox"
			ref={ markMixed }
			aria-label={ label }
			checked={ checked }
			disabled={ disabled }
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
	);
}

function expandCell( input: TableModelInput, row: AdminRecord ): TableCell {
	if ( ! row.has_children ) {
		return EMPTY_CELL;
	}

	const isOpen = input.expanded.includes( row.id );
	const verb = message( input.messages, isOpen ? MESSAGE_KEYS.collapse : MESSAGE_KEYS.expand );

	return cell(
		<ExpandButton
			isOpen={ isOpen }
			label={ joinLabel( verb, rowName( input.definition, row ) ) }
			onClick={ () => input.onToggle( row.id ) }
		/>
	);
}

function selectCell( input: TableModelInput, row: AdminRecord, family: string[] ): TableCell {
	const selection = input.selection as Selection;
	const label = joinLabel( message( input.messages, MESSAGE_KEYS.selectRow ), rowName( input.definition, row ) );

	return cell(
		<Checkbox
			label={ label }
			checked={ selection.selectedIds.includes( row.id ) }
			disabled={ ! row.selectable }
			onChange={ ( checked ) => selection.set( family, checked ) }
		/>
	);
}

function dataCells( input: TableModelInput, row: AdminRecord, key: string, isChild: boolean ): TableCell[] {
	return input.definition.columns.map( ( column, index ) => {
		const content =
			column.cell_type === 'actions'
				? input.renderActions( row, key )
				: renderCell( column.cell_type, row.cells[ column.id ] );
		const indent = isChild && index === 0;

		return cell( indent ? <span style={ STYLES.childCell }>{ content }</span> : content );
	} );
}

function leadCells( input: TableModelInput, expand: TableCell, select: TableCell ): TableCell[] {
	const lead: TableCell[] = [];

	if ( input.definition.children ) {
		lead.push( expand );
	}
	if ( input.selection ) {
		lead.push( select );
	}

	return lead;
}

function parentLine( input: TableModelInput, row: AdminRecord ): TableLine {
	const family = selectableFamily( row, input.children[ row.id ] );
	const select = input.selection ? selectCell( input, row, family ) : EMPTY_CELL;

	return {
		key: row.id,
		cells: [ ...leadCells( input, expandCell( input, row ), select ), ...dataCells( input, row, row.id, false ) ],
	};
}

function childLine( input: TableModelInput, parent: AdminRecord, child: AdminRecord ): TableLine {
	const key = `${ parent.id }/${ child.id }`;
	const select = input.selection ? selectCell( input, child, [ child.id ] ) : EMPTY_CELL;

	return { key, cells: [ ...leadCells( input, EMPTY_CELL, select ), ...dataCells( input, child, key, true ) ] };
}

/** A line that spans no data: its first declared column holds a status or a button, the rest stay empty. */
function noteLine( input: TableModelInput, key: string, content: ReactNode ): TableLine {
	const data = input.definition.columns.map( ( _column, index ) =>
		index === 0 ? cell( <span style={ STYLES.childCell }>{ content }</span> ) : EMPTY_CELL
	);

	return { key, cells: [ ...leadCells( input, EMPTY_CELL, EMPTY_CELL ), ...data ] };
}

function childrenNote( input: TableModelInput, parent: AdminRecord, state: ChildrenState ): ReactNode {
	const more = () => input.onMoreChildren( parent.id );

	if ( state.status === 'loading' ) {
		return (
			<span role="status">
				<Spinner /> { message( input.messages, MESSAGE_KEYS.childrenLoading ) }
			</span>
		);
	}

	if ( state.status === 'error' ) {
		return (
			<span role="alert">
				{ state.message || message( input.messages, MESSAGE_KEYS.error ) }{ ' ' }
				<Button variant="link" onClick={ more }>
					{ message( input.messages, MESSAGE_KEYS.retry ) || '↻' }
				</Button>
			</span>
		);
	}

	if ( state.rows.length < state.total ) {
		return (
			<Button variant="link" onClick={ more }>
				{ message( input.messages, MESSAGE_KEYS.childrenMore ) || '…' }
			</Button>
		);
	}

	return null;
}

function childLines( input: TableModelInput, parent: AdminRecord ): TableLine[] {
	const state = input.children[ parent.id ];
	if ( ! state || ! input.expanded.includes( parent.id ) ) {
		return [];
	}

	const lines = state.rows.map( ( child ) => childLine( input, parent, child ) );
	const note = childrenNote( input, parent, state );

	return note === null ? lines : [ ...lines, noteLine( input, `${ parent.id }/_note`, note ) ];
}

/**
 * One line per row on screen, children right after their parent.
 *
 * @param input The table as it is on screen.
 */
export function buildLines( input: TableModelInput ): TableLine[] {
	return input.rows.flatMap( ( row ) => [ parentLine( input, row ), ...childLines( input, row ) ] );
}

function columnHeader( column: Column, messages: Messages ): TableHeader {
	const header: TableHeader = {
		key: column.id,
		label: column.label,
		isSortable: column.sort_key !== null,
		isLeftAligned: column.align !== 'right',
		isNumeric: column.align === 'right',
		required: column.required,
		visible: column.visible,
	};
	const fallbackName = column.cell_type === 'actions' ? message( messages, MESSAGE_KEYS.actionsColumn ) : '';

	if ( column.label === '' && fallbackName !== '' ) {
		header.screenReaderLabel = fallbackName;
	}

	return header;
}

function expandHeader( messages: Messages ): TableHeader {
	const name = firstMessage( messages, [ MESSAGE_KEYS.expandColumn, MESSAGE_KEYS.expand ] );
	const header: TableHeader = { key: EXPAND_COLUMN, label: '', required: true };

	if ( name !== '' ) {
		header.screenReaderLabel = name;
	}

	return header;
}

/** The header is the "select all" checkbox itself, so it must not get a `screenReaderLabel` (that hides the label). */
function selectHeader( input: TableModelInput ): TableHeader {
	const selection = input.selection as Selection;
	const all = selectableIds( input );
	const chosen = all.filter( ( id ) => selection.selectedIds.includes( id ) );

	return {
		key: SELECT_COLUMN,
		required: true,
		label: (
			<Checkbox
				label={ message( input.messages, MESSAGE_KEYS.selectAll ) }
				checked={ all.length > 0 && chosen.length === all.length }
				indeterminate={ chosen.length > 0 && chosen.length < all.length }
				disabled={ all.length === 0 }
				onChange={ ( checked ) => selection.set( all, checked ) }
			/>
		),
	};
}

/**
 * The headers of the table: utility columns first, then every declared column in PHP's order.
 *
 * @param input The table as it is on screen.
 */
export function buildHeaders( input: TableModelInput ): TableHeader[] {
	const lead: TableHeader[] = [];

	if ( input.definition.children ) {
		lead.push( expandHeader( input.messages ) );
	}
	if ( input.selection ) {
		lead.push( selectHeader( input ) );
	}

	return [ ...lead, ...input.definition.columns.map( ( column ) => columnHeader( column, input.messages ) ) ];
}
