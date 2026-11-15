/**
 * The row above the table: account selector, search box and one control per filter PHP declared.
 *
 * Controls are chosen by filter type only. Every change is a new query handed to `onChange`, which the table writes
 * to the URL — the controls hold no filter state of their own except the text being typed into the search box.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, FormEvent, MutableRefObject } from 'react';
import { Button, CheckboxControl, SearchControl, SelectControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { CountLinks, withAllEntry } from '../components/count-links';
import { PeriodPicker, type PeriodLabels } from '../components/period-picker/period-picker';
import { PRESET_IDS, type PresetId } from '../components/period-picker/presets';
import { MESSAGE_KEYS, PERIOD_KEYS, joinLabel, message } from './messages';
import {
	MAX_MULTI_VALUES,
	changeFilter,
	changeQuery,
	isSearchText,
	sameFilterValue,
	valueFilters,
} from './query';
import { STYLES } from './styles';
import type { DateRange, Definition, Facet, Filter, FilterValue, Messages, Option, RecordsQuery } from './types';

interface FilterControlProps {
	filter: Filter;
	value: FilterValue | undefined;
	facets: Facet[];
	messages: Messages;
	onChange: ( value: FilterValue | undefined ) => void;
}

const ANY = '';

function withCount( option: Option, facets: Facet[] ): Option {
	const facet = facets.find( ( entry ) => entry.value === option.value );

	return facet ? { value: option.value, label: `${ option.label } (${ facet.count })` } : option;
}

/**
 * The options of a select-like filter: PHP's inline list, or the list response's facets when the filter declares a
 * source. A selected value that is in neither still gets an entry, so the control never shows "any" for a filter
 * that is applied.
 *
 * @param filter   The filter.
 * @param facets   Its facets from the list response.
 * @param selected The values currently applied.
 */
function filterOptions( filter: Filter, facets: Facet[], selected: string[] ): Option[] {
	const declared: Option[] = filter.options_source ? facets : filter.options;
	const known = new Set( declared.map( ( option ) => option.value ) );
	const orphans = selected
		.filter( ( value ) => ! known.has( value ) )
		.map( ( value ) => ( { value, label: value } ) );

	return [ ...declared.map( ( option ) => withCount( option, facets ) ), ...orphans ];
}

function SelectFilter( { filter, value, facets, messages, onChange }: FilterControlProps ) {
	const selected = typeof value === 'string' ? value : ANY;
	const anyOption = { value: ANY, label: message( messages, MESSAGE_KEYS.filterAny ) || '—' };
	const options = filterOptions( filter, facets, selected === ANY ? [] : [ selected ] );

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ filter.label }
			value={ selected }
			options={ [ anyOption, ...options ] }
			onChange={ ( next: string ) => onChange( next === ANY ? undefined : next ) }
		/>
	);
}

function MultiSelectFilter( { filter, value, facets, onChange }: FilterControlProps ) {
	const selected = Array.isArray( value ) ? value : [];

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			multiple
			label={ filter.label }
			value={ selected }
			options={ filterOptions( filter, facets, selected ) }
			onChange={ ( next: string[] ) => {
				if ( next.length <= MAX_MULTI_VALUES ) {
					onChange( next.length === 0 ? undefined : next );
				}
			} }
		/>
	);
}

/** Ticked sends `true`; unticked sends nothing at all, which is not the same request as `false`. */
function BooleanFilter( { filter, value, onChange }: FilterControlProps ) {
	return (
		<div className="woodev-records__boolean">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ filter.label }
				checked={ value === true }
				onChange={ ( checked: boolean ) => onChange( checked ? true : undefined ) }
			/>
		</div>
	);
}

/**
 * How many days the site's day is ahead of (positive) or behind the browser's own, from the `YYYY-MM-DD` PHP sent when the
 * page loaded; `undefined` when PHP sent none. Kept as a difference, not as a date, so a tab left open past midnight still
 * rolls over — on the site's day, not the browser's.
 *
 * @param day `YYYY-MM-DD`.
 */
function siteDayShift( day: string | undefined ): number | undefined {
	const match = day === undefined ? null : /^(\d{4})-(\d{2})-(\d{2})$/.exec( day );

	if ( ! match ) {
		return undefined;
	}

	const now = new Date();
	const site = Date.UTC( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
	const here = Date.UTC( now.getFullYear(), now.getMonth(), now.getDate() );

	return Math.round( ( site - here ) / 86400000 );
}

/** The site's day as a local date at midnight: the browser's day moved by the load-time difference. */
function siteToday( shift: number | undefined ): Date | undefined {
	if ( shift === undefined ) {
		return undefined;
	}

	const now = new Date();

	return new Date( now.getFullYear(), now.getMonth(), now.getDate() + shift );
}

function periodLabels( filter: Filter, messages: Messages ): PeriodLabels {
	const text = ( key: string ) => messages[ key ] ?? '';
	const presets = Object.fromEntries(
		PRESET_IDS.map( ( preset ) => [ preset, text( PERIOD_KEYS.preset( preset ) ) ] )
	) as Record< PresetId, string >;

	return {
		label: text( PERIOD_KEYS.label ) || filter.label,
		legend: text( PERIOD_KEYS.legend ),
		all: text( PERIOD_KEYS.all ),
		presets,
		custom: text( PERIOD_KEYS.custom ),
		back: text( PERIOD_KEYS.back ),
		apply: text( PERIOD_KEYS.apply ),
		from: joinLabel( filter.label, message( messages, MESSAGE_KEYS.dateFrom ) ),
		to: joinLabel( filter.label, message( messages, MESSAGE_KEYS.dateTo ) ),
	};
}

/** Every `date_range` filter is the period picker: the URL keeps `from~to` days, the control offers named periods. */
function PeriodFilter( { filter, value, messages, onChange }: FilterControlProps ) {
	const range = ( typeof value === 'object' && ! Array.isArray( value ) ? value : {} ) as DateRange;
	// Taken once per definition, applied on every render: the days roll over, the offset to the site's day stays.
	const shift = useMemo( () => siteDayShift( filter.today ), [ filter.today ] );

	return (
		<PeriodPicker
			value={ range }
			today={ siteToday( shift ) }
			labels={ periodLabels( filter, messages ) }
			onChange={ ( next ) => onChange( Object.keys( next ).length === 0 ? undefined : next ) }
		/>
	);
}

const FILTER_CONTROLS: Partial< Record< Filter[ 'type' ], ComponentType< FilterControlProps > > > = {
	select: SelectFilter,
	multi_select: MultiSelectFilter,
	boolean: BooleanFilter,
	date_range: PeriodFilter,
};

export interface SearchBoxProps {
	filter: Filter;
	value: string;
	onSearch: ( text: string ) => void;
	/**
	 * Set by the table when the box had keyboard focus as the table swapped between its rows and its empty card — the
	 * two are different elements, so the box is created again. The new box takes the focus and clears the flag.
	 */
	restoreFocus?: MutableRefObject< boolean >;
}

/** Searches on Enter, and at once when the box is cleared; typing alone reads nothing. */
export function SearchBox( { filter, value, onSearch, restoreFocus }: SearchBoxProps ) {
	const [ text, setText ] = useState( value );
	const form = useRef< HTMLFormElement >( null );

	useEffect( () => setText( value ), [ value ] );

	useEffect( () => {
		if ( restoreFocus?.current ) {
			restoreFocus.current = false;
			form.current?.querySelector( 'input' )?.focus();
		}
	}, [ restoreFocus ] );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		const trimmed = text.trim();

		// Text the server would refuse (too long, control characters) is not written.
		if ( isSearchText( trimmed ) ) {
			onSearch( trimmed );
		}
	};

	const type = ( next: string ) => {
		setText( next );
		if ( next === '' && value !== '' ) {
			onSearch( '' );
		}
	};

	return (
		<form ref={ form } role="search" className="woodev-records__search" onSubmit={ submit }>
			<SearchControl
				__nextHasNoMarginBottom
				label={ filter.label }
				placeholder={ filter.label }
				value={ text }
				onChange={ type }
			/>
		</form>
	);
}

interface AccountSelectProps {
	definition: Definition;
	account: string;
	onChange: ( account: string ) => void;
}

function AccountSelect( { definition, account, onChange }: AccountSelectProps ) {
	const selector = definition.context;
	if ( ! selector || selector.options.length < 2 ) {
		return null;
	}

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ selector.label }
			value={ account }
			options={ selector.options }
			onChange={ onChange }
		/>
	);
}

function defaultFilters( definition: Definition ): Record< string, FilterValue > {
	const entries = valueFilters( definition )
		.filter( ( filter ) => filter.default !== undefined )
		.map( ( filter ) => [ filter.id, filter.default as FilterValue ] );

	return Object.fromEntries( entries );
}

/**
 * Whether the query narrows the table beyond what PHP shows by default.
 *
 * @param definition The table's definition.
 * @param query      The query on screen.
 */
export function isNarrowed( definition: Definition, query: RecordsQuery ): boolean {
	const filtersChanged = valueFilters( definition ).some(
		( filter ) => ! sameFilterValue( query.filters[ filter.id ], filter.default )
	);

	return query.search !== '' || filtersChanged;
}

export interface FiltersBarProps {
	definition: Definition;
	/** The account the server resolved for this definition. */
	account: string;
	query: RecordsQuery;
	facets: Record< string, Facet[] >;
	messages: Messages;
	onChange: ( query: RecordsQuery ) => void;
}

/** The select that is drawn as the counted row above the table, if the definition declares one. */
export function viewsFilter( definition: Definition ): Filter | undefined {
	return definition.filters.find( ( filter ) => filter.type === 'select' && filter.views === true );
}

export function FiltersBar( { definition, account, query, facets, messages, onChange }: FiltersBarProps ) {
	const resetLabel = message( messages, MESSAGE_KEYS.resetFilters );
	const canReset = resetLabel !== '' && isNarrowed( definition, query );
	const reset = () => onChange( changeQuery( query, { search: '', filters: defaultFilters( definition ) } ) );
	const counted = viewsFilter( definition );

	return (
		<div className="woodev-records__toolbar" style={ STYLES.toolbar }>
			<AccountSelect
				definition={ definition }
				account={ account }
				onChange={ ( next ) => onChange( changeQuery( query, { account: next } ) ) }
			/>
			{ valueFilters( definition )
				.filter( ( filter ) => filter !== counted )
				.map( ( filter ) => {
					const Control = FILTER_CONTROLS[ filter.type ];

					return Control ? (
						<Control
							key={ filter.id }
							filter={ filter }
							value={ query.filters[ filter.id ] }
							facets={ facets[ filter.id ] ?? [] }
							messages={ messages }
							onChange={ ( value ) => onChange( changeFilter( query, filter.id, value ) ) }
						/>
					) : null;
				} ) }
			{ canReset && (
				<Button __next40pxDefaultSize variant="tertiary" onClick={ reset }>
					{ resetLabel }
				</Button>
			) }
		</div>
	);
}

export interface ViewsRowProps {
	definition: Definition;
	query: RecordsQuery;
	facets: Record< string, Facet[] >;
	messages: Messages;
	/** The list's total, which is exactly «all» while no view is chosen. */
	total: number | undefined;
	onChange: ( query: RecordsQuery ) => void;
}

/**
 * The counted row of the definition's `views` filter: «Все» (clears the filter) and one link per option with its count
 * from the facets. Nothing until the server has sent the counts.
 */
export function ViewsRow( { definition, query, facets, messages, total, onChange }: ViewsRowProps ) {
	const filter = viewsFilter( definition );
	const facet = filter ? facets[ filter.id ] ?? [] : [];
	const counts = facet.filter( ( entry ) => entry.value !== ANY );

	if ( ! filter || counts.length === 0 ) {
		return null;
	}

	const value = query.filters[ filter.id ];
	const current = typeof value === 'string' ? value : ANY;
	// The options may overlap (a variable product can sit in several states at once), so the sum of the counts can exceed
	// the rows. PHP then sends the real number as the entry with the empty value; the list's own total is the same number
	// while no view is chosen; the sum is the last resort, exact for options that partition the rows.
	const allTotal = facet.find( ( entry ) => entry.value === ANY )?.count ?? ( current === ANY ? total : undefined );

	return (
		<CountLinks
			label={ filter.label }
			entries={ withAllEntry( counts, message( messages, MESSAGE_KEYS.viewsAll ), allTotal ) }
			current={ current }
			onSelect={ ( next ) => onChange( changeFilter( query, filter.id, next === ANY ? undefined : next ) ) }
		/>
	);
}
