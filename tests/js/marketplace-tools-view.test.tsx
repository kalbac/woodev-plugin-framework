/**
 * The «Инструменты» view: every tool runs on an account, and the account selector shows only when the plugin has two or
 * more of them. One account is chosen silently; none leaves the empty state.
 *
 * @see src/marketplace-admin/views/tools/tools-view.tsx
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { ToolsView } from '../../src/marketplace-admin/views/tools';
import type { ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';
import type { ToolsState } from '../../src/marketplace-admin/views/tools/types';

jest.mock( '@wordpress/api-fetch' );

const BASE = '/woodev/v1/admin/marketplace/ozon/tools';

const PAGE = { id: 'tools', title: 'Инструменты', path: '/ozon/tools', kind: 'custom', tables: [], view: 'tools', breadcrumbs: [], analytics: null } as ManifestPage;
const MENU = { id: 'ozon', pluginId: 'ozon', title: 'Ozon', domId: 'x', pages: [ PAGE ] } as ManifestMenu;

const mocked = apiFetch as unknown as jest.Mock;

const state = ( accounts: ToolsState[ 'accounts' ] ): ToolsState => ( {
	messages: {
		accountLabel: 'Аккаунт',
		chooseAccount: 'Выберите аккаунт, чтобы запускать инструменты.',
		noTools: 'Инструментов нет.',
		previewButton: 'Проверить',
		cancelButton: 'Отмена',
		running: 'Выполняется…',
		loadFailed: 'Не удалось загрузить.',
		runFailed: 'Не выполнен.',
	},
	accounts,
	tools: [ { id: 'rerun', title: 'Перенос данных', description: 'Переносит данные.', button: 'Запустить', takes_claim: false, disabled: false, disabled_reason: '' } ],
	activity: { active: false, text: '', blocked_actions: [], observed_at: '2026-11-14T00:00:00Z' },
} );

type Call = { path: string; method?: string; data?: Record< string, unknown > };

/** Answers the state route and the preview route, and remembers every call. */
function serve( accounts: ToolsState[ 'accounts' ] ): Call[] {
	const calls: Call[] = [];

	mocked.mockImplementation( ( options: Call ) => {
		calls.push( options );

		if ( options.path.endsWith( '/preview' ) ) {
			return Promise.resolve( { tool: 'rerun', summary: 'Будет перенесено 3.', disabled: false, disabled_reason: '', confirm_label: 'Запустить', can_run: true } );
		}

		return Promise.resolve( state( accounts ) );
	} );

	return calls;
}

beforeEach( () => {
	mocked.mockReset();
} );

describe( 'the account selector', () => {
	test( 'is not shown with one account, and the tool runs on that account', async () => {
		const calls = serve( [ { id: 3, name: 'Главный' } ] );

		render( <ToolsView page={ PAGE } menu={ MENU } query={ {} } /> );

		await screen.findByText( 'Перенос данных' );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Проверить' } ) ).toBeEnabled() );

		expect( screen.queryByLabelText( 'Аккаунт' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Выберите аккаунт, чтобы запускать инструменты.' ) ).not.toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );
		} );

		await screen.findByText( 'Будет перенесено 3.' );
		expect( calls.find( ( call ) => call.path.endsWith( '/preview' ) )?.data ).toEqual( { account_id: 3 } );
	} );

	test( 'is shown with two accounts, the first one chosen', async () => {
		serve( [
			{ id: 3, name: 'Главный' },
			{ id: 7, name: 'Запасной' },
		] );

		render( <ToolsView page={ PAGE } menu={ MENU } query={ {} } /> );

		const select = await screen.findByLabelText( 'Аккаунт' );

		await waitFor( () => expect( select ).toHaveValue( '3' ) );
		expect( screen.getByRole( 'option', { name: 'Запасной' } ) ).toBeInTheDocument();
	} );

	test( 'is not shown with no account: the page asks to choose one and the tool cannot be previewed', async () => {
		serve( [] );

		render( <ToolsView page={ PAGE } menu={ MENU } query={ {} } /> );

		await screen.findByText( 'Выберите аккаунт, чтобы запускать инструменты.' );

		expect( screen.queryByLabelText( 'Аккаунт' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Проверить' } ) ).toBeDisabled();
		expect( mocked ).toHaveBeenCalledWith( { path: BASE } );
	} );
} );
