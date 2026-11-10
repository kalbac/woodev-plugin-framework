/**
 * Cell renderers, one per `cell_type` PHP may declare.
 *
 * The cell type is the only thing a renderer is chosen by: the vocabulary is finite and generic, and nothing here
 * looks at a table, column or marketplace id. Values arrive normalised and tag-free from `Row_Normalizer`, and are
 * rendered as text — never as HTML. A `null` value is an empty cell.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { dateI18n, getSettings } from '@wordpress/date';
import { STYLES, badgeStyle } from './styles';
import type { BadgeCell, CellType, LinkCell, ProgressCell, StackItem } from './types';

type CellRenderer = ( value: unknown ) => ReactNode;

const DATE_ONLY_LENGTH = 'YYYY-MM-DD'.length;

function TextValue( { value }: { value: string | number } ) {
	return <span>{ String( value ) }</span>;
}

function Badge( { value }: { value: BadgeCell } ) {
	return (
		<span className={ `woodev-records__badge is-${ value.tone }` } style={ badgeStyle( value.tone ) }>
			{ value.label }
		</span>
	);
}

function LinkValue( { value }: { value: LinkCell } ) {
	return <a href={ value.url }>{ value.label }</a>;
}

/** A date in the site's own format; a value without a time is shown without one. */
function DateValue( { value }: { value: string } ) {
	const { formats } = getSettings();
	const format = value.length === DATE_ONLY_LENGTH ? formats.date : formats.datetime;

	return <time dateTime={ value }>{ dateI18n( format, value, undefined ) }</time>;
}

/** Measured progress, or an indeterminate bar when PHP could not measure it. The sentence is always visible. */
function Progress( { value }: { value: ProgressCell } ) {
	const measured = value.percent !== null ? { value: value.percent, max: 100 } : {};

	return (
		<span className="woodev-records__progress" style={ STYLES.stack }>
			<progress aria-label={ value.text } { ...measured } />
			<span>{ value.text }</span>
		</span>
	);
}

const STACK_RENDERERS: Record< StackItem[ 'type' ], CellRenderer > = {
	text: ( value ) => <TextValue value={ value as string } />,
	badge: ( value ) => <Badge value={ value as BadgeCell } />,
	link: ( value ) => <LinkValue value={ value as LinkCell } />,
};

function Stack( { items }: { items: StackItem[] } ) {
	return (
		<span className="woodev-records__stack" style={ STYLES.stack }>
			{ items.map( ( item, index ) => (
				<span key={ index }>{ item.value === null ? null : STACK_RENDERERS[ item.type ]?.( item.value ) }</span>
			) ) }
		</span>
	);
}

const CELL_RENDERERS: Record< Exclude< CellType, 'actions' >, CellRenderer > = {
	text: ( value ) => <TextValue value={ value as string } />,
	number: ( value ) => <TextValue value={ value as number } />,
	date: ( value ) => <DateValue value={ value as string } />,
	badge: ( value ) => <Badge value={ value as BadgeCell } />,
	link: ( value ) => <LinkValue value={ value as LinkCell } />,
	stack: ( value ) => <Stack items={ value as StackItem[] } />,
	progress: ( value ) => <Progress value={ value as ProgressCell } />,
};

/**
 * What a cell shows. An `actions` cell carries no value: the table renders it from the row's own actions.
 *
 * @param cellType The column's declared cell type.
 * @param value    The cell value PHP sent.
 */
export function renderCell( cellType: CellType, value: unknown ): ReactNode {
	if ( value === null || value === undefined || cellType === 'actions' ) {
		return null;
	}

	return CELL_RENDERERS[ cellType ]?.( value ) ?? null;
}

/**
 * A row's name for accessible labels: the text of its first cell that has one.
 *
 * @param cells The row's cells, in column order.
 */
export function cellText( cells: unknown[] ): string {
	for ( const cell of cells ) {
		if ( typeof cell === 'string' || typeof cell === 'number' ) {
			return String( cell );
		}

		const label = ( cell as { label?: unknown } | null )?.label;
		if ( typeof label === 'string' && label !== '' ) {
			return label;
		}
	}

	return '';
}
