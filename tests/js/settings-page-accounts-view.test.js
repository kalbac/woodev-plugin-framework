import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import AccountsView, { isAccountsTab, splitAccountSections, cardStatus, canDelete } from '../../src/settings-page/accounts-view';
import { fetchDeletion } from '../../src/settings-page/deletion-rest';

jest.mock( '../../src/settings-page/deletion-rest' );

const account = ( id, overrides = {} ) => ( {
	id: `account_connection_${ id }`,
	label: `Account ${ id }`,
	is_connection: true,
	status: { success: true, message: 'Подключено' },
	card: {
		new: false,
		title: `Shop ${ id }`,
		identifier_label: 'Client-Id',
		identifier: `4180${ id }`,
		enabled: true,
		enabled_field: `account_${ id }_enabled`,
		delete_hint: 'Удаление аккаунта появится позже.',
		...overrides,
	},
} );

const adder = { id: 'account_connection_new', label: 'Подключение', is_connection: true, card: { new: true } };

const view = ( sections, props = {}, tab = {} ) => render( createElement( AccountsView, {
	tab: { id: 'ozon-seller', sections, ...tab },
	renderSection: ( t, id ) => createElement( 'div', { 'data-testid': `block-${ id }` } ),
	...props,
} ) );

test( 'a tab is the accounts tab when a section carries a card', () => {
	expect( isAccountsTab( { sections: [ account( 1 ) ] } ) ).toBe( true );
	expect( isAccountsTab( { sections: [ { id: 'advanced', fields: {} } ] } ) ).toBe( false );
} );

test( 'saved accounts and the add block are told apart', () => {
	const { accounts, adder: add } = splitAccountSections( { sections: [ account( 1 ), account( 2 ), adder ] } );

	expect( accounts.map( ( s ) => s.id ) ).toEqual( [ 'account_connection_1', 'account_connection_2' ] );
	expect( add.id ).toBe( 'account_connection_new' );
} );

test( 'the status line says «Выключен» for a switched-off account and shows the stored message otherwise', () => {
	expect( cardStatus( account( 1, { enabled: false } ) ) ).toEqual( { ok: false, text: 'Выключен' } );
	expect( cardStatus( account( 1 ) ) ).toEqual( { ok: true, text: 'Подключено' } );
	expect( cardStatus( { ...account( 1 ), status: null } ).ok ).toBe( false );
} );

test( 'with no account the wrapper says to add the first one, and «Добавить аккаунт» opens the form', () => {
	view( [ adder ] );

	expect( screen.getByText( /Пока нет ни одного аккаунта/ ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-account_connection_new' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) );

	expect( screen.getByTestId( 'block-account_connection_new' ) ).toBeInTheDocument();
	expect( screen.queryByText( /Пока нет ни одного аккаунта/ ) ).not.toBeInTheDocument();
} );

test( 'the wrapper is one white card with the tab description, which may carry a link, and the add button in its header', () => {
	const { container } = view( [ account( 1 ), adder ], {}, { description: 'Ключи выдаёт <a href="https://seller.ozon.ru/app/settings/api-keys">личный кабинет</a>.' } );

	const header = container.querySelector( '.woodev-accounts__header' );
	expect( container.querySelectorAll( '.woodev-accounts' ) ).toHaveLength( 1 );
	expect( container.querySelector( '.woodev-accounts' ) ).toHaveClass( 'woodev-settings__card' );
	expect( header ).toContainElement( screen.getByRole( 'link', { name: 'личный кабинет' } ) );
	expect( screen.getByRole( 'link', { name: 'личный кабинет' } ) ).toHaveAttribute( 'href', 'https://seller.ozon.ru/app/settings/api-keys' );
	expect( header ).toContainElement( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) );
	expect( screen.queryByText( /Пока нет ни одного аккаунта/ ) ).not.toBeInTheDocument();
} );

test( 'a card shows title and status, never the Client-Id, and opens its fields on «Редактировать»', () => {
	view( [ account( 1 ), adder ] );

	expect( screen.getByText( 'Shop 1' ) ).toBeInTheDocument();
	expect( screen.queryByText( /Client-Id/ ) ).not.toBeInTheDocument();
	expect( screen.queryByText( /41801/ ) ).not.toBeInTheDocument();
	expect( screen.getByText( 'Подключено' ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-account_connection_1' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );

	expect( screen.getByTestId( 'block-account_connection_1' ) ).toBeInTheDocument();
} );

test( '«Удалить» of an enabled account is available: the dialog switches it off first, so there is no hint', () => {
	view( [ account( 1, { delete_url: '/wp-json/x/deletion', delete_hint: '' } ), adder ] );

	expect( screen.getByRole( 'button', { name: 'Удалить' } ) ).toBeEnabled();
	expect( document.querySelector( '.woodev-account-card__hint' ) ).toBeNull();
} );

test( 'a screen that does not offer deleting keeps «Удалить» disabled and says so as visible text', () => {
	view( [ account( 1, { delete_hint: 'Удаление аккаунта недоступно.' } ), adder ] );

	expect( screen.getByRole( 'button', { name: 'Удалить' } ) ).toBeDisabled();
	expect( screen.getByText( 'Удаление аккаунта недоступно.' ) ).toBeVisible();
} );

test( '«Удалить» needs only the deletion route, whether the account is on or off', () => {
	expect( canDelete( account( 1, { enabled: false, delete_url: '/wp-json/x/deletion' } ).card ) ).toBe( true );
	expect( canDelete( account( 1, { enabled: true, delete_url: '/wp-json/x/deletion' } ).card ) ).toBe( true );
	expect( canDelete( account( 1, { enabled: false } ).card ) ).toBe( false );
} );

test( 'the line under the name is the merchant\'s own description, and nothing without one', () => {
	view( [ account( 1 ), adder ] );
	expect( document.querySelector( '.woodev-account-card__identifier' ) ).toBeNull();
	cleanup();

	view( [ account( 1, { description: 'Одежда и обувь' } ), adder ] );
	expect( screen.getByText( 'Одежда и обувь' ) ).toBeInTheDocument();
	expect( screen.queryByText( /Client-Id/ ) ).not.toBeInTheDocument();
} );

test( 'a card has an on/off switch that saves at once through the page', async () => {
	const onToggleEnabled = jest.fn().mockResolvedValue( undefined );
	view( [ account( 1 ), account( 2, { enabled: false } ), adder ], { onToggleEnabled } );

	const [ first, second ] = screen.getAllByRole( 'checkbox', { name: 'Аккаунт включён' } );
	expect( first ).toBeChecked();
	expect( second ).not.toBeChecked();

	fireEvent.click( first );

	expect( onToggleEnabled ).toHaveBeenCalledWith( expect.objectContaining( { id: 'account_connection_1' } ), false );
	await waitFor( () => expect( first ).toBeEnabled() );

	fireEvent.click( second );
	expect( onToggleEnabled ).toHaveBeenLastCalledWith( expect.objectContaining( { id: 'account_connection_2' } ), true );
	await waitFor( () => expect( second ).toBeEnabled() );
} );

test( 'the switch waits for the save and survives a failed one', async () => {
	let reject;
	const onToggleEnabled = jest.fn( () => new Promise( ( resolve, fail ) => { reject = fail; } ) );
	view( [ account( 1 ), adder ], { onToggleEnabled } );

	const toggle = screen.getByRole( 'checkbox', { name: 'Аккаунт включён' } );
	fireEvent.click( toggle );
	expect( toggle ).toBeDisabled();

	reject( new Error( 'boom' ) );
	await waitFor( () => expect( toggle ).toBeEnabled() );
} );

test( 'without a save handler the switch is read-only', () => {
	view( [ account( 1 ), adder ] );

	expect( screen.getByRole( 'checkbox', { name: 'Аккаунт включён' } ) ).toBeDisabled();
} );

test( 'pressing «Удалить» on a switched-off account opens the deletion dialog, and closing it reloads the cards', async () => {
	fetchDeletion.mockResolvedValue( { deleted: false, can_delete: true, preview: { counts: [], kept: '', enabled_note: '', warnings: [] }, deletion: null } );
	const onAccountsChanged = jest.fn();

	render( createElement( AccountsView, {
		tab: { id: 'ozon-seller', sections: [ account( 1, { enabled: false, delete_url: '/wp-json/x/deletion', delete_hint: '' } ), adder ] },
		renderSection: () => null,
		onAccountsChanged,
	} ) );

	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить' } ) );
	expect( await screen.findByText( 'Удаление аккаунта' ) ).toBeInTheDocument();

	fireEvent.click( await screen.findByRole( 'button', { name: 'Отмена' } ) );
	expect( onAccountsChanged ).toHaveBeenCalledTimes( 1 );
} );

test( 'the header has no on/off BUTTON: the switch is a toggle', () => {
	view( [ account( 1 ), account( 2, { enabled: false } ), adder ] );

	expect( screen.queryByRole( 'button', { name: 'Выключить' } ) ).not.toBeInTheDocument();
	expect( screen.queryByRole( 'button', { name: 'Включить' } ) ).not.toBeInTheDocument();
	expect( screen.getByText( 'Выключен' ) ).toBeInTheDocument();
} );

test( 'opening the form puts the connection message away from the header: it is said once, under «Проверить подключение»', () => {
	view( [ account( 1 ), adder ] );

	expect( screen.getByText( 'Подключено' ) ).toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );

	expect( screen.queryByText( 'Подключено' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Свернуть' } ) );

	expect( screen.getByText( 'Подключено' ) ).toBeInTheDocument();
} );

test( 'adding an account collapses the open edit form, and opening an edit puts the new-account form away', () => {
	view( [ account( 1 ), adder ] );

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );
	expect( screen.getByTestId( 'block-account_connection_1' ) ).toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) );
	expect( screen.queryByTestId( 'block-account_connection_1' ) ).not.toBeInTheDocument();
	expect( screen.getByTestId( 'block-account_connection_new' ) ).toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );
	expect( screen.getByTestId( 'block-account_connection_1' ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-account_connection_new' ) ).not.toBeInTheDocument();
} );

test( 'Esc closes the open form', () => {
	view( [ account( 1 ), adder ] );

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );
	fireEvent.keyDown( screen.getByTestId( 'block-account_connection_1' ), { key: 'Escape' } );
	expect( screen.queryByTestId( 'block-account_connection_1' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) );
	fireEvent.keyDown( screen.getByTestId( 'block-account_connection_new' ), { key: 'Escape' } );
	expect( screen.queryByTestId( 'block-account_connection_new' ) ).not.toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) ).toBeInTheDocument();
} );
