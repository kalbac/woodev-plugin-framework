/**
 * The row above the table: account selector, search box and one control per filter PHP declared.
 *
 * Controls are chosen by filter type only. Every change is a new query handed to `onChange`, which the table writes
 * to the URL — the controls hold no filter state of their own except the text being typed into the search box.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, FormEvent } from 'react';
import { Button, CheckboxControl, SearchControl, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { MESSAGE_KEYS, joinLabel, message } from './messages';
import {
	MAX_MULTI_VALUES,
	changeFilter,
	changeQuery,
	isSearchText,
	sameFilterValue,
	searchFilter,
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
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ filter.label }
			checked={ value === true }
			onChange={ ( checked: boolean ) => onChange( checked ? true : undefined ) }
		/>
	);
}

function DateRangeFilter( { filter, value, messages, onChange }: FilterControlProps ) {
	const range = ( typeof value === 'object' && ! Array.isArray( value ) ? value : {} ) as DateRange;
	const ends: Array< { end: keyof DateRange; label: string } > = [
		{ end: 'from', label: message( messages, MESSAGE_KEYS.dateFrom ) },
		{ end: 'to', label: message( messages, MESSAGE_KEYS.dateTo ) },
	];

	const change = ( end: keyof DateRange, day: string ) => {
		const next: DateRange = { ...range, [ end ]: day };
		if ( day === '' ) {
			delete next[ end ];
		}

		const isBackwards = next.from !== undefined && next.to !== undefined && next.from > next.to;
		if ( ! isBackwards ) {
			onChange( Object.keys( next ).length === 0 ? undefined : next );
		}
	};

	return (
		<fieldset className="woodev-records__date-range" style={ STYLES.dateRange }>
			<legend>{ filter.label }</legend>
			{ ends.map( ( { end, label } ) => (
				<input
					key={ end }
					type="date"
					aria-label={ joinLabel( filter.label, label ) }
					value={ range[ end ] ?? '' }
					onChange={ ( event ) => change( end, event.target.value ) }
				/>
			) ) }
		</fieldset>
	);
}

const FILTER_CONTROLS: Partial< Record< Filter[ 'type' ], ComponentType< FilterControlProps > > > = {
	select: SelectFilter,
	multi_select: MultiSelectFilter,
	boolean: BooleanFilter,
	date_range: DateRangeFilter,
};

interface SearchBoxProps {
	filter: Filter;
	value: string;
	onSearch: ( text: string ) => void;
}

/** Searches on Enter, and at once when the box is cleared; typing alone reads nothing. */
function SearchBox( { filter, value, onSearch }: SearchBoxProps ) {
	const [ text, setText ] = useState( value );

	useEffect( () => setText( value ), [ value ] );

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
		<form role="search" onSubmit={ submit }>
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

export function FiltersBar( { definition, account, query, facets, messages, onChange }: FiltersBarProps ) {
	const search = searchFilter( definition );
	const resetLabel = message( messages, MESSAGE_KEYS.resetFilters );
	const canReset = resetLabel !== '' && isNarrowed( definition, query );
	const reset = () => onChange( changeQuery( query, { search: '', filters: defaultFilters( definition ) } ) );

	return (
		<div className="woodev-records__toolbar" style={ STYLES.toolbar }>
			<AccountSelect
				definition={ definition }
				account={ account }
				onChange={ ( next ) => onChange( changeQuery( query, { account: next } ) ) }
			/>
			{ search && (
				<SearchBox
					filter={ search }
					value={ query.search }
					onSearch={ ( text ) => onChange( changeQuery( query, { search: text } ) ) }
				/>
			) }
			{ valueFilters( definition ).map( ( filter ) => {
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
