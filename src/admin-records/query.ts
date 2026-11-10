/**
 * The table query and its two encodings: the page URL and the list request.
 *
 * The URL is the only place the query lives, so a reload, a bookmark and Back/Forward all restore the same table.
 * {@see readQuery} checks every URL value against the definition and falls back to the declared default for anything
 * it does not accept, so the request it leads to is one the server will not refuse. {@see writeQuery} leaves a default
 * out of the URL, and names every key it owns so that a value which went back to its default is removed.
 *
 * A filter that is not set has no entry. For a filter with a PHP default the URL needs a way to say "cleared", which
 * is {@link NO_VALUE} — a character no option value can contain.
 *
 * @package woodev-plugin-framework
 */

import type {
	DateRange,
	Definition,
	Direction,
	Filter,
	FilterValue,
	RecordsContext,
	RecordsQuery,
	RouteQuery,
} from './types';

/** URL value of a filter the merchant cleared although PHP declares a default for it. */
export const NO_VALUE = '~';

/** Prefix of a filter's URL key; the rest is the filter id. */
export const FILTER_KEY_PREFIX = 'filter_';

const URL_KEYS = {
	account: 'account',
	page: 'paged',
	perPage: 'per_page',
	search: 'search',
	sort: 'orderby',
	direction: 'order',
} as const;

const OPAQUE_VALUE = /^[A-Za-z0-9_.:-]{1,191}$/;
const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;
const WHOLE_NUMBER = /^\d{1,9}$/;
const MAX_SEARCH_LENGTH = 200;
const CONTROL_CHARACTER = /[\x00-\x1F\x7F]/;

/** The server's bounds (`Query_Validator::MAX_PAGE` and `::MAX_MULTI_VALUES`): a request beyond them is a 400. */
export const MAX_PAGE = 100000;
export const MAX_MULTI_VALUES = 50;
const LIST_SEPARATOR = ',';

type FilterDecoder = ( filter: Filter, raw: string ) => FilterValue | undefined;
type FilterEncoder = ( value: FilterValue ) => string;

export function filterUrlKey( filterId: string ): string {
	return FILTER_KEY_PREFIX + filterId;
}

/** The filters a merchant sets through `filters[...]`; the search box travels as `search`. */
export function valueFilters( definition: Definition ): Filter[] {
	return definition.filters.filter( ( filter ) => filter.type !== 'search' );
}

export function searchFilter( definition: Definition ): Filter | undefined {
	return definition.filters.find( ( filter ) => filter.type === 'search' );
}

function isKnownOption( filter: Filter, value: string ): boolean {
	if ( ! OPAQUE_VALUE.test( value ) ) {
		return false;
	}

	return filter.options_source || filter.options.some( ( option ) => option.value === value );
}

function isCalendarDay( value: string ): boolean {
	if ( ! ISO_DAY.test( value ) ) {
		return false;
	}

	const parsed = new Date( `${ value }T00:00:00Z` );

	return ! Number.isNaN( parsed.getTime() ) && parsed.toISOString().slice( 0, 10 ) === value;
}

function decodeSelect( filter: Filter, raw: string ): FilterValue | undefined {
	return isKnownOption( filter, raw ) ? raw : undefined;
}

function decodeMultiSelect( filter: Filter, raw: string ): FilterValue | undefined {
	const values = raw.split( LIST_SEPARATOR );
	const distinct = new Set( values );
	const valid =
		values.length <= MAX_MULTI_VALUES &&
		values.every( ( value ) => isKnownOption( filter, value ) ) &&
		distinct.size === values.length;

	return valid ? values : undefined;
}

function decodeBoolean( _filter: Filter, raw: string ): FilterValue | undefined {
	if ( raw === '1' ) {
		return true;
	}

	return raw === '0' ? false : undefined;
}

function decodeDateRange( _filter: Filter, raw: string ): FilterValue | undefined {
	const parts = raw.split( LIST_SEPARATOR );
	const [ from = '', to = '' ] = parts;
	const ends = [ from, to ].filter( ( day ) => day !== '' );
	const wellFormed = parts.length === 2 && ends.length > 0 && ends.every( isCalendarDay );

	if ( ! wellFormed || ( from !== '' && to !== '' && from > to ) ) {
		return undefined;
	}

	const range: DateRange = {};
	if ( from !== '' ) {
		range.from = from;
	}
	if ( to !== '' ) {
		range.to = to;
	}

	return range;
}

const DECODERS: Partial< Record< Filter[ 'type' ], FilterDecoder > > = {
	select: decodeSelect,
	multi_select: decodeMultiSelect,
	boolean: decodeBoolean,
	date_range: decodeDateRange,
};

function encodeDateRange( value: FilterValue ): string {
	const range = value as DateRange;

	return [ range.from ?? '', range.to ?? '' ].join( LIST_SEPARATOR );
}

const ENCODERS: Partial< Record< Filter[ 'type' ], FilterEncoder > > = {
	select: ( value ) => String( value ),
	multi_select: ( value ) => ( value as string[] ).join( LIST_SEPARATOR ),
	boolean: ( value ) => ( value === true ? '1' : '0' ),
	date_range: encodeDateRange,
};

export function sameFilterValue( a: FilterValue | undefined, b: FilterValue | undefined ): boolean {
	return JSON.stringify( a ) === JSON.stringify( b );
}

function readFilter( filter: Filter, raw: string | undefined ): FilterValue | undefined {
	if ( raw === NO_VALUE ) {
		return undefined;
	}

	const decoded = raw === undefined ? undefined : DECODERS[ filter.type ]?.( filter, raw );

	return decoded === undefined ? filter.default : decoded;
}

function readFilters( definition: Definition, route: RouteQuery ): Record< string, FilterValue > {
	const filters: Record< string, FilterValue > = {};

	valueFilters( definition ).forEach( ( filter ) => {
		const value = readFilter( filter, route[ filterUrlKey( filter.id ) ] );

		if ( value !== undefined ) {
			filters[ filter.id ] = value;
		}
	} );

	return filters;
}

function readWholeNumber( raw: string | undefined ): number | undefined {
	return raw !== undefined && WHOLE_NUMBER.test( raw ) ? Number( raw ) : undefined;
}

function readPerPage( definition: Definition, raw: string | undefined ): number {
	const requested = readWholeNumber( raw );
	const { per_page_options: options, default_per_page: fallback } = definition.pagination;

	return requested !== undefined && options.includes( requested ) ? requested : fallback;
}

/**
 * Whether the server accepts this search text: at most 200 characters, no control character. The caller trims first.
 *
 * @param text The text to search for.
 */
export function isSearchText( text: string ): boolean {
	return [ ...text ].length <= MAX_SEARCH_LENGTH && ! CONTROL_CHARACTER.test( text );
}

function readSearch( definition: Definition, raw: string | undefined ): string {
	const text = ( raw ?? '' ).trim();

	return searchFilter( definition ) !== undefined && isSearchText( text ) ? text : '';
}

function readSort( definition: Definition, route: RouteQuery ): Pick< RecordsQuery, 'sort' | 'direction' > {
	const { keys, default: fallback } = definition.sorts;

	if ( fallback === null ) {
		return { sort: '', direction: 'asc' };
	}

	const requestedKey = route[ URL_KEYS.sort ];
	const requestedDirection = route[ URL_KEYS.direction ];
	const sort = requestedKey !== undefined && keys.includes( requestedKey ) ? requestedKey : fallback.key;
	const impliedDirection: Direction = sort === fallback.key ? fallback.direction : 'asc';
	const directionIsValid = requestedDirection === 'asc' || requestedDirection === 'desc';

	return { sort, direction: directionIsValid ? requestedDirection : impliedDirection };
}

/**
 * The account the URL asks for, before a definition exists to check it against (the server answers 404 for one it
 * did not offer).
 *
 * @param route The route query.
 */
export function readAccount( route: RouteQuery ): string {
	const account = route[ URL_KEYS.account ] ?? '';

	return OPAQUE_VALUE.test( account ) ? account : '';
}

/**
 * The query a URL describes, with the definition's default for everything it leaves out or gets wrong.
 *
 * @param definition The table's definition.
 * @param route      The route query.
 */
export function readQuery( definition: Definition, route: RouteQuery ): RecordsQuery {
	const page = readWholeNumber( route[ URL_KEYS.page ] );

	return {
		account: readAccount( route ),
		page: page !== undefined && page >= 1 && page <= MAX_PAGE ? page : 1,
		perPage: readPerPage( definition, route[ URL_KEYS.perPage ] ),
		search: readSearch( definition, route[ URL_KEYS.search ] ),
		...readSort( definition, route ),
		filters: readFilters( definition, route ),
	};
}

function writeFilter( filter: Filter, value: FilterValue | undefined ): string | undefined {
	if ( sameFilterValue( value, filter.default ) ) {
		return undefined;
	}

	return value === undefined ? NO_VALUE : ENCODERS[ filter.type ]?.( value );
}

function writeSort( definition: Definition, query: RecordsQuery ): RouteQuery {
	const fallback = definition.sorts.default;
	const isDefault = fallback === null || ( query.sort === fallback.key && query.direction === fallback.direction );

	return {
		[ URL_KEYS.sort ]: isDefault ? undefined : query.sort,
		[ URL_KEYS.direction ]: isDefault ? undefined : query.direction,
	};
}

/**
 * The URL parameters of a query. Every key the table owns is present; `undefined` removes it from the URL.
 *
 * @param definition The table's definition.
 * @param query      The query to encode.
 */
export function writeQuery( definition: Definition, query: RecordsQuery ): RouteQuery {
	const route: RouteQuery = {
		[ URL_KEYS.account ]: query.account === '' ? undefined : query.account,
		[ URL_KEYS.page ]: query.page > 1 ? String( query.page ) : undefined,
		[ URL_KEYS.perPage ]:
			query.perPage === definition.pagination.default_per_page ? undefined : String( query.perPage ),
		[ URL_KEYS.search ]: query.search === '' ? undefined : query.search,
		...writeSort( definition, query ),
	};

	valueFilters( definition ).forEach( ( filter ) => {
		route[ filterUrlKey( filter.id ) ] = writeFilter( filter, query.filters[ filter.id ] );
	} );

	return route;
}

/**
 * A query with one part changed. Anything but a page change starts again from the first page, because the old page
 * number belongs to another result set.
 *
 * @param query  The current query.
 * @param change The part that changes.
 */
export function changeQuery( query: RecordsQuery, change: Partial< RecordsQuery > ): RecordsQuery {
	const keepsPage = Object.keys( change ).every( ( key ) => key === 'page' );

	const page = keepsPage ? change.page ?? query.page : 1;

	return { ...query, ...change, page: Math.min( Math.max( page, 1 ), MAX_PAGE ) };
}

/**
 * A query with one filter set, or cleared when `value` is `undefined`.
 *
 * @param query    The current query.
 * @param filterId The filter that changes.
 * @param value    Its new value.
 */
export function changeFilter( query: RecordsQuery, filterId: string, value: FilterValue | undefined ): RecordsQuery {
	const filters = { ...query.filters };

	if ( Array.isArray( value ) && value.length > MAX_MULTI_VALUES ) {
		// The server refuses more values than this; the control keeps the selection it had.
		return query;
	}

	if ( value === undefined ) {
		delete filters[ filterId ];
	} else {
		filters[ filterId ] = value;
	}

	return changeQuery( query, { filters } );
}

export type RestParam = [ string, string ];

function filterParams( filterId: string, value: FilterValue ): RestParam[] {
	const key = `filters[${ filterId }]`;

	if ( Array.isArray( value ) ) {
		return value.map( ( item ): RestParam => [ `${ key }[]`, item ] );
	}
	if ( typeof value === 'object' ) {
		return Object.entries( value ).map( ( [ end, day ] ): RestParam => [ `${ key }[${ end }]`, String( day ) ] );
	}

	return [ [ key, String( value ) ] ];
}

/**
 * The `context` parameter of any records request.
 *
 * @param account The account to read for; empty lets the server use the declared default.
 */
export function contextParams( account: string ): RestParam[] {
	return account === '' ? [] : [ [ 'context[account_id]', account ] ];
}

/**
 * The parameters of the list request (`GET R`) for a query. Sort and filters are always sent as the table shows
 * them, so the response never depends on a server-side default the merchant cannot see.
 *
 * @param context The context the definition was resolved for.
 * @param query   The query on screen.
 */
export function listParams( context: RecordsContext, query: RecordsQuery ): RestParam[] {
	const params: RestParam[] = [
		...contextParams( context.account_id ),
		[ 'page', String( query.page ) ],
		[ 'per_page', String( query.perPage ) ],
	];

	if ( query.search !== '' ) {
		params.push( [ 'search', query.search ] );
	}
	if ( query.sort !== '' ) {
		params.push( [ 'sort', query.sort ], [ 'direction', query.direction ] );
	}

	Object.entries( query.filters ).forEach( ( [ filterId, value ] ) =>
		params.push( ...filterParams( filterId, value ) )
	);

	return params;
}
