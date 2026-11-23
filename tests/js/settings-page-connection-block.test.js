/**
 * Tests for the connection block's test-button gate (#1926): only REQUIRED fields block «Проверить подключение»,
 * so an empty optional «Описание» never disables it, while an empty required field does and the hint names it.
 *
 * @see src/settings-page/connection-block.js
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import ConnectionBlock from '../../src/settings-page/connection-block';

jest.mock( '../../src/settings-page/rest', () => ( {
	testConnection: jest.fn(),
} ) );

const field = ( name, extra = {} ) => ( { name, type: 'text', value: '', required: false, ...extra } );

const button = () => screen.getByRole( 'button', { name: 'Проверить подключение' } );

function renderBlock( fields, values = {} ) {
	return render(
		createElement( ConnectionBlock, {
			providerId: 'test-provider',
			section: { id: 'account', supports_test: true, fields, status: null },
			values,
			conditionValues: values,
			onFieldChange: jest.fn(),
			onFieldRevert: jest.fn(),
		} )
	);
}

describe( 'ConnectionBlock test-button gate', () => {
	test( 'an empty optional field does not disable the test button', () => {
		renderBlock( {
			client_id: field( 'Client-Id', { required: true, value: 'cid' } ),
			description: field( 'Описание' ),
		} );

		expect( button() ).toBeEnabled();
		expect( screen.queryByText( /Заполните/ ) ).not.toBeInTheDocument();
	} );

	test( 'an empty required field disables the button and the hint names it', () => {
		renderBlock( {
			client_id: field( 'Client-Id', { required: true } ),
			description: field( 'Описание' ),
		} );

		expect( button() ).toBeDisabled();
		expect( screen.getByText( 'Заполните поле «Client-Id».' ) ).toBeInTheDocument();
	} );

	test( 'several empty required fields are all listed in schema order', () => {
		renderBlock( {
			client_id: field( 'Client-Id', { required: true } ),
			secret: field( 'Секретный ключ', { required: true } ),
			description: field( 'Описание' ),
		} );

		expect( button() ).toBeDisabled();
		expect( screen.getByText( 'Заполните поля: Client-Id, Секретный ключ.' ) ).toBeInTheDocument();
	} );

	test( 'the hint drops a required field once it is filled', () => {
		renderBlock(
			{
				client_id: field( 'Client-Id', { required: true } ),
				secret: field( 'Секретный ключ', { required: true } ),
			},
			{ client_id: 'typed-by-merchant' }
		);

		expect( button() ).toBeDisabled();
		expect( screen.getByText( 'Заполните поле «Секретный ключ».' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Заполните поле «Client-Id».' ) ).not.toBeInTheDocument();
	} );

	test( 'a saved masked secret and a wp-config constant both count as filled', () => {
		renderBlock( {
			secret: field( 'Секретный ключ', { required: true, sensitive: true, is_set: true } ),
			token: field( 'Токен', { required: true, constant_managed: true } ),
		} );

		expect( button() ).toBeEnabled();
	} );

	// The marketplace account form (#1926b): the credentials of a saved account are required settings.
	describe( 'a saved marketplace account', () => {
		const account = () => ( {
			name: field( 'Название' ),
			client_id: field( 'Client-Id', { required: true, value: '12345' } ),
			api_key: field( 'API-ключ', { required: true, sensitive: true, is_set: true } ),
			description: field( 'Описание' ),
		} );

		test( 'with the stored credentials untouched (the secret is masked, not retyped) the button is enabled', () => {
			renderBlock( account() );

			expect( button() ).toBeEnabled();
			expect( screen.queryByText( /Заполните/ ) ).not.toBeInTheDocument();
		} );

		test( 'clearing Client-Id disables the button and the hint names the field', () => {
			renderBlock( account(), { client_id: '' } );

			expect( button() ).toBeDisabled();
			expect( screen.getByText( 'Заполните поле «Client-Id».' ) ).toBeInTheDocument();
		} );
	} );

	test( 'a handshake block with no fields stays enabled', () => {
		renderBlock( {} );

		expect( button() ).toBeEnabled();
	} );
} );
