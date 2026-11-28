/**
 * A proxy or a bot filter may replace an answer with a body of its own (#2065). Under the marketplace middleware every
 * failed request carries a message, and the HTTP status of a replaced one — checked through the real `apiFetch`, which
 * must still renew an expired nonce.
 *
 * @see src/components/foreign-answer.ts
 */

import apiFetch from '@wordpress/api-fetch';
import { nameForeignAnswers } from '../../src/components/foreign-answer';

const PATH = '/woodev/v1/admin/marketplace/fake/tools';

/** A raw `Response` whose body is `body`, or is not JSON at all when `body` is omitted. */
function response( status: number, body?: unknown ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => {
			if ( undefined === body ) {
				throw new SyntaxError( 'Unexpected token <' );
			}

			return body;
		},
		text: async () => 'fresh-nonce',
	};
}

const fetchMock = jest.fn();

beforeAll( () => {
	apiFetch.use( nameForeignAnswers( /woodev\/v1\/admin\/(records|marketplace)\// ) );
	global.fetch = fetchMock as unknown as typeof fetch;
} );

beforeEach( () => fetchMock.mockReset() );

describe( 'answers of the marketplace routes', () => {
	test( 'a successful answer is its JSON', async () => {
		fetchMock.mockResolvedValue( response( 200, { tools: [] } ) );

		await expect( apiFetch( { path: PATH } ) ).resolves.toEqual( { tools: [] } );
	} );

	test( 'a refusal of the server is passed on as it is', async () => {
		const refusal = { code: 'woodev_tools_disabled', message: 'Инструмент выключен.', data: { status: 409 } };
		fetchMock.mockResolvedValue( response( 409, refusal ) );

		await expect( apiFetch( { path: PATH } ) ).rejects.toEqual( refusal );
	} );

	test.each( [
		[ 'a page of its own', 502, undefined ],
		[ 'a JSON body without a message', 503, { error: 'blocked' } ],
		[ 'a JSON body with an empty message', 403, { message: '' } ],
		[ 'a JSON `null`', 504, null ],
		[ 'a JSON message without a REST status', 403, { message: 'Access denied' } ],
		[ 'a page served with a 200', 200, undefined ],
	] )( 'an answer replaced by %s names the HTTP status', async ( name, status, body ) => {
		fetchMock.mockResolvedValue( response( status, body ) );

		await expect( apiFetch( { path: PATH } ) ).rejects.toMatchObject( {
			message: expect.stringContaining( `HTTP ${ status }` ),
			httpStatus: status,
		} );
	} );

	test( 'a full address of the routes is looked after too, a plain permalink included', async () => {
		fetchMock.mockResolvedValue( response( 502 ) );

		await expect(
			apiFetch( { url: 'https://x.test/index.php?rest_route=/woodev/v1/admin/marketplace/fake/accounts/1/deletion' } )
		).rejects.toMatchObject( { httpStatus: 502 } );
	} );

	test( 'a request that got no answer keeps `apiFetch`’s own error', async () => {
		fetchMock.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		await expect( apiFetch( { path: PATH } ) ).rejects.toMatchObject( { code: 'fetch_error' } );
	} );

	test( 'another plugin’s route is left alone', async () => {
		fetchMock.mockResolvedValue( response( 502 ) );

		await expect( apiFetch( { path: '/wc/v3/orders' } ) ).rejects.toEqual(
			expect.not.objectContaining( { httpStatus: 502 } )
		);
	} );

	// Last: the nonce middleware stays registered on the shared `apiFetch`.
	test( 'an expired nonce is still renewed and the request repeated with the new one', async () => {
		apiFetch.nonceEndpoint = 'https://x.test/nonce';
		apiFetch.nonceMiddleware = apiFetch.createNonceMiddleware( 'stale' );
		apiFetch.use( apiFetch.nonceMiddleware );
		fetchMock
			.mockResolvedValueOnce(
				response( 403, {
					code: 'rest_cookie_invalid_nonce',
					message: 'Проверка не пройдена',
					data: { status: 403 },
				} )
			)
			.mockResolvedValueOnce( response( 200 ) )
			.mockResolvedValueOnce( response( 200, { tools: [] } ) );

		await expect( apiFetch( { path: PATH } ) ).resolves.toEqual( { tools: [] } );
		expect( fetchMock ).toHaveBeenCalledTimes( 3 );
		expect( fetchMock.mock.calls[ 0 ][ 1 ].headers ).toMatchObject( { 'X-WP-Nonce': 'stale' } );
		expect( fetchMock.mock.calls[ 2 ][ 1 ].headers ).toMatchObject( { 'X-WP-Nonce': 'fresh-nonce' } );
	} );
} );
