/**
 * «Every row under the filter» on the records table (framework card #1792, spec §2.1–§2.2): the bar above a ticked page, the
 * mode and what ends it, the toolbar that offers only the actions that declared `all_matching`, the request body that
 * carries `matching` and no `ids`, and the buttons of the account line that run an account action with the payload the
 * server put on them. A `hidden` field is the carrier of that payload and is never drawn.
 *
 * @see src/admin-records/selection.ts
 * @see src/admin-records/table-model.tsx
 * @see src/admin-records/activity/views.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions } from '../../src/admin-records';
import { footerButton } from './dialog-parts';
import { createActivityExtensions, type ActivityExtensions } from '../../src/admin-records/activity';
import { filterUrlKey } from '../../src/admin-records/query';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor, ActionField, Definition } from '../../src/admin-records/types';
import type { AccountActivity } from '../../src/admin-records/activity/types';
import type { ApplyResponse, PreviewResponse } from '../../src/admin-records/actions/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const BASE = '/woodev/v1/admin/records/shop/items';

/** The words `Standard_Messages::matching()` and `::bulk()` send, plus the dialog's own. */
const COPY = {
	selected_page: 'Выбраны все записи на этой странице: {count}.',
	select_all_matching: 'Выбрать все записи под текущим отбором: {count}',
	selected_all_matching: 'Выбраны все записи под текущим отбором: {count}. {filters}',
	clear_selection: 'Снять выбор',
	action_cancel: 'Отмена',
	action_close: 'Закрыть',
	action_planning: 'Считаем…',
	action_applying: 'Выполняем…',
	action_status_done: 'Готово.',
	action_status_queued: 'Поставлено в очередь.',
};

function action( id: string, label: string, overrides: Partial< ActionDescriptor > = {} ): ActionDescriptor {
	return {
		id,
		label,
		tone: 'neutral',
		capability: '',
		effect: 'mutate',
		interaction: 'dialog',
		scope: 'selection',
		scope_sentence: '',
		confirm_label: '',
		skip_preview: false,
		all_matching: false,
		fields: [],
		...overrides,
	};
}

function field( id: string, label: string, overrides: Partial< ActionField > = {} ): ActionField {
	return { id, label, type: 'text', required: false, options: [], options_source: false, help: '', ...overrides };
}

const BULK_ACTIONS = [
	action( 'send_stock', 'Отправить остатки', { all_matching: true } ),
	action( 'plain', 'Только отмеченные' ),
	action( 'link_all', 'Связать по артикулу', { scope: 'account', scope_sentence: 'Будут обработаны все товары аккаунта.' } ),
	// The stored report of a run: read-only, its answer is the preview.
	action( 'bulk_report', 'Подробнее', {
		effect: 'read',
		scope: 'account',
		scope_sentence: 'Отчёт о последнем запуске.',
		fields: [ field( 'run_id', 'Запуск', { type: 'hidden', required: true } ) ],
	} ),
	action( 'bulk_stop', 'Остановить', {
		scope: 'account',
		scope_sentence: 'Остановит выполняющееся действие.',
		fields: [ field( 'run_id', 'Запуск', { type: 'hidden', required: true } ) ],
	} ),
];

function definition( overrides: Partial< Definition > = {} ): Definition {
	const base = fixtureDefinition();

	return {
		...base,
		row_actions: [],
		bulk_actions: BULK_ACTIONS,
		children: null,
		pagination: { default_per_page: 25, per_page_options: [ 25, 50 ], max_per_page: 50, max_selection: 100 },
		// A refresh far away: the loop is on (the account line needs it) but never fires inside a test.
		activity: { refresh_ms: 600000, max_refresh_ms: 600000, stale_after_ms: 600000, id_limit: 50 },
		messages: { ...base.messages, ...COPY },
		...overrides,
	};
}

const ROWS = [ fixtureRow( 'p1', 'Кружка' ), fixtureRow( 'p2', 'Футболка' ) ];

function account( overrides: Partial< AccountActivity > = {} ): AccountActivity {
	return { active: false, text: '', blocked_actions: [], observed_at: '2026-11-16T10:00:00Z', ...overrides };
}

function plan( overrides: Partial< PreviewResponse > = {} ): PreviewResponse {
	return {
		action: 'send_stock',
		scope: { type: 'selection', sentence: 'Будет затронуто 340 товаров по фильтру.' },
		requested: 340,
		eligible: 340,
		skipped: 0,
		warnings: [],
		groups: [],
		messages: {},
		fields: [],
		confirm_label: '',
		can_apply: true,
		...overrides,
	};
}

function outcome( overrides: Partial< ApplyResponse > = {} ): ApplyResponse {
	return {
		status: 'queued',
		rows: [],
		removed_ids: [],
		requested: 340,
		accepted: 340,
		skipped: 0,
		failed: 0,
		groups: [],
		messages: {},
		run: { id: 'r9f2', products_total: 340 },
		...overrides,
	};
}

interface Call {
	path: string;
	method?: string;
	data?: Record< string, unknown >;
}

function serve( list: { total?: number; account?: AccountActivity } = {} ): void {
	fetchMock.mockImplementation( ( options: Call ) => {
		const path = decodeURIComponent( options.path );
		const [ route ] = path.split( '?' );

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve( fixtureDefinitionEnvelope( definition() ) );
		}
		if ( options.method === 'POST' && route.endsWith( '/activity' ) ) {
			return Promise.resolve( {
				rows: [],
				removed_ids: [],
				account_activity: list.account ?? account(),
				observed_at: '2026-11-16T10:00:00Z',
				refresh_after_ms: 600000,
				messages: {},
			} );
		}
		if ( options.method === 'POST' && route.endsWith( '/bulk_report/preview' ) ) {
			return Promise.resolve(
				plan( {
					action: 'bulk_report',
					scope: { type: 'account', sentence: 'Отчёт о последнем запуске.' },
					groups: [ { kind: 'failed', reason: 'Нет артикула', count: 45, fix_label: '', fix_url: '', examples: [ 'Кружка' ] } ],
				} )
			);
		}
		if ( options.method === 'POST' && route.endsWith( '/preview' ) ) {
			return Promise.resolve( plan() );
		}
		if ( options.method === 'POST' ) {
			return Promise.resolve( outcome() );
		}

		return Promise.resolve(
			fixtureList( ROWS, { total: list.total ?? 340, account_activity: list.account ?? account() } )
		);
	} );
}

function posts( suffix: string ): Call[] {
	return fetchMock.mock.calls
		.map( ( [ options ] ) => ( { ...options, path: decodeURIComponent( options.path ) } ) as Call )
		.filter( ( call ) => call.method === 'POST' && call.path.startsWith( `${ BASE }/actions/` ) )
		.filter( ( call ) => call.path.endsWith( '/preview' ) === ( suffix === 'preview' ) );
}

function listReads(): number {
	return fetchMock.mock.calls.filter( ( [ options ] ) => decodeURIComponent( options.path ).startsWith( `${ BASE }?` ) )
		.length;
}

function FakeTableCard( props: TableCardProps ) {
	if ( props.isLoading ) {
		return <div data-testid="table-loading" />;
	}

	return (
		<>
			<div className="fake-card-header">{ props.actions }</div>
			<table aria-label={ props.title }>
				<thead>
					<tr>
						{ props.headers.map( ( header ) => (
							<th key={ header.key }>{ header.label }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ props.rows.map( ( row, index ) => (
						<tr key={ props.rowKey( row, index ) }>
							{ row.map( ( cell, column ) => (
								<td key={ column }>{ cell.display }</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}

const activity: ActivityExtensions = createActivityExtensions();

function Page( { query = {} }: { query?: Record< string, string > } ) {
	const actions = useActionExtensions( activity );

	return (
		<RecordsTable
			owner="shop"
			table="items"
			title="Товары"
			query={ query }
			navigate={ jest.fn() }
			extensions={ { ...actions, ...activity.extensions } }
		/>
	);
}

async function renderPage( query: Record< string, string > = {} ) {
	const view = render( <Page query={ query } /> );
	await screen.findByRole( 'table', { name: 'Товары' } );

	return view;
}

const headerBox = () => screen.getByRole( 'checkbox', { name: 'Выбрать все на странице' } );
const rowBox = ( name: string ) => screen.getByRole( 'checkbox', { name: new RegExp( name ) } );
const bar = () => document.querySelector( '.woodev-records__selection-bar' );

function bulkChoices(): string[] {
	const select = screen.getByRole( 'combobox', { name: 'Массовые действия' } );

	return within( select )
		.getAllByRole( 'option' )
		.map( ( option ) => option.textContent ?? '' )
		.filter( ( text ) => text !== 'Выберите действие' );
}

/** Ticks the whole page and takes every row under the filter. */
async function takeEveryRow() {
	fireEvent.click( headerBox() );
	fireEvent.click( await screen.findByRole( 'button', { name: /Выбрать все записи под текущим отбором/ } ) );
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	serve();
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'the bar above a ticked page', () => {
	test( 'is absent until the header box has selected the whole page', async () => {
		await renderPage();

		expect( bar() ).toBeNull();

		fireEvent.click( rowBox( 'Кружка' ) );

		expect( bar() ).toBeNull();
	} );

	test( 'offers every row under the filter on a full page when the table holds more', async () => {
		await renderPage();

		fireEvent.click( headerBox() );

		expect( bar() ).toHaveTextContent( 'Выбраны все записи на этой странице: 2.' );
		expect( screen.getByRole( 'button', { name: /Выбрать все записи под текущим отбором: 340/ } ) ).toBeInTheDocument();
	} );

	test( 'is absent when the page already holds every row', async () => {
		serve( { total: 2 } );
		await renderPage();

		fireEvent.click( headerBox() );

		expect( bar() ).toBeNull();
	} );

	test( 'is absent when no bulk action can run on every row', async () => {
		fetchMock.mockImplementation( ( options: Call ) => {
			const [ route ] = decodeURIComponent( options.path ).split( '?' );

			return Promise.resolve(
				route.endsWith( '/definition' )
					? fixtureDefinitionEnvelope(
							definition( { bulk_actions: [ action( 'plain', 'Только отмеченные' ) ] } )
						)
					: fixtureList( ROWS, { total: 340 } )
			);
		} );
		await renderPage();

		fireEvent.click( headerBox() );

		expect( bar() ).toBeNull();
	} );
} );

describe( 'the «every row under the filter» mode', () => {
	test( 'says how many rows and under which filters, ticks every visible row and lists only the actions that declared it', async () => {
		await renderPage( { search: 'кружка', [ filterUrlKey( 'state' ) ]: 'linked' } );

		expect( bulkChoices() ).toEqual( [ 'Отправить остатки', 'Только отмеченные' ] );

		await takeEveryRow();

		expect( bar() ).toHaveTextContent( /Выбраны все записи под текущим отбором: 340/ );
		expect( bar() ).toHaveTextContent( '«Состояние: Связан · Поиск по товарам: «кружка»»' );
		expect( screen.getByRole( 'button', { name: 'Снять выбор' } ) ).toBeInTheDocument();
		expect( rowBox( 'Кружка' ) ).toBeChecked();
		expect( rowBox( 'Футболка' ) ).toBeChecked();
		expect( bulkChoices() ).toEqual( [ 'Отправить остатки' ] );
	} );

	test( 'unticking a row leaves the mode and clears the selection entirely', async () => {
		await renderPage();
		await takeEveryRow();

		fireEvent.click( rowBox( 'Футболка' ) );

		expect( bar() ).toBeNull();
		expect( rowBox( 'Кружка' ) ).not.toBeChecked();
		expect( rowBox( 'Футболка' ) ).not.toBeChecked();
		expect( bulkChoices() ).toEqual( [ 'Отправить остатки', 'Только отмеченные' ] );
		// Nothing is selected, so the toolbar has nothing to run on.
		expect( screen.getByRole( 'button', { name: 'Применить' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'unticking a row in the ordinary ticked mode only takes that row out', async () => {
		await renderPage();
		fireEvent.click( headerBox() );

		fireEvent.click( rowBox( 'Футболка' ) );

		expect( rowBox( 'Кружка' ) ).toBeChecked();
		expect( rowBox( 'Футболка' ) ).not.toBeChecked();
	} );

	test( 'the clear button ends it and unticks the page', async () => {
		await renderPage();
		await takeEveryRow();

		fireEvent.click( screen.getByRole( 'button', { name: 'Снять выбор' } ) );

		expect( bar() ).toBeNull();
		expect( rowBox( 'Кружка' ) ).not.toBeChecked();
	} );

	test( 'a changed filter clears it', async () => {
		const view = await renderPage();
		await takeEveryRow();
		const reads = listReads();

		view.rerender( <Page query={ { [ filterUrlKey( 'state' ) ]: 'draft' } } /> );
		await waitFor( () => expect( listReads() ).toBeGreaterThan( reads ) );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( bar() ).toBeNull();
		expect( rowBox( 'Кружка' ) ).not.toBeChecked();
	} );

	test( 'a changed search clears it', async () => {
		const view = await renderPage();
		await takeEveryRow();
		const reads = listReads();

		view.rerender( <Page query={ { search: 'футболка' } } /> );
		await waitFor( () => expect( listReads() ).toBeGreaterThan( reads ) );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( bar() ).toBeNull();
		expect( rowBox( 'Футболка' ) ).not.toBeChecked();
	} );
} );

describe( 'the request of an action over every row', () => {
	test( 'carries the frozen filter as `matching` and no `ids`, in the preview and in the apply', async () => {
		await renderPage( { search: 'кружка', [ filterUrlKey( 'state' ) ]: 'linked' } );
		await takeEveryRow();

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Массовые действия' } ), {
			target: { value: 'send_stock' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Применить' } ) );

		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByText( 'Будет затронуто 340 товаров по фильтру.' );

		const previewed = posts( 'preview' );
		expect( previewed ).toHaveLength( 1 );
		expect( previewed[ 0 ].path ).toBe( `${ BASE }/actions/send_stock/preview` );
		expect( previewed[ 0 ].data ).toEqual( {
			context: { account_id: 'a1' },
			matching: { filters: { state: 'linked' }, search: 'кружка' },
			payload: {},
		} );
		expect( previewed[ 0 ].data ).not.toHaveProperty( 'ids' );

		const reads = listReads();
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Отправить остатки' } ) );
		await within( dialog ).findByText( /Поставлено в очередь/ );

		const applied = posts( 'apply' );
		expect( applied ).toHaveLength( 1 );
		expect( applied[ 0 ].data ).toEqual( {
			context: { account_id: 'a1' },
			matching: { filters: { state: 'linked' }, search: 'кружка' },
			payload: {},
			confirmed: true,
		} );
		expect( applied[ 0 ].data ).not.toHaveProperty( 'ids' );
		// The rows that changed are mostly off this page: the table is read again.
		await waitFor( () => expect( listReads() ).toBeGreaterThan( reads ) );
	} );

	test( 'an empty filter sends an empty `matching`: the whole table', async () => {
		await renderPage( { [ filterUrlKey( 'state' ) ]: '' } );
		await takeEveryRow();

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Массовые действия' } ), {
			target: { value: 'send_stock' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Применить' } ) );
		await screen.findByRole( 'dialog' );
		await waitFor( () => expect( posts( 'preview' ) ).toHaveLength( 1 ) );

		expect( posts( 'preview' )[ 0 ].data?.matching ).toEqual( {} );
	} );

	test( 'ticked rows still send ids and no `matching`', async () => {
		await renderPage();

		fireEvent.click( rowBox( 'Кружка' ) );
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Массовые действия' } ), {
			target: { value: 'plain' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Применить' } ) );
		await screen.findByRole( 'dialog' );
		await waitFor( () => expect( posts( 'preview' ) ).toHaveLength( 1 ) );

		expect( posts( 'preview' )[ 0 ].data ).toEqual( {
			context: { account_id: 'a1' },
			ids: [ 'p1' ],
			payload: {},
		} );
	} );
} );

describe( 'the account line with buttons', () => {
	const LINE = account( {
		active: true,
		text: 'Выполняется «Отправить остатки» по фильтру: товаров 3 400 из ~12 345.',
		actions: [
			{ action: 'bulk_stop', label: 'Остановить', payload: { run_id: 'r9f2' } },
			{ action: 'bulk_report', label: 'Подробнее', payload: { run_id: 'r9f2' } },
		],
	} );

	function lineButton( name: string ): HTMLElement {
		const line = document.querySelector( '.woodev-records__account-line' ) as HTMLElement;

		return within( line ).getByRole( 'button', { name } );
	}

	test( 'draws each button beside the sentence', async () => {
		serve( { account: LINE } );
		await renderPage();

		const line = document.querySelector( '.woodev-records__account-line' ) as HTMLElement;

		expect( line ).toHaveTextContent( 'Выполняется «Отправить остатки» по фильтру' );
		expect( within( line ).getAllByRole( 'button' ).map( ( button ) => button.textContent ) ).toEqual( [
			'Остановить',
			'Подробнее',
		] );
	} );

	test( 'a finished line keeps its sentence for the buttons', async () => {
		serve( { account: { ...LINE, active: false, text: 'Готово: товаров 12 000.' } } );
		await renderPage();

		expect( lineButton( 'Подробнее' ) ).toBeInTheDocument();
	} );

	test( 'a line that is neither active nor has buttons draws nothing', async () => {
		serve( { account: account( { text: 'Тихо.' } ) } );
		await renderPage();

		expect( document.querySelector( '.woodev-records__account-line' ) ).toBeNull();
	} );

	test( 'a button runs its account action with the account context, no ids and the payload it was given', async () => {
		serve( { account: LINE } );
		await renderPage();

		fireEvent.click( lineButton( 'Остановить' ) );

		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByText( 'Будет затронуто 340 товаров по фильтру.' );

		const previewed = posts( 'preview' );
		expect( previewed[ 0 ].path ).toBe( `${ BASE }/actions/bulk_stop/preview` );
		expect( previewed[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [], payload: { run_id: 'r9f2' } } );

		// A mutating action asks for the merchant's yes in its own dialog before anything is applied.
		expect( posts( 'apply' ) ).toHaveLength( 0 );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Остановить' } ) );
		await within( dialog ).findByText( /Поставлено в очередь/ );

		expect( posts( 'apply' )[ 0 ].data ).toEqual( {
			context: { account_id: 'a1' },
			ids: [],
			payload: { run_id: 'r9f2' },
			confirmed: true,
		} );
	} );

	test( 'a hidden field is never rendered: the dialog has no input for it', async () => {
		serve( { account: LINE } );
		await renderPage();

		fireEvent.click( lineButton( 'Остановить' ) );
		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByText( 'Будет затронуто 340 товаров по фильтру.' );

		expect( dialog.querySelector( 'input, select, textarea' ) ).toBeNull();
		expect( within( dialog ).queryByText( 'Запуск' ) ).toBeNull();
		expect( dialog.querySelector( '.woodev-records__fields' ) ).toBeNull();
	} );
	test( 'a read-only report shows its preview groups and closes without applying anything', async () => {
		serve( { account: LINE } );
		await renderPage();

		fireEvent.click( lineButton( 'Подробнее' ) );

		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByText( 'Нет артикула' );

		const previewed = posts( 'preview' );
		expect( previewed ).toHaveLength( 1 );
		expect( previewed[ 0 ].path ).toBe( `${ BASE }/actions/bulk_report/preview` );
		expect( previewed[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [], payload: { run_id: 'r9f2' } } );
		// Nothing to confirm: the only button is the one that closes.
		expect( within( dialog ).getAllByRole( 'button' ).map( ( button ) => button.textContent ) ).toContain( 'Закрыть' );
		expect( within( dialog ).queryByRole( 'button', { name: 'Подробнее' } ) ).toBeNull();

		fireEvent.click( footerButton( dialog, 'Закрыть' ) );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( posts( 'apply' ) ).toHaveLength( 0 );
	} );

	test( 'an action that needs a hidden value has no standalone button: only the line runs it', async () => {
		serve( { account: LINE } );
		await renderPage();

		const toolbar = document.querySelector( '.woodev-records__account-actions' ) as HTMLElement;

		expect( within( toolbar ).getAllByRole( 'button' ).map( ( button ) => button.textContent ) ).toEqual( [
			'Связать по артикулу',
		] );
		expect( screen.getAllByRole( 'button', { name: 'Остановить' } ) ).toHaveLength( 1 );
		expect( lineButton( 'Остановить' ) ).toBeInTheDocument();
	} );
} );
