/**
 * The URL side of the «Аналитика» tab (#1891): which account, chart and interval the URL asks for, the request it leads to, and the
 * links from a status count to the «Заказы» tab.
 *
 * Every control of the tab lives in the URL like the controls of the «Заказы» tab, so a reload, a bookmark and Back show the same
 * figures; a tab switch drops these arguments ({@see ../route}).
 *
 * @package woodev-plugin-framework
 */

import { withQuery } from '../../../components/rest-url';
import type { AnalyticsDateRange } from '../../../shipping-orders-page/analytics/date-range';
import { MARKETPLACE_ARGUMENT, ORDERS_TAB, tabPatch } from '../route';
import type { AnalyticsIntervalName, AnalyticsRequest, ChartMetric } from './types';

type Query = Record< string, string | undefined >;

/** The URL argument of the account; absent means every account. */
export const ACCOUNT_ARGUMENT = 'account';

/** The account value of «Все аккаунты». */
export const ALL_ACCOUNTS = 'all';

const INTERVALS: AnalyticsIntervalName[] = [ 'day', 'week', 'month' ];

/**
 * @param query The route's query.
 * @return The account id the URL names, or {@see ALL_ACCOUNTS}.
 */
export function accountOf( query: Query ): string {
	const account = query[ ACCOUNT_ARGUMENT ];

	return account !== undefined && /^\d{1,19}$/.test( account ) ? account : ALL_ACCOUNTS;
}

/**
 * @param query The route's query.
 * @return The metric the chart plots: the selected tile's.
 */
export function chartMetricOf( query: Query ): ChartMetric {
	return query.chart === 'revenue' ? 'revenue' : 'orders';
}

/**
 * @param query The route's query.
 * @return The interval the chart's own picker chose, `auto` while none.
 */
export function intervalOf( query: Query ): AnalyticsRequest[ 'interval' ] {
	return INTERVALS.find( ( interval ) => interval === query.interval ) ?? 'auto';
}

/**
 * The request of the URL, or null while the period cannot be resolved (no `wc-date`).
 *
 * @param pluginId The marketplace plugin.
 * @param query    The route's query.
 * @param range    The period the URL resolves to.
 */
export function buildRequest( pluginId: string, query: Query, range: AnalyticsDateRange | null ): AnalyticsRequest | null {
	if ( range === null ) {
		return null;
	}

	return {
		pluginId,
		after: range.after,
		before: range.before,
		compareAfter: range.compareAfter,
		compareBefore: range.compareBefore,
		interval: intervalOf( query ),
		account: accountOf( query ),
	};
}

/**
 * The REST path of a request; `refresh=1` only when the merchant asked for a fresh read.
 *
 * @param request The request.
 * @param refresh Whether to skip the server's kept answer.
 */
export function analyticsPath( request: AnalyticsRequest, refresh: boolean ): string {
	const params = new URLSearchParams( { after: request.after, before: request.before, interval: request.interval } );

	if ( request.compareAfter && request.compareBefore ) {
		params.set( 'compare_after', request.compareAfter );
		params.set( 'compare_before', request.compareBefore );
	}

	if ( request.account !== ALL_ACCOUNTS ) {
		params.set( 'account', request.account );
	}

	if ( refresh ) {
		params.set( 'refresh', '1' );
	}

	return withQuery( `/woodev/v1/admin/marketplace/${ encodeURIComponent( request.pluginId ) }/orders/analytics`, params.toString() );
}

/**
 * The patch that opens the «Заказы» tab on one account's postings in one marketplace status, finished ones included («Состояние»
 * left at «Все») — the rows a status count stands for. It starts from the tab switch, so nothing of the analytics URL is left behind.
 *
 * @param query       The route's query.
 * @param marketplace The plugin id.
 * @param account     The account id.
 * @param status      The marketplace status code.
 */
export function statusPatch( query: Query, marketplace: string, account: string, status: string ): Query {
	return {
		...tabPatch( query, ORDERS_TAB ),
		[ MARKETPLACE_ARGUMENT ]: marketplace,
		[ ACCOUNT_ARGUMENT ]: account,
		filter_state: undefined,
		filter_posting_status: status,
	};
}

/**
 * The picker's identity: `DateRangeFilterPicker` copies `dateQuery` into its own state once, so it is remounted whenever the period
 * of the URL changes (Back / Forward), the way the delivery tab keys it.
 *
 * @param range The period.
 */
export function pickerKey( range: AnalyticsDateRange ): string {
	return JSON.stringify( [ range.dateQuery.period, range.dateQuery.compare, range.after, range.before, range.compareAfter, range.compareBefore ] );
}
