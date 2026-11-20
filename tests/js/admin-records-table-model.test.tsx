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
import { buildLines, nameColumnIndex, type TableModelInput } from '../../src/admin-records/table-model';
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
