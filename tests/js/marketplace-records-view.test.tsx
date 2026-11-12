/**
 * The records view of the marketplace pages mounts the action and the activity parts, so the table the PHP provider
 * describes has its selection column, its bulk toolbar and its working row actions on the built page.
 *
 * @see src/marketplace-admin/views/records/index.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../src/marketplace-admin/page-view';
import { activeTable, switchPatch } from '../../src/marketplace-admin/views/records';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ManifestMenu, ManifestPage, PageView } from '../../src/marketplace-admin/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const PAGE = { slug: 'products', title: 'Товары', kind: 'records', tables: [ 'items' ] } as unknown as ManifestPage;
const MENU = { pluginId: 'shop' } as unknown as ManifestMenu;

function FakeTableCard( props: TableCardProps ) {
	if ( props.isLoading ) {
		return <div data-testid="table-loading" />;
	}

	return (
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
	);
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	fetchMock.mockImplementation( ( { path }: { path: string } ) => {
		const route = decodeURIComponent( path ).split( '?' )[ 0 ];

		return Promise.resolve(
			route.endsWith( '/definition' )
				? fixtureDefinitionEnvelope( fixtureDefinition() )
				: fixtureList( [ fixtureRow( 'p1', 'Кружка' ) ] )
		);
	} );
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

test( 'the records view renders the selection column and the bulk toolbar the definition declares', async () => {
	const View = applyFilters( PAGE_VIEW_FILTER, null, PAGE ) as PageView;

	render( <View page={ PAGE } menu={ MENU } query={ {} } /> );
	await screen.findByRole( 'table', { name: 'Товары' } );

	expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).toBeInTheDocument();
	expect( within( screen.getByRole( 'toolbar' ) ).getByRole( 'button', { name: 'Выгрузить выбранные' } ) ).toBeInTheDocument();
} );

test( 'a row action is no longer disabled for want of a runner', async () => {
	const View = applyFilters( PAGE_VIEW_FILTER, null, PAGE ) as PageView;

	render( <View page={ PAGE } menu={ MENU } query={ {} } /> );
	await screen.findByRole( 'table', { name: 'Товары' } );

	expect( screen.getByRole( 'button', { name: 'Выгрузить' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
} );

describe( 'a page with several tables', () => {
	const TWO = {
		...PAGE,
		tables: [ 'items', 'cards' ],
		tableLabels: { items: 'Товары сайта', cards: 'Карточки на маркетплейсе' },
	} as unknown as ManifestPage;

	/** The definition and list routes the view read, newest last. */
	function reads(): string[] {
		return fetchMock.mock.calls.map( ( [ { path } ] ) => decodeURIComponent( path ).split( '?' )[ 0 ] );
	}

	function View(): PageView {
		return applyFilters( PAGE_VIEW_FILTER, null, TWO ) as PageView;
	}

	test( 'a page with one table shows no switch', async () => {
		const Single = applyFilters( PAGE_VIEW_FILTER, null, PAGE ) as PageView;

		render( <Single page={ PAGE } menu={ MENU } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		expect( screen.queryByRole( 'navigation' ) ).not.toBeInTheDocument();
	} );

	test( 'the switch names the tables with the labels PHP sent and opens on the first one', async () => {
		const Page = View();

		render( <Page page={ TWO } menu={ MENU } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары сайта' } );

		const switcher = screen.getByRole( 'navigation', { name: 'Товары' } );
		expect( within( switcher ).getByRole( 'button', { name: 'Товары сайта' } ) ).toHaveAttribute( 'aria-current', 'page' );
		expect( within( switcher ).getByRole( 'button', { name: 'Карточки на маркетплейсе' } ) ).not.toHaveAttribute( 'aria-current' );
		expect( reads().every( ( route ) => route.includes( '/shop/items' ) ) ).toBe( true );
	} );

	test( 'the table named in the URL is the one shown', async () => {
		const Page = View();

		render( <Page page={ TWO } menu={ MENU } query={ { table: 'cards' } } /> );
		await screen.findByRole( 'table', { name: 'Карточки на маркетплейсе' } );

		expect( reads().every( ( route ) => route.includes( '/shop/cards' ) ) ).toBe( true );
		expect( screen.getByRole( 'button', { name: 'Карточки на маркетплейсе' } ) ).toHaveAttribute( 'aria-current', 'page' );
	} );

	test.each( [ 'orders', '../items', 'items/children', '', 'CARDS' ] )( 'a URL asking for "%s", which is not one of the page tables, shows the first table', async ( asked ) => {
		const Page = View();

		render( <Page page={ TWO } menu={ MENU } query={ { table: asked } } /> );
		await screen.findByRole( 'table', { name: 'Товары сайта' } );

		expect( reads().every( ( route ) => route.includes( '/shop/items' ) ) ).toBe( true );
		expect( reads().some( ( route ) => route.includes( 'orders' ) || route.includes( '..' ) ) ).toBe( false );
	} );

	test( 'a click on the other table writes it to the URL and drops what belonged to the first one', async () => {
		const update = jest.fn();
		( window as unknown as { wc: { navigation: unknown } } ).wc.navigation = { updateQueryString: update };
		const Page = View();

		render(
			<Page
				page={ TWO }
				menu={ MENU }
				query={ { page: 'wc-admin', path: '/shop/products', account: 'a2', paged: '3', search: 'кружка', orderby: 'name', filter_state: 'error' } }
			/>
		);
		await screen.findByRole( 'table', { name: 'Товары сайта' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Карточки на маркетплейсе' } ) );

		expect( update ).toHaveBeenCalledTimes( 1 );
		expect( update ).toHaveBeenCalledWith( {
			table: 'cards',
			paged: undefined,
			search: undefined,
			orderby: undefined,
			filter_state: undefined,
		} );
	} );

	test( 'a click on the table already open changes nothing, and the way back leaves the argument out', async () => {
		const update = jest.fn();
		( window as unknown as { wc: { navigation: unknown } } ).wc.navigation = { updateQueryString: update };
		const Page = View();

		render( <Page page={ TWO } menu={ MENU } query={ { table: 'cards', paged: '2' } } /> );
		await screen.findByRole( 'table', { name: 'Карточки на маркетплейсе' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Карточки на маркетплейсе' } ) );
		expect( update ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Товары сайта' } ) );
		expect( update ).toHaveBeenCalledWith( { table: undefined, paged: undefined } );
	} );

	test( 'switching starts the other table clean: the selection of the first one is gone', async () => {
		const Page = View();
		const { rerender } = render( <Page page={ TWO } menu={ MENU } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары сайта' } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).toBeChecked();

		rerender( <Page page={ TWO } menu={ MENU } query={ { table: 'cards' } } /> );
		await screen.findByRole( 'table', { name: 'Карточки на маркетплейсе' } );
		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).not.toBeChecked();

		rerender( <Page page={ TWO } menu={ MENU } query={ {} } /> );
		await screen.findByRole( 'table', { name: 'Товары сайта' } );
		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).not.toBeChecked();
	} );
} );

describe( 'the table switch helpers', () => {
	const TWO = { tables: [ 'products', 'remote_cards' ] } as unknown as ManifestPage;

	test( 'only a table of the page is active', () => {
		expect( activeTable( TWO, {} ) ).toBe( 'products' );
		expect( activeTable( TWO, { table: 'remote_cards' } ) ).toBe( 'remote_cards' );
		expect( activeTable( TWO, { table: 'constructor' } ) ).toBe( 'products' );
		expect( activeTable( TWO, { table: 'toString' } ) ).toBe( 'products' );
	} );

	test( 'a switch keeps wc-admin, the account and the table argument, and clears the rest', () => {
		const query = { page: 'wc-admin', path: '/p', account: '3', table: 'remote_cards', paged: '2', per_page: '50', filter_on_site: '~' };

		expect( switchPatch( TWO, query, 'products' ) ).toEqual( { table: undefined, paged: undefined, per_page: undefined, filter_on_site: undefined } );
		expect( switchPatch( TWO, { account: '3' }, 'remote_cards' ) ).toEqual( { table: 'remote_cards' } );
	} );
} );
