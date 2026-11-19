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
import { Tooltip } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { STYLES } from './styles';
import type { BadgeCell, CellType, ImageCell, LinkCell, ProgressCell, StackItem } from './types';

type CellRenderer = ( value: unknown ) => ReactNode;

const DATE_ONLY_LENGTH = 'YYYY-MM-DD'.length;
const DATE_FORMAT = 'd.m.Y';
const DATETIME_FORMAT = 'd.m.Y H:i';

function TextValue( { value, title }: { value: string | number; title?: string } ) {
	return (
		<span className="woodev-records__text" title={ title ?? String( value ) }>
			{ String( value ) }
		</span>
	);
}

/** A badge longer than this is a sentence (an error text), not a state: it wraps instead of widening the column. */
const LONG_BADGE_LENGTH = 32;

/**
 * The status badge of «Заказы доставки» (the size, the corners and the colours are the stylesheet's). A badge with a
 * `tooltip` explains itself on hover and on keyboard focus: the sub-lines and the errors a cell used to stack under it.
 */
function Badge( { value }: { value: BadgeCell } ) {
	const long = value.label.length > LONG_BADGE_LENGTH;
	const tooltip = value.tooltip ?? '';
	const badge = (
		<span
			className={ `woodev-records__badge is-${ value.tone }${ long ? ' is-long' : '' }${ tooltip === '' ? '' : ' has-tooltip' }` }
			tabIndex={ tooltip === '' ? undefined : 0 }
		>
			{ value.label }
		</span>
	);

	if ( tooltip === '' ) {
		return badge;
	}

	return (
		<Tooltip text={ tooltip } className="woodev-records__tooltip" delay={ 150 }>
			{ badge }
		</Tooltip>
	);
}

/** Whether an address leaves this site: such a link opens in a new tab, so the table stays where it was. */
function isExternal( url: string ): boolean {
	try {
		return new URL( url, window.location.href ).origin !== window.location.origin;
	} catch ( error ) {
		return false;
	}
}

function LinkValue( { value }: { value: LinkCell } ) {
	const external = isExternal( value.url );

	return (
		<a
			className="woodev-records__link"
			href={ value.url }
			title={ value.label }
			target={ external ? '_blank' : undefined }
			rel={ external ? 'noopener noreferrer' : undefined }
		>
			{ value.label }
		</a>
	);
}

/**
 * A date as «15.11.2026 23:24» (a value without a time as «15.11.2026»), in the site's timezone and on one line — the
 * format a site picks for its blog posts is a sentence («November 15, 2026 11:24 pm») that a table cell cannot hold.
 */
function DateValue( { value }: { value: string } ) {
	const format = value.length === DATE_ONLY_LENGTH ? DATE_FORMAT : DATETIME_FORMAT;

	return (
		<time dateTime={ value } style={ STYLES.nowrap }>
			{ dateI18n( format, value, undefined ) }
		</time>
	);
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
	// The full text is the line's `title`: a column may clip a long one (the article, to two lines).
	text: ( value ) => <TextValue value={ value as string } title={ value as string } />,
	badge: ( value ) => <Badge value={ value as BadgeCell } />,
	link: ( value ) => <LinkValue value={ value as LinkCell } />,
	// The card's photo: a small square; the browser reads it only when it scrolls into view.
	image: ( value ) => (
		<img className="woodev-records__thumb" src={ ( value as ImageCell ).url } alt={ ( value as ImageCell ).alt } loading="lazy" />
	),
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
