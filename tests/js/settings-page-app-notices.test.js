/**
 * Pins the save notices of the settings page (#1932): one snackbar per block, replaced on each save, dismissed by its
 * close button or its timer.
 *
 * WHY IT WAS BROKEN. Every save created its snackbar without an id, so the notices store gave each one a fresh generated
 * id and the list grew by one line per save. The fix keys the snackbar by block and replaces the block's previous one.
 *
 * `@wordpress/data` and `@wordpress/notices` are NOT mocked: the assertions read the real notices store, which is the
 * state the page renders from.
 *
 * @see src/settings-page/app.js (showBlockSnackbar)
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import App from '../../src/settings-page/app';
import { fetchSchema, saveTab } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

const SAVED = 'Настройки сохранены.';

/**
 * A text setting in a section. Typing into it stages an edit, which enables that section's Save button.
 *
 * @param {string} id    setting id.
 * @param {string} label visible label.
 * @return {Object} field schema.
 */
const textField = ( id, label ) => ( { id, type: 'text', label, value: '' } );

/**
 * A plain (non-stacked) tab with one section: one field, one Save button.
 *
 * @return {Object} tab schema.
 */
function singleSectionTab() {
	return {
		id: 'shipping',
		label: 'Доставка',
		capability: 'manage_woocommerce',
		sections: [
			{
				id: 'general',
				label: 'Общие',
				description: '',
				fields: { note_field: textField( 'note_field', 'Заметка' ) },
			},
		],
	};
}

/**
 * A tab whose two sections are stacked: each card has its own field and its own Save button, so each is a block.
 *
 * @return {Object} tab schema.
 */
function stackedTab() {
	return {
		id: 'shipping',
		label: 'Доставка',
		capability: 'manage_woocommerce',
		sections: [
			{
				id: 'first',
				label: 'Первый блок',
				stacked: true,
				description: '',
				fields: { first_field: textField( 'first_field', 'Первое' ) },
			},
			{
				id: 'second',
				label: 'Второй блок',
				stacked: true,
				description: '',
				fields: { second_field: textField( 'second_field', 'Второе' ) },
			},
		],
	};
}

/**
 * The snackbars currently in the notices store with the given text.
 *
 * @param {string} content notice text.
 * @return {Array} matching notices.
 */
function snackbarsWith( content ) {
	return select( noticesStore )
		.getNotices()
		.filter( ( n ) => 'snackbar' === n.type && n.content === content );
}

/**
 * Stages a value in the nth text input, clicks the nth Save button and waits until the page has run its success
 * handler. The notice is created just before the page refetches the schema, so the refetch is the marker.
 *
 * @param {number} index index of the text input and of the Save button (same order on the page).
 * @param {string} value the value to stage.
 */
async function saveBlock( index, value ) {
	const refetches = fetchSchema.mock.calls.length;
	fireEvent.change( screen.getAllByRole( 'textbox' )[ index ], { target: { value } } );
	fireEvent.click( screen.getAllByRole( 'button', { name: 'Сохранить' } )[ index ] );
	await waitFor( () => expect( fetchSchema.mock.calls.length ).toBe( refetches + 1 ) );
}

beforeEach( () => {
	saveTab.mockResolvedValue( {} );
} );

afterEach( () => {
	// The notices store is a module-wide singleton: clear what one test left for the next.
	select( noticesStore ).getNotices().forEach( ( n ) => dispatch( noticesStore ).removeNotice( n.id ) );
} );

describe( 'the settings page keeps one snackbar per block (#1932)', () => {
	beforeEach( () => {
		fetchSchema.mockResolvedValue( { tabs: [ singleSectionTab() ] } );
	} );

	it( 'two saves in a row leave one «Настройки сохранены.» snackbar, not two', async () => {
		render( <App /> );
		await screen.findByRole( 'textbox' );

		await saveBlock( 0, 'first' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );

		await saveBlock( 0, 'second' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );

		// The rendered list shows one line as well, once any exit animation of the replaced notice has finished.
		await waitFor( () =>
			expect( within( screen.getByTestId( 'snackbar-list' ) ).getAllByText( SAVED ) ).toHaveLength( 1 )
		);
	} );

	it( 'the close button of the snackbar removes it', async () => {
		render( <App /> );
		await screen.findByRole( 'textbox' );

		await saveBlock( 0, 'first' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );

		fireEvent.click(
			within( screen.getByTestId( 'snackbar-list' ) ).getByRole( 'button', { name: 'Dismiss this notice' } )
		);

		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 0 ) );
	} );

	it( 'a save replaces the notice with a new one, so the snackbar mounts afresh and its own dismiss timer starts', async () => {
		// The snackbar dismisses itself after NOTICE_TIMEOUT (6 s) from the moment it MOUNTS, and React keeps a mounted
		// snackbar by its notice id. Reusing one id would let the first save's timer dismiss the second save's text early;
		// a new id per save remounts it. The timer itself belongs to @wordpress/components and is not re-tested here.
		render( <App /> );
		await screen.findByRole( 'textbox' );

		await saveBlock( 0, 'first' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );
		const firstId = snackbarsWith( SAVED )[ 0 ].id;

		await saveBlock( 0, 'second' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );
		expect( snackbarsWith( SAVED )[ 0 ].id ).not.toBe( firstId );
	} );
} );

describe( 'a save in one block does not touch another block’s snackbar', () => {
	beforeEach( () => {
		fetchSchema.mockResolvedValue( { tabs: [ stackedTab() ] } );
	} );

	it( 'each block keeps its own single snackbar; a repeat save in one block replaces only that one', async () => {
		render( <App /> );
		await screen.findAllByRole( 'textbox' );

		await saveBlock( 0, 'a' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 1 ) );

		// The first block's snackbar stays until its own timer or close button removes it, exactly as before: a save in
		// another block does not remove it. The second block adds its own.
		await saveBlock( 1, 'b' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 2 ) );

		// A repeat save in the first block replaces its own snackbar and leaves the second one alone.
		await saveBlock( 0, 'c' );
		await waitFor( () => expect( snackbarsWith( SAVED ) ).toHaveLength( 2 ) );
	} );
} );
