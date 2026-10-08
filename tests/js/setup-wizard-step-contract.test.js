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

	test( 'a non-destructive action runs at once with the merchant\'s unsaved edits and shows its answer', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Ключ подходит.', data: {} } )
		);
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		expect( runAction ).toHaveBeenCalledTimes( 1 );
		// Only the merchant's edits travel; the server lays them over the stored values.
		expect( runAction ).toHaveBeenCalledWith( 'connection', 'check-key', {}, false );
		expect( saveStep ).not.toHaveBeenCalled(); // an action never saves the step.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Ключ подходит.' );
	} );

	test( 'what the merchant typed is what the action receives', async () => {
		window.woodevSetupWizard = bootstrap( { actions: [ checkKey ] } );
		render( createElement( App ) );
		await openConnection();

		fireEvent.change( document.querySelector( '.woodev-setup__fields input' ), { target: { value: 'TYPED' } } );
		await click( screen.getByRole( 'button', { name: 'Проверить ключ' } ) );

		expect( runAction.mock.calls[ 0 ][ 2 ] ).toEqual( { api_key: 'TYPED' } );
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

describe( 'Continue and the validation contract', () => {
	const contentStep = ( extra = {} ) => ( {
		id: 'migrate',
		label: 'Перенос',
		type: 'content',
		content: '<p>Перенесите данные.</p>',
		skippable: false,
		actions: [],
		...extra,
	} );

	function withContentStep( extra ) {
		const data = bootstrap();
		data.steps.splice( 1, 0, contentStep( extra ) );
		return data;
	}

	test( 'only the fields the merchant edited are sent for saving', async () => {
		window.woodevSetupWizard = bootstrap();
		render( createElement( App ) );
		await openConnection();

		fireEvent.change( document.querySelector( '.woodev-setup__fields input' ), { target: { value: 'NEW' } } );
		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( saveStep ).toHaveBeenCalledWith( 'connection', { api_key: 'NEW' } );
	} );

	test( 'an untouched settings step still asks the server (it validates the stored values)', async () => {
		window.woodevSetupWizard = bootstrap();
		render( createElement( App ) );
		await openConnection();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( saveStep ).toHaveBeenCalledWith( 'connection', {} );
	} );

	test( 'a content step that validates is refused on Continue and does not advance', async () => {
		window.woodevSetupWizard = withContentStep( { validates: true } );
		saveStep.mockImplementation( () =>
			Promise.reject( {
				code: 'woodev_setup_invalid',
				message: 'Проверьте правильность заполнения полей на этом шаге.',
				data: { status: 400, errors: { _step: 'Сначала выполните перенос.' } },
			} )
		);
		render( createElement( App ) );
		await openConnection(); // welcome → the content step «Перенос»
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Перенос' );
		saveStep.mockClear();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( saveStep ).toHaveBeenCalledWith( 'migrate', {} );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Перенос' );
		expect( document.querySelector( '.woodev-setup__error' ) ).toHaveTextContent( 'Сначала выполните перенос.' );
	} );

	test( 'a content step that validates advances once the server accepts it', async () => {
		window.woodevSetupWizard = withContentStep( { validates: true } );
		render( createElement( App ) );
		await openConnection();
		saveStep.mockClear();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( saveStep ).toHaveBeenCalledWith( 'migrate', {} );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
	} );

	test( 'a plain content step advances without a request', async () => {
		window.woodevSetupWizard = withContentStep( { validates: false } );
		render( createElement( App ) );
		await openConnection();
		saveStep.mockClear();

		await click( document.querySelector( '.woodev-setup__primary' ) );

		expect( saveStep ).not.toHaveBeenCalled();
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Подключение' );
	} );
} );

describe( 'an action answer belongs to the step it was started on', () => {
	const slow = { id: 'slow', label: 'Медленная проверка', destructive: false, confirm: '' };
	const other = { id: 'other', label: 'Другая проверка', destructive: false, confirm: '' };

	function deferred() {
		const d = {};
		d.promise = new Promise( ( resolve, reject ) => {
			d.resolve = resolve;
			d.reject = reject;
		} );
		return d;
	}

	function twoStepsWithActions() {
		const data = bootstrap( { actions: [ slow ] } );
		data.steps[ 0 ] = { ...data.steps[ 0 ], actions: [ other ], skippable: true };
		return data;
	}

	async function clickBack() {
		await click( document.querySelector( '.woodev-setup__back' ) );
	}

	test( 'a result that arrives after Back is not shown on the step we went back to', async () => {
		window.woodevSetupWizard = twoStepsWithActions();
		const pending = deferred();
		runAction.mockImplementation( () => pending.promise );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Медленная проверка' } ) );
		await clickBack(); // «Приветствие» has its own actions

		await act( async () => {
			pending.resolve( { status: 'success', message: 'Ключ подходит.', data: {} } );
		} );

		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Приветствие' );
		expect( document.querySelector( '.woodev-setup__action-result' ) ).not.toBeInTheDocument();
	} );

	test( 'a failure that arrives after Back is dropped as well', async () => {
		window.woodevSetupWizard = twoStepsWithActions();
		const pending = deferred();
		runAction.mockImplementation( () => pending.promise );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Медленная проверка' } ) );
		await clickBack();
		await act( async () => {
			pending.reject( { message: 'Сервер недоступен.' } );
		} );

		expect( document.querySelector( '.woodev-setup__action-result' ) ).not.toBeInTheDocument();
	} );

	test( 'an old request finishing does not re-enable the controls of a newer one', async () => {
		window.woodevSetupWizard = twoStepsWithActions();
		const first = deferred();
		const second = deferred();
		runAction.mockImplementationOnce( () => first.promise ).mockImplementationOnce( () => second.promise );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Медленная проверка' } ) ); // request 1, on «Подключение»
		await clickBack();
		await click( screen.getByRole( 'button', { name: 'Другая проверка' } ) ); // request 2, on «Приветствие»

		await act( async () => {
			first.resolve( { status: 'success', message: 'Старый ответ.', data: {} } );
		} );

		// Request 2 is still running: its button stays busy and disabled, and nothing from request 1 shows.
		expect( screen.getByRole( 'button', { name: 'Другая проверка' } ) ).toBeDisabled();
		expect( document.querySelector( '.woodev-setup__action-result' ) ).not.toBeInTheDocument();

		await act( async () => {
			second.resolve( { status: 'success', message: 'Новый ответ.', data: {} } );
		} );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Новый ответ.' );
		expect( screen.getByRole( 'button', { name: 'Другая проверка' } ) ).not.toBeDisabled();
	} );

	test( 'control: an answer for the step still on screen is shown', async () => {
		window.woodevSetupWizard = twoStepsWithActions();
		const pending = deferred();
		runAction.mockImplementation( () => pending.promise );
		render( createElement( App ) );
		await openConnection();

		await click( screen.getByRole( 'button', { name: 'Медленная проверка' } ) );
		await act( async () => {
			pending.resolve( { status: 'success', message: 'Ключ подходит.', data: {} } );
		} );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Ключ подходит.' );
	} );
} );
