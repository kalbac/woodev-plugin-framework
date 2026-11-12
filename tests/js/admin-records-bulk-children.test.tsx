/**
 * The bulk toolbar and the locks of expanded children.
 *
 * The shell's handle used to list top-level rows only, so a selected child (a variation) with `activity.blocked_actions`
 * left the bulk button on. The handle now lists the expanded children too, and the toolbar checks them like any row, so a
 * lock the refresh loop patches into a child turns the button off. The server still refuses a blocked child on apply.
 *
 * @see src/admin-records/actions/bulk-toolbar.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
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
const small = { ...fixtureRow( 'v1', 'Футболка S' ), activity: LOCKED };
const medium = { ...fixtureRow( 'v2', 'Футболка M' ), activity: IDLE };

interface Server {
	/** The children of the expanded parent. */
	children: () => AdminRecord[];
}

function serve( overrides: Partial< Server > = {} ): void {
	const server: Server = { children: () => [ small, medium ], ...overrides };

	fetchMock.mockImplementation( ( options: { path: string } ) => {
		const path = decodeURIComponent( options.path );
		const [ route ] = path.split( '?' );

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve(
				fixtureDefinitionEnvelope(
					fixtureDefinition( {
						bulk_actions: [ BULK_EXPORT ],
						pagination: { default_per_page: 25, per_page_options: [ 25 ], max_per_page: 25, max_selection: 10 },
						activity: { refresh_ms: 60000, max_refresh_ms: 60000, stale_after_ms: 120000, id_limit: 100 },
					} )
				)
			);
		}
		if ( route.endsWith( '/children' ) ) {
			return Promise.resolve( fixtureList( server.children(), { total: 2 } ) );
		}

		return Promise.resolve( fixtureList( [ shirt ] ) );
	} );
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
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );

		expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( toolbarButton() ).toHaveAccessibleDescription( LOCK_REASON );
	} );

	test( 'a variation that is free leaves the button on, and unselecting the locked one turns it on again', async () => {
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка M/ } ) );
		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'selecting the parent selects its children, and a locked child blocks the action for the selection', async () => {
		await renderExpanded( createActivityExtensions() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка$/ } ) );

		expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'a lock that arrives after the row was ticked turns the button off (the refresh patches the child)', async () => {
		serve( { children: () => [ { ...small, activity: IDLE }, medium ] } );
		const activity = createActivityExtensions();
		await renderExpanded( activity );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );
		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );

		// The next list read answers with the child locked: collapse and expand re-reads the children.
		serve();
		fireEvent.click( screen.getByRole( 'button', { name: 'Скрыть вариации: Футболка' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Показать вариации: Футболка' } ) );
		await screen.findByText( 'Футболка S' );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка S/ } ) );

		expect( toolbarButton() ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'a collapsed parent with no lock of its own leaves the button on', async () => {
		render( <Page activity={ createActivityExtensions() } /> );
		await screen.findByRole( 'table', { name: 'Товары' } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: /Футболка/ } ) );

		expect( toolbarButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );
