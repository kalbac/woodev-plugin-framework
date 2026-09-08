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
 * SP-10 increment 7 (D10/D11) adds the rest of the filter row beside it, all
 * URL-driven the same way: `DateRangeFilterPicker` (default `period=year` —
 * this is a work queue, not a trend, so `compare` is read only because the
 * component requires it and is never sent to the server) and `AdvancedFilters`
 * for delivery status / WC order status / tracking presence — the three D10
 * measured as actually reachable through `Orders_Controller`'s `after`,
 * `before`, `delivery_status`, `status` and `has_tracking` args (increment 6's
 * server half, already merged). No delivery-type filter: three hops ending in
 * the shipping zones, nothing to query. `filters.ts` carries the pure
 * URL <-> filter-state translation; this file only wires it to the components.
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
import { DELIVERY_STATUS_LABELS, formatOrderDate, getStatusTone, hasTrackingNumber } from './columns';
import {
	ADVANCED_FILTERS_VALUE,
	ALL_CARRIERS,
	CARRIER_PARAM,
	DELIVERY_STATUS_PARAM,
	FILTER_ALL_VALUE,
	FILTER_PARAM,
	HAS_TRACKING_PARAM,
	ORDER_STATUS_PARAM,
	buildAdvancedFiltersConfig,
	filtersEqual,
	getCarrierFromQuery,
	getDeliveryStatusFromQuery,
	getHasTrackingFromQuery,
	getOrderStatusFromQuery,
	isAdvancedFiltersOpen,
	readDateFilters,
} from './filters';
import type { DateFilterState, UrlFilters } from './filters';
import type { WcFilterPickerConfig, WcTableHeader, WcTableRowCell } from './wc-globals';

/** Rows per page — increment 1's REST default. */
const DEFAULT_PER_PAGE = 20;

/**
 * The date-range and `AdvancedFilters` query keys — every filter-row key
 * that belongs to neither `CARRIER_PARAM` nor `FILTER_PARAM`. Each of the two
 * `FilterPicker`s below (#835) must carry this list PLUS the other picker's
 * own param in its `staticParams`, or `FilterPicker`'s navigation drops
 * whatever it does not list (its own contract — an unlisted param does not
 * survive its navigation).
 */
const DATE_AND_ADVANCED_PARAMS = [
	'period',
	'compare',
	'before',
	'after',
	DELIVERY_STATUS_PARAM,
	ORDER_STATUS_PARAM,
	HAS_TRACKING_PARAM,
];

/** Reads the URL query WooCommerce's navigation module currently reports, or `{}` when the module itself is unavailable. */
function getQuery(): Record<string, string | undefined> {
	return window.wc?.navigation?.getQuery() || {};
}

/** Builds one comparable snapshot of every URL-driven filter (`filters.ts`) from the current query. */
function readUrlFilters( query: Record<string, string | undefined> ): UrlFilters {
	const dateApi = window.wc?.date;
	const { after, before } = dateApi
		? readDateFilters( dateApi, query )
		: { after: '', before: '' };

	return {
		carrier: getCarrierFromQuery( query ),
		after,
		before,
		deliveryStatus: getDeliveryStatusFromQuery( query ),
		status: getOrderStatusFromQuery( query ),
		hasTracking: getHasTrackingFromQuery( query ),
	};
}

/** Resolves `DateRangeFilterPicker`'s own `dateQuery` prop, or `null` when `wc-date` is unavailable — the page degrades rather than crash. */
function readDateFilterState( query: Record<string, string | undefined> ): DateFilterState | null {
	const dateApi = window.wc?.date;
	return dateApi ? readDateFilters( dateApi, query ) : null;
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

/**
 * The delivery-analytics panel, announced rather than built (#711).
 *
 * Operator, 08.09.2026: put it BELOW the table, not above it the way Analytics
 * does, and ship the frame today behind a «Скоро» overlay — *«даже если ROI не
 * войдёт в V2, пользователи уже будут видеть, что такая возможность будет»*.
 * WHICH metric it plots is still open on #711; the shape is not — `SummaryList`
 * tiles over a `Chart`, which is why the frame is built from WooCommerce's own
 * `SummaryListPlaceholder`/`ChartPlaceholder` rather than a drawing of one.
 *
 * ⚠ Those two are LOADING skeletons, and a permanently shimmering block reads
 * as "stuck loading" rather than "not built yet". Two things make the
 * difference: the overlay, and `style.scss` stopping their animation. The frame
 * is `aria-hidden` — it is decoration, and the overlay carries the real message.
 */
function RoiPanel() {
	const SummaryListPlaceholder = window.wc?.components?.SummaryListPlaceholder;
	const ChartPlaceholder = window.wc?.components?.ChartPlaceholder;

	if ( ! SummaryListPlaceholder || ! ChartPlaceholder ) {
		return null;
	}

	return (
		<section className="woodev-orders-roi">
			<div className="woodev-orders-roi__frame" aria-hidden="true">
				<SummaryListPlaceholder numberOfItems={ 4 } />
				<ChartPlaceholder height={ 260 } />
			</div>
			<div className="woodev-orders-roi__overlay">
				<p className="woodev-orders-roi__badge">
					{ __( 'Скоро', 'woodev-plugin-framework' ) }
				</p>
				<p className="woodev-orders-roi__note">
					{ __(
						'Здесь появится аналитика по доставке — показатели и график за период.',
						'woodev-plugin-framework'
					) }
				</p>
			</div>
		</section>
	);
}

export default function OrdersPage() {
	const providers = getProviders();
	const hasCarrierFilter = providers.length > 1;

	const [ urlFilters, setUrlFilters ] = useState<UrlFilters>( () => readUrlFilters( getQuery() ) );
	const [ dateFilterState, setDateFilterState ] = useState<DateFilterState | null>( () =>
		readDateFilterState( getQuery() )
	);
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

	// Every control in the filter row — carrier `FilterPicker`, `DateRangeFilterPicker`,
	// `AdvancedFilters` — changes the URL by NAVIGATING rather than calling back
	// with a value, so the only way to learn about a pick, or about the browser's
	// back button, is the history. The listener returns its own unlisten function
	// (verified against the live runtime). A change to any of them starts at page 1
	// (requirement #4) — `filtersEqual` is what decides "any of them".
	useEffect( () => {
		const navigation = window.wc?.navigation;

		if ( ! navigation ) {
			return;
		}

		return navigation.addHistoryListener( () => {
			const query = navigation.getQuery();

			setDateFilterState( readDateFilterState( query ) );

			setUrlFilters( ( current ) => {
				const next = readUrlFilters( query );

				if ( ! filtersEqual( current, next ) ) {
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

		fetchOrders( {
			carrier: urlFilters.carrier,
			page,
			perPage,
			orderby,
			order,
			search,
			after: urlFilters.after,
			before: urlFilters.before,
			status: urlFilters.status,
			deliveryStatus: urlFilters.deliveryStatus,
			hasTracking: urlFilters.hasTracking,
		} )
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
				// `rows` was reset to `null` at the top of this effect and this
				// branch never touched it, so `TableCard`'s `isLoading={ null ===
				// rows }` stayed `true` forever — a permanent loading skeleton
				// under the error notice above it. Settling to an empty table
				// here is what `isLoading` becoming `false` actually looks like.
				setRows( [] );
				setTotal( 0 );
			} );

		return () => {
			cancelled = true;
		};
	}, [ urlFilters, page, perPage, orderby, order, search ] );

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

	// #835: carrier SCOPE and display MODE are two independent `FilterPicker`s
	// now, each on its own param — see `filters.ts`'s `CARRIER_PARAM`/
	// `FILTER_PARAM` doc comments for why. Each one's `staticParams` carries
	// the OTHER picker's param plus the date-range/advanced keys, or a pick on
	// one silently drops the other (`FilterPicker`'s own contract). `paged` is
	// deliberately in neither list — it is component state, not a URL param at
	// all here — so it still cannot survive a change and strand the merchant on
	// a page that no longer exists.
	const carrierConfig: WcFilterPickerConfig = {
		label: __( 'Перевозчик', 'woodev-plugin-framework' ),
		param: CARRIER_PARAM,
		staticParams: [ FILTER_PARAM, ...DATE_AND_ADVANCED_PARAMS ],
		showFilters: () => true,
		defaultValue: ALL_CARRIERS,
		filters: providers.map( ( p ) => ( {
			label: `${ p.label } (${ p.count })`,
			value: p.id,
		} ) ),
	};

	const filterModeConfig: WcFilterPickerConfig = {
		label: __( 'Фильтры', 'woodev-plugin-framework' ),
		param: FILTER_PARAM,
		staticParams: [ CARRIER_PARAM, ...DATE_AND_ADVANCED_PARAMS ],
		showFilters: () => true,
		defaultValue: FILTER_ALL_VALUE,
		filters: [
			{ label: __( 'Все заказы', 'woodev-plugin-framework' ), value: FILTER_ALL_VALUE },
			// LAST on purpose. WooCommerce's own «Аналитика → Заказы» «Show» picker
			// carries exactly `All orders` + `Advanced filters`, advanced last —
			// measured on the rig, 08.09.2026 — and the advanced block is revealed
			// by that option rather than standing open. Operator asked for the same.
			{ label: __( 'Расширенные фильтры', 'woodev-plugin-framework' ), value: ADVANCED_FILTERS_VALUE },
		],
	};

	const dateApi = window.wc?.date;
	// ⚠ `window.wc.currency` is the MODULE, not the factory — measured on the rig:
	// `typeof wc.currency === 'object'` and it is NOT callable, while
	// `wc.currency.CurrencyFactory` is the function and calling it returns the
	// instance `AdvancedFilters` wants. Calling the module directly threw
	// `TypeError: B is not a function` and took the whole wc-admin app down.
	const currency = window.wc?.currency?.CurrencyFactory?.();
	const orderStatusOptions = window.wc?.wcSettings?.getSetting<Record<string, string>>(
		'orderStatuses',
		{}
	);
	const advancedFiltersConfig = buildAdvancedFiltersConfig( DELIVERY_STATUS_LABELS, orderStatusOptions );

	const TableCard = window.wc?.components?.TableCard;
	const FilterPicker = window.wc?.components?.FilterPicker;
	const DateRangeFilterPicker = window.wc?.components?.DateRangeFilterPicker;
	const AdvancedFilters = window.wc?.components?.AdvancedFilters;
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

	// Each control below is checked again, inline, right where it renders — TS
	// only narrows `DateRangeFilterPicker`/`dateFilterState`/`dateApi` etc. from
	// the actual condition guarding that JSX, not from a boolean copy of it — so
	// this is only for the wrapper `<div>`'s own visibility.
	/**
	 * The advanced block is revealed by the display-mode picker's LAST option
	 * (#835 — its own `FILTER_PARAM`, split from the carrier picker), never
	 * standing open. Read from the URL like every other filter here —
	 * `FilterPicker` navigates instead of calling back.
	 */
	const advancedOpen = isAdvancedFiltersOpen( getQuery() );

	const hasAnyFilterControl = Boolean(
		( hasCarrierFilter && FilterPicker && navigation ) ||
			( FilterPicker && navigation ) || // the display-mode picker (#835) is always offered
			( DateRangeFilterPicker && dateFilterState && navigation && dateApi ) ||
			( advancedOpen && AdvancedFilters && navigation && currency )
	);

	return (
		<>
			{ /*
			 * #837 defect 5: a rejected query parameter used to return this
			 * `Notice` INSTEAD OF the whole page, leaving bare text on an
			 * otherwise empty screen with no way back. It now renders ABOVE the
			 * filter row and table, which stay mounted, so the merchant can use
			 * the very controls that caused the error to fix it.
			 */ }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ hasAnyFilterControl && (
				<div className="woodev-orders__filters">
					{ /*
					 * The basic pickers sit on ONE row. WooCommerce's own
					 * «Аналитика → Заказы» puts both inside a single flex
					 * `.woocommerce-filters__basic-filters` — measured on the rig,
					 * 08.09.2026. Stacking them was a defect the operator caught.
					 */ }
					<div className="woodev-orders__basic-filters">
					{ hasCarrierFilter && FilterPicker && navigation && (
						<FilterPicker
							config={ carrierConfig }
							path={ navigation.getPath() }
							query={ navigation.getQuery() }
						/>
					) }
					{ /*
					 * #835: display MODE is its own `FilterPicker`, independent of
					 * carrier scope — always offered, unlike the carrier picker
					 * above which only exists once there is more than one provider.
					 */ }
					{ FilterPicker && navigation && (
						<FilterPicker
							config={ filterModeConfig }
							path={ navigation.getPath() }
							query={ navigation.getQuery() }
						/>
					) }
					{ /*
					 * Degrades — renders nothing for this one control — when `wc-date`
					 * or the component itself is unavailable (an older WooCommerce),
					 * the same rule `RoiPanel` already follows, rather than crashing
					 * the whole page over one missing filter.
					 */ }
					{ DateRangeFilterPicker && dateFilterState && navigation && dateApi && (
						<DateRangeFilterPicker
							dateQuery={ dateFilterState.dateQuery }
							isoDateFormat={ dateApi.isoDateFormat }
							onRangeSelect={ ( update ) => {
								navigation.updateQueryString?.(
									update,
									navigation.getPath(),
									navigation.getQuery()
								);
							} }
						/>
					) }
					</div>
					{ /*
					 * NOT a permanently visible region: revealed by the display-mode
					 * picker's «Расширенные фильтры» option (#835), the way Analytics does it.
					 *
					 * `currency` is required by `AdvancedFilters`' own contract (its
					 * README: an instance of `@woocommerce/currency`'s `CurrencyFactory`).
					 * No instance means the runtime is missing `wc-currency`, and this
					 * control degrades the same way as the other two.
					 */ }
					{ advancedOpen && AdvancedFilters && navigation && currency && (
						<AdvancedFilters
							config={ advancedFiltersConfig }
							path={ navigation.getPath() }
							query={ navigation.getQuery() }
							siteLocale="ru_RU"
							currency={ currency }
						/>
					) }
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
			<RoiPanel />
		</>
	);
}
