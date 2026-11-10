/**
 * The marketplace routing bundle: the manifest PHP publishes becomes wc-admin routes, one per page.
 *
 * @see src/marketplace-admin/
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { addFilter, applyFilters, removeFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';
import { readManifest } from '../../src/marketplace-admin/manifest';
import { buildAdminPages } from '../../src/marketplace-admin/pages';
import { PAGE_VIEW_FILTER } from '../../src/marketplace-admin/page-view';
import type { Manifest, ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';

function page( id: string, title: string, kind: ManifestPage[ 'kind' ] = 'records' ): ManifestPage {
	return {
		id,
		title,
		path: `/ozon-seller/${ id }`,
		kind,
		tables: kind === 'records' ? [ id ] : [],
		view: kind === 'custom' ? id : '',
		breadcrumbs: [ [ '/ozon-seller/products', 'OZON Seller' ], title ],
		analytics: null,
	};
}

const OZON: ManifestMenu = {
	id: 'ozon-seller',
	pluginId: 'ozon',
	title: 'OZON Seller',
	domId: 'toplevel_page_wc-admin-path--ozon-seller-products',
	pages: [ page( 'settings', 'Настройки', 'settings' ), page( 'products', 'Товары' ), page( 'tools', 'Инструменты', 'custom' ) ],
};

const WILDBERRIES: ManifestMenu = {
	id: 'wildberries',
	pluginId: 'wb',
	title: 'Wildberries',
	domId: 'toplevel_page_wc-admin-path--wildberries-products',
	pages: [ { ...page( 'products', 'Товары' ), path: '/wildberries/products' } ],
};

describe( 'readManifest', () => {
	test( 'a window without a manifest adds no route', () => {
		expect( readManifest( {} ) ).toEqual( { menus: [] } );
	} );

	test( 'a manifest without a menu list adds no route', () => {
		expect( readManifest( { woodevMarketplaceAdmin: {} as Manifest } ) ).toEqual( { menus: [] } );
	} );

	test( 'a published manifest is read as it is', () => {
		const manifest: Manifest = { menus: [ OZON ] };

		expect( readManifest( { woodevMarketplaceAdmin: manifest } ) ).toBe( manifest );
	} );
} );

describe( 'buildAdminPages', () => {
	test( 'one route per page, in the order PHP listed them', () => {
		const pages = buildAdminPages( { menus: [ OZON ] } );

		expect( pages.map( ( entry ) => entry.path ) ).toEqual( [
			'/ozon-seller/settings',
			'/ozon-seller/products',
			'/ozon-seller/tools',
		] );
	} );

	test( 'paths, breadcrumbs and the menu to open come from PHP, not from the bundle', () => {
		const [ , products ] = buildAdminPages( { menus: [ OZON ] } );

		expect( products.breadcrumbs ).toEqual( [ [ '/ozon-seller/products', 'OZON Seller' ], 'Товары' ] );
		expect( products.wpOpenMenu ).toBe( 'toplevel_page_wc-admin-path--ozon-seller-products' );
	} );

	test( 'two marketplace plugins add the routes of both menus to the one bundle', () => {
		const pages = buildAdminPages( { menus: [ OZON, WILDBERRIES ] } );

		expect( pages ).toHaveLength( 4 );
		expect( pages[ 3 ].path ).toBe( '/wildberries/products' );
		expect( pages[ 3 ].wpOpenMenu ).toBe( WILDBERRIES.domId );
	} );

	test( 'an empty manifest adds nothing', () => {
		expect( buildAdminPages( { menus: [] } ) ).toEqual( [] );
	} );
} );

describe( 'a page container', () => {
	afterEach( () => {
		removeFilter( PAGE_VIEW_FILTER, 'test/view' );
	} );

	test( 'shows the PHP title until a view registers for the page', () => {
		const [ , products ] = buildAdminPages( { menus: [ OZON ] } );

		render( createElement( products.container, { query: {} } ) );

		expect( screen.getByRole( 'heading', { name: 'Товары' } ) ).toBeInTheDocument();
	} );

	test( 'renders the view a bundle registers through the page filter, with the page, the menu and the route query', () => {
		const seen: unknown[] = [];

		addFilter( PAGE_VIEW_FILTER, 'test/view', ( current, forPage: ManifestPage, forMenu: ManifestMenu ) => {
			if ( forPage.kind !== 'custom' ) {
				return current;
			}

			return ( { page: p, menu, query } ) => {
				seen.push( [ p.id, menu.id, query ] );

				return createElement( 'p', null, `view of ${ forPage.view } in ${ forMenu.id }` );
			};
		} );

		const [ , products, tools ] = buildAdminPages( { menus: [ OZON ] } );

		render( createElement( tools.container, { query: { account: '7' } } ) );

		expect( screen.getByText( 'view of tools in ozon-seller' ) ).toBeInTheDocument();
		expect( seen ).toEqual( [ [ 'tools', 'ozon-seller', { account: '7' } ] ] );

		// A page of another kind is not claimed by that view.
		render( createElement( products.container, { query: {} } ) );
		expect( screen.getByRole( 'heading', { name: 'Товары' } ) ).toBeInTheDocument();
	} );
} );

describe( 'the bundle entry', () => {
	afterEach( () => {
		removeFilter( 'woocommerce_admin_pages_list', 'woodev/marketplace-admin' );
		delete window.woodevMarketplaceAdmin;
		jest.resetModules();
	} );

	test( 'adds the manifest pages to the list wc-admin reads, keeping the pages already on it', async () => {
		window.woodevMarketplaceAdmin = { menus: [ OZON, WILDBERRIES ] };

		await import( '../../src/marketplace-admin/index' );

		const existing = { path: '/analytics/overview' };
		const pages = applyFilters( 'woocommerce_admin_pages_list', [ existing ] ) as Array< { path: string } >;

		expect( pages[ 0 ] ).toBe( existing );
		expect( pages.map( ( entry ) => entry.path ) ).toEqual( [
			'/analytics/overview',
			'/ozon-seller/settings',
			'/ozon-seller/products',
			'/ozon-seller/tools',
			'/wildberries/products',
		] );
	} );
} );
