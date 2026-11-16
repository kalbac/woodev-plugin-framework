/**
 * The settings page's REST client: a page instead of JSON becomes a readable message (#1595),
 * and the connection test is called on `/verify`, not on the generic word `/test`.
 *
 * @see src/settings-page/rest.js
 */

import apiFetch from '@wordpress/api-fetch';
import { setBootstrap } from '../../src/settings-page/bootstrap';
import { testConnection, saveTab } from '../../src/settings-page/rest';

jest.mock( '@wordpress/api-fetch' );

describe( 'settings page REST client', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		setBootstrap( { restRoot: 'https://example.test/wp-json/woodev/v1/settings', nonce: 'n' } );
	} );

	afterEach( () => setBootstrap( null ) );

	it( 'calls the connection check on /verify', async () => {
		apiFetch.mockResolvedValue( { success: true, message: 'ok' } );

		await testConnection( 'cdek', 'api', { a: 1 } );

		const { url, method } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( url ).toBe( 'https://example.test/wp-json/woodev/v1/settings/cdek/connection/api/verify' );
		expect( url ).not.toMatch( /\/test$/ );
		expect( method ).toBe( 'POST' );
	} );

	it( 'explains a page returned instead of JSON', async () => {
		apiFetch.mockRejectedValue( { code: 'invalid_json', message: 'The response is not a valid JSON response.' } );

		await expect( testConnection( 'cdek', 'api', {} ) ).rejects.toMatchObject( {
			code: 'invalid_json',
			message: expect.stringContaining( 'Сайт вернул страницу вместо ответа API' ),
		} );
	} );

	it( 'leaves any other error untouched', async () => {
		const error = { code: 'rest_forbidden', message: 'Нет прав.' };
		apiFetch.mockRejectedValue( error );

		await expect( saveTab( 'cdek', {} ) ).rejects.toBe( error );
	} );
} );
