import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
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

const view = ( sections, onToggleEnabled = jest.fn() ) => render( createElement( AccountsView, {
	tab: { id: 'ozon-seller', sections },
	renderSection: ( tab, id ) => createElement( 'div', { 'data-testid': `block-${ id }` } ),
	onToggleEnabled,
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

test( 'with no account the tab is one big «Добавить аккаунт» button that opens the form', () => {
	view( [ adder ] );

	expect( screen.queryByTestId( 'block-account_connection_new' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить аккаунт' } ) );

	expect( screen.getByTestId( 'block-account_connection_new' ) ).toBeInTheDocument();
} );

test( 'a card shows title, identifier and status, and opens its fields on «Редактировать»', () => {
	view( [ account( 1 ), adder ] );

	expect( screen.getByText( 'Shop 1' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Client-Id: 41801' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Подключено' ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-account_connection_1' ) ).not.toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Редактировать' } ) );

	expect( screen.getByTestId( 'block-account_connection_1' ) ).toBeInTheDocument();
} );

test( '«Удалить» of an enabled account is disabled and the hint is its tooltip, not text under every card', () => {
	view( [ account( 1, { delete_url: '/wp-json/x/deletion', delete_hint: 'Сначала выключите аккаунт.' } ), adder ] );

	const button = screen.getByRole( 'button', { name: 'Удалить' } );

	expect( button ).toBeDisabled();
	expect( button ).toHaveAttribute( 'title', 'Сначала выключите аккаунт.' );
	expect( button.parentElement ).toHaveAttribute( 'title', 'Сначала выключите аккаунт.' );
	expect( screen.queryByText( 'Сначала выключите аккаунт.' ) ).not.toBeInTheDocument();
} );

test( '«Удалить» is available for a switched-off account only when the screen offers deleting', () => {
	expect( canDelete( account( 1, { enabled: false, delete_url: '/wp-json/x/deletion' } ).card ) ).toBe( true );
	expect( canDelete( account( 1, { enabled: true, delete_url: '/wp-json/x/deletion' } ).card ) ).toBe( false );
	expect( canDelete( account( 1, { enabled: false } ).card ) ).toBe( false );
} );

test( 'pressing «Удалить» on a switched-off account opens the deletion dialog, and closing it reloads the cards', async () => {
	fetchDeletion.mockResolvedValue( { deleted: false, can_delete: true, refusal: '', preview: { counts: [], kept: '', warnings: [] }, deletion: null } );
	const onAccountsChanged = jest.fn();

	render( createElement( AccountsView, {
		tab: { id: 'ozon-seller', sections: [ account( 1, { enabled: false, delete_url: '/wp-json/x/deletion', delete_hint: '' } ), adder ] },
		renderSection: () => null,
		onToggleEnabled: jest.fn(),
		onAccountsChanged,
	} ) );

	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить' } ) );
	expect( await screen.findByText( 'Удаление аккаунта' ) ).toBeInTheDocument();

	fireEvent.click( await screen.findByRole( 'button', { name: 'Отмена' } ) );
	expect( onAccountsChanged ).toHaveBeenCalledTimes( 1 );
} );

test( '«Выключить» hands the section to the page, and an off account offers «Включить»', () => {
	const onToggle = jest.fn();
	const { unmount } = view( [ account( 1 ), adder ], onToggle );

	fireEvent.click( screen.getByRole( 'button', { name: 'Выключить' } ) );

	expect( onToggle ).toHaveBeenCalledTimes( 1 );
	expect( onToggle.mock.calls[ 0 ][ 1 ].card.enabled_field ).toBe( 'account_1_enabled' );

	unmount();
	view( [ account( 1, { enabled: false } ), adder ] );

	expect( screen.getByRole( 'button', { name: 'Включить' } ) ).toBeInTheDocument();
} );

test( '«Включить» is disabled while the account\'s data is being deleted', () => {
	const onToggle = jest.fn();
	view( [ account( 1, { enabled: false, delete_url: '/x/1', deletion: 'running' } ), adder ], onToggle );

	const button = screen.getByRole( 'button', { name: 'Включить' } );

	expect( button ).toBeDisabled();
	expect( button ).toHaveAttribute( 'title', 'Аккаунт удаляется — включить его нельзя.' );
	fireEvent.click( button );
	expect( onToggle ).not.toHaveBeenCalled();
} );
