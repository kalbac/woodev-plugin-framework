/**
 * The KPI tiles of the «Аналитика» tab (#711): WooCommerce's own `SummaryList` / `SummaryNumber`.
 *
 * Four tiles drive the chart (the selected one is underlined, like in WooCommerce's reports); the other two are plain
 * figures. A chart tile is a LINK to the same page with `chart=<metric>` (`href` from `getNewPath()`), the way WooCommerce's
 * own reports select a tile — not `onToggle`, which is the mobile open/close toggle of a collapsed list (a button with a chevron
 * and a shorter, clipped tile). The bad-outcome tile and the two time tiles use `reverseTrend`: a rise in returns is red, and a shorter delivery time is green (#1651). The time tile is honest about what it is,
 * with the sample size beside it: the order-to-delivery time, measured only where the order has a completion date — until the status log
 * (#1564) has seen an order from the carrier taking the parcel to the buyer receiving it; from then on the exact handover-to-delivery time.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { __, sprintf } from '@wordpress/i18n';
import { DASH, formatCount, formatDays, formatMoney, formatShare } from './format';
import type { WcQuery } from './wc-query';
import type { AnalyticsMetrics, AnalyticsResponse, ChartMetric } from './types';

interface TileSpec {
	key: string;
	chart: ChartMetric | null;
	label: string;
	value: ( metrics: AnalyticsMetrics, currency: string ) => string;
	reverseTrend?: boolean;
}

/** Whether the exact handover-to-delivery time has a sample, so its tile replaces the order-to-delivery one. */
function hasExactTime( data: AnalyticsResponse ): boolean {
	return data.totals.current.handover_sample > 0;
}

function tiles( data: AnalyticsResponse ): TileSpec[] {
	return [
		{ key: 'orders', chart: 'orders', label: __( 'Заказы', 'woodev-plugin-framework' ), value: ( m ) => formatCount( m.orders ) },
		{ key: 'revenue', chart: 'revenue', label: __( 'Выручка', 'woodev-plugin-framework' ), value: ( m, c ) => formatMoney( m.revenue, c ) },
		{
			key: 'shipping_paid',
			chart: 'shipping_paid',
			label: __( 'Оплачено за доставку', 'woodev-plugin-framework' ),
			value: ( m, c ) => formatMoney( m.shipping_paid, c ),
		},
		{ key: 'delivered_share', chart: 'delivered_share', label: __( 'Доставлено', 'woodev-plugin-framework' ), value: ( m ) => formatShare( m.delivered_share ) },
		{
			key: 'returned_share',
			chart: null,
			label: __( 'Отказы и возвраты', 'woodev-plugin-framework' ),
			value: ( m ) => formatShare( m.returned_share ),
			reverseTrend: true,
		},
		hasExactTime( data )
			? {
					key: 'avg_days_handover_to_delivery',
					chart: null,
					label: __( 'От передачи до вручения, дней', 'woodev-plugin-framework' ),
					value: ( m ) => formatDays( m.avg_days_handover_to_delivery ),
					reverseTrend: true,
			  }
			: {
					key: 'avg_days_to_delivery',
					chart: null,
					label: __( 'От заказа до вручения, дней', 'woodev-plugin-framework' ),
					value: ( m ) => formatDays( m.avg_days_to_delivery ),
					reverseTrend: true,
			  },
	];
}

/** The small line under a tile's number. */
function note( key: string, data: AnalyticsResponse ): string | null {
	const current = data.totals.current;

	if ( 'delivered_share' === key ) {
		return sprintf(
			/* translators: %s: how many orders are still on their way. */
			__( 'в работе: %s', 'woodev-plugin-framework' ),
			formatCount( current.outcomes.in_progress )
		);
	}

	if ( 'avg_days_handover_to_delivery' === key ) {
		return sprintf(
			/* translators: 1: delivered orders whose status log shows the handover and the delivery, 2: delivered orders in the period. */
			__( 'по %1$s из %2$s доставленных', 'woodev-plugin-framework' ),
			formatCount( data.coverage.handover_sample ),
			formatCount( data.coverage.delivered )
		);
	}

	if ( 'avg_days_to_delivery' === key ) {
		return null === current.avg_days_to_delivery
			? __( 'Нет данных: у доставленных заказов нет даты завершения', 'woodev-plugin-framework' )
			: sprintf(
					/* translators: 1: orders the average is based on, 2: delivered orders in the period. */
					__( 'по %1$s из %2$s доставленных', 'woodev-plugin-framework' ),
					formatCount( data.coverage.time_sample ),
					formatCount( data.coverage.delivered )
			  );
	}

	return null;
}

interface SummaryProps {
	data: AnalyticsResponse;
	metric: ChartMetric;
	/** The current `wc-admin` query and the path the tab is on: what a tile's link is built from. */
	query: WcQuery;
	path: string;
}

export function Summary( { data, metric, query, path }: SummaryProps ) {
	const SummaryList = window.wc?.components?.SummaryList;
	const SummaryNumber = window.wc?.components?.SummaryNumber;
	const getNewPath = window.wc?.navigation?.getNewPath;

	if ( ! SummaryList || ! SummaryNumber ) {
		return null;
	}

	const { current, previous, delta } = data.totals;

	return (
		<SummaryList>
			{ () =>
				tiles( data ).map( ( tile ) => {
					const text = note( tile.key, data );
					const chart = tile.chart;

					return (
						<SummaryNumber
							key={ tile.key }
							label={ tile.label }
							value={ tile.value( current, data.currency ) }
							prevValue={ previous ? tile.value( previous, data.currency ) : undefined }
							prevLabel={ __( 'Предыдущий период:', 'woodev-plugin-framework' ) }
							delta={ previous ? delta[ tile.key ] ?? undefined : undefined }
							reverseTrend={ tile.reverseTrend }
							selected={ null !== chart && chart === metric }
							// Without `getNewPath` (an older wc-navigation) a tile is a plain figure rather than a dead link.
							href={ null === chart || ! getNewPath ? undefined : getNewPath( { chart }, path, query ) }
						>
							{ null === text ? null : <span className="woodev-analytics__tile-note">{ text }</span> }
						</SummaryNumber>
					);
				} )
			}
		</SummaryList>
	);
}

export { DASH };
