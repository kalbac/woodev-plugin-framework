/**
 * Tests the setup wizard's accessibility + honest-failure behaviour (#1047):
 * the stepper's current/done/upcoming state as text and `aria-current`, focus moving to
 * the new step's heading (never on first load), and a visible alert when persisting
 * «completed» (finish) or «skipped» (footer exit) fails.
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

function bootstrap() {
	return {
		pluginId: 'woodev_test',
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps: [
			{ id: 'welcome', label: 'Приветствие', type: 'content', content: '' },
			{ id: 'delivery', label: 'Доставка', type: 'settings', fields: {} },
			{ id: 'payment', label: 'Оплата', type: 'settings', fields: {} },
			{ id: 'finish', label: 'Готово', type: 'finish' },
		],
	};
}

async function next() {
	await act( async () => {
		fireEvent.click( document.querySelector( '.woodev-setup__primary' ) );
	} );
}

function exitLink() {
	return document.querySelector( '.woodev-setup__footer a' );
}

async function clickExit() {
	await act( async () => {
		fireEvent.click( exitLink() );
	} );
}

beforeEach( () => {
	window.location.hash = '';
	window.sessionStorage.clear();
	window.woodevSetupWizard = bootstrap();
	complete.mockReset();
	complete.mockImplementation( () => Promise.resolve( { complete: true } ) );
	jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.woodevSetupWizard;
} );

describe( 'stepper state for assistive tech', () => {
	test( 'only the current step carries aria-current="step"', async () => {
		const { container } = render( createElement( App ) );
		await next(); // «Доставка»

		const items = container.querySelectorAll( 'ol.woodev-stepper > li' );

		expect( items[ 0 ] ).not.toHaveAttribute( 'aria-current' );
		expect( items[ 1 ] ).toHaveAttribute( 'aria-current', 'step' );
		expect( items[ 2 ] ).not.toHaveAttribute( 'aria-current' );
		expect( items[ 3 ] ).not.toHaveAttribute( 'aria-current' );
	} );

	test( 'every step states done / current / upcoming as text, not by class alone', async () => {
		const { container } = render( createElement( App ) );
		await next(); // «Доставка»

		const statuses = Array.from( container.querySelectorAll( 'ol.woodev-stepper > li' ) )
			.map( ( li ) => li.querySelector( '.woodev-stepper__status' ).textContent );

		expect( statuses ).toEqual( [ 'Шаг пройден', 'Текущий шаг', 'Шаг ещё не пройден', 'Шаг ещё не пройден' ] );
	} );

	test( 'the status text does not leak into a step button\'s accessible name', async () => {
		render( createElement( App ) );
		await next();

		expect( screen.getByRole( 'button', { name: 'Приветствие' } ) ).toHaveClass( 'woodev-stepper__label' );
	} );
} );

describe( 'focus after step navigation', () => {
	test( 'the first render does not steal focus', () => {
		render( createElement( App ) );

		expect( document.body ).toHaveFocus();
	} );

	test( 'Continue moves focus to the new step heading, which is programmatically focusable', async () => {
		render( createElement( App ) );
		await next();

		const heading = document.querySelector( '.woodev-setup__step-title' );

		expect( heading ).toHaveTextContent( 'Доставка' );
		expect( heading ).toHaveAttribute( 'tabindex', '-1' );
		expect( heading ).toHaveFocus();
	} );

	test( 'Back and a stepper click each move focus to the heading they land on', async () => {
		render( createElement( App ) );
		await next();
		await next(); // «Оплата»

		await act( async () => {
			fireEvent.click( document.querySelector( '.woodev-setup__back' ) );
		} );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Доставка' );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveFocus();

		// Park focus elsewhere so the stepper click has to move it itself.
		document.querySelector( '.woodev-setup__primary' ).focus();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Приветствие' } ) );
		} );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveTextContent( 'Приветствие' );
		expect( document.querySelector( '.woodev-setup__step-title' ) ).toHaveFocus();
	} );

	test( 'the finish heading takes focus too', async () => {
		render( createElement( App ) );
		await next();
		await next();
		await next(); // finish

		expect( document.querySelector( '.woodev-setup__finish-title' ) ).toHaveFocus();
	} );
} );

describe( 'persistence failures are not silent', () => {
	test( 'a failed «completed» write shows an alert with a retry; success clears it', async () => {
		complete.mockImplementationOnce( () => Promise.reject( new Error( 'boom' ) ) );
		render( createElement( App ) );
		await next();
		await next();
		await next(); // finish → complete( 'completed' ) rejects

		const alert = screen.getByRole( 'alert' );
		expect( alert ).toHaveTextContent( 'отметить мастер завершённым не удалось' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );
		} );

		expect( complete ).toHaveBeenCalledTimes( 2 );
		expect( complete ).toHaveBeenLastCalledWith( 'completed' );
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	test( 'a successful «completed» write shows no alert', async () => {
		render( createElement( App ) );
		await next();
		await next();
		await next();

		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	test( 'a failed «skipped» write shows an alert and does not redirect; a second click leaves anyway', async () => {
		complete.mockImplementation( () => Promise.reject( new Error( 'boom' ) ) );
		// jsdom cannot observe a real navigation; a hash-only «admin URL» can be read back.
		window.woodevSetupWizard.adminUrl = '#left-the-wizard';
		render( createElement( App ) );
		const hashBefore = window.location.hash;

		await clickExit();

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Не удалось запомнить, что мастер пропущен' );
		expect( window.location.hash ).toBe( hashBefore );

		await clickExit();

		expect( window.location.hash ).toBe( '#left-the-wizard' );
		expect( complete ).toHaveBeenCalledTimes( 1 );
	} );
} );
