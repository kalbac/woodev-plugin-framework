/**
 * The «Период» control of a table: a dropdown in the shape of WooCommerce's `FilterPicker` (the same class names, so it
 * looks like the pickers beside it), holding «all time», ten named periods and a custom range.
 *
 * It is the generic twin of the delivery page's own picker and imports nothing from it. Value in and out is a
 * `{ from?, to? }` range of `YYYY-MM-DD` days; the control keeps no value of its own, and every word is a prop.
 *
 * The custom range is WooCommerce's calendar (`window.wc.components.DateRange`, driven by `window.moment`). Without
 * either of them the dropdown offers two native date inputs instead, so a merchant is never left without a way to pick
 * a range.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType } from 'react';
import { Button, Dropdown } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { PRESET_IDS, matchPreset, presetRange, type PresetId } from './presets';

export interface PeriodValue {
	from?: string;
	to?: string;
}

export interface PeriodLabels {
	/** Label above the control. */
	label: string;
	/** Accessible name of the list of periods. */
	legend: string;
	/** «All time»: no bound at all. */
	all: string;
	presets: Record< PresetId, string >;
	custom: string;
	back: string;
	apply: string;
	/** The two native inputs' names, used only without the calendar. */
	from: string;
	to: string;
}

export interface PeriodPickerProps {
	value: PeriodValue;
	onChange: ( value: PeriodValue ) => void;
	labels: PeriodLabels;
	/** The day presets are counted from. The browser's today unless a test says otherwise. */
	today?: Date;
}

interface Moment {
	format: ( pattern: string ) => string;
}

interface MomentFactory {
	( input: string, pattern: string ): Moment;
}

interface CalendarState {
	after: Moment | null;
	before: Moment | null;
	afterText: string;
	beforeText: string;
	afterError: string | null;
	beforeError: string | null;
	focusedInput: 'startDate' | 'endDate';
}

type CalendarProps = CalendarState & {
	shortDateFormat: string;
	isInvalidDate: ( date: Date ) => boolean;
	onUpdate: ( update: Partial< CalendarState > ) => void;
};

interface Runtime {
	moment?: MomentFactory;
	wc?: { components?: { DateRange?: ComponentType< CalendarProps > } };
}

const ISO_DAY = 'YYYY-MM-DD';
const TYPED_DAY = 'DD.MM.YYYY';

function runtime(): Runtime {
	return window as unknown as Runtime;
}

/**
 * `2026-11-05` → `05.11.2026`. A string that is not a plain day comes back as it was.
 *
 * @param day A `YYYY-MM-DD` day.
 */
export function formatDay( day: string ): string {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( day );

	return match ? `${ match[ 3 ] }.${ match[ 2 ] }.${ match[ 1 ] }` : day;
}

/**
 * What the button says: the named period while the dates still are that period today, the dates themselves otherwise,
 * «all time» for no bound.
 *
 * @param value  The range.
 * @param labels The labels.
 * @param today  The day presets are counted from.
 */
export function periodButtonLabel( value: PeriodValue, labels: PeriodLabels, today: Date ): string {
	if ( ! value.from && ! value.to ) {
		return labels.all;
	}

	const preset = matchPreset( value, today );
	if ( preset ) {
		return labels.presets[ preset ];
	}

	return `${ value.from ? formatDay( value.from ) : '…' } — ${ value.to ? formatDay( value.to ) : '…' }`;
}

function isFuture( date: Date ): boolean {
	const endOfToday = new Date();
	endOfToday.setHours( 23, 59, 59, 999 );

	return date.getTime() > endOfToday.getTime();
}

function initialCalendar( value: PeriodValue ): CalendarState {
	const moment = runtime().moment;
	const parse = ( day?: string ) => ( day && moment ? moment( day, ISO_DAY ) : null );
	const after = parse( value.from );
	const before = parse( value.to );

	return {
		after,
		before,
		afterText: after ? after.format( TYPED_DAY ) : '',
		beforeText: before ? before.format( TYPED_DAY ) : '',
		afterError: null,
		beforeError: null,
		focusedInput: 'startDate',
	};
}

interface ContentProps {
	value: PeriodValue;
	labels: PeriodLabels;
	today: Date;
	onPick: ( value: PeriodValue ) => void;
}

/** The two native date inputs of a runtime without the calendar. */
function NativeRange( { value, labels, onPick }: Pick< ContentProps, 'value' | 'labels' | 'onPick' > ) {
	const [ from, setFrom ] = useState( value.from ?? '' );
	const [ to, setTo ] = useState( value.to ?? '' );
	const backwards = from !== '' && to !== '' && from > to;

	return (
		<div className="woodev-table-period__custom">
			<div className="woodev-table-period__native">
				<input type="date" aria-label={ labels.from } value={ from } onChange={ ( e ) => setFrom( e.target.value ) } />
				<input type="date" aria-label={ labels.to } value={ to } onChange={ ( e ) => setTo( e.target.value ) } />
			</div>
			<div className="woodev-table-period__actions">
				<Button
					variant="primary"
					disabled={ ( from === '' && to === '' ) || backwards }
					onClick={ () => onPick( { ...( from ? { from } : {} ), ...( to ? { to } : {} ) } ) }
				>
					{ labels.apply }
				</Button>
			</div>
		</div>
	);
}

function CalendarRange( {
	value,
	labels,
	onPick,
	onBack,
}: Pick< ContentProps, 'value' | 'labels' | 'onPick' > & { onBack: () => void } ) {
	const DateRange = runtime().wc?.components?.DateRange;
	const [ range, setRange ] = useState< CalendarState >( () => initialCalendar( value ) );

	if ( ! DateRange ) {
		return null;
	}

	// A half-filled range would make the table's URL unreadable, so it is never applied.
	const apply = () => {
		if ( range.after && range.before ) {
			onPick( { from: range.after.format( ISO_DAY ), to: range.before.format( ISO_DAY ) } );
		}
	};

	return (
		<div className="woodev-table-period__custom">
			<DateRange
				{ ...range }
				shortDateFormat={ TYPED_DAY }
				isInvalidDate={ isFuture }
				onUpdate={ ( update ) => setRange( ( current ) => ( { ...current, ...update } ) ) }
			/>
			<div className="woodev-table-period__actions">
				<Button variant="tertiary" onClick={ onBack }>
					{ labels.back }
				</Button>
				<Button variant="primary" disabled={ ! range.after || ! range.before } onClick={ apply }>
					{ labels.apply }
				</Button>
			</div>
		</div>
	);
}

/**
 * «Всё время», the ten periods, then the custom range. The periods sit in WooCommerce's two-column segmented list (their
 * stylesheet draws it, class for class); the two wide rows are outside the grid so its child parity stays right.
 */
function Content( { value, labels, today, onPick }: ContentProps ) {
	const { moment, wc } = runtime();
	const hasCalendar = Boolean( moment && wc?.components?.DateRange );
	const current = matchPreset( value, today );
	const isAll = ! value.from && ! value.to;
	const [ custom, setCustom ] = useState( false );

	if ( custom ) {
		return hasCalendar ? (
			<CalendarRange value={ value } labels={ labels } onPick={ onPick } onBack={ () => setCustom( false ) } />
		) : (
			<NativeRange value={ value } labels={ labels } onPick={ onPick } />
		);
	}

	return (
		<fieldset className="woocommerce-segmented-selection woodev-table-period__selection">
			<legend className="screen-reader-text">{ labels.legend }</legend>
			<div className="woodev-table-period__wide">
				<input
					className="woocommerce-segmented-selection__input"
					type="radio"
					name="woodev-table-period"
					id="woodev-table-period-all"
					checked={ isAll }
					onChange={ () => onPick( {} ) }
				/>
				<label htmlFor="woodev-table-period-all">
					<span className="woocommerce-segmented-selection__label">{ labels.all }</span>
				</label>
			</div>
			<div className="woocommerce-segmented-selection__container">
				{ PRESET_IDS.map( ( preset ) => (
					<div className="woocommerce-segmented-selection__item" key={ preset }>
						<input
							className="woocommerce-segmented-selection__input"
							type="radio"
							name="woodev-table-period"
							id={ `woodev-table-period-${ preset }` }
							checked={ current === preset }
							onChange={ () => onPick( presetRange( preset, today ) ) }
						/>
						<label htmlFor={ `woodev-table-period-${ preset }` }>
							<span className="woocommerce-segmented-selection__label">{ labels.presets[ preset ] }</span>
						</label>
					</div>
				) ) }
			</div>
			<div className="woodev-table-period__wide woodev-table-period__wide--action">
				<button type="button" className="woocommerce-segmented-selection__label" onClick={ () => setCustom( true ) }>
					{ labels.custom }
				</button>
			</div>
		</fieldset>
	);
}

export function PeriodPicker( { value, onChange, labels, today }: PeriodPickerProps ) {
	const day = today ?? new Date();

	return (
		<div className="woodev-table-period woocommerce-filters-filter">
			<span className="woodev-table-period__label">{ labels.label }</span>
			<Dropdown
				contentClassName="woocommerce-filters-filter__content woodev-table-period__content"
				popoverProps={ { placement: 'bottom-start' } }
				expandOnMobile
				headerTitle={ labels.label }
				renderToggle={ ( { isOpen, onToggle }: { isOpen: boolean; onToggle: () => void } ) => (
					<Button
						className={ 'woocommerce-dropdown-button' + ( isOpen ? ' is-open' : '' ) }
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						<div className="woocommerce-dropdown-button__labels">
							<span>{ periodButtonLabel( value, labels, day ) }</span>
						</div>
					</Button>
				) }
				renderContent={ ( { onClose }: { onClose: () => void } ) => (
					<Content
						value={ value }
						labels={ labels }
						today={ day }
						onPick={ ( next ) => {
							onChange( next );
							onClose();
						} }
					/>
				) }
			/>
		</div>
	);
}
