/**
 * How the analytics tab prints numbers (#711): money, shares, days and counts, in the shop's currency and
 * Russian number format. A missing value is «—», never `0` and never `NaN`.
 *
 * `Intl` rather than `@woocommerce/currency`: the formatter has no runtime dependency to measure on every
 * WooCommerce version, and a share or a day count needs `Intl` anyway.
 *
 * @package woodev-plugin-framework
 */

export const DASH = '—';

const LOCALE = 'ru-RU';

function isNumber( value: number | null | undefined ): value is number {
	return 'number' === typeof value && Number.isFinite( value );
}

export function formatCount( value: number | null | undefined ): string {
	return isNumber( value ) ? new Intl.NumberFormat( LOCALE ).format( value ) : DASH;
}

export function formatMoney( value: number | null | undefined, currency: string ): string {
	if ( ! isNumber( value ) ) {
		return DASH;
	}

	try {
		return new Intl.NumberFormat( LOCALE, { style: 'currency', currency, maximumFractionDigits: 0 } ).format( value );
	} catch ( error ) {
		// An unknown currency code makes `Intl` throw — print the number and the code instead of crashing the page.
		return `${ new Intl.NumberFormat( LOCALE, { maximumFractionDigits: 0 } ).format( value ) } ${ currency }`;
	}
}

/** A ratio between 0 and 1 as a percentage. */
export function formatShare( ratio: number | null | undefined ): string {
	return isNumber( ratio )
		? new Intl.NumberFormat( LOCALE, { style: 'percent', maximumFractionDigits: 1 } ).format( ratio )
		: DASH;
}

/** A count of days with one decimal at most — «4,5». */
export function formatDays( value: number | null | undefined ): string {
	return isNumber( value ) ? new Intl.NumberFormat( LOCALE, { maximumFractionDigits: 1 } ).format( value ) : DASH;
}

/**
 * The chart's date labels in Russian (#1653). `Chart` formats its axis and tooltip dates with d3's `timeFormat`, whose built-in names are
 * English («Oct 2026», «October 15, 2026») and which has no locale prop — but `xFormat`, `x2Format`, `tooltipLabelFormat` and
 * `screenReaderFormat` each accept a FUNCTION taking a `Date` (`getFormatter()` in the installed `Chart`), so `Intl` supplies the names.
 * The same d3 shapes as the defaults: a day number, a month with year, a full date.
 */
const DAY = new Intl.DateTimeFormat( LOCALE, { day: '2-digit' } );
const MONTH = new Intl.DateTimeFormat( LOCALE, { month: 'short' } );
const FULL_DATE = new Intl.DateTimeFormat( LOCALE, { day: 'numeric', month: 'long', year: 'numeric' } );

export const chartDateFormats = {
	/** Axis tick: «01». */
	x: ( date: Date ): string => DAY.format( date ),
	/** Axis month line under the ticks: «окт. 2026». */
	x2: ( date: Date ): string => `${ MONTH.format( date ) } ${ date.getFullYear() }`,
	/** Tooltip title and screen-reader label: «15 октября 2026 г.». */
	full: ( date: Date ): string => FULL_DATE.format( date ),
};
