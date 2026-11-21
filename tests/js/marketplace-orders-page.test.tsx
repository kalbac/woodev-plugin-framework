/**
 * «Заказы маркетплейсов»: the route PHP publishes, the tabs, the marketplace whose orders table is shown, and the plugin's old
 * «Заказы» route that forwards there.
 *
 * @see src/marketplace-admin/orders-page/
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { buildAdminPages } from '../../src/marketplace-admin/pages';
import { forwardedQuery } from '../../src/marketplace-admin/orders-page/moved-page';
import { OrdersPage } from '../../src/marketplace-admin/orders-page/orders-page';
import {
	activeMarketplace,
	activeTab,
	marketplacePatch,
	tabPatch,
} from '../../src/marketplace-admin/orders-page/route';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { Manifest, ManifestMenu, OrdersPageManifest } from '../../src/marketplace-admin/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const LABELS = {
	marketplace: 'Маркетплейс',
	tabs: 'Разделы страницы',
	orders: 'Заказы',
	analytics: 'Аналитика',
};

const ANALYTICS = { intro: 'На этой странице отображаются заказы маркетплейса за выбранный период.', refresh: 'Обновить', loadFailed: 'Не удалось загрузить аналитику.' };

function ordersPage( marketplaces: OrdersPageManifest[ 'marketplaces' ] ): OrdersPageManifest {
	return {
		path: '/woodev-marketplace-orders',
		title: 'Заказы маркетплейсов',
		breadcrumbs: [ 'Заказы маркетплейсов' ],
		domId: 'toplevel_page_woocommerce',
		marketplaces,
		labels: LABELS,
		analytics: ANALYTICS,
	};
}

const OZON = { id: 'ozon-seller', label: 'Ozon', table: 'orders', help: { url: 'https://woodev.ru/docs/ozon/orders', text: 'Подробнее' } };
const WB = { id: 'wildberries', label: 'Wildberries', table: 'orders' };

function FakeTableCard( props: TableCardProps ) {
	return props.isLoading ? <div data-testid="table-loading" /> : <table aria-label={ props.title } />;
}

let navigate: jest.Mock;

function requestedOwners(): string[] {
	return fetchMock.mock.calls.map( ( [ options ] ) => decodeURIComponent( options.path ).split( '/admin/records/' )[ 1 ]?.split( '/' )[ 0 ] ?? '' );
}

beforeEach( () => {
	fetchMock.mockReset();
	navigate = jest.fn();
	( window as unknown as { wc: unknown } ).wc = {
		components: { TableCard: FakeTableCard },
		navigation: { updateQueryString: navigate },
	};
	fetchMock.mockImplementation( ( { path }: { path: string } ) =>
		Promise.resolve(
			decodeURIComponent( path ).split( '?' )[ 0 ].endsWith( '/definition' )
				? fixtureDefinitionEnvelope( fixtureDefinition() )
				: fixtureList( [ fixtureRow( 'p1', 'Кружка' ) ] )
		)
	);
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'the URL', () => {
	const page = ordersPage( [ OZON, WB ] );

	test( 'the marketplace is the one the URL names, the first one for anything else', () => {
		expect( activeMarketplace( page, { marketplace: 'wildberries' } ) ).toBe( WB );
		expect( activeMarketplace( page, { marketplace: 'yandex-market' } ) ).toBe( OZON );
		expect( activeMarketplace( page, {} ) ).toBe( OZON );
	} );

	test( 'the orders tab is the absence of the argument', () => {
		expect( activeTab( {} ) ).toBe( 'orders' );
		expect( activeTab( { tab: 'analytics' } ) ).toBe( 'analytics' );
		expect( activeTab( { tab: 'reports' } ) ).toBe( 'orders' );
	} );

	test( 'a switch keeps wc-admin\'s arguments and drops everything of the table that was left', () => {
		const query = { page: 'wc-admin', path: '/woodev-marketplace-orders', marketplace: 'ozon-seller', account: '3', filter_state: 'open', paged: '2' };

		expect( marketplacePatch( query, 'wildberries' ) ).toEqual( { marketplace: 'wildberries', account: undefined, filter_state: undefined, paged: undefined } );
		expect( tabPatch( query, 'analytics' ) ).toEqual( { tab: 'analytics', account: undefined, filter_state: undefined, paged: undefined } );
		expect( tabPatch( { ...query, tab: 'analytics' }, 'orders' ) ).toEqual( { tab: undefined, account: undefined, filter_state: undefined, paged: undefined } );
	} );
} );

describe( 'the routes PHP publishes', () => {
	const menu = {
		id: 'ozon-seller',
		pluginId: 'ozon-seller',
		title: 'Ozon Seller',
		domId: 'toplevel_page_wc-admin-path--ozon-seller',
		pages: [
			{ id: 'orders', title: 'Заказы', path: '/ozon-seller/orders', kind: 'records', tables: [ 'orders' ], view: '', breadcrumbs: [], analytics: null, movedTo: { path: '/woodev-marketplace-orders', query: { marketplace: 'ozon-seller' } } },
		],
	} as unknown as ManifestMenu;

	test( 'the shared page is a route of its own that keeps the WooCommerce menu open', () => {
		const manifest: Manifest = { menus: [ menu ], ordersPage: ordersPage( [ OZON ] ) };
		const pages = buildAdminPages( manifest );

		expect( pages.map( ( page ) => page.path ) ).toEqual( [ '/ozon-seller/orders', '/woodev-marketplace-orders' ] );
		expect( pages[ 1 ].wpOpenMenu ).toBe( 'toplevel_page_woocommerce' );
		expect( pages[ 1 ].breadcrumbs ).toEqual( [ 'Заказы маркетплейсов' ] );
		expect( buildAdminPages( { menus: [ menu ] } ).map( ( page ) => page.path ) ).toEqual( [ '/ozon-seller/orders' ] );
	} );

	test( 'the plugin\'s old route forwards to the shared page in place, keeping its own arguments', () => {
		const replace = jest.fn();
		const getNewPath = jest.fn( () => 'admin.php?page=wc-admin&path=%2Fwoodev-marketplace-orders&marketplace=ozon-seller&account=3' );
		( window as unknown as { wc: unknown } ).wc = { navigation: { getNewPath, getHistory: () => ( { replace } ) } };

		const Container = buildAdminPages( { menus: [ menu ] } )[ 0 ].container;
		const { container } = render( <Container query={ { page: 'wc-admin', path: '/ozon-seller/orders', account: '3' } } /> );

		expect( getNewPath ).toHaveBeenCalledWith( { account: '3', marketplace: 'ozon-seller' }, '/woodev-marketplace-orders', {} );
		expect( replace ).toHaveBeenCalledWith( 'admin.php?page=wc-admin&path=%2Fwoodev-marketplace-orders&marketplace=ozon-seller&account=3' );
		expect( container ).toBeEmptyDOMElement();
		expect( fetchMock ).not.toHaveBeenCalled();
	} );

	test( 'the move\'s own preset wins over an argument of the old route', () => {
		expect( forwardedQuery( { path: '/x', query: { marketplace: 'ozon-seller' } }, { path: '/old', marketplace: 'other', search: 'P-1' } ) ).toEqual( { marketplace: 'ozon-seller', search: 'P-1' } );
	} );
} );

describe( 'the page', () => {
	test( 'one marketplace: its orders table, the tabs and its help link, no marketplace select', async () => {
		render( <OrdersPage page={ ordersPage( [ OZON ] ) } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Заказы маркетплейсов' } );

		expect( new Set( requestedOwners() ) ).toEqual( new Set( [ 'ozon-seller' ] ) );
		expect( screen.getByRole( 'tab', { name: 'Заказы' } ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByRole( 'tab', { name: 'Аналитика' } ) ).toHaveAttribute( 'aria-selected', 'false' );
		expect( screen.queryByRole( 'combobox', { name: 'Маркетплейс' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: /Подробнее/ } ) ).toHaveAttribute( 'href', 'https://woodev.ru/docs/ozon/orders' );
	} );

	test( 'several marketplaces: the select leads the filter row and a choice switches the table through the URL', async () => {
		render( <OrdersPage page={ ordersPage( [ OZON, WB ] ) } query={ { marketplace: 'wildberries', account: 'a1' } } /> );
		await screen.findByRole( 'table', { name: 'Заказы маркетплейсов' } );

		const select = screen.getByRole( 'combobox', { name: 'Маркетплейс' } );

		expect( new Set( requestedOwners() ) ).toEqual( new Set( [ 'wildberries' ] ) );
		expect( select ).toHaveValue( 'wildberries' );
		expect( select.closest( '.woodev-records__toolbar' )?.firstElementChild?.contains( select ) ).toBe( true );

		fireEvent.change( select, { target: { value: 'ozon-seller' } } );

		expect( navigate ).toHaveBeenLastCalledWith( { marketplace: 'ozon-seller', account: undefined } );
	} );

	test( 'a stale preset with another plugin\'s account opens the shown marketplace from scratch and fixes the URL', async () => {
		render( <OrdersPage page={ ordersPage( [ WB, OZON ] ) } query={ { marketplace: 'yandex-market', account: '7', filter_state: 'open' } } /> );
		await screen.findByRole( 'table', { name: 'Заказы маркетплейсов' } );

		const paths = fetchMock.mock.calls.map( ( [ options ] ) => decodeURIComponent( options.path ) );

		expect( new Set( requestedOwners() ) ).toEqual( new Set( [ 'wildberries' ] ) );
		expect( paths.some( ( path ) => path.includes( 'account_id' ) && path.includes( '7' ) ) ).toBe( false );
		expect( paths.some( ( path ) => path.includes( 'state' ) ) ).toBe( false );
		expect( navigate ).toHaveBeenCalledWith( { marketplace: 'wildberries', account: undefined, filter_state: undefined } );
		expect( screen.getByRole( 'combobox', { name: 'Маркетплейс' } ) ).toHaveValue( 'wildberries' );
	} );

	test( 'a URL that names its marketplace is left alone', async () => {
		render( <OrdersPage page={ ordersPage( [ OZON, WB ] ) } query={ { marketplace: 'ozon-seller', account: 'a1' } } /> );
		await screen.findByRole( 'table', { name: 'Заказы маркетплейсов' } );

		expect( navigate ).not.toHaveBeenCalled();
	} );

	test( 'the analytics tab reads the marketplace\'s analytics in PHP\'s words and no table', () => {
		render( <OrdersPage page={ ordersPage( [ OZON ] ) } query={ { tab: 'analytics' } } /> );

		expect( screen.getByText( ANALYTICS.intro ) ).toBeInTheDocument();
		expect( requestedOwners() ).toEqual( [] );
		expect( fetchMock.mock.calls.every( ( [ options ] ) => String( options.path ).includes( '/admin/marketplace/ozon-seller/orders/analytics' ) ) ).toBe( true );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Заказы' } ) );

		expect( navigate ).toHaveBeenLastCalledWith( { tab: undefined } );
	} );
} );
