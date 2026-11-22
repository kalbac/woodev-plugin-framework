/**
 * The lines of an expanded row: which column carries the child's indent.
 *
 * A child row (a variation) is indented in the table's NAME column. The photo column in front of it is not that column: indenting
 * it pushed the photo 24px to the right of its own 46–52px column, so it was clipped (operator, rig review 20.11.2026, #1898).
 *
 * @see src/admin-records/table-model.tsx
 */

import '@testing-library/jest-dom';
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { fixtureDefinition, fixtureRow } from '../../src/admin-records/testing/fixture';
import { SELECT_COLUMN, buildHeaders, buildLines, nameColumnIndex, type TableModelInput } from '../../src/admin-records/table-model';
import type { Definition } from '../../src/admin-records/types';
import type { ChildrenState } from '../../src/admin-records/use-records';

/** The fixture table with a photo column in front of the (required) name column. */
function photoFirst(): Definition {
	const base = fixtureDefinition();
	const photo = { ...base.columns[ 0 ], id: 'image', label: 'Фото', cell_type: 'text' as const, sort_key: null, required: false };

	return { ...base, children: true, columns: [ photo, ...base.columns ] } as Definition;
}

function input( definition: Definition, state: ChildrenState ): TableModelInput {
	const parent = fixtureRow( 'p1', 'Футболка', { has_children: true, cells: { ...fixtureRow( 'p1', 'x' ).cells, image: 'ФОТО' } } );

	return {
		definition,
		messages: {},
		rows: [ parent ],
		children: { p1: state },
		expanded: [ 'p1' ],
		selection: null,
		onToggle: () => undefined,
		onMoreChildren: () => undefined,
		renderActions: () => null,
	};
}

function ready( rows: ChildrenState[ 'rows' ], total = rows.length ): ChildrenState {
	return { status: 'ready', rows, total, page: 1, failedPage: 0, message: '' };
}

/** The cells of line `index`, drawn. */
function drawn( cells: Array< { display: ReactNode } > ) {
	return cells.map( ( cell ) => render( <div>{ cell.display }</div> ).container );
}

const isIndented = ( container: HTMLElement ) => container.querySelector( 'span[style*="padding-left"]' ) !== null;

describe( 'the name column', () => {
	test( 'is the first required column, else the first', () => {
		expect( nameColumnIndex( photoFirst() ) ).toBe( 1 );
		expect( nameColumnIndex( { ...photoFirst(), columns: photoFirst().columns.map( ( column ) => ( { ...column, required: false } ) ) } ) ).toBe( 0 );
	} );
} );

describe( 'a child row', () => {
	test( 'is indented in the name column and leaves the photo column where it is', () => {
		const definition = photoFirst();
		const lines = buildLines( input( definition, ready( [ fixtureRow( 'v1', 'Размер S', { cells: { ...fixtureRow( 'v1', 'x' ).cells, image: 'ФОТО' } } ) ] ) ) );
		const child = drawn( lines[ 1 ].cells );
		// The expand column leads: [expand, photo, name, …].
		const lead = 1;

		expect( lines ).toHaveLength( 2 );
		expect( isIndented( child[ lead ] ) ).toBe( false );
		expect( isIndented( child[ lead + 1 ] ) ).toBe( true );
	} );

	test( 'the «show more» and «loading» lines sit in the name column too', () => {
		const definition = photoFirst();
		const more = buildLines( input( definition, ready( [ fixtureRow( 'v1', 'Размер S' ) ], 5 ) ) );
		const note = drawn( more[ more.length - 1 ].cells );

		expect( isIndented( note[ 1 ] ) ).toBe( false );
		expect( isIndented( note[ 2 ] ) ).toBe( true );
	} );
} );

describe( 'a row with a process in flight (#1937)', () => {
	const running = { locked: false, reason: '', blocked_actions: [], observed_at: '', processes: [ { id: 'p', state: 'running' as const, label: 'Импорт', completed: null, total: null, percent: null, text: '' } ] };
	const select = ( set: jest.Mock ) => ( {
		selectedIds: [],
		limitReached: false,
		matching: false,
		matchingQuery: null,
		set,
		selectMatching: () => undefined,
		clear: () => undefined,
	} );
	const withSelection = ( rows: TableModelInput[ 'rows' ], children: TableModelInput[ 'children' ], set: jest.Mock ): TableModelInput => ( {
		...input( photoFirst(), ready( [] ) ),
		rows,
		children,
		selection: select( set ),
	} );
	const checkboxOf = ( cells: Array< { display: ReactNode } >, at: number ) => drawn( cells )[ at ].querySelector( 'input[type="checkbox"]' ) as HTMLInputElement;

	test( 'the header checkbox selects only the idle rows', () => {
		const set = jest.fn();
		const idle = fixtureRow( 'idle', 'Кружка' );
		const busy = fixtureRow( 'busy', 'Футболка', { activity: running } );
		const model = withSelection( [ idle, busy ], {}, set );
		const header = buildHeaders( model ).find( ( entry ) => entry.key === SELECT_COLUMN );

		render( <div>{ header?.label }</div> ).container.querySelector( 'input' )?.click();

		expect( set ).toHaveBeenCalledWith( [ 'idle' ], true );
	} );

	test( 'a parent selects its idle children only, and a busy child has a disabled checkbox', () => {
		const set = jest.fn();
		const parent = fixtureRow( 'p1', 'Футболка', { has_children: true } );
		const idleChild = fixtureRow( 'v1', 'S' );
		const busyChild = fixtureRow( 'v2', 'M', { activity: running } );
		const model = withSelection( [ parent ], { p1: ready( [ idleChild, busyChild ] ) }, set );
		model.expanded = [ 'p1' ];
		const lines = buildLines( model );
		// [expand, select, …]
		const parentBox = checkboxOf( lines[ 0 ].cells, 1 );

		parentBox.click();

		expect( set ).toHaveBeenCalledWith( [ 'p1', 'v1' ], true );
		expect( checkboxOf( lines[ 2 ].cells, 1 ) ).toBeDisabled();
		expect( checkboxOf( lines[ 1 ].cells, 1 ) ).toBeEnabled();
	} );

	test( 'the bar sits in the actions column, never in a pinned one; an idle row has none', () => {
		const definition = photoFirst();
		const busy = fixtureRow( 'busy', 'Футболка', { activity: running } );
		const idle = fixtureRow( 'idle', 'Кружка' );
		const lines = buildLines( { ...input( definition, ready( [] ) ), rows: [ busy, idle ], expanded: [] } );
		const marked = ( at: number ) => drawn( lines[ at ].cells ).map( ( cell ) => cell.querySelector( '.woodev-records__row-busy' ) !== null );
		// The expand and select columns lead the declared ones.
		const lead = 1;
		const actionsAt = lead + definition.columns.findIndex( ( column ) => column.cell_type === 'actions' );

		expect( marked( 0 ).filter( Boolean ) ).toHaveLength( 1 );
		expect( marked( 0 )[ actionsAt ] ).toBe( true );
		expect( marked( 1 ).filter( Boolean ) ).toHaveLength( 0 );
	} );

	test( 'a table with no actions column carries the bar in its last column', () => {
		const definition = { ...photoFirst(), columns: photoFirst().columns.filter( ( column ) => column.cell_type !== 'actions' ) } as Definition;
		const busy = fixtureRow( 'busy', 'Футболка', { activity: running } );
		const lines = buildLines( { ...input( definition, ready( [] ) ), rows: [ busy ], expanded: [] } );
		const marked = drawn( lines[ 0 ].cells ).map( ( cell ) => cell.querySelector( '.woodev-records__row-busy' ) !== null );

		expect( marked.lastIndexOf( true ) ).toBe( marked.length - 1 );
	} );
} );
