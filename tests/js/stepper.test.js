/**
 * Tests for the shared progress-line stepper (#960, lifted from the setup wizard).
 *
 * The markup contract is what the setup wizard rendered before the lift — an <ol> of
 * equal-width steps, done/active/upcoming state classes, the current step a plain span
 * and every other step a button — so these pin it against the two hosts (the setup
 * wizard now, the order wizard next, #710).
 *
 * @see src/components/stepper.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import Stepper from '../../src/components/stepper';

const STEPS = [
	{ id: 'welcome', label: 'Приветствие' },
	{ id: 'delivery', label: 'Доставка' },
	{ id: 'finish', label: 'Готово' },
];

describe( 'Stepper', () => {
	test( 'renders one item per step, in order, with done / active / upcoming states', () => {
		const { container } = render( createElement( Stepper, { steps: STEPS, index: 1 } ) );

		const items = container.querySelectorAll( 'ol.woodev-stepper > li' );

		expect( Array.from( items ).map( ( li ) => li.textContent ) ).toEqual( [ 'Приветствие', 'Доставка', 'Готово' ] );
		expect( items[ 0 ] ).toHaveClass( 'is-done' );
		expect( items[ 1 ] ).toHaveClass( 'is-active' );
		expect( items[ 2 ] ).toHaveClass( 'is-upcoming' );
	} );

	test( 'the current step is a plain span; every other step is a button', () => {
		const { container } = render( createElement( Stepper, { steps: STEPS, index: 1 } ) );

		const items = container.querySelectorAll( 'li' );

		expect( items[ 1 ].querySelector( 'span.woodev-stepper__label' ) ).not.toBeNull();
		expect( items[ 1 ].querySelector( 'button' ) ).toBeNull();
		expect( items[ 0 ].querySelector( 'button.woodev-stepper__label' ) ).not.toBeNull();
		expect( items[ 2 ].querySelector( 'button.woodev-stepper__label' ) ).not.toBeNull();
	} );

	test( 'clicking a non-current step calls onNavigate with its index', () => {
		const onNavigate = jest.fn();
		render( createElement( Stepper, { steps: STEPS, index: 1, onNavigate } ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'Готово' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Приветствие' } ) );

		expect( onNavigate ).toHaveBeenNthCalledWith( 1, 2 );
		expect( onNavigate ).toHaveBeenNthCalledWith( 2, 0 );
	} );

	test( 'the current step is not a button, so it cannot navigate', () => {
		const onNavigate = jest.fn();
		render( createElement( Stepper, { steps: STEPS, index: 1, onNavigate } ) );

		expect( screen.queryByRole( 'button', { name: 'Доставка' } ) ).toBeNull();
	} );

	test( 'disabled makes every step button non-clickable', () => {
		const onNavigate = jest.fn();
		render( createElement( Stepper, { steps: STEPS, index: 0, onNavigate, disabled: true } ) );

		const button = screen.getByRole( 'button', { name: 'Готово' } );
		expect( button ).toBeDisabled();

		fireEvent.click( button );
		expect( onNavigate ).not.toHaveBeenCalled();
	} );

	test( 'a step button without an onNavigate handler is inert, not an error', () => {
		render( createElement( Stepper, { steps: STEPS, index: 0 } ) );

		expect( () => fireEvent.click( screen.getByRole( 'button', { name: 'Доставка' } ) ) ).not.toThrow();
	} );

	test( 'the last step active marks every earlier one done', () => {
		const { container } = render( createElement( Stepper, { steps: STEPS, index: 2 } ) );

		const items = container.querySelectorAll( 'li' );

		expect( items[ 0 ] ).toHaveClass( 'is-done' );
		expect( items[ 1 ] ).toHaveClass( 'is-done' );
		expect( items[ 2 ] ).toHaveClass( 'is-active' );
	} );
} );
