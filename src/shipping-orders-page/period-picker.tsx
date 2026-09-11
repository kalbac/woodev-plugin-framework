/**
 * The «Период» control (#855) — this page's own picker, in the place
 * `DateRangeFilterPicker` used to hold.
 *
 * WHY IT IS OURS AND NOT WOOCOMMERCE'S. The page needs «Всё время» as its DEFAULT
 * period: a delivery-orders list is a work queue, and the order stuck since June is
 * exactly the one the merchant opens the page for. `DateRangeFilterPicker` cannot offer
 * it and cannot be made to — it builds its preset list from `@woocommerce/date`'s own
 * `presetValues` module export, not from a prop (`options: filter( presetValues, e =>
 * 'custom' !== e.value )`, read off the shipped bundle), so there is no seam to pass one
 * through. And the value could not be smuggled into the URL either: `getCurrentDates()`
 * throws `Cannot find period: all` for anything outside that same list, and the throw
 * takes the whole wc-admin app down rather than this one control.
 *
 * WHAT IS STILL WOOCOMMERCE'S. The calendar — `window.wc.components.DateRange`, which is
 * the two date inputs plus the two-month `DayPickerRangeController` and nothing else
 * (the presets and the «compare to» radios live in a sibling container inside
 * `DateRangeFilterPicker`, not in it). And the MARKUP: every class name below is
 * `FilterPicker`'s own, so the control sits in the filter row looking like the
 * «Перевозчик» picker beside it rather than like something we drew.
 *
 * NO «compare to». WooCommerce draws two radios there that would change nothing here —
 * this page compares no periods and never sends `compare` to its REST route. The URL
 * key still travels with every pick, for a reason that has nothing to do with the UI:
 * see `DEFAULT_COMPARE` in `./filters`.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Dropdown } from '@wordpress/components';
import {
	AFTER_PARAM,
	ALL_TIME_PERIOD,
	BEFORE_PARAM,
	CUSTOM_PERIOD,
	DEFAULT_DATE_RANGE,
	PERIOD_PRESETS,
	getPeriodFromQuery,
	periodQuery,
} from './filters';
import type { WcDateApi, WcQuery } from './filters';
import type { WcDateRangeUpdate, WcMomentLike } from './wc-globals';

/**
 * The `moment` format the two typed inputs use — it both prints and PARSES them
 * (`validateDateInputForRange( type, value, before, after, format )` inside `DateRange`),
 * so it is the format the merchant is expected to type in, not only to read.
 *
 * `DD.MM.YYYY` and not WooCommerce's own `MM/DD/YYYY`: this admin is Russian, and a
 * picker that accepts `09/12/2026` for «12 сентября» is a trap, not a default.
 */
export const SHORT_DATE_FORMAT = 'DD.MM.YYYY';

/**
 * `2026-02-01` → `01.02.2026`, for the toggle button's own label.
 *
 * Deliberately NOT `dateI18n()`: the value is a plain calendar DAY with no time and no
 * zone, and every date library that parses one has to invent a zone to do it — which is
 * how a range reads as a day short in one timezone and not in another. Reordering three
 * captured groups cannot do that. Anything that is not a plain ISO day comes back
 * unchanged rather than as «Invalid date».
 */
export function formatIsoDay( iso: string ): string {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( iso );

	return match ? `${ match[ 3 ] }.${ match[ 2 ] }.${ match[ 1 ] }` : iso;
}

/**
 * Days the calendar refuses, mirroring WooCommerce's own `isFutureDate` guard on the same
 * component — and mirroring it is the point rather than a preference: their
 * `validateDateInputForRange()` rejects a future date in the TYPED input regardless
 * (`moment().isBefore( date, 'day' )` → `future` error), so a calendar that let one be
 * CLICKED would accept what the input beside it refuses.
 *
 * Compared at day granularity, without `moment` — the calendar hands over a native
 * `Date`, and `window.moment` is `wc-date`'s own dependency, not a global this page
 * declares.
 */
export function isFutureDate( date: Date ): boolean {
	const endOfToday = new Date();
	endOfToday.setHours( 23, 59, 59, 999 );

	return date.getTime() > endOfToday.getTime();
}

/** The whole of `DateRange`'s controlled state, which this control owns on its behalf. */
interface CustomRangeState {
	after: WcMomentLike | null;
	before: WcMomentLike | null;
	afterText: string;
	beforeText: string;
	afterError: string | null;
	beforeError: string | null;
	focusedInput: 'startDate' | 'endDate';
}

/**
 * The custom range the dropdown OPENS on: whatever is already in the URL when the
 * merchant is standing in «Произвольный период», and an empty calendar otherwise.
 *
 * ⚠ The two `moment`s come from `getDateParamsFromQuery()` rather than being built here.
 * `DateRange` hands them straight to `DayPickerRangeController`, which calls real
 * `moment` methods on them (`clone()`, `isAfter()`, …), so they have to be the article —
 * and `@woocommerce/date` is the only place this bundle can get one, since `moment`
 * itself is `wc-date`'s dependency and not a handle this page declares.
 */
function initialCustomRange( dateApi: WcDateApi, query: WcQuery ): CustomRangeState {
	const empty: CustomRangeState = {
		after: null,
		before: null,
		afterText: '',
		beforeText: '',
		afterError: null,
		beforeError: null,
		focusedInput: 'startDate',
	};

	if ( CUSTOM_PERIOD !== getPeriodFromQuery( query ) ) {
		return empty;
	}

	const params = dateApi.getDateParamsFromQuery( query, DEFAULT_DATE_RANGE );

	if ( ! params.after || ! params.before ) {
		return empty;
	}

	return {
		...empty,
		after: params.after,
		before: params.before,
		afterText: params.after.format( SHORT_DATE_FORMAT ),
		beforeText: params.before.format( SHORT_DATE_FORMAT ),
	};
}

/**
 * What the toggle button says. One line, never two — `DateRangeFilterPicker`'s second
 * line was its «vs. Previous year» comparison, which this page does not make.
 */
export function periodButtonLabel( query: WcQuery ): string {
	const period = getPeriodFromQuery( query );

	if ( CUSTOM_PERIOD === period ) {
		return `${ formatIsoDay( query[ AFTER_PARAM ] || '' ) } — ${ formatIsoDay(
			query[ BEFORE_PARAM ] || ''
		) }`;
	}

	const preset = PERIOD_PRESETS.find( ( entry ) => entry.value === period );

	return preset ? preset.label : __( 'Всё время', 'woodev-plugin-framework' );
}

interface PeriodPickerContentProps {
	query: WcQuery;
	dateApi: WcDateApi;
	onSelect: ( patch: Record< string, string | undefined > ) => void;
	onClose: () => void;
}

/**
 * The dropdown's body: the list of periods, and — behind «Произвольный период» — the
 * calendar.
 *
 * Its own component so the calendar's half-entered state can live in a `useState` that is
 * born and dies with the dropdown. `Dropdown` unmounts `renderContent` on close, so
 * abandoning a half-picked range and reopening starts from the URL again, which is the
 * behaviour a merchant expects from a control that has an explicit «Применить».
 */
function PeriodPickerContent( { query, dateApi, onSelect, onClose }: PeriodPickerContentProps ) {
	const DateRange = window.wc?.components?.DateRange;
	const period = getPeriodFromQuery( query );
	const [ showCustom, setShowCustom ] = useState( CUSTOM_PERIOD === period );
	const [ range, setRange ] = useState< CustomRangeState >( () =>
		initialCustomRange( dateApi, query )
	);

	const pick = ( value: string ) => {
		onSelect( periodQuery( value ) );
		onClose();
	};

	if ( showCustom && DateRange ) {
		const applyCustom = () => {
			if ( ! range.after || ! range.before ) {
				return;
			}

			onSelect(
				periodQuery(
					CUSTOM_PERIOD,
					range.after.format( dateApi.isoDateFormat ),
					range.before.format( dateApi.isoDateFormat )
				)
			);
			onClose();
		};

		return (
			<div className="woodev-orders__period-custom">
				<DateRange
					after={ range.after }
					before={ range.before }
					afterText={ range.afterText }
					beforeText={ range.beforeText }
					afterError={ range.afterError }
					beforeError={ range.beforeError }
					focusedInput={ range.focusedInput }
					shortDateFormat={ SHORT_DATE_FORMAT }
					isInvalidDate={ isFutureDate }
					onUpdate={ ( update: WcDateRangeUpdate ) =>
						setRange( ( current ) => ( { ...current, ...update } ) )
					}
				/>
				<div className="woodev-orders__period-actions">
					<Button variant="tertiary" onClick={ () => setShowCustom( false ) }>
						{ __( 'Назад', 'woodev-plugin-framework' ) }
					</Button>
					{ /*
					 * ⚠ Disabled until BOTH dates are in hand, and that is a crash guard, not
					 * politeness: `getCurrentDates()` throws `Custom date range requires both
					 * after and before dates.` on a half-filled custom range, and the throw
					 * kills the whole wc-admin app. `./filters` refuses to write such a URL
					 * too — two guards, because this one can be reached with a keyboard.
					 */ }
					<Button variant="primary" onClick={ applyCustom } disabled={ ! range.after || ! range.before }>
						{ __( 'Применить', 'woodev-plugin-framework' ) }
					</Button>
				</div>
			</div>
		);
	}

	/**
	 * «Всё время» FIRST and the default, then WooCommerce's own presets in their own
	 * order, then «Произвольный период» last — the item that opens a calendar rather than
	 * picking a value belongs at the end, which is also where WooCommerce puts its own
	 * `custom`.
	 *
	 * The custom entry is dropped entirely when `wc.components.DateRange` is missing (an
	 * older WooCommerce): a menu item that opens nothing is worse than one absent option,
	 * and the ten presets beside it still work. Same degrade-rather-than-crash rule the
	 * rest of this page follows.
	 */
	const entries: { value: string; label: string; isCustom?: boolean }[] = [
		{ value: ALL_TIME_PERIOD, label: __( 'Всё время', 'woodev-plugin-framework' ) },
		...PERIOD_PRESETS,
		...( DateRange
			? [
					{
						value: CUSTOM_PERIOD,
						label: __( 'Произвольный период', 'woodev-plugin-framework' ),
						isCustom: true,
					},
			  ]
			: [] ),
	];

	const presets = entries.filter(
		( entry ) => ! entry.isCustom && entry.value !== ALL_TIME_PERIOD
	);
	const custom = entries.find( ( entry ) => entry.isCustom );
	const allTimeId = `woodev-period-${ ALL_TIME_PERIOD || 'all-time' }`;

	/**
	 * Three bands: «Всё время» across the top, the TEN presets in WooCommerce's two-column
	 * grid, «Произвольный период» across the bottom (operator, 12.09.2026). Both wide rows
	 * look the same — same fill, same hover, same height, text centred.
	 *
	 * ⚠ The grid holds EXACTLY the ten presets, and that is a correctness constraint, not
	 * tidiness. WooCommerce draws the column divider and the row rules by CHILD PARITY:
	 *
	 * ```css
	 * .woocommerce-segmented-selection__item:nth-child(2n)   { border-left: 1px solid #ccc; border-top: 1px solid #ccc }
	 * .woocommerce-segmented-selection__item:nth-child(2n+1) { border-top: 1px solid #ccc }
	 * .woocommerce-segmented-selection__item:nth-child(-n+2) { border-top: 0 }
	 * ```
	 *
	 * So a wide row INSIDE the container shifts every following item's parity by one: the
	 * left column takes the divider that belongs to the right one, and the top rules land a
	 * row out. That is exactly what shipped and what the operator caught. Ten items keep
	 * their parity honest and their whole stylesheet applies untouched — the markup is
	 * theirs, class for class, copied from the live DOM of their picker on
	 * `/analytics/orders` rather than guessed.
	 *
	 * The wide rows sit OUTSIDE the container for the same reason, and they need no borders
	 * of their own: the container already carries `border-top`/`border-bottom`, which is the
	 * rule between each wide row and the grid.
	 */
	return (
		<fieldset className="woocommerce-segmented-selection woodev-orders__period-selection">
			<legend className="screen-reader-text">
				{ __( 'Выберите период', 'woodev-plugin-framework' ) }
			</legend>

			<div className="woodev-orders__period-wide">
				<input
					className="woocommerce-segmented-selection__input"
					type="radio"
					name="woodev-orders-period"
					id={ allTimeId }
					checked={ ALL_TIME_PERIOD === period }
					onChange={ () => pick( ALL_TIME_PERIOD ) }
				/>
				<label htmlFor={ allTimeId }>
					<span className="woocommerce-segmented-selection__label">
						{ __( 'Всё время', 'woodev-plugin-framework' ) }
					</span>
				</label>
			</div>

			<div className="woocommerce-segmented-selection__container">
				{ presets.map( ( entry ) => {
					const id = `woodev-period-${ entry.value }`;

					return (
						<div className="woocommerce-segmented-selection__item" key={ id }>
							<input
								className="woocommerce-segmented-selection__input"
								type="radio"
								name="woodev-orders-period"
								id={ id }
								checked={ entry.value === period }
								onChange={ () => pick( entry.value ) }
							/>
							<label htmlFor={ id }>
								<span className="woocommerce-segmented-selection__label">
									{ entry.label }
								</span>
							</label>
						</div>
					);
				} ) }
			</div>

			{ /*
			 * A BUTTON, not a radio: it picks no value, it swaps the panel for a calendar.
			 * It carries WooCommerce's own `__label` class so the fill, the hover and the
			 * height are theirs rather than a second set of numbers that drifts from them —
			 * the operator's report was that it read as a stray link, taller than the rows
			 * above it, which is what a `Button` component's own metrics do here.
			 */ }
			{ custom && (
				<div className="woodev-orders__period-wide woodev-orders__period-wide--action">
					<button
						type="button"
						className="woocommerce-segmented-selection__label"
						onClick={ () => setShowCustom( true ) }
					>
						{ custom.label }
					</button>
				</div>
			) }
		</fieldset>
	);
}

export interface PeriodPickerProps {
	/** The current URL query — the control is URL-driven, like every other filter here. */
	query: WcQuery;
	/** `window.wc.date`; the caller has already established it exists. */
	dateApi: WcDateApi;
	/** Pushes a query patch into the URL — `wc.navigation.updateQueryString()` at the call site. */
	onUpdate: ( patch: Record< string, string | undefined > ) => void;
}

/**
 * The control itself: a labelled dropdown button in the filter row.
 *
 * ⚠ The markup is `FilterPicker`'s, element for element and class for class, read off the
 * shipped bundle (WooCommerce 10.9.4) rather than recalled: `.woocommerce-filters-filter`
 * wrapping a `.woocommerce-filters-label` and a `Dropdown` whose toggle is a
 * `.woocommerce-dropdown-button` holding its labels in a
 * `.woocommerce-dropdown-button__labels`. `DropdownButton` is a real `wc-components`
 * export and this could have used it; the six lines below are what it renders for a
 * single label, and writing them out means the control has no second way to fail when
 * that export is missing.
 */
export default function PeriodPicker( { query, dateApi, onUpdate }: PeriodPickerProps ) {
	return (
		<div className="woocommerce-filters-filter woodev-orders__period">
			<span className="woocommerce-filters-label">
				{ __( 'Период', 'woodev-plugin-framework' ) }:
			</span>
			<Dropdown
				contentClassName="woocommerce-filters-filter__content woodev-orders__period-content"
				popoverProps={ { placement: 'bottom' } }
				expandOnMobile
				headerTitle={ __( 'Период заказов', 'woodev-plugin-framework' ) }
				renderToggle={ ( { isOpen, onToggle }: { isOpen: boolean; onToggle: () => void } ) => (
					<Button
						className={ 'woocommerce-dropdown-button' + ( isOpen ? ' is-open' : '' ) }
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						<div className="woocommerce-dropdown-button__labels">
							<span>{ periodButtonLabel( query ) }</span>
						</div>
					</Button>
				) }
				renderContent={ ( { onClose }: { onClose: () => void } ) => (
					<PeriodPickerContent
						query={ query }
						dateApi={ dateApi }
						onSelect={ onUpdate }
						onClose={ onClose }
					/>
				) }
			/>
		</div>
	);
}
