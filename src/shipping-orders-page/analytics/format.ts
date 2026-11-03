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
