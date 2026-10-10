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

		expect( Array.from( items ).map( ( li ) => li.querySelector( '.woodev-stepper__label' ).textContent ) ).toEqual( [ 'Приветствие', 'Доставка', 'Готово' ] );
		expect( items[ 0 ] ).toHaveClass( 'is-done' );
		expect( items[ 1 ] ).toHaveClass( 'is-active' );
		expect( items[ 2 ] ).toHaveClass( 'is-upcoming' );
	} );

	test( 'the active step has aria-current="step" and every step states its state as text (#1047)', () => {
		const { container } = render( createElement( Stepper, { steps: STEPS, index: 1 } ) );

		const items = container.querySelectorAll( 'li' );

		expect( items[ 0 ] ).not.toHaveAttribute( 'aria-current' );
		expect( items[ 1 ] ).toHaveAttribute( 'aria-current', 'step' );
		expect( items[ 2 ] ).not.toHaveAttribute( 'aria-current' );
		expect( Array.from( items ).map( ( li ) => li.querySelector( '.woodev-stepper__status' ).textContent ) )
			.toEqual( [ 'Шаг пройден', 'Текущий шаг', 'Шаг ещё не пройден' ] );
		// The status is a sibling of the button, so it does not rename it.
		expect( screen.getByRole( 'button', { name: 'Приветствие' } ) ).toBeInTheDocument();
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

	test( 'canNavigate=false renders a step as a plain label; true keeps it a button', () => {
		const onNavigate = jest.fn();
		const { container } = render(
			createElement( Stepper, { steps: STEPS, index: 1, onNavigate, canNavigate: ( i ) => i < 1 } )
		);

		const items = container.querySelectorAll( 'li' );

		// Back to a completed step stays a button…
		expect( items[ 0 ].querySelector( 'button' ) ).not.toBeNull();
		// …forward (index 2) is a label: reachable only through the wizard's own «Далее».
		expect( items[ 2 ].querySelector( 'button' ) ).toBeNull();
		expect( items[ 2 ].querySelector( 'span.woodev-stepper__label' ) ).not.toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Готово' } ) ).toBeNull();
	} );

	test( 'the last step active marks every earlier one done', () => {
		const { container } = render( createElement( Stepper, { steps: STEPS, index: 2 } ) );

		const items = container.querySelectorAll( 'li' );

		expect( items[ 0 ] ).toHaveClass( 'is-done' );
		expect( items[ 1 ] ).toHaveClass( 'is-done' );
		expect( items[ 2 ] ).toHaveClass( 'is-active' );
	} );

	describe( 'shortLabel', () => {
		const LONG = [
			{ id: 'a', label: 'Подключение к сервису доставки', shortLabel: 'Связь' },
			{ id: 'b', label: 'Сопоставление статусов заказов', shortLabel: 'Статусы' },
			{ id: 'c', label: 'Итог' },
		];

		test( 'shows the short label when present and the full label otherwise', () => {
			const { container } = render( createElement( Stepper, { steps: LONG, index: 1 } ) );

			const visible = Array.from( container.querySelectorAll( '.woodev-stepper__label' ) )
				.map( ( el ) => el.querySelector( '[aria-hidden="true"]' )?.textContent ?? el.textContent );

			expect( visible ).toEqual( [ 'Связь', 'Статусы', 'Итог' ] );
		} );

		test( 'the full label stays the accessible name and the hover title of a button', () => {
			render( createElement( Stepper, { steps: LONG, index: 1 } ) );

			const button = screen.getByRole( 'button', { name: 'Подключение к сервису доставки' } );

			expect( button ).toHaveAttribute( 'title', 'Подключение к сервису доставки' );
			// The status marker is still a sibling, not part of the name.
			expect( button.parentElement.querySelector( '.woodev-stepper__status' ) ).not.toBeNull();
			expect( screen.queryByRole( 'button', { name: 'Связь' } ) ).toBeNull();
		} );

		test( 'a plain (non-button) step carries the full label as title and screen-reader text', () => {
			const { container } = render( createElement( Stepper, { steps: LONG, index: 1 } ) );

			const span = container.querySelectorAll( 'li' )[ 1 ].querySelector( 'span.woodev-stepper__label' );

			expect( span ).toHaveAttribute( 'title', 'Сопоставление статусов заказов' );
			expect( span.querySelector( '.screen-reader-text' ) ).toHaveTextContent( 'Сопоставление статусов заказов' );
		} );

		test( 'a step without a short label gets no title; one equal to the label is not doubled', () => {
			const steps = [ { id: 'a', label: 'Итог' }, { id: 'b', label: 'Готово', shortLabel: 'Готово' } ];
			const { container } = render( createElement( Stepper, { steps, index: 0 } ) );

			expect( container.querySelectorAll( '[title]' ) ).toHaveLength( 0 );
			expect( container.querySelector( '.screen-reader-text' ) ).toBeNull();
		} );
	} );
} );
