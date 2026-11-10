/**
 * The settings body inside a marketplace plugin's own «Настройки» page (framework F3, #1681).
 *
 * Two promises: the Woodev → Настройки page is untouched (no props, the same request, byte for byte), and the embedded
 * body asks for its own plugin's providers only (`?placement=`) and gives its bootstrap back when it goes away.
 *
 * @see src/settings-page/embedded-view.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import App from '../../src/settings-page/app';
import { getBootstrap, setBootstrap } from '../../src/settings-page/bootstrap';
import { SettingsPageView, resolveSettingsView } from '../../src/settings-page/embedded-view';
import { fetchSchema } from '../../src/settings-page/rest';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );

const WOODEV_PAGE = { restRoot: 'https://shop.test/wp-json/woodev/v1/settings', nonce: 'n-1', adminUrl: 'https://shop.test/wp-admin/' };

const TAB = {
	id: 'ozon',
	label: 'OZON Seller',
	capability: 'manage_woocommerce',
	sections: [
		{
			id: 'accounts',
			label: 'Аккаунты',
			description: '',
			fields: { client_id: { id: 'client_id', type: 'string', controlType: 'text', name: 'Client ID', value: '' } },
		},
	],
};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { tabs: [ TAB ] } );
	window.woodevSettings = { ...WOODEV_PAGE };
	setBootstrap( null );
} );

afterEach( () => {
	delete window.woodevSettings;
	setBootstrap( null );
} );

describe( 'the Woodev page keeps its request', () => {
	it( 'asks for the bare REST root, with no placement', async () => {
		await fetchSchema();

		expect( apiFetch ).toHaveBeenCalledWith( {
			url: WOODEV_PAGE.restRoot,
			method: 'GET',
			headers: { 'X-WP-Nonce': 'n-1' },
		} );
	} );

	it( 'mounts with no props and reads the page global', async () => {
		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Client ID' ) ).toBeInTheDocument() );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe( WOODEV_PAGE.restRoot );
	} );
} );

describe( 'the embedded body lists its own plugin only', () => {
	it( 'adds the placement to the request', async () => {
		setBootstrap( { placement: 'woocommerce-ozon-seller', restRoot: 'https://shop.test/wp-json/woodev/v1/settings', nonce: 'n-2' } );

		await fetchSchema();

		expect( apiFetch ).toHaveBeenCalledWith( {
			url: 'https://shop.test/wp-json/woodev/v1/settings?placement=woocommerce-ozon-seller',
			method: 'GET',
			headers: { 'X-WP-Nonce': 'n-2' },
		} );
	} );

	it( 'continues a query string a plain-permalink REST root already has', async () => {
		setBootstrap( { placement: 'ozon', restRoot: 'https://shop.test/index.php?rest_route=/woodev/v1/settings', nonce: 'n' } );

		await fetchSchema();

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe( 'https://shop.test/index.php?rest_route=/woodev/v1/settings&placement=ozon' );
	} );

	it( 'renders the plugin\'s tabs from the props and forgets them on unmount', async () => {
		const bootstrap = { placement: 'ozon', restRoot: 'https://shop.test/wp-json/woodev/v1/settings', nonce: 'n-3' };

		const { unmount } = render( <App bootstrap={ bootstrap } /> );

		await waitFor( () => expect( screen.getByText( 'Client ID' ) ).toBeInTheDocument() );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe( 'https://shop.test/wp-json/woodev/v1/settings?placement=ozon' );
		expect( getBootstrap() ).toBe( bootstrap );

		unmount();

		expect( getBootstrap() ).toBe( window.woodevSettings );
	} );

	it( 'is handed the menu\'s plugin as its placement and the page global\'s root and nonce', async () => {
		render( <SettingsPageView menu={ { pluginId: 'woocommerce-ozon-seller' } } /> );

		await waitFor( () => expect( screen.getByText( 'Client ID' ) ).toBeInTheDocument() );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual( {
			url: 'https://shop.test/wp-json/woodev/v1/settings?placement=woocommerce-ozon-seller',
			method: 'GET',
			headers: { 'X-WP-Nonce': 'n-1' },
		} );
	} );
} );

describe( 'the page-view filter', () => {
	it( 'answers for a settings page', () => {
		expect( resolveSettingsView( null, { kind: 'settings' } ) ).toBe( SettingsPageView );
	} );

	it( 'leaves every other page to the bundle that owns it', () => {
		expect( resolveSettingsView( null, { kind: 'records' } ) ).toBeNull();
		expect( resolveSettingsView( null, { kind: 'custom' } ) ).toBeNull();
	} );

	it( 'does not replace a view another callback already chose', () => {
		const other = () => null;

		expect( resolveSettingsView( other, { kind: 'settings' } ) ).toBe( other );
	} );
} );
