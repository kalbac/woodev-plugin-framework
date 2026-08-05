/**
 * Tests for pickup-datasource.js
 *
 * Covers query serialisation, the nonce header, de-duplication, the
 * fetchPoints() debounce, the distinct server error shapes, and the
 * out-of-order/supersede guarantee — SP-5 Task 11.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-datasource.js
 */

'use strict';

const WoodevPickupDataSource = require( '../../woodev/shipping-method/assets/js/frontend/pickup-datasource' );

const REST_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/pickup/carrier/points';
const NONCE = 'test-nonce-abc';

/**
 * Builds a fetch-like Response double.
 *
 * @param {number}  status
 * @param {*}       body
 * @param {boolean} [ok]
 */
function response( status, body, ok ) {
	return {
		ok: undefined !== ok ? ok : status >= 200 && status < 300,
		status: status,
		json: function() {
			return Promise.resolve( body );
		},
	};
}

/**
 * Installs a fetch mock that resolves every call immediately with the same
 * response, and returns the jest.fn() so callers can inspect its calls.
 *
 * @param {number} status
 * @param {*}      body
 */
function mockFetchOnce( status, body ) {
	global.fetch = jest.fn( function() {
		return Promise.resolve( response( status, body ) );
	} );

	return global.fetch;
}

/**
 * Installs a fetch mock whose calls resolve only when the caller manually
 * settles the returned deferred — used to control resolution ORDER across
 * multiple in-flight requests.
 */
function mockFetchDeferred() {
	const deferreds = [];

	global.fetch = jest.fn( function() {
		let resolveFn;
		const promise = new Promise( function( resolve ) {
			resolveFn = resolve;
		} );
		deferreds.push( { promise: promise, resolve: resolveFn } );

		return promise;
	} );

	return deferreds;
}

afterEach( () => {
	delete global.fetch;
	jest.useRealTimers();
} );

// -----------------------------------------------------------------------
// Query serialisation
// -----------------------------------------------------------------------

test( 'fetchPoints() with only a locality sends exactly locality=<value>', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'Москва' } );
	jest.advanceTimersByTime( 300 );
	await promise;

	expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( REST_ROOT + '?locality=' + encodeURIComponent( 'Москва' ) );
} );

test( 'fetchPoints() bbox is bounds joined lat1,lng1,lat2,lng2 — exact separator and order', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { bounds: [ 55.1, 37.2, 55.9, 37.9 ], q: 'твер' } );
	jest.advanceTimersByTime( 300 );
	await promise;

	const expectedUrl = REST_ROOT
		+ '?bbox=' + encodeURIComponent( '55.1,37.2,55.9,37.9' )
		+ '&q=' + encodeURIComponent( 'твер' );

	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( expectedUrl );
} );

test( 'fetchPoints() with locality, bounds AND q together emits all three in locality, bbox, q order', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'Москва', bounds: [ 1, 2, 3, 4 ], q: 'твер' } );
	jest.advanceTimersByTime( 300 );
	await promise;

	const expectedUrl = REST_ROOT
		+ '?locality=' + encodeURIComponent( 'Москва' )
		+ '&bbox=' + encodeURIComponent( '1,2,3,4' )
		+ '&q=' + encodeURIComponent( 'твер' );

	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( expectedUrl );
} );

test( 'a bounds array of the wrong arity is omitted from the query rather than sent malformed', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { bounds: [ 1, 2, 3 ] } );
	jest.advanceTimersByTime( 300 );
	await promise;

	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( REST_ROOT );
} );

// -----------------------------------------------------------------------
// Nonce header
// -----------------------------------------------------------------------

test( 'fetchPoints() sends the REST nonce as the X-WP-Nonce header', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'A' } );
	jest.advanceTimersByTime( 300 );
	await promise;

	expect( fetchMock.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( NONCE );
} );

test( 'fetchDetails() sends the REST nonce as the X-WP-Nonce header too', async () => {
	const fetchMock = mockFetchOnce( 200, { id: 'P1' } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	await ds.fetchDetails( 'P1' );

	expect( fetchMock.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( NONCE );
	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( REST_ROOT + '/P1' );
} );

// -----------------------------------------------------------------------
// De-duplication
// -----------------------------------------------------------------------

test( 'de-duplicates points by id, keeping the first occurrence', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 200, {
		points: [
			{ id: 'A', name: 'first' },
			{ id: 'B', name: 'only' },
			{ id: 'A', name: 'stale-duplicate' },
		],
	} );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );
	const points = await promise;

	expect( points ).toEqual( [ { id: 'A', name: 'first' }, { id: 'B', name: 'only' } ] );
} );

// -----------------------------------------------------------------------
// Empty result vs. rejection
// -----------------------------------------------------------------------

test( '{ points: [] } resolves to an empty array — not a rejection', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'Nowhere' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).resolves.toEqual( [] );
} );

test( 'a failed response rejects rather than returning an empty list', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 502, {
		code: 'woodev_pickup_upstream_error',
		message: 'Сервис пунктов выдачи временно недоступен.',
		data: { status: 502 },
	}, false );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).rejects.toBeTruthy();
} );

// -----------------------------------------------------------------------
// Distinct server error shapes
// -----------------------------------------------------------------------

test( '502 upstream error surfaces status 502 and code woodev_pickup_upstream_error', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 502, {
		code: 'woodev_pickup_upstream_error',
		message: 'Carrier unavailable.',
		data: { status: 502 },
	}, false );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).rejects.toMatchObject( { status: 502, code: 'woodev_pickup_upstream_error' } );
} );

test( '429 rate limit surfaces status 429 and code woodev_pickup_rate_limited', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 429, {
		code: 'woodev_pickup_rate_limited',
		message: 'Too many requests.',
		data: { status: 429 },
	}, false );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).rejects.toMatchObject( { status: 429, code: 'woodev_pickup_rate_limited' } );
} );

test( 'fetchDetails() 404 surfaces status 404 and code woodev_pickup_point_not_found', async () => {
	mockFetchOnce( 404, {
		code: 'woodev_pickup_point_not_found',
		message: 'Пункт выдачи не найден.',
		data: { status: 404 },
	}, false );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	await expect( ds.fetchDetails( 'unknown' ) ).rejects.toMatchObject( {
		status: 404,
		code: 'woodev_pickup_point_not_found',
	} );
} );

test( 'prefers the body\'s own data.status over the transport-level response.status', async () => {
	jest.useFakeTimers();
	// A deliberately mismatched pair: response.status stays generic (500) while the
	// WP_Error body carries the controller's actual, more specific status (502).
	mockFetchOnce( 500, {
		code: 'woodev_pickup_upstream_error',
		message: 'Carrier unavailable.',
		data: { status: 502 },
	}, false );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).rejects.toMatchObject( { status: 502 } );
} );

test( 'a network failure (fetch itself rejects) surfaces as a rejection with a non-null-safe code', async () => {
	jest.useFakeTimers();
	global.fetch = jest.fn( function() {
		return Promise.reject( new Error( 'offline' ) );
	} );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const promise = ds.fetchPoints( { locality: 'X' } );
	jest.advanceTimersByTime( 300 );

	await expect( promise ).rejects.toMatchObject( { status: null, code: 'woodev_pickup_network_error' } );
} );

// -----------------------------------------------------------------------
// Debounce
// -----------------------------------------------------------------------

test( 'collapses rapid bbox calls into one request, using the LAST call\'s query', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [ { id: 'P1' } ] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	const p1 = ds.fetchPoints( { locality: 'first' } );
	const p2 = ds.fetchPoints( { locality: 'second' } );
	const p3 = ds.fetchPoints( { locality: 'third' } );

	jest.advanceTimersByTime( 300 );

	await Promise.all( [ p1, p2, p3 ] );

	expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	expect( fetchMock.mock.calls[ 0 ][ 0 ] ).toBe( REST_ROOT + '?locality=third' );
} );

test( 'every promise in a debounced burst resolves (not just the last), all with the same result', async () => {
	jest.useFakeTimers();
	mockFetchOnce( 200, { points: [ { id: 'P1' } ] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	const p1 = ds.fetchPoints( { locality: 'a' } );
	const p2 = ds.fetchPoints( { locality: 'b' } );

	jest.advanceTimersByTime( 300 );

	const [ r1, r2 ] = await Promise.all( [ p1, p2 ] );

	expect( r1 ).toEqual( [ { id: 'P1' } ] );
	expect( r2 ).toEqual( [ { id: 'P1' } ] );
} );

test( 'a burst that starts again after the debounce window fires a second, separate request', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	await ( function() {
		const p = ds.fetchPoints( { locality: 'first' } );
		jest.advanceTimersByTime( 300 );
		return p;
	}() );

	await ( function() {
		const p = ds.fetchPoints( { locality: 'second' } );
		jest.advanceTimersByTime( 300 );
		return p;
	}() );

	expect( fetchMock ).toHaveBeenCalledTimes( 2 );
} );

test( 'fetchDetails() is never debounced — each call fires immediately', async () => {
	const fetchMock = mockFetchOnce( 200, { id: 'P1' } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );
	const p1 = ds.fetchDetails( 'P1' );
	const p2 = ds.fetchDetails( 'P2' );

	// No timer advance at all — both calls must already have hit fetch().
	expect( fetchMock ).toHaveBeenCalledTimes( 2 );

	await Promise.all( [ p1, p2 ] );
} );

test( 'a custom debounceMs is honoured', async () => {
	jest.useFakeTimers();
	const fetchMock = mockFetchOnce( 200, { points: [] } );

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE, debounceMs: 50 } );
	const promise = ds.fetchPoints( { locality: 'a' } );

	jest.advanceTimersByTime( 49 );
	expect( fetchMock ).not.toHaveBeenCalled();

	jest.advanceTimersByTime( 1 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );

	await promise;
} );

// -----------------------------------------------------------------------
// Supersede: an out-of-order-delivered stale response must never win
// -----------------------------------------------------------------------

test( 'a stale response delivered AFTER a newer one resolves to the newer result, not its own', async () => {
	jest.useFakeTimers();
	const deferreds = mockFetchDeferred();

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	// Burst A flushes (request #1 issued, still pending).
	const pA = ds.fetchPoints( { locality: 'viewport-A' } );
	jest.advanceTimersByTime( 300 );

	// The customer pans again after the debounce window closes — burst B flushes
	// (request #2 issued, also still pending). Two requests now in flight.
	const pB = ds.fetchPoints( { locality: 'viewport-B' } );
	jest.advanceTimersByTime( 300 );

	expect( deferreds.length ).toBe( 2 );

	// Resolve OUT OF ORDER: the newer request (#2/B) answers first, the older
	// request (#1/A) answers last — exactly the race the supersede logic exists for.
	deferreds[ 1 ].resolve( response( 200, { points: [ { id: 'B1' } ] } ) );
	deferreds[ 0 ].resolve( response( 200, { points: [ { id: 'A1' } ] } ) );

	const resultA = await pA;
	const resultB = await pB;

	// The stale (A) promise must NOT resolve with its own (now-superseded) A1 payload —
	// it adopts the newer, currently-relevant B1 result instead.
	expect( resultA ).toEqual( [ { id: 'B1' } ] );
	expect( resultB ).toEqual( [ { id: 'B1' } ] );
} );

test( 'when the newer request rejects, a superseded older request adopts that rejection', async () => {
	jest.useFakeTimers();
	const deferreds = mockFetchDeferred();

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	const pA = ds.fetchPoints( { locality: 'viewport-A' } );
	jest.advanceTimersByTime( 300 );

	const pB = ds.fetchPoints( { locality: 'viewport-B' } );
	jest.advanceTimersByTime( 300 );

	deferreds[ 1 ].resolve( response( 502, {
		code: 'woodev_pickup_upstream_error',
		message: 'Carrier unavailable.',
		data: { status: 502 },
	}, false ) );
	deferreds[ 0 ].resolve( response( 200, { points: [ { id: 'A1' } ] } ) );

	await expect( pA ).rejects.toMatchObject( { code: 'woodev_pickup_upstream_error' } );
	await expect( pB ).rejects.toMatchObject( { code: 'woodev_pickup_upstream_error' } );
} );

test( 'in-order delivery (no race) still resolves each burst with its own result', async () => {
	jest.useFakeTimers();
	const deferreds = mockFetchDeferred();

	const ds = WoodevPickupDataSource( { restRoot: REST_ROOT, nonce: NONCE } );

	const pA = ds.fetchPoints( { locality: 'viewport-A' } );
	jest.advanceTimersByTime( 300 );
	deferreds[ 0 ].resolve( response( 200, { points: [ { id: 'A1' } ] } ) );
	const resultA = await pA;

	expect( resultA ).toEqual( [ { id: 'A1' } ] );

	const pB = ds.fetchPoints( { locality: 'viewport-B' } );
	jest.advanceTimersByTime( 300 );
	deferreds[ 1 ].resolve( response( 200, { points: [ { id: 'B1' } ] } ) );
	const resultB = await pB;

	expect( resultB ).toEqual( [ { id: 'B1' } ] );
} );
