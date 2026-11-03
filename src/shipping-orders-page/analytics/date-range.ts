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

export const DEFAULT_DATE_RANGE = 'period=month&compare=previous_period';

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

function resolve( query: WcQuery ): AnalyticsDateRange | null {
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
