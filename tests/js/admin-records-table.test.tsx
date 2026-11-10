/**
 * The records shell: a PHP-described table on WooCommerce's runtime `TableCard`.
 *
 * `TableCard` is a double here — it renders exactly what it is given and records its props, which is also how the
 * Q3 check works: the shell may pass only what both the oldest supported WooCommerce and trunk accept.
 *
 * @see src/admin-records/
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import {
	RecordsTable,
	type RecordsExtensions,
	type RecordsTableProps,
	type TableHandle,
} from '../../src/admin-records';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { DefinitionEnvelope, ListEnvelope, RouteQuery } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

/** `TableCard` props that exist in WooCommerce 7.0 AND trunk (see src/admin-records/wc-runtime.ts). */
const TABLE_CARD_PROPS_IN_EVERY_VERSION = [
	'title',
	'headers',
	'rows',
	'rowKey',
	'rowHeader',
	'rowsPerPage',
	'totalRows',
	'query',
	'isLoading',
	'showMenu',
	'onSort',
	'onPageChange',
	'onQueryChange',
	'className',
];

const HEADER_PROPS_IN_EVERY_VERSION = [
	'key',
	'label',
	'screenReaderLabel',
	'isSortable',
	'isLeftAligned',
	'isNumeric',
	'required',
	'defaultSort',
	'defaultOrder',
	// 7.0's `TableCard.getShowCols()` reads it too (table/index.js, checked against the 7.0.0 tag).
	'visible',
	'cellClassName',
];

/** 7.0.0 `TableCard.getShowCols( headers )`, copied: the columns it shows on mount. */
function showColsOf70( headers: Array< { key: string; visible?: boolean } > ): string[] {
	return headers
		.map( ( { key, visible } ) => ( typeof visible === 'undefined' || visible ? key : false ) )
		.filter( Boolean ) as string[];
}

let tableCardProps: TableCardProps[] = [];

function FakeTableCard( props: TableCardProps ) {
	tableCardProps.push( props );

	if ( props.isLoading ) {
		return <div data-testid="table-loading" />;
	}

	const nextDirection = ( key: string ) =>
		props.query.orderby === key && props.query.order === 'desc' ? 'asc' : 'desc';

	return (
		<div>
			<table aria-label={ props.title }>
				<thead>
					<tr>
						{ props.headers.map( ( header ) => (
							<th key={ header.key } data-key={ header.key }>
								{ header.isSortable ? (
									<button
										type="button"
										onClick={ () => props.onSort( header.key, nextDirection( header.key ) ) }
									>
										{ header.label }
									</button>
								) : (
									header.label
								) }
								{ header.screenReaderLabel && (
									<span className="screen-reader-text">{ header.screenReaderLabel }</span>
								) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ props.rows.map( ( row, index ) => (
						<tr key={ props.rowKey( row, index ) } data-key={ props.rowKey( row, index ) }>
							{ row.map( ( cell, column ) =>
								column === props.rowHeader ? (
									<th key={ column } scope="row">
										{ cell.display }
									</th>
								) : (
									<td key={ column }>{ cell.display }</td>
								)
							) }
						</tr>
					) ) }
				</tbody>
			</table>
			<button
				type="button"
				onClick={ () => {
					// WooCommerce calls both for one click.
					props.onPageChange( 2 );
					props.onQueryChange( 'paged' )( '2' );
				} }
			>
				next page
			</button>
			<button type="button" onClick={ () => props.onQueryChange( 'per_page' )( '50' ) }>
				50 per page
			</button>
			<button type="button" onClick={ () => props.onQueryChange( 'per_page' )( '75' ) }>
				75 per page
			</button>
			{ [ 100, 75 ].map( ( size ) => (
				<button
					key={ size }
					type="button"
					onClick={ () => {
						// WooCommerce's Pagination.perPageChange, then TableCard.onPageChange for the clamped page.
						const clamped = Math.ceil( props.totalRows / size );
						props.onQueryChange( 'per_page' )( size );
						props.onPageChange( clamped );
						props.onQueryChange( 'paged' )( clamped );
					} }
				>
					{ `vendor ${ size } per page` }
				</button>
			) ) }
		</div>
	);
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

type Answer< T > = T | Promise< T >;

interface Server {
	definition: ( path: string ) => Answer< DefinitionEnvelope >;
	rows: ( path: string ) => Answer< ListEnvelope >;
	children: ( path: string ) => Answer< ListEnvelope >;
}

const BASE = '/woodev/v1/admin/records/shop/items';
const mug = fixtureRow( 'p1', 'Кружка' );
const shirt = fixtureRow( 'p2', 'Футболка', { has_children: true } );
const small = fixtureRow( 'v1', 'Футболка S' );
const medium = fixtureRow( 'v2', 'Футболка M' );
const large = fixtureRow( 'v3', 'Футболка L' );

function serve( overrides: Partial< Server > = {} ): void {
	const server: Server = {
		definition: () => fixtureDefinitionEnvelope(),
		rows: () => fixtureList( [ mug, shirt ] ),
		children: () => fixtureList( [ small, medium ], { total: 2 } ),
		...overrides,
	};

	fetchMock.mockImplementation( ( { path }: { path: string } ) => {
		const decoded = decodeURIComponent( path );
		const [ route ] = decoded.split( '?' );

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve( server.definition( decoded ) );
		}
		if ( route.endsWith( '/children' ) ) {
			return Promise.resolve( server.children( decoded ) );
		}

		return Promise.resolve( server.rows( decoded ) );
	} );
}

/** `page=2` alone would also match `per_page=25`. */
function isPage( path: string, page: number ): boolean {
	return new RegExp( `[?&]page=${ page }(&|$)` ).test( path );
}

function requests(): string[] {
	return fetchMock.mock.calls.map( ( [ options ] ) => decodeURIComponent( options.path ) );
}

function listRequests(): string[] {
	return requests().filter( ( path ) => path === BASE || path.startsWith( `${ BASE }?` ) );
}

function childRequests(): string[] {
	return requests().filter( ( path ) => path.includes( '/children' ) );
}

function props( overrides: Partial< RecordsTableProps > = {} ): RecordsTableProps {
	return { owner: 'shop', table: 'items', title: 'Товары', query: {}, navigate: jest.fn(), ...overrides };
}

async function renderTable( overrides: Partial< RecordsTableProps > = {} ) {
	const tableProps = props( overrides );
	const view = render( <RecordsTable { ...tableProps } /> );

	await screen.findByRole( 'table', { name: 'Товары' } );

	return { ...view, navigate: tableProps.navigate as jest.Mock, tableProps };
}

function tableRow( key: string ): HTMLElement {
	const row = document.querySelector( `tr[data-key="${ key }"]` );
	if ( ! row ) {
		throw new Error( `No row with the key ${ key }` );
	}

	return row as HTMLElement;
}

function rowKeys(): string[] {
	return Array.from( document.querySelectorAll( 'tbody tr' ) ).map( ( row ) => row.getAttribute( 'data-key' ) ?? '' );
}

beforeEach( () => {
	tableCardProps = [];
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	serve();
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'rendering from PHP descriptors', () => {
	test( 'headers come from the definition, in PHP order', async () => {
		await renderTable();

		const labels = Array.from( document.querySelectorAll( 'thead th' ) ).map( ( th ) => th.textContent );

		expect( labels ).toEqual( [
			'Вариации',
			'Товар',
			'Артикул',
			'Состояние',
			'Остаток',
			'Обновлён',
			'Детали',
			'Отправка',
			'Действия',
		] );
	} );

	test( 'every cell type is rendered from its value', async () => {
		await renderTable();

		const row = within( tableRow( 'p1' ) );

		expect( row.getByRole( 'link', { name: 'Кружка' } ) ).toHaveAttribute(
			'href',
			'/wp-admin/post.php?post=p1&action=edit'
		);
		expect( row.getByText( 'SKU-p1' ) ).toBeInTheDocument();
		expect( row.getByText( 'Связан' ) ).toHaveClass( 'woodev-records__badge', 'is-success' );
		expect( row.getByText( '7' ) ).toBeInTheDocument();
		expect( tableRow( 'p1' ).querySelector( 'time' ) ).toHaveAttribute( 'datetime', '2026-11-09T12:30:00+03:00' );
		expect( tableRow( 'p1' ).querySelector( 'time' )?.textContent ).not.toBe( '' );
		expect( row.getByText( 'Склад Москва' ) ).toBeInTheDocument();
		expect( row.getByText( 'FBS' ) ).toHaveClass( 'is-info' );
		expect( row.getByRole( 'progressbar', { name: 'Отправлено 3 из 10' } ) ).toHaveAttribute( 'value', '30' );
		expect( row.getByText( 'Отправлено 3 из 10' ) ).toBeInTheDocument();
	} );

	test( 'the row header is the first declared column, after the utility columns', async () => {
		await renderTable();

		expect( within( tableRow( 'p1' ) ).getByRole( 'rowheader' ) ).toHaveTextContent( 'Кружка' );
	} );

	test( 'an empty cell and unmeasured progress are shown as such', async () => {
		const bare = fixtureRow( 'p9', 'Пустой', {
			cells: {
				...mug.cells,
				sku: null,
				sync: { completed: null, total: null, percent: null, text: 'В очереди' },
			},
		} );
		serve( { rows: () => fixtureList( [ bare ] ) } );

		await renderTable();

		expect( within( tableRow( 'p9' ) ).queryByText( /SKU/ ) ).not.toBeInTheDocument();
		expect( within( tableRow( 'p9' ) ).getByRole( 'progressbar', { name: 'В очереди' } ) ).not.toHaveAttribute(
			'value'
		);
	} );

	test( 'text that looks like markup stays text', async () => {
		const odd = fixtureRow( 'p8', 'Обычный', { cells: { ...mug.cells, sku: '<b>жирный</b>' } } );
		serve( { rows: () => fixtureList( [ odd ] ) } );

		await renderTable();

		expect( within( tableRow( 'p8' ) ).getByText( '<b>жирный</b>' ) ).toBeInTheDocument();
		expect( tableRow( 'p8' ).querySelector( 'b' ) ).toBeNull();
	} );

	test( 'the summary sentence of the response overrides the definition', async () => {
		serve( { rows: () => fixtureList( [ mug ], { messages: { summary: 'Найден 1 товар' } } ) } );

		await renderTable();

		expect( screen.getByText( 'Найден 1 товар' ) ).toBeInTheDocument();
	} );
} );

describe( 'row actions', () => {
	test( 'a button per action PHP listed, labelled by the definition', async () => {
		await renderTable( { extensions: { onRowAction: jest.fn() } } );

		const row = within( tableRow( 'p1' ) );

		expect( row.getByRole( 'button', { name: 'Выгрузить' } ) ).toBeEnabled();
		expect( row.getByRole( 'button', { name: 'Отвязать' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'a disabled action says why in text tied to the button', async () => {
		await renderTable( { extensions: { onRowAction: jest.fn() } } );

		const button = within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Отвязать' } );
		const reason = within( tableRow( 'p1' ) ).getByText( 'Идёт выгрузка — отвязать нельзя' );

		expect( button ).toHaveAttribute( 'aria-describedby', reason.id );
	} );

	test( 'activation is handed to the action part with the descriptor, the row and the table', async () => {
		const onRowAction = jest.fn();
		await renderTable( { extensions: { onRowAction } } );

		fireEvent.click( within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Выгрузить' } ) );
		fireEvent.click( within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Отвязать' } ) );

		expect( onRowAction ).toHaveBeenCalledTimes( 1 );
		expect( onRowAction ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'export', label: 'Выгрузить' } ),
			mug,
			expect.objectContaining( { context: { account_id: 'a1' }, visibleIds: [ 'p1', 'p2' ] } )
		);
	} );

	test( 'without an action part the buttons are there but run nothing', async () => {
		await renderTable();

		expect( within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
	} );

	test( 'an action the user has no descriptor for is not rendered', async () => {
		const definition = fixtureDefinition();
		const limited = {
			...definition,
			row_actions: definition.row_actions.filter( ( action ) => action.id !== 'unlink' ),
		};
		serve( { definition: () => fixtureDefinitionEnvelope( limited ) } );

		await renderTable();

		expect( within( tableRow( 'p1' ) ).queryByRole( 'button', { name: 'Отвязать' } ) ).not.toBeInTheDocument();
	} );

	test( 'the activity part can block an enabled action with its own reason', async () => {
		const extensions: RecordsExtensions = {
			onRowAction: jest.fn(),
			blockedReason: ( action, row ) =>
				action.id === 'export' && row.id === 'p1' ? 'Идёт фоновая отправка' : '',
			renderRowActivity: ( row ) => <em>{ `процессы ${ row.id }` }</em>,
		};
		await renderTable( { extensions } );

		expect( within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		expect( within( tableRow( 'p1' ) ).getByText( 'Идёт фоновая отправка' ) ).toBeInTheDocument();
		expect( within( tableRow( 'p2' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).toBeEnabled();
		expect( within( tableRow( 'p2' ) ).getByText( 'процессы p2' ) ).toBeInTheDocument();
	} );
} );

describe( 'URL round trip', () => {
	test( 'a clean URL reads the declared defaults', async () => {
		await renderTable();

		expect( requests()[ 0 ] ).toBe( `${ BASE }/definition` );
		expect( listRequests() ).toEqual( [
			`${ BASE }?context[account_id]=a1&page=1&per_page=25&sort=updated&direction=desc`,
		] );
	} );

	test( 'the URL becomes the list request', async () => {
		const query: RouteQuery = {
			account: 'a2',
			paged: '2',
			per_page: '50',
			search: 'кружка',
			orderby: 'stock',
			order: 'asc',
			filter_state: 'draft',
			filter_brand: 'acme,zen',
			filter_in_stock: '0',
			filter_updated: '2026-11-01,2026-11-09',
		};
		serve( { definition: () => fixtureDefinitionEnvelope( fixtureDefinition(), 'a2' ) } );

		await renderTable( { query } );

		expect( requests()[ 0 ] ).toBe( `${ BASE }/definition?context[account_id]=a2` );
		expect( listRequests() ).toEqual( [
			`${ BASE }?context[account_id]=a2&page=2&per_page=50&search=кружка&sort=stock&direction=asc` +
				'&filters[state]=draft&filters[brand][]=acme&filters[brand][]=zen&filters[in_stock]=false' +
				'&filters[updated][from]=2026-11-01&filters[updated][to]=2026-11-09',
		] );
	} );

	test( 'the URL is shown back in the controls', async () => {
		await renderTable( {
			query: { search: 'кружка', filter_state: 'draft', filter_in_stock: '1', filter_updated: '2026-11-01,' },
		} );

		expect( screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ) ).toHaveValue( 'кружка' );
		expect( screen.getByRole( 'combobox', { name: 'Состояние' } ) ).toHaveValue( 'draft' );
		expect( screen.getByRole( 'checkbox', { name: 'Только в наличии' } ) ).toBeChecked();
		expect( screen.getByLabelText( 'Период обновления: С' ) ).toHaveValue( '2026-11-01' );
		expect( screen.getByLabelText( 'Период обновления: По' ) ).toHaveValue( '' );
	} );

	test( 'sorting writes the sort key of the column, not its id, and returns to page one', async () => {
		const { navigate } = await renderTable( { query: { paged: '3' } } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Товар' } ) );

		expect( navigate ).toHaveBeenLastCalledWith(
			expect.objectContaining( { orderby: 'title', order: 'desc', paged: undefined } )
		);
	} );

	test( 'the sorted column is handed to the table by its column id', async () => {
		await renderTable( { query: { orderby: 'title', order: 'asc' } } );

		expect( tableCardProps.at( -1 )?.query ).toEqual( { orderby: 'name', order: 'asc', paged: '1', per_page: 25 } );
	} );

	test( 'one page click is one navigation', async () => {
		const { navigate } = await renderTable();

		fireEvent.click( screen.getByRole( 'button', { name: 'next page' } ) );

		expect( navigate ).toHaveBeenCalledTimes( 1 );
		expect( navigate ).toHaveBeenCalledWith( expect.objectContaining( { paged: '2' } ) );
	} );

	test( 'a page size PHP declared is written, another is ignored', async () => {
		const { navigate } = await renderTable( { query: { paged: '2' } } );

		fireEvent.click( screen.getByRole( 'button', { name: '75 per page' } ) );
		expect( navigate ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: '50 per page' } ) );
		expect( navigate ).toHaveBeenCalledWith( expect.objectContaining( { per_page: '50', paged: undefined } ) );
	} );

	describe( 'page size', () => {
		const pagination = {
			default_per_page: 20,
			per_page_options: [ 10, 20, 50 ],
			max_per_page: 50,
			max_selection: 3,
		};
		const servePhpSizes = () =>
			serve( {
				definition: () => fixtureDefinitionEnvelope( fixtureDefinition( { pagination } ) ),
				rows: () => fixtureList( [ mug, shirt ], { total: 150 } ),
			} );

		test( "the control offers PHP's sizes only and WooCommerce's own picker is hidden", async () => {
			servePhpSizes();
			const { navigate } = await renderTable( { query: { paged: '3' } } );

			const select = screen.getByRole( 'combobox', { name: 'Строк на странице' } );
			expect(
				within( select )
					.getAllByRole( 'option' )
					.map( ( option ) => option.textContent )
			).toEqual( [ '10', '20', '50' ] );
			expect( select ).toHaveValue( '20' );
			const css = Array.from( document.querySelectorAll( 'style' ) ).map( ( style ) => style.textContent );
			expect( css.join( '' ) ).toContain( '.woocommerce-pagination__per-page-picker{display:none}' );

			fireEvent.change( select, { target: { value: '10' } } );
			expect( navigate ).toHaveBeenLastCalledWith(
				expect.objectContaining( { per_page: '10', paged: undefined } )
			);

			fireEvent.change( select, { target: { value: '50' } } );
			expect( navigate ).toHaveBeenLastCalledWith(
				expect.objectContaining( { per_page: '50', paged: undefined } )
			);
		} );

		test( 'the declared default is the size that is left out of the URL', async () => {
			servePhpSizes();
			const { navigate } = await renderTable( { query: { per_page: '50' } } );

			fireEvent.change( screen.getByRole( 'combobox', { name: 'Строк на странице' } ), {
				target: { value: '20' },
			} );

			expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { per_page: undefined } ) );
		} );

		test( 'a table with one size has no size control', async () => {
			serve( {
				definition: () =>
					fixtureDefinitionEnvelope(
						fixtureDefinition( { pagination: { ...pagination, per_page_options: [ 20 ] } } )
					),
			} );
			await renderTable();

			expect( screen.queryByRole( 'combobox', { name: 'Строк на странице' } ) ).not.toBeInTheDocument();
		} );

		test( "WooCommerce's size-then-page callbacks end on the new size and the clamped page", async () => {
			serve( { rows: () => fixtureList( [ mug, shirt ], { total: 150 } ) } );
			const { navigate } = await renderTable( { query: { paged: '4' } } );

			fireEvent.click( screen.getByRole( 'button', { name: 'vendor 100 per page' } ) );

			expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { per_page: '100', paged: '2' } ) );
		} );

		test( 'a size PHP did not declare is dropped together with the page WooCommerce clamps for it', async () => {
			serve( { rows: () => fixtureList( [ mug, shirt ], { total: 150 } ) } );
			const { navigate } = await renderTable( { query: { paged: '4' } } );

			fireEvent.click( screen.getByRole( 'button', { name: 'vendor 75 per page' } ) );
			expect( navigate ).not.toHaveBeenCalled();

			// The guard lasts for that one event only.
			await act( async () => {} );
			fireEvent.click( screen.getByRole( 'button', { name: 'next page' } ) );
			await waitFor( () => expect( navigate ).toHaveBeenCalledTimes( 1 ) );
		} );
	} );

	test( 'filters write their own URL keys', async () => {
		const { navigate } = await renderTable( { query: { paged: '4' } } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Состояние' } ), { target: { value: 'linked' } } );
		expect( navigate ).toHaveBeenLastCalledWith(
			expect.objectContaining( { filter_state: 'linked', paged: undefined } )
		);

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Только в наличии' } ) );
		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_in_stock: '1' } ) );

		fireEvent.change( screen.getByLabelText( 'Период обновления: По' ), { target: { value: '2026-11-09' } } );
		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_updated: ',2026-11-09' } ) );
	} );

	test( 'a multi-select takes its options from the response facets', async () => {
		const { navigate } = await renderTable();
		const brands = screen.getByRole( 'listbox', { name: 'Бренд' } );

		expect( within( brands ).getByRole( 'option', { name: 'Acme (4)' } ) ).toBeInTheDocument();

		const options = Array.from( brands.querySelectorAll( 'option' ) );
		options.forEach( ( option ) => ( option.selected = true ) );
		fireEvent.change( brands );

		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_brand: 'acme,zen' } ) );
	} );

	test( 'the 51st facet value cannot be selected', async () => {
		const facets = Array.from( { length: 51 }, ( _, index ) => ( {
			value: `v${ index }`,
			label: `Бренд ${ index }`,
			count: 1,
		} ) );
		serve( { rows: () => fixtureList( [ mug ], { facets: { brand: facets } } ) } );
		const { navigate } = await renderTable();
		const brands = screen.getByRole( 'listbox', { name: 'Бренд' } );
		const options = Array.from( brands.querySelectorAll( 'option' ) );

		options.slice( 0, 50 ).forEach( ( option ) => ( option.selected = true ) );
		fireEvent.change( brands );
		expect( navigate ).toHaveBeenCalledTimes( 1 );

		options.forEach( ( option ) => ( option.selected = true ) );
		fireEvent.change( brands );
		expect( navigate ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a search the server would refuse is not written', async () => {
		const { navigate } = await renderTable();
		const box = screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } );

		fireEvent.change( box, { target: { value: 'foo\u0000bar' } } );
		fireEvent.submit( box.closest( 'form' ) as HTMLFormElement );
		fireEvent.change( box, { target: { value: 'я'.repeat( 201 ) } } );
		fireEvent.submit( box.closest( 'form' ) as HTMLFormElement );

		expect( navigate ).not.toHaveBeenCalled();
	} );

	test( 'unticking a boolean filter removes it instead of sending false', async () => {
		const { navigate } = await renderTable( { query: { filter_in_stock: '1' } } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Только в наличии' } ) );

		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { filter_in_stock: undefined } ) );
	} );

	test( 'a date range that would end before it starts is not written', async () => {
		const { navigate } = await renderTable( { query: { filter_updated: '2026-11-05,' } } );

		fireEvent.change( screen.getByLabelText( 'Период обновления: По' ), { target: { value: '2026-11-01' } } );

		expect( navigate ).not.toHaveBeenCalled();
	} );

	test( 'search is written on submit, not while typing', async () => {
		const { navigate } = await renderTable();
		const box = screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } );

		fireEvent.change( box, { target: { value: ' кружка ' } } );
		expect( navigate ).not.toHaveBeenCalled();

		fireEvent.submit( box.closest( 'form' ) as HTMLFormElement );
		expect( navigate ).toHaveBeenCalledWith( expect.objectContaining( { search: 'кружка' } ) );
	} );

	test( 'the account selector writes the account and keeps the filters', async () => {
		const { navigate } = await renderTable( { query: { filter_state: 'draft', paged: '2' } } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Аккаунт' } ), { target: { value: 'a2' } } );

		expect( navigate ).toHaveBeenCalledWith(
			expect.objectContaining( { account: 'a2', filter_state: 'draft', paged: undefined } )
		);
	} );

	test( 'reset clears search and filters and is offered only when something narrows the table', async () => {
		const { navigate, rerender, tableProps } = await renderTable();

		expect( screen.queryByRole( 'button', { name: 'Сбросить фильтры' } ) ).not.toBeInTheDocument();

		rerender(
			<RecordsTable { ...tableProps } query={ { search: 'кружка', filter_state: 'draft', orderby: 'title' } } />
		);
		fireEvent.click( await screen.findByRole( 'button', { name: 'Сбросить фильтры' } ) );

		expect( navigate ).toHaveBeenCalledWith(
			expect.objectContaining( { search: undefined, filter_state: undefined, orderby: 'title' } )
		);
	} );

	test( 'a new URL (Back, Forward, a link) reads the table it describes', async () => {
		const { rerender, tableProps } = await renderTable();

		rerender( <RecordsTable { ...tableProps } query={ { paged: '2', filter_state: 'draft' } } /> );

		await waitFor( () => expect( listRequests() ).toHaveLength( 2 ) );
		expect( listRequests()[ 1 ] ).toBe(
			`${ BASE }?context[account_id]=a1&page=2&per_page=25&sort=updated&direction=desc&filters[state]=draft`
		);
	} );

	test( 'a re-render with the same URL reads nothing', async () => {
		const { rerender, tableProps } = await renderTable( { query: { paged: '2' } } );

		rerender( <RecordsTable { ...tableProps } query={ { paged: '2' } } /> );
		await act( async () => {} );

		expect( listRequests() ).toHaveLength( 1 );
	} );

	test( 'outside wc-admin the table keeps its own URL state and still works', async () => {
		await renderTable( { navigate: undefined } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Состояние' } ), { target: { value: 'draft' } } );

		await waitFor( () => expect( listRequests() ).toHaveLength( 2 ) );
		expect( listRequests()[ 1 ] ).toContain( 'filters[state]=draft' );
	} );

	test( "wc-admin's own navigation is used when the page gives none", async () => {
		const updateQueryString = jest.fn();
		( window as unknown as { wc: Record< string, unknown > } ).wc.navigation = { updateQueryString };

		await renderTable( { navigate: undefined } );
		fireEvent.click( screen.getByRole( 'button', { name: 'next page' } ) );

		expect( updateQueryString ).toHaveBeenCalledWith( expect.objectContaining( { paged: '2' } ) );
	} );
} );

describe( 'expandable children', () => {
	const expander = ( name: string ) => screen.getByRole( 'button', { name } );

	test( 'only a row with children has an expander, labelled from PHP with the row name', async () => {
		await renderTable();

		expect( expander( 'Показать вариации: Футболка' ) ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByRole( 'button', { name: 'Показать вариации: Кружка' } ) ).not.toBeInTheDocument();
	} );

	test( 'expanding reads the children and places them right after their parent', async () => {
		await renderTable();

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );

		expect( await screen.findByText( 'Футболка S' ) ).toBeInTheDocument();
		expect( childRequests() ).toEqual( [ `${ BASE }/p2/children?context[account_id]=a1&page=1&per_page=2` ] );
		expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v1', 'p2/v2' ] );
		expect( expander( 'Скрыть вариации: Футболка' ) ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	test( 'the wait is announced with the PHP sentence', async () => {
		const pending = defer< ListEnvelope >();
		serve( { children: () => pending.promise } );
		await renderTable();

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );

		expect( within( tableRow( 'p2/_note' ) ).getByRole( 'status' ) ).toHaveTextContent( 'Загружаем вариации…' );

		await act( async () => pending.resolve( fixtureList( [ small ], { total: 1 } ) ) );

		expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v1' ] );
	} );

	test( 'more children are read page by page and appended', async () => {
		serve( {
			children: ( path ) =>
				isPage( path, 2 )
					? fixtureList( [ large ], { total: 3, page: 2 } )
					: fixtureList( [ small, medium ], { total: 3 } ),
		} );
		await renderTable();

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Показать ещё' } ) );

		expect( await screen.findByText( 'Футболка L' ) ).toBeInTheDocument();
		expect( childRequests()[ 1 ] ).toBe( `${ BASE }/p2/children?context[account_id]=a1&page=2&per_page=2` );
		expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v1', 'p2/v2', 'p2/v3' ] );
		expect( screen.queryByRole( 'button', { name: 'Показать ещё' } ) ).not.toBeInTheDocument();
	} );

	test( 'collapsing removes the children', async () => {
		await renderTable();

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		await screen.findByText( 'Футболка S' );
		fireEvent.click( expander( 'Скрыть вариации: Футболка' ) );

		expect( rowKeys() ).toEqual( [ 'p1', 'p2' ] );
	} );

	test( 'a failed read shows the server sentence and can be repeated', async () => {
		let attempt = 0;
		serve( {
			children: () => {
				attempt++;

				return attempt === 1
					? Promise.reject( {
							code: 'woodev_records_provider_error',
							message: 'Не удалось прочитать вариации',
					  } )
					: fixtureList( [ small ], { total: 1 } );
			},
		} );
		await renderTable();

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );

		const alert = await within( tableRow( 'p2/_note' ) ).findByRole( 'alert' );
		expect( alert ).toHaveTextContent( 'Не удалось прочитать вариации' );

		fireEvent.click( within( alert ).getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByText( 'Футболка S' ) ).toBeInTheDocument();
	} );

	test( 'a child row has its own actions and no expander', async () => {
		await renderTable( { extensions: { onRowAction: jest.fn() } } );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		await screen.findByText( 'Футболка S' );

		expect( within( tableRow( 'p2/v1' ) ).getByRole( 'button', { name: 'Выгрузить' } ) ).toBeEnabled();
		expect( within( tableRow( 'p2/v1' ) ).queryByRole( 'button', { name: /вариации/ } ) ).not.toBeInTheDocument();
	} );

	test( 'a reload keeps an expanded row open and reads its children again', async () => {
		let handle!: TableHandle;
		await renderTable( { extensions: { renderActivity: ( table ) => ( ( handle = table ), null ) } } );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		await screen.findByText( 'Футболка S' );

		act( () => handle.reload() );

		await waitFor( () => expect( childRequests() ).toHaveLength( 2 ) );
		expect( await screen.findByText( 'Футболка S' ) ).toBeInTheDocument();
		expect( expander( 'Скрыть вариации: Футболка' ) ).toBeInTheDocument();
	} );

	test( "another account never shows the first account's children under a parent with the same id", async () => {
		const pendingB = defer< ListEnvelope >();
		const accountOf = ( path: string ) => ( path.includes( 'account_id]=a2' ) ? 'a2' : 'a1' );
		serve( {
			definition: ( path ) => fixtureDefinitionEnvelope( fixtureDefinition(), accountOf( path ) ),
			children: ( path ) =>
				accountOf( path ) === 'a2'
					? pendingB.promise
					: fixtureList( [ fixtureRow( 'v1', 'ACCOUNT A CHILD' ) ], { total: 1 } ),
		} );
		const { rerender, tableProps } = await renderTable( { query: { account: 'a1' } } );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		expect( await screen.findByText( 'ACCOUNT A CHILD' ) ).toBeInTheDocument();

		rerender( <RecordsTable { ...tableProps } query={ { account: 'a2' } } /> );
		await waitFor( () =>
			expect( listRequests().some( ( path ) => path.includes( 'account_id]=a2' ) ) ).toBe( true )
		);
		await screen.findByRole( 'table', { name: 'Товары' } );

		// B's rows are on screen, B's children are still being read: A's must not stand in for them.
		expect( screen.queryByText( 'ACCOUNT A CHILD' ) ).not.toBeInTheDocument();
		expect( rowKeys() ).toEqual( [ 'p1', 'p2' ] );
		expect( expander( 'Показать вариации: Футболка' ) ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( childRequests() ).toHaveLength( 1 );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		await act( async () =>
			pendingB.resolve( fixtureList( [ fixtureRow( 'v1', 'ACCOUNT B CHILD' ) ], { total: 1 } ) )
		);

		expect( await screen.findByText( 'ACCOUNT B CHILD' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'ACCOUNT A CHILD' ) ).not.toBeInTheDocument();
	} );

	test( 'a child read still in flight for the first account is dropped after the switch', async () => {
		const pendingA = defer< ListEnvelope >();
		const accountOf = ( path: string ) => ( path.includes( 'account_id]=a2' ) ? 'a2' : 'a1' );
		serve( {
			definition: ( path ) => fixtureDefinitionEnvelope( fixtureDefinition(), accountOf( path ) ),
			children: () => pendingA.promise,
		} );
		const { rerender, tableProps } = await renderTable( { query: { account: 'a1' } } );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		rerender( <RecordsTable { ...tableProps } query={ { account: 'a2' } } /> );
		await waitFor( () =>
			expect( listRequests().some( ( path ) => path.includes( 'account_id]=a2' ) ) ).toBe( true )
		);
		await screen.findByRole( 'table', { name: 'Товары' } );
		await act( async () =>
			pendingA.resolve( fixtureList( [ fixtureRow( 'v1', 'ACCOUNT A CHILD' ) ], { total: 1 } ) )
		);

		expect( screen.queryByText( 'ACCOUNT A CHILD' ) ).not.toBeInTheDocument();
		expect( rowKeys() ).toEqual( [ 'p1', 'p2' ] );
	} );

	test( 'a retry after a failed first-page refresh repeats page one, not the next page', async () => {
		let handle!: TableHandle;
		let refresh = false;
		serve( {
			children: ( path ) => {
				if ( isPage( path, 1 ) && refresh ) {
					return Promise.reject( {
						code: 'woodev_records_provider_error',
						message: 'Не удалось прочитать вариации',
					} );
				}

				return isPage( path, 2 )
					? fixtureList( [ large ], { total: 3, page: 2 } )
					: fixtureList( [ small, medium ], { total: 3 } );
			},
		} );
		await renderTable( { extensions: { renderActivity: ( table ) => ( ( handle = table ), null ) } } );

		fireEvent.click( expander( 'Показать вариации: Футболка' ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Показать ещё' } ) );
		await screen.findByText( 'Футболка L' );

		refresh = true;
		await act( async () => handle.reload() );
		const alert = await screen.findByRole( 'alert' );
		refresh = false;
		fireEvent.click( within( alert ).getByRole( 'button', { name: 'Повторить' } ) );

		await waitFor( () => expect( childRequests() ).toHaveLength( 4 ) );
		expect( childRequests().at( -1 ) ).toBe( `${ BASE }/p2/children?context[account_id]=a1&page=1&per_page=2` );
		await waitFor( () => expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v1', 'p2/v2', 'p2/_note' ] ) );
	} );

	test( 'a table without a children contract has no expand column', async () => {
		serve( {
			definition: () => fixtureDefinitionEnvelope( fixtureDefinition( { children: null } ) ),
			rows: () => fixtureList( [ mug ] ),
		} );

		await renderTable();

		expect( document.querySelector( 'th[data-key="_expand"]' ) ).toBeNull();
	} );
} );

describe( 'stale-read protection', () => {
	const first = fixtureList( [ fixtureRow( 'old', 'Старый ответ' ) ] );
	const second = fixtureList( [ fixtureRow( 'new', 'Новый ответ' ) ] );

	function serveTwoReads() {
		const slow = defer< ListEnvelope >();
		const fast = defer< ListEnvelope >();
		serve( { rows: ( path ) => ( isPage( path, 2 ) ? fast.promise : slow.promise ) } );

		return { slow, fast };
	}

	async function startBothReads() {
		const reads = serveTwoReads();
		const tableProps = props();
		const view = render( <RecordsTable { ...tableProps } /> );

		await waitFor( () => expect( listRequests() ).toHaveLength( 1 ) );
		view.rerender( <RecordsTable { ...tableProps } query={ { paged: '2' } } /> );
		await waitFor( () => expect( listRequests() ).toHaveLength( 2 ) );

		return reads;
	}

	test( 'a slower older response never replaces the rows of a newer query', async () => {
		const { slow, fast } = await startBothReads();

		await act( async () => fast.resolve( second ) );
		expect( await screen.findByText( 'Новый ответ' ) ).toBeInTheDocument();

		await act( async () => slow.resolve( first ) );

		expect( screen.getByText( 'Новый ответ' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Старый ответ' ) ).not.toBeInTheDocument();
	} );

	test( 'an older response that arrives first does not end the wait for the newer one', async () => {
		const { slow, fast } = await startBothReads();

		await act( async () => slow.resolve( first ) );

		expect( screen.queryByText( 'Старый ответ' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'table-loading' ) ).toBeInTheDocument();

		await act( async () => fast.resolve( second ) );

		expect( await screen.findByText( 'Новый ответ' ) ).toBeInTheDocument();
	} );

	test( 'a late failure of an older read does not turn a newer table into an error', async () => {
		const { slow, fast } = await startBothReads();

		await act( async () => fast.resolve( second ) );
		await act( async () => slow.reject( { message: 'Старая ошибка' } ) );

		expect( screen.getByText( 'Новый ответ' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	test( 'an early failure of an older read does not end the wait either', async () => {
		const { slow, fast } = await startBothReads();

		await act( async () => slow.reject( { message: 'Старая ошибка' } ) );

		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'table-loading' ) ).toBeInTheDocument();

		await act( async () => fast.resolve( second ) );
		expect( await screen.findByText( 'Новый ответ' ) ).toBeInTheDocument();
	} );

	test( 'an obsolete read is aborted', async () => {
		await startBothReads();

		const listSignals = fetchMock.mock.calls
			.filter( ( [ options ] ) => decodeURIComponent( options.path ).startsWith( `${ BASE }?` ) )
			.map( ( [ options ] ) => options.signal as AbortSignal );

		expect( listSignals.map( ( signal ) => signal.aborted ) ).toEqual( [ true, false ] );
	} );

	test( 'children of an earlier expansion do not land in a later one', async () => {
		const earlier = defer< ListEnvelope >();
		const later = defer< ListEnvelope >();
		let childReads = 0;
		serve( {
			children: () => {
				childReads++;

				return childReads === 1 ? earlier.promise : later.promise;
			},
		} );
		await renderTable();

		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Скрыть вариации: Футболка' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		await act( async () => earlier.resolve( fixtureList( [ small ], { total: 1 } ) ) );

		expect( screen.queryByText( 'Футболка S' ) ).not.toBeInTheDocument();
		expect( within( tableRow( 'p2/_note' ) ).getByRole( 'status' ) ).toBeInTheDocument();

		await act( async () => later.resolve( fixtureList( [ medium ], { total: 1 } ) ) );

		expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v2' ] );
	} );

	test( 'children read before a list reload do not land in the reloaded table', async () => {
		const stale = defer< ListEnvelope >();
		let childReads = 0;
		serve( {
			children: () => {
				childReads++;

				return childReads === 1 ? stale.promise : fixtureList( [ medium ], { total: 1 } );
			},
		} );
		const { rerender, tableProps } = await renderTable();

		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		rerender( <RecordsTable { ...tableProps } query={ { paged: '2' } } /> );

		expect( await screen.findByText( 'Футболка M' ) ).toBeInTheDocument();
		await act( async () => stale.resolve( fixtureList( [ small ], { total: 1 } ) ) );

		expect( screen.queryByText( 'Футболка S' ) ).not.toBeInTheDocument();
		expect( rowKeys() ).toEqual( [ 'p1', 'p2', 'p2/v2' ] );
	} );

	test( 'the definition of an account the merchant already left is dropped', async () => {
		const slow = defer< DefinitionEnvelope >();
		const renamed = fixtureDefinition( {
			columns: fixtureDefinition().columns.map( ( column ) =>
				column.id === 'name' ? { ...column, label: 'Чужая колонка' } : column
			),
		} );
		serve( {
			definition: ( path ) => ( path.includes( 'a2' ) ? slow.promise : fixtureDefinitionEnvelope() ),
		} );
		const tableProps = props( { query: { account: 'a2' } } );
		const view = render( <RecordsTable { ...tableProps } /> );

		view.rerender( <RecordsTable { ...tableProps } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );
		await act( async () => slow.resolve( fixtureDefinitionEnvelope( renamed, 'a2' ) ) );

		expect( screen.queryByText( 'Чужая колонка' ) ).not.toBeInTheDocument();
		expect( listRequests().every( ( path ) => path.includes( 'context[account_id]=a1' ) ) ).toBe( true );
	} );
} );

describe( 'error and empty states', () => {
	test( 'a failed list read shows the server sentence and a retry that reads again', async () => {
		let attempt = 0;
		serve( {
			rows: () => {
				attempt++;

				return attempt === 1
					? Promise.reject( { code: 'woodev_records_provider_error', message: 'Сервер не ответил' } )
					: fixtureList( [ mug ] );
			},
		} );
		render( <RecordsTable { ...props() } /> );

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent( 'Сервер не ответил' );
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();

		fireEvent.click( within( alert ).getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByText( 'Кружка' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	test( 'a failure without a sentence falls back to the PHP one, never to the error code', async () => {
		serve( { rows: () => Promise.reject( { code: 'fetch_error' } ) } );
		render( <RecordsTable { ...props() } /> );

		const alert = await screen.findByRole( 'alert' );

		expect( alert ).toHaveTextContent( 'Не удалось загрузить список' );
		expect( alert ).not.toHaveTextContent( 'fetch_error' );
	} );

	test( 'filters stay usable while the list is in error', async () => {
		serve( { rows: () => Promise.reject( { message: 'Сервер не ответил' } ) } );
		render( <RecordsTable { ...props() } /> );

		await screen.findByRole( 'alert' );

		expect( screen.getByRole( 'combobox', { name: 'Состояние' } ) ).toBeEnabled();
	} );

	test( 'a failed definition read shows the server sentence; retry reads it again', async () => {
		let attempt = 0;
		serve( {
			definition: () => {
				attempt++;

				return attempt === 1
					? Promise.reject( { code: 'woodev_records_not_found', message: 'Таблица не найдена' } )
					: fixtureDefinitionEnvelope();
			},
		} );
		render( <RecordsTable { ...props( { fallbackMessages: { retry: 'Повторить' } } ) } /> );

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent( 'Таблица не найдена' );
		expect( listRequests() ).toHaveLength( 0 );

		fireEvent.click( within( alert ).getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByRole( 'table', { name: 'Товары' } ) ).toBeInTheDocument();
	} );

	test( 'without a PHP label there is no retry button rather than an invented one', async () => {
		serve( { definition: () => Promise.reject( { message: 'Таблица не найдена' } ) } );
		render( <RecordsTable { ...props() } /> );

		expect( within( await screen.findByRole( 'alert' ) ).queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	test( 'an empty table shows the PHP sentence instead of an empty grid', async () => {
		serve( { rows: () => fixtureList( [] ) } );
		render( <RecordsTable { ...props() } /> );

		expect( await screen.findByRole( 'status' ) ).toHaveTextContent( 'Товаров пока нет' );
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	test( 'an empty result of a search or filter says so and offers the reset', async () => {
		serve( { rows: () => fixtureList( [] ) } );
		render( <RecordsTable { ...props( { query: { filter_state: 'draft' } } ) } /> );

		expect( await screen.findByRole( 'status' ) ).toHaveTextContent( 'По этим условиям ничего не найдено' );
		expect( screen.getByRole( 'button', { name: 'Сбросить фильтры' } ) ).toBeInTheDocument();
	} );

	test( 'the first load shows that something is happening', () => {
		serve( { definition: () => defer< DefinitionEnvelope >().promise } );
		const { container } = render( <RecordsTable { ...props() } /> );

		expect( container.querySelector( '[aria-busy="true"]' ) ).toBeInTheDocument();
	} );
} );

describe( 'keyboard access', () => {
	test( 'the expander is a real button in the tab order and keeps focus when the row opens', async () => {
		await renderTable();
		const button = screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } );

		expect( button.tagName ).toBe( 'BUTTON' );
		expect( button ).not.toHaveAttribute( 'tabindex', '-1' );

		button.focus();
		fireEvent.click( button );
		await screen.findByText( 'Футболка S' );

		expect( document.activeElement ).toBe( button );
		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	test( 'a disabled action can still be reached to read its reason', async () => {
		await renderTable( { extensions: { onRowAction: jest.fn() } } );
		const button = within( tableRow( 'p1' ) ).getByRole( 'button', { name: 'Отвязать' } );

		button.focus();

		expect( button ).not.toBeDisabled();
		expect( document.activeElement ).toBe( button );
	} );

	test( 'every control of the toolbar is a native control with a PHP name', async () => {
		await renderTable( { query: { search: 'кружка' } } );

		const named = [
			screen.getByRole( 'combobox', { name: 'Аккаунт' } ),
			screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } ),
			screen.getByRole( 'combobox', { name: 'Состояние' } ),
			screen.getByRole( 'listbox', { name: 'Бренд' } ),
			screen.getByRole( 'checkbox', { name: 'Только в наличии' } ),
			screen.getByLabelText( 'Период обновления: С' ),
			screen.getByLabelText( 'Период обновления: По' ),
			screen.getByRole( 'button', { name: 'Сбросить фильтры' } ),
		];

		named.forEach( ( control ) => {
			expect( [ 'SELECT', 'INPUT', 'BUTTON' ] ).toContain( control.tagName );
			expect( control ).not.toHaveAttribute( 'tabindex', '-1' );
		} );
	} );

	test( 'Enter in the search box searches', async () => {
		const { navigate } = await renderTable();
		const box = screen.getByRole( 'searchbox', { name: 'Поиск по товарам' } );

		expect( box.closest( 'form' ) ).toHaveAttribute( 'role', 'search' );

		fireEvent.change( box, { target: { value: 'кружка' } } );
		fireEvent.submit( box.closest( 'form' ) as HTMLFormElement );

		expect( navigate ).toHaveBeenCalledWith( expect.objectContaining( { search: 'кружка' } ) );
	} );
} );

describe( 'Q3: TableCard and labels', () => {
	test( 'only props that WooCommerce 7.0 and trunk both accept are passed', async () => {
		await renderTable();

		tableCardProps.forEach( ( cardProps ) => {
			expect( TABLE_CARD_PROPS_IN_EVERY_VERSION ).toEqual( expect.arrayContaining( Object.keys( cardProps ) ) );
			cardProps.headers.forEach( ( header ) => {
				expect( HEADER_PROPS_IN_EVERY_VERSION ).toEqual( expect.arrayContaining( Object.keys( header ) ) );
			} );
		} );
	} );

	test( 'a column PHP hides by default is hidden on 7.0 as well: its visible flag reaches getShowCols', async () => {
		const columns = fixtureDefinition().columns.map( ( column ) =>
			column.id === 'sku' ? { ...column, visible: false } : column
		);
		serve( { definition: () => fixtureDefinitionEnvelope( fixtureDefinition( { columns } ) ) } );
		await renderTable();

		const shown = showColsOf70( tableCardProps.at( -1 )?.headers ?? [] );

		expect( shown ).not.toContain( 'sku' );
		expect( shown ).toContain( 'name' );
		expect( shown ).toContain( 'stock' );
	} );

	test( 'the empty message and the preface, missing in 7.0, are never used', async () => {
		await renderTable();

		tableCardProps.forEach( ( cardProps ) => {
			expect( cardProps ).not.toHaveProperty( 'emptyMessage' );
			expect( cardProps ).not.toHaveProperty( 'tablePreface' );
		} );
	} );

	test( 'the card is mounted only once the columns are known and has them from its first render', async () => {
		await renderTable();

		expect( tableCardProps[ 0 ].headers.map( ( header ) => header.key ) ).toEqual( [
			'_expand',
			'name',
			'sku',
			'state',
			'stock',
			'updated',
			'details',
			'sync',
			'actions',
		] );
	} );

	test( 'the card is remounted when the columns change, because WooCommerce reads them once', async () => {
		const fewer = fixtureDefinition( {
			columns: fixtureDefinition().columns.filter( ( column ) => column.id !== 'sku' ),
		} );
		serve( {
			definition: ( path ) =>
				fixtureDefinitionEnvelope(
					path.includes( 'a2' ) ? fewer : fixtureDefinition(),
					path.includes( 'a2' ) ? 'a2' : 'a1'
				),
		} );
		const { rerender, tableProps } = await renderTable();
		const table = screen.getByRole( 'table', { name: 'Товары' } );

		rerender( <RecordsTable { ...tableProps } query={ { account: 'a2' } } /> );
		await waitFor( () => {
			expect( screen.getByRole( 'table', { name: 'Товары' } ) ).not.toBe( table );
			expect( document.querySelector( 'th[data-key="sku"]' ) ).toBeNull();
		} );
	} );

	test( 'every header has an accessible name: its label, or a screen-reader label from PHP', async () => {
		await renderTable( { extensions: { renderBulkActions: () => null } } );

		Array.from( document.querySelectorAll( 'thead th' ) ).forEach( ( th ) => {
			const labelledControl = th.querySelector( '[aria-label]' )?.getAttribute( 'aria-label' ) ?? '';

			expect( `${ th.textContent }${ labelledControl }` ).not.toBe( '' );
		} );
	} );

	test( 'a header that is itself a control gets no screen-reader label, which would hide it', async () => {
		await renderTable( { extensions: { renderBulkActions: () => null } } );

		const selectHeader = tableCardProps.at( -1 )?.headers.find( ( header ) => header.key === '_select' );

		expect( selectHeader ).toBeDefined();
		expect( selectHeader ).not.toHaveProperty( 'screenReaderLabel' );
	} );

	test( 'alignment, sortability and the column menu follow the definition', async () => {
		await renderTable();
		const headers = tableCardProps.at( -1 )?.headers ?? [];
		const header = ( key: string ) => headers.find( ( entry ) => entry.key === key );

		expect( header( 'stock' ) ).toMatchObject( { isNumeric: true, isLeftAligned: false, isSortable: true } );
		expect( header( 'sku' ) ).toMatchObject( {
			isNumeric: false,
			isLeftAligned: true,
			isSortable: false,
			required: false,
		} );
		expect( header( 'name' ) ).toMatchObject( { required: true } );
		expect( tableCardProps.at( -1 )?.showMenu ).toBe( true );
	} );

	test( 'page size and total come from the query and the response', async () => {
		serve( { rows: () => fixtureList( [ mug ], { total: 120 } ) } );

		await renderTable( { query: { per_page: '50' } } );

		expect( tableCardProps.at( -1 ) ).toMatchObject( { rowsPerPage: 50, totalRows: 120, title: 'Товары' } );
	} );
} );

describe( 'extension seams', () => {
	async function renderWithBulk() {
		let handle!: TableHandle;
		const renderBulkActions = jest.fn( ( selection ) => (
			<output data-testid="selected">{ selection.selectedIds.join( ',' ) }</output>
		) );
		const view = await renderTable( {
			extensions: { renderBulkActions, renderActivity: ( table ) => ( ( handle = table ), null ) },
		} );

		return { ...view, renderBulkActions, handle: () => handle };
	}

	const selected = () => screen.getByTestId( 'selected' ).textContent;

	test( 'there is no selection column until the action part asks for one', async () => {
		await renderTable();

		expect( document.querySelector( 'th[data-key="_select"]' ) ).toBeNull();
	} );

	test( 'rows are selected by a checkbox named from PHP and the row', async () => {
		await renderWithBulk();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Выбрать строку: Кружка' } ) );

		expect( selected() ).toBe( 'p1' );
	} );

	test( 'a row PHP marks as not selectable cannot be ticked', async () => {
		serve( { rows: () => fixtureList( [ fixtureRow( 'p1', 'Кружка', { selectable: false } ) ] ) } );
		await renderWithBulk();

		expect( screen.getByRole( 'checkbox', { name: 'Выбрать строку: Кружка' } ) ).toBeDisabled();
		expect( screen.getByRole( 'checkbox', { name: 'Выбрать все на странице' } ) ).toBeDisabled();
	} );

	test( 'selecting a parent selects its loaded children', async () => {
		await renderWithBulk();

		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		await screen.findByText( 'Футболка S' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Выбрать строку: Футболка' } ) );

		expect( selected() ).toBe( 'p2,v1,v2' );
	} );

	test( 'the selection stops at the PHP limit and says so', async () => {
		await renderWithBulk();

		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		await screen.findByText( 'Футболка S' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Выбрать все на странице' } ) );

		expect( selected() ).toBe( 'p1,p2,v1' );
		expect( screen.queryByText( 'Можно выбрать не больше трёх' ) ).not.toBeInTheDocument();
	} );

	test( "the limit sentence is PHP's", async () => {
		const definition = fixtureDefinition();
		serve( {
			definition: () =>
				fixtureDefinitionEnvelope( {
					...definition,
					messages: { ...definition.messages, selection_limit: 'Можно выбрать не больше трёх' },
				} ),
		} );
		await renderWithBulk();

		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		await screen.findByText( 'Футболка S' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Выбрать все на странице' } ) );

		expect( screen.getByText( 'Можно выбрать не больше трёх' ) ).toBeInTheDocument();
	} );

	test( 'a list read drops the selection', async () => {
		const { handle } = await renderWithBulk();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Выбрать строку: Кружка' } ) );
		act( () => handle().reload() );

		await waitFor( () => expect( listRequests() ).toHaveLength( 2 ) );
		await waitFor( () => expect( selected() ).toBe( '' ) );
	} );

	test( 'rows can be patched and removed without a list read', async () => {
		const { handle } = await renderWithBulk();

		act( () => handle().patchRows( [ fixtureRow( 'p1', 'Кружка обновлённая' ) ], [ 'p2' ] ) );

		expect( screen.getByText( 'Кружка обновлённая' ) ).toBeInTheDocument();
		expect( rowKeys() ).toEqual( [ 'p1' ] );
		expect( listRequests() ).toHaveLength( 1 );
	} );

	test( 'the epoch moves with every list read', async () => {
		const { handle } = await renderWithBulk();
		const before = handle().epoch;

		act( () => handle().reload() );
		await waitFor( () => expect( listRequests() ).toHaveLength( 2 ) );

		expect( handle().epoch ).toBeGreaterThan( before );
	} );
} );
