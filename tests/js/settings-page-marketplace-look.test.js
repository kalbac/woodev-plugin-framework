/**
 * The marketplace look of the settings body (finding 33, 36, 38–40, 50 of the s237 UI audit): a body embedded in a marketplace
 * plugin's page (it receives `bootstrap.placement`) gets the `woodev-settings--marketplace` class, a «Сохранить» that reads as a
 * button at rest with «Нет изменений» beside it, a copyable address and the folded seldom-used actions. The Woodev page (no
 * bootstrap) keeps everything as it was.
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../../src/settings-page/app';
import CopyField from '../../src/settings-page/copy-field';
import { ToolsRow } from '../../src/settings-page/tools-block';
import { fetchSchema, runTool } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
	runTool: jest.fn(),
	setBootstrap: jest.fn(),
} ) );

const tab = () => ( {
	id: 'mp',
	label: 'Параметры',
	capability: 'manage_woocommerce',
	sections: [
		{
			id: 'params',
			label: 'Параметры',
			description: '',
			fields: { note: { id: 'note', type: 'string', controlType: 'text', name: 'Заметка', value: 'a' } },
		},
	],
} );

describe( 'the Save button', () => {
	beforeEach( () => {
		fetchSchema.mockResolvedValue( { tabs: [ tab() ] } );
	} );

	it( 'on a marketplace screen is a neutral disabled button with «Нет изменений» beside it, and turns primary on a change', async () => {
		const { container } = render( <App bootstrap={ { placement: 'ozon', restRoot: '/x', nonce: 'n' } } /> );

		const save = await screen.findByRole( 'button', { name: 'Сохранить' } );

		expect( container.querySelector( '.woodev-settings' ) ).toHaveClass( 'woodev-settings--marketplace' );
		expect( save ).toBeDisabled();
		expect( save ).toHaveClass( 'is-secondary', 'is-idle' );
		expect( save ).not.toHaveClass( 'is-primary' );
		expect( screen.getByText( 'Нет изменений' ) ).toBeInTheDocument();

		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'b' } } );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeEnabled() );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toHaveClass( 'is-primary' );
		expect( screen.queryByText( 'Нет изменений' ) ).not.toBeInTheDocument();
	} );

	it( 'on the Woodev page (delivery and payment plugins) stays the primary button it was, with no extra text', async () => {
		const { container } = render( <App /> );

		const save = await screen.findByRole( 'button', { name: 'Сохранить' } );

		expect( container.querySelector( '.woodev-settings' ) ).not.toHaveClass( 'woodev-settings--marketplace' );
		expect( save ).toBeDisabled();
		expect( save ).toHaveClass( 'is-primary' );
		expect( screen.queryByText( 'Нет изменений' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'the copyable address', () => {
	afterEach( () => {
		delete navigator.clipboard;
	} );

	it( 'is a read-only field with a «Копировать» button that copies the value', async () => {
		const writeText = jest.fn().mockResolvedValue( undefined );
		Object.defineProperty( navigator, 'clipboard', { value: { writeText }, configurable: true } );

		render( <CopyField field={ { label: 'Адрес', value: 'https://shop.example/hook/1/abc' } } /> );

		const field = screen.getByRole( 'textbox', { name: 'Адрес' } );
		expect( field ).toHaveAttribute( 'readonly' );
		expect( field ).toHaveValue( 'https://shop.example/hook/1/abc' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Копировать' } ) );

		expect( writeText ).toHaveBeenCalledWith( 'https://shop.example/hook/1/abc' );
		expect( await screen.findByRole( 'button', { name: 'Скопировано' } ) ).toBeInTheDocument();
	} );

	it( 'says to press Ctrl+C where the browser has no clipboard, instead of claiming success', () => {
		render( <CopyField field={ { label: 'Адрес', value: 'http://x' } } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Копировать' } ) );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Ctrl+C' );
		expect( screen.getByRole( 'button', { name: 'Копировать' } ) ).toBeInTheDocument();
	} );
} );

describe( 'a row of actions with advanced ones', () => {
	const tools = [
		{ id: 'register', name: 'Регистрация', desc: 'Передать адрес', button: 'Зарегистрировать адрес', disabled: false, status_text: '' },
		{ id: 'check', name: 'Проверка', desc: 'Проверить', button: 'Проверить связь', disabled: false, status_text: '' },
		{ id: 'types', name: 'Типы', desc: 'Привести типы в соответствие', button: 'Обновить типы событий', disabled: false, status_text: '', advanced: true },
	];

	it( 'keeps the everyday buttons in the row and folds the advanced one under «Дополнительно» with its explanation', () => {
		const { container } = render( <ToolsRow providerId="mp" tools={ tools } advancedLabel="Дополнительно" /> );

		const row = container.querySelector( '.woodev-tools-row__buttons' );
		expect( row.textContent ).toContain( 'Зарегистрировать адрес' );
		expect( row.textContent ).toContain( 'Проверить связь' );
		expect( row.textContent ).not.toContain( 'Обновить типы событий' );

		const more = container.querySelector( 'details.woodev-tools-row__more' );
		expect( more ).not.toHaveAttribute( 'open' );
		expect( more.querySelector( 'summary' ) ).toHaveTextContent( 'Дополнительно' );
		expect( more ).toHaveTextContent( 'Привести типы в соответствие' );
	} );

	it( 'runs a folded action like any other', async () => {
		runTool.mockResolvedValue( { success: true, message: 'Список типов актуален.' } );

		render( <ToolsRow providerId="mp" tools={ tools } advancedLabel="Дополнительно" /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить типы событий' } ) );

		expect( runTool ).toHaveBeenCalledWith( 'mp', 'types', {} );
		expect( await screen.findByText( 'Список типов актуален.' ) ).toBeInTheDocument();
	} );

	it( 'draws no collapsed block when no action is advanced', () => {
		const { container } = render( <ToolsRow providerId="mp" tools={ tools.slice( 0, 2 ) } /> );

		expect( container.querySelector( 'details' ) ).toBeNull();
	} );
} );
