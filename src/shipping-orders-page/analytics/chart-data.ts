/**
 * Turns the REST intervals into the points WooCommerce's `Chart` draws (#711): one point per interval,
 * one series per carrier, the series value chosen by the selected KPI tile.
 *
 * Pure, so the null-safety of `delivered_share` is pinned by a unit test instead of by looking at a chart.
 *
 * @package woodev-plugin-framework
 */

import type { AnalyticsCarrier, AnalyticsInterval, AnalyticsSeries, ChartMetric } from './types';

/** `Chart`'s data row: a `date` and one `{ label, value }` per series key (read off the component's README). */
export interface ChartPoint {
	date: string;
	[ series: string ]: string | { label: string; value: number };
}

/** The value of one series at one point; a share is a percentage and is 0 when nothing was resolved yet. */
export function seriesValue( series: AnalyticsSeries | undefined, metric: ChartMetric ): number {
	if ( ! series ) {
		return 0;
	}

	if ( 'delivered_share' === metric ) {
		return series.resolved > 0 ? Math.round( ( series.delivered / series.resolved ) * 1000 ) / 10 : 0;
	}

	return series[ metric ];
}

/**
 * The legend's per-carrier total for a RATIO metric: the share over the whole period — the very figure the carrier's own
 * KPI numbers use — instead of the sum of the per-interval percentages that `Chart` shows by default («2 977,8 %», #1652).
 * `undefined` for a countable metric, where the default sum is right.
 */
export function legendTotals( carriers: AnalyticsCarrier[], metric: ChartMetric ): Record< string, number > | undefined {
	if ( 'delivered_share' !== metric ) {
		return undefined;
	}

	return Object.fromEntries(
		carriers.map( ( carrier ) => [ carrier.label, Math.round( ( carrier.current.delivered_share ?? 0 ) * 1000 ) / 10 ] )
	);
}

/** `Chart` parses `YYYY-MM-DDTHH:mm:ss`. */
function chartDate( day: string ): string {
	return `${ day }T00:00:00`;
}

export function buildChartData(
	intervals: AnalyticsInterval[],
	carriers: AnalyticsCarrier[],
	metric: ChartMetric
): ChartPoint[] {
	return intervals.map( ( interval ) => {
		const point: ChartPoint = { date: chartDate( interval.start ) };

		for ( const carrier of carriers ) {
			point[ carrier.label ] = {
				label: carrier.label,
				value: seriesValue( interval.carriers[ carrier.id ], metric ),
			};
		}

		return point;
	} );
}

/** Whether there is anything to draw: every point of every series is zero. */
export function isChartEmpty( points: ChartPoint[] ): boolean {
	return points.every( ( point ) =>
		Object.values( point ).every( ( entry ) => 'string' === typeof entry || 0 === entry.value )
	);
}
