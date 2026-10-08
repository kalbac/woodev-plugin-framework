/**
 * Tests the Setup Wizard's step contract on the client (#109, D2):
 *
 * - `skippable: false` removes the «Пропустить» control; the default keeps it;
 * - per-field errors from the server's validation callback are rendered on their fields
 *   (and an error on a key that is not a rendered field reaches the banner);
 * - a step action runs through REST with the step's current UNSAVED values and shows the
 *   structured answer; a destructive action asks for confirmation first and is sent with
 *   `confirmed: true` only after it.
 *
 * @see src/setup-wizard/app.js
 * @see src/setup-wizard/rest.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import App from '../../src/setup-wizard/app';
import { saveStep, runAction } from '../../src/setup-wizard/rest';

jest.mock( '../../src/setup-wizard/rest', () => ( {
	saveStep: jest.fn( () => Promise.resolve( { saved: true } ) ),
	complete: jest.fn( () => Promise.resolve( { complete: true } ) ),
	runAction: jest.fn( () => Promise.resolve( { status: 'success', message: '', data: {} } ) ),
} ) );

const textField = ( name, value = '' ) => ( { type: 'string', name, value } );

function bootstrap( connection = {} ) {
	return {
		pluginId: 'woodev_test',
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps: [
			{ id: 'welcome', label: 'Приветствие', type: 'content', content: '' },
			{
				id: 'connection',
				label: 'Подключение',
				type: 'settings',
				fields: { api_key: textField( 'Ключ API', 'DEFAULT' ), token: textField( 'Токен' ) },
				skippable: true,
				actions: [],
				...connection,
			},
			{ id: 'finish', label: 'Готово', type: 'finish' },
		],
	};
}

async function openConnection() {
	await act( async () => {
		fireEvent.click( document.querySelector( '.woodev-setup__primary' ) );
	} );
}

async function click( element ) {
	await act( async () => {
		fireEvent.click( element );
	} );
}

beforeEach( () => {
	window.location.hash = '';
	window.sessionStorage.clear();
	saveStep.mockReset();
	saveStep.mockImplementation( () => Promise.resolve( { saved: true } ) );
	runAction.mockReset();
	runAction.mockImplementation( () => Promise.resolve( { status: 'success', message: '', data: {} } ) );
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.woodevSetupWizard;
} );

describe( 'skippable', () => {
	test( 'a step is skippable by default: the skip control is there', async () => {
		window.woodevSetupWizard = bootstrap();
		render( createElement( App ) );
		await openConnection();

		expect( document.querySelector( '.woodev-setup__skip' ) ).toBeInTheDocument();
	} );

	test( 'a step declared non-skippable has no skip control, only the primary button', async () => {
		window.woodevSetupWizard = bootstrap( { skippable: false } );
		render( createElement( App ) );
		await openConnection();

		expect( document.querySelector( '.woodev-setup__skip' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Пропустить' } ) ).not.toBeInTheDocument();
		expect( document.querySelector( '.woodev-setup__primary' ) ).toBeInTheDocument();
	} );
} );

describe( 'server validation errors', () => {
	function rejectWith( errors, message = 'Проверьте правильность заполнения полей на этом шаге.' ) {
		saveStep.mockImplementation( () =>
			Promise.reject( { code: 'woodev_setup_invalid', message, data: { status: 400, errors } } )
		);
	}

	test( 'each message is rendered on its own field and the step does not advance', async () => {
		window.woodevSetupWizard = bootstrap();
		rejectWith( { api_key: 'Ключ не принят банком.', token: 'Токен просрочен.' } );
		render( createElement( App ) );
		await openConnection();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( screen.getByText( 'Ключ не принят банком.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Токен просрочен.' ) ).toBeInTheDocument();
		expect( document.querySelectorAll( '.woodev-field--error' ) ).toHaveLength( 2 );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
	} );

	test( 'an error on a key that is not a rendered field is not lost: it joins the banner', async () => {
		window.woodevSetupWizard = bootstrap();
		rejectWith( { _step: 'Сначала подключите аккаунт.' } );
		render( createElement( App ) );
		await openConnection();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( document.querySelector( '.woodev-setup__error' ) ).toHaveTextContent( 'Сначала подключите аккаунт.' );
	} );
} );

describe( 'step actions', () => {
	const checkKey = { id: 'check-key', label: 'Проверить ключ', destructive: false, confirm: '' };
	const wipe = { id: 'wipe', label: 'Очистить старые данные', destructive: true, confirm: 'Удалить старые данные?' };

	test( 'a non-destructive action runs at once with the current, unsaved values and shows its answer', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Ключ подходит.', data: {} } )
		);
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		expect( runAction ).toHaveBeenCalledTimes( 1 );
		expect( runAction ).toHaveBeenCalledWith( 'connection', 'check-key', { api_key: 'DEFAULT', token: '' }, false );
		expect( saveStep ).not.toHaveBeenCalled(); // an action never saves the step.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Ключ подходит.' );
	} );

	test( 'what the merchant typed is what the action receives', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		render( createElement( App ) );
		await openConnection();

		fireEvent.change( document.querySelector( '.woodev-setup__fields input' ), { target: { value: 'TYPED' } } );
		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		expect( runAction.mock.calls[ 0 ][ 2 ] ).toEqual( { api_key: 'TYPED', token: '' } );
	} );

	test( 'a structured error answer is shown as an alert, not as a success', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'error', message: 'Ключ не подходит.', data: {} } )
		);
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		const result = document.querySelector( '.woodev-setup__action-result' );
		expect( result ).toHaveClass( 'woodev-setup__action-result--error' );
		expect( result ).toHaveAttribute( 'role', 'alert' );
		expect( result ).toHaveTextContent( 'Ключ не подходит.' );
	} );

	test( 'a rejected request (unexpected failure) shows the server\'s generic message', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		runAction.mockImplementation( () =>
			Promise.reject( { code: 'woodev_setup_action_failed', message: 'Внутренняя ошибка сервера. Попробуйте ещё раз.' } )
		);
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		expect( document.querySelector( '.woodev-setup__action-result--error' ) ).toHaveTextContent( 'Внутренняя ошибка сервера' );
	} );

	test( 'a destructive action asks for confirmation first and does not call the server', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ wipe ] } );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Очистить старые данные' } ) );

		expect( runAction ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Удалить старые данные?' );
	} );

	test( 'confirming runs the destructive action, flagged as confirmed', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ wipe ] } );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Данные удалены.', data: {} } )
		);
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Очистить старые данные' } ) );
		await click( screen.getByRole( 'button', { name: 'Да, выполнить' } ) );

		expect( runAction ).toHaveBeenCalledTimes( 1 );
		expect( runAction.mock.calls[ 0 ][ 1 ] ).toBe( 'wipe' );
		expect( runAction.mock.calls[ 0 ][ 3 ] ).toBe( true );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Данные удалены.' );
		expect( document.querySelector( '.woodev-setup__confirm' ) ).not.toBeInTheDocument();
	} );

	test( 'cancelling the confirmation never reaches the server', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ wipe ] } );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Очистить старые данные' } ) );
		await click( screen.getByRole( 'button', { name: 'Отмена' } ) );

		expect( runAction ).not.toHaveBeenCalled();
		expect( document.querySelector( '.woodev-setup__confirm' ) ).not.toBeInTheDocument();
	} );

	test( 'a destructive action without its own text falls back to a generic confirmation', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ { ...wipe, confirm: '' } ] } );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Очистить старые данные' } ) );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Это действие нельзя отменить' );
	} );
} );
