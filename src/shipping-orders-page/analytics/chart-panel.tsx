/**
 * The chart of the «Аналитика» tab (#711): WooCommerce's `Chart`, one series per carrier, the metric chosen by the
 * selected KPI tile. The interval picker and the line/bar toggle are `Chart`'s own header controls and write
 * `interval` / `type` to the URL through the `path` and `query` props.
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

export function ChartPanel( { data, metric, query, loading }: ChartPanelProps ) {
	const Chart = window.wc?.components?.Chart;

	if ( ! Chart ) {
		return null;
	}

	const points = buildChartData( data.intervals, data.carriers, metric );

	return (
		<Chart
			data={ points }
			title={ title( metric ) }
			mode="item-comparison"
			chartType={ 'bar' === query.type ? 'bar' : 'line' }
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
