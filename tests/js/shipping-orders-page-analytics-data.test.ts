/**
 * The pure parts of the «Аналитика» tab (#711): the chart's points, the number formatting and the request's query string.
 * Null-safety is the point — a share over nothing, a missing average, a carrier absent from an interval — so a
 * chart or tile never prints `NaN` or a misleading `0`.
 *
 * @see src/shipping-orders-page/analytics/
 */

import { buildAnalyticsQuery } from '../../src/shipping-orders-page/analytics/analytics-rest';
import { methodRows } from '../../src/shipping-orders-page/analytics/tables';
import { buildChartData, isChartEmpty, legendTotals, seriesValue } from '../../src/shipping-orders-page/analytics/chart-data';
import { DASH, formatCount, formatDays, formatMoney, formatShare } from '../../src/shipping-orders-page/analytics/format';
import type { AnalyticsCarrier, AnalyticsInterval, AnalyticsRequest, AnalyticsSeries } from '../../src/shipping-orders-page/analytics/types';

const series = ( overrides: Partial< AnalyticsSeries > = {} ): AnalyticsSeries => ( {
	orders: 0,
	revenue: 0,
	shipping_paid: 0,
	delivered: 0,
	resolved: 0,
	...overrides,
} );

const CARRIERS = [ { id: 'cdek', label: 'СДЭК' }, { id: 'yandex', label: 'Яндекс' } ] as AnalyticsCarrier[];

const INTERVALS: AnalyticsInterval[] = [
	{
		start: '2026-10-01',
		end: '2026-10-07',
		totals: series( { orders: 5 } ),
		carriers: { cdek: series( { orders: 3, revenue: 300, delivered: 1, resolved: 4 } ), yandex: series( { orders: 2 } ) },
	},
	{ start: '2026-10-08', end: '2026-10-14', totals: series(), carriers: {} },
];

describe( 'seriesValue', () => {
	it( 'reads the metric straight from the series', () => {
		expect( seriesValue( series( { orders: 3, revenue: 300.5 } ), 'orders' ) ).toBe( 3 );
		expect( seriesValue( series( { orders: 3, revenue: 300.5 } ), 'revenue' ) ).toBe( 300.5 );
	} );

	it( 'a delivered share is a percentage of the resolved orders, one decimal', () => {
		expect( seriesValue( series( { delivered: 1, resolved: 3 } ), 'delivered_share' ) ).toBe( 33.3 );
	} );

	it( 'a share over nothing resolved is 0, not NaN', () => {
		expect( seriesValue( series( { delivered: 0, resolved: 0 } ), 'delivered_share' ) ).toBe( 0 );
	} );

	it( 'a carrier absent from an interval plots as 0', () => {
		expect( seriesValue( undefined, 'orders' ) ).toBe( 0 );
	} );
} );

describe( 'buildChartData', () => {
	it( 'makes one point per interval and one labelled series per carrier', () => {
		const points = buildChartData( INTERVALS, CARRIERS, 'orders' );

		expect( points ).toHaveLength( 2 );
		expect( points[ 0 ].date ).toBe( '2026-10-01T00:00:00' );
		expect( points[ 0 ].СДЭК ).toEqual( { label: 'СДЭК', value: 3 } );
		expect( points[ 0 ].Яндекс ).toEqual( { label: 'Яндекс', value: 2 } );
	} );

	it( 'an empty interval still has every series, at 0', () => {
		expect( buildChartData( INTERVALS, CARRIERS, 'orders' )[ 1 ].СДЭК ).toEqual( { label: 'СДЭК', value: 0 } );
	} );

	it( 'follows the selected metric', () => {
		expect( buildChartData( INTERVALS, CARRIERS, 'delivered_share' )[ 0 ].СДЭК ).toEqual( { label: 'СДЭК', value: 25 } );
	} );
} );

describe( 'isChartEmpty', () => {
	it( 'is true only when every value of every point is 0', () => {
		expect( isChartEmpty( buildChartData( [ INTERVALS[ 1 ] ], CARRIERS, 'orders' ) ) ).toBe( true );
		expect( isChartEmpty( buildChartData( INTERVALS, CARRIERS, 'orders' ) ) ).toBe( false );
		expect( isChartEmpty( [] ) ).toBe( true );
	} );
} );

describe( 'number formatting', () => {
	it( 'prints a missing value as a dash, never 0 or NaN', () => {
		for ( const text of [ formatCount( null ), formatMoney( null, 'RUB' ), formatShare( null ), formatDays( null ), formatShare( NaN ) ] ) {
			expect( text ).toBe( DASH );
		}
	} );

	it( 'formats a share as a percentage and a count of days with one decimal', () => {
		expect( formatShare( 0.6 ).replace( /\s/g, '' ) ).toBe( '60%' );
		expect( formatDays( 4.5 ) ).toBe( '4,5' );
	} );

	it( 'formats money in the shop currency', () => {
		const text = formatMoney( 12345, 'RUB' );

		expect( text ).toContain( '12' );
		expect( text ).toMatch( /₽|RUB/ );
	} );

	it( 'does not throw on a currency code Intl does not know', () => {
		expect( formatMoney( 10, 'XX' ) ).toBe( '10 XX' );
	} );
} );

describe( 'buildAnalyticsQuery', () => {
	const request: AnalyticsRequest = {
		after: '2026-10-01',
		before: '2026-10-31',
		compareAfter: '2026-09-01',
		compareBefore: '2026-09-30',
		interval: 'auto',
		carrier: 'all',
	};

	it( 'sends both periods and no carrier for «all»', () => {
		const params = new URLSearchParams( buildAnalyticsQuery( request, false ) );

		expect( params.get( 'after' ) ).toBe( '2026-10-01' );
		expect( params.get( 'compare_before' ) ).toBe( '2026-09-30' );
		expect( params.get( 'interval' ) ).toBe( 'auto' );
		expect( params.has( 'carrier' ) ).toBe( false );
		expect( params.has( 'refresh' ) ).toBe( false );
	} );

	it( 'sends a carrier, and `refresh` only when asked', () => {
		const params = new URLSearchParams( buildAnalyticsQuery( { ...request, carrier: 'cdek' }, true ) );

		expect( params.get( 'carrier' ) ).toBe( 'cdek' );
		expect( params.get( 'refresh' ) ).toBe( '1' );
	} );

	it( 'omits the comparison when there is none, or only half of it', () => {
		const none = new URLSearchParams( buildAnalyticsQuery( { ...request, compareAfter: null, compareBefore: null }, false ) );
		const half = new URLSearchParams( buildAnalyticsQuery( { ...request, compareBefore: null }, false ) );

		expect( none.has( 'compare_after' ) ).toBe( false );
		expect( half.has( 'compare_after' ) ).toBe( false );
	} );
} );

describe( 'methodRows', () => {
	it( 'puts the revenue of each shipping method beside its orders and what the buyers paid for delivery', () => {
		const rows = methodRows(
			[
				{ method_id: 'cdek_pickup', carrier_id: 'cdek', label: 'СДЭК: пункт', orders: 4, lines: 4, revenue: 10000, shipping_paid: 600 },
				{ method_id: 'cdek_door', carrier_id: 'cdek', label: 'СДЭК: дверь', orders: 9, lines: 10, revenue: 30000, shipping_paid: 1800 },
			],
			CARRIERS,
			'RUB'
		);

		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ].map( ( cell ) => cell.value ) ).toEqual( [ 'СДЭК: пункт', 'СДЭК', 4, 10000, 600 ] );
		expect( String( rows[ 1 ][ 3 ].display ).replace( /\s/g, '' ) ).toContain( '30000' );
	} );
} );

describe( 'legendTotals', () => {
	const carrier = ( label: string, share: number | null ) => ( { id: label, label, current: { delivered_share: share } } ) as unknown as AnalyticsCarrier;

	it( 'is the period-wide share in percent for the ratio metric, 0 over nothing', () => {
		expect( legendTotals( [ carrier( 'СДЭК', 0.8333 ), carrier( 'Яндекс', null ) ], 'delivered_share' ) ).toEqual( { СДЭК: 83.3, Яндекс: 0 } );
	} );

	it( 'is left to the legend (its own sum) for a countable metric', () => {
		expect( legendTotals( [ carrier( 'СДЭК', 0.5 ) ], 'orders' ) ).toBeUndefined();
	} );
} );
