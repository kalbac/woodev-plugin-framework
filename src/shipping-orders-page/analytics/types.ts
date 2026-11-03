/**
 * The contract of `GET woodev/v1/shipping/orders/analytics` (#711, spec §4) — mirrors
 * `Analytics_Report::build()` and `Analytics_Aggregator::metrics()`.
 *
 * Every ratio is `null` when it is a ratio over nothing; the tab prints «—» for it, never `0`.
 *
 * @package woodev-plugin-framework
 */

export interface AnalyticsOutcomes {
	in_progress: number;
	delivered: number;
	returned: number;
	failed: number;
	cancelled: number;
	unknown: number;
}

export interface AnalyticsMetrics {
	orders: number;
	revenue: number;
	shipping_paid: number;
	avg_order: number | null;
	avg_shipping: number | null;
	shipping_share: number | null;
	outcomes: AnalyticsOutcomes;
	/** delivered + returned + failed — the denominator of the three shares. */
	resolved: number;
	delivered_share: number | null;
	returned_share: number | null;
	failed_share: number | null;
	avg_days_to_delivery: number | null;
	time_sample: number;
}

/** Percent change against the previous period, per metric; `null` when there is nothing to compare with. */
export type AnalyticsDelta = Record< string, number | null >;

export interface AnalyticsComparison {
	current: AnalyticsMetrics;
	previous: AnalyticsMetrics | null;
	delta: AnalyticsDelta;
}

export interface AnalyticsCarrier extends AnalyticsComparison {
	id: string;
	label: string;
}

export interface AnalyticsMethod {
	method_id: string;
	carrier_id: string;
	label: string;
	orders: number;
	lines: number;
	shipping_paid: number;
}

/** One chart point of one carrier (or of the totals). */
export interface AnalyticsSeries {
	orders: number;
	revenue: number;
	shipping_paid: number;
	delivered: number;
	resolved: number;
}

export interface AnalyticsInterval {
	start: string;
	end: string;
	totals: AnalyticsSeries;
	carriers: Record< string, AnalyticsSeries >;
}

export type AnalyticsIntervalName = 'day' | 'week' | 'month';

export interface AnalyticsResponse {
	range: { after: string; before: string; interval: AnalyticsIntervalName };
	compare: { after: string; before: string } | null;
	currency: string;
	totals: AnalyticsComparison;
	carriers: AnalyticsCarrier[];
	methods: AnalyticsMethod[];
	intervals: AnalyticsInterval[];
	previous_intervals: AnalyticsInterval[] | null;
	/** `delivered` orders in the period and how many of them the time metric could use. */
	coverage: { delivered: number; time_sample: number };
	generated_at: string;
	cached: boolean;
}

export interface AnalyticsRequest {
	after: string;
	before: string;
	compareAfter: string | null;
	compareBefore: string | null;
	/** `auto` lets the server pick from the range length. */
	interval: AnalyticsIntervalName | 'auto';
	/** A provider id, or `all`. */
	carrier: string;
}

/** The four metrics the chart can plot — the KPI tile that is selected decides. */
export type ChartMetric = 'orders' | 'revenue' | 'shipping_paid' | 'delivered_share';

export const CHART_METRICS: ChartMetric[] = [ 'orders', 'revenue', 'shipping_paid', 'delivered_share' ];
