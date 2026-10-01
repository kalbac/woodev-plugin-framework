/**
 * Tests the setup wizard's stepper navigation gate (#110, item 2).
 *
 * The stepper used to render every non-current label as a button, so a merchant could
 * click straight past unvisited steps — onto the terminal finish step included, which
 * marks the wizard completed on mount. The rule now: back is always free; forward only
 * to steps already visited in this session; the finish step is never a stepper button
 * (it is reached only through the last real step's primary button). The `#{id}-step`
 * hash obeys the same gate.
 *
 * The server keeps no per-step completion state (only `get_state()`, a single
 * ''|completed|skipped option), so there is nothing to seed «visited» from: it lives
 * in memory for the session, and a reload resumes at the hash step.
 *
 * @see src/setup-wizard/app.js
 * @see src/components/stepper.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import App from '../../src/setup-wizard/app';
import { complete } from '../../src/setup-wizard/rest';

jest.mock( '../../src/setup-wizard/rest', () => ( {
	saveStep: jest.fn( () => Promise.resolve( { saved: true } ) ),
	complete: jest.fn( () => Promise.resolve( { complete: true } ) ),
} ) );

const LABELS = [ 'Приветствие', 'Доставка', 'Оплата', 'Готово' ];

/**
 * Four steps: welcome (content), two settings steps without fields, finish.
 * Settings steps with no fields validate trivially and save through the mock.
 *
 * @return {Object} the `window.woodevSetupWizard` payload.
 */
function bootstrap() {
	return {
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps: [
			{ id: 'welcome', label: LABELS[ 0 ], type: 'content', content: '' },
			{ id: 'delivery', label: LABELS[ 1 ], type: 'settings', fields: {} },
			{ id: 'payment', label: LABELS[ 2 ], type: 'settings', fields: {} },
			{ id: 'finish', label: LABELS[ 3 ], type: 'finish' },
		],
	};
}

/**
 * The stepper label of a step, as a button when it is one.
 *
 * @param {string} label step label.
 * @return {HTMLElement|null} the stepper button or null.
 */
function stepperButton( label ) {
	return screen
		.queryAllByRole( 'button', { name: label } )
		.find( ( el ) => el.classList.contains( 'woodev-stepper__label' ) ) || null;
}

/**
 * Presses the primary button and waits for the (mocked) save to settle.
 */
async function next() {
	await act( async () => {
		fireEvent.click( document.querySelector( '.woodev-setup__primary' ) );
	} );
}

beforeEach( () => {
	window.location.hash = '';
	window.woodevSetupWizard = bootstrap();
	complete.mockClear();
} );

afterEach( () => {
	delete window.woodevSetupWizard;
} );

test( 'on a fresh wizard no later step is a stepper button', () => {
	render( createElement( App ) );

	expect( stepperButton( 'Доставка' ) ).toBeNull();
	expect( stepperButton( 'Оплата' ) ).toBeNull();
	expect( stepperButton( 'Готово' ) ).toBeNull();
} );

test( 'a visited step becomes a button once the user has moved back from it', async () => {
	render( createElement( App ) );

	await next();
	await next(); // now on «Оплата»

	expect( stepperButton( 'Приветствие' ) ).not.toBeNull(); // back
	expect( stepperButton( 'Доставка' ) ).not.toBeNull(); // back

	fireEvent.click( stepperButton( 'Приветствие' ) );

	expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Приветствие' );
	// «Оплата» was visited, «Доставка» too: forward within the visited range works…
	expect( stepperButton( 'Оплата' ) ).not.toBeNull();
	// …but nothing beyond it does.
	expect( stepperButton( 'Готово' ) ).toBeNull();

	fireEvent.click( stepperButton( 'Оплата' ) );
	expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Оплата' );
} );

test( 'an unvisited step stays a plain label', async () => {
	render( createElement( App ) );

	await next(); // on «Доставка»; «Оплата» not visited

	expect( stepperButton( 'Оплата' ) ).toBeNull();
} );

test( 'the finish step is never a stepper button, even after it was reached', async () => {
	render( createElement( App ) );

	await next();
	await next();
	await next(); // finish, through the normal flow
	expect( document.querySelector( '.woodev-setup__finish-title' ) ).not.toBeNull();

	expect( stepperButton( 'Готово' ) ).toBeNull();
	fireEvent.click( stepperButton( 'Оплата' ) ); // back off the finish step
	expect( stepperButton( 'Готово' ) ).toBeNull();
} );

test( 'the finish step is not completed by clicking the stepper', async () => {
	render( createElement( App ) );

	expect( stepperButton( 'Готово' ) ).toBeNull();
	await act( async () => {} );

	expect( complete ).not.toHaveBeenCalled();
} );

test( 'a hand-edited hash cannot jump forward to an unvisited step or to finish', async () => {
	render( createElement( App ) );

	await act( async () => {
		window.location.hash = '#finish-step';
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
	} );

	expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Приветствие' );
	expect( window.location.hash ).toBe( '#welcome-step' );
	expect( complete ).not.toHaveBeenCalled();
} );

test( 'a deep link to the finish step lands on the last real step instead', async () => {
	window.location.hash = '#finish-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Оплата' );
	expect( complete ).not.toHaveBeenCalled();
} );
