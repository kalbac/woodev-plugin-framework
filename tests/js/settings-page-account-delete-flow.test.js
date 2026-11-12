import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import AccountDeleteFlow, { phaseOf, confirms } from '../../src/settings-page/account-delete-flow';
import { fetchDeletion, startDeletion } from '../../src/settings-page/deletion-rest';

jest.mock( '../../src/settings-page/deletion-rest' );

const card = {
	title: 'Shop 1',
	identifier_label: 'Client-Id',
	identifier: '41801',
	enabled: false,
	delete_url: '/wp-json/woodev/v1/x/deletion',
};

const preview = {
	counts: [ { key: 'links', label: 'Связи товаров с карточками', count: 12 }, { key: 'postings', label: 'Отправления', count: 3 } ],
	kept: 'Заказы WooCommerce остаются в магазине.',
	warnings: [ 'Адрес уведомлений может остаться в кабинете.' ],
};

const state = ( overrides = {} ) => ( { deleted: false, can_delete: true, refusal: '', preview, deletion: null, ...overrides } );

const flow = ( onClose = jest.fn() ) => render( createElement( AccountDeleteFlow, { card, onClose } ) );

beforeEach( () => {
	jest.resetAllMocks();
} );

test( 'a state belongs to one screen: preview, progress, done or the refusal', () => {
	expect( phaseOf( state() ) ).toBe( 'preview' );
	expect( phaseOf( state( { deletion: { state: 'failed', removed: {}, message: 'x', step: '' } } ) ) ).toBe( 'preview' );
	expect( phaseOf( state( { deletion: { state: 'running', removed: {}, message: '', step: '' } } ) ) ).toBe( 'progress' );
	expect( phaseOf( state( { deleted: true, can_delete: false, preview: null } ) ) ).toBe( 'done' );
	expect( phaseOf( state( { can_delete: false, refusal: 'Сначала выключите аккаунт.', preview: null } ) ) ).toBe( 'error' );
} );

test( 'only the exact identifier confirms', () => {
	expect( confirms( '41801', '41801' ) ).toBe( true );
	expect( confirms( ' 41801 ', '41801' ) ).toBe( true );
	expect( confirms( '41802', '41801' ) ).toBe( false );
	expect( confirms( '', '' ) ).toBe( false );
} );

test( 'the preview shows the counts, what stays and the warnings, and asks for the identifier', async () => {
	fetchDeletion.mockResolvedValue( state() );
	flow();

	expect( await screen.findByText( 'Связи товаров с карточками:' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Заказы WooCommerce остаются в магазине.' ) ).toBeInTheDocument();
	expect( screen.getAllByText( 'Адрес уведомлений может остаться в кабинете.' ).length ).toBeGreaterThan( 0 );
	expect( screen.getByRole( 'button', { name: 'Удалить аккаунт' } ) ).toBeDisabled();
} );

test( 'typing the identifier enables the button, and it sends the typed text', async () => {
	fetchDeletion.mockResolvedValue( state() );
	startDeletion.mockResolvedValue( state( { deletion: { state: 'running', removed: {}, message: '', step: '' } } ) );
	flow();

	const input = await screen.findByRole( 'textbox' );
	fireEvent.change( input, { target: { value: '41802' } } );
	expect( screen.getByRole( 'button', { name: 'Удалить аккаунт' } ) ).toBeDisabled();

	fireEvent.change( input, { target: { value: '41801' } } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить аккаунт' } ) );

	await waitFor( () => expect( startDeletion ).toHaveBeenCalledWith( card.delete_url, '41801' ) );
	expect( await screen.findByText( /Удаляем данные аккаунта/ ) ).toBeInTheDocument();
} );

test( 'a refusal from the server stays on the preview with its sentence', async () => {
	fetchDeletion.mockResolvedValue( state() );
	startDeletion.mockRejectedValue( { message: 'Идентификатор введён неверно.' } );
	flow();

	fireEvent.change( await screen.findByRole( 'textbox' ), { target: { value: '41801' } } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить аккаунт' } ) );

	expect( ( await screen.findAllByText( 'Идентификатор введён неверно.' ) ).length ).toBeGreaterThan( 0 );
	expect( screen.getByRole( 'button', { name: 'Удалить аккаунт' } ) ).toBeInTheDocument();
} );

test( 'a deletion already running opens on the progress, and the end shows what stays at the marketplace', async () => {
	fetchDeletion
		.mockResolvedValueOnce( state( { deletion: { state: 'running', removed: { links: 5 }, message: '', step: 'links' } } ) )
		.mockResolvedValue( state( { deleted: true, can_delete: false, preview: null, deletion: { state: 'done', removed: {}, message: '', step: '', notes: [ 'Уберите адрес в кабинете.' ] } } ) );
	flow();

	expect( await screen.findByText( /Удаляем данные аккаунта/ ) ).toBeInTheDocument();
	expect( await screen.findByText( 'Аккаунт удалён.', {}, { timeout: 4000 } ) ).toBeInTheDocument();
	expect( screen.getAllByText( 'Уберите адрес в кабинете.' ).length ).toBeGreaterThan( 0 );
}, 10000 );

test( 'an account that cannot be deleted shows the reason and only closes', async () => {
	fetchDeletion.mockResolvedValue( state( { can_delete: false, refusal: 'Сначала выключите аккаунт.', preview: null } ) );
	const onClose = jest.fn();
	flow( onClose );

	expect( ( await screen.findAllByText( 'Сначала выключите аккаунт.' ) ).length ).toBeGreaterThan( 0 );
	fireEvent.click( screen.getByRole( 'button', { name: 'Закрыть' } ) );
	expect( onClose ).toHaveBeenCalledTimes( 1 );
} );
