/**
 * The «Аналитика» tab and the page's tab switch (#711): the tab fetches for the period the URL resolves to, shows the
 * states (loading keeps the old figures, error offers a retry, empty says so), the KPI tiles drive the chart through the URL,
 * and the page tabs only write `tab=` — the orders list is not touched.
 *
 * WooCommerce's components are stand-ins that render their props as text; `./rest` and the fetch are mocked.
 *
 * @see src/shipping-orders-page/analytics/analytics-tab.tsx
 * @see src/shipping-orders-page/page-tabs.tsx
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import AnalyticsTab from '../../src/shipping-orders-page/analytics/analytics-tab';
import PageTabs from '../../src/shipping-orders-page/page-tabs';
import { fetchAnalytics } from '../../src/shipping-orders-page/analytics/analytics-rest';

jest.mock( '../../src/shipping-orders-page/analytics/analytics-rest', () => ( {
	fetchAnalytics: jest.fn(),
} ) );
jest.mock( '../../src/shipping-orders-page/app', () => ( {
	__esModule: true,
	default: () => <div>ORDERS LIST</div>,
} ) );

const metrics = ( overrides = {} ) => ( {
	orders: 10,
	revenue: 10000,
	shipping_paid: 1500,
	avg_order: 1000,
	avg_shipping: 150,
	shipping_share: 0.15,
	outcomes: { in_progress: 2, delivered: 6, returned: 1, failed: 1, cancelled: 0, unknown: 0 },
	resolved: 8,
	delivered_share: 0.75,
	returned_share: 0.125,
	failed_share: 0.125,
	avg_days_to_delivery: 4.5,
	time_sample: 5,
	...overrides,
} );

const series = ( orders: number ) => ( { orders, revenue: orders * 100, shipping_paid: orders * 10, delivered: 0, resolved: 0 } );

const RESPONSE = {
	range: { after: '2026-10-01', before: '2026-10-31', interval: 'day' },
	compare: { after: '2026-09-01', before: '2026-09-30' },
	currency: 'RUB',
	totals: { current: metrics(), previous: metrics( { orders: 8 } ), delta: { orders: 25, revenue: 0 } },
	carriers: [ { id: 'cdek', label: 'СДЭК', current: metrics(), previous: null, delta: {} } ],
	methods: [ { method_id: 'cdek_door', carrier_id: 'cdek', label: 'СДЭК: до двери', orders: 6, lines: 6, shipping_paid: 900 } ],
	intervals: [ { start: '2026-10-01', end: '2026-10-01', totals: series( 3 ), carriers: { cdek: series( 3 ) } } ],
	previous_intervals: null,
	coverage: { delivered: 6, time_sample: 5 },
	generated_at: '2026-11-03T05:00:00+00:00',
	cached: false,
};

const EMPTY = { ...RESPONSE, totals: { current: metrics( { orders: 0 } ), previous: null, delta: {} }, carriers: [], methods: [], intervals: [] };

const moment = ( text: string ) => ( { format: () => text } );

let query: Record< string, string | undefined >;
let listeners: Array< () => void >;
const updateQueryString = jest.fn();

function installWooCommerce() {
	listeners = [];
	( window as any ).wc = {
		navigation: {
			getQuery: () => query,
			getPath: () => '/woodev-shipping-orders',
			addHistoryListener: ( listener: () => void ) => {
				listeners.push( listener );
				return () => undefined;
			},
			updateQueryString,
		},
		date: {
			isoDateFormat: 'YYYY-MM-DD',
			getDateParamsFromQuery: () => ( { period: 'month', compare: 'previous_period', after: moment( '2026-10-01' ), before: moment( '2026-10-31' ) } ),
			getCurrentDates: () => ( {
				primary: { label: 'x', range: 'x', after: moment( '2026-10-01' ), before: moment( '2026-10-31' ) },
				secondary: { label: 'y', range: 'y', after: moment( '2026-09-01' ), before: moment( '2026-09-30' ) },
			} ),
		},
		components: {
			DateRangeFilterPicker: () => <div>DATE PICKER</div>,
			Chart: ( props: any ) => (
				<div data-testid="chart">
					{ props.title }|{ props.interval }|{ props.data.length }|{ props.emptyMessage ?? '' }
				</div>
			),
			SummaryList: ( { children }: any ) => <div>{ children() }</div>,
			SummaryNumber: ( props: any ) => (
				<button type="button" disabled={ ! props.onToggle } aria-pressed={ !! props.selected } onClick={ props.onToggle }>
					{ props.label }: { props.value } ({ props.delta ?? 'no delta' }) { props.children }
				</button>
			),
			TableCard: ( props: any ) => (
				<div data-testid="table">
					{ props.title }: { props.rows.length } rows
				</div>
			),
		},
	};
	( window as any ).woodevShippingOrders = { providers: [ { id: 'cdek', label: 'СДЭК' } ] };
}

beforeEach( () => {
	query = {};
	updateQueryString.mockReset();
	( fetchAnalytics as jest.Mock ).mockReset();
	installWooCommerce();
} );

describe( 'AnalyticsTab', () => {
	it( 'fetches for the period the URL resolves to, comparison included, and draws the answer', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <AnalyticsTab /> );

		await screen.findByText( /Заказы: 10/ );
		expect( fetchAnalytics ).toHaveBeenCalledTimes( 1 );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 0 ][ 0 ] ).toEqual( {
			after: '2026-10-01',
			before: '2026-10-31',
			compareAfter: '2026-09-01',
			compareBefore: '2026-09-30',
			interval: 'auto',
			carrier: 'all',
		} );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 0 ][ 1 ] ).toBe( false );
		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'Заказы по перевозчикам|day|1' );
		expect( screen.getAllByTestId( 'table' ) ).toHaveLength( 2 );
		expect( screen.getByText( /в работе: 2/ ) ).toBeInTheDocument();
		expect( screen.getByText( /по 5 из 6 доставленных/ ) ).toBeInTheDocument();
	} );

	it( 'a tile that drives the chart writes `chart=` to the URL; a plain figure cannot be selected', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		fireEvent.click( screen.getByText( /^Выручка:/ ) );

		expect( updateQueryString ).toHaveBeenCalledWith( { chart: 'revenue' }, '/woodev-shipping-orders', {} );
		expect( screen.getByText( /^Отказы и возвраты:/ ) ).toBeDisabled();
	} );

	it( 'plots the metric the URL names', async () => {
		query = { chart: 'delivered_share' };
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <AnalyticsTab /> );

		expect( await screen.findByTestId( 'chart' ) ).toHaveTextContent( 'Доля доставленных по перевозчикам' );
	} );

	it( 'sends the carrier and the interval of the URL', async () => {
		query = { carrier: 'cdek', interval: 'week' };
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 0 ][ 0 ] ).toMatchObject( { carrier: 'cdek', interval: 'week' } );
	} );

	it( 'says so when the period has no orders, and draws no tiles', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( EMPTY );

		render( <AnalyticsTab /> );

		expect( await screen.findByText( /нет заказов с доставкой перевозчиками/ ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'chart' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the server message with a retry, and the retry asks for a fresh read', async () => {
		( fetchAnalytics as jest.Mock ).mockRejectedValueOnce( { message: 'Период слишком длинный' } ).mockResolvedValue( RESPONSE );

		render( <AnalyticsTab /> );
		expect( ( await screen.findAllByText( /Период слишком длинный/ ) ).length ).toBeGreaterThan( 0 ); // a notice is also announced to screen readers.

		fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		await screen.findByText( /Заказы: 10/ );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 1 ][ 1 ] ).toBe( true );
	} );

	it( 'a refresh is for that one call: the next period pick asks normally', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить' } ) );
		await waitFor( () => expect( fetchAnalytics ).toHaveBeenCalledTimes( 2 ) );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 1 ][ 1 ] ).toBe( true );

		query = { carrier: 'cdek' };
		act( () => listeners.forEach( ( listener ) => listener() ) );

		await waitFor( () => expect( fetchAnalytics ).toHaveBeenCalledTimes( 3 ) );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 2 ][ 1 ] ).toBe( false );
	} );

	it( 'does not crash without the WooCommerce date helpers: it says the tab is unavailable', () => {
		delete ( window as any ).wc.date;

		render( <AnalyticsTab /> );

		expect( screen.getAllByText( /Аналитика недоступна/ ).length ).toBeGreaterThan( 0 );
		expect( fetchAnalytics ).not.toHaveBeenCalled();
	} );

	it( 'falls back to the default period when the URL carries one WooCommerce cannot resolve', async () => {
		let calls = 0;
		( window as any ).wc.date.getCurrentDates = ( ...args: unknown[] ) => {
			calls++;
			if ( 1 === calls ) {
				throw new Error( 'Cannot find period: all' );
			}
			return {
				primary: { label: 'x', range: 'x', after: moment( '2026-10-01' ), before: moment( '2026-10-31' ) },
				secondary: { label: 'y', range: 'y', after: moment( '2026-09-01' ), before: moment( '2026-09-30' ) },
			};
		};
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <AnalyticsTab /> );

		await screen.findByText( /Заказы: 10/ );
	} );
} );

describe( 'PageTabs', () => {
	it( 'shows the orders list by default and writes only `tab=` to switch', () => {
		render( <PageTabs /> );

		expect( screen.getByText( 'ORDERS LIST' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'tab', { name: 'Заказы' } ) ).toHaveAttribute( 'aria-selected', 'true' );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Аналитика' } ) );

		expect( updateQueryString ).toHaveBeenCalledWith( { tab: 'analytics' }, '/woodev-shipping-orders', {} );
	} );

	it( 'shows the analytics tab for `tab=analytics`, and the list tab clears the query', async () => {
		query = { tab: 'analytics' };
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <PageTabs /> );

		await screen.findByText( /Заказы: 10/ );
		expect( screen.queryByText( 'ORDERS LIST' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'tab', { name: 'Заказы' } ) );

		expect( updateQueryString ).toHaveBeenCalledWith( {}, '/woodev-shipping-orders', {} );
	} );
} );
