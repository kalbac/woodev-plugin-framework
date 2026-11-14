/**
 * The shell's checkboxes do not depend on `max_selection`: they appear whenever the table has bulk actions and the page gave the
 * shell its bulk toolbar, and a row's own `selectable` decides whether its box is enabled. The marketplace cards table raises
 * `max_selection` from 1 to 100 for «Импортировать» (framework card I6), so this pins what it relies on: one enabled box per
 * selectable row at the cap of 100, none enabled for a row that is not selectable, the selection-scope bulk action waiting for a
 * tick and the account-scope one not needing it.
 *
 * @see src/admin-records/table-model.tsx
 * @see src/admin-records/selection.ts
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions } from '../../src/admin-records';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

function bulk( id: string, label: string, scope: 'selection' | 'account' ): ActionDescriptor {
	return {
		id,
		label,
		tone: 'neutral',
		capability: '',
		effect: 'mutate',
		interaction: 'dialog',
		scope,
		scope_sentence: scope === 'account' ? 'Весь аккаунт.' : '',
		confirm_label: '',
		skip_preview: false,
		fields: [],
	};
}

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

function Page() {
	return (
		<RecordsTable
			owner="shop"
			table="cards"
			title="Карточки"
			query={ {} }
			navigate={ jest.fn() }
			extensions={ useActionExtensions() }
		/>
	);
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	fetchMock.mockImplementation( ( options: { path: string } ) => {
		const [ route ] = decodeURIComponent( options.path ).split( '?' );

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve(
				fixtureDefinitionEnvelope(
					fixtureDefinition( {
						bulk_actions: [
							bulk( 'import_selected', 'Импортировать', 'selection' ),
							bulk( 'refresh_list', 'Обновить список', 'account' ),
						],
						pagination: { default_per_page: 20, per_page_options: [ 20 ], max_per_page: 20, max_selection: 100 },
					} )
				)
			);
		}

		return Promise.resolve(
			fixtureList( [
				fixtureRow( 'c1', 'Кружка' ),
				fixtureRow( 'c2', 'Футболка' ),
				{ ...fixtureRow( 'c3', 'Блокнот' ), selectable: false },
			] )
		);
	} );
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'selection at a cap of 100', () => {
	test( 'every selectable row has an enabled box and a row that is not selectable has a disabled one', async () => {
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Карточки' } );

		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).toBeEnabled();
		expect( screen.getByRole( 'checkbox', { name: /Футболка/ } ) ).toBeEnabled();
		expect( screen.getByRole( 'checkbox', { name: /Блокнот/ } ) ).toBeDisabled();
	} );

	test( 'the selection action waits for a tick and the account action does not', async () => {
		render( <Page /> );
		await screen.findByRole( 'table', { name: 'Карточки' } );

		// A disabled toolbar button stays focusable, so it says so with `aria-disabled`.
		expect( screen.getByRole( 'button', { name: 'Импортировать' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByRole( 'button', { name: 'Обновить список' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Кружка/ } ) );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );

		expect( screen.getByRole( 'button', { name: 'Импортировать' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByRole( 'checkbox', { name: /Кружка/ } ) ).toBeChecked();
		expect( screen.getByRole( 'checkbox', { name: /Футболка/ } ) ).toBeChecked();
	} );
} );
