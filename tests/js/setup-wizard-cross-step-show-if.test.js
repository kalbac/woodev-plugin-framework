/**
 * Tests that a field's `show_if` may depend on a value that lives on ANOTHER wizard step (#109,
 * rig finding): the condition is evaluated over the SAVED values of every step overlaid with the
 * current step's edits — the same rule the server applies when it filters submitted values.
 *
 * Before the fix the client evaluated it over the current step's own fields only, so a key shown
 * only for the mode picked on an earlier step never rendered.
 *
 * @see src/setup-wizard/app.js
 * @see src/setup-wizard/step-view.js
 */

import '@testing-library/jest-dom';
import { render, fireEvent, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import App from '../../src/setup-wizard/app';
import { saveStep } from '../../src/setup-wizard/rest';

jest.mock( '../../src/setup-wizard/rest', () => ( {
	saveStep: jest.fn( () => Promise.resolve( { saved: true } ) ),
	complete: jest.fn( () => Promise.resolve( { complete: true } ) ),
	runAction: jest.fn( () => Promise.resolve( { status: 'success', message: '', data: {} } ) ),
} ) );

const finish = { id: 'finish', label: 'Готово', type: 'finish' };

function bootstrap( savedMode ) {
	return {
		pluginId: 'woodev_test',
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps: [
			{
				id: 'start',
				visible: true,
				label: 'Режим',
				type: 'settings',
				fields: {
					mode: {
						type: 'string',
						name: 'Режим',
						value: savedMode,
						options: { test: 'Тест', live: 'Боевой' },
						control: 'select',
					},
				},
				skippable: true,
			},
			{
				id: 'connection',
				visible: true,
				label: 'Подключение',
				type: 'settings',
				fields: {
					api_key: {
						type: 'string',
						name: 'API-ключ',
						value: '',
						show_if: { setting: 'mode', value: 'live' },
					},
				},
				skippable: true,
			},
			finish,
		],
	};
}

async function goToConnection() {
	await act( async () => {
		fireEvent.click( document.querySelector( '.woodev-setup__primary' ) );
	} );
}

const keyInput = () => document.querySelector( '.woodev-setup__fields input[type="text"]' );

beforeEach( () => {
	window.location.hash = '';
	window.sessionStorage.clear();
	saveStep.mockReset();
	saveStep.mockImplementation( () => Promise.resolve( { saved: true } ) );
	jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	jest.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.woodevSetupWizard;
} );

describe( 'show_if across steps', () => {
	test( 'a field whose controlling value is saved on an earlier step renders when the condition holds', async () => {
		window.woodevSetupWizard = bootstrap( 'live' );
		render( createElement( App ) );

		await goToConnection();

		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
		expect( keyInput() ).toBeInTheDocument();
	} );

	test( 'it stays hidden when the saved value on the other step does not match', async () => {
		window.woodevSetupWizard = bootstrap( 'test' );
		render( createElement( App ) );

		await goToConnection();

		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
		expect( keyInput() ).not.toBeInTheDocument();
	} );

	test( 'a visible required field on the later step blocks Continue while empty', async () => {
		const live = bootstrap( 'live' );
		live.steps[ 1 ].fields.api_key.required = true;
		window.woodevSetupWizard = live;
		render( createElement( App ) );
		await goToConnection();

		// Visible and required: an empty key is refused on the client, nothing is sent.
		await goToConnection();
		expect( saveStep ).not.toHaveBeenCalledWith( 'connection', expect.anything() );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
	} );

	test( 'a required field hidden by the saved value on another step does not block Continue', async () => {
		const test = bootstrap( 'test' );
		test.steps[ 1 ].fields.api_key.required = true;
		window.woodevSetupWizard = test;
		render( createElement( App ) );
		await goToConnection();

		await goToConnection();

		expect( saveStep ).toHaveBeenCalledWith( 'connection', {} );
	} );

	test( 'a controller owned by a server-hidden step is absent: the dependent field stays hidden and Continue does not validate it', async () => {
		const data = bootstrap( 'live' );
		// The server sends a hidden step as a bare entry — no fields, so no saved value either.
		data.steps[ 0 ] = { id: 'start', visible: false };
		data.steps[ 1 ].fields.api_key.required = true;
		window.woodevSetupWizard = data;
		render( createElement( App ) );

		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
		expect( keyInput() ).not.toBeInTheDocument();

		await goToConnection();

		// Nothing to fix on screen, so nothing is refused on the client either.
		expect( saveStep ).toHaveBeenCalledWith( 'connection', {} );
	} );

	test( 'typing into the field on the later step is what Continue sends', async () => {
		window.woodevSetupWizard = bootstrap( 'live' );
		render( createElement( App ) );
		await goToConnection();

		fireEvent.change( keyInput(), { target: { value: 'live_abc' } } );
		await goToConnection();

		expect( saveStep ).toHaveBeenCalledWith( 'connection', { api_key: 'live_abc' } );
	} );

	test( 'the current step\'s own edit still wins over the saved value of a same-named field elsewhere', async () => {
		const data = bootstrap( 'test' );
		// The condition reads `mode`; the current step also declares `mode`, edited to `live`.
		data.steps[ 1 ].fields = { mode: { type: 'string', name: 'Режим здесь', value: 'test' }, ...data.steps[ 1 ].fields };
		window.woodevSetupWizard = data;
		render( createElement( App ) );
		await goToConnection();
		expect( document.querySelectorAll( '.woodev-setup__fields input[type="text"]' ) ).toHaveLength( 1 );

		fireEvent.change( keyInput(), { target: { value: 'live' } } );

		expect( document.querySelectorAll( '.woodev-setup__fields input[type="text"]' ) ).toHaveLength( 2 );
	} );
} );
