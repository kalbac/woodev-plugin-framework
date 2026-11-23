/**
 * The «Аналитика» tab of «Заказы маркетплейсов» (#1891): the URL → request mapping, the states, the tiles and tables in PHP's words,
 * and the links from a status count to the «Заказы» tab.
 *
 * WooCommerce's components are stand-ins that render their props as text; the fetch is mocked.
 *
 * @see src/marketplace-admin/orders-page/analytics/
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { AnalyticsTab, chartPoints } from '../../src/marketplace-admin/orders-page/analytics/analytics-tab';
import {
	accountOf,
	analyticsPath,
	buildRequest,
	chartMetricOf,
	intervalOf,
	statusPatch,
} from '../../src/marketplace-admin/orders-page/analytics/request';
import { sortedProducts, withShare } from '../../src/marketplace-admin/orders-page/analytics/tables';
import type { AnalyticsResponse } from '../../src/marketplace-admin/orders-page/analytics/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const MESSAGES: Record< string, string > = {
	intro: 'На этой странице отображаются заказы маркетплейса за выбранный период.',
	account: 'Аккаунт',
	allAccounts: 'Все аккаунты',
	refresh: 'Обновить',
	retry: 'Повторить',
	loadFailed: 'Не удалось загрузить аналитику.',
	unavailable: 'Аналитика недоступна.',
	empty: 'За выбранный период заказов с маркетплейса нет.',
	previous: 'Предыдущий период:',
	orders: 'Заказы',
	revenue: 'Выручка',
	cancelShare: 'Отменено',
	avgOrder: 'Средний чек',
	unitsPerOrder: 'Товаров в заказе',
	share: 'Доля в выручке магазина',
	cancelledNote: 'отменено заказов: %s',
	shareNote: 'выручка магазина: %s',
	chartOrders: 'Заказы по аккаунтам',
	chartRevenue: 'Выручка по аккаунтам',
	chartItems: 'Аккаунты',
	chartEmpty: 'За этот период данных нет.',
	byAccount: 'По аккаунтам',
	cancelled: 'Отменено',
	statuses: 'Отправления в работе по статусам',
	statusesHint: 'Число открывает список заказов с этим статусом.',
	status: 'Статус на маркетплейсе',
	count: 'Отправлений',
	total: 'Всего',
	statusesEmpty: 'Сейчас нет отправлений в работе.',
	stuck: 'Зависшие отправления',
	stuckEmpty: 'Зависших отправлений нет.',
	order: 'Заказ',
	posting: 'Отправление',
	waiting: 'Ждёт, ч',
	products: 'Топ товаров',
	product: 'Товар',
	quantity: 'Продано, шт.',
	productsEmpty: 'За этот период товары не продавались.',
	unlinked: 'Продано, но не связано с сайтом',
	unlinkedHint: 'Позиции без товара магазина.',
	unlinkedName: 'Название на маркетплейсе',
	unlinkedEmpty: 'Все проданные позиции связаны с товарами магазина.',
	footnote: 'Выручка — сумма заказов без отменённых и возвращённых.',
	footnoteOrders: 'Отменённые — заказы со статусом «Отменён».',
};

const metrics = ( overrides = {} ) => ( {
	orders: 5,
	cancelled: 1,
	cancel_share: 0.2,
	sold: 4,
	revenue: 2000,
	avg_order: 500,
	units: 9,
	units_per_order: 2.25,
	...overrides,
} );

const ACCOUNTS = [
	{ id: '1', label: 'Основной' },
	{ id: '2', label: 'Второй' },
];

function response( overrides: Partial< AnalyticsResponse > = {} ): AnalyticsResponse {
	return {
		range: { after: '2026-11-01', before: '2026-11-07', interval: 'day' },
		compare: { after: '2026-10-25', before: '2026-10-31' },
		account: 'all',
		accounts: ACCOUNTS,
		options: ACCOUNTS,
		totals: { current: metrics(), previous: metrics( { orders: 2 } ), delta: { orders: 150, revenue: 150, cancel_share: -60 } },
		by_account: [
			{ id: '1', label: 'Основной', current: metrics( { orders: 4 } ), previous: null, delta: {} },
			{ id: '2', label: 'Второй', current: metrics( { orders: 1, cancelled: 0, cancel_share: 0 } ), previous: null, delta: {} },
		],
		intervals: [ { start: '2026-11-01', end: '2026-11-01', totals: { orders: 2, revenue: 1200 }, accounts: { 1: { orders: 2, revenue: 1200 } } } ],
		share: { shop_revenue: 3500, marketplace_revenue: 2000, share: 0.5714, previous: 0.8, delta: -28.6 },
		classified: true,
		statuses: [ { code: 'awaiting_packaging', label: 'Ожидает упаковки', tone: 'warning', count: 3, accounts: { 1: 2, 2: 1 } } ],
		stuck: {
			threshold_hours: 24,
			count: 1,
			items: [ { order_id: 101, order_url: '/wp-admin/post.php?post=101&action=edit', posting: 'P-101', account: 'Основной', status: 'Ожидает упаковки', hours: 146 } ],
			hint: 'Отправления, которые ждут упаковки или отгрузки дольше 24 часов.',
			more: '',
		},
		products: {
			top: [
				{ key: '11-0', label: 'Футболка', url: '/p/11', quantity: 2, revenue: 1000 },
				{ key: '13-14', label: 'Худи — L', url: '', quantity: 3, revenue: 300 },
			],
			unlinked: { items: [ { label: 'Кружка', quantity: 3, revenue: 400 } ], names: 1, quantity: 3, revenue: 400 },
		},
		currency: 'RUB',
		generated_at: '2026-11-07T12:00:00+00:00',
		cached: false,
		...overrides,
	};
}

const moment = ( text: string ) => ( { format: () => text } );

let navigate: jest.Mock;

function FakeTableCard( props: TableCardProps ) {
	return (
		<table aria-label={ props.title }>
			<thead>
				<tr>
					{ props.headers.map( ( header ) => (
						<th key={ header.key }>
							{ header.isSortable ? (
								<button type="button" onClick={ () => props.onSort( header.key, 'desc' ) }>
									{ header.label }
								</button>
							) : (
								header.label
							) }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ props.rows.map( ( row, index ) => (
					<tr key={ index }>
						{ row.map( ( entry, column ) => (
							<td key={ column }>{ entry.display }</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function installWooCommerce() {
	( window as any ).wc = {
		navigation: {
			updateQueryString: navigate,
			getNewPath: ( patch: Record< string, string | undefined >, path: string, current: Record< string, string | undefined > ) => {
				const merged = Object.entries( { ...current, ...patch } ).filter( ( [ , value ] ) => value !== undefined ) as [ string, string ][];

				return `admin.php?page=wc-admin&path=${ path }&${ new URLSearchParams( merged ).toString() }`;
			},
		},
		date: {
			isoDateFormat: 'YYYY-MM-DD',
			getDateParamsFromQuery: ( q: Record< string, string | undefined > ) => ( { period: q.period ?? 'month', compare: 'previous_period', after: null, before: null } ),
			getCurrentDates: () => ( {
				primary: { label: 'x', range: 'x', after: moment( '2026-11-01' ), before: moment( '2026-11-07' ) },
				secondary: { label: 'y', range: 'y', after: moment( '2026-10-25' ), before: moment( '2026-10-31' ) },
			} ),
		},
		components: {
			TableCard: FakeTableCard,
			SummaryList: ( { children }: any ) => <div data-testid="tiles">{ children() }</div>,
			SummaryNumber: ( props: any ) => (
				<div data-testid={ `tile-${ props.label }` } data-href={ props.href ?? '' } data-selected={ String( Boolean( props.selected ) ) } data-reverse={ String( Boolean( props.reverseTrend ) ) }>
					{ props.label }: { props.value } / { props.prevValue ?? 'none' } ({ props.delta ?? 'no delta' }) { props.children }
				</div>
			),
			Chart: ( props: any ) => (
				<div data-testid="chart">
					{ props.title }|{ props.interval }|{ Object.entries( props.data[ 0 ] ?? {} ).filter( ( [ key ] ) => key !== 'date' ).map( ( [ , entry ]: any ) => entry.label ).join( ',' ) }
				</div>
			),
			DateRangeFilterPicker: ( props: any ) => (
				<button type="button" onClick={ () => props.onRangeSelect( { period: 'week', compare: 'previous_year' } ) }>
					PICKER
				</button>
			),
		},
	};
}

function renderTab( query: Record< string, string | undefined > = {} ) {
	return render( <AnalyticsTab marketplace="ozon-seller" messages={ MESSAGES } path="/woodev-marketplace-orders" query={ { marketplace: 'ozon-seller', tab: 'analytics', ...query } } /> );
}

function requestedPaths(): string[] {
	return fetchMock.mock.calls.map( ( [ options ] ) => options.path as string );
}

beforeEach( () => {
	fetchMock.mockReset();
	navigate = jest.fn();
	installWooCommerce();
	fetchMock.mockResolvedValue( response() );
} );

describe( 'the URL of the tab', () => {
	test( 'an account is a number; anything else is every account', () => {
		expect( accountOf( { account: '2' } ) ).toBe( '2' );
		expect( accountOf( {} ) ).toBe( 'all' );
		expect( accountOf( { account: 'x1' } ) ).toBe( 'all' );
		expect( chartMetricOf( { chart: 'revenue' } ) ).toBe( 'revenue' );
		expect( chartMetricOf( { chart: 'delivered_share' } ) ).toBe( 'orders' );
		expect( intervalOf( { interval: 'week' } ) ).toBe( 'week' );
		expect( intervalOf( { interval: 'year' } ) ).toBe( 'auto' );
	} );

	test( 'the request carries the period, its comparison and the account; refresh only when asked', () => {
		const range = { after: '2026-11-01', before: '2026-11-07', compareAfter: '2026-10-25', compareBefore: '2026-10-31', dateQuery: {} as any };
		const request = buildRequest( 'ozon-seller', { account: '2', interval: 'week' }, range );

		expect( request ).toEqual( { pluginId: 'ozon-seller', after: '2026-11-01', before: '2026-11-07', compareAfter: '2026-10-25', compareBefore: '2026-10-31', interval: 'week', account: '2' } );
		expect( analyticsPath( request!, false ) ).toBe(
			'/woodev/v1/admin/marketplace/ozon-seller/orders/analytics?after=2026-11-01&before=2026-11-07&interval=week&compare_after=2026-10-25&compare_before=2026-10-31&account=2'
		);
		expect( analyticsPath( { ...request!, account: 'all', compareAfter: null, compareBefore: null }, true ) ).toBe(
			'/woodev/v1/admin/marketplace/ozon-seller/orders/analytics?after=2026-11-01&before=2026-11-07&interval=week&refresh=1'
		);
		expect( buildRequest( 'ozon-seller', {}, null ) ).toBeNull();
	} );

	test( 'a status link opens the «Заказы» tab on that account\'s orders in that status, finished ones too, and leaves nothing of the analytics behind', () => {
		expect( statusPatch( { marketplace: 'ozon-seller', tab: 'analytics', period: 'week', account: 'all', chart: 'revenue' }, 'ozon-seller', '2', 'awaiting_packaging' ) ).toEqual( {
			tab: undefined,
			period: undefined,
			chart: undefined,
			marketplace: 'ozon-seller',
			account: '2',
			filter_state: undefined,
			filter_posting_status: 'awaiting_packaging',
		} );
	} );

	test( 'two accounts with one name are two series, told apart in the legend', () => {
		const same = [
			{ id: '1', label: 'Магазин' },
			{ id: '2', label: 'Магазин' },
		];
		const points = chartPoints(
			response( {
				accounts: same,
				intervals: [ { start: '2026-11-01', end: '2026-11-01', totals: { orders: 3, revenue: 1500 }, accounts: { 1: { orders: 2, revenue: 1200 }, 2: { orders: 1, revenue: 300 } } } ],
			} ),
			'revenue'
		);

		expect( points ).toEqual( [
			{
				date: '2026-11-01T00:00:00',
				'account-1': { label: 'Магазин (#1)', value: 1200 },
				'account-2': { label: 'Магазин (#2)', value: 300 },
			},
		] );
	} );

	test( 'the best sellers are the top of the chosen sort; a share reads «count · percent»', () => {
		const top = response().products.top;

		expect( sortedProducts( top, 'revenue' ).map( ( row ) => row.label ) ).toEqual( [ 'Футболка', 'Худи — L' ] );
		expect( sortedProducts( top, 'quantity' ).map( ( row ) => row.label ) ).toEqual( [ 'Худи — L', 'Футболка' ] );
		expect( withShare( 3, 0.25 ) ).toMatch( /^3 · 25\s%$/ );
		expect( withShare( 0, null ) ).toBe( '0 · —' );
	} );
} );

describe( 'the tab', () => {
	test( 'it reads the period the URL resolves to, for every account, and draws the tiles, the chart and the tables in PHP\'s words', async () => {
		renderTab();

		expect( await screen.findByTestId( 'tile-Заказы' ) ).toHaveTextContent( 'Заказы: 5 / 2 (150)' );
		expect( requestedPaths() ).toEqual( [
			'/woodev/v1/admin/marketplace/ozon-seller/orders/analytics?after=2026-11-01&before=2026-11-07&interval=auto&compare_after=2026-10-25&compare_before=2026-10-31',
		] );
		expect( screen.getByText( MESSAGES.intro ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'tile-Заказы' ) ).toHaveAttribute( 'data-selected', 'true' );
		expect( screen.getByTestId( 'tile-Выручка' ).getAttribute( 'data-href' ) ).toContain( 'chart=revenue' );
		expect( screen.getByTestId( 'tile-Отменено' ) ).toHaveTextContent( 'отменено заказов: 1' );
		expect( screen.getByTestId( 'tile-Отменено' ) ).toHaveAttribute( 'data-reverse', 'true' );
		expect( screen.getByTestId( 'tile-Доля в выручке магазина' ) ).toHaveTextContent( /57,1\s%/ );
		expect( screen.getByTestId( 'tile-Доля в выручке магазина' ) ).toHaveTextContent( /выручка магазина: 3\s500\s₽/ );
		expect( screen.getByTestId( 'chart' ) ).toHaveTextContent( 'Заказы по аккаунтам|day|Основной,Второй' );
		expect( screen.getByRole( 'table', { name: 'По аккаунтам' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'table', { name: 'Топ товаров' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'table', { name: 'Продано, но не связано с сайтом' } ) ).toHaveTextContent( 'Кружка' );
		expect( screen.getByText( MESSAGES.footnote ) ).toBeInTheDocument();
	} );

	test( 'the revenue tile\'s chart plots revenue', async () => {
		renderTab( { chart: 'revenue' } );

		expect( await screen.findByTestId( 'chart' ) ).toHaveTextContent( 'Выручка по аккаунтам' );
		expect( screen.getByTestId( 'tile-Выручка' ) ).toHaveAttribute( 'data-selected', 'true' );
	} );

	test( 'the account and the period are written to the URL; a chosen account is in the request', async () => {
		renderTab();
		await screen.findByTestId( 'tiles' );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Аккаунт' } ), { target: { value: '2' } } );
		expect( navigate ).toHaveBeenLastCalledWith( { account: '2' } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Аккаунт' } ), { target: { value: 'all' } } );
		expect( navigate ).toHaveBeenLastCalledWith( { account: undefined } );

		fireEvent.click( screen.getByRole( 'button', { name: 'PICKER' } ) );
		expect( navigate ).toHaveBeenLastCalledWith( { period: 'week', compare: 'previous_year' } );

		fetchMock.mockClear();
		renderTab( { account: '2' } );
		await waitFor( () => expect( requestedPaths()[ 0 ] ).toContain( '&account=2' ) );
	} );

	test( 'one account: the share tile is the account\'s share of the shop and names the shop revenue like for all accounts', async () => {
		fetchMock.mockResolvedValue( response( { account: '2', share: { shop_revenue: 3500, marketplace_revenue: 300, share: 0.0857, previous: null, delta: null } } ) );
		renderTab( { account: '2' } );

		const tile = await screen.findByTestId( 'tile-Доля в выручке магазина' );

		expect( tile ).toHaveTextContent( /8,6\s%/ );
		expect( tile ).toHaveTextContent( /выручка магазина: 3\s500\s₽/ );
	} );

	test( 'one account: no account select, the status table has one column of counts', async () => {
		const one = [ ACCOUNTS[ 0 ] ];
		fetchMock.mockResolvedValue( response( { accounts: one, options: one, by_account: response().by_account.slice( 0, 1 ) } ) );
		renderTab();
		const statuses = await screen.findByRole( 'table', { name: MESSAGES.statuses } );

		expect( screen.queryByRole( 'combobox', { name: 'Аккаунт' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'table', { name: 'По аккаунтам' } ) ).not.toBeInTheDocument();
		expect( within( statuses ).getAllByRole( 'columnheader' ).map( ( header ) => header.textContent ) ).toEqual( [ MESSAGES.status, MESSAGES.count ] );
	} );

	test( 'every status count is a link to that account\'s orders in that status; a plain click stays in wc-admin', async () => {
		renderTab();
		const statuses = await screen.findByRole( 'table', { name: MESSAGES.statuses } );
		const links = within( statuses ).getAllByRole( 'link' );

		expect( within( statuses ).getAllByRole( 'columnheader' ).map( ( header ) => header.textContent ) ).toEqual( [ MESSAGES.status, 'Основной', 'Второй', 'Всего' ] );
		expect( links.map( ( link ) => link.textContent ) ).toEqual( [ '2', '1' ] );
		expect( links[ 1 ].getAttribute( 'href' ) ).toContain( 'account=2' );
		expect( links[ 1 ].getAttribute( 'href' ) ).toContain( 'filter_posting_status=awaiting_packaging' );
		expect( links[ 1 ].getAttribute( 'href' ) ).not.toContain( 'tab=analytics' );

		fireEvent.click( links[ 0 ] );

		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { tab: undefined, account: '1', filter_state: undefined, filter_posting_status: 'awaiting_packaging' } ) );
	} );

	test( 'the stuck postings link their orders and say the threshold', async () => {
		renderTab();
		const stuck = await screen.findByRole( 'table', { name: MESSAGES.stuck } );

		expect( screen.getByText( response().stuck.hint ) ).toBeInTheDocument();
		expect( within( stuck ).getByRole( 'link', { name: '#101' } ) ).toHaveAttribute( 'href', '/wp-admin/post.php?post=101&action=edit' );
		expect( stuck ).toHaveTextContent( '146' );
	} );

	test( 'the best sellers sort by revenue first and by units on the header', async () => {
		renderTab();
		const products = await screen.findByRole( 'table', { name: MESSAGES.products } );
		const names = () => within( products ).getAllByRole( 'row' ).slice( 1 ).map( ( row ) => row.firstChild?.textContent );

		expect( names() ).toEqual( [ 'Футболка', 'Худи — L' ] );
		expect( within( products ).getByRole( 'link', { name: 'Футболка' } ) ).toHaveAttribute( 'href', '/p/11' );

		fireEvent.click( within( products ).getByRole( 'button', { name: MESSAGES.quantity } ) );

		expect( names() ).toEqual( [ 'Худи — L', 'Футболка' ] );
	} );

	test( '«Обновить» asks for a fresh read', async () => {
		renderTab();
		await screen.findByTestId( 'tiles' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить' } ) );

		await waitFor( () => expect( requestedPaths() ).toHaveLength( 2 ) );
		expect( requestedPaths()[ 1 ] ).toContain( '&refresh=1' );
	} );

	test( 'a failed read shows the server\'s words and a retry', async () => {
		fetchMock.mockRejectedValue( { code: 'woodev_marketplace_analytics_failed', message: 'Не удалось собрать аналитику.' } );
		renderTab();

		expect( await screen.findByText( 'Не удалось собрать аналитику.' ) ).toBeInTheDocument();

		fetchMock.mockResolvedValue( response() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByTestId( 'tiles' ) ).toBeInTheDocument();
	} );

	test( 'an account that is gone sends the tab back to every account', async () => {
		fetchMock.mockRejectedValue( { code: 'woodev_marketplace_analytics_unknown_account', message: 'Такого аккаунта нет.' } );
		renderTab( { account: '9' } );

		await waitFor( () => expect( navigate ).toHaveBeenCalledWith( { account: undefined } ) );
		expect( screen.queryByText( 'Такого аккаунта нет.' ) ).not.toBeInTheDocument();
	} );

	test( 'an empty period says so and still shows the shop share and what is there now', async () => {
		fetchMock.mockResolvedValue(
			response( {
				totals: { current: metrics( { orders: 0, revenue: 0 } ), previous: null, delta: {} },
				share: { shop_revenue: 100, marketplace_revenue: 100, share: 1, previous: null, delta: null },
			} )
		);
		renderTab();

		expect( await screen.findByText( MESSAGES.empty ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'tile-Доля в выручке магазина' ) ).toHaveTextContent( /100\s%/ );
		expect( screen.queryByTestId( 'chart' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'table', { name: 'Топ товаров' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'table', { name: MESSAGES.statuses } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'table', { name: MESSAGES.stuck } ) ).toBeInTheDocument();
	} );

	test( 'an adapter that does not classify its statuses gets no stuck block and no promise about rejections', async () => {
		fetchMock.mockResolvedValue( response( { classified: false, stuck: null } ) );
		renderTab();

		expect( await screen.findByRole( 'table', { name: MESSAGES.statuses } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table', { name: MESSAGES.stuck } ) ).not.toBeInTheDocument();
		expect( screen.queryByText( MESSAGES.stuckEmpty ) ).not.toBeInTheDocument();
		expect( screen.queryByText( MESSAGES.stuck ) ).not.toBeInTheDocument();
		expect( screen.getByText( MESSAGES.footnoteOrders ) ).toBeInTheDocument();
		expect( screen.queryByText( MESSAGES.footnote ) ).not.toBeInTheDocument();
	} );

	test( 'an empty table is the empty card with its sentence', async () => {
		fetchMock.mockResolvedValue( response( { stuck: { ...response().stuck!, count: 0, items: [] } } ) );
		renderTab();

		expect( await screen.findByText( MESSAGES.stuckEmpty ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table', { name: MESSAGES.stuck } ) ).not.toBeInTheDocument();
	} );
} );
