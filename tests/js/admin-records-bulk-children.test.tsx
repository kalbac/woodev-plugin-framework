/**
 * The bulk toolbar and the locks of expanded children.
 *
 * The shell's handle lists top-level rows only, so a selected child (a variation) with `activity.blocked_actions` used to
 * leave the bulk button on. The toolbar now reads the locks of the selected children from the activity route. The server
 * still refuses a blocked child on apply; this is about not offering a button that cannot work.
 *
 * @see src/admin-records/actions/bulk-toolbar.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions } from '../../src/admin-records';
import { createActivityExtensions, type ActivityExtensions } from '../../src/admin-records/activity';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor, AdminRecord } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const BASE = '/woodev/v1/admin/records/shop/items';
const LOCK_REASON = 'Карточка отправляется на маркетплейс — дождитесь результата';

const BULK_EXPORT: ActionDescriptor = {
	id: 'bulk_export',
	label: 'Выгрузить выбранные',
	tone: 'neutral',
	capability: '',
	effect: 'mutate',
	interaction: 'dialog',
	scope: 'selection',
	scope_sentence: '',
	confirm_label: '',
	skip_preview: false,
	fields: [],
};

const IDLE = { locked: false, reason: '', blocked_actions: [], processes: [], observed_at: '2026-11-12T10:00:00+03:00' };
const LOCKED = { ...IDLE, locked: true, reason: LOCK_REASON, blocked_actions: [ 'bulk_export' ] };

const shirt = fixtureRow( 'p2', 'Футболка', { has_children: true } );
const small = fixtureRow( 'v1', 'Футболка S' );
const medium = fixtureRow( 'v2', 'Футболка M' );

interface Server {
	/** The activity route: the ids asked about → the rows it answers with. */
	activity: ( ids: string[] ) => AdminRecord[] | Promise< AdminRecord[] >;
}

function serve( overrides: Partial< Server > = {} ): void {
	const server: Server = {
		activity: ( ids ) => ids.map( ( id ) => ( { ...fixtureRow( id, id ), activity: IDLE } ) ),
		...overrides,
	};

	fetchMock.mockImplementation( ( options: { path: string; method?: string; data?: { ids: string[] } } ) => {
		const path = decodeURIComponent( options.path );
		const [ route ] = path.split( '?' );

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve(
				fixtureDefinitionEnvelope(
					fixtureDefinition( {
						bulk_actions: [ BULK_EXPORT ],
						pagination: { default_per_page: 25, per_page_options: [ 25 ], max_per_page: 25, max_selection: 10 },
						activity: { refresh_ms: 60000, max_refresh_ms: 60000, stale_after_ms: 120000, id_limit: 2 },
					} )
				)
			);
		}
		if ( route.endsWith( '/children' ) ) {
			return Promise.resolve( fixtureList( [ small, medium ], { total: 2 } ) );
		}
		if ( route.endsWith( '/activity' ) ) {
			return Promise.resolve( server.activity( options.data?.ids ?? [] ) ).then( ( rows ) => ( {
				rows,
				removed_ids: [],
				account_activity: { active: false, text: '', blocked_actions: [], observed_at: IDLE.observed_at },
				observed_at: IDLE.observed_at,
				refresh_after_ms: 60000,
				messages: {},
			} ) );
		}

		return Promise.resolve( fixtureList( [ shirt ] ) );
	} );
}

function activityReads(): string[][] {
	return fetchMock.mock.calls
		.filter( ( [ options ] ) => String( options.path ).endsWith( '/activity' ) )
		.map( ( [ options ] ) => options.data.ids );
}

function FakeTableCard( props: TableCardProps ) {
	if ( props.isLoading ) {
		return <div data-testid="table-loading" />;
	}

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

function Page( { activity }: { activity?: ActivityExtensions } ) {
	const actions = useActionExtensions( activity );
	const extensions = activity ? { ...actions, ...activity.extensions } : actions;

	return (
		<RecordsTable
			owner="shop"
			table="items"
			title="Товары"
			query={ {} }
			navigate={ jest.fn() }
			extensions={ extensions }
		/>
	);
}

async function renderExpanded( activity?: ActivityExtensions ) {
	render( <Page activity={ activity } /> );
	await screen.findByRole( 'table', { name: 'Товары' } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
	await screen.findByText( 'Футболка S' );
}

function toolbarButton(): HTMLElement {
	return screen.getByRole( 'button', { name: 'Выгрузить выбранные' } );
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	serve();
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'bulk actions and selected children', () => {
	test( 'a selected variation whose lock blocks the action turns the bulk button off, with the reason beside it', async () => {
		serve( { activity: ( ids ) => ids.map( ( id ) => ( { ...fixtureRow( id, id ), activity: id === 'v1' ? LOCKED : IDLE } ) ) } );
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );

		await waitFor( () => expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' ) );
		expect( toolbarButton() ).toHaveAccessibleDescription( LOCK_REASON );
		expect( activityReads() ).toContainEqual( [ 'v1' ] );
	} );

	test( 'a variation that is free leaves the button on, and unselecting the locked one turns it on again', async () => {
		serve( { activity: ( ids ) => ids.map( ( id ) => ( { ...fixtureRow( id, id ), activity: id === 'v1' ? LOCKED : IDLE } ) ) } );
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка M/ } ) );
		await waitFor( () => expect( activityReads() ).toContainEqual( [ 'v2' ] ) );
		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		await waitFor( () => expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' ) );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		await waitFor( () => expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' ) );
	} );

	test( 'selecting the parent selects its children, and a locked child blocks the action for the whole selection', async () => {
		serve( { activity: ( ids ) => ids.map( ( id ) => ( { ...fixtureRow( id, id ), activity: id === 'v2' ? LOCKED : IDLE } ) ) } );
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка$/ } ) );

		await waitFor( () => expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' ) );
		expect( activityReads().flat().sort() ).toEqual( [ 'v1', 'v2' ] );
	} );

	test( 'one read carries the selected children, within the definition’s id limit', async () => {
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка$/ } ) );

		await waitFor( () => expect( activityReads() ).toHaveLength( 1 ) );
		expect( activityReads()[ 0 ] ).toHaveLength( 2 );
	} );

	test( 'a failed read leaves the button on: the server still decides on apply', async () => {
		serve( { activity: () => Promise.reject( { code: 'fetch_error', message: 'Failed to fetch' } ) } );
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		await waitFor( () => expect( activityReads() ).toHaveLength( 1 ) );

		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'without the activity part nothing is read and nothing is blocked', async () => {
		await renderExpanded();

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );

		expect( activityReads() ).toEqual( [] );
		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'a top-level selection reads nothing: the handle already has those rows', async () => {
		render( <Page activity={ createActivityExtensions() } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );

		expect( activityReads() ).toEqual( [] );
	} );
} );
