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
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import ControlField from '../../src/components/control-field';

/**
 * The settlement axis renders exactly what the SERVER offered it, and nothing else.
 *
 * There used to be a `getLiveSelectOptions()` here: issue #404 let the settlement axis
 * borrow the REGION axis's option set the moment the region chooser was set to
 * `related-list`, so a merchant saw «Предустановленный список» appear for НП before
 * saving. That whole rule is gone (operator decision, 24.08.2026) — the settlement axis
 * has exactly two modes and never a third.
 *
 * Removing the server half alone was NOT enough, which is why this test exists: the
 * server correctly sent two options while this client special case put the region's
 * three on screen, so the admin still offered «Предустановленный список» for НП. The
 * REST payload and the rendered control disagreed, and only the rendered control is what
 * anyone can see.
 *
 * The dropdown must be OPENED before asserting — its options live in a popover portal and
 * are simply absent from the DOM while it is closed, so an assertion against the closed
 * control passes whatever the option set is.
 */
test( 'the settlement chooser offers only the options the server sent, whatever the region axis is', async () => {
	const { container } = render(
		createElement( ControlField, {
			settingId: 'field_mode_settlement',
			schema: {
				type: 'string',
				name: 'Тип поля НП',
				controlType: 'select',
				options: {
					typeahead: 'Текст с подсказками',
					'ajax-select2': 'Список с поиском',
				},
			},
			value: 'ajax-select2',
			// The region axis sits at `related-list` — the exact state that used to inject
			// a third option into this control.
			conditionValues: { field_mode_region: 'related-list' },
			onChange: () => {},
			showErrors: false,
		} )
	);

	fireEvent.click( container.querySelector( '.woodev-select__trigger' ) );

	// The popover positions itself asynchronously — await it, or its state update lands
	// outside act() and @wordpress/jest-console fails the NEXT test in this file instead.
	await waitFor( () => {
		// Both real options are on screen, which is what makes the negative assertion
		// below meaningful rather than vacuous.
		expect( screen.getByText( 'Текст с подсказками' ) ).toBeTruthy();
	} );

	expect( screen.queryByText( 'Предустановленный список' ) ).toBeNull();
} );

/**
 * `disabled_reason` must never clobber the authored `description` — both are
 * legitimate at once: the description explains what the option does, the
 * reason explains why it is currently unavailable. Regression coverage for
 * the fix in `Field_Schema::from_handler()`: the two render as separate,
 * visually distinguishable notes.
 */
test( 'a disabled checkbox renders its description and disabled reason as separate notes', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Скрывать адрес',
				disabled: true,
				disabled_reason: 'Недоступно на блочном чекауте',
				description: 'Скрывает адрес получателя из письма на этапе оформления.',
			},
			value: true,
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();

	// The authored description survives, unmodified.
	expect(
		screen.getByText( 'Скрывает адрес получателя из письма на этапе оформления.' )
	).toBeInTheDocument();

	// The disabled reason renders too, in its own, visually distinguishable node.
	const reasonNode = container.querySelector( '.woodev-field__disabled-reason' );
	expect( reasonNode ).not.toBeNull();
	expect( reasonNode ).toHaveTextContent( 'Недоступно на блочном чекауте' );
} );

/**
 * Same contract for a control that goes through `withAnatomy`/`FieldRow`
 * (every non-checkbox control) rather than the checkbox branch's own markup.
 */
test( 'a disabled text field renders its description and disabled reason as separate notes', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'text',
				name: 'Значение',
				disabled: true,
				description: 'Что делает это поле.',
				disabled_reason: 'Недоступно в этом режиме.',
			},
			value: 'x',
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( screen.getByDisplayValue( 'x' ) ).toBeDisabled();
	expect( screen.getByText( 'Что делает это поле.' ) ).toBeInTheDocument();

	const reasonNode = container.querySelector( '.woodev-field__disabled-reason' );
	expect( reasonNode ).not.toBeNull();
	expect( reasonNode ).toHaveTextContent( 'Недоступно в этом режиме.' );
} );

/**
 * The checkbox/toggle branch renders its own label+description markup instead
 * of going through `withAnatomy`/`FieldRow`, so a `tooltip` declared on a
 * boolean setting used to render nothing at all. Regression coverage for the
 * fix: the tooltip affordance now shows next to the checkbox's label too.
 */
test( 'a boolean field with a tooltip shows the tooltip affordance next to its label', () => {
	render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Подсказки для адреса',
				tooltip: 'Показывать варианты адреса по мере ввода.',
			},
			value: false,
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect(
		screen.getByRole( 'img', { name: 'Показывать варианты адреса по мере ввода.' } )
	).toBeInTheDocument();
} );

test( 'a boolean field without a tooltip renders no tooltip affordance', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Подсказки для адреса',
			},
			value: false,
			onChange: () => {},
			showErrors: false,
		} )
	);

	expect( container.querySelector( '.woodev-field__tip' ) ).toBeNull();
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

/**
 * The `sensitive` branch in `ControlField` returns before the shared `disabled` computation
 * used to be reached — a disabled secret field rendered fully interactive (typeable input,
 * live «Очистить сохранённое» link). Regression coverage for that fix: `disabled` must now
 * reach `SecretControl`'s `PasswordControl` AND both clear affordances.
 */
test( 'a disabled sensitive field renders its password input disabled and its clear link inert', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'password',
				sensitive: true,
				is_set: true,
				name: 'API-ключ',
				disabled: true,
				description: 'Недоступно в этом режиме',
			},
			value: '',
			onChange: () => {},
			onRevert: () => {},
			showErrors: false,
		} )
	);

	expect( container.querySelector( '.woodev-field__password input' ) ).toBeDisabled();
	expect( container.querySelector( '.woodev-field__secret-clear-link' ) ).toBeDisabled();
} );

/**
 * A disabled field must not offer a one-click destructive action either: in the pending-clear
 * state (an explicit empty edit already staged against a stored secret) «Отменить» is still
 * shown — the notice stays honest about what will happen on Save — but rendered inert.
 */
test( 'a disabled sensitive field in the pending-clear state renders an inert «Отменить»', () => {
	render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'password',
				sensitive: true,
				is_set: true,
				name: 'API-ключ',
				disabled: true,
				description: 'Недоступно в этом режиме',
			},
			value: '',
			onChange: () => {},
			onRevert: () => {},
			hasEdit: true,
			showErrors: false,
		} )
	);

	expect(
		screen.getByText( 'Сохранённый секрет будет удалён при сохранении.' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Отменить' ) ).toBeDisabled();
} );

/**
 * Issue #373 — the operator's rule is `description` carries a clickable link
 * (e.g. "получить в личном кабинете"). Before this fix, `FieldRow` rendered
 * `description` as a plain text child, so React escaped any `<a>` tag into
 * literal `&lt;a href…` text instead of a real link. Regression coverage for
 * switching that render path to `RawHTML` (`src/components/field-row.js`).
 */
test( 'a description containing a link renders an actual anchor, not escaped markup', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'text',
				name: 'Токен API DaData',
				description: 'Получить токен можно в <a href="https://dadata.ru/profile/#info">личном кабинете DaData</a>.',
			},
			value: '',
			onChange: () => {},
			showErrors: false,
		} )
	);

	const link = container.querySelector( '.woodev-field__desc a[href="https://dadata.ru/profile/#info"]' );

	expect( link ).not.toBeNull();
	expect( link ).toHaveTextContent( 'личном кабинете DaData' );
	expect( container.querySelector( '.woodev-field__desc' ).innerHTML ).not.toContain( '&lt;a' );
} );

/**
 * Same fix, the checkbox/toggle branch (`ControlField`'s own markup, not
 * `FieldRow`) — issue #373 lists boolean fields too (though the fields this
 * task actually ships all use plain-text tooltips; this pins the mechanism
 * generically so a future boolean field CAN carry a link if it ever needs one).
 */
test( 'a boolean field description containing a link renders an actual anchor', () => {
	const { container } = render(
		createElement( ControlField, {
			schema: {
				type: 'boolean',
				controlType: 'checkbox',
				name: 'Пример',
				description: 'Подробнее — <a href="https://example.com/docs">в документации</a>.',
			},
			value: false,
			onChange: () => {},
			showErrors: false,
		} )
	);

	const link = container.querySelector( '.woodev-field__toggle-desc a[href="https://example.com/docs"]' );

	expect( link ).not.toBeNull();
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

/**
 * Issue #387: for a flat-array options list ( `set_options( [ 'red', 'green', 'blue' ] )` ),
 * the option's own string is both the value and the label — there is no separate machine
 * key. `normalizeOptions()` used to submit the array INDEX instead ( `String( i )` ), so
 * selecting "green" silently sent "1". `Woodev_Setting::get_validation_error()` (PHP) is
 * fixed to match: a flat list now validates strictly against its values, never its keys.
 *
 * Exercised via the `radio` control (native inputs, no popover portal) rather than
 * `select` — same `normalizeOptions()` call, simpler to interact with synchronously.
 */
test( 'a flat-array option submits its own value, not its array index', () => {
	let submitted;

	render(
		createElement( ControlField, {
			schema: {
				type: 'string',
				controlType: 'radio',
				name: 'Цвет',
				options: [ 'red', 'green', 'blue' ],
			},
			value: '',
			onChange: ( next ) => {
				submitted = next;
			},
			showErrors: false,
		} )
	);

	fireEvent.click( screen.getByLabelText( 'green' ) );

	expect( submitted ).toBe( 'green' );
} );
