/**
 * The activity part of the records library: locks, blocked actions, processes, and the bounded refresh loop.
 *
 * The loop is driven with jest's fake timers; `TableCard` is a double that renders exactly what it is given.
 *
 * @see src/admin-records/activity/
 */

import '@testing-library/jest-dom';
import { act, render, screen, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, type RecordsExtensions, type RecordsTableProps } from '../../src/admin-records';
import {
	createActivityExtensions,
	createOwnership,
	type AccountActivity,
	type ActivityExtensions,
	type ActivityProcess,
	type ActivityResponse,
	type RowActivity,
} from '../../src/admin-records/activity';
import { chunk, delayAfterFailure, delayAfterSuccess } from '../../src/admin-records/activity/schedule';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { AdminRecord, Definition, ListEnvelope } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const BASE = '/woodev/v1/admin/records/shop/items';
const LIMITS = { refresh_ms: 2000, max_refresh_ms: 16000, stale_after_ms: 7000, id_limit: 2 };
const MESSAGES = {
	activity_stale: 'Данные о ходе работы устарели. Пока они не обновятся, действия остаются недоступными.',
	activity_failed: 'Не удалось обновить ход работы. Пробуем ещё раз.',
};

function FakeTableCard( props: TableCardProps ) {
	return (
		<table aria-label={ props.title }>
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

function idle(): RowActivity {
	return { locked: false, reason: '', blocked_actions: [], processes: [], observed_at: '2026-11-11T10:00:00Z' };
}

function process( id: string, overrides: Partial< ActivityProcess > = {} ): ActivityProcess {
	return {
		id,
		state: 'running',
		label: 'Отправка остатков',
		completed: 2,
		total: 8,
		percent: 25,
		text: '2 из 8',
		...overrides,
	};
}

function locked( processes: ActivityProcess[] = [] ): RowActivity {
	return {
		...idle(),
		locked: true,
		reason: 'Товар уже отправляется на маркетплейс.',
		blocked_actions: [ 'export' ],
		processes,
	};
}

function account( overrides: Partial< AccountActivity > = {} ): AccountActivity {
	return { active: false, text: '', blocked_actions: [], observed_at: '2026-11-11T10:00:00Z', ...overrides };
}

function row( id: string, name: string, activity: RowActivity = idle() ): AdminRecord {
	return fixtureRow( id, name, { activity } );
}

function answer( rows: AdminRecord[], overrides: Partial< ActivityResponse > = {} ): ActivityResponse {
	return {
		rows,
		removed_ids: [],
		account_activity: account(),
		observed_at: '2026-11-11T10:00:05Z',
		refresh_after_ms: 2000,
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

type Read = ( options: { path: string; method?: string; data?: unknown; signal?: AbortSignal } ) => unknown;

let reads: Array< { path: string; data: { context: { account_id: string }; ids: string[] }; signal?: AbortSignal } >;
let onActivity: Read;

interface Setup {
	rows?: AdminRecord[];
	list?: Partial< ListEnvelope >;
	definition?: Partial< Definition >;
	activity?: Read;
}

function serve( { rows, list, definition, activity }: Setup = {} ): void {
	const items = rows ?? [ row( 'p1', 'Кружка' ), row( 'p2', 'Футболка' ) ];
	onActivity = activity ?? ( () => answer( [] ) );

	fetchMock.mockImplementation( ( options: { path: string; method?: string; data?: unknown; signal?: AbortSignal } ) => {
		const path = decodeURIComponent( options.path );
		if ( path === `${ BASE }/activity` ) {
			reads.push( { path, data: options.data as never, signal: options.signal } );
			return Promise.resolve( onActivity( options ) );
		}
		if ( path.endsWith( '/definition' ) ) {
			return Promise.resolve(
				fixtureDefinitionEnvelope(
					fixtureDefinition( {
						activity: LIMITS,
						messages: { ...fixtureDefinition().messages, ...MESSAGES },
						...definition,
					} )
				)
			);
		}

		return Promise.resolve( fixtureList( items, { account_activity: account(), ...list } ) );
	} );
}

let activity: ActivityExtensions;

function tableProps( overrides: Partial< RecordsTableProps > = {} ): RecordsTableProps {
	const extensions: RecordsExtensions = { ...activity.extensions, onRowAction: jest.fn() };

	return { owner: 'shop', table: 'items', title: 'Товары', query: {}, navigate: jest.fn(), extensions, ...overrides };
}

async function flush(): Promise< void > {
	await act( async () => {
		await Promise.resolve();
	} );
}

async function renderTable( overrides: Partial< RecordsTableProps > = {} ) {
	const view = render( <RecordsTable { ...tableProps( overrides ) } /> );

	await screen.findByRole( 'table', { name: 'Товары' } );
	await flush();

	return view;
}

/** Moves the clock and lets every promise the timers started settle. */
async function wait( ms: number ): Promise< void > {
	await act( async () => {
		await jest.advanceTimersByTimeAsync( ms );
	} );
}

function tableRow( key: string ): HTMLElement {
	const found = document.querySelector( `tr[data-key="${ key }"]` );
	if ( ! found ) {
		throw new Error( `No row with the key ${ key }` );
	}

	return found as HTMLElement;
}

/** A row's own activity block (the fixture's other cells have a progress bar of their own). */
function activityOf( key: string ): HTMLElement {
	const found = tableRow( key ).querySelector( '.woodev-records__activity' );
	if ( ! found ) {
		throw new Error( `Row ${ key } shows no activity` );
	}

	return found as HTMLElement;
}

function exportButton( key: string ): HTMLElement {
	return within( tableRow( key ) ).getByRole( 'button', { name: 'Выгрузить' } );
}

function setVisibility( state: 'visible' | 'hidden' ): void {
	Object.defineProperty( document, 'visibilityState', { configurable: true, get: () => state } );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
}

beforeEach( () => {
	jest.useFakeTimers();
	fetchMock.mockReset();
	reads = [];
	activity = createActivityExtensions();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	setVisibility( 'visible' );
	serve();
} );

afterEach( () => {
	jest.useRealTimers();
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'locks and blocked actions', () => {
	test( 'a locked row says why in visible text and disables only the blocked action', async () => {
		serve( { rows: [ row( 'p1', 'Кружка', locked() ), row( 'p2', 'Футболка' ) ] } );
		await renderTable();

		const lockedRow = within( tableRow( 'p1' ) );

		expect( lockedRow.getByText( 'Товар уже отправляется на маркетплейс.' ) ).toBeVisible();
		expect( exportButton( 'p1' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( exportButton( 'p1' ) ).toHaveAccessibleDescription( 'Товар уже отправляется на маркетплейс.' );
		expect( exportButton( 'p2' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'read links stay available on a locked row', async () => {
		serve( { rows: [ row( 'p1', 'Кружка', locked( [ process( 'obs-1' ) ] ) ) ] } );
		await renderTable();

		expect( within( tableRow( 'p1' ) ).getByRole( 'link', { name: 'Кружка' } ) ).toHaveAttribute(
			'href',
			'/wp-admin/post.php?post=p1&action=edit'
		);
	} );

	test( 'an action the row does not block stays enabled even while the row is busy', async () => {
		const busy = { ...idle(), processes: [ process( 'obs-1' ) ] };
		serve( { rows: [ row( 'p1', 'Кружка', busy ) ] } );
		await renderTable();

		expect( exportButton( 'p1' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );

describe( 'processes', () => {
	test( 'several processes coexist, each with its own progress', async () => {
		serve( {
			rows: [
				row(
					'p1',
					'Кружка',
					locked( [
						process( 'obs-stock', { label: 'Остатки', completed: 1, total: 2, percent: 50, text: '1 из 2' } ),
						process( 'obs-price', { label: 'Цены', state: 'queued', completed: 0, total: 4, percent: 0, text: 'В очереди' } ),
					] )
				),
			],
		} );
		await renderTable();

		const items = within( activityOf( 'p1' ) ).getAllByRole( 'listitem' );

		expect( items ).toHaveLength( 2 );
		expect( items[ 0 ] ).toHaveTextContent( 'Остатки' );
		expect( items[ 0 ] ).toHaveTextContent( '1 из 2' );
		expect( items[ 1 ] ).toHaveTextContent( 'Цены' );
		expect( items[ 1 ] ).toHaveTextContent( 'В очереди' );
		expect( within( items[ 0 ] ).getByRole( 'progressbar' ) ).toHaveAttribute( 'value', '1' );
		expect( within( items[ 1 ] ).getByRole( 'progressbar' ) ).toHaveAttribute( 'value', '0' );
	} );

	test( 'a process with no measurable total is an indeterminate indicator with no percentage', async () => {
		const unknown = process( 'obs-1', { completed: null, total: null, percent: null, text: 'Идёт обработка' } );
		serve( { rows: [ row( 'p1', 'Кружка', { ...idle(), processes: [ unknown ] } ) ] } );
		await renderTable();

		const bar = within( activityOf( 'p1' ) ).getByRole( 'progressbar' );

		expect( bar ).not.toHaveAttribute( 'value' );
		expect( bar ).not.toHaveAttribute( 'max' );
		expect( activityOf( 'p1' ).textContent ).not.toMatch( /\d+\s?%/ );
		expect( activityOf( 'p1' ) ).toHaveTextContent( 'Идёт обработка' );

		// Time passing never invents a percentage.
		await wait( 60_000 );
		expect( activityOf( 'p1' ).textContent ).not.toMatch( /\d+\s?%/ );
		expect( within( activityOf( 'p1' ) ).getByRole( 'progressbar' ) ).not.toHaveAttribute( 'value' );
	} );

	test( 'a finished process draws no indicator', async () => {
		const done = process( 'obs-1', { state: 'completed', completed: 8, total: 8, percent: 100, text: 'Готово' } );
		serve( { rows: [ row( 'p1', 'Кружка', { ...idle(), processes: [ done ] } ) ] } );
		await renderTable();

		expect( within( activityOf( 'p1' ) ).queryByRole( 'progressbar' ) ).toBeNull();
		expect( activityOf( 'p1' ) ).toHaveTextContent( 'Готово' );
	} );
} );

describe( 'work started somewhere else', () => {
	test( 'is on screen from the first list read, before any refresh', async () => {
		serve( {
			rows: [ row( 'p1', 'Кружка', locked( [ process( 'cron-1', { label: 'Фоновая выгрузка' } ) ] ) ) ],
			list: { account_activity: account( { active: true, text: 'По аккаунту идёт фоновая работа.' } ) },
		} );
		await renderTable();

		expect( reads ).toHaveLength( 0 );
		expect( tableRow( 'p1' ) ).toHaveTextContent( 'Фоновая выгрузка' );
		expect( screen.getByText( 'По аккаунту идёт фоновая работа.' ) ).toBeVisible();
	} );

	test( 'a refresh picks up work another manager started and work that finished', async () => {
		serve( {
			rows: [ row( 'p1', 'Кружка', locked( [ process( 'obs-1' ) ] ) ), row( 'p2', 'Футболка' ) ],
			activity: () =>
				answer(
					[
						row( 'p1', 'Кружка' ),
						row( 'p2', 'Футболка', locked( [ process( 'cron-9', { label: 'Выгрузка по расписанию' } ) ] ) ),
					],
					{ account_activity: account( { active: true, text: 'Идёт фоновая работа.', blocked_actions: [ 'bulk_export' ] } ) }
				),
		} );
		await renderTable();

		await wait( LIMITS.refresh_ms );

		expect( within( tableRow( 'p1' ) ).queryByText( 'Товар уже отправляется на маркетплейс.' ) ).toBeNull();
		expect( exportButton( 'p1' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( tableRow( 'p2' ) ).toHaveTextContent( 'Выгрузка по расписанию' );
		expect( exportButton( 'p2' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByText( 'Идёт фоновая работа.' ) ).toBeVisible();
		expect( activity.state.get().account?.blocked_actions ).toEqual( [ 'bulk_export' ] );
	} );
} );

describe( 'the refresh loop', () => {
	test( 'asks for the ids on screen, in the resolved context, at the PHP cadence', async () => {
		await renderTable();

		await wait( LIMITS.refresh_ms - 1 );
		expect( reads ).toHaveLength( 0 );

		await wait( 1 );
		expect( reads ).toHaveLength( 1 );
		expect( reads[ 0 ].data ).toEqual( { context: { account_id: 'a1' }, ids: [ 'p1', 'p2' ] } );

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 2 );
	} );

	test( 'one loop per table: a re-render does not start a second one', async () => {
		const view = await renderTable();

		view.rerender( <RecordsTable { ...tableProps() } /> );
		view.rerender( <RecordsTable { ...tableProps() } /> );
		await wait( LIMITS.refresh_ms * 3 );

		expect( reads ).toHaveLength( 3 );
	} );

	test( 'the server hint sets the next wait, kept between the cadence and the ceiling', async () => {
		let hint = 5000;
		serve( { activity: () => answer( [], { refresh_after_ms: hint } ) } );
		await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 1 );

		await wait( 4999 );
		expect( reads ).toHaveLength( 1 );
		await wait( 1 );
		expect( reads ).toHaveLength( 2 );

		hint = 1;
		await wait( 5000 );
		expect( reads ).toHaveLength( 3 );
		await wait( LIMITS.refresh_ms - 1 );
		expect( reads ).toHaveLength( 3 );
		await wait( 1 );
		expect( reads ).toHaveLength( 4 );

		hint = 600_000;
		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 5 );
		await wait( LIMITS.max_refresh_ms - 1 );
		expect( reads ).toHaveLength( 5 );
		await wait( 1 );
		expect( reads ).toHaveLength( 6 );
	} );

	test( 'a table that declares no activity never polls', async () => {
		serve( { definition: { activity: null } } );
		await renderTable();

		await wait( 600_000 );

		expect( reads ).toHaveLength( 0 );
	} );

	test( 'more ids than the limit are read in parts, one after the other', async () => {
		serve( { rows: [ row( 'p1', 'A' ), row( 'p2', 'B' ), row( 'p3', 'C' ) ] } );
		await renderTable();

		await wait( LIMITS.refresh_ms );

		expect( reads.map( ( read ) => read.data.ids ) ).toEqual( [ [ 'p1', 'p2' ], [ 'p3' ] ] );
	} );

	test( 'an empty table still reads once for its account line', async () => {
		serve( {
			rows: [],
			list: { account_activity: account() },
			activity: () => answer( [], { account_activity: account( { active: true, text: 'Идёт фоновая работа.' } ) } ),
		} );
		render( <RecordsTable { ...tableProps() } /> );
		await screen.findByText( 'Товаров пока нет' );
		await flush();

		await wait( LIMITS.refresh_ms );

		expect( reads.map( ( read ) => read.data.ids ) ).toEqual( [ [] ] );
		expect( await screen.findByText( 'Идёт фоновая работа.' ) ).toBeVisible();
	} );

	test( 'rows the server says are gone are removed', async () => {
		serve( { activity: () => answer( [], { removed_ids: [ 'p2' ] } ) } );
		await renderTable();

		await wait( LIMITS.refresh_ms );

		expect( document.querySelector( 'tr[data-key="p2"]' ) ).toBeNull();
		expect( tableRow( 'p1' ) ).toBeInTheDocument();
	} );

	test( 'unmounting stops the loop and aborts the read in flight', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		const view = await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 1 );

		view.unmount();
		expect( reads[ 0 ].signal?.aborted ).toBe( true );

		await wait( 600_000 );
		expect( reads ).toHaveLength( 1 );
	} );
} );

describe( 'back-off and stale state', () => {
	function failing(): void {
		serve( { activity: () => Promise.reject( new Error( 'down' ) ) } );
	}

	test( 'a failed read backs off, doubling up to the ceiling, and says so in PHP copy', async () => {
		failing();
		await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 1 );
		expect( screen.getByText( MESSAGES.activity_failed ) ).toBeVisible();

		// After the first failure: cadence × 2, then × 4, then × 8 — which is the ceiling.
		expect( delayAfterFailure( LIMITS, 1 ) ).toBe( 4000 );
		await wait( 3999 );
		expect( reads ).toHaveLength( 1 );
		await wait( 1 );
		expect( reads ).toHaveLength( 2 );

		await wait( 8000 );
		expect( reads ).toHaveLength( 3 );

		await wait( 16_000 );
		expect( reads ).toHaveLength( 4 );

		await wait( 16_000 );
		expect( reads ).toHaveLength( 5 );
	} );

	test( 'a good read ends the back-off and the notice', async () => {
		let ok = false;
		serve( { activity: () => ( ok ? answer( [] ) : Promise.reject( new Error( 'down' ) ) ) } );
		await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( screen.getByText( MESSAGES.activity_failed ) ).toBeVisible();

		ok = true;
		await wait( 4000 );

		expect( screen.queryByText( MESSAGES.activity_failed ) ).toBeNull();
		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 3 );
	} );

	test( 'after stale_after_ms without a good read the table is marked stale and blocked controls stay disabled', async () => {
		serve( {
			rows: [ row( 'p1', 'Кружка', locked( [ process( 'obs-1' ) ] ) ) ],
			activity: () => Promise.reject( new Error( 'down' ) ),
		} );
		await renderTable();

		await wait( LIMITS.stale_after_ms );

		expect( screen.getByText( MESSAGES.activity_stale ) ).toBeVisible();
		expect( screen.queryByText( MESSAGES.activity_failed ) ).toBeNull();
		expect( exportButton( 'p1' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( tableRow( 'p1' ) ).toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );
	} );

	test( 'a good read clears stale and keeps the clock running', async () => {
		let ok = false;
		serve( { activity: () => ( ok ? answer( [] ) : Promise.reject( new Error( 'down' ) ) ) } );
		await renderTable();

		await wait( LIMITS.stale_after_ms );
		expect( screen.getByText( MESSAGES.activity_stale ) ).toBeVisible();

		ok = true;
		await wait( 16_000 );
		expect( screen.queryByText( MESSAGES.activity_stale ) ).toBeNull();

		ok = false;
		await wait( LIMITS.stale_after_ms + LIMITS.max_refresh_ms );
		expect( screen.getByText( MESSAGES.activity_stale ) ).toBeVisible();
	} );

	test( 'staleness never enables anything: an idle row stays enabled and a locked one stays locked', async () => {
		serve( {
			rows: [ row( 'p1', 'Кружка', locked() ), row( 'p2', 'Футболка' ) ],
			activity: () => Promise.reject( new Error( 'down' ) ),
		} );
		await renderTable();

		await wait( 60_000 );

		expect( exportButton( 'p1' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( exportButton( 'p2' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );

describe( 'a hidden page', () => {
	test( 'stops reading while hidden and reads at once when shown again', async () => {
		await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 1 );

		setVisibility( 'hidden' );
		await wait( 600_000 );
		expect( reads ).toHaveLength( 1 );

		setVisibility( 'visible' );
		await wait( 0 );
		expect( reads ).toHaveLength( 2 );

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 3 );
	} );

	test( 'hiding the page aborts the read in flight and drops its answer', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		await renderTable();

		await wait( LIMITS.refresh_ms );
		setVisibility( 'hidden' );
		expect( reads[ 0 ].signal?.aborted ).toBe( true );

		await act( async () => {
			pending.resolve( answer( [ row( 'p1', 'Кружка', locked() ) ] ) );
			await Promise.resolve();
		} );

		expect( tableRow( 'p1' ) ).not.toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );
	} );

	test( 'a table that starts hidden does not read until shown', async () => {
		setVisibility( 'hidden' );
		await renderTable();

		await wait( 600_000 );
		expect( reads ).toHaveLength( 0 );

		setVisibility( 'visible' );
		await wait( 0 );
		expect( reads ).toHaveLength( 1 );
	} );
} );

describe( 'old answers never overwrite newer rows', () => {
	test( 'a new list read aborts the read in flight and drops its late answer', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		const view = await renderTable();

		await wait( LIMITS.refresh_ms );
		expect( reads ).toHaveLength( 1 );

		// Another page: a new list read, so a new epoch.
		view.rerender( <RecordsTable { ...tableProps( { query: { paged: '2' } } ) } /> );
		await flush();
		expect( reads[ 0 ].signal?.aborted ).toBe( true );

		await act( async () => {
			pending.resolve( answer( [ row( 'p1', 'Кружка', locked( [ process( 'late' ) ] ) ) ] ) );
			await Promise.resolve();
		} );

		expect( tableRow( 'p1' ) ).not.toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );
		expect( tableRow( 'p1' ) ).not.toHaveTextContent( 'Отправка остатков' );
	} );

	test( 'a row an operation owns is not overwritten, the others are', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		await renderTable();
		await wait( LIMITS.refresh_ms );

		// An action is writing p1 while the read is in flight.
		const token = activity.ownership.claim( [ 'p1' ] );
		await act( async () => {
			pending.resolve(
				answer( [ row( 'p1', 'Кружка', locked() ), row( 'p2', 'Футболка', locked( [ process( 'obs-2' ) ] ) ) ] )
			);
			await Promise.resolve();
		} );

		expect( tableRow( 'p1' ) ).not.toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );
		expect( tableRow( 'p2' ) ).toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );

		activity.ownership.release( [ 'p1' ], token );
	} );

	test( 'a row written after the read was sent is not overwritten even once released', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		await renderTable();
		await wait( LIMITS.refresh_ms );

		const token = activity.ownership.claim( [ 'p1' ] );
		activity.ownership.release( [ 'p1' ], token );
		await act( async () => {
			pending.resolve( answer( [ row( 'p1', 'Кружка', locked() ) ] ) );
			await Promise.resolve();
		} );

		expect( tableRow( 'p1' ) ).not.toHaveTextContent( 'Товар уже отправляется на маркетплейс.' );
	} );

	test( 'rows that left the screen while the read was in flight are not brought back', async () => {
		const pending = defer< ActivityResponse >();
		serve( { activity: () => pending.promise } );
		await renderTable();
		await wait( LIMITS.refresh_ms );

		await act( async () => {
			pending.resolve( answer( [ row( 'p9', 'Чужая', locked() ) ] ) );
			await Promise.resolve();
		} );

		expect( document.querySelector( 'tr[data-key="p9"]' ) ).toBeNull();
	} );
} );

describe( 'pure rules', () => {
	test( 'delays stay inside the declared bounds', () => {
		expect( delayAfterSuccess( LIMITS, 1 ) ).toBe( 2000 );
		expect( delayAfterSuccess( LIMITS, 5000 ) ).toBe( 5000 );
		expect( delayAfterSuccess( LIMITS, 10 ** 9 ) ).toBe( 16000 );
		expect( delayAfterSuccess( LIMITS, Number.NaN ) ).toBe( 2000 );
		expect( [ 1, 2, 3, 4, 50 ].map( ( failures ) => delayAfterFailure( LIMITS, failures ) ) ).toEqual( [
			4000, 8000, 16000, 16000, 16000,
		] );
	} );

	test( 'ids are split by the limit and an empty list is one empty read', () => {
		expect( chunk( [ 'a', 'b', 'c', 'd', 'e' ], 2 ) ).toEqual( [ [ 'a', 'b' ], [ 'c', 'd' ], [ 'e' ] ] );
		expect( chunk( [], 2 ) ).toEqual( [ [] ] );
		expect( chunk( [ 'a' ], 0 ) ).toEqual( [ [ 'a' ] ] );
	} );

	test( 'ownership: a claim blocks writes, a release leaves a newer version, a stranger cannot free it', () => {
		const ownership = createOwnership();
		const before = ownership.snapshot( [ 'a', 'b' ] );
		const first = ownership.claim( [ 'a' ] );
		const second = ownership.claim( [ 'a' ] );

		expect( ownership.mayWrite( 'a', before ) ).toBe( false );
		expect( ownership.mayWrite( 'b', before ) ).toBe( true );

		ownership.release( [ 'a' ], first );
		expect( ownership.isClaimed( 'a' ) ).toBe( true );

		ownership.release( [ 'a' ], second );
		expect( ownership.isClaimed( 'a' ) ).toBe( false );
		expect( ownership.mayWrite( 'a', before ) ).toBe( false );
		expect( ownership.mayWrite( 'a', ownership.snapshot( [ 'a' ] ) ) ).toBe( true );

		ownership.touch( [ 'b' ] );
		expect( ownership.mayWrite( 'b', before ) ).toBe( false );
	} );
} );
