/**
 * Pins M3 (#505 round-1 critic finding, and N1 of the round-2 re-critic): the
 * «Инструменты» section must NOT render a «Сохранить» button.
 *
 * WHY IT NEEDS ITS OWN FILE. `settings-page-app.test.js` covers only the pure
 * helpers exported from `app.js`, and says so in its own header — `App` itself
 * pulls in `@wordpress/data`, `@wordpress/notices` and REST fetches. But the M3
 * fix lives inside `App`'s `renderSection()`, so a helper-level test cannot see
 * it: the re-critic proved that by mutating the guard away and watching all 1389
 * tests stay green. A fix the whole suite cannot tell from its own absence is the
 * exact failure mode round 1 was convened to punish, so this file stubs the REST
 * layer and renders `App` for real.
 *
 * WHY THE RULE. The section is defined by a contrast — the spec's opening line is
 * "One place … where **actions** live — as opposed to settings, which is what
 * every other section on that tab holds". It declares zero setting ids by
 * construction, so a control whose only meaning is "persist this section's
 * settings" can never mean anything there. The broader half of M3 — `hasChanges`
 * being computed per TAB, so one section's Save button reflects another
 * section's edits — is deliberately NOT fixed here and is tracked as issue #515.
 *
 * @see src/settings-page/app.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../../src/settings-page/app';
import { fetchSchema } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

// `@wordpress/data` and `@wordpress/notices` are deliberately NOT mocked. Both were
// tried and both fail at module load: replacing `@wordpress/data` wholesale breaks
// `@wordpress/components`, which imports `combineReducers` from it, and
// `jest.requireActual` on it trips its own circular initialisation. They work fine
// unmocked here because nothing in this file saves — `dispatch( noticesStore )` is
// only reached on a save round trip.

/**
 * A tab carrying both kinds of section, so the assertions below are a contrast
 * rather than an absence: «Поля» owns a setting and must keep its Save button,
 * «Инструменты» owns none and must not have one.
 *
 * @return {Object} one tab schema entry.
 */
function tabWithToolsAndFields() {
	return {
		id: 'shipping',
		label: 'Доставка',
		capability: 'manage_woocommerce',
		sections: [
			{
				id: 'fields',
				label: 'Поля',
				description: '',
				fields: {
					postcode_field: {
						id: 'postcode_field',
						type: 'select',
						label: 'Индекс',
						value: 'show',
						options: { show: 'Показывать', remove: 'Удалять' },
					},
				},
			},
			{
				id: 'tools',
				label: 'Инструменты',
				description: 'Действия над данными раздела «Доставка».',
				fields: {},
				is_tools: true,
				tools: [
					{
						id: 'popular_settlements_clear',
						name: 'Очистить список популярных городов',
						desc: 'Удаляет все сохранённые популярные населённые пункты выбранного провайдера.',
						button: 'Очистить',
						disabled: false,
						status_text: '',
						selector: null,
					},
				],
			},
		],
	};
}

describe( 'the «Инструменты» section renders no Сохранить button (#505 M3)', () => {
	beforeEach( () => {
		fetchSchema.mockResolvedValue( { tabs: [ tabWithToolsAndFields() ] } );
	} );

	it( 'renders Сохранить for a section that owns settings', async () => {
		// The control. Without it, the assertion below would also pass if the Save
		// button had simply stopped rendering everywhere.
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeInTheDocument()
		);
	} );

	it( 'renders NO Сохранить once the tools section is the open one', async () => {
		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Инструменты' ) ).toBeInTheDocument() );

		// fireEvent, not a bare .click(): testing-library wraps it in act(), and this
		// suite fails a test that logs a React act() warning (@wordpress/jest-console).
		fireEvent.click( screen.getByText( 'Инструменты' ) );

		await waitFor( () =>
			expect( screen.queryByRole( 'button', { name: 'Сохранить' } ) ).not.toBeInTheDocument()
		);
	} );
} );
