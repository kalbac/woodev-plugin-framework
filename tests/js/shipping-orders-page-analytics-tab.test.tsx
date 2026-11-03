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
import { Component } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import AnalyticsTab from '../../src/shipping-orders-page/analytics/analytics-tab';
import PageTabs from '../../src/shipping-orders-page/page-tabs';
import DeliveryReport, { REPORT_PATH } from '../../src/shipping-orders-page/analytics/delivery-report';
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
	avg_days_handover_to_delivery: null,
	handover_sample: 0,
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
	coverage: { delivered: 6, time_sample: 5, handover_sample: 0 },
	generated_at: '2026-11-03T05:00:00+00:00',
	cached: false,
};

const EMPTY = { ...RESPONSE, totals: { current: metrics( { orders: 0 } ), previous: null, delta: {} }, carriers: [], methods: [], intervals: [] };

const moment = ( text: string ) => ( { format: () => text } );

/**
 * `DateRangeFilterPicker` as the installed component behaves: it copies `dateQuery` into its OWN state when it is created and never
 * syncs afterwards (WC 11.2.1, `getResetState()` in the constructor only) — so a picker that is not remounted keeps showing and editing
 * the period it was first opened on.
 */
class StatefulDatePicker extends Component< any, { period: string } > {
	constructor( props: any ) {
		super( props );
		this.state = { period: props.dateQuery.period };
	}

	render() {
		return <div data-testid="picker">PICKER EDITS: { this.state.period }</div>;
	}
}

/**
 * `Chart` as the installed component behaves for legend visibility (`_l`, WC 11.2.1): in item-comparison mode the visible series are
 * initialised once from the series with a non-zero total, and rebuilt only when the SET of data keys changes. The toggle buttons emit
 * what the real header controls emit: `{ chartType }` (handleTypeToggle) through `updateQueryString( patch, path, query )`.
 */
class StatefulChart extends Component< any, { visible: string[] } > {
	constructor( props: any ) {
		super( props );
		this.state = { visible: StatefulChart.nonZeroKeys( props.data ) };
	}

	static keys( data: any[] ): string[] {
		return Array.from( new Set( data.flatMap( ( point ) => Object.keys( point ).filter( ( key ) => 'date' !== key ) ) ) ).sort();
	}

	static nonZeroKeys( data: any[] ): string[] {
		return StatefulChart.keys( data ).filter( ( key ) => data.some( ( point ) => point[ key ]?.value > 0 ) );
	}

	componentDidUpdate( previous: any ) {
		if ( StatefulChart.keys( previous.data ).join() !== StatefulChart.keys( this.props.data ).join() ) {
			this.setState( { visible: StatefulChart.nonZeroKeys( this.props.data ) } );
		}
	}

	render() {
		const { title, interval, chartType, path, query } = this.props;

		return (
			<div data-testid="chart">
				{ title }|{ interval }|{ this.props.data.length }|{ this.props.emptyMessage ?? '' }|type={ chartType }|visible=[{ this.state.visible.join( ',' ) }]
				<button type="button" onClick={ () => ( window as any ).wc.navigation.updateQueryString( { chartType: 'bar' }, path, query ) }>
					bar
				</button>
			</div>
		);
	}
}

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
			// `getNewPath( patch, path, current )`: the patch merged into the current query, as a path (the installed one does the same).
			getNewPath: ( patch: Record< string, string | undefined >, path: string, current: Record< string, string | undefined > ) => {
				const merged = Object.entries( { ...current, ...patch } ).filter( ( [ , value ] ) => undefined !== value ) as [ string, string ][];

				return `${ path }?${ new URLSearchParams( merged ).toString() }`;
			},
		},
		date: {
			isoDateFormat: 'YYYY-MM-DD',
			getDateParamsFromQuery: ( q: Record< string, string | undefined > ) => ( { period: q.period ?? 'month', compare: 'previous_period', after: moment( '2026-10-01' ), before: moment( '2026-10-31' ) } ),
			getCurrentDates: ( q: Record< string, string | undefined > ) =>
				'last_month' === q.period
					? {
							primary: { label: 'x', range: 'x', after: moment( '2026-09-01' ), before: moment( '2026-09-30' ) },
							secondary: { label: 'y', range: 'y', after: moment( '2026-08-01' ), before: moment( '2026-08-31' ) },
					  }
					: {
							primary: { label: 'x', range: 'x', after: moment( '2026-10-01' ), before: moment( '2026-10-31' ) },
							secondary: { label: 'y', range: 'y', after: moment( '2026-09-01' ), before: moment( '2026-09-30' ) },
					  },
		},
		components: {
			DateRangeFilterPicker: StatefulDatePicker,
			Chart: StatefulChart,
			SummaryList: ( { children }: any ) => <div>{ children() }</div>,
			// The installed `SummaryNumber`: `onToggle` makes it the mobile collapse BUTTON (chevron, `is-dropdown-button`), `href` makes it a
			// link that selects the tile, neither makes it a plain figure. A link click does what wc-admin's `Link` does: it navigates.
			SummaryNumber: ( props: any ) => {
				const text = (
					<>
						{ props.label }: { props.value } ({ props.delta ?? 'no delta' }) { props.children }
					</>
				);

				if ( props.onToggle ) {
					return (
						<button type="button" data-chevron aria-expanded={ false } onClick={ props.onToggle }>
							{ text }
						</button>
					);
				}

				if ( props.href ) {
					return (
						<a
							href={ props.href }
							aria-current={ props.selected ? 'page' : undefined }
							onClick={ ( event ) => {
								event.preventDefault();
								props.onLinkClickCallback?.();
								query = Object.fromEntries( new URLSearchParams( props.href.split( '?' )[ 1 ] ) );
								act( () => listeners.forEach( ( listener ) => listener() ) );
							} }
						>
							{ text }
						</a>
					);
				}

				return <div>{ text }</div>;
			},
			// `FilterPicker` is URL-driven: a pick rewrites its own param and the `staticParams` kept from the query.
			FilterPicker: ( { config, path, query: current }: any ) => (
				<label>
					{ config.label }
					<select
						value={ current[ config.param ] ?? config.defaultValue }
						onChange={ ( event ) =>
							updateQueryString(
								{
									...Object.fromEntries( config.staticParams.filter( ( key: string ) => key in current ).map( ( key: string ) => [ key, current[ key ] ] ) ),
									[ config.param ]: event.target.value,
								},
								path,
								current
							)
						}
					>
						{ config.filters.map( ( filter: any ) => (
							<option key={ filter.value } value={ filter.value }>
								{ filter.label }
							</option>
						) ) }
					</select>
				</label>
			),
			TableCard: ( props: any ) => (
				<div data-testid="table">
					{ props.title }: { props.rows.length } rows
				</div>
			),
		},
	};
	( window as any ).woodevShippingOrders = { providers: [ { id: 'all', label: 'Все перевозчики' }, { id: 'cdek', label: 'СДЭК' } ] };
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

	it( 'a tile that drives the chart is a link to `chart=`, not a collapse toggle; a plain figure is neither', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		// `onToggle` would draw the chevron button (shorter, clipped tile in the real component) — no tile may carry it.
		expect( document.querySelector( '[data-chevron]' ) ).toBeNull();
		expect( screen.getByText( /^Выручка:/ ).closest( 'a' ) ).toHaveAttribute( 'href', '/woodev-shipping-orders?chart=revenue' );
		expect( screen.getByText( /^Отказы и возвраты:/ ).closest( 'a' ) ).toBeNull();
		expect( screen.getByText( /^От заказа до вручения/ ).closest( 'a' ) ).toBeNull();
	} );

	it( 'the time tile stays the order-to-delivery figure until a status log has an exact sample (#1564)', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		expect( screen.getByText( /^От заказа до вручения, дней:/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /^От передачи до вручения/ ) ).toBeNull();
	} );

	it( 'with an exact sample the tile reads «от передачи до вручения» with the exact value and its own coverage (#1564)', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( {
			...RESPONSE,
			totals: { ...RESPONSE.totals, current: metrics( { avg_days_handover_to_delivery: 2.5, handover_sample: 3 } ) },
			coverage: { delivered: 6, time_sample: 5, handover_sample: 3 },
		} );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		expect( screen.getByText( /^От передачи до вручения, дней: 2,5/ ) ).toBeInTheDocument();
		expect( screen.getByText( /по 3 из 6 доставленных/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /^От заказа до вручения/ ) ).toBeNull();
		expect( screen.queryByText( /по 5 из 6 доставленных/ ) ).toBeNull();
	} );

	it( 'choosing a tile switches the chart to its metric and marks the tile selected', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );
		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'Заказы по перевозчикам' );

		fireEvent.click( screen.getByText( /^Доставлено:/ ) );

		await waitFor( () => expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'Доля доставленных по перевозчикам' ) );
		expect( screen.getByText( /^Доставлено:/ ).closest( 'a' ) ).toHaveAttribute( 'aria-current', 'page' );
		expect( fetchAnalytics ).toHaveBeenCalledTimes( 1 ); // a chart switch is not a refetch.
	} );

	it( 'keeps a tile as a plain figure when this WooCommerce cannot build the link', async () => {
		delete ( window as any ).wc.navigation.getNewPath;
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		await screen.findByText( /Заказы: 10/ );

		expect( screen.getByText( /^Выручка:/ ).closest( 'a' ) ).toBeNull();
		expect( document.querySelector( '[data-chevron]' ) ).toBeNull();
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

describe( 'AnalyticsTab — the WooCommerce components it hands state to (#711 critic round 1)', () => {
	it( 'the chart\'s line/bar control writes `chartType`, and the tab reads that same key', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		expect( await screen.findByTestId( 'chart' ) ).toHaveTextContent( 'type=line' );

		fireEvent.click( screen.getByRole( 'button', { name: 'bar' } ) );

		// What the installed Chart emits (handleTypeToggle → updateQueryString( { chartType }, path, query )).
		expect( updateQueryString ).toHaveBeenCalledWith( { chartType: 'bar' }, '/woodev-shipping-orders', {} );

		query = { chartType: 'bar' };
		act( () => listeners.forEach( ( listener ) => listener() ) );

		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'type=bar' );
		expect( fetchAnalytics ).toHaveBeenCalledTimes( 1 ); // a chart toggle is not a new report.
	} );

	it( 'the date picker follows the URL after browser Back: it edits the period the report shows', async () => {
		query = { period: 'month' };
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		expect( await screen.findByTestId( 'picker' ) ).toHaveTextContent( 'PICKER EDITS: month' );

		query = { period: 'last_month' }; // the merchant picks September…
		await act( async () => listeners.forEach( ( listener ) => listener() ) );
		expect( screen.getByTestId( 'picker' ) ).toHaveTextContent( 'PICKER EDITS: last_month' );

		query = { period: 'month' }; // …and presses Back.
		await act( async () => listeners.forEach( ( listener ) => listener() ) );

		expect( screen.getByTestId( 'picker' ) ).toHaveTextContent( 'PICKER EDITS: month' );
	} );

	it( 'a carrier that plots all-zero for one metric is visible again once the next metric is positive', async () => {
		query = { chart: 'delivered_share' }; // nothing delivered yet: the carrier\'s share is 0 at every point.
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <AnalyticsTab /> );
		expect( await screen.findByTestId( 'chart' ) ).toHaveTextContent( 'visible=[]' ); // empty: the series is hidden.

		fireEvent.click( screen.getByText( /^Заказы:/ ) );
		query = { chart: 'orders' };
		act( () => listeners.forEach( ( listener ) => listener() ) );

		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'visible=[СДЭК]' );
	} );

	it( 'the same remount rule covers a new period: a carrier hidden in one report is drawn in the next', async () => {
		const quiet = { ...RESPONSE, intervals: [ { ...RESPONSE.intervals[ 0 ], carriers: { cdek: series( 0 ) } } ] };
		( fetchAnalytics as jest.Mock ).mockResolvedValueOnce( quiet ).mockResolvedValue( { ...RESPONSE, generated_at: '2026-11-03T06:00:00+00:00' } );
		render( <AnalyticsTab /> );
		expect( await screen.findByTestId( 'chart' ) ).toHaveTextContent( 'visible=[]' );

		query = { period: 'last_month' };
		act( () => listeners.forEach( ( listener ) => listener() ) );

		await waitFor( () => expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'visible=[СДЭК]' ) );
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

describe( 'DeliveryReport — the same body in WooCommerce → Аналитика (#711, stage 3)', () => {
	it( 'lives on the report path the PHP menu entry declares', () => {
		expect( REPORT_PATH ).toBe( '/analytics/woodev-delivery' );
	} );

	it( 'is the tab\'s body: the same request for the same period, the same tiles, chart and tables', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );

		render( <DeliveryReport /> );

		await screen.findByText( /Заказы: 10/ );
		expect( ( fetchAnalytics as jest.Mock ).mock.calls[ 0 ][ 0 ] ).toEqual( {
			after: '2026-10-01',
			before: '2026-10-31',
			compareAfter: '2026-09-01',
			compareBefore: '2026-09-30',
			interval: 'auto',
			carrier: 'all',
		} );
		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'Заказы по перевозчикам' );
		expect( screen.getAllByTestId( 'table' ) ).toHaveLength( 2 );
	} );

	it( 'writes every control to the REPORT\'s path, not the orders page\'s', async () => {
		( fetchAnalytics as jest.Mock ).mockResolvedValue( RESPONSE );
		render( <DeliveryReport /> );
		await screen.findByText( /Заказы: 10/ );

		expect( screen.getByText( /^Выручка:/ ).closest( 'a' ) ).toHaveAttribute( 'href', '/analytics/woodev-delivery?chart=revenue' );

		fireEvent.click( screen.getByRole( 'button', { name: 'bar' } ) ); // the Chart\'s own header control gets the report\'s path as a prop.
		expect( updateQueryString ).toHaveBeenLastCalledWith( { chartType: 'bar' }, '/analytics/woodev-delivery', {} );

		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'cdek' } } );
		expect( updateQueryString ).toHaveBeenLastCalledWith( { carrier: 'cdek' }, '/analytics/woodev-delivery', {} );
		expect( screen.getByLabelText( 'Перевозчик' ) ).toBeInTheDocument(); // labelled, like the «Заказы» tab's picker.
	} );
} );

