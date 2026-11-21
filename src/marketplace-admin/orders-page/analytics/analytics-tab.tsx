/**
 * The «Аналитика» tab of «Заказы маркетплейсов» (#1891), in the look of the delivery page's «Аналитика»: the filter row (the
 * marketplace, the account, WooCommerce's period picker with its comparison, «Обновить»), the tiles with the previous period,
 * the chart by account, then the tables.
 *
 * Owns the URL → request mapping and the states (loading keeps the old figures dimmed, an error offers a retry, an empty period
 * says so); the figures are the aggregates of `GET woodev/v1/admin/marketplace/{plugin id}/orders/analytics`. Every word comes from
 * PHP (`Orders_Analytics_Controller::messages()`); WooCommerce's components are read off `window.wc`.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, ReactNode } from 'react';
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useEffect, useMemo, useRef } from '@wordpress/element';
import { getNavigate } from '../../../admin-records/wc-runtime';
import { resolveDateRange } from '../../../shipping-orders-page/analytics/date-range';
import { chartDateFormats, formatCount, formatMoney, formatShare } from '../../../shipping-orders-page/analytics/format';
import { ACCOUNT_ARGUMENT, ALL_ACCOUNTS, accountOf, buildRequest, chartMetricOf, pickerKey } from './request';
import { NowTables, PeriodTables } from './tables';
import type { AnalyticsMessages, AnalyticsMetrics, AnalyticsResponse, ChartMetric } from './types';
import { useAnalytics } from './use-analytics';
import './style.scss';

type Query = Record< string, string | undefined >;

/** What the tab reads off `window.wc`: the subset of each component's README props it passes. */
interface AnalyticsRuntime {
	components?: {
		SummaryList?: ComponentType< { children: () => ReactNode } >;
		SummaryNumber?: ComponentType< {
			label: string;
			value?: string;
			prevValue?: string;
			prevLabel?: string;
			delta?: number;
			reverseTrend?: boolean;
			selected?: boolean;
			href?: string;
			children?: ReactNode;
		} >;
		Chart?: ComponentType< Record< string, unknown > >;
		DateRangeFilterPicker?: ComponentType< {
			dateQuery: object;
			isoDateFormat: string;
			onRangeSelect: ( selection: Query ) => void;
		} >;
	};
	navigation?: { getNewPath?: ( query: Query, path: string, current: Query ) => string };
	date?: { isoDateFormat?: string };
}

function runtime(): AnalyticsRuntime {
	return ( window as unknown as { wc?: AnalyticsRuntime } ).wc ?? {};
}

/** The unknown-account answer of the route: the URL names an account that is gone, so the tab goes back to every account. */
const UNKNOWN_ACCOUNT = 'woodev_marketplace_analytics_unknown_account';

interface Tile {
	key: string;
	chart: ChartMetric | null;
	label: string;
	value: ( metrics: AnalyticsMetrics ) => string;
	reverseTrend?: boolean;
	note?: string;
}

function tiles( data: AnalyticsResponse, m: AnalyticsMessages ): Tile[] {
	const money = ( value: number | null ) => formatMoney( value, data.currency );

	return [
		{ key: 'orders', chart: 'orders', label: m.orders, value: ( metrics ) => formatCount( metrics.orders ) },
		{ key: 'revenue', chart: 'revenue', label: m.revenue, value: ( metrics ) => money( metrics.revenue ) },
		{
			key: 'cancel_share',
			chart: null,
			label: m.cancelShare,
			value: ( metrics ) => formatShare( metrics.cancel_share ),
			reverseTrend: true,
			note: ( m.cancelledNote ?? '' ).replace( '%s', formatCount( data.totals.current.cancelled ) ),
		},
		{ key: 'avg_order', chart: null, label: m.avgOrder, value: ( metrics ) => money( metrics.avg_order ) },
		{ key: 'units_per_order', chart: null, label: m.unitsPerOrder, value: ( metrics ) => formatCount( metrics.units_per_order ) },
	];
}

interface SummaryProps {
	data: AnalyticsResponse;
	messages: AnalyticsMessages;
	metric: ChartMetric;
	path: string;
	query: Query;
}

/** WooCommerce's `SummaryList`: the tiles; a chart tile is a link that selects the chart's metric, as in WooCommerce's reports. */
export function Summary( { data, messages: m, metric, path, query }: SummaryProps ) {
	const { components, navigation } = runtime();
	const SummaryList = components?.SummaryList;
	const SummaryNumber = components?.SummaryNumber;

	if ( ! SummaryList || ! SummaryNumber ) {
		return null;
	}

	const { current, previous, delta } = data.totals;
	const share = data.share;
	const shareNote = data.account === ALL_ACCOUNTS ? ( m.shareNote ?? '' ).replace( '%s', formatMoney( share.shop_revenue, data.currency ) ) : m.shareAllNote;

	return (
		<SummaryList>
			{ () => [
				...tiles( data, m ).map( ( tile ) => (
					<SummaryNumber
						key={ tile.key }
						label={ tile.label }
						value={ tile.value( current ) }
						prevValue={ previous ? tile.value( previous ) : undefined }
						prevLabel={ m.previous }
						delta={ previous ? delta[ tile.key ] ?? undefined : undefined }
						reverseTrend={ tile.reverseTrend }
						selected={ tile.chart !== null && tile.chart === metric }
						href={ tile.chart === null || ! navigation?.getNewPath ? undefined : navigation.getNewPath( { chart: tile.chart }, path, query ) }
					>
						{ tile.note ? <span className="woodev-marketplace-analytics__tile-note">{ tile.note }</span> : null }
					</SummaryNumber>
				) ),
				<SummaryNumber
					key="share"
					label={ m.share }
					value={ formatShare( share.share ) }
					prevValue={ data.compare ? formatShare( share.previous ) : undefined }
					prevLabel={ m.previous }
					delta={ share.delta ?? undefined }
				>
					<span className="woodev-marketplace-analytics__tile-note">{ shareNote }</span>
				</SummaryNumber>,
			] }
		</SummaryList>
	);
}

/**
 * The series of the chart: one per account the figures cover, keyed by the account id (names are the merchant's and need not be
 * unique); a name two accounts share gets the account number beside it, so the legend tells them apart.
 */
export function chartSeries( data: AnalyticsResponse ): Array< { key: string; id: string; label: string } > {
	const seen = new Map< string, number >();

	data.accounts.forEach( ( account ) => seen.set( account.label, ( seen.get( account.label ) ?? 0 ) + 1 ) );

	return data.accounts.map( ( account ) => ( {
		key: `account-${ account.id }`,
		id: account.id,
		label: ( seen.get( account.label ) ?? 0 ) > 1 ? `${ account.label } (#${ account.id })` : account.label,
	} ) );
}

/** The chart's points: one per interval, one series per account. */
export function chartPoints( data: AnalyticsResponse, metric: ChartMetric ): Array< Record< string, unknown > > {
	const series = chartSeries( data );

	return data.intervals.map( ( interval ) => {
		const point: Record< string, unknown > = { date: `${ interval.start }T00:00:00` };

		series.forEach( ( entry ) => {
			point[ entry.key ] = { label: entry.label, value: interval.accounts[ entry.id ]?.[ metric ] ?? 0 };
		} );

		return point;
	} );
}

/**
 * The chart, remounted whenever what it plots changes: WooCommerce's `Chart` decides once which legend items are visible, so a
 * series that was all zeros for the last metric would stay hidden (the delivery tab's `chartKey()`).
 */
function ChartPanel( { data, messages: m, metric, path, query, loading }: SummaryProps & { loading: boolean } ) {
	const Chart = runtime().components?.Chart;

	if ( ! Chart ) {
		return null;
	}

	const points = chartPoints( data, metric );
	const empty = points.every( ( point ) => Object.values( point ).every( ( entry ) => typeof entry === 'string' || ( entry as { value: number } ).value === 0 ) );

	return (
		<Chart
			key={ JSON.stringify( [ metric, data.generated_at, data.range, data.compare, data.accounts.map( ( account ) => account.id ) ] ) }
			data={ points }
			title={ metric === 'revenue' ? m.chartRevenue : m.chartOrders }
			mode="item-comparison"
			chartType={ query.chartType === 'bar' ? 'bar' : 'line' }
			interval={ data.range.interval }
			allowedIntervals={ [ 'day', 'week', 'month' ] }
			itemsLabel={ m.chartItems }
			emptyMessage={ empty ? m.chartEmpty : undefined }
			isRequesting={ loading && points.length === 0 }
			path={ path }
			query={ query }
			legendPosition="bottom"
			tooltipValueFormat={ metric === 'revenue' ? ( value: number ) => formatMoney( value, data.currency ) : formatCount }
			xFormat={ chartDateFormats.x }
			x2Format={ chartDateFormats.x2 }
			tooltipLabelFormat={ chartDateFormats.full }
			screenReaderFormat={ chartDateFormats.full }
		/>
	);
}

export interface AnalyticsTabProps {
	/** The plugin id whose orders are shown. */
	marketplace: string;
	messages: AnalyticsMessages;
	/** The page's `wc-admin` path. */
	path: string;
	query: Query;
	/** The marketplace select, first in the filter row (absent with one marketplace). */
	leading?: ReactNode;
}

export function AnalyticsTab( { marketplace, messages: m, path, query, leading }: AnalyticsTabProps ) {
	const { period, compare, after, before } = query;
	// Resolved only when the period's own keys change, so a chart toggle does not refetch.
	const range = useMemo( () => resolveDateRange( query ), [ period, compare, after, before ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const request = useMemo( () => buildRequest( marketplace, query, range ), [ marketplace, range, query.interval, query[ ACCOUNT_ARGUMENT ] ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const { data, loading, error, errorCode, reload } = useAnalytics( request, m.loadFailed );
	const options = useRef< AnalyticsResponse[ 'options' ] >( [] );
	const metric = chartMetricOf( query );
	const account = accountOf( query );
	const { components, date } = runtime();
	const DateRangeFilterPicker = components?.DateRangeFilterPicker;

	if ( data !== null ) {
		options.current = data.options;
	}

	useEffect( () => {
		if ( errorCode === UNKNOWN_ACCOUNT ) {
			getNavigate()?.( { [ ACCOUNT_ARGUMENT ]: undefined } );
		}
	}, [ errorCode ] );

	const empty = data !== null && data.totals.current.orders === 0 && ( data.totals.previous?.orders ?? 0 ) === 0;
	const tableProps = data === null ? null : { data, messages: m, loading, marketplace, path, query };

	return (
		<div className="woodev-records woodev-marketplace-analytics">
			<p className="woodev-records__description">{ m.intro }</p>
			<div className="woodev-records__filters">
				<div className="woodev-records__toolbar woodev-marketplace-analytics__filters">
					{ leading }
					{ options.current.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ m.account }
							value={ account }
							options={ [
								{ value: ALL_ACCOUNTS, label: m.allAccounts },
								...options.current.map( ( option ) => ( { value: option.id, label: option.label } ) ),
							] }
							onChange={ ( next: string ) => getNavigate()?.( { [ ACCOUNT_ARGUMENT ]: next === ALL_ACCOUNTS ? undefined : next } ) }
						/>
					) }
					{ DateRangeFilterPicker && range && date?.isoDateFormat && (
						<DateRangeFilterPicker
							key={ pickerKey( range ) }
							dateQuery={ range.dateQuery }
							isoDateFormat={ date.isoDateFormat }
							onRangeSelect={ ( selection ) => getNavigate()?.( selection ) }
						/>
					) }
					<Button __next40pxDefaultSize variant="secondary" onClick={ reload } disabled={ loading } isBusy={ loading }>
						{ m.refresh }
					</Button>
				</div>
			</div>
			{ range === null && (
				<Notice status="warning" isDismissible={ false }>
					{ m.unavailable }
				</Notice>
			) }
			{ error !== null && errorCode !== UNKNOWN_ACCOUNT && (
				<Notice status="error" isDismissible={ false }>
					{ error }{ ' ' }
					<Button variant="link" onClick={ reload }>
						{ m.retry }
					</Button>
				</Notice>
			) }
			{ tableProps !== null && (
				<div className={ loading ? 'woodev-marketplace-analytics__body is-loading' : 'woodev-marketplace-analytics__body' }>
					{ /* The tiles stay for an empty period: the shop share is read by `created_via`, apart from the registry. */ }
					<Summary data={ tableProps.data } messages={ m } metric={ metric } path={ path } query={ query } />
					{ empty ? (
						<p className="woodev-marketplace-analytics__empty">{ m.empty }</p>
					) : (
						<>
							<ChartPanel data={ tableProps.data } messages={ m } metric={ metric } path={ path } query={ query } loading={ loading } />
							<PeriodTables { ...tableProps } />
							<p className="woodev-marketplace-analytics__footnote">{ tableProps.data.classified ? m.footnote : m.footnoteOrders }</p>
						</>
					) }
					<NowTables { ...tableProps } />
				</div>
			) }
		</div>
	);
}
