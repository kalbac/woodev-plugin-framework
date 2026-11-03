/**
 * The «Аналитика» tab of the shipping orders page (#711).
 *
 * Owns the URL → request mapping and the four states (loading, error, empty, data); the drawing is `Filters`, `Summary`,
 * `ChartPanel` and `Tables`. Nothing here talks to the carriers: the figures are the aggregates of
 * `GET woodev/v1/shipping/orders/analytics` over orders that are already in the shop.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useMemo } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ChartPanel } from './chart-panel';
import { resolveDateRange } from './date-range';
import { Filters } from './filters';
import { Summary } from './summary';
import { Tables } from './tables';
import { useAnalytics } from './use-analytics';
import { navigate, PAGE_PATH, useWcQuery, type WcQuery } from './wc-query';
import { CHART_METRICS, type AnalyticsIntervalName, type AnalyticsRequest, type ChartMetric } from './types';

const INTERVALS: AnalyticsIntervalName[] = [ 'day', 'week', 'month' ];

function chartMetricOf( query: WcQuery ): ChartMetric {
	return CHART_METRICS.find( ( metric ) => metric === query.chart ) ?? 'orders';
}

function intervalOf( query: WcQuery ): AnalyticsRequest[ 'interval' ] {
	return INTERVALS.find( ( interval ) => interval === query.interval ) ?? 'auto';
}

interface AnalyticsTabProps {
	/**
	 * The `wc-admin` path this body is shown on. It is the orders page's «Аналитика» tab by default; the «Доставка перевозчиками» report
	 * in WooCommerce → Аналитика renders the SAME body on `/analytics/woodev-delivery` ({@see ./delivery-report}), and every control
	 * writes its parameters to the path it is on.
	 */
	path?: string;
}

export default function AnalyticsTab( { path = PAGE_PATH }: AnalyticsTabProps ) {
	const query = useWcQuery();
	const carrier = query.carrier || 'all';
	const metric = chartMetricOf( query );
	const interval = intervalOf( query );
	const { period, compare, after, before } = query;

	// The period is resolved only when one of its own URL keys changes, so a chart toggle does not refetch.
	const range = useMemo( () => resolveDateRange( query ), [ period, compare, after, before ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const request = useMemo< AnalyticsRequest | null >(
		() =>
			null === range
				? null
				: {
						after: range.after,
						before: range.before,
						compareAfter: range.compareAfter,
						compareBefore: range.compareBefore,
						interval,
						carrier,
				  },
		[ range, interval, carrier ]
	);

	const { data, loading, error, reload } = useAnalytics( request );
	const providers = window.woodevShippingOrders?.providers ?? [];
	const isEmpty = null !== data && 0 === data.totals.current.orders && 0 === ( data.totals.previous?.orders ?? 0 );

	return (
		<section className="woodev-analytics">
			<Filters range={ range } query={ query } carrier={ carrier } providers={ providers } loading={ loading } path={ path } onRefresh={ reload } />
			{ null === range && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Аналитика недоступна: эта версия WooCommerce не предоставляет нужные компоненты.', 'woodev-plugin-framework' ) }
				</Notice>
			) }
			{ null !== error && (
				<Notice status="error" isDismissible={ false }>
					{ error }{ ' ' }
					<Button variant="link" onClick={ reload }>
						{ __( 'Повторить', 'woodev-plugin-framework' ) }
					</Button>
				</Notice>
			) }
			{ null !== data && isEmpty && (
				<p className="woodev-analytics__empty">
					{ __( 'За выбранный период нет заказов с доставкой перевозчиками. Выберите период шире.', 'woodev-plugin-framework' ) }
				</p>
			) }
			{ null !== data && ! isEmpty && (
				<div className={ loading ? 'woodev-analytics__body is-loading' : 'woodev-analytics__body' }>
					<Summary data={ data } metric={ metric } onSelect={ ( next ) => navigate( { chart: next }, query, path ) } />
					<ChartPanel data={ data } metric={ metric } query={ query } loading={ loading } path={ path } />
					<Tables carriers={ data.carriers } methods={ data.methods } currency={ data.currency } loading={ loading } />
				</div>
			) }
		</section>
	);
}
