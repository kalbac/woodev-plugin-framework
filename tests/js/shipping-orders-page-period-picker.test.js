/**
 * Component tests for the page's own «Период» control (SP-10 #855).
 *
 * The subject is the CONTROL'S CONTRACT WITH THE URL: which entries it offers, what it
 * writes for each of them, and what it says on its own button when it reads one back.
 * Every assertion below therefore goes through the rendered DOM and through the query
 * patch handed to `onUpdate` — never through a helper's return value — because the two
 * defects this control exists to avoid are both visible only there: an option the
 * merchant cannot see, and a `period` value that crashes the whole wc-admin app when the
 * page reads it back.
 *
 * `window.wc.components.DateRange` is a real `@woocommerce/components` global at runtime
 * and is not installed in this repo, so it is faked — with the patch shapes read off the
 * shipped bundle (WooCommerce 10.9.4, `assets/client/admin/components/index.js`), not
 * imagined: `onDatesChange` emits `{ after, before, afterText, beforeText, afterError,
 * beforeError }`, `onInputChange` emits one side of that at a time, already validated
 * inside the component. A fake that got those wrong would let a green suite certify a
 * calendar whose «Применить» never enables.
 *
 * @see src/shipping-orders-page/period-picker.tsx
 */

import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import PeriodPicker from '../../src/shipping-orders-page/period-picker';

/**
 * A `moment` stand-in. `.format()` is the only method this control calls on one, and it
 * calls it with TWO different formats — the ISO one for the URL and the short one for the
 * inputs — so the fake has to honour its argument rather than return a fixed string.
 *
 * @param {string} iso   the ISO day, `YYYY-MM-DD`.
 * @param {string} short the same day, `DD.MM.YYYY`.
 * @return {Object} a moment-like object.
 */
function fakeMoment( iso, short ) {
	return { format: ( format ) => ( 'YYYY-MM-DD' === format ? iso : short ) };
}

/** The `window.wc.date` surface this control uses: moments out of a query, and the ISO format. */
const dateApi = {
	getDateParamsFromQuery: ( query ) => ( {
		period: query.period || 'year',
		compare: query.compare || 'previous_year',
		after: query.after ? fakeMoment( query.after, '01.02.2026' ) : null,
		before: query.before ? fakeMoment( query.before, '01.03.2026' ) : null,
	} ),
	getCurrentDates: () => ( {
		primary: { label: '', range: '', before: null, after: null },
		secondary: { label: '', range: '', before: null, after: null },
	} ),
	isoDateFormat: 'YYYY-MM-DD',
};

/**
 * Stands in for `DateRange`. It surfaces the props that carry a decision (the format the
 * merchant is expected to TYPE, whether future days are refused, the two current texts)
 * and exposes one button per half of a range, each emitting the patch the real component
 * emits for that action.
 *
 * @param {Object} props the real component's own props.
 * @return {JSX.Element} the fake calendar.
 */
function FakeDateRange( { afterText, beforeText, shortDateFormat, isInvalidDate, onUpdate } ) {
	const tomorrow = new Date();
	tomorrow.setDate( tomorrow.getDate() + 1 );

	return (
		<div
			data-testid="date-range"
			data-short-format={ shortDateFormat }
			data-after-text={ afterText }
			data-before-text={ beforeText }
			data-refuses-future={ String( Boolean( isInvalidDate && isInvalidDate( tomorrow ) ) ) }
		>
			<button
				onClick={ () =>
					onUpdate( {
						after: fakeMoment( '2026-02-01', '01.02.2026' ),
						afterText: '01.02.2026',
						afterError: null,
					} )
				}
			>
				Выбрать начало
			</button>
			<button
				onClick={ () =>
					onUpdate( {
						before: fakeMoment( '2026-03-01', '01.03.2026' ),
						beforeText: '01.03.2026',
						beforeError: null,
					} )
				}
			>
				Выбрать конец
			</button>
		</div>
	);
}

let onUpdate;

beforeEach( () => {
	onUpdate = jest.fn();
	window.wc = { components: { DateRange: FakeDateRange } };
} );

/**
 * One click, with the popover's own asynchronous re-layout flushed before the next
 * assertion runs.
 *
 * ⚠ `await act( async … )` and not a bare `act()`. `Dropdown` mounts a `Popover`, which
 * positions itself asynchronously and sets state when it lands. A synchronous click
 * returns before that, the state update happens outside `act()`, and
 * `@wordpress/jest-console` turns React's warning about it into a failure — of whichever
 * assertion runs next, which is what makes it read as an unrelated defect.
 *
 * @param {HTMLElement} element the element to click.
 */
async function click( element ) {
	await act( async () => {
		element.click();
	} );
}

/**
 * Renders the control and opens its dropdown, which is where every entry lives.
 *
 * @param {Object} query the URL query the control reads.
 */
async function open( query = {} ) {
	render( <PeriodPicker query={ query } dateApi={ dateApi } onUpdate={ onUpdate } /> );

	await click( screen.getByRole( 'button', { expanded: false } ) );
}

/**
 * The dropdown's entries, in order, as the merchant reads them.
 *
 * ⚠ Read from the RENDERED labels, not from any array the component was handed. The
 * preset block is WooCommerce's `SegmentedSelection` markup — radio inputs whose visible
 * text lives in a sibling `<label>` — and «Произвольный период» is a button BELOW that
 * grid, because it opens a calendar rather than picking a value. Both are entries the
 * merchant sees, so both belong in this list, in the order they appear.
 */
function entryLabels() {
	const presets = screen
		.getAllByRole( 'radio' )
		.map( ( input ) => input.labels[ 0 ].textContent.trim() );
	const custom = screen.queryByRole( 'button', { name: 'Произвольный период' } );

	return custom ? [ ...presets, custom.textContent.trim() ] : presets;
}

describe( 'the button label', () => {
	test( 'an empty query reads «Всё время» — the page default, stated on the control itself', () => {
		render( <PeriodPicker query={ {} } dateApi={ dateApi } onUpdate={ onUpdate } /> );

		expect( screen.getByRole( 'button' ) ).toHaveTextContent( 'Всё время' );
	} );

	test( 'a preset reads as its own Russian label', () => {
		render( <PeriodPicker query={ { period: 'last_week' } } dateApi={ dateApi } onUpdate={ onUpdate } /> );

		expect( screen.getByRole( 'button' ) ).toHaveTextContent( 'Прошлая неделя' );
	} );

	/** `DD.MM.YYYY`, not the ISO the URL carries and not WooCommerce's own `MM/DD/YYYY`. */
	test( 'a custom range reads as its two days, in the order this admin writes them', () => {
		render(
			<PeriodPicker
				query={ { period: 'custom', after: '2026-02-01', before: '2026-03-01' } }
				dateApi={ dateApi }
				onUpdate={ onUpdate }
			/>
		);

		expect( screen.getByRole( 'button' ) ).toHaveTextContent( '01.02.2026 — 01.03.2026' );
	} );

	/**
	 * ⚠ `period=all` is the value one would reach for to spell «всё время» by hand, and it
	 * is precisely the one that throws `Cannot find period: all` inside
	 * `@woocommerce/date` and takes the entire admin app down. The control must read it
	 * back as the default rather than echo it.
	 */
	test( 'an unrecognised period in the URL reads as «Всё время» rather than as itself', () => {
		render( <PeriodPicker query={ { period: 'all' } } dateApi={ dateApi } onUpdate={ onUpdate } /> );

		expect( screen.getByRole( 'button' ) ).toHaveTextContent( 'Всё время' );
	} );
} );

describe( 'the entries it offers', () => {
	test( '«Всё время» is FIRST and «Произвольный период» LAST, with the presets between', async () => {
		await open();

		expect( entryLabels() ).toEqual( [
			'Всё время',
			'Сегодня',
			'Вчера',
			'С начала недели',
			'Прошлая неделя',
			'С начала месяца',
			'Прошлый месяц',
			'С начала квартала',
			'Прошлый квартал',
			'С начала года',
			'Прошлый год',
			'Произвольный период',
		] );
	} );

	/**
	 * WooCommerce draws two «compare to» radios in this dropdown. They change nothing on
	 * this page — it compares no periods and never sends `compare` to its REST route — and
	 * a control that offers a choice it does not honour is a lie told in the UI. Asserting
	 * the absence is the only way to keep it absent, since it costs nothing to reintroduce
	 * by reaching for WooCommerce's own component again.
	 */
	test( 'no «compare to» control anywhere in the dropdown', async () => {
		await open();

		expect( screen.queryByText( /compare/i ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /сравн/i ) ).not.toBeInTheDocument();

		/**
		 * ⚠ This assertion used to read `queryAllByRole( 'radio' )` → length 0, which was a
		 * PROXY for «no compare block» and stopped being one the day the presets became
		 * WooCommerce's own `SegmentedSelection` — a radio group of their own. A proxy that
		 * silently starts measuring something else is worse than no test, so it now names
		 * the thing itself: every radio on screen belongs to the PERIOD group, and no
		 * `compare` group exists beside it.
		 */
		const groups = new Set( screen.getAllByRole( 'radio' ).map( ( input ) => input.name ) );

		expect( [ ...groups ] ).toEqual( [ 'woodev-orders-period' ] );
	} );

	test( 'the current period is the marked entry', async () => {
		await open( { period: 'quarter' } );

		const selected = screen
			.getAllByRole( 'radio' )
			.filter( ( input ) => input.checked )
			.map( ( input ) => input.labels[ 0 ].textContent.trim() );

		expect( selected ).toEqual( [ 'С начала квартала' ] );
	} );
} );

describe( 'what a pick writes into the URL', () => {
	/**
	 * Starts FROM a custom range, because the leftover dates are what this pins: standing
	 * in «Произвольный период» the control opens on the calendar, so reaching a preset
	 * from there goes through «Назад» — which is the route a merchant actually takes when
	 * they abandon a custom range for a preset.
	 */
	test( 'a preset writes period + compare and clears any leftover custom dates', async () => {
		await open( { period: 'custom', after: '2026-02-01', before: '2026-03-01' } );

		await click( screen.getByText( 'Назад' ) );
		await click( screen.getByText( 'Прошлый месяц' ) );

		expect( onUpdate ).toHaveBeenCalledWith( {
			period: 'last_month',
			compare: 'previous_year',
			after: undefined,
			before: undefined,
		} );
	} );

	/**
	 * «Всё время» is the ABSENCE of the params, never `period=all` — see the label test
	 * above for what writing a value would cost. `undefined` is how `addQueryArgs()` drops
	 * a key.
	 */
	test( '«Всё время» clears every date key instead of writing a value', async () => {
		await open( { period: 'year' } );

		await click( screen.getByText( 'Всё время' ) );

		expect( onUpdate ).toHaveBeenCalledWith( {
			period: undefined,
			compare: undefined,
			after: undefined,
			before: undefined,
		} );
	} );

	test( 'a preset pick closes the dropdown', async () => {
		await open();

		await click( screen.getByText( 'Сегодня' ) );

		expect( screen.queryByRole( 'listitem' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'the custom range', () => {
	test( '«Произвольный период» opens the calendar instead of writing anything', async () => {
		await open();

		await click( screen.getByText( 'Произвольный период' ) );

		expect( screen.getByTestId( 'date-range' ) ).toBeInTheDocument();
		expect( onUpdate ).not.toHaveBeenCalled();
	} );

	test( 'the calendar is told to parse and print DD.MM.YYYY, and to refuse future days', async () => {
		await open();

		await click( screen.getByText( 'Произвольный период' ) );

		expect( screen.getByTestId( 'date-range' ) ).toHaveAttribute( 'data-short-format', 'DD.MM.YYYY' );
		expect( screen.getByTestId( 'date-range' ) ).toHaveAttribute( 'data-refuses-future', 'true' );
	} );

	/**
	 * ⚠ The load-bearing guard. `getCurrentDates()` throws «Custom date range requires
	 * both after and before dates.» on a half-filled range, and the throw kills the whole
	 * wc-admin app — so a URL carrying one date must be unreachable from the control, not
	 * merely unlikely.
	 */
	test( '«Применить» stays disabled until BOTH days are in hand', async () => {
		await open();

		await click( screen.getByText( 'Произвольный период' ) );

		expect( screen.getByText( 'Применить' ) ).toBeDisabled();

		await click( screen.getByText( 'Выбрать начало' ) );

		expect( screen.getByText( 'Применить' ) ).toBeDisabled();

		await click( screen.getByText( 'Выбрать конец' ) );

		expect( screen.getByText( 'Применить' ) ).toBeEnabled();
	} );

	test( 'applying writes period=custom with both days in ISO and the compare beside them', async () => {
		await open();

		await click( screen.getByText( 'Произвольный период' ) );
		await click( screen.getByText( 'Выбрать начало' ) );
		await click( screen.getByText( 'Выбрать конец' ) );
		await click( screen.getByText( 'Применить' ) );

		expect( onUpdate ).toHaveBeenCalledWith( {
			period: 'custom',
			compare: 'previous_year',
			after: '2026-02-01',
			before: '2026-03-01',
		} );
	} );

	/** Standing in a custom range, opening the control starts from the range the URL holds. */
	test( 'a custom range already in the URL opens the calendar on it', async () => {
		await open( { period: 'custom', after: '2026-02-01', before: '2026-03-01' } );

		expect( screen.getByTestId( 'date-range' ) ).toHaveAttribute( 'data-after-text', '01.02.2026' );
		expect( screen.getByTestId( 'date-range' ) ).toHaveAttribute( 'data-before-text', '01.03.2026' );
	} );

	test( '«Назад» returns to the list without writing anything', async () => {
		await open();

		await click( screen.getByText( 'Произвольный период' ) );
		await click( screen.getByText( 'Назад' ) );

		expect( screen.queryByTestId( 'date-range' ) ).not.toBeInTheDocument();
		expect( entryLabels() ).toContain( 'Сегодня' );
		expect( onUpdate ).not.toHaveBeenCalled();
	} );
} );

describe( 'degrading rather than crashing', () => {
	/**
	 * An older WooCommerce may not export `DateRange`. The custom entry then goes away
	 * entirely — a menu item that opens nothing is worse than one absent option — and the
	 * ten presets beside it keep working, which is the same rule the rest of this page
	 * follows for a missing `wc` global.
	 */
	test( 'without wc.components.DateRange the custom entry is dropped and the presets still work', async () => {
		window.wc = { components: {} };

		await open();

		expect( entryLabels() ).not.toContain( 'Произвольный период' );
		expect( entryLabels() ).toContain( 'Всё время' );

		await click( screen.getByText( 'Прошлый год' ) );

		expect( onUpdate ).toHaveBeenCalledWith(
			expect.objectContaining( { period: 'last_year', compare: 'previous_year' } )
		);
	} );

	test( 'no wc global at all still renders the list', async () => {
		delete window.wc;

		await open();

		expect( entryLabels() ).toContain( 'Всё время' );
		expect( entryLabels() ).not.toContain( 'Произвольный период' );
	} );
} );
