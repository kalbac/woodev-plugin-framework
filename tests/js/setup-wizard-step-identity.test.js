/**
 * Tests that the Setup Wizard gives each step its own component identity (#783).
 *
 * The wizard renders one `StepView` at a time, and `step-view.js` keys every field
 * by the BARE field id. Without a per-step key on `StepView` itself, two steps that
 * declare the same field id are the same element at the same position with the same
 * key, so React reuses the instance instead of remounting it. Every control that
 * seeds itself once on mount then shows the PREVIOUS step's content.
 *
 * `WizardRichText` is exactly such a control, and deliberately so: it writes `value`
 * into its contentEditable once in a `useEffect( …, [] )` and never feeds `value`
 * back on re-render, because React reconciling a contentEditable's children resets
 * the caret to the start on every keystroke. Seeding once is the FIX for that older
 * bug, not the defect — the defect was the missing identity, one level up. Anyone
 * tempted to "fix" this by re-seeding on every render is about to restore the caret
 * reset; that is why this file spells the reasoning out.
 *
 * Measured before the fix landed (s120): without the key, step two's editor renders
 * step one's content. With it, step two renders its own.
 *
 * Asserted on the RENDERED text of the editor, never on a prop or a stored value —
 * only what the merchant can actually see proves this.
 *
 * The advance here goes through «Пропустить», not «Продолжить»: skipping is a plain
 * synchronous step change, while the primary button awaits a REST save first. What
 * is under test is step identity, so the shorter path is the honest one and needs no
 * network double at all.
 *
 * @see src/setup-wizard/app.js
 * @see src/components/richtext.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import App from '../../src/setup-wizard/app';

const FIRST = 'СОДЕРЖИМОЕ ПЕРВОГО ШАГА';
const SECOND = 'СОДЕРЖИМОЕ ВТОРОГО ШАГА';

/**
 * Builds a two-step wizard bootstrap whose steps share ONE field id.
 *
 * A repeated id across steps is legal: `Setup_Wizard::register_step()` takes a list
 * of SETTING ids, and nothing stops two steps from listing the same setting. That is
 * what makes the reused instance reachable at all.
 *
 * @return {Object} the `window.woodevSetupWizard` payload.
 */
function twoStepsSharingAFieldId() {
	const richtext = ( value ) => ( {
		type: 'string',
		name: 'Описание',
		controlType: 'richtext',
		value,
	} );

	return {
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps: [
			{
				id: 'first',
				label: 'Первый шаг',
				type: 'settings',
				fields: { note: richtext( FIRST ) },
			},
			{
				id: 'second',
				label: 'Второй шаг',
				type: 'settings',
				fields: { note: richtext( SECOND ) },
			},
		],
	};
}

/**
 * The rendered contentEditable of the step currently on screen.
 *
 * @param {HTMLElement} container render container.
 * @return {HTMLElement} the editor node.
 */
function editor( container ) {
	return container.querySelector( '.woodev-field__richtext-editor' );
}

beforeEach( () => {
	window.location.hash = '';
	window.woodevSetupWizard = twoStepsSharingAFieldId();
} );

afterEach( () => {
	delete window.woodevSetupWizard;
} );

test( 'a second step declaring the same field id renders ITS OWN content, not the first step\'s', () => {
	const { container } = render( createElement( App ) );

	expect( editor( container ) ).toHaveTextContent( FIRST );

	fireEvent.click( screen.getByRole( 'button', { name: 'Пропустить' } ) );

	expect(
		container.querySelector( '.woodev-setup__step-title' )
	).toHaveTextContent( 'Второй шаг' );

	// The assertion the whole card is about. Without a per-step key this reads
	// FIRST — the mount-time seed of the reused instance.
	expect( editor( container ) ).toHaveTextContent( SECOND );
	expect( editor( container ) ).not.toHaveTextContent( FIRST );
} );

test( 'going back re-seeds the first step from its own value', () => {
	const { container } = render( createElement( App ) );

	fireEvent.click( screen.getByRole( 'button', { name: 'Пропустить' } ) );
	expect( editor( container ) ).toHaveTextContent( SECOND );

	fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );

	expect( editor( container ) ).toHaveTextContent( FIRST );
	expect( editor( container ) ).not.toHaveTextContent( SECOND );
} );
