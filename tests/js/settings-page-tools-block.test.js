/**
 * Tests for ToolsBlock (#505): the button goes busy/disabled the instant it is
 * clicked (a sweep over a live provider takes seconds — an un-indicated wait
 * reads as a dead button), the result renders BELOW the action row and never
 * above it, and success/failure carry visually distinct styling — not bare
 * text. These are exactly the four defects the s90 session log recorded as
 * having survived 1380 green tests and five review passes.
 *
 * @see src/settings-page/tools-block.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import ToolsBlock from '../../src/settings-page/tools-block';
import { runTool } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	runTool: jest.fn(),
} ) );

const section = {
	id: 'tools',
	label: 'Инструменты',
	description: 'Действия над данными раздела «Доставка».',
	tools: [
		{
			id: 'popular-settlements-sweep',
			name: 'Проверить актуальность популярных городов',
			desc: 'Перепроверяет каждую запись списка популярных городов.',
			button: 'Проверить',
			disabled: false,
			status_text: '',
			selector: {
				description: 'Провайдер',
				name: 'provider_id',
				placeholder: '',
				options: [ { value: 'dadata', label: 'DaData' } ],
				default: 'dadata',
			},
		},
	],
};

beforeEach( () => {
	runTool.mockReset();
} );

test( 'renders the tool name, description and button label', () => {
	render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );

	expect( screen.getByText( 'Проверить актуальность популярных городов' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Перепроверяет каждую запись списка популярных городов.' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Проверить' } ) ).toBeInTheDocument();
} );

test( 'the button goes busy and disabled the instant it is clicked, before the request resolves', async () => {
	let resolvePromise;
	runTool.mockReturnValue(
		new Promise( ( resolve ) => {
			resolvePromise = resolve;
		} )
	);

	render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );

	const button = screen.getByRole( 'button', { name: 'Проверить' } );
	fireEvent.click( button );

	// Synchronous, BEFORE the promise resolves — un-indicated waiting reads as
	// a dead button.
	expect( button ).toBeDisabled();
	expect( button.className ).toContain( 'is-busy' );

	resolvePromise( { success: true, message: 'Готово' } );
	await waitFor( () => expect( button ).not.toBeDisabled() );
} );

test( 'the result renders below the action row and carries the success style', async () => {
	runTool.mockResolvedValue( { success: true, message: 'Проверено: 3.' } );

	const { container } = render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );

	await waitFor( () => screen.getByText( 'Проверено: 3.' ) );

	const action = container.querySelector( '.woodev-tool__action' );
	const result = container.querySelector( '.woodev-tool__result' );

	expect( result ).not.toBeNull();
	// DOCUMENT_POSITION_FOLLOWING (4): `result` must come AFTER `action` in the
	// DOM, never before it.
	// eslint-disable-next-line no-bitwise
	expect( action.compareDocumentPosition( result ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
	expect( result.className ).toContain( 'is-ok' );
} );

test( 'a failed run renders the distinct error style, not bare text', async () => {
	runTool.mockResolvedValue( { success: false, message: 'Ошибка проверки.' } );

	const { container } = render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );

	await waitFor( () => screen.getByText( 'Ошибка проверки.' ) );

	const result = container.querySelector( '.woodev-tool__result' );
	expect( result.className ).toContain( 'is-error' );
	expect( result.className ).not.toContain( 'is-ok' );
} );

test( 'runTool is called with the selector value scoped under its own selector name', async () => {
	runTool.mockResolvedValue( { success: true, message: 'ok' } );

	render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );

	expect( runTool ).toHaveBeenCalledWith( 'shipping', 'popular-settlements-sweep', { provider_id: 'dadata' } );

	await waitFor( () => screen.getByText( 'ok' ) );
} );

test( 'a rejected request renders a failure result instead of leaving the button stuck busy', async () => {
	runTool.mockRejectedValue( new Error( 'network down' ) );

	render( createElement( ToolsBlock, { providerId: 'shipping', section } ) );
	const button = screen.getByRole( 'button', { name: 'Проверить' } );
	fireEvent.click( button );

	await waitFor( () => expect( button ).not.toBeDisabled() );
	expect( screen.getByText( 'network down' ) ).toBeInTheDocument();
} );

test( 'a tool without a selector renders no selector and calls runTool with empty args', async () => {
	runTool.mockResolvedValue( { success: true, message: 'ok' } );

	const noSelectorSection = {
		...section,
		tools: [
			{
				id: 'popular-settlements-clear',
				name: 'Очистить список популярных городов',
				desc: '',
				button: 'Очистить',
				disabled: false,
				status_text: '',
			},
		],
	};

	const { container } = render( createElement( ToolsBlock, { providerId: 'shipping', section: noSelectorSection } ) );

	expect( container.querySelector( '.woodev-tool__selector' ) ).toBeNull();

	fireEvent.click( screen.getByRole( 'button', { name: 'Очистить' } ) );

	expect( runTool ).toHaveBeenCalledWith( 'shipping', 'popular-settlements-clear', {} );

	await waitFor( () => screen.getByText( 'ok' ) );
} );

test( 'a disabled tool renders its status text and a disabled button', () => {
	const disabledSection = {
		...section,
		tools: [
			{
				...section.tools[ 0 ],
				disabled: true,
				status_text: 'Недоступно: ни один провайдер не поддерживает эту операцию.',
			},
		],
	};

	render( createElement( ToolsBlock, { providerId: 'shipping', section: disabledSection } ) );

	expect( screen.getByRole( 'button', { name: 'Проверить' } ) ).toBeDisabled();
	expect(
		screen.getByText( 'Недоступно: ни один провайдер не поддерживает эту операцию.' )
	).toBeInTheDocument();
} );
