/**
 * Pins #515: «Сохранить» must reflect edits of the OPEN SECTION, not the
 * whole tab. Before this fix `hasChanges`/`onSave()` both read `edits[tab.id]`
 * — a flat, tab-wide map — so editing one section enabled every OTHER
 * section's Save button too, and saving from any one of them submitted
 * (and then wiped) every section's staged edits at once.
 *
 * WHY IT NEEDS A REAL RENDER. Like `settings-page-app-tools-section.test.js`,
 * the fix lives inside `App`'s `renderSection()`/`onSave()`, which pulls in
 * `@wordpress/data` / `@wordpress/notices` / REST fetches — a helper-level
 * test cannot see it. This file stubs the REST layer and renders `App` for
 * real, exactly like that sibling file (and for the same act()-safety
 * reasons: `fireEvent`, not a bare `.click()`).
 *
 * @see src/settings-page/app.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../../src/settings-page/app';
import { fetchSchema, saveTab } from '../../src/settings-page/rest';

// jsdom has no layout engine, so it logs "Not implemented: window.scrollTo"
// (via @wordpress/jest-console, that fails the test) the moment the success
// `<Notice>` this suite actually renders (unlike its sibling test files,
// which never reach a real save) runs its framer-motion enter animation —
// its layout measurement calls `window.scrollTo` from a rAF callback. A
// no-op stub is the standard jsdom workaround; it has nothing to do with
// this file's own `.scrollIntoView()` error-reveal effect.
window.scrollTo = () => {};

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

/**
 * One tab with two ordinary («fields») sections, each owning exactly one
 * plain text setting — enough to tell «this section's edits» apart from
 * «the sibling section's edits».
 *
 * @return {Object} one tab schema entry.
 */
function tabWithTwoFieldSections() {
	return {
		id: 'shipping',
		label: 'Доставка',
		capability: 'manage_woocommerce',
		sections: [
			{
				id: 'section_a',
				label: 'Раздел A',
				description: '',
				fields: {
					field_a: { id: 'field_a', type: 'string', controlType: 'text', name: 'Поле A', value: '' },
				},
			},
			{
				id: 'section_b',
				label: 'Раздел B',
				description: '',
				fields: {
					field_b: { id: 'field_b', type: 'string', controlType: 'text', name: 'Поле B', value: '' },
				},
			},
		],
	};
}

describe( 'Сохранить reflects the open section, not the whole tab (#515)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		fetchSchema.mockResolvedValue( { tabs: [ tabWithTwoFieldSections() ] } );
	} );

	it( 'disables a sibling section\'s Save even while this tab has a staged edit elsewhere', async () => {
		render( <App /> );

		// Section A opens first; stage an edit and confirm Save reacts to it.
		await waitFor( () => expect( screen.getByRole( 'textbox' ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'A edit' } } );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).not.toBeDisabled();

		// Switch to section B — same tab, no edits of its OWN.
		fireEvent.click( screen.getByText( 'Раздел B' ) );
		await waitFor( () => expect( screen.getByDisplayValue( '' ) ).toBeInTheDocument() );

		// Before the fix this read the whole tab's edits and stayed enabled.
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeDisabled();

		// Switching back: section A's own staged edit — and its enabled Save —
		// must still be there.
		fireEvent.click( screen.getByText( 'Раздел A' ) );
		await waitFor( () => expect( screen.getByDisplayValue( 'A edit' ) ).toBeInTheDocument() );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).not.toBeDisabled();
	} );

	it( 'saves only the open section\'s edits, and a sibling section\'s pending edit survives the save', async () => {
		saveTab.mockResolvedValue( { saved: true } );

		render( <App /> );

		await waitFor( () => expect( screen.getByRole( 'textbox' ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'A edit' } } );

		fireEvent.click( screen.getByText( 'Раздел B' ) );
		await waitFor( () => expect( screen.getByDisplayValue( '' ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'B edit' } } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		await waitFor( () => expect( saveTab ).toHaveBeenCalledTimes( 1 ) );
		// Only section B's own field reached the REST payload — section A's
		// staged edit must never leave the browser via B's Save button.
		expect( saveTab ).toHaveBeenCalledWith( 'shipping', { field_b: 'B edit' } );

		// The post-save refetch must not wipe section A's still-unsaved edit.
		await waitFor( () => expect( fetchSchema ).toHaveBeenCalledTimes( 2 ) );
		fireEvent.click( screen.getByText( 'Раздел A' ) );
		await waitFor( () => expect( screen.getByDisplayValue( 'A edit' ) ).toBeInTheDocument() );
	} );
} );
