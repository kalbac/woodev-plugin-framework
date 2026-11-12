/**
 * The records view of the marketplace pages mounts the action and the activity parts, so the table the PHP provider
 * describes has its selection column, its bulk toolbar and its working row actions on the built page.
 *
 * @see src/marketplace-admin/views/records/index.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../src/marketplace-admin/page-view';
import '../../src/marketplace-admin/views/records';
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
