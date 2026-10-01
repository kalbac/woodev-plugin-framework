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
 * ''|completed|skipped option), so there is nothing to seed «visited» from: the boundary
 * lives in the tab's sessionStorage (keyed by the plugin id), and a reload resumes at the
 * hash step only up to that boundary — a deep link to an unvisited step lands on the
 * furthest visited one instead (round 2: it used to seed the boundary at the hash step).
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
		pluginId: 'woodev_test',
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
	window.sessionStorage.clear();
	window.woodevSetupWizard = bootstrap();
	complete.mockClear();
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.woodevSetupWizard;
} );

/**
 * The current step's title text.
 *
 * @return {string} title.
 */
function currentTitle() {
	return document.querySelector( '.woodev-setup__step-title' ).textContent;
}

/**
 * Fires the hashchange a browser raises on back/forward or a hand edit.
 *
 * @param {string} hash new hash, with the leading '#'.
 */
async function goToHash( hash ) {
	await act( async () => {
		window.location.hash = hash;
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
	} );
}

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

test( 'a deep link to the finish step lands on the last real step once it was visited', async () => {
	window.sessionStorage.setItem( 'woodevSetupWizard:visited:woodev_test', '2' );
	window.location.hash = '#finish-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Оплата' );
	expect( complete ).not.toHaveBeenCalled();
} );

test( 'a deep link to the finish step in a fresh tab lands on the first step', async () => {
	window.location.hash = '#finish-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Приветствие' );
	expect( complete ).not.toHaveBeenCalled();
} );

test( 'a fresh load at the hash of an unvisited ordinary step lands on the first step', async () => {
	window.location.hash = '#payment-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Приветствие' );
	expect( window.location.hash ).toBe( '#welcome-step' );
	// The boundary was not seeded at the hash step either.
	expect( stepperButton( 'Оплата' ) ).toBeNull();
	expect( stepperButton( 'Доставка' ) ).toBeNull();
} );

test( 'a deep link beyond the stored boundary lands on the furthest visited step', async () => {
	window.sessionStorage.setItem( 'woodevSetupWizard:visited:woodev_test', '1' );
	window.location.hash = '#payment-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Доставка' );
	expect( window.location.hash ).toBe( '#delivery-step' );
	expect( stepperButton( 'Оплата' ) ).toBeNull();
} );

test( 'a reload on an already visited step stays on it', async () => {
	const first = render( createElement( App ) );
	await next();
	await next(); // on «Оплата», hash #payment-step
	expect( window.location.hash ).toBe( '#payment-step' );
	first.unmount();

	render( createElement( App ) ); // the reload: fresh state, same tab, same hash
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Оплата' );
	expect( stepperButton( 'Приветствие' ) ).not.toBeNull();
	expect( stepperButton( 'Доставка' ) ).not.toBeNull();
	expect( stepperButton( 'Готово' ) ).toBeNull();
} );

test( 'a reload on an earlier step keeps the furthest boundary', async () => {
	const first = render( createElement( App ) );
	await next();
	await next(); // furthest = «Оплата»
	fireEvent.click( stepperButton( 'Доставка' ) );
	expect( window.location.hash ).toBe( '#delivery-step' );
	first.unmount();

	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Доставка' );
	expect( stepperButton( 'Оплата' ) ).not.toBeNull();
} );

test( 'boundaries of different plugins do not leak into each other', async () => {
	window.sessionStorage.setItem( 'woodevSetupWizard:visited:another_plugin', '2' );
	window.location.hash = '#payment-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Приветствие' );
} );

test( 'unavailable sessionStorage does not crash and nothing counts as visited', async () => {
	jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
		throw new Error( 'blocked' );
	} );
	jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
		throw new Error( 'blocked' );
	} );
	window.location.hash = '#payment-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Приветствие' );
	expect( stepperButton( 'Оплата' ) ).toBeNull();

	await next(); // the normal flow still works in-memory
	expect( currentTitle() ).toBe( 'Доставка' );
	expect( stepperButton( 'Приветствие' ) ).not.toBeNull();
} );

test( 'a garbage stored boundary is ignored', async () => {
	window.sessionStorage.setItem( 'woodevSetupWizard:visited:woodev_test', 'banana' );
	window.location.hash = '#payment-step';
	render( createElement( App ) );
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Приветствие' );
} );

test( 'browser back/forward (hashchange) obeys the visited gate', async () => {
	render( createElement( App ) );
	await next();
	await next(); // visited up to «Оплата»

	await goToHash( '#welcome-step' ); // back
	expect( currentTitle() ).toBe( 'Приветствие' );

	await goToHash( '#payment-step' ); // forward, still inside the visited range
	expect( currentTitle() ).toBe( 'Оплата' );

	await goToHash( '#delivery-step' ); // back again
	expect( currentTitle() ).toBe( 'Доставка' );

	await goToHash( '#finish-step' ); // forward onto finish: refused
	expect( currentTitle() ).toBe( 'Доставка' );
	expect( window.location.hash ).toBe( '#delivery-step' );
	expect( complete ).not.toHaveBeenCalled();
} );

test( 'history forward onto an unvisited step is refused', async () => {
	render( createElement( App ) );
	await next(); // visited up to «Доставка»

	await goToHash( '#payment-step' );

	expect( currentTitle() ).toBe( 'Доставка' );
	expect( window.location.hash ).toBe( '#delivery-step' );
} );

test( 'skipping the final real step reaches finish only through the normal flow', async () => {
	render( createElement( App ) );
	await next();
	await next(); // on «Оплата», the last real step

	expect( stepperButton( 'Готово' ) ).toBeNull();
	expect( complete ).not.toHaveBeenCalled();

	fireEvent.click( document.querySelector( '.woodev-setup__skip' ) );
	await act( async () => {} );

	expect( document.querySelector( '.woodev-setup__finish-title' ) ).not.toBeNull();
	expect( complete ).toHaveBeenCalledTimes( 1 );
	expect( complete ).toHaveBeenCalledWith( 'completed' );
	expect( stepperButton( 'Готово' ) ).toBeNull();
} );

test( 'a reload on the finish hash after completing resumes the last real step, not finish', async () => {
	const first = render( createElement( App ) );
	await next();
	await next();
	await next(); // finish, via the normal flow
	complete.mockClear();
	first.unmount();

	render( createElement( App ) ); // reload with #finish-step in the URL
	await act( async () => {} );

	expect( currentTitle() ).toBe( 'Оплата' );
	expect( complete ).not.toHaveBeenCalled();
} );
