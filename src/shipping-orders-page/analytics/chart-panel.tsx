/**
 * The chart of the «Аналитика» tab (#711): WooCommerce's `Chart`, one series per carrier, the metric chosen by the
 * selected KPI tile. The interval picker and the line/bar toggle are `Chart`'s own header controls and write
 * `interval` / `chartType` to the URL through the `path` and `query` props (`handleTypeToggle()` emits
 * `{ chartType }` — read off the installed `wc-components` bundle, WC 11.2.1).
 *
 * **The chart is REMOUNTED whenever what it plots changes** ({@see chartKey()}). `Chart` initialises which legend items are visible
 * once, from the series that have a non-zero total, and rebuilds that only when the SET of series keys changes. A carrier that is all
 * zeros for one metric (a delivered share before anything was delivered) and positive for the next (orders), or in the previous
 * period and not in the current one, keeps the same key — so without a fresh instance it would stay hidden while the KPI tile shows its orders.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import { buildChartData, isChartEmpty } from './chart-data';
import { formatCount, formatMoney, formatShare } from './format';
import { PAGE_PATH, type WcQuery } from './wc-query';
import type { AnalyticsResponse, ChartMetric } from './types';

interface ChartPanelProps {
	data: AnalyticsResponse;
	metric: ChartMetric;
	query: WcQuery;
	loading: boolean;
}

function title( metric: ChartMetric ): string {
	switch ( metric ) {
		case 'revenue':
			return __( 'Выручка по перевозчикам', 'woodev-plugin-framework' );
		case 'shipping_paid':
			return __( 'Оплачено за доставку по перевозчикам', 'woodev-plugin-framework' );
		case 'delivered_share':
			return __( 'Доля доставленных по перевозчикам', 'woodev-plugin-framework' );
		default:
			return __( 'Заказы по перевозчикам', 'woodev-plugin-framework' );
	}
}

/** How a plotted value reads in the tooltip. A share is already a percentage (0–100) here. */
function tooltipFormat( metric: ChartMetric, currency: string ): ( value: number ) => string {
	if ( 'delivered_share' === metric ) {
		return ( value ) => formatShare( value / 100 );
	}

	return 'orders' === metric ? formatCount : ( value ) => formatMoney( value, currency );
}

/** Identity of what the chart plots: the metric, the answer itself (`generated_at` moves on a refresh), its period and interval, the carrier filter and the carriers drawn. */
export function chartKey( data: AnalyticsResponse, metric: ChartMetric, query: WcQuery ): string {
	return JSON.stringify( [ metric, data.generated_at, data.range, data.compare, query.carrier ?? 'all', data.carriers.map( ( carrier ) => carrier.id ) ] );
}

export function ChartPanel( { data, metric, query, loading }: ChartPanelProps ) {
	const Chart = window.wc?.components?.Chart;

	if ( ! Chart ) {
		return null;
	}

	const points = buildChartData( data.intervals, data.carriers, metric );

	return (
		<Chart
			key={ chartKey( data, metric, query ) }
			data={ points }
			title={ title( metric ) }
			mode="item-comparison"
			chartType={ 'bar' === query.chartType ? 'bar' : 'line' }
			interval={ data.range.interval }
			allowedIntervals={ [ 'day', 'week', 'month' ] }
			itemsLabel={ __( 'Перевозчики', 'woodev-plugin-framework' ) }
			emptyMessage={ isChartEmpty( points ) ? __( 'За этот период данных нет.', 'woodev-plugin-framework' ) : undefined }
			isRequesting={ loading && 0 === points.length }
			path={ PAGE_PATH }
			query={ query }
			legendPosition="bottom"
			tooltipValueFormat={ tooltipFormat( metric, data.currency ) }
		/>
	);
}
