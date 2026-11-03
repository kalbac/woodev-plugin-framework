/**
 * The period of the «Аналитика» tab and of the report in WooCommerce → Аналитика (#711, rig smoke round 3): a URL that names
 * `period=custom&after=…&before=…` but no `compare` must APPLY the custom range, with the default comparison.
 *
 * `@woocommerce/date` is stood in for by a copy of the two behaviours that matter (measured in the installed WC 11.2.1 bundle):
 * `getDateParamsFromQuery()` honours a query only when it has BOTH `period` and `compare` and otherwise returns the default range, and
 * `getCurrentDates()` computes `previous_period` as the same number of days ending the day before the primary range starts.
 *
 * @see src/shipping-orders-page/analytics/date-range.ts
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import DeliveryReport from '../../src/shipping-orders-page/analytics/delivery-report';
import AnalyticsTab from '../../src/shipping-orders-page/analytics/analytics-tab';
import { fetchAnalytics } from '../../src/shipping-orders-page/analytics/analytics-rest';
import { DEFAULT_DATE_RANGE, resolveDateRange, withComparison } from '../../src/shipping-orders-page/analytics/date-range';

jest.mock( '../../src/shipping-orders-page/analytics/analytics-rest', () => ( { fetchAnalytics: jest.fn() } ) );

type Q = Record< string, string | undefined >;

const DAY = 86400000;
const iso = ( ms: number ) => new Date( ms ).toISOString().slice( 0, 10 );
const day = ( text: string ) => ( { text, format: () => text } );

/** The default range as WooCommerce resolves it for these tests: «month to date», frozen at 3 November. */
const TODAY_RANGE = { after: '2026-11-01', before: '2026-11-03' };

function installDateHelpers() {
	const parse = ( q: Q ) => ( q.period && q.compare ? { period: q.period, compare: q.compare, after: q.after, before: q.before } : null );

	( window as any ).wc = {
		navigation: { getQuery: () => ( {} ), getPath: () => '/', addHistoryListener: () => () => undefined, updateQueryString: jest.fn() },
		date: {
			isoDateFormat: 'YYYY-MM-DD',
			// WooCommerce: both `period` and `compare`, or the default for EVERYTHING.
			getDateParamsFromQuery: ( q: Q, defaults: string ) => {
				const given = parse( q );
				const fallback = Object.fromEntries( new URLSearchParams( defaults ) );

				return given ?? { period: fallback.period, compare: fallback.compare, after: undefined, before: undefined };
			},
			getCurrentDates: ( q: Q, defaults: string ) => {
				const given = parse( q );
				const fallback = Object.fromEntries( new URLSearchParams( defaults ) );
				const period = given ? given.period : fallback.period;
				const compare = given ? given.compare : fallback.compare;
				const [ after, before ] = 'custom' === period && given ? [ given.after as string, given.before as string ] : [ TODAY_RANGE.after, TODAY_RANGE.before ];
				const days = ( Date.parse( before ) - Date.parse( after ) ) / DAY;
				const end = Date.parse( after ) - DAY;

				return {
					primary: { label: period, range: '', after: day( after ), before: day( before ) },
					secondary: 'previous_period' === compare
						? { label: compare, range: '', after: day( iso( end - days * DAY ) ), before: day( iso( end ) ) }
						: { label: compare, range: '', after: day( iso( Date.parse( after ) - 365 * DAY ) ), before: day( iso( Date.parse( before ) - 365 * DAY ) ) },
				};
			},
		},
		components: {},
	};
}

beforeEach( () => {
	installDateHelpers();
	( fetchAnalytics as jest.Mock ).mockReset();
	( fetchAnalytics as jest.Mock ).mockReturnValue( new Promise( () => undefined ) ); // never settles: only the request is under test.
	( window as any ).woodevShippingOrders = { providers: [] };
} );

const CUSTOM_JUNE = { period: 'custom', after: '2026-06-01', before: '2026-06-30' };

describe( 'withComparison', () => {
	it( 'completes a period that has no comparison with the default one', () => {
		expect( withComparison( CUSTOM_JUNE ) ).toEqual( { ...CUSTOM_JUNE, compare: 'previous_period' } );
		expect( DEFAULT_DATE_RANGE ).toContain( 'compare=previous_period' );
	} );

	it( 'leaves a query alone that has a comparison, or no period at all', () => {
		const withCompare = { ...CUSTOM_JUNE, compare: 'previous_year' };
		const empty = {};

		expect( withComparison( withCompare ) ).toBe( withCompare );
		expect( withComparison( empty ) ).toBe( empty );
	} );
} );

describe( 'resolveDateRange', () => {
	it( 'APPLIES `period=custom&after&before` without `compare` — the rig bug: it used to fall back to the default month', () => {
		const range = resolveDateRange( CUSTOM_JUNE );

		expect( range ).toMatchObject( { after: '2026-06-01', before: '2026-06-30' } );
		// The default comparison: the 30 days before, ending the day before the range starts.
		expect( range ).toMatchObject( { compareAfter: '2026-05-02', compareBefore: '2026-05-31' } );
	} );

	it( 'still honours an explicit comparison', () => {
		expect( resolveDateRange( { ...CUSTOM_JUNE, compare: 'previous_year' } ) ).toMatchObject( {
			after: '2026-06-01',
			before: '2026-06-30',
			compareAfter: '2025-06-01',
			compareBefore: '2025-06-30',
		} );
	} );

	it( 'falls back to the default period only when the URL names none', () => {
		expect( resolveDateRange( {} ) ).toMatchObject( { after: TODAY_RANGE.after, before: TODAY_RANGE.before } );
	} );
} );

describe( 'the same period, in the tab and in the WooCommerce report', () => {
	it.each( [
		[ 'the «Аналитика» tab', AnalyticsTab ],
		[ 'the report in WooCommerce → Аналитика', DeliveryReport ],
	] )( 'a custom range without `compare` reaches the REST request in %s', ( _name, Body ) => {
		( window as any ).wc.navigation.getQuery = () => CUSTOM_JUNE;

		render( <Body /> );

		expect( fetchAnalytics ).toHaveBeenCalledTimes( 1 );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			after: '2026-06-01',
			before: '2026-06-30',
			compareAfter: '2026-05-02',
			compareBefore: '2026-05-31',
		} );
		expect( screen.queryByText( /недоступна/ ) ).not.toBeInTheDocument();
	} );
} );
