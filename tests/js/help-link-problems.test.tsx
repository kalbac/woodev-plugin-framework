/**
 * The «Частые проблемы» link of a page: provided once by the page container, drawn by every error or warning the framework
 * shows, never by a success note, and absent when the plugin's help table has no topic.
 *
 * @see src/components/help-link.tsx (ProblemsHelpContext, ProblemsLink)
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { addFilter, removeFilter } from '@wordpress/hooks';
import { ProblemsHelpContext, ProblemsLink } from '../../src/components/help-link';
import { ErrorState } from '../../src/admin-records/states';
import { PageBody, PAGE_VIEW_FILTER } from '../../src/marketplace-admin/page-view';
import { Notices } from '../../src/marketplace-admin/views/mappings/notices';
import { DiagnosticsView } from '../../src/marketplace-admin/views/diagnostics/diagnostics-view';
import { ToolsView } from '../../src/marketplace-admin/views/tools';
import type { ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';

jest.mock( '@wordpress/api-fetch' );

const PROBLEMS = {
	url: 'https://woodev.ru/docs/ozon/#chastye-problemy',
	text: 'Что делать, если не получается',
	label: 'Что делать, если не получается (откроется в новой вкладке)',
};

const PAGE = { id: 'tools', title: 'Инструменты', path: '/ozon/tools', kind: 'custom', tables: [], view: 'tools', breadcrumbs: [], analytics: null } as ManifestPage;
const MENU = { id: 'ozon', pluginId: 'ozon', title: 'Ozon', domId: 'x', pages: [ PAGE ] } as ManifestMenu;

const mocked = apiFetch as unknown as jest.Mock;

beforeEach( () => {
	mocked.mockReset();
} );

const withProblems = ( node: JSX.Element, value = PROBLEMS ) => (
	<ProblemsHelpContext.Provider value={ value }>{ node }</ProblemsHelpContext.Provider>
);

describe( 'ProblemsLink', () => {
	test( 'draws the page link after an error or a warning, not after a success', () => {
		render( withProblems( <><ProblemsLink status="error" /><ProblemsLink status="warning" /><ProblemsLink status="success" /></> ) );

		expect( screen.getAllByRole( 'link', { name: PROBLEMS.label } ) ).toHaveLength( 2 );
	} );

	test( 'draws nothing without a page link', () => {
		render( <ProblemsLink status="error" /> );

		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );
} );

describe( 'the framework surfaces that show errors and notes', () => {
	test( 'the mappings and profile notices: error yes, success no', () => {
		const { rerender } = render( withProblems( <Notices notice={ { status: 'error', lines: [ 'Не сохранилось.' ] } } onDismiss={ jest.fn() } /> ) );
		expect( screen.getByRole( 'link', { name: PROBLEMS.label } ) ).toHaveAttribute( 'href', PROBLEMS.url );

		rerender( withProblems( <Notices notice={ { status: 'success', lines: [ 'Сохранено.' ] } } onDismiss={ jest.fn() } /> ) );
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	test( 'the records table error state', () => {
		render( withProblems( <ErrorState text="Не удалось загрузить." retryLabel="Повторить" onRetry={ jest.fn() } /> ) );

		expect( screen.getByRole( 'link', { name: PROBLEMS.label } ) ).toBeInTheDocument();
	} );

	test( 'the diagnostics view when its state cannot be read', async () => {
		mocked.mockRejectedValue( { message: 'Нет связи' } );
		render( withProblems( <DiagnosticsView page={ PAGE } menu={ MENU } query={ {} } /> ) );

		expect( await screen.findByRole( 'link', { name: PROBLEMS.label } ) ).toBeInTheDocument();
	} );

	test( 'the tools view when its state cannot be read', async () => {
		mocked.mockRejectedValue( { message: 'Нет связи' } );
		render( withProblems( <ToolsView page={ PAGE } menu={ MENU } query={ {} } /> ) );

		expect( await screen.findByRole( 'link', { name: PROBLEMS.label } ) ).toBeInTheDocument();
	} );

	test( 'a plugin without the topic: the same failure shows no link', async () => {
		mocked.mockRejectedValue( { message: 'Нет связи' } );
		render( <ToolsView page={ PAGE } menu={ MENU } query={ {} } /> );

		await screen.findByText( /Нет связи/ );
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );
} );

describe( 'the page container', () => {
	function Probe() {
		return <ProblemsLink status="error" />;
	}

	test( 'provides page.problems to the registered view, and nothing when the manifest has none', () => {
		const filter = () => Probe;
		addFilter( PAGE_VIEW_FILTER, 'test/problems-probe', filter );

		const { unmount } = render( <PageBody page={ { ...PAGE, problems: PROBLEMS } } menu={ MENU } query={ {} } /> );
		expect( screen.getByRole( 'link', { name: PROBLEMS.label } ) ).toHaveAttribute( 'href', PROBLEMS.url );
		unmount();

		render( <PageBody page={ PAGE } menu={ MENU } query={ {} } /> );
		expect( screen.queryByRole( 'link' ) ).toBeNull();

		removeFilter( PAGE_VIEW_FILTER, 'test/problems-probe' );
	} );
} );
