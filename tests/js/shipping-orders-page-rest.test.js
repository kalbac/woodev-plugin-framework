/**
 * Tests for the REST client's query-string wiring (SP-10 spec D10/D11,
 * increment 7 — the filter-row params). `@wordpress/api-fetch` is mocked;
 * these assert the URL `fetchOrders()` actually builds, which is the real
 * seam against `Orders_Controller::register_routes()`.
 *
 * @see src/shipping-orders-page/rest.ts
 */

import apiFetch from '@wordpress/api-fetch';
import { fetchOrderDocument, fetchOrders, fetchSyncStatus, getExportsInProgress, performOrderAction } from '../../src/shipping-orders-page/rest';

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

describe( 'carrier document downloads (#1134)', () => {
	/** A `Response`-shaped double: what `apiFetch( { parse: false } )` resolves with. */
	function response( { status = 200, headers = {}, json, blob } = {} ) {
		const lower = Object.fromEntries( Object.entries( headers ).map( ( [ k, v ] ) => [ k.toLowerCase(), v ] ) );

		return {
			status,
			headers: { get: ( name ) => lower[ name.toLowerCase() ] ?? null },
			json: async () => json,
			blob: async () => blob,
		};
	}

	test( 'asks the route with the nonce in the HEADER, never in the URL, and parse:false', async () => {
		apiFetch.mockResolvedValue( response( { json: { status: 'url', url: 'https://carrier.test/x.pdf' } } ) );

		await fetchOrderDocument( 42, 'waybill' );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];

		expect( call.url ).toBe( 'https://example.test/wp-json/woodev/v1/shipping/orders/42/documents/waybill?format=json' );
		expect( call.url ).not.toContain( 'nonce' );
		expect( call.headers ).toEqual( { 'X-WP-Nonce': 'abc' } );
		expect( call.method ).toBe( 'GET' );
		expect( call.parse ).toBe( false );
	} );

	test( 'on PLAIN permalinks (restRoot already holds ?rest_route=) format=json is a separate parameter, not part of the route', async () => {
		window.woodevShippingOrders = { restRoot: 'https://example.test/index.php?rest_route=/woodev/v1/shipping/orders', nonce: 'abc' };
		apiFetch.mockResolvedValue( response( { json: { status: 'url', url: 'https://carrier.test/x.pdf' } } ) );

		await fetchOrderDocument( 42, 'waybill' );

		const url = new URL( apiFetch.mock.calls[ 0 ][ 0 ].url );

		expect( url.searchParams.get( 'rest_route' ) ).toBe( '/woodev/v1/shipping/orders/42/documents/waybill' );
		expect( url.searchParams.get( 'format' ) ).toBe( 'json' );
	} );

	test( 'the orders list on plain permalinks keeps its filters out of the rest_route value too', async () => {
		window.woodevShippingOrders = { restRoot: 'https://example.test/index.php?rest_route=/woodev/v1/shipping/orders', nonce: 'abc' };

		await fetchOrders( { carrier: 'cdek', page: 2 } );

		const url = new URL( calledUrl() );

		expect( url.searchParams.get( 'rest_route' ) ).toBe( '/woodev/v1/shipping/orders' );
		expect( url.searchParams.get( 'page' ) ).toBe( '2' );
	} );

	test( 'a PDF answer becomes a file with the server\'s filename', async () => {
		const blob = new Blob( [ '%PDF-' ], { type: 'application/pdf' } );

		apiFetch.mockResolvedValue(
			response( {
				headers: {
					'Content-Type': 'application/pdf',
					'Content-Disposition': 'attachment; filename="order-42-waybill.pdf"',
				},
				blob,
			} )
		);

		expect( await fetchOrderDocument( 42, 'waybill' ) ).toEqual( { kind: 'file', blob, filename: 'order-42-waybill.pdf' } );
	} );

	test( 'a PDF answer without a Content-Disposition falls back to a sane filename', async () => {
		const blob = new Blob( [ '%PDF-' ] );

		apiFetch.mockResolvedValue( response( { headers: { 'Content-Type': 'application/pdf' }, blob } ) );

		expect( ( await fetchOrderDocument( 42, 'barcode' ) ).filename ).toBe( 'order-42-barcode.pdf' );
	} );

	test( 'a carrier link answer becomes a link', async () => {
		apiFetch.mockResolvedValue( response( { json: { status: 'url', url: 'https://carrier.test/x.pdf' } } ) );

		expect( await fetchOrderDocument( 42, 'waybill' ) ).toEqual( { kind: 'link', url: 'https://carrier.test/x.pdf' } );
	} );

	test( '202 is "pending" with the retry delay from the body, else from Retry-After, else 5', async () => {
		apiFetch.mockResolvedValue( response( { status: 202, json: { status: 'pending', message: 'Документ ещё готовится.', retry_after: 7 } } ) );
		expect( await fetchOrderDocument( 42, 'waybill' ) ).toEqual( { kind: 'pending', message: 'Документ ещё готовится.', retryAfter: 7 } );

		apiFetch.mockResolvedValue( response( { status: 202, headers: { 'Retry-After': '9' }, json: { status: 'pending' } } ) );
		expect( ( await fetchOrderDocument( 42, 'waybill' ) ).retryAfter ).toBe( 9 );

		apiFetch.mockResolvedValue( response( { status: 202, json: {} } ) );
		expect( ( await fetchOrderDocument( 42, 'waybill' ) ).retryAfter ).toBe( 5 );
	} );

	test( 'a REST error rejects with the server\'s own {code, message}, read from the raw Response', async () => {
		apiFetch.mockRejectedValue( response( { status: 502, json: { code: 'woodev_document_failed', message: 'Не удалось получить документ у перевозчика.' } } ) );

		await expect( fetchOrderDocument( 42, 'waybill' ) ).rejects.toEqual( {
			code: 'woodev_document_failed',
			message: 'Не удалось получить документ у перевозчика.',
		} );
	} );

	test( 'a rejection that is already a plain object, or an unreadable Response, still rejects with an object', async () => {
		apiFetch.mockRejectedValue( { code: 'x', message: 'нет сети' } );
		await expect( fetchOrderDocument( 42, 'waybill' ) ).rejects.toEqual( { code: 'x', message: 'нет сети' } );

		apiFetch.mockRejectedValue( { json: async () => { throw new Error( 'not json' ); } } );
		await expect( fetchOrderDocument( 42, 'waybill' ) ).rejects.toEqual( {} );
	} );

	test( 'an answer that is none of the three rejects instead of resolving with nothing', async () => {
		apiFetch.mockResolvedValue( response( { json: { status: 'weird' } } ) );

		await expect( fetchOrderDocument( 42, 'waybill' ) ).rejects.toEqual( {} );
	} );
} );

describe( 'fetchSyncStatus (#828 increment 8)', () => {
	test( 'requests the sync-status sibling route under the same REST root, with the nonce header', async () => {
		apiFetch.mockResolvedValue( { last_updated: null, carriers: [] } );

		await fetchSyncStatus();

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url ).toBe(
			'https://example.test/wp-json/woodev/v1/shipping/orders/sync-status'
		);
		expect( call.method ).toBe( 'GET' );
		expect( call.headers ).toEqual( { 'X-WP-Nonce': 'abc' } );
	} );

	test( 'sends no body for an action without fields — the request is exactly as it was', async () => {
		apiFetch.mockResolvedValue( { row: {}, message: '' } );

		await performOrderAction( 42, 'export' );

		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'data' );
	} );

	test( 'sends the values of an action\'s fields as a `payload` object (#1180)', async () => {
		apiFetch.mockResolvedValue( { row: {}, message: '' } );
		const payload = { day: '2026-10-13', window: { from: '09:00', to: '18:00' }, comment: '' };

		await performOrderAction( 42, 'call_courier', payload );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { payload } );
	} );

	test( 'strips a trailing slash from restRoot before appending the sub-path', async () => {
		window.woodevShippingOrders = {
			restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders/',
			nonce: 'abc',
		};
		apiFetch.mockResolvedValue( { last_updated: null, carriers: [] } );

		await fetchSyncStatus();

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe(
			'https://example.test/wp-json/woodev/v1/shipping/orders/sync-status'
		);
	} );

	test( 'resolves with whatever the route returns, untouched', async () => {
		const response = {
			last_updated: 1788900000,
			carriers: [
				{ id: 'test_shipping', label: 'Тестовая доставка', last_updated: 1788900000, next_update: 1788903600 },
			],
		};
		apiFetch.mockResolvedValue( response );

		await expect( fetchSyncStatus() ).resolves.toEqual( response );
	} );
} );

/**
 * `isExported` (#841) — the arg the «Новые» scope link sends. A tri-state read by
 * PRESENCE on the server (`Orders_Controller::register_routes()` gives `is_exported`
 * no default, and `Orders_Query::build_args()` uses `array_key_exists`), so the URL
 * this builds is the whole contract: an omitted param means «не фильтровать», and
 * `is_exported=false` means «только новые». Sending `true` for «Все» would silently
 * show the ALREADY-EXPORTED orders under a link that says «Все».
 */
/**
 * `performOrderAction` (#824) — the write route behind one «Действие» button. Its
 * `apiFetch` call is the actual server contract §2 of the brief fixes, so these pin the
 * URL/method/headers, not just that SOMETHING gets called.
 */
describe( 'performOrderAction (#824)', () => {
	test( 'POSTs to the order/action sub-path under the same REST root, with the nonce header', async () => {
		apiFetch.mockResolvedValue( { row: {}, message: 'Заказ выгружен.' } );

		await performOrderAction( 42, 'export' );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url ).toBe(
			'https://example.test/wp-json/woodev/v1/shipping/orders/42/actions/export'
		);
		expect( call.method ).toBe( 'POST' );
		expect( call.headers ).toEqual( { 'X-WP-Nonce': 'abc' } );
	} );

	test( 'strips a trailing slash from restRoot before appending the sub-path', async () => {
		window.woodevShippingOrders = {
			restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders/',
			nonce: 'abc',
		};
		apiFetch.mockResolvedValue( { row: {}, message: 'Заказ выгружен.' } );

		await performOrderAction( 42, 'export' );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe(
			'https://example.test/wp-json/woodev/v1/shipping/orders/42/actions/export'
		);
	} );

	test( 'resolves with the row and message untouched', async () => {
		const response = { row: { id: 42 }, message: 'Заказ выгружен.' };
		apiFetch.mockResolvedValue( response );

		await expect( performOrderAction( 42, 'export' ) ).resolves.toEqual( response );
	} );

	test( 'rejects with whatever apiFetch rejects with — the server\'s Russian message is never swallowed', async () => {
		apiFetch.mockRejectedValue( { message: 'СДЭК недоступен.', code: 'woodev_carrier_unavailable' } );

		await expect( performOrderAction( 42, 'export' ) ).rejects.toEqual( {
			message: 'СДЭК недоступен.',
			code: 'woodev_carrier_unavailable',
		} );
	} );
} );

describe( 'isExported — the «Новые» scope arg (#841)', () => {
	test( 'false sends is_exported=false — an explicit false must still filter', async () => {
		await fetchOrders( { isExported: false } );

		expect( new URL( calledUrl() ).searchParams.get( 'is_exported' ) ).toBe( 'false' );
	} );

	test( 'true sends is_exported=true', async () => {
		await fetchOrders( { isExported: true } );

		expect( new URL( calledUrl() ).searchParams.get( 'is_exported' ) ).toBe( 'true' );
	} );

	test( 'undefined omits the param entirely — that, and not `true`, is «Все»', async () => {
		await fetchOrders( {} );

		expect( new URL( calledUrl() ).searchParams.has( 'is_exported' ) ).toBe( false );
	} );
} );

describe( 'match — «Все / Любое» (#843)', () => {
	test( "'any' sends match=any", async () => {
		await fetchOrders( { match: 'any' } );

		expect( new URL( calledUrl() ).searchParams.get( 'match' ) ).toBe( 'any' );
	} );

	test( "'all' and undefined send nothing — the URL writes nothing for «Все» and the server reads the absence as all", async () => {
		await fetchOrders( { match: 'all' } );
		expect( new URL( calledUrl() ).searchParams.has( 'match' ) ).toBe( false );

		await fetchOrders( {} );
		expect( new URL( calledUrl() ).searchParams.has( 'match' ) ).toBe( false );
	} );
} );

describe( 'getExportsInProgress (#1007)', () => {
	const good = { count: 2, text: 'Сейчас выгружаются 2 заказа перевозчику', heartbeatKey: 'woodev-exports-in-progress' };

	test( 'returns the inlined object as is', () => {
		window.woodevShippingOrders.exportsInProgress = good;

		expect( getExportsInProgress() ).toEqual( good );
	} );

	test( 'accepts count 0 with an empty sentence', () => {
		window.woodevShippingOrders.exportsInProgress = { ...good, count: 0, text: '' };

		expect( getExportsInProgress() ).toEqual( { ...good, count: 0, text: '' } );
	} );

	test.each( [
		[ 'absent', undefined ],
		[ 'a non-object', 'nope' ],
		[ 'a string count', { ...good, count: '2' } ],
		[ 'a negative count', { ...good, count: -1 } ],
		[ 'an infinite count', { ...good, count: Infinity } ],
		[ 'a non-string text', { ...good, text: 2 } ],
		[ 'an empty heartbeat key', { ...good, heartbeatKey: '' } ],
		[ 'a missing heartbeat key', { count: 1, text: 'x' } ],
	] )( 'returns null for %s', ( label, value ) => {
		window.woodevShippingOrders.exportsInProgress = value;

		expect( getExportsInProgress() ).toBeNull();
	} );
} );
