/**
 * The records table's query codec: URL ↔ query ↔ list request.
 *
 * @see src/admin-records/query.ts
 */

import {
	NO_VALUE,
	changeFilter,
	changeQuery,
	listParams,
	readAccount,
	readQuery,
	writeQuery,
} from '../../src/admin-records/query';
import { fixtureDefinition } from '../../src/admin-records/testing/fixture';
import type { Definition, RecordsQuery, RouteQuery } from '../../src/admin-records/types';

const definition = fixtureDefinition();

const DEFAULT_QUERY: RecordsQuery = {
	account: '',
	page: 1,
	perPage: 25,
	search: '',
	sort: 'updated',
	direction: 'desc',
	filters: {},
};

/** What the URL looks like after wc-admin merged a written query into it: removed keys are gone. */
function asUrl( route: RouteQuery ): RouteQuery {
	return Object.fromEntries( Object.entries( route ).filter( ( [ , value ] ) => value !== undefined ) );
}

function withDefault( filterId: string, value: unknown ): Definition {
	return fixtureDefinition( {
		filters: definition.filters.map( ( filter ) =>
			filter.id === filterId ? { ...filter, default: value as never } : filter
		),
	} );
}

describe( 'readQuery', () => {
	test( 'an empty URL is the declared defaults', () => {
		expect( readQuery( definition, {} ) ).toEqual( DEFAULT_QUERY );
	} );

	test( 'every part of the query is read from the URL', () => {
		const route: RouteQuery = {
			account: 'a2',
			paged: '3',
			per_page: '50',
			search: '  кружка ',
			orderby: 'stock',
			order: 'desc',
			filter_state: 'draft',
			filter_brand: 'acme,zen',
			filter_in_stock: '0',
			filter_updated: '2026-11-01,2026-11-09',
		};

		expect( readQuery( definition, route ) ).toEqual( {
			account: 'a2',
			page: 3,
			perPage: 50,
			search: 'кружка',
			sort: 'stock',
			direction: 'desc',
			filters: {
				state: 'draft',
				brand: [ 'acme', 'zen' ],
				in_stock: false,
				updated: { from: '2026-11-01', to: '2026-11-09' },
			},
		} );
	} );

	test( 'a boolean filter set to false is not the same as one left out', () => {
		expect( readQuery( definition, { filter_in_stock: '0' } ).filters ).toEqual( { in_stock: false } );
		expect( readQuery( definition, {} ).filters ).toEqual( {} );
	} );

	test( 'a date range may have one end only', () => {
		expect( readQuery( definition, { filter_updated: ',2026-11-09' } ).filters ).toEqual( {
			updated: { to: '2026-11-09' },
		} );
		expect( readQuery( definition, { filter_updated: '2026-11-01,' } ).filters ).toEqual( {
			updated: { from: '2026-11-01' },
		} );
	} );

	test.each( [
		[ 'an unknown sort key', { orderby: 'price' }, { sort: 'updated', direction: 'desc' } ],
		[ 'a direction that is not one', { orderby: 'stock', order: 'up' }, { sort: 'stock', direction: 'asc' } ],
		[ 'a page size PHP did not offer', { per_page: '30' }, { perPage: 25 } ],
		[ 'a page that is not a number', { paged: '-2' }, { page: 1 } ],
		[ 'an option the select does not have', { filter_state: 'gone' }, { filters: {} } ],
		[ 'a repeated multi-select value', { filter_brand: 'acme,acme' }, { filters: {} } ],
		[ 'a boolean that is neither 1 nor 0', { filter_in_stock: 'yes' }, { filters: {} } ],
		[ 'a day that does not exist', { filter_updated: '2026-02-30,' }, { filters: {} } ],
		[ 'a range that ends before it starts', { filter_updated: '2026-11-09,2026-11-01' }, { filters: {} } ],
		[ 'a filter the table does not declare', { filter_price: '10' }, { filters: {} } ],
		[ 'an account that is not an opaque id', { account: 'a 1' }, { account: '' } ],
	] )( '%s falls back to the default', ( _name, route, expected ) => {
		expect( readQuery( definition, route ) ).toEqual( { ...DEFAULT_QUERY, ...expected } );
	} );

	test( 'a sort key without a direction sorts ascending, as the server does', () => {
		expect( readQuery( definition, { orderby: 'title' } ) ).toMatchObject( { sort: 'title', direction: 'asc' } );
	} );

	test( 'search is ignored by a table without a search filter', () => {
		const noSearch = fixtureDefinition( {
			filters: definition.filters.filter( ( filter ) => filter.type !== 'search' ),
		} );

		expect( readQuery( noSearch, { search: 'кружка' } ).search ).toBe( '' );
	} );

	test( 'a table that is not sortable has no sort', () => {
		const unsorted = fixtureDefinition( { sorts: { keys: [], directions: [ 'asc', 'desc' ], default: null } } );

		expect( readQuery( unsorted, { orderby: 'title', order: 'desc' } ) ).toMatchObject( { sort: '' } );
		expect( listParams( { account_id: '' }, readQuery( unsorted, {} ) ) ).toEqual( [
			[ 'page', '1' ],
			[ 'per_page', '25' ],
		] );
	} );

	test( 'a PHP default applies until the URL says otherwise, and the URL can clear it', () => {
		const preset = withDefault( 'state', 'linked' );

		expect( readQuery( preset, {} ).filters ).toEqual( { state: 'linked' } );
		expect( readQuery( preset, { filter_state: 'draft' } ).filters ).toEqual( { state: 'draft' } );
		expect( readQuery( preset, { filter_state: NO_VALUE } ).filters ).toEqual( {} );
	} );
} );

describe( 'writeQuery', () => {
	test( 'the default query leaves the URL clean', () => {
		expect( asUrl( writeQuery( definition, DEFAULT_QUERY ) ) ).toEqual( {} );
	} );

	test( 'a value that went back to its default is removed from the URL', () => {
		const route = writeQuery( definition, DEFAULT_QUERY );

		expect( Object.keys( route ) ).toEqual(
			expect.arrayContaining( [
				'account',
				'paged',
				'per_page',
				'search',
				'orderby',
				'order',
				'filter_state',
				'filter_in_stock',
			] )
		);
		expect( Object.values( route ).every( ( value ) => value === undefined ) ).toBe( true );
	} );

	test( 'a cleared filter with a PHP default is written explicitly', () => {
		const preset = withDefault( 'in_stock', true );

		expect( asUrl( writeQuery( preset, { ...DEFAULT_QUERY, filters: {} } ) ) ).toEqual( {
			filter_in_stock: NO_VALUE,
		} );
		expect( asUrl( writeQuery( preset, { ...DEFAULT_QUERY, filters: { in_stock: true } } ) ) ).toEqual( {} );
		expect( asUrl( writeQuery( preset, { ...DEFAULT_QUERY, filters: { in_stock: false } } ) ) ).toEqual( {
			filter_in_stock: '0',
		} );
	} );

	test.each( [
		[ 'defaults', {} ],
		[ 'paging and sort', { paged: '4', per_page: '100', orderby: 'title', order: 'desc' } ],
		[ 'the default key in the other direction', { orderby: 'updated', order: 'asc' } ],
		[ 'search and account', { account: 'a2', search: 'синяя кружка' } ],
		[
			'every filter type',
			{ filter_state: 'linked', filter_brand: 'zen,acme', filter_in_stock: '1', filter_updated: '2026-11-01,' },
		],
		[ 'false and an open-ended range', { filter_in_stock: '0', filter_updated: ',2026-11-09' } ],
	] )( 'URL → query → URL keeps %s', ( _name, route ) => {
		expect( asUrl( writeQuery( definition, readQuery( definition, route ) ) ) ).toEqual( route );
	} );

	test( 'query → URL → query keeps the query', () => {
		const query: RecordsQuery = {
			account: 'a2',
			page: 2,
			perPage: 50,
			search: 'кружка',
			sort: 'stock',
			direction: 'asc',
			filters: { state: 'draft', brand: [ 'acme' ], in_stock: false, updated: { to: '2026-11-09' } },
		};

		expect( readQuery( definition, asUrl( writeQuery( definition, query ) ) ) ).toEqual( query );
	} );
} );

describe( 'changing the query', () => {
	const onPageThree: RecordsQuery = { ...DEFAULT_QUERY, page: 3 };

	test( 'a page change keeps everything else', () => {
		expect( changeQuery( onPageThree, { page: 4 } ) ).toEqual( { ...DEFAULT_QUERY, page: 4 } );
	} );

	test.each( [
		[ 'search', { search: 'кружка' } ],
		[ 'sort', { sort: 'title', direction: 'asc' as const } ],
		[ 'page size', { perPage: 50 } ],
		[ 'account', { account: 'a2' } ],
	] )( 'a new %s starts again from the first page', ( _name, change ) => {
		expect( changeQuery( onPageThree, change ) ).toEqual( { ...DEFAULT_QUERY, ...change, page: 1 } );
	} );

	test( 'setting and clearing a filter returns to the first page', () => {
		const filtered = changeFilter( onPageThree, 'state', 'draft' );

		expect( filtered ).toMatchObject( { page: 1, filters: { state: 'draft' } } );
		expect( changeFilter( { ...filtered, page: 2 }, 'state', undefined ) ).toMatchObject( {
			page: 1,
			filters: {},
		} );
	} );
} );

describe( 'the list request', () => {
	test( 'sends the query as the server validates it', () => {
		const query = readQuery( definition, {
			paged: '2',
			search: 'кружка',
			orderby: 'stock',
			filter_state: 'draft',
			filter_brand: 'acme,zen',
			filter_in_stock: '0',
			filter_updated: '2026-11-01,',
		} );

		expect( listParams( { account_id: 'a2' }, query ) ).toEqual( [
			[ 'context[account_id]', 'a2' ],
			[ 'page', '2' ],
			[ 'per_page', '25' ],
			[ 'search', 'кружка' ],
			[ 'sort', 'stock' ],
			[ 'direction', 'asc' ],
			[ 'filters[state]', 'draft' ],
			[ 'filters[brand][]', 'acme' ],
			[ 'filters[brand][]', 'zen' ],
			[ 'filters[in_stock]', 'false' ],
			[ 'filters[updated][from]', '2026-11-01' ],
		] );
	} );

	test( 'a table without accounts sends no context', () => {
		expect( listParams( { account_id: '' }, DEFAULT_QUERY )[ 0 ] ).toEqual( [ 'page', '1' ] );
	} );

	test( 'the requested account is read before any definition exists', () => {
		expect( readAccount( { account: 'a2' } ) ).toBe( 'a2' );
		expect( readAccount( {} ) ).toBe( '' );
	} );
} );
