/**
 * Three optional parts of the records shell that «Заказы маркетплейсов» needs to look like «Заказы доставки»: filters behind
 * «Расширенные фильтры», the «Статус данных» block and a control of the page at the head of the filter row.
 *
 * @see src/admin-records/filters.tsx
 * @see src/admin-records/data-status.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, type RecordsTableProps } from '../../src/admin-records';
import { isAdvancedOpen, readQuery, toggleAdvanced, writeQuery } from '../../src/admin-records/query';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { Definition, ListEnvelope } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

/** The fixture with «Состояние» (a select with a default) and «Только в наличии» (a switch) moved behind the toggle. */
function advancedDefinition(): Definition {
	const base = fixtureDefinition();

	return {
		...base,
		filters: base.filters.map( ( filter ) => {
			if ( filter.id === 'state' ) {
				return { ...filter, advanced: true, default: 'linked' };
			}

			return filter.id === 'in_stock' ? { ...filter, advanced: true } : filter;
		} ),
		messages: { ...base.messages, advanced_filters: 'Расширенные фильтры', data_status: 'Статус данных' },
	};
}

function FakeTableCard( props: TableCardProps ) {
	return props.isLoading ? <div data-testid="table-loading" /> : <table aria-label={ props.title } />;
}

function serve( definition: Definition, list: ListEnvelope = fixtureList( [ fixtureRow( 'p1', 'Кружка' ) ] ) ): void {
	fetchMock.mockImplementation( ( { path }: { path: string } ) =>
		Promise.resolve( decodeURIComponent( path ).split( '?' )[ 0 ].endsWith( '/definition' ) ? fixtureDefinitionEnvelope( definition ) : list )
	);
}

async function renderTable( overrides: Partial< RecordsTableProps > = {} ): Promise< jest.Mock > {
	const navigate = jest.fn();

	render( <RecordsTable owner="shop" table="items" title="Товары" query={ {} } navigate={ navigate } { ...overrides } /> );
	await screen.findByRole( 'table', { name: 'Товары' } );

	return navigate;
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'the advanced filters in the URL', () => {
	test( 'the switch is read and written only for a table that has advanced filters', () => {
		const definition = advancedDefinition();

		expect( readQuery( definition, { advanced_filters: '1' } ).advanced ).toBe( true );
		expect( readQuery( fixtureDefinition(), { advanced_filters: '1' } ) ).not.toHaveProperty( 'advanced' );
		expect( readQuery( definition, {} ) ).not.toHaveProperty( 'advanced' );
		expect( writeQuery( definition, { ...readQuery( definition, {} ), advanced: true } ).advanced_filters ).toBe( '1' );
		expect( writeQuery( definition, readQuery( definition, {} ) ).advanced_filters ).toBeUndefined();
	} );

	test( 'a link that narrows by an advanced filter shows it; switching off puts every advanced filter back to its default', () => {
		const definition = advancedDefinition();
		const narrowed = readQuery( definition, { filter_state: 'draft', filter_in_stock: '1', paged: '3' } );

		expect( isAdvancedOpen( definition, narrowed ) ).toBe( true );
		expect( isAdvancedOpen( definition, readQuery( definition, {} ) ) ).toBe( false );

		const closed = toggleAdvanced( definition, { ...narrowed, advanced: true }, false );

		expect( closed.filters ).toEqual( { state: 'linked' } );
		expect( closed ).not.toHaveProperty( 'advanced' );
		expect( closed.page ).toBe( 1 );
		expect( toggleAdvanced( definition, narrowed, true ) ).toEqual( { ...narrowed, advanced: true } );
	} );
} );

describe( 'the filter row', () => {
	test( 'advanced filters wait under their switch, which writes the URL', async () => {
		serve( advancedDefinition() );
		const navigate = await renderTable();

		expect( screen.queryByRole( 'combobox', { name: 'Состояние' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'checkbox', { name: 'Только в наличии' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) );

		expect( navigate ).toHaveBeenLastCalledWith( expect.objectContaining( { advanced_filters: '1' } ) );
	} );

	test( 'switched on, the advanced filters are drawn in a row of their own', async () => {
		serve( advancedDefinition() );
		await renderTable( { query: { advanced_filters: '1' } } );

		const select = screen.getByRole( 'combobox', { name: 'Состояние' } );

		expect( select.closest( '.woodev-records__advanced' ) ).not.toBeNull();
		expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeChecked();
	} );

	test( 'a table without advanced filters has no switch', async () => {
		serve( fixtureDefinition() );
		await renderTable();

		expect( screen.queryByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).not.toBeInTheDocument();
	} );

	test( 'the page\'s own control leads the row', async () => {
		serve( fixtureDefinition() );
		await renderTable( { leadingFilter: <button type="button">Маркетплейс</button> } );

		const toolbar = screen.getByRole( 'button', { name: 'Маркетплейс' } ).closest( '.woodev-records__toolbar' );

		expect( toolbar?.firstElementChild ).toBe( screen.getByRole( 'button', { name: 'Маркетплейс' } ) );
	} );
} );

describe( 'the «Статус данных» block', () => {
	test( 'drawn at the end of the row from the list read, with its tooltip', async () => {
		serve(
			advancedDefinition(),
			fixtureList( [ fixtureRow( 'p1', 'Кружка' ) ], {
				data_status: {
					items: [
						{ label: 'Обновлено', value: '20 ноя 2026 14:05' },
						{ label: 'Обновится', value: 'Сейчас' },
					],
					title: 'Когда заказы проверялись',
				},
			} )
		);
		await renderTable();

		const block = document.querySelector( '.woodev-records__data-status' );

		expect( block ).not.toBeNull();
		expect( block?.parentElement?.classList.contains( 'woodev-records__toolbar' ) ).toBe( true );
		expect( block ).toHaveTextContent( 'Статус данных' );
		expect( document.querySelector( '.woodev-records__data-status-bar' ) ).toHaveAttribute( 'title', 'Когда заказы проверялись' );
		expect( document.querySelector( '.woodev-records__data-status-bar' ) ).toHaveTextContent( 'Обновлено20 ноя 2026 14:05ОбновитсяСейчас' );
		expect( document.querySelector( '.woodev-records__data-status-bar' ) ).toHaveAttribute( 'role', 'status' );
	} );

	test( 'absent when the list carries none', async () => {
		serve( fixtureDefinition() );
		await renderTable();

		expect( document.querySelector( '.woodev-records__data-status' ) ).toBeNull();
	} );
} );
