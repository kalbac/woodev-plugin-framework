/**
 * The tables of the «Аналитика» tab (#1891), on WooCommerce's `TableCard` like the delivery tab's: by account, the marketplace
 * statuses now (each count opens the «Заказы» tab on those orders), the stuck postings, the best sellers and what was sold without a
 * shop product. Every table is a handful of rows, so there is no paging; an empty one is the records engine's empty card.
 *
 * @package woodev-plugin-framework
 */

import type { MouseEvent, ReactNode } from 'react';
import { useState } from '@wordpress/element';
import { EmptyCard } from '../../../admin-records/states';
import { getNavigate, getTableCard, type TableCell, type TableHeader } from '../../../admin-records/wc-runtime';
import { DASH, formatCount, formatMoney, formatShare } from '../../../shipping-orders-page/analytics/format';
import { statusPatch } from './request';
import type {
	AnalyticsAccount,
	AnalyticsMessages,
	AnalyticsProduct,
	AnalyticsResponse,
	AnalyticsStatus,
	AnalyticsStuckItem,
	AnalyticsUnlinked,
} from './types';

type Query = Record< string, string | undefined >;

/** How many best sellers are shown, under the sort the merchant chose. */
export const TOP_ROWS = 10;

const NUMERIC = 'woodev-marketplace-analytics__number';

function cell( display: ReactNode, value: string | number ): TableCell {
	return { display, value };
}

function numberHeader( key: string, label: string, sortable = false ): TableHeader {
	return { key, label, isNumeric: true, isSortable: sortable, cellClassName: NUMERIC };
}

/** A count with its share, «3 · 25 %». */
export function withShare( count: number, share: number | null ): string {
	return `${ formatCount( count ) } · ${ formatShare( share ) }`;
}

export function accountRows( accounts: AnalyticsAccount[], currency: string ): TableCell[][] {
	return accounts.map( ( { label, current: m } ) => [
		cell( label, label ),
		cell( formatCount( m.orders ), m.orders ),
		cell( formatMoney( m.revenue, currency ), m.revenue ),
		cell( withShare( m.cancelled, m.cancel_share ), m.cancelled ),
		cell( formatMoney( m.avg_order, currency ), m.avg_order ?? 0 ),
		cell( formatCount( m.units_per_order ), m.units_per_order ?? 0 ),
	] );
}

/**
 * The best sellers under a sort: the server sends the top of both sorts in one list, so the first {@see TOP_ROWS} under either
 * are exactly that sort's top.
 *
 * @param products The list.
 * @param key      The sort key.
 */
export function sortedProducts( products: AnalyticsProduct[], key: 'quantity' | 'revenue' ): AnalyticsProduct[] {
	return [ ...products ]
		.sort( ( a, b ) => b[ key ] - a[ key ] || a.label.localeCompare( b.label, 'ru' ) )
		.slice( 0, TOP_ROWS );
}

/** A link that stays in wc-admin: a plain left click is a navigation of the app, anything else is the browser's. */
function AppLink( { href, patch, children }: { href: string; patch: Query; children: ReactNode } ) {
	const follow = ( event: MouseEvent< HTMLAnchorElement > ) => {
		const navigate = getNavigate();

		if ( navigate === null || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		event.preventDefault();
		navigate( patch );
	};

	return (
		<a href={ href } onClick={ follow }>
			{ children }
		</a>
	);
}

interface CardProps {
	title: string;
	headers: TableHeader[];
	rows: TableCell[][];
	empty: string;
	loading: boolean;
	description?: string;
	note?: string;
	query?: { orderby?: string; order?: string };
	onSort?: ( key: string, direction: string ) => void;
}

/** One table: its sentence above, WooCommerce's card, a note under it. */
function Card( { title, headers, rows, empty, loading, description, note, query, onSort }: CardProps ) {
	const TableCard = getTableCard();

	if ( TableCard === null ) {
		return null;
	}

	return (
		<section className="woodev-marketplace-analytics__section">
			{ description && <p className="woodev-records__description">{ description }</p> }
			{ rows.length === 0 ? (
				<EmptyCard title={ title } actions={ null } text={ empty } />
			) : (
				<TableCard
					className="woodev-marketplace-analytics__table"
					title={ title }
					headers={ headers }
					rows={ rows }
					rowsPerPage={ rows.length }
					totalRows={ rows.length }
					query={ query ?? {} }
					isLoading={ loading }
					showMenu={ false }
					rowHeader={ 0 }
					rowKey={ ( row, index ) => `${ String( row[ 0 ]?.value ?? '' ) }-${ index }` }
					onSort={ onSort ?? ( () => undefined ) }
					onPageChange={ () => undefined }
					onQueryChange={ () => () => undefined }
				/>
			) }
			{ note && <p className="woodev-marketplace-analytics__footnote">{ note }</p> }
		</section>
	);
}

export interface TablesProps {
	data: AnalyticsResponse;
	messages: AnalyticsMessages;
	loading: boolean;
	marketplace: string;
	/** The `wc-admin` path and query a status link is built on. */
	path: string;
	query: Query;
}

function AccountsTable( { data, messages: m, loading }: TablesProps ) {
	if ( data.by_account.length < 2 ) {
		return null;
	}

	return (
		<Card
			title={ m.byAccount }
			headers={ [
				{ key: 'account', label: m.account, required: true },
				numberHeader( 'orders', m.orders ),
				numberHeader( 'revenue', m.revenue ),
				numberHeader( 'cancelled', m.cancelled ),
				numberHeader( 'avg_order', m.avgOrder ),
				numberHeader( 'units_per_order', m.unitsPerOrder ),
			] }
			rows={ accountRows( data.by_account, data.currency ) }
			empty={ m.empty }
			loading={ loading }
		/>
	);
}

/**
 * The status rows: one count per account the figures cover (each a link to that account's orders in that status), and the total
 * when there is more than one account.
 */
export function statusRows( props: TablesProps, statuses: AnalyticsStatus[] ): TableCell[][] {
	const { data, marketplace, path, query } = props;
	const getNewPath = ( window as unknown as { wc?: { navigation?: { getNewPath?: ( q: Query, p: string, c: Query ) => string } } } ).wc?.navigation?.getNewPath;

	return statuses.map( ( status ) => {
		const counts = data.accounts.map( ( account ) => {
			const count = status.accounts[ account.id ] ?? 0;

			if ( count === 0 ) {
				return cell( DASH, 0 );
			}

			const patch = statusPatch( query, marketplace, account.id, status.code );
			const href = getNewPath ? getNewPath( patch, path, query ) : '';

			return cell(
				href === '' ? formatCount( count ) : <AppLink href={ href } patch={ patch }>{ formatCount( count ) }</AppLink>,
				count
			);
		} );

		return [
			cell( <span className={ `woodev-marketplace-analytics__status is-${ status.tone }` }>{ status.label }</span>, status.label ),
			...counts,
			...( data.accounts.length > 1 ? [ cell( formatCount( status.count ), status.count ) ] : [] ),
		];
	} );
}

function StatusesTable( props: TablesProps ) {
	const { data, messages: m, loading } = props;
	const several = data.accounts.length > 1;

	return (
		<Card
			title={ m.statuses }
			description={ m.statusesHint }
			headers={ [
				{ key: 'status', label: m.status, required: true },
				...data.accounts.map( ( account ) => numberHeader( `account-${ account.id }`, several ? account.label : m.count ) ),
				...( several ? [ numberHeader( 'total', m.total ) ] : [] ),
			] }
			rows={ statusRows( props, data.statuses ) }
			empty={ m.statusesEmpty }
			loading={ loading }
		/>
	);
}

export function stuckRows( items: AnalyticsStuckItem[], withAccount: boolean ): TableCell[][] {
	return items.map( ( item ) => [
		cell( item.order_url === '' ? `#${ item.order_id }` : <a href={ item.order_url }>#{ item.order_id }</a>, item.order_id ),
		cell( item.posting, item.posting ),
		...( withAccount ? [ cell( item.account, item.account ) ] : [] ),
		cell( item.status, item.status ),
		cell( formatCount( item.hours ), item.hours ?? 0 ),
	] );
}

function StuckTable( { data, messages: m, loading }: TablesProps ) {
	const several = data.accounts.length > 1;

	return (
		<Card
			title={ m.stuck }
			description={ data.stuck.hint }
			headers={ [
				{ key: 'order', label: m.order, required: true },
				{ key: 'posting', label: m.posting },
				...( several ? [ { key: 'account', label: m.account } ] : [] ),
				{ key: 'status', label: m.status },
				numberHeader( 'hours', m.waiting ),
			] }
			rows={ stuckRows( data.stuck.items, several ) }
			empty={ m.stuckEmpty }
			loading={ loading }
			note={ data.stuck.more }
		/>
	);
}

function ProductsTable( { data, messages: m, loading }: TablesProps ) {
	const [ sort, setSort ] = useState< 'quantity' | 'revenue' >( 'revenue' );
	const rows = sortedProducts( data.products.top, sort ).map( ( product ) => [
		cell( product.url === '' ? product.label : <a href={ product.url }>{ product.label }</a>, product.label ),
		cell( formatCount( product.quantity ), product.quantity ),
		cell( formatMoney( product.revenue, data.currency ), product.revenue ),
	] );

	return (
		<Card
			title={ m.products }
			headers={ [
				{ key: 'product', label: m.product, required: true },
				{ ...numberHeader( 'quantity', m.quantity, true ), defaultOrder: 'desc' },
				{ ...numberHeader( 'revenue', m.revenue, true ), defaultSort: true, defaultOrder: 'desc' },
			] }
			rows={ rows }
			empty={ m.productsEmpty }
			loading={ loading }
			query={ { orderby: sort, order: 'desc' } }
			onSort={ ( key ) => setSort( key === 'quantity' ? 'quantity' : 'revenue' ) }
		/>
	);
}

export function unlinkedRows( unlinked: AnalyticsUnlinked, currency: string ): TableCell[][] {
	return unlinked.items.map( ( item ) => [
		cell( item.label, item.label ),
		cell( formatCount( item.quantity ), item.quantity ),
		cell( formatMoney( item.revenue, currency ), item.revenue ),
	] );
}

function UnlinkedTable( { data, messages: m, loading }: TablesProps ) {
	return (
		<Card
			title={ m.unlinked }
			description={ m.unlinkedHint }
			headers={ [ { key: 'name', label: m.unlinkedName, required: true }, numberHeader( 'quantity', m.quantity ), numberHeader( 'revenue', m.revenue ) ] }
			rows={ unlinkedRows( data.products.unlinked, data.currency ) }
			empty={ m.unlinkedEmpty }
			loading={ loading }
		/>
	);
}

/** The tables of the chosen period: by account, the best sellers, what was sold without a shop product. */
export function PeriodTables( props: TablesProps ) {
	return (
		<>
			<AccountsTable { ...props } />
			<ProductsTable { ...props } />
			<UnlinkedTable { ...props } />
		</>
	);
}

/** The tables of now, whatever the period: the open postings by status and the stuck ones. */
export function NowTables( props: TablesProps ) {
	return (
		<>
			<StatusesTable { ...props } />
			<StuckTable { ...props } />
		</>
	);
}
