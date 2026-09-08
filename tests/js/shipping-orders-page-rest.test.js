/**
 * Tests for the REST client's query-string wiring (SP-10 spec D10/D11,
 * increment 7 — the filter-row params). `@wordpress/api-fetch` is mocked;
 * these assert the URL `fetchOrders()` actually builds, which is the real
 * seam against `Orders_Controller::register_routes()`.
 *
 * @see src/shipping-orders-page/rest.ts
 */

import apiFetch from '@wordpress/api-fetch';
import { fetchOrders } from '../../src/shipping-orders-page/rest';

jest.mock( '@wordpress/api-fetch' );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { rows: [], total: 0, total_pages: 0 } );
	window.woodevShippingOrders = { restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders', nonce: 'abc' };
} );

function calledUrl() {
	return apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].url;
}

describe( 'fetchOrders — the new filter-row params', () => {
	test( 'omits after/before/status/delivery_status/has_tracking when unset — no invented params', async () => {
		await fetchOrders( {} );

		const url = new URL( calledUrl() );
		expect( url.searchParams.has( 'after' ) ).toBe( false );
		expect( url.searchParams.has( 'before' ) ).toBe( false );
		expect( url.searchParams.has( 'status' ) ).toBe( false );
		expect( url.searchParams.has( 'delivery_status' ) ).toBe( false );
		expect( url.searchParams.has( 'has_tracking' ) ).toBe( false );
	} );

	test( 'sends after/before verbatim as ISO dates', async () => {
		await fetchOrders( { after: '2026-01-01', before: '2026-09-08' } );

		const url = new URL( calledUrl() );
		expect( url.searchParams.get( 'after' ) ).toBe( '2026-01-01' );
		expect( url.searchParams.get( 'before' ) ).toBe( '2026-09-08' );
	} );

	test( 'sends a single order status as a bare value, and multiple as comma-joined — WP REST\'s own array-arg convention', async () => {
		await fetchOrders( { status: [ 'processing' ] } );
		expect( new URL( calledUrl() ).searchParams.get( 'status' ) ).toBe( 'processing' );

		await fetchOrders( { status: [ 'processing', 'completed' ] } );
		expect( new URL( calledUrl() ).searchParams.get( 'status' ) ).toBe( 'processing,completed' );
	} );

	test( 'sends deliveryStatus as `delivery_status`', async () => {
		await fetchOrders( { deliveryStatus: 'in_transit' } );

		expect( new URL( calledUrl() ).searchParams.get( 'delivery_status' ) ).toBe( 'in_transit' );
	} );

	describe( 'hasTracking — a tri-state the REST route reads by presence, not truthiness', () => {
		test( 'true sends has_tracking=true', async () => {
			await fetchOrders( { hasTracking: true } );
			expect( new URL( calledUrl() ).searchParams.get( 'has_tracking' ) ).toBe( 'true' );
		} );

		test( 'false sends has_tracking=false — not omitted as falsy', async () => {
			await fetchOrders( { hasTracking: false } );
			expect( new URL( calledUrl() ).searchParams.get( 'has_tracking' ) ).toBe( 'false' );
		} );

		test( 'undefined omits the param entirely', async () => {
			await fetchOrders( {} );
			expect( new URL( calledUrl() ).searchParams.has( 'has_tracking' ) ).toBe( false );
		} );
	} );
} );
