/**
 * The records table's card chrome (#1776): the search and the bulk select in the card header, the account actions above
 * the filters, the counted view row, the footer count and the empty result in the frame of the table.
 *
 * `TableCard` is a double that draws what it is given — its `actions` header, its rows and its `summary` — and records
 * its props.
 *
 * @see src/admin-records/records-table.tsx
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions, type RecordsTableProps } from '../../src/admin-records';
import { fixtureDefinition, fixtureDefinitionEnvelope, fixtureList, fixtureRow } from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor, Definition, ListEnvelope } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';
import { accountButton } from './bulk-actions';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

let cardProps: TableCardProps[] = [];

function FakeTableCard( props: TableCardProps ) {
	cardProps.push( props );

	return (
		<div data-testid="card">
			<div data-testid="card-header">{ props.actions }</div>
			<table aria-label={ props.title }>
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
			<footer data-testid="card-summary">
				{ props.summary?.map( ( entry ) => `${ entry.value } ${ entry.label }` ).join( ', ' ) }
			</footer>
		</div>
	);
}

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
		fields: [],
		...overrides,
	};
}

/** The fixture table with a counted `state` row and one selection and one account action. */
function definition(): Definition {
	const base = fixtureDefinition();

	return {
		...base,
		filters: base.filters.map( ( filter ) => ( filter.id === 'state' ? { ...filter, views: true } : filter ) ),
		bulk_actions: [ action( 'send', 'Отправить' ), action( 'link', 'Связать по артикулу', { scope: 'account' } ) ],
	};
}

const COUNTS = [
	{ value: 'draft', label: 'Черновик', count: 8 },
	{ value: 'linked', label: 'Связан', count: 3 },
	{ value: 'gone', label: 'Удалён', count: 0 },
];

const mug = fixtureRow( 'p1', 'Кружка' );
const shirt = fixtureRow( 'p2', 'Футболка' );

function serve( list: ( path: string ) => ListEnvelope, def: Definition = definition() ): void {
	fetchMock.mockImplementation( ( { path }: { path: string } ) => {
		const decoded = decodeURIComponent( path );

		return Promise.resolve( decoded.split( '?' )[ 0 ].endsWith( '/definition' ) ? fixtureDefinitionEnvelope( def ) : list( decoded ) );
	} );
}

function Page( props: Partial< RecordsTableProps > ) {
	const extensions = useActionExtensions();

	return (
		<RecordsTable owner="shop" table="items" title="Товары" query={ {} } navigate={ jest.fn() } extensions={ extensions } { ...props } />
	);
}

beforeEach( () => {
	cardProps = [];
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'the card header', () => {
	test( 'holds the bulk select and the search, and the search is no longer in the filter row', async () => {
		serve( () => fixtureList( [ mug, shirt ] ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const header = within( screen.getByTestId( 'card-header' ) );

		expect( header.getByRole( 'combobox', { name: 'Массовые действия' } ) ).toBeInTheDocument();
		expect( header.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toBeInTheDocument();
		expect( document.querySelector( '.woodev-records__toolbar' )?.querySelector( '[role="search"]' ) ).toBeNull();
		expect( cardProps.at( -1 )?.hasSearch ).toBe( true );
	} );

	test( 'only the selection actions are in the select; an account action is a button in the card header, beside the select', async () => {
		serve( () => fixtureList( [ mug ] ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const select = screen.getByRole( 'combobox', { name: 'Массовые действия' } );
		const options = within( select ).getAllByRole( 'option' ).map( ( option ) => option.textContent );
		const accountRow = document.querySelector( '.woodev-records__account-actions' ) as HTMLElement;
		const header = screen.getByTestId( 'card-header' );

		expect( options ).toEqual( [ 'Выберите действие', 'Отправить' ] );
		expect( within( accountRow ).getByRole( 'button', { name: 'Связать по артикулу' } ) ).toBeInTheDocument();
		expect( header ).toContainElement( accountRow );
		expect( select.compareDocumentPosition( accountRow ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
	} );

	test( 'the select runs a selection action only for a selected row', async () => {
		serve( () => fixtureList( [ mug ] ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Массовые действия' } ), { target: { value: 'send' } } );
		expect( screen.getByRole( 'button', { name: 'Применить' } ) ).toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		expect( screen.getByRole( 'button', { name: 'Применить' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( accountButton( 'Связать по артикулу' ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'the footer says how many rows there are, in PHP\'s word', async () => {
		serve( () => fixtureList( [ mug, shirt ], { total: 42 } ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( screen.getByTestId( 'card-summary' ) ).toHaveTextContent( '42 Товаров' );
	} );
} );

describe( 'the counted view row', () => {
	function served(): void {
		serve( () => fixtureList( [ mug, shirt ], { total: 11, facets: { state: COUNTS } } ) );
	}

	test( 'is drawn from the facets of the `views` filter, which is no select any more', async () => {
		served();
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( screen.getByRole( 'link', { name: 'Все (11)' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Черновик (8)' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Связан (3)' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: /Удалён/ } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'combobox', { name: 'Состояние' } ) ).not.toBeInTheDocument();
	} );

	test( '«Все» shows the sum of the counts once a view is chosen, and writes what «Любое» wrote', async () => {
		served();
		const navigate = jest.fn();
		render( <Page query={ { filter_state: 'draft' } } navigate={ navigate } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( screen.getByRole( 'link', { name: 'Черновик (8)' } ) ).toHaveAttribute( 'aria-current', 'page' );

		fireEvent.click( screen.getByRole( 'link', { name: 'Все (11)' } ) );
		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_state: undefined, paged: undefined } ) );
	} );

	test( 'a view writes its option value and returns to page one', async () => {
		served();
		const navigate = jest.fn();
		render( <Page query={ { paged: '3' } } navigate={ navigate } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		fireEvent.click( screen.getByRole( 'link', { name: 'Связан (3)' } ) );

		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_state: 'linked', paged: undefined } ) );
	} );

	test( '«Все» is the entry PHP sends for «the filter left out», whatever view is chosen: the options may overlap', async () => {
		// One product sits in two states, so the counts add up to 11 for 10 products.
		const overlapping = [ { value: '', label: '', count: 10 }, ...COUNTS ];
		serve( () => fixtureList( [ mug ], { total: 8, facets: { state: overlapping } } ) );
		const { unmount } = render( <Page query={ { filter_state: 'draft' } } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( screen.getByRole( 'link', { name: 'Все (10)' } ) ).toBeInTheDocument();
		expect( within( screen.getByRole( 'list', { name: 'Состояние' } ) ).getAllByRole( 'link' ).map( ( link ) => link.textContent ) ).toEqual( [
			'Все (10)',
			'Черновик (8)',
			'Связан (3)',
		] );

		unmount();
	} );

	test( 'without counts from the server there is no row', async () => {
		serve( () => fixtureList( [ mug ], { facets: {} } ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( document.querySelector( '.woodev-table-views' ) ).toBeNull();
	} );
} );

describe( 'the period filter', () => {
	test( 'counts its named periods from the site\'s day that PHP sends, not the browser\'s', async () => {
		const base = definition();
		serve(
			() => fixtureList( [ mug ] ),
			{
				...base,
				filters: base.filters.map( ( filter ) => ( filter.id === 'updated' ? { ...filter, today: '2026-11-15' } : filter ) ),
			}
		);
		render( <Page query={ { filter_updated: '2026-11-09,2026-11-15' } } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		// 15.11.2026 is a Sunday: «С начала недели» is Monday the 9th to that day.
		expect( screen.getByRole( 'button', { name: 'Период: С начала недели' } ) ).toBeInTheDocument();
	} );

	test( 'keeps the site\'s offset from the browser\'s day while the days roll over in a tab left open', async () => {
		// Only the clock is faked: every timer stays real, so the async queries still resolve.
		jest.useFakeTimers( {
			now: new Date( 2026, 10, 14, 23, 0 ),
			doNotFake: [ 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'setImmediate', 'clearImmediate', 'nextTick', 'queueMicrotask' ],
		} );

		try {
			const base = definition();
			serve(
				() => fixtureList( [ mug ] ),
				{
					...base,
					// The site is a day ahead of the browser: it is 15.11 there while the browser still says 14.11.
					filters: base.filters.map( ( filter ) => ( filter.id === 'updated' ? { ...filter, today: '2026-11-15' } : filter ) ),
				}
			);
			const query = { filter_updated: '2026-11-09,2026-11-15' };
			const view = render( <Page query={ query } /> );
			await screen.findByRole( 'table', { name: 'Товары' } );

			expect( screen.getByRole( 'button', { name: 'Период: С начала недели' } ) ).toBeInTheDocument();

			// The browser's next day: the site's day moves with it, to Monday the 16th, so the week-to-date is that day alone.
			jest.setSystemTime( new Date( 2026, 10, 15, 1, 0 ) );
			view.rerender( <Page query={ { ...query } } /> );

			expect( screen.queryByRole( 'button', { name: 'Период: С начала недели' } ) ).not.toBeInTheDocument();
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'an empty result', () => {
	const none = ( path: string ) => ( path.includes( 'search=' ) ? fixtureList( [] ) : fixtureList( [ mug ] ) );

	test( 'is PHP\'s sentence inside a card whose header keeps the search with the typed text', async () => {
		serve( none );
		render( <Page query={ { search: 'нет такого' } } /> );

		expect( await screen.findByText( 'По этим условиям ничего не найдено' ) ).toBeInTheDocument();

		const card = document.querySelector( '.woodev-records__empty-card' ) as HTMLElement;

		expect( within( card ).getByRole( 'heading', { name: 'Товары' } ) ).toBeInTheDocument();
		expect( within( card ).getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveValue( 'нет такого' );
		expect( within( card ).getByRole( 'combobox', { name: 'Массовые действия' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	test( 'an empty table with no filter says the plain empty sentence', async () => {
		serve( () => fixtureList( [] ) );
		render( <Page /> );

		expect( await screen.findByText( 'Товаров пока нет' ) ).toBeInTheDocument();
	} );

	test( 'keyboard focus stays in the search box when the table swaps to the empty card and back', async () => {
		serve( none );
		const view = render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		act( () => screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ).focus() );
		expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveFocus();

		view.rerender( <Page query={ { search: 'нет' } } /> );
		await screen.findByText( 'По этим условиям ничего не найдено' );

		await waitFor( () => expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveFocus() );
		expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveValue( 'нет' );

		view.rerender( <Page query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		await waitFor( () => expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveFocus() );
	} );

	test( 'a box that did not have the focus does not take it', async () => {
		serve( none );
		const view = render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		view.rerender( <Page query={ { search: 'нет' } } /> );
		await screen.findByText( 'По этим условиям ничего не найдено' );

		expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).not.toHaveFocus();
	} );
} );

describe( 'the polish of s237', () => {
	const ZEROES = [
		{ value: 'draft', label: 'Черновик', count: 0 },
		{ value: 'linked', label: 'Связан', count: 0 },
	];

	test( 'an empty list shows no row of zero counts', async () => {
		serve( () => fixtureList( [], { total: 0, facets: { state: ZEROES } } ) );
		render( <Page /> );
		await screen.findByText( 'Товаров пока нет' );

		expect( document.querySelector( '.woodev-table-views' ) ).toBeNull();
	} );

	test( 'the view the definition opens on is no choice: an empty list still shows no row (the remote cards open on «Нет на сайте»)', async () => {
		const base = definition();
		serve( () => fixtureList( [], { total: 0, facets: { state: ZEROES } } ), {
			...base,
			filters: base.filters.map( ( filter ) => ( filter.id === 'state' ? { ...filter, default: 'draft' } : filter ) ),
		} );
		render( <Page /> );
		await screen.findByText( 'Товаров пока нет' );

		expect( document.querySelector( '.woodev-table-views' ) ).toBeNull();
	} );

	test( 'a view the merchant chose stays on screen even when it is empty', async () => {
		serve( () => fixtureList( [], { total: 0, facets: { state: ZEROES } } ) );
		render( <Page query={ { filter_state: 'draft' } } /> );

		expect( await screen.findByRole( 'link', { name: 'Черновик (0)' } ) ).toBeInTheDocument();
	} );

	test( 'the bulk select lists actions under their headings and says how many rows are ticked', async () => {
		const base = definition();
		serve( () => fixtureList( [ mug, shirt ] ), {
			...base,
			bulk_actions: [ action( 'send', 'Отправить', { group: 'Выгрузка' } ), action( 'archive', 'Архивировать', { group: 'Связи и архив' } ) ],
			messages: { ...base.messages, selected_count: 'Выбрано: {count}' },
		} );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const select = screen.getByRole( 'combobox', { name: 'Массовые действия' } );
		const groups = Array.from( select.querySelectorAll( 'optgroup' ) );

		expect( groups.map( ( group ) => group.label ) ).toEqual( [ 'Выгрузка', 'Связи и архив' ] );
		expect( within( groups[ 1 ] as HTMLElement ).getByRole( 'option', { name: 'Архивировать' } ) ).toBeInTheDocument();
		expect( screen.queryByText( /Выбрано:/ ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );

		expect( screen.getByText( 'Выбрано: 2' ) ).toBeInTheDocument();
	} );

	test( 'a row button with a short label shows it and keeps the full one as its name', async () => {
		const base = definition();
		serve( () => fixtureList( [ fixtureRow( 'p1', 'Кружка', { actions: [ { id: 'cancel', enabled: true, reason: '' } ] } ) ] ), {
			...base,
			row_actions: [ action( 'cancel', 'Отменить отправление', { short_label: 'Отменить' } ) ],
		} );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const button = screen.getByRole( 'button', { name: 'Отменить отправление' } );

		expect( button ).toHaveTextContent( /^Отменить$/ );
		expect( button ).toHaveAttribute( 'title', 'Отменить отправление' );
	} );

	test( 'a column carries its own class on the header and the cells, so the stylesheet can size it', async () => {
		serve( () => fixtureList( [ mug ] ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const declared = cardProps.at( -1 )?.headers.filter( ( header ) => ! header.key.startsWith( '_' ) ) ?? [];

		expect( declared.length ).toBeGreaterThan( 0 );
		expect( declared.every( ( header ) => ( header.cellClassName ?? '' ).includes( `woodev-records__col-${ header.key }` ) ) ).toBe( true );
	} );

	test( 'a date is «д.м.г ч:м» on one line, whatever format the site picked for its posts', async () => {
		serve( () => fixtureList( [ mug ] ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const date = document.querySelector( 'time' ) as HTMLElement;

		expect( date.textContent ).toMatch( /^\d{2}\.\d{2}\.2026 \d{2}:\d{2}$/ );
		expect( date ).toHaveStyle( { whiteSpace: 'nowrap' } );
	} );

	test( 'a long summary keeps its first sentence and folds the rest', async () => {
		const first = 'Список загружен 14.11.2023 22:13: карточек — 120.';
		const rest = 'Последняя связь по артикулу связала 40 товаров, 12 пропущено: у них нет артикула. Ещё 3 пары ждут ответа в отчёте о похожих артикулах.';
		serve( () => fixtureList( [ mug ], { messages: { summary: `${ first } ${ rest }` } } ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		const details = document.querySelector( 'details.woodev-records__summary' ) as HTMLDetailsElement;

		expect( details ).not.toBeNull();
		expect( details.open ).toBe( false );
		expect( within( details ).getByText( first ) ).toBeInTheDocument();
	} );

	test( 'a short summary stays a plain line', async () => {
		serve( () => fixtureList( [ mug ], { messages: { summary: 'Список загружен.' } } ) );
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( document.querySelector( 'details.woodev-records__summary' ) ).toBeNull();
		expect( screen.getByText( 'Список загружен.' ) ).toBeInTheDocument();
	} );

	test( 'the empty card keeps its title for assistive technology only', async () => {
		serve( () => fixtureList( [], { total: 0 } ) );
		render( <Page /> );

		expect( await screen.findByRole( 'heading', { name: 'Товары' } ) ).toHaveClass( 'screen-reader-text' );
	} );
} );
