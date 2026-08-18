/**
 * Tests for ControlField's `disabled` rendering (D11 — a blocked control is
 * always explained, never silently hidden or silently dead).
 *
 * First React test coverage `ControlField` has ever had — `@testing-library/react`
 * was installed specifically for it (see `docs-internal/plans/2026-08-18-shipping-settings-v2-plan.md`
 * Task 2). Real `@wordpress/components` renders here (installed as a devDependency);
 * production builds externalize the package to the `wp.components` global instead —
 * this only affects jest, never the shipped bundle.
 *
 * @see src/components/control-field.js
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import ControlField from '../../src/components/control-field';

test( 'a disabled checkbox renders disabled with the reason as description', () => {
	render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Скрывать адрес',
				disabled: true,
				disabled_reason: 'Недоступно на блочном чекауте',
				description: 'Недоступно на блочном чекауте',
			},
			value: true,
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();
	expect(
		screen.getByText( 'Недоступно на блочном чекауте' )
	).toBeInTheDocument();
} );

test( 'an enabled checkbox stays interactive', () => {
	render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Скрывать адрес',
			},
			value: false,
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( screen.getByRole( 'checkbox' ) ).not.toBeDisabled();
} );

test( 'a disabled text field renders its input disabled', () => {
	render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'text',
				name: 'Значение',
				disabled: true,
				description: 'Причина недоступности',
			},
			value: 'x',
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( screen.getByDisplayValue( 'x' ) ).toBeDisabled();
} );

test( 'a disabled select renders a disabled trigger that does not open its popover', async () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'select',
				name: 'Режим',
				disabled: true,
				description: 'Недоступно на блочном чекауте',
				options: { show: 'Показывать', hide: 'Скрывать' },
			},
			value: 'show',
			onChange: () => {},
			showErrors: false,
		} )
	);

	const trigger = container.querySelector( '.woodev-select__trigger' );
	expect( trigger ).toBeDisabled();

	trigger.click();

	// A disabled native <button> never dispatches click, so the popover never opens.
	expect(
		container.querySelector( '.woodev-select__popover' )
	).not.toBeInTheDocument();
	expect( screen.queryByRole( 'listbox' ) ).not.toBeInTheDocument();
} );

test( 'an enabled select stays a plain (non-disabled) trigger', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'select',
				name: 'Режим',
				options: { show: 'Показывать', hide: 'Скрывать' },
			},
			value: 'show',
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect(
		container.querySelector( '.woodev-select__trigger' )
	).not.toBeDisabled();
} );
