/**
 * Shipping orders page — mounted into WooCommerce's own admin app (`wc-admin`)
 * through `addFilter( 'woocommerce_admin_pages_list', ... )`, see `./index`.
 *
 * The table is `@woocommerce/components`' `TableCard` (Route B, `./wc-globals`),
 * not a hand-written `<table>` — sorting, pagination and the loading skeleton
 * are all `TableCard`'s own (`onSort`, `onPageChange`/`onQueryChange`,
 * `isLoading`). The carrier dimension #694 asked for is a `FilterPicker` ABOVE
 * the card — the control WooCommerce's own «Аналитика → Заказы» uses to switch
 * report scope («Показать: Все заказы»). Settled by the operator on the rig,
 * 08.09.2026, with both pages open side by side; the first implementation put a
 * `SelectControl` inside `TableCard`'s `actions` slot and that is now wrong.
 *
 * ⚠ Consequence worth knowing: `FilterPicker` is URL-driven. The chosen carrier
 * lives in the `carrier` query parameter, so the view is linkable and the
 * browser's back button works on it — the component navigates rather than
 * calling back with a value, so this page reads the carrier out of the query
 * and re-reads it on every history change.
 *
 * There is deliberately no delivery-status filter: the REST route
 * (`Orders_Controller::register_routes()`) has no status query arg, and
 * filtering rows client-side after the server already paginated them would
 * silently report a wrong total — inventing that capability was out of scope
 * for this rewrite (SP-10 #820, increment 2b rewrite report).
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SearchControl } from '@wordpress/components';
import { fetchOrders, getProviders } from './rest';
import type {
	OrderRow,
	OrderRowCustomer,
	OrderRowDeliveryStatus,
	OrderRowPayment,
	OrderRowTracking,
} from './rest';
import { formatOrderDate, getStatusTone, hasTrackingNumber } from './columns';
import type { WcFilterPickerConfig, WcTableHeader, WcTableRowCell } from './wc-globals';

/** Rows per page — increment 1's REST default. */
const DEFAULT_PER_PAGE = 20;

/** Query parameter the carrier `FilterPicker` owns. */
const CARRIER_PARAM = 'carrier';

/** Carrier value meaning "every provider" — the aggregate #694 made the default. */
const ALL_CARRIERS = 'all';

/**
 * Reads the active carrier out of the URL. `FilterPicker` navigates instead of
 * calling back, so the query — not React state — is the source of truth; an
 * absent parameter is the aggregate.
 */
function getCarrierFromQuery(): string {
	return window.wc?.navigation?.getQuery()?.[ CARRIER_PARAM ] || ALL_CARRIERS;
}

/** Debounce for the search box, ms. */
const SEARCH_DEBOUNCE_MS = 400;

/** `type` => short Russian label. `unknown` omits the prefix. */
const TYPE_LABELS: Record<string, string> = {
	courier: __( 'Курьер', 'woodev-plugin-framework' ),
	pickup: __( 'Пункт выдачи', 'woodev-plugin-framework' ),
	postal: __( 'Почта', 'woodev-plugin-framework' ),
};

/**
 * Renders the «Статус» cell: canonical label with a tone dot, raw carrier
 * label as the muted secondary line — `unknown` renders visibly as
 * "Неизвестно", never dressed up as a real state (it already carries its own
 * canonical_label from the server, so no special-casing is needed here).
 */
function StatusCell( { deliveryStatus }: { deliveryStatus: OrderRowDeliveryStatus } ) {
	const tone = getStatusTone( deliveryStatus.canonical );

	return (
		<>
			<span className={ `woodev-orders-status woodev-orders-status--${ tone }` }>
				<span className="woodev-orders-status__dot" aria-hidden="true" />
				{ deliveryStatus.canonical_label }
			</span>
			{ deliveryStatus.raw_label && (
				<span className="woodev-orders-cell__meta">{ deliveryStatus.raw_label }</span>
			) }
		</>
	);
}

/**
 * Renders the «Доставка» cell: an optional short type prefix, the resolved
 * destination (M1: pickup point first, else the formatted address), and the
 * method+total as the muted secondary line.
 */
function ShippingCell( { row }: { row: OrderRow } ) {
	const typeLabel = TYPE_LABELS[ row.type ];
	const destination = typeLabel
		? `${ typeLabel } · ${ row.shipping.destination_text }`
		: row.shipping.destination_text;

	return (
		<>
			<span>{ destination }</span>
			<span className="woodev-orders-cell__meta">
				{ row.shipping.method_title }: { row.shipping.formatted_total }
			</span>
		</>
	);
}

/**
 * Renders the «Покупатель» cell: name (linked when there is a user account),
 * email, and phone when present.
 */
function CustomerCell( { customer }: { customer: OrderRowCustomer } ) {
	return (
		<>
			<span>
				{ customer.user_edit_url ? (
					<a href={ customer.user_edit_url }>{ customer.name }</a>
				) : (
					customer.name
				) }
			</span>
			<span className="woodev-orders-cell__meta">{ customer.email }</span>
			{ customer.phone && (
				<span className="woodev-orders-cell__meta">{ customer.phone }</span>
			) }
		</>
	);
}

/**
 * Renders the «Оплата» cell: method + formatted total, with a soft warning
 * badge when the order still needs payment.
 */
function PaymentCell( { payment }: { payment: OrderRowPayment } ) {
	return (
		<>
			<span>{ payment.method_title }</span>
			<span className="woodev-orders-cell__meta">{ payment.formatted_total }</span>
			{ payment.needs_payment && (
				<span className="woodev-orders-badge woodev-orders-badge--warn">
					{ __( 'Ожидает оплаты', 'woodev-plugin-framework' ) }
				</span>
			) }
		</>
	);
}

/**
 * Renders the «Трек» cell: a link when the row carries a tracking number,
 * an em dash otherwise — the dash is this component's own display choice,
 * never something the row payload carries.
 */
function TrackingCell( { tracking }: { tracking: OrderRowTracking } ) {
	if ( ! hasTrackingNumber( tracking ) ) {
		return <span className="woodev-orders-cell__meta">—</span>;
	}

	return tracking.url ? (
		<a href={ tracking.url } target="_blank" rel="noopener noreferrer">
			{ tracking.number }
		</a>
	) : (
		<span>{ tracking.number }</span>
	);
}

/**
 * `TableCard`'s column headers. Only `ID` and `date` carry `isSortable` —
 * increment 1's REST route (`Orders_Controller::register_routes()`) passes
 * `orderby` straight through to `wc_get_orders()`, and those are the two
 * keys it was ever exercised against; the rest have no server-side ordering
 * behind them, so they stay non-sortable rather than sending an `orderby`
 * the query layer would silently ignore. `Покупатель`/`Доставка`/`Оплата`/`Трек`
 * are left non-`required` so `TableCard`'s own column-visibility menu
 * (`showMenu`) has something real to toggle.
 */
const HEADERS: WcTableHeader[] = [
	{ key: 'ID', label: __( 'Заказ', 'woodev-plugin-framework' ), isSortable: true, required: true },
	{ key: 'date', label: __( 'Дата', 'woodev-plugin-framework' ), isSortable: true, required: true },
	{ key: 'status', label: __( 'Статус', 'woodev-plugin-framework' ), required: true },
	{ key: 'customer', label: __( 'Покупатель', 'woodev-plugin-framework' ) },
	{ key: 'shipping', label: __( 'Доставка', 'woodev-plugin-framework' ) },
	{ key: 'payment', label: __( 'Оплата', 'woodev-plugin-framework' ) },
	{ key: 'tracking', label: __( 'Трек', 'woodev-plugin-framework' ) },
];

/** Builds one `TableCard` row from a REST row — display cell + raw sort value each. */
function buildRow( row: OrderRow ): WcTableRowCell[] {
	const date = formatOrderDate( row.date_created );

	return [
		{
			display: (
				<a href={ row.edit_url }>
					{ sprintf( __( 'Заказ %s', 'woodev-plugin-framework' ), row.order_number ) }
				</a>
			),
			value: row.id,
		},
		{ display: <span title={ date.title }>{ date.text }</span>, value: row.date_created || '' },
		{ display: <StatusCell deliveryStatus={ row.delivery_status } />, value: row.delivery_status.canonical },
		{ display: <CustomerCell customer={ row.customer } />, value: row.customer.name },
		{ display: <ShippingCell row={ row } />, value: row.shipping.destination_text },
		{ display: <PaymentCell payment={ row.payment } />, value: row.payment.formatted_total },
		{ display: <TrackingCell tracking={ row.tracking } />, value: row.tracking.number || '' },
	];
}

export default function OrdersPage() {
	const providers = getProviders();
	const hasCarrierFilter = providers.length > 1;

	const [ carrier, setCarrier ] = useState( getCarrierFromQuery );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( DEFAULT_PER_PAGE );
	const [ orderby, setOrderby ] = useState( 'date' );
	const [ order, setOrder ] = useState<'ASC' | 'DESC'>( 'DESC' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ rows, setRows ] = useState<OrderRow[] | null>( null );
	const [ total, setTotal ] = useState( 0 );
	const [ error, setError ] = useState( '' );

	const searchDebounce = useRef<ReturnType<typeof setTimeout> | null>( null );

	// `FilterPicker` changes the carrier by NAVIGATING, so the only way to learn
	// about a pick — or about the browser's back button — is the history. The
	// listener returns its own unlisten function (verified against the live
	// runtime). Paging is per-carrier, so a change starts at page 1.
	useEffect( () => {
		const navigation = window.wc?.navigation;

		if ( ! navigation ) {
			return;
		}

		return navigation.addHistoryListener( () => {
			setCarrier( ( current ) => {
				const next = getCarrierFromQuery();

				if ( next !== current ) {
					setPage( 1 );
				}

				return next;
			} );
		} );
	}, [] );

	// Debounce the search box into `search`, which is what actually drives the fetch.
	useEffect( () => {
		if ( searchDebounce.current ) {
			clearTimeout( searchDebounce.current );
		}
		searchDebounce.current = setTimeout( () => {
			setPage( 1 );
			setSearch( searchInput );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( searchDebounce.current as ReturnType<typeof setTimeout> );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ searchInput ] );

	useEffect( () => {
		let cancelled = false;

		setError( '' );
		setRows( null );

		fetchOrders( { carrier, page, perPage, orderby, order, search } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setRows( ( res && res.rows ) || [] );
				setTotal( ( res && res.total ) || 0 );
			} )
			.catch( ( err: { message?: string } ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					( err && err.message ) ||
						__( 'Не удалось загрузить заказы.', 'woodev-plugin-framework' )
				);
			} );

		return () => {
			cancelled = true;
		};
	}, [ carrier, page, perPage, orderby, order, search ] );

	/**
	 * `Table` (inside `TableCard`) computes the NEXT sort direction itself from
	 * the `query.orderby`/`query.order` we hand it, and calls this with that
	 * already-toggled direction — verified in `table.tsx`'s `sortBy()`. This
	 * only needs to store what it is given, never invert it again.
	 */
	const onSort = ( key: string, direction: string ) => {
		setOrderby( key );
		setOrder( 'asc' === direction ? 'ASC' : 'DESC' );
		setPage( 1 );
	};

	/** `TableCard`'s per-page-size control routes through `onQueryChange('per_page')`, not `onPageChange`. */
	const onQueryChange = ( param: string ) => ( value: string ) => {
		if ( 'per_page' === param ) {
			setPerPage( Number( value ) || DEFAULT_PER_PAGE );
			setPage( 1 );
		} else if ( 'paged' === param ) {
			setPage( Number( value ) || 1 );
		}
	};

	const actions = [
		<SearchControl
			key="search"
			value={ searchInput }
			placeholder={ __( 'Поиск по заказам…', 'woodev-plugin-framework' ) }
			onChange={ setSearchInput }
		/>,
	];

	const carrierConfig: WcFilterPickerConfig = {
		label: __( 'Показать', 'woodev-plugin-framework' ),
		param: CARRIER_PARAM,
		// Nothing is carried across a carrier change on purpose: `paged` must not
		// survive it, or switching carrier can land on a page that no longer exists.
		staticParams: [],
		showFilters: () => true,
		defaultValue: ALL_CARRIERS,
		filters: providers.map( ( p ) => ( {
			label: `${ p.label } (${ p.count })`,
			value: p.id,
		} ) ),
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	const TableCard = window.wc?.components?.TableCard;
	const FilterPicker = window.wc?.components?.FilterPicker;
	const navigation = window.wc?.navigation;

	if ( ! TableCard ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Компонент WooCommerce «TableCard» недоступен — обновите WooCommerce.',
					'woodev-plugin-framework'
				) }
			</Notice>
		);
	}

	return (
		<>
			{ hasCarrierFilter && FilterPicker && navigation && (
				<div className="woodev-orders__filters">
					<FilterPicker
						config={ carrierConfig }
						path={ navigation.getPath() }
						query={ navigation.getQuery() }
					/>
				</div>
			) }
			<TableCard
				className="woodev-orders"
				title={ __( 'Заказы доставки', 'woodev-plugin-framework' ) }
				headers={ HEADERS }
				rows={ null === rows ? [] : rows.map( buildRow ) }
				rowsPerPage={ perPage }
				totalRows={ total }
				isLoading={ null === rows }
				query={ { orderby, order: order.toLowerCase(), paged: String( page ) } }
				onSort={ onSort }
				onPageChange={ setPage }
				onQueryChange={ onQueryChange }
				actions={ actions }
				hasSearch
				showMenu
				emptyMessage={ __( 'Заказов доставки пока нет.', 'woodev-plugin-framework' ) }
				summary={
					null === rows
						? undefined
						: [ { label: __( 'Заказов', 'woodev-plugin-framework' ), value: total } ]
				}
			/>
		</>
	);
}
