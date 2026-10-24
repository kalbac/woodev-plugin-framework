/**
 * REST URLs built from a base that already holds a query (#1439).
 *
 * With plain permalinks WordPress hands out `https://site/index.php?rest_route=/woodev/v1/…`. A
 * second `?` appended to it ends up INSIDE the `rest_route` value, the route is not found and the
 * city suggestions / pickup points stop working. Every builder here is pinned with both base
 * shapes: pretty (`/wp-json/woodev/v1/…`) and plain (`/index.php?rest_route=/woodev/v1/…`).
 * (`pickup-datasource.js` and `location-cascade.js` have their own cases in their own suites.)
 *
 * @see src/components/rest-url.ts
 * @see src/components/location-picker.tsx
 * @see src/shipping-orders-page/order-wizard/api.ts
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { withQuery } from '../../src/components/rest-url';
import LocationPicker from '../../src/components/location-picker';
import {
	fetchAddressPolicy,
	checkPickupPoint,
	searchProducts,
	searchCustomers,
} from '../../src/shipping-orders-page/order-wizard/api';

jest.mock( '@wordpress/api-fetch' );

const PRETTY = 'https://example.test/wp-json/woodev/v1';
const PLAIN = 'https://example.test/index.php?rest_route=/woodev/v1';

/** The route WordPress would dispatch for `url`, and the other query parameters it would see. */
const parse = ( url ) => {
	const parsed = new URL( url );

	return {
		route: parsed.searchParams.get( 'rest_route' ) ?? parsed.pathname.replace( '/wp-json', '' ),
		params: parsed.searchParams,
		questionMarks: url.split( '?' ).length - 1,
	};
};

describe( 'withQuery', () => {
	test.each( [
		[ `${ PRETTY }/location/suggest`, `${ PRETTY }/location/suggest?q=a&level=settlement` ],
		[ `${ PLAIN }/location/suggest`, `${ PLAIN }/location/suggest&q=a&level=settlement` ],
	] )( 'joins %s with the right separator', ( base, expected ) => {
		expect( withQuery( base, 'q=a&level=settlement' ) ).toBe( expected );
	} );
} );

describe.each( [
	[ 'pretty', PRETTY ],
	[ 'plain', PLAIN ],
] )( 'LocationPicker on %s permalinks', ( _shape, root ) => {
	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { suggestions: [] } );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	test( 'asks the suggest route with its parameters outside the route', async () => {
		render( createElement( LocationPicker, { value: null, level: 'settlement', country: 'RU', restRoot: root, onChange: () => {} } ) );
		fireEvent.click( screen.getAllByRole( 'button' )[ 0 ] );
		fireEvent.change( await screen.findByRole( 'searchbox' ), { target: { value: 'Жуко' } } );
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		const { route, params, questionMarks } = parse( apiFetch.mock.calls[ 0 ][ 0 ].url );

		expect( route ).toBe( '/woodev/v1/location/suggest' );
		expect( questionMarks ).toBe( 1 );
		expect( params.get( 'q' ) ).toBe( 'Жуко' );
		expect( params.get( 'level' ) ).toBe( 'settlement' );
		expect( params.get( 'country' ) ).toBe( 'RU' );
	} );
} );

describe.each( [
	[ 'pretty', `${ PRETTY }/shipping/orders`, `${ PRETTY }/shipping/pickup/carrier/points` ],
	[ 'plain', `${ PLAIN }/shipping/orders`, `${ PLAIN }/shipping/pickup/carrier/points` ],
] )( 'the order wizard network layer on %s permalinks', ( _shape, ordersRoot, pointsRoot ) => {
	beforeEach( () => {
		apiFetch.mockReset();
		window.woodevShippingOrders = { restRoot: ordersRoot, nonce: 'nonce-1', providers: [], wizard: {} };
	} );

	afterEach( () => {
		delete window.woodevShippingOrders;
	} );

	test( 'fetchAddressPolicy() keeps country and pickup out of the route', async () => {
		apiFetch.mockResolvedValue( {} );

		await fetchAddressPolicy( 'KZ', true );

		const { route, params, questionMarks } = parse( apiFetch.mock.calls[ 0 ][ 0 ].url );

		expect( route ).toBe( '/woodev/v1/shipping/orders/address-policy' );
		expect( questionMarks ).toBe( 1 );
		expect( params.get( 'country' ) ).toBe( 'KZ' );
		expect( params.get( 'pickup' ) ).toBe( '1' );
	} );

	test( 'checkPickupPoint() keeps the weight, payment method and location out of the route', async () => {
		apiFetch.mockResolvedValue( { selectable: { allowed: true } } );

		await checkPickupPoint( `${ pointsRoot }/`, 'P1', 1500, 'cod', { city: 'Москва' }, 'carrier', 7 );

		const { route, params, questionMarks } = parse( apiFetch.mock.calls[ 0 ][ 0 ].url );

		expect( route ).toBe( '/woodev/v1/shipping/pickup/carrier/points/P1' );
		expect( questionMarks ).toBe( 1 );
		expect( params.get( 'weight' ) ).toBe( '1500' );
		expect( params.get( 'payment_method' ) ).toBe( 'cod' );
		expect( params.get( 'instance_id' ) ).toBe( '7' );
		expect( params.get( 'location[city]' ) ).toBe( 'Москва' );
	} );

	test.each( [
		[ 'searchProducts', () => searchProducts( 'шапка' ), '/wc/v3/products', 'search' ],
		[ 'searchCustomers', () => searchCustomers( 'иван' ), '/wc/v3/customers', 'search' ],
	] )( '%s() keeps its search outside the WooCommerce route', async ( _name, run, expectedRoute, param ) => {
		apiFetch.mockResolvedValue( [] );

		await run();

		const { route, params, questionMarks } = parse( apiFetch.mock.calls[ 0 ][ 0 ].url );

		expect( route ).toBe( expectedRoute );
		expect( questionMarks ).toBe( 1 );
		expect( params.get( param ) ).not.toBe( null );
	} );
} );
