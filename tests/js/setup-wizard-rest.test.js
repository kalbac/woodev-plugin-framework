/**
 * Tests the Setup Wizard REST client's request shapes: URL, nonce header and payload of the
 * save, action and complete calls.
 *
 * @see src/setup-wizard/rest.js
 */

import apiFetch from '@wordpress/api-fetch';
import { saveStep, runAction, complete } from '../../src/setup-wizard/rest';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );

beforeEach( () => {
	apiFetch.mockClear();
	window.woodevSetupWizard = {
		restRoot: 'https://example.test/wp-json/woodev/v1/acme/setup',
		nonce: 'NONCE',
	};
} );

afterEach( () => {
	delete window.woodevSetupWizard;
} );

test( 'saveStep posts the edited values to the step route with the REST nonce', () => {
	saveStep( 'connection', { api_key: 'K' } );

	expect( apiFetch ).toHaveBeenCalledWith( {
		url: 'https://example.test/wp-json/woodev/v1/acme/setup/steps/connection',
		method: 'POST',
		headers: { 'X-WP-Nonce': 'NONCE' },
		data: { values: { api_key: 'K' } },
	} );
} );

test( 'runAction posts to the action route; a non-destructive run carries no confirmation flag', () => {
	runAction( 'connection', 'check-key', { api_key: 'K' } );

	expect( apiFetch ).toHaveBeenCalledWith( {
		url: 'https://example.test/wp-json/woodev/v1/acme/setup/steps/connection/actions/check-key',
		method: 'POST',
		headers: { 'X-WP-Nonce': 'NONCE' },
		data: { values: { api_key: 'K' } },
	} );
} );

test( 'runAction sends confirmed:true only when the merchant confirmed', () => {
	runAction( 'connection', 'wipe', {}, true );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { values: {}, confirmed: true } );
} );

test( 'complete posts the requested state', () => {
	complete( 'skipped' );

	expect( apiFetch ).toHaveBeenCalledWith( {
		url: 'https://example.test/wp-json/woodev/v1/acme/setup/complete',
		method: 'POST',
		headers: { 'X-WP-Nonce': 'NONCE' },
		data: { state: 'skipped' },
	} );
} );
