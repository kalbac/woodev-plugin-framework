/**
 * An account's connection on the settings page (#2025, #2058): a save checks an account nothing has checked yet, the card says
 * what «Проверить подключение» heard without a reload, and a page of a marketplace plugin shows each notice once.
 *
 * `@wordpress/data` and `@wordpress/notices` are not mocked: the page reads the real notices store.
 *
 * @see src/settings-page/app.js (accountsToCheck, checkAccounts)
 * @see src/settings-page/connection-block.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import App, { accountsToCheck } from '../../src/settings-page/app';
import { fetchSchema, saveTab, testConnection } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
	testConnection: jest.fn(),
} ) );

const BOOTSTRAP = { placement: 'test-marketplace', restRoot: 'https://shop.test/wp-json/woodev/v1/settings', nonce: 'n' };
const WORKS = { success: true, message: 'Подключено.' };
const LIMITED = { success: true, warning: true, message: 'Подключено. Токен только для чтения.' };

/**
 * One account's connection section.
 *
 * @param {number|string} id     account id, `new` for the block that adds one.
 * @param {?Object}       status stored connection status.
 * @param {Object}        card   card overrides.
 * @return {Object} section schema.
 */
function account( id, status = null, card = {} ) {
	const field = `account_${ id }_name`;

	return {
		id: `account_connection_${ id }`,
		label: 'Подключение',
		description: '',
		is_connection: true,
		supports_test: true,
		...( status ? { status } : {} ),
		fields: { [ field ]: { id: field, type: 'string', controlType: 'text', name: 'Название', value: '' } },
		card: { title: `Кабинет ${ id }`, identifier_label: '', identifier: '', enabled: true, enabled_field: `account_${ id }_enabled`, new: 'new' === id, ...card },
	};
}

const tabOf = ( ...sections ) => ( { id: 'marketplace', label: 'Подключение', capability: 'manage_woocommerce', sections } );

const schemas = ( ...tabs ) => tabs.forEach( ( tab ) => fetchSchema.mockResolvedValueOnce( { tabs: [ tab ] } ) );

/** Opens the first card, stages a name and presses «Сохранить». */
async function editAndSave() {
	fireEvent.click( await screen.findByRole( 'button', { name: 'Редактировать' } ) );
	fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'Новое название' } } );
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
	} );
}

beforeEach( () => {
	fetchSchema.mockReset();
	saveTab.mockReset().mockResolvedValue( {} );
	testConnection.mockReset().mockResolvedValue( WORKS );
} );

afterEach( () => {
	select( noticesStore ).getNotices().forEach( ( n ) => dispatch( noticesStore ).removeNotice( n.id ) );
} );

describe( 'which accounts a save leaves to be checked', () => {
	const before = tabOf( account( 1 ), account( 2 ), account( 'new' ) );

	it( 'the saved account that has no stored status, and no other unchecked one', () => {
		expect( accountsToCheck( before, before, 'account_connection_1' ).map( ( s ) => s.id ) ).toEqual( [ 'account_connection_1' ] );
	} );

	it( 'the account a save added', () => {
		const after = tabOf( account( 1 ), account( 2 ), account( 3 ), account( 'new' ) );

		expect( accountsToCheck( before, after, 'account_connection_new' ).map( ( s ) => s.id ) ).toEqual( [ 'account_connection_3' ] );
	} );

	it( 'none that already has a status, is switched off, or cannot be tested', () => {
		const untestable = { ...account( 3 ), supports_test: false };
		const after = tabOf( account( 1, WORKS ), account( 2, null, { enabled: false } ), untestable );

		[ 'account_connection_1', 'account_connection_2', 'account_connection_3' ].forEach( ( id ) =>
			expect( accountsToCheck( tabOf(), after, id ).filter( ( s ) => s.id === id ) ).toEqual( [] )
		);
	} );

	it( 'never a section that is not an account', () => {
		const plain = tabOf( { id: 'advanced', is_connection: true, supports_test: true, fields: {} } );

		expect( accountsToCheck( plain, plain, 'advanced' ) ).toEqual( [] );
	} );
} );

describe( '«Сохранить» checks the connection', () => {
	it( 'sends no values, says it is checking, then shows what the server stored', async () => {
		let answer;
		testConnection.mockReturnValue( new Promise( ( resolve ) => {
			answer = resolve;
		} ) );
		schemas( tabOf( account( 1 ) ), tabOf( account( 1 ) ), tabOf( account( 1, LIMITED ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		await editAndSave();

		expect( await screen.findByText( 'Проверяем подключение…' ) ).toBeInTheDocument();
		expect( testConnection ).toHaveBeenCalledWith( 'marketplace', 'account_connection_1', {} );
		expect( screen.getByRole( 'button', { name: 'Проверить подключение' } ) ).toBeDisabled();

		await act( async () => answer( LIMITED ) );

		await waitFor( () => expect( screen.getByText( LIMITED.message ) ).toHaveClass( 'woodev-connection__result', 'is-warning' ) );
		expect( screen.queryByText( 'Проверяем подключение…' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Подключение не проверялось' ) ).not.toBeInTheDocument();
	} );

	it( 'does not ask about an account whose stored status the save kept', async () => {
		schemas( tabOf( account( 1, WORKS ) ), tabOf( account( 1, WORKS ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		await editAndSave();

		await waitFor( () => expect( fetchSchema ).toHaveBeenCalledTimes( 2 ) );
		expect( testConnection ).not.toHaveBeenCalled();
	} );

	it( 'says why when the check itself could not run, and stops saying it is checking', async () => {
		testConnection.mockRejectedValue( { message: 'Ошибка при проверке подключения.' } );
		schemas( tabOf( account( 1 ) ), tabOf( account( 1 ) ), tabOf( account( 1 ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		await editAndSave();

		await waitFor( () => expect( screen.queryByText( 'Проверяем подключение…' ) ).not.toBeInTheDocument() );
		expect( select( noticesStore ).getNotices().map( ( n ) => n.content ) ).toContain( 'Ошибка при проверке подключения.' );
	} );
} );

describe( '«Проверить подключение»', () => {
	it( 'brings the account card up to date without a reload', async () => {
		schemas( tabOf( account( 1 ) ), tabOf( account( 1, WORKS ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Редактировать' } ) );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Проверить подключение' } ) );
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Свернуть' } ) );

		expect( await screen.findByText( WORKS.message ) ).toHaveClass( 'woodev-account-card__status', 'is-ok' );
		expect( screen.queryByText( 'Подключение не проверялось' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps saying why a check could not run, over the stored status', async () => {
		testConnection.mockRejectedValue( { message: 'Сайт не ответил.' } );
		schemas( tabOf( account( 1, WORKS ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Редактировать' } ) );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Проверить подключение' } ) );
		} );

		expect( screen.getByText( 'Сайт не ответил.' ) ).toHaveClass( 'is-error' );
		expect( fetchSchema ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps its answer about typed values over the stored status', async () => {
		testConnection.mockResolvedValue( { success: false, message: 'Ключ отклонён.' } );
		schemas( tabOf( account( 1, WORKS ) ), tabOf( account( 1, WORKS ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Редактировать' } ) );
		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'другое' } } );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Проверить подключение' } ) );
		} );
		await waitFor( () => expect( fetchSchema ).toHaveBeenCalledTimes( 2 ) );

		expect( screen.getByText( 'Ключ отклонён.' ) ).toHaveClass( 'is-error' );
		expect( screen.queryByText( WORKS.message ) ).not.toBeInTheDocument();
	} );
} );

describe( 'one notice for one save', () => {
	const refusal = { message: 'Проверьте правильность заполнения полей.', data: { errors: {} } };

	it( 'a marketplace page leaves the snackbar to wc-admin, which shows the notices store itself', async () => {
		saveTab.mockRejectedValue( refusal );
		schemas( tabOf( account( 1, WORKS ) ) );
		render( <App bootstrap={ BOOTSTRAP } /> );

		await editAndSave();

		expect( screen.queryByTestId( 'snackbar-list' ) ).not.toBeInTheDocument();
		expect( select( noticesStore ).getNotices().filter( ( n ) => 'snackbar' === n.type ) ).toHaveLength( 1 );
	} );

	it( 'the Woodev page draws its own snackbar list', async () => {
		fetchSchema.mockResolvedValue( { tabs: [ tabOf( account( 1, WORKS ) ) ] } );
		render( <App /> );

		await screen.findByRole( 'button', { name: 'Редактировать' } );

		expect( screen.getByTestId( 'snackbar-list' ) ).toBeInTheDocument();
	} );
} );
