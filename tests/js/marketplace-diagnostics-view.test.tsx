/**
 * The «Диагностика» view: one white wrapper with an intro sentence and a card per section. The page has no logging switch of
 * its own — the menu lists it only while «Логирование» is on (PHP), and the switch lives in the plugin's settings.
 *
 * @see src/marketplace-admin/views/diagnostics/diagnostics-view.tsx
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { DiagnosticsView } from '../../src/marketplace-admin/views/diagnostics';
import type { ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';

jest.mock( '@wordpress/api-fetch' );

const BASE = '/woodev/v1/admin/marketplace/ozon/diagnostics';

const PAGE = { id: 'diagnostics', title: 'Диагностика', path: '/ozon/diagnostics', kind: 'custom', tables: [], view: 'diagnostics', breadcrumbs: [], analytics: null } as ManifestPage;
const MENU = { id: 'ozon', pluginId: 'ozon', title: 'Ozon', domId: 'x', pages: [ PAGE ] } as ManifestMenu;

const mocked = apiFetch as unknown as jest.Mock;

const messages = {
	intro: 'Что происходит с подключением, уведомлениями, очередью и ошибками.',
	loggingOff: 'Логирование выключено, поэтому подробной диагностики нет.',
	reportHeading: 'Отчёт для поддержки',
	reportHint: 'Скопируйте этот текст.',
	copyReport: 'Скопировать отчёт для поддержки',
	copied: 'Скопировано',
	copyFailed: 'Не удалось скопировать.',
	reportFailed: 'Не удалось получить отчёт.',
};

const serve = ( enabled: boolean ) => {
	mocked.mockImplementation( ( options: { path: string } ) => {
		if ( options.path === `${ BASE }/report` ) {
			return Promise.resolve( { messages, diagnostic: [ { title: 'Очередь', lines: [ { label: 'Задач', value: '3' } ] } ], report: 'ОТЧЁТ' } );
		}

		return Promise.resolve( {
			messages,
			logging: { enabled },
			operational: [ { title: 'Лицензия', lines: [ { label: 'Статус', value: 'активна' } ] } ],
		} );
	} );
};

beforeEach( () => mocked.mockReset() );

describe( 'the diagnostics view', () => {
	test( 'is one wrapper card with the intro, a card per section and the support report, and no logging switch', async () => {
		serve( true );

		const { container } = render( <DiagnosticsView page={ PAGE } menu={ MENU } query={ {} } /> );

		await screen.findByText( 'Очередь' );

		const wrappers = container.querySelectorAll( '.woodev-settings__card' );
		expect( wrappers ).toHaveLength( 1 );
		expect( wrappers[ 0 ].querySelector( '.woodev-settings__section-desc' ) ).toHaveTextContent( messages.intro );
		expect( wrappers[ 0 ].querySelectorAll( '.woodev-group' ) ).toHaveLength( 3 ); // Лицензия, Очередь, the report
		expect( screen.getByRole( 'button', { name: 'Скопировать отчёт для поддержки' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /логирование/i } ) ).not.toBeInTheDocument();
		expect( screen.queryByText( messages.loggingOff ) ).not.toBeInTheDocument();
		expect( mocked ).not.toHaveBeenCalledWith( expect.objectContaining( { method: 'POST' } ) );
	} );

	test( 'a page left open after logging was switched off says so and shows only the operational state', async () => {
		serve( false );

		render( <DiagnosticsView page={ PAGE } menu={ MENU } query={ {} } /> );

		// A WordPress notice is also announced through the a11y live region, so the sentence can be on the page twice.
		expect( ( await screen.findAllByText( messages.loggingOff ) ).length ).toBeGreaterThan( 0 );

		expect( screen.getByText( 'Лицензия' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Очередь' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( mocked ).not.toHaveBeenCalledWith( { path: `${ BASE }/report` } );
	} );
} );
