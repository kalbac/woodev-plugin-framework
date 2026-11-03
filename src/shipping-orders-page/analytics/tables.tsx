/**
 * The two tables of the «Аналитика» tab (#711), on WooCommerce's `TableCard`: the carrier table and the
 * shipping-method table. Both are tiny (a handful of rows), so there is no paging and no server sort.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import type { WcTableHeader, WcTableRowCell } from '../wc-globals';
import { formatCount, formatMoney, formatShare } from './format';
import type { AnalyticsCarrier, AnalyticsMethod } from './types';

function cell( display: string, value: string | number ): WcTableRowCell {
	return { display, value };
}

function carrierHeaders(): WcTableHeader[] {
	return [
		{ key: 'carrier', label: __( 'Перевозчик', 'woodev-plugin-framework' ), required: true },
		{ key: 'orders', label: __( 'Заказы', 'woodev-plugin-framework' ) },
		{ key: 'revenue', label: __( 'Выручка', 'woodev-plugin-framework' ) },
		{ key: 'shipping_paid', label: __( 'Оплачено за доставку', 'woodev-plugin-framework' ) },
		{ key: 'delivered', label: __( 'Доставлено', 'woodev-plugin-framework' ) },
		{ key: 'in_progress', label: __( 'В работе', 'woodev-plugin-framework' ) },
		{ key: 'returned', label: __( 'Отказ / возврат', 'woodev-plugin-framework' ) },
		{ key: 'failed', label: __( 'Не доставлено', 'woodev-plugin-framework' ) },
		{ key: 'unknown', label: __( 'Нет статуса', 'woodev-plugin-framework' ) },
	];
}

/** A count with its share of the resolved orders, «12 · 60 %»; the share is «—» while nothing is resolved. */
function withShare( count: number, share: number | null ): string {
	return `${ formatCount( count ) } · ${ formatShare( share ) }`;
}

export function carrierRows( carriers: AnalyticsCarrier[], currency: string ): WcTableRowCell[][] {
	return carriers.map( ( { label, current: m } ) => [
		cell( label, label ),
		cell( formatCount( m.orders ), m.orders ),
		cell( formatMoney( m.revenue, currency ), m.revenue ),
		cell( formatMoney( m.shipping_paid, currency ), m.shipping_paid ),
		cell( withShare( m.outcomes.delivered, m.delivered_share ), m.outcomes.delivered ),
		cell( formatCount( m.outcomes.in_progress ), m.outcomes.in_progress ),
		cell( withShare( m.outcomes.returned, m.returned_share ), m.outcomes.returned ),
		cell( withShare( m.outcomes.failed, m.failed_share ), m.outcomes.failed ),
		cell( formatCount( m.outcomes.unknown ), m.outcomes.unknown ),
	] );
}

export function methodRows( methods: AnalyticsMethod[], carriers: AnalyticsCarrier[], currency: string ): WcTableRowCell[][] {
	const carrierLabel = ( id: string ) => carriers.find( ( carrier ) => carrier.id === id )?.label ?? id;

	return methods.map( ( method ) => [
		cell( method.label, method.label ),
		cell( carrierLabel( method.carrier_id ), carrierLabel( method.carrier_id ) ),
		cell( formatCount( method.orders ), method.orders ),
		cell( formatMoney( method.revenue, currency ), method.revenue ),
		cell( formatMoney( method.shipping_paid, currency ), method.shipping_paid ),
	] );
}

interface TablesProps {
	carriers: AnalyticsCarrier[];
	methods: AnalyticsMethod[];
	currency: string;
	loading: boolean;
}

export function Tables( { carriers, methods, currency, loading }: TablesProps ) {
	const TableCard = window.wc?.components?.TableCard;

	if ( ! TableCard ) {
		return null;
	}

	const methodHeaders: WcTableHeader[] = [
		{ key: 'method', label: __( 'Способ', 'woodev-plugin-framework' ), required: true },
		{ key: 'carrier', label: __( 'Перевозчик', 'woodev-plugin-framework' ) },
		{ key: 'orders', label: __( 'Заказы', 'woodev-plugin-framework' ) },
		{ key: 'revenue', label: __( 'Выручка', 'woodev-plugin-framework' ) },
		{ key: 'shipping_paid', label: __( 'Оплачено за доставку', 'woodev-plugin-framework' ) },
	];

	return (
		<>
			<TableCard
				className="woodev-analytics__table"
				title={ __( 'По перевозчикам', 'woodev-plugin-framework' ) }
				headers={ carrierHeaders() }
				rows={ carrierRows( carriers, currency ) }
				rowsPerPage={ Math.max( carriers.length, 1 ) }
				totalRows={ carriers.length }
				isLoading={ loading }
				emptyMessage={ __( 'Нет заказов с доставкой перевозчиками.', 'woodev-plugin-framework' ) }
			/>
			<TableCard
				className="woodev-analytics__table"
				title={ __( 'По способам доставки', 'woodev-plugin-framework' ) }
				headers={ methodHeaders }
				rows={ methodRows( methods, carriers, currency ) }
				rowsPerPage={ Math.max( methods.length, 1 ) }
				totalRows={ methods.length }
				isLoading={ loading }
				emptyMessage={ __( 'Нет заказов с доставкой перевозчиками.', 'woodev-plugin-framework' ) }
			/>
			<p className="woodev-analytics__footnote">
				{ __( 'Заказ с несколькими способами доставки учитывается в каждом из них.', 'woodev-plugin-framework' ) }
			</p>
		</>
	);
}
