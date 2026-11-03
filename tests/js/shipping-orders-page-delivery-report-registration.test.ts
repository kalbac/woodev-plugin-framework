/**
 * Registration of the «Доставка перевозчиками» report (#711, stage 3): the entry appended to `woocommerce_admin_reports_list`, and that the
 * page bundle's entry point registers it next to the orders page — in the SAME bundle, so the orders page itself is untouched.
 *
 * @see src/shipping-orders-page/analytics/report-registration.ts
 * @see src/shipping-orders-page/index.tsx
 */

import { addFilter } from '@wordpress/hooks';
import DeliveryReport from '../../src/shipping-orders-page/analytics/delivery-report';
import {
	addDeliveryReport,
	REPORT_FILTER,
	REPORT_FILTER_NAMESPACE,
	REPORT_MENU_ID,
} from '../../src/shipping-orders-page/analytics/report-registration';

jest.mock( '@wordpress/hooks', () => ( { addFilter: jest.fn() } ) );
jest.mock( '../../src/shipping-orders-page/page-tabs', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../src/shipping-orders-page/style.scss', () => ( {} ), { virtual: true } );

describe( 'addDeliveryReport', () => {
	const WC_REPORT = { report: 'orders', title: 'Orders', component: () => null, navArgs: { id: 'woocommerce-analytics-orders' } };

	it( 'appends our report after WooCommerce\'s own, which stay as they were', () => {
		const reports = [ WC_REPORT ];

		const result = addDeliveryReport( reports );

		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ] ).toBe( WC_REPORT );
		expect( reports ).toHaveLength( 1 ); // the filter\'s input is not mutated.
	} );

	it( 'is `woodev-delivery` — the tail of /analytics/woodev-delivery — with the menu entry\'s id and the shared body', () => {
		const entry = addDeliveryReport( [] )[ 0 ];

		expect( entry.report ).toBe( 'woodev-delivery' );
		expect( entry.title ).toBe( 'Доставка перевозчиками' );
		expect( entry.component ).toBe( DeliveryReport );
		expect( entry.navArgs ).toEqual( { id: 'woodev-shipping-delivery' } );
		expect( REPORT_MENU_ID ).toBe( 'woodev-shipping-delivery' );
	} );
} );

describe( 'the bundle entry point', () => {
	it( 'registers the orders page AND the report, each on its own filter, in one bundle', () => {
		jest.isolateModules( () => {
			require( '../../src/shipping-orders-page/index' );
		} );

		const calls = ( addFilter as jest.Mock ).mock.calls;
		const hooks = calls.map( ( call ) => call[ 0 ] );

		expect( hooks ).toEqual( [ 'woocommerce_admin_pages_list', REPORT_FILTER ] );
		expect( REPORT_FILTER ).toBe( 'woocommerce_admin_reports_list' );
		expect( calls[ 0 ][ 1 ] ).toBe( 'woodev/shipping-orders' );
		expect( calls[ 1 ][ 1 ] ).toBe( REPORT_FILTER_NAMESPACE );
		// A fresh module instance (isolateModules): compare what the callback does, not its identity.
		expect( calls[ 1 ][ 2 ]( [] ).map( ( entry: { report: string } ) => entry.report ) ).toEqual( [ 'woodev-delivery' ] );
	} );

	it( 'leaves the orders page\'s own registration as it was', () => {
		const pagesCallback = ( addFilter as jest.Mock ).mock.calls[ 0 ][ 2 ];

		const pages = pagesCallback( [] );

		expect( pages ).toHaveLength( 1 );
		expect( pages[ 0 ].path ).toBe( '/woodev-shipping-orders' );
	} );
} );
