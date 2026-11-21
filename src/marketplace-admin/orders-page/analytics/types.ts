/**
 * The contract of `GET woodev/v1/admin/marketplace/{plugin id}/orders/analytics` (#1891) — mirrors
 * `Orders_Analytics_Report::build()` and `Orders_Analytics_Controller::get_report()`.
 *
 * Every ratio is `null` when it is a ratio over nothing; the tab prints «—» for it, never `0`.
 *
 * @package woodev-plugin-framework
 */

export interface AnalyticsMetrics {
	orders: number;
	cancelled: number;
	/** cancelled / orders. */
	cancel_share: number | null;
	/** Orders neither cancelled nor refunded. */
	sold: number;
	revenue: number;
	avg_order: number | null;
	units: number;
	units_per_order: number | null;
}

/** Percent change against the previous period, per metric; `null` when there is nothing to compare with. */
export type AnalyticsDelta = Record< string, number | null >;

export interface AnalyticsComparison {
	current: AnalyticsMetrics;
	previous: AnalyticsMetrics | null;
	delta: AnalyticsDelta;
}

export interface AnalyticsAccount extends AnalyticsComparison {
	id: string;
	label: string;
}

export interface AnalyticsSeries {
	orders: number;
	revenue: number;
}

export interface AnalyticsInterval {
	start: string;
	end: string;
	totals: AnalyticsSeries;
	/** By account id; an account with nothing in the interval is absent. */
	accounts: Record< string, AnalyticsSeries >;
}

export interface AnalyticsStatus {
	code: string;
	label: string;
	tone: string;
	count: number;
	accounts: Record< string, number >;
}

export interface AnalyticsStuckItem {
	order_id: number;
	/** The order's edit screen, or '' when the user may not edit it. */
	order_url: string;
	posting: string;
	account: string;
	status: string;
	hours: number | null;
}

export interface AnalyticsStuck {
	threshold_hours: number;
	count: number;
	items: AnalyticsStuckItem[];
	/** The sentence under the title, with the threshold in it. */
	hint: string;
	/** «Показаны самые старые: N из M.», or '' when every stuck posting is listed. */
	more: string;
}

export interface AnalyticsProduct {
	key: string;
	label: string;
	url: string;
	quantity: number;
	revenue: number;
}

export interface AnalyticsUnlinked {
	items: Array< { label: string; quantity: number; revenue: number } >;
	/** Different names sold without a shop product. */
	names: number;
	quantity: number;
	revenue: number;
}

export interface AnalyticsShare {
	shop_revenue: number;
	marketplace_revenue: number;
	share: number | null;
	previous: number | null;
	delta: number | null;
}

export type AnalyticsIntervalName = 'day' | 'week' | 'month';

export interface AnalyticsResponse {
	range: { after: string; before: string; interval: AnalyticsIntervalName };
	compare: { after: string; before: string } | null;
	/** `all` or the account id the figures are for. */
	account: string;
	/** The accounts the figures cover, in the filter's order. */
	accounts: Array< { id: string; label: string } >;
	/** Every account the filter offers. */
	options: Array< { id: string; label: string } >;
	totals: AnalyticsComparison;
	by_account: AnalyticsAccount[];
	intervals: AnalyticsInterval[];
	share: AnalyticsShare;
	statuses: AnalyticsStatus[];
	stuck: AnalyticsStuck;
	/** `top` holds the best sellers by units AND by revenue; the table sorts them. */
	products: { top: AnalyticsProduct[]; unlinked: AnalyticsUnlinked };
	currency: string;
	generated_at: string;
	cached: boolean;
}

export interface AnalyticsRequest {
	/** The marketplace plugin. */
	pluginId: string;
	after: string;
	before: string;
	compareAfter: string | null;
	compareBefore: string | null;
	/** `auto` lets the server pick from the range length. */
	interval: AnalyticsIntervalName | 'auto';
	/** An account id, or `all`. */
	account: string;
}

/** The two metrics the chart can plot; the selected tile decides. */
export type ChartMetric = 'orders' | 'revenue';

/** The words of the tab (`Orders_Analytics_Controller::messages()`). */
export type AnalyticsMessages = Record< string, string >;
