/**
 * The tab's period (#711): `@woocommerce/date` resolves the URL's `period` / `compare` / `after` / `before` into the
 * two calendar ranges the REST route takes (`after`/`before` and `compare_after`/`compare_before`), so the server never
 * re-derives WooCommerce's period arithmetic.
 *
 * The default is «month to date» against the previous period. It is not «last 30 days»: WooCommerce has no such preset,
 * and `getCurrentDates()` THROWS for a period outside its list, which takes the whole wc-admin app down — so every
 * call here is guarded and falls back to the default.
 *
 * @package woodev-plugin-framework
 */

import type { WcDateParams } from '../wc-globals';
import type { WcQuery } from './wc-query';

/** What the comparison is when the URL names a period but no `compare`. */
export const DEFAULT_COMPARE = 'previous_period';

export const DEFAULT_DATE_RANGE = `period=month&compare=${ DEFAULT_COMPARE }`;

export interface AnalyticsDateRange {
	after: string;
	before: string;
	compareAfter: string | null;
	compareBefore: string | null;
	/** What `DateRangeFilterPicker` takes as `dateQuery`. */
	dateQuery: WcDateParams & {
		primaryDate: unknown;
		secondaryDate: unknown;
	};
}

/**
 * A URL that names a period but no `compare` (`period=custom&after=…&before=…`, as a link or a bookmark may carry it) is completed with the
 * default comparison. `getDateParamsFromQuery()` honours a query only when it has BOTH `period` and `compare` and otherwise discards the
 * lot for the default range (measured in the installed `@woocommerce/date`), so the merchant's custom range was silently replaced by
 * «this month». The picker always writes both; nothing else does.
 */
export function withComparison( query: WcQuery ): WcQuery {
	return query.period && ! query.compare ? { ...query, compare: DEFAULT_COMPARE } : query;
}

function resolve( rawQuery: WcQuery ): AnalyticsDateRange | null {
	const query = withComparison( rawQuery );
	const date = window.wc?.date;

	if ( ! date ) {
		return null;
	}

	const params = date.getDateParamsFromQuery( query, DEFAULT_DATE_RANGE );
	const { primary, secondary } = date.getCurrentDates( query, DEFAULT_DATE_RANGE );

	if ( ! primary.after || ! primary.before ) {
		return null;
	}

	const format = date.isoDateFormat;
	const compared = Boolean( secondary.after && secondary.before );

	return {
		after: primary.after.format( format ),
		before: primary.before.format( format ),
		compareAfter: compared ? secondary.after?.format( format ) ?? null : null,
		compareBefore: compared ? secondary.before?.format( format ) ?? null : null,
		dateQuery: { ...params, primaryDate: primary, secondaryDate: secondary },
	};
}

/** The period of the URL, or the default one when the URL carries something WooCommerce cannot resolve; null without `wc-date`. */
export function resolveDateRange( query: WcQuery ): AnalyticsDateRange | null {
	try {
		return resolve( query );
	} catch ( error ) {
		try {
			return resolve( {} );
		} catch ( fallbackError ) {
			return null;
		}
	}
}
