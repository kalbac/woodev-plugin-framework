/**
 * Preview and action interactions of the records library: the dialog a row or bulk action opens, the plan, the
 * confirmation, the result, and what happens when a request is repeated, refused or lost.
 *
 * The routes are the ones of `Action_Rest_Handler` (F6): `POST …/actions/{action}/preview` and
 * `POST …/actions/{action}`. `TableCard` is a double, as in the shell's own tests.
 *
 * @see src/admin-records/actions/
 */

import '@testing-library/jest-dom';
import { StrictMode } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions } from '../../src/admin-records';
import { createActivityExtensions, type ActivityExtensions } from '../../src/admin-records/activity';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor, ActionField, Definition } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';
import type { ApplyResponse, PreviewResponse } from '../../src/admin-records/actions/types';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const BASE = '/woodev/v1/admin/records/shop/items';

/** What PHP sends for the dialog: a definition's `messages` carry these next to the shell's. */
const ACTION_COPY = {
	action_cancel: 'Отмена',
	action_close: 'Закрыть',
	action_preview: 'Показать, что изменится',
	action_planning: 'Считаем…',
	action_applying: 'Выполняем…',
	action_retry: 'Повторить расчёт',
	action_requested: 'Запрошено',
	action_eligible: 'Будет обработано',
	action_skipped: 'Пропущено',
	action_accepted: 'Принято',
	action_failed: 'Не удалось',
	action_status_done: 'Готово.',
	action_status_partial: 'Выполнено частично.',
	action_status_queued: 'Поставлено в очередь.',
	action_status_failed: 'Ничего не принято.',
	action_group_skipped: 'Пропущено строк',
	action_group_failed: 'Ошибка',
	action_warnings: 'Обратите внимание',
	action_recovery: 'Ответа нет, действие могло выполниться. Список обновлён — проверьте строки.',
	action_error: 'Не удалось выполнить действие',
	action_download: 'Скачать файл',
	action_download_blocked: 'Адрес файла не прошёл проверку',
	action_choose: 'Выберите',
	action_options_more: 'Ещё склады',
	action_options_error: 'Не удалось загрузить список',
};

function action( id: string, label: string, overrides: Partial< ActionDescriptor > = {} ): ActionDescriptor {
	return {
		id,
		label,
		tone: 'neutral',
		capability: '',
		effect: 'mutate',
		interaction: 'submit',
		scope: 'selection',
		scope_sentence: '',
		confirm_label: '',
		skip_preview: false,
		fields: [],
		...overrides,
	};
}

function field( id: string, label: string, overrides: Partial< ActionField > = {} ): ActionField {
	return { id, label, type: 'text', required: false, options: [], options_source: false, help: '', ...overrides };
}

const WAREHOUSE = field( 'warehouse', 'Склад', { type: 'select', required: true, options_source: true } );

const ROW_ACTIONS = [
	action( 'export', 'Выгрузить', { tone: 'primary', confirm_label: 'Выгрузить товар' } ),
	action( 'sync', 'Отправить сразу', { skip_preview: true } ),
	action( 'open', 'Открыть', { interaction: 'link', effect: 'read' } ),
];

const BULK_ACTIONS = [
	action( 'bulk_export', 'Выгрузить выбранные', {
		interaction: 'dialog',
		confirm_label: 'Выгрузить всё',
		fields: [ WAREHOUSE, field( 'note', 'Заметка' ) ],
	} ),
	action( 'link_all', 'Связать по артикулу', {
		interaction: 'dialog',
		scope: 'account',
		scope_sentence: 'Будут обработаны все товары аккаунта.',
	} ),
	action( 'labels', 'Скачать этикетки', { effect: 'read', interaction: 'download' } ),
];

function definition( overrides: Partial< Definition > = {} ): Definition {
	const base = fixtureDefinition();

	return {
		...base,
		row_actions: ROW_ACTIONS,
		bulk_actions: BULK_ACTIONS,
		messages: { ...base.messages, ...ACTION_COPY },
		...overrides,
	};
}

/** A row that offers the actions of this suite (the fixture's own rows offer two others). */
function shopRow( id: string, name: string, openUrl: string | null = `/wp-admin/post.php?post=${ id }&action=edit` ) {
	return fixtureRow( id, name, {
		actions: [
			{ id: 'export', enabled: true, reason: '' },
			{ id: 'sync', enabled: true, reason: '' },
			{ id: 'open', enabled: true, reason: '', ...( openUrl === null ? {} : { url: openUrl } ) },
		],
	} );
}

function plan( overrides: Partial< PreviewResponse > = {} ): PreviewResponse {
	return {
		action: 'export',
		scope: { type: 'selection', sentence: '' },
		requested: 1,
		eligible: 1,
		skipped: 0,
		warnings: [],
		groups: [],
		messages: { summary: 'Будет выгружено 1 из 1.' },
		fields: [],
		confirm_label: '',
		can_apply: true,
		...overrides,
	};
}

function outcome( overrides: Partial< ApplyResponse > = {} ): ApplyResponse {
	return {
		status: 'done',
		rows: [],
		removed_ids: [],
		requested: 1,
		accepted: 1,
		skipped: 0,
		failed: 0,
		groups: [],
		messages: {},
		...overrides,
	};
}

interface Deferred< T > {
	promise: Promise< T >;
	resolve: ( value: T ) => void;
	reject: ( error: unknown ) => void;
}

function defer< T >(): Deferred< T > {
	let resolve!: ( value: T ) => void;
	let reject!: ( error: unknown ) => void;
	const promise = new Promise< T >( ( res, rej ) => {
		resolve = res;
		reject = rej;
	} );

	return { promise, resolve, reject };
}

interface Call {
	path: string;
	method: string;
	data?: Record< string, unknown >;
	signal?: AbortSignal;
}

type Handler< T > = ( call: Call ) => T | Promise< T >;

interface Server {
	definition: Handler< unknown >;
	rows: Handler< unknown >;
	preview: Handler< PreviewResponse >;
	apply: Handler< ApplyResponse >;
	options: Handler< unknown >;
}

function serve( overrides: Partial< Server > = {} ): void {
	const server: Server = {
		definition: () => fixtureDefinitionEnvelope( definition() ),
		rows: () => fixtureList( [ shopRow( 'p1', 'Кружка' ), shopRow( 'p2', 'Футболка' ) ] ),
		preview: () => plan(),
		apply: () => outcome(),
		options: () => ( { options: [ { value: 'w1', label: 'Москва' } ], next_cursor: null } ),
		...overrides,
	};

	fetchMock.mockImplementation( ( options: Call ) => {
		const path = decodeURIComponent( options.path );
		const [ route ] = path.split( '?' );
		const call = { ...options, path };

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve( server.definition( call ) );
		}
		if ( route.endsWith( '/options' ) ) {
			return Promise.resolve( server.options( call ) );
		}
		if ( options.method === 'POST' && route.endsWith( '/preview' ) ) {
			return Promise.resolve( server.preview( call ) );
		}
		if ( options.method === 'POST' ) {
			return Promise.resolve( server.apply( call ) );
		}

		return Promise.resolve( server.rows( call ) );
	} );
}

function posts(): Call[] {
	return fetchMock.mock.calls
		.map( ( [ options ] ) => ( { ...options, path: decodeURIComponent( options.path ) } ) as Call )
		.filter( ( call ) => call.method === 'POST' );
}

function applies(): Call[] {
	return posts().filter( ( call ) => ! call.path.endsWith( '/preview' ) );
}

function previews(): Call[] {
	return posts().filter( ( call ) => call.path.endsWith( '/preview' ) );
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
					<tr key={ props.rowKey( row, index ) } data-key={ props.rowKey( row, index ) }>
						{ row.map( ( cell, column ) => (
							<td key={ column }>{ cell.display }</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function Page( { query = {}, activity }: { query?: Record< string, string >; activity?: ActivityExtensions } ) {
	const actions = useActionExtensions( activity );
	const extensions = activity ? { ...actions, ...activity.extensions } : actions;

	return (
		<RecordsTable
			owner="shop"
			table="items"
			title="Товары"
			query={ query }
			navigate={ jest.fn() }
			extensions={ extensions }
		/>
	);
}

async function renderPage( strict = false ) {
	const view = render( strict ? <StrictMode><Page /></StrictMode> : <Page /> );
	await screen.findByRole( 'table', { name: 'Товары' } );

	return view;
}

function row( key: string ): HTMLElement {
	const found = document.querySelector( `tr[data-key="${ key }"]` );
	if ( ! found ) {
		throw new Error( `No row ${ key }` );
	}

	return found as HTMLElement;
}

/** Opens a row action's dialog and returns it. */
async function openRowAction( key: string, name: string ): Promise< HTMLElement > {
	fireEvent.click( within( row( key ) ).getByRole( 'button', { name } ) );

	return screen.findByRole( 'dialog' );
}

function toolbarButton( name: string ): HTMLElement {
	return within( screen.getByRole( 'toolbar' ) ).getByRole( 'button', { name } );
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	serve();
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'preview', () => {
	test( 'a row action opens a dialog and asks for the plan, changing nothing', async () => {
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );

		await within( dialog ).findByText( 'Будет выгружено 1 из 1.' );
		expect( previews() ).toHaveLength( 1 );
		expect( previews()[ 0 ].path ).toBe( `${ BASE }/actions/export/preview` );
		expect( previews()[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [ 'p1' ], payload: {} } );
		expect( applies() ).toHaveLength( 0 );
	} );

	test( 'the plan shows its counts and every reason the server wrote', async () => {
		serve( {
			preview: () =>
				plan( {
					requested: 5,
					eligible: 3,
					skipped: 2,
					warnings: [ 'Цены будут округлены' ],
					groups: [
						{
							kind: 'skipped',
							reason: 'Нет артикула',
							count: 2,
							fix_label: 'Заполнить артикул',
							fix_url: '/wp-admin/edit.php',
							examples: [ 'Чашка', 'Блюдце' ],
						},
					],
				} ),
		} );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );

		const counts = await within( dialog ).findByText( 'Будет обработано' );
		expect( counts.nextElementSibling ).toHaveTextContent( '3' );
		expect( within( dialog ).getByText( 'Нет артикула' ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Пропущено строк:' ) ).toBeInTheDocument();
		expect( within( dialog ).getByRole( 'link', { name: 'Заполнить артикул' } ) ).toHaveAttribute( 'href', '/wp-admin/edit.php' );
		expect( within( dialog ).getByText( /Чашка, Блюдце/ ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Цены будут округлены' ) ).toBeInTheDocument();
	} );

	test( 'an account-wide action states its scope before anything is sent', async () => {
		await renderPage();

		fireEvent.click( toolbarButton( 'Связать по артикулу' ) );
		const dialog = await screen.findByRole( 'dialog' );

		expect( within( dialog ).getByText( 'Будут обработаны все товары аккаунта.' ) ).toHaveAttribute( 'data-scope', 'account' );
		await waitFor( () => expect( previews() ).toHaveLength( 1 ) );
		expect( previews()[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [], payload: {} } );
	} );

	test( 'the scope sentence of the plan replaces the declared one', async () => {
		serve( { preview: () => plan( { scope: { type: 'account', sentence: 'Затронет 340 товаров аккаунта.' } } ) } );
		await renderPage();

		fireEvent.click( toolbarButton( 'Связать по артикулу' ) );
		const dialog = await screen.findByRole( 'dialog' );

		await within( dialog ).findByText( 'Затронет 340 товаров аккаунта.' );
		expect( within( dialog ).queryByText( 'Будут обработаны все товары аккаунта.' ) ).not.toBeInTheDocument();
	} );

	test( 'a plan with nothing eligible cannot be confirmed', async () => {
		serve( { preview: () => plan( { eligible: 0, skipped: 1, can_apply: false } ) } );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );

		const confirm = await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } );
		expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( confirm );
		expect( applies() ).toHaveLength( 0 );
	} );

	test( 'closing while the plan is being read drops the late answer', async () => {
		const pending = defer< PreviewResponse >();
		serve( { preview: () => pending.promise } );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		const signal = previews()[ 0 ].signal as AbortSignal;
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Отмена' } ) );
		await act( async () => pending.resolve( plan() ) );

		expect( signal.aborted ).toBe( true );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	test( 'a failed plan can be read again; it changed nothing', async () => {
		let attempt = 0;
		serve( {
			preview: () => {
				attempt++;
				return attempt === 1 ? Promise.reject( { code: 'fetch_error', message: 'Failed to fetch' } ) : plan();
			},
		} );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Повторить расчёт' } ) );

		await within( dialog ).findByText( 'Будет выгружено 1 из 1.' );
		expect( previews() ).toHaveLength( 2 );
		expect( applies() ).toHaveLength( 0 );
	} );
} );

describe( 'apply', () => {
	test( 'confirming sends one apply with the explicit confirmation and shows the replanned counts', async () => {
		serve( {
			preview: () => plan( { requested: 1, eligible: 1 } ),
			apply: () => outcome( { requested: 1, accepted: 0, skipped: 1, status: 'failed', groups: [ { kind: 'skipped', reason: 'Уже отправлено', count: 1, fix_label: '', fix_url: '', examples: [] } ] } ),
		} );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Ничего не принято.' );
		expect( applies() ).toHaveLength( 1 );
		expect( applies()[ 0 ].path ).toBe( `${ BASE }/actions/export` );
		expect( applies()[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [ 'p1' ], payload: {}, confirmed: true } );
		expect( within( dialog ).getByText( 'Уже отправлено' ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Принято' ).nextElementSibling ).toHaveTextContent( '0' );
	} );

	test( 'a partial result keeps every reason, and the changed rows replace the old ones', async () => {
		serve( {
			apply: () =>
				outcome( {
					status: 'partial',
					requested: 3,
					accepted: 1,
					skipped: 1,
					failed: 1,
					rows: [ shopRow( 'p1', 'Кружка (обновлена)' ) ],
					removed_ids: [ 'p2' ],
					groups: [
						{ kind: 'skipped', reason: 'Товар скрыт', count: 1, fix_label: '', fix_url: '', examples: [] },
						{ kind: 'failed', reason: 'Не удалось сохранить', count: 1, fix_label: '', fix_url: '', examples: [] },
					],
					messages: { summary: 'Принят 1 из 3.' },
				} ),
		} );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Выполнено частично.' );
		expect( within( dialog ).getByText( /Принят 1 из 3/ ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Товар скрыт' ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Не удалось сохранить' ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( 'Ошибка:' ) ).toBeInTheDocument();

		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Закрыть' } ) );
		await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument() );
		expect( within( row( 'p1' ) ).getByRole( 'link', { name: 'Кружка (обновлена)' } ) ).toBeInTheDocument();
		expect( document.querySelector( 'tr[data-key="p2"]' ) ).toBeNull();
	} );

	test( 'a result never shows the plan’s summary: eligibility changed between preview and apply', async () => {
		serve( {
			preview: () => plan( { requested: 4, eligible: 4, messages: { summary: 'Будет выгружено 4 из 4.' } } ),
			apply: () =>
				outcome( {
					status: 'failed',
					requested: 4,
					accepted: 0,
					skipped: 4,
					groups: [ { kind: 'skipped', reason: 'Уже отправлено', count: 4, fix_label: '', fix_url: '', examples: [] } ],
				} ),
		} );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		await within( dialog ).findByText( 'Будет выгружено 4 из 4.' );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Ничего не принято.' );
		expect( within( dialog ).queryByText( /Будет выгружено/ ) ).not.toBeInTheDocument();
		expect( within( dialog ).getByText( 'Уже отправлено' ) ).toBeInTheDocument();
	} );

	test( 'an answer that arrives after the account changed is not applied to the other account’s rows', async () => {
		const pending = defer< ApplyResponse >();
		const forAccount = ( path: string ) => ( path.includes( 'account_id]=a2' ) ? 'a2' : 'a1' );
		serve( {
			definition: ( { path } ) => fixtureDefinitionEnvelope( definition(), forAccount( path ) ),
			rows: ( { path } ) =>
				fixtureList( [ shopRow( 'p1', forAccount( path ) === 'a2' ? 'Товар аккаунта B' : 'Товар аккаунта A' ) ] ),
			apply: () => pending.promise,
		} );
		const view = await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );
		await within( dialog ).findByText( 'Выполняем…' );

		view.rerender( <Page query={ { account: 'a2' } } /> );
		await waitFor( () => expect( screen.getByText( 'Товар аккаунта B' ) ).toBeInTheDocument() );
		await act( async () => pending.resolve( outcome( { rows: [ shopRow( 'p1', 'Результат аккаунта A' ) ] } ) ) );

		expect( screen.queryByText( 'Результат аккаунта A' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Товар аккаунта B' ) ).toBeInTheDocument();
		expect( applies() ).toHaveLength( 1 );
	} );

	test( 'a repeated click sends one apply, and the dialog cannot be dismissed meanwhile', async () => {
		const pending = defer< ApplyResponse >();
		serve( { apply: () => pending.promise } );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		const confirm = await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } );
		fireEvent.click( confirm );
		fireEvent.click( confirm );
		fireEvent.click( confirm );

		await within( dialog ).findByText( 'Выполняем…' );
		expect( applies() ).toHaveLength( 1 );
		expect( within( dialog ).getByRole( 'button', { name: 'Выгрузить товар' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( within( dialog ).getByRole( 'button', { name: 'Отмена' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.keyDown( dialog, { key: 'Escape', code: 'Escape' } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Отмена' } ) );
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();

		await act( async () => pending.resolve( outcome() ) );
		await within( dialog ).findByText( 'Готово.' );
		expect( applies() ).toHaveLength( 1 );
	} );

	test( 'a remount of the tree (strict mode) does not send the apply twice', async () => {
		await renderPage( true );

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Готово.' );
		expect( applies() ).toHaveLength( 1 );
		expect( previews() ).toHaveLength( 1 );
	} );

	test( 'a row action that skips the preview applies on the click', async () => {
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Отправить сразу' );

		await within( dialog ).findByText( 'Готово.' );
		expect( previews() ).toHaveLength( 0 );
		expect( applies() ).toHaveLength( 1 );
		expect( applies()[ 0 ].data ).toMatchObject( { ids: [ 'p1' ], confirmed: true } );
	} );

	test( 'a link action is a link to the row’s destination: no dialog, no request', async () => {
		await renderPage();

		const link = within( row( 'p1' ) ).getByRole( 'link', { name: 'Открыть' } );
		expect( link ).toHaveAttribute( 'href', '/wp-admin/post.php?post=p1&action=edit' );

		// The click is left to the browser (nothing prevented it); jsdom cannot navigate, so stop it here.
		let leftToTheBrowser = false;
		const stop = ( event: Event ) => {
			leftToTheBrowser = ! event.defaultPrevented;
			event.preventDefault();
		};
		document.addEventListener( 'click', stop );
		fireEvent.click( link );
		document.removeEventListener( 'click', stop );

		expect( leftToTheBrowser ).toBe( true );

		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( posts() ).toHaveLength( 0 );
	} );

	test( 'a link action without a destination is not drawn as a dead button', async () => {
		serve( { rows: () => fixtureList( [ shopRow( 'p1', 'Кружка', null ) ] ) } );
		await renderPage();

		expect( within( row( 'p1' ) ).queryByRole( 'link', { name: 'Открыть' } ) ).not.toBeInTheDocument();
		expect( within( row( 'p1' ) ).queryByRole( 'button', { name: 'Открыть' } ) ).not.toBeInTheDocument();
		expect( within( row( 'p1' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).toBeInTheDocument();
	} );

	test( 'queued work reads the table again', async () => {
		serve( { apply: () => outcome( { status: 'queued' } ) } );
		await renderPage();
		const before = listReads();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Поставлено в очередь.' );
		await waitFor( () => expect( listReads() ).toBe( before + 1 ) );
	} );
} );

describe( 'refusals and lost answers', () => {
	test( 'a state refusal shows the server reason and offers no second try', async () => {
		serve( { apply: () => Promise.reject( { code: 'woodev_records_action_conflict', message: 'Идёт фоновая работа по аккаунту.', data: { status: 409 } } ) } );
		await renderPage();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Идёт фоновая работа по аккаунту.' );
		expect( within( dialog ).queryByRole( 'button', { name: 'Выгрузить товар' } ) ).not.toBeInTheDocument();
		expect( within( dialog ).getByRole( 'button', { name: 'Закрыть' } ) ).toBeInTheDocument();
		expect( applies() ).toHaveLength( 1 );
	} );

	test( 'no answer to an apply: PHP’s recovery sentence, the table is read again, nothing is resent', async () => {
		serve( { apply: () => Promise.reject( { code: 'fetch_error', message: 'Failed to fetch' } ) } );
		await renderPage();
		const before = listReads();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( ACTION_COPY.action_recovery );
		expect( within( dialog ).queryByText( 'Failed to fetch' ) ).not.toBeInTheDocument();
		await waitFor( () => expect( listReads() ).toBe( before + 1 ) );
		expect( applies() ).toHaveLength( 1 );
		expect( within( dialog ).queryByRole( 'button', { name: 'Выгрузить товар' } ) ).not.toBeInTheDocument();
	} );

	test( 'a server error on apply shows its sentence and reads the table again', async () => {
		serve( { apply: () => Promise.reject( { code: 'woodev_records_provider_error', message: 'Внутренняя ошибка сервера. Попробуйте ещё раз.', data: { status: 500 } } ) } );
		await renderPage();
		const before = listReads();

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( 'Внутренняя ошибка сервера. Попробуйте ещё раз.' );
		await waitFor( () => expect( listReads() ).toBe( before + 1 ) );
	} );
} );

describe( 'input fields', () => {
	const refused = {
		code: 'woodev_records_invalid_fields',
		message: 'Проверьте заполненные поля.',
		data: { status: 422, errors: { warehouse: 'Заполните это поле.' } },
	};

	test( 'fields are drawn from the schema, with source options read from the server', async () => {
		await renderPage();

		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) ); // Disabled: nothing is selected.
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) );
		const dialog = await screen.findByRole( 'dialog' );

		const select = await within( dialog ).findByRole( 'combobox', { name: 'Склад' } );
		await within( dialog ).findByRole( 'option', { name: 'Москва' } );
		expect( select ).toBeEnabled();
		expect( within( dialog ).getByRole( 'textbox', { name: 'Заметка' } ) ).toBeInTheDocument();
		expect( fetchMock.mock.calls.map( ( [ options ] ) => decodeURIComponent( options.path ) ) ).toContain(
			`${ BASE }/actions/bulk_export/fields/warehouse/options?context[account_id]=a1`
		);
		expect( previews() ).toHaveLength( 0 );
	} );

	test( 'a refused field shows the server sentence beside it and keeps the form', async () => {
		serve( { preview: () => Promise.reject( refused ) } );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) );
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Показать, что изменится' } ) );

		const alert = await within( dialog ).findByText( 'Заполните это поле.' );
		expect( alert ).toHaveAttribute( 'role', 'alert' );
		expect( within( dialog ).getByRole( 'combobox', { name: 'Склад' } ) ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( previews() ).toHaveLength( 1 );
		expect( applies() ).toHaveLength( 0 );
	} );

	test( 'the typed values are sent; empty optional text is left out; a changed input asks for a new plan', async () => {
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) );
		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByRole( 'option', { name: 'Москва' } );
		fireEvent.change( within( dialog ).getByRole( 'combobox', { name: 'Склад' } ), { target: { value: 'w1' } } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Показать, что изменится' } ) );

		await within( dialog ).findByText( 'Будет выгружено 1 из 1.' );
		expect( previews()[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [ 'p1' ], payload: { warehouse: 'w1' } } );

		fireEvent.change( within( dialog ).getByRole( 'textbox', { name: 'Заметка' } ), { target: { value: 'срочно' } } );

		expect( within( dialog ).queryByText( 'Будет выгружено 1 из 1.' ) ).not.toBeInTheDocument();
		expect( within( dialog ).queryByRole( 'button', { name: 'Выгрузить всё' } ) ).not.toBeInTheDocument();
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Показать, что изменится' } ) );
		await within( dialog ).findByRole( 'button', { name: 'Выгрузить всё' } );
		expect( previews()[ 1 ].data ).toMatchObject( { payload: { warehouse: 'w1', note: 'срочно' } } );
	} );

	test( 'more options are read page by page', async () => {
		serve( {
			options: ( { path } ) =>
				path.includes( 'cursor=c2' )
					? { options: [ { value: 'w2', label: 'Казань' } ], next_cursor: null }
					: { options: [ { value: 'w1', label: 'Москва' } ], next_cursor: 'c2' },
		} );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) );
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Ещё склады' } ) );

		await within( dialog ).findByRole( 'option', { name: 'Казань' } );
		expect( within( dialog ).getByRole( 'option', { name: 'Москва' } ) ).toBeInTheDocument();
		expect( within( dialog ).queryByRole( 'button', { name: 'Ещё склады' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'bulk actions', () => {
	test( 'a selection action needs a selection; an account-wide one does not', async () => {
		await renderPage();

		expect( toolbarButton( 'Выгрузить выбранные' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( toolbarButton( 'Связать по артикулу' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		expect( toolbarButton( 'Выгрузить выбранные' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'the selection is cleared once the apply came back', async () => {
		serve( { preview: () => plan( { requested: 2, eligible: 2 } ), apply: () => outcome( { requested: 2, accepted: 2 } ) } );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );
		fireEvent.click( toolbarButton( 'Выгрузить выбранные' ) );
		const dialog = await screen.findByRole( 'dialog' );
		await within( dialog ).findByRole( 'option', { name: 'Москва' } );
		fireEvent.change( within( dialog ).getByRole( 'combobox', { name: 'Склад' } ), { target: { value: 'w1' } } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Показать, что изменится' } ) );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить всё' } ) );

		await within( dialog ).findByText( 'Готово.' );
		expect( applies()[ 0 ].data ).toMatchObject( { ids: [ 'p1', 'p2' ], confirmed: true, payload: { warehouse: 'w1' } } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Закрыть' } ) );
		await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument() );
		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).not.toBeChecked();
	} );

	test( 'an account-wide apply sends no ids and reads the table again', async () => {
		await renderPage();
		const before = listReads();

		fireEvent.click( toolbarButton( 'Связать по артикулу' ) );
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Связать по артикулу' } ) );

		await within( dialog ).findByText( 'Готово.' );
		expect( applies()[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [], payload: {}, confirmed: true } );
		await waitFor( () => expect( listReads() ).toBe( before + 1 ) );
	} );
} );

describe( 'guarded downloads', () => {
	test( 'a read action applies on the click and offers the file on this site', async () => {
		serve( { apply: () => outcome( { download: { url: '/wp-admin/admin-post.php?label=1', filename: 'labels.pdf', origins: [] } } ) } );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Скачать этикетки' ) );
		const dialog = await screen.findByRole( 'dialog' );

		const link = await within( dialog ).findByRole( 'link', { name: 'Скачать файл' } );
		expect( link ).toHaveAttribute( 'href', '/wp-admin/admin-post.php?label=1' );
		expect( link ).toHaveAttribute( 'download', 'labels.pdf' );
		expect( previews() ).toHaveLength( 0 );
	} );

	test( 'with the admin and the front end on different hosts, the server’s origins are trusted', async () => {
		serve( { apply: () => outcome( { download: { url: 'https://shop.example/labels.pdf', filename: 'labels.pdf', origins: [ 'https://shop.example', window.location.origin ] } } ) } );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Скачать этикетки' ) );
		const dialog = await screen.findByRole( 'dialog' );

		expect( await within( dialog ).findByRole( 'link', { name: 'Скачать файл' } ) ).toHaveAttribute( 'href', 'https://shop.example/labels.pdf' );
	} );

	test.each( [
		[ 'another site', 'https://evil.example/labels.pdf' ],
		[ 'a script', 'javascript:alert(1)' ],
		[ 'a protocol-relative address', '//evil.example/labels.pdf' ],
	] )( 'an address on %s is never offered', async ( _name, url ) => {
		serve( { apply: () => outcome( { download: { url, filename: '', origins: [ 'https://shop.example' ] } } ) } );
		await renderPage();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( toolbarButton( 'Скачать этикетки' ) );
		const dialog = await screen.findByRole( 'dialog' );

		await within( dialog ).findByText( 'Адрес файла не прошёл проверку' );
		expect( within( dialog ).queryByRole( 'link', { name: 'Скачать файл' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'with the activity part', () => {
	const LOCK = {
		locked: true,
		reason: 'Идёт выгрузка этого товара',
		blocked_actions: [ 'export', 'bulk_export' ],
		processes: [],
		observed_at: '2026-11-11T10:00:00+03:00',
	};

	async function renderWithActivity( activity: ActivityExtensions ) {
		render( <Page activity={ activity } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );
	}

	test( 'the rows of an apply are claimed while it is in flight, so an earlier refresh cannot overwrite them', async () => {
		const activity = createActivityExtensions();
		const pending = defer< ApplyResponse >();
		serve( { apply: () => pending.promise } );
		await renderWithActivity( activity );
		const refreshStartedBefore = activity.ownership.snapshot( [ 'p1' ] );

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );
		await within( dialog ).findByText( 'Выполняем…' );

		expect( activity.ownership.isClaimed( 'p1' ) ).toBe( true );
		expect( activity.ownership.mayWrite( 'p1', refreshStartedBefore ) ).toBe( false );

		await act( async () => pending.resolve( outcome() ) );
		await within( dialog ).findByText( 'Готово.' );

		expect( activity.ownership.isClaimed( 'p1' ) ).toBe( false );
		expect( activity.ownership.mayWrite( 'p1', refreshStartedBefore ) ).toBe( false ); // The apply's write is newer.
		expect( activity.ownership.mayWrite( 'p1', activity.ownership.snapshot( [ 'p1' ] ) ) ).toBe( true );
	} );

	test( 'a failed apply releases its claim too', async () => {
		const activity = createActivityExtensions();
		serve( { apply: () => Promise.reject( { code: 'fetch_error', message: 'Failed to fetch' } ) } );
		await renderWithActivity( activity );

		const dialog = await openRowAction( 'p1', 'Выгрузить' );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Выгрузить товар' } ) );

		await within( dialog ).findByText( ACTION_COPY.action_recovery );
		expect( activity.ownership.isClaimed( 'p1' ) ).toBe( false );
	} );

	test( 'a row locked by activity does not offer the blocked row action or the blocked bulk action', async () => {
		const activity = createActivityExtensions();
		serve( { rows: () => fixtureList( [ { ...shopRow( 'p1', 'Кружка' ), activity: LOCK }, shopRow( 'p2', 'Футболка' ) ] ) } );
		await renderWithActivity( activity );

		const exportButton = within( row( 'p1' ) ).getByRole( 'button', { name: 'Выгрузить' } );
		expect( exportButton ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( exportButton );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( previews() ).toHaveLength( 0 );
		expect( within( row( 'p2' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );
		expect( toolbarButton( 'Выгрузить выбранные' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		expect( toolbarButton( 'Выгрузить выбранные' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( toolbarButton( 'Выгрузить выбранные' ) ).toHaveAccessibleDescription( 'Идёт выгрузка этого товара' );
	} );

	test( 'an account action the account line blocks is off, with the line’s sentence beside it', async () => {
		const activity = createActivityExtensions();
		await renderWithActivity( activity );
		expect( toolbarButton( 'Связать по артикулу' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );

		act( () =>
			activity.state.set( {
				account: {
					active: true,
					text: 'По аккаунту идёт фоновая работа',
					blocked_actions: [ 'link_all' ],
					observed_at: '2026-11-11T10:00:00+03:00',
				},
			} )
		);

		expect( toolbarButton( 'Связать по артикулу' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( toolbarButton( 'Связать по артикулу' ) ).toHaveAccessibleDescription( 'По аккаунту идёт фоновая работа' );
		expect( document.querySelectorAll( '.woodev-records__reason' ) ).toHaveLength( 1 ); // Beside the action it blocks only.
	} );
} );
