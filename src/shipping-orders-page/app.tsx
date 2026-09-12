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
 * URL-driven the same way: the «Период» picker (`./period-picker` — ours since
 * #855, because this page's default period is «всё время» and WooCommerce's own
 * picker has no such preset and no seam to add one) and `AdvancedFilters`
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
import { Button, Dashicon, Notice, Popover, SearchControl, ToggleControl, Tooltip } from '@wordpress/components';
import type { ComponentProps } from 'react';
import {
	fetchOrders,
	fetchSyncStatus,
	getProviders,
	getReachableDeliveryStatuses,
	performOrderAction,
} from './rest';
import type {
	OrderRow,
	OrderRowAction,
	OrderRowCustomer,
	OrderRowDeliveryStatus,
	OrderRowPayment,
	OrderRowTracking,
	OrdersScopeCounts,
	SyncStatusResponse,
} from './rest';
import {
	DELIVERY_STATUS_LABELS,
	formatOrderDate,
	formatSyncTimestamp,
	getStatusTone,
	hasTrackingNumber,
} from './columns';
import {
	ADVANCED_FILTERS_VALUE,
	ALL_CARRIERS,
	CARRIER_PARAM,
	DELIVERY_STATUS_NOT_PARAM,
	DELIVERY_STATUS_PARAM,
	FILTER_PARAM,
	HAS_PICKUP_POINT_PARAM,
	HAS_TRACKING_PARAM,
	NEW_SCOPE,
	ORDER_STATUS_NOT_PARAM,
	ORDER_STATUS_PARAM,
	SCOPE_PARAM,
	advancedFiltersToggleQuery,
	buildAdvancedFiltersConfig,
	filtersEqual,
	getCarrierFromQuery,
	getDeliveryStatusFromQuery,
	getDeliveryStatusNotFromQuery,
	getHasPickupPointFromQuery,
	getHasTrackingFromQuery,
	getOrderStatusFromQuery,
	getOrderStatusNotFromQuery,
	getScopeFromQuery,
	isAdvancedFiltersOpen,
	isExportedForScope,
	readDateFilters,
	scopeQuery,
} from './filters';
import type { UrlFilters } from './filters';
import PeriodPicker from './period-picker';
import type { WcFilterPickerConfig, WcTableHeader, WcTableRowCell } from './wc-globals';

/** Rows per page — increment 1's REST default. */
const DEFAULT_PER_PAGE = 20;

/**
 * The date-range and `AdvancedFilters` query keys — every filter-row key that
 * belongs to neither `CARRIER_PARAM` nor `FILTER_PARAM`. Listed in both pickers'
 * `staticParams`.
 *
 * ⚠ NOT because an unlisted param would be dropped — it would not.
 * `FilterPicker.update()` re-asserts `staticParams` from the current query and
 * then calls `updateQueryString()`, which merges the WHOLE existing query
 * (`getNewPath()`); an unlisted param survives on its own. What `staticParams`
 * actually protects against is `getAllFilterParams()`, which explicitly sets
 * every param belonging to THIS picker's own config to `undefined` on each
 * update. Listing them is cheap and harmless either way, but the reason matters:
 * the earlier comment here claimed a contract the component does not have.
 */
const DATE_AND_ADVANCED_PARAMS = [
	'period',
	'compare',
	'before',
	'after',
	DELIVERY_STATUS_PARAM,
	DELIVERY_STATUS_NOT_PARAM,
	ORDER_STATUS_PARAM,
	ORDER_STATUS_NOT_PARAM,
	HAS_TRACKING_PARAM,
	HAS_PICKUP_POINT_PARAM,
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
		scope: getScopeFromQuery( query ),
		after,
		before,
		deliveryStatus: getDeliveryStatusFromQuery( query ),
		deliveryStatusNot: getDeliveryStatusNotFromQuery( query ),
		status: getOrderStatusFromQuery( query ),
		statusNot: getOrderStatusNotFromQuery( query ),
		hasTracking: getHasTrackingFromQuery( query ),
		hasPickupPoint: getHasPickupPointFromQuery( query ),
	};
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
 * Renders the «Статус» cell: one WooCommerce-coloured badge carrying our
 * canonical status (#829) — `unknown` renders visibly as "Неизвестно", never
 * dressed up as a real state (it already carries its own canonical_label
 * from the server, so no special-casing is needed here).
 */
function StatusCell( { deliveryStatus }: { deliveryStatus: OrderRowDeliveryStatus } ) {
	const tone = getStatusTone( deliveryStatus.canonical );
	const badge = (
		<span className={ `woodev-orders-status woodev-orders-status--${ tone }` }>
			{ deliveryStatus.canonical_label }
		</span>
	);

	// The carrier's own word (#829) lives in a hover tooltip, not a second
	// visible line — it stays valuable exactly when it carries something the
	// canonical status lost ("Неизвестно" / "Задержан на таможне"). No
	// tooltip at all when there is nothing to say, per the card: an empty
	// `raw_label` renders no `Tooltip`, never an empty one.
	return deliveryStatus.raw_label ? <Tooltip text={ deliveryStatus.raw_label }>{ badge }</Tooltip> : badge;
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
 *
 * ⚠ The total carries its OWN class on top of the shared meta one (#865). A
 * formatted price separates thousands with a space, so «3 980,00 ₽» breaks
 * across two lines the moment the column is narrower than the amount — which
 * is exactly what happened once #861 gave seeded orders real totals. The
 * shared `woodev-orders-cell__meta` cannot carry `white-space: nowrap`: the
 * «Доставка» cell renders a postal address through the same class and that one
 * MUST wrap. Hence a dedicated class rather than a rule on the shared one, and
 * rather than an `:nth-child()` on the cell — `TableCard` gives its cells no
 * per-column class, and adding the «Действие» column (#824) would shift any
 * positional selector.
 */
function PaymentCell( { payment }: { payment: OrderRowPayment } ) {
	return (
		<>
			<span>{ payment.method_title }</span>
			<span className="woodev-orders-cell__meta woodev-orders-amount">
				{ payment.formatted_total }
			</span>
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
 * One row's action-button state (#824), keyed by order id in the page's own
 * `actionRowStates`. `pendingAction` scopes the in-flight/disabled state to THIS row —
 * one order's export must not freeze every other row's buttons. `confirmingAction` is the
 * «Да / Нет» popover step a `destructive` action needs before it fires; both are cleared
 * together once the request settles (success or failure), so a row never gets stuck
 * showing a stale confirm after its own request already answered.
 */
interface RowActionState {
	pendingAction: string | null;
	confirmingAction: string | null;
}

/**
 * Dashicon per action id — the SAME glyphs the shipped plugins use, read out of their
 * own stylesheets rather than chosen here (operator, rig rejection of the text buttons:
 * *«см. как у меня в референсных плагинах, там иконки вместо текста с тултипами»*).
 *
 * `woocommerce-edostavka/assets/css/admin/orders-table.css` and
 * `woodev-russian-post/assets/css/admin/orders-table.css` agree glyph for glyph, which is
 * what makes this a common set rather than one carrier's taste:
 *
 * | action | reference CSS      | codepoint | Dashicon           |
 * |--------|--------------------|-----------|--------------------|
 * | export | `*-export::after`  | `317`   | `dashicons-upload` |
 * | update | `*-update::after`  | `463`   | `dashicons-update` |
 * | cancel | `*-cancel::after`  | `14f`   | `dashicons-remove` |
 *
 * The codepoint → name mapping is from WordPress's own `wp-includes/css/dashicons.css`,
 * not from memory. A carrier extra the server declares through
 * `woodev_shipping_order_actions` has no entry here and falls back to a neutral glyph —
 * it renders rather than vanishing.
 */
type DashiconName = ComponentProps< typeof Dashicon >[ 'icon' ];

const ACTION_ICONS: Record< string, DashiconName > = {
	export: 'upload',
	update: 'update',
	cancel: 'remove',
};

const FALLBACK_ACTION_ICON: DashiconName = 'admin-generic';

/**
 * Renders the «Действие» cell (#824): one icon-only `Button` per entry in `row.actions`,
 * in server order. Absent/empty `actions` renders nothing — never an invented button.
 *
 * ⚠ **Icons, inline, never text and never stacked.** The first implementation used text
 * buttons; on the rig they wrapped «Обновить» and «Отменить» onto two lines in 11 rows of
 * 19 and the operator rejected it against his own plugins. The row is `nowrap` and the
 * buttons are square — see `style.scss`.
 *
 * ⚠ The accessible NAME stays the short verb («Выгрузить»), while the TOOLTIP carries the
 * explanation: `Button`'s own `label` would make one string do both, so the tooltip is
 * ours and `showTooltip` is off to avoid rendering two.
 *
 * ⚠ A `destructive` action confirms in a `Popover`, not inline. An inline confirm grows
 * the cell, which is the very thing that got the text version rejected; a popover leaves
 * the row's height and width untouched. Still no `window.confirm()` — a native modal
 * blocks the page and the browser automation the rig is verified with.
 */
function ActionsCell( {
	row,
	rowState,
	onActionClick,
	onCancelConfirm,
}: {
	row: OrderRow;
	rowState?: RowActionState;
	onActionClick: ( row: OrderRow, action: OrderRowAction ) => void;
	onCancelConfirm: ( orderId: number ) => void;
} ) {
	if ( ! row.actions || 0 === row.actions.length ) {
		return null;
	}

	const pendingAction = rowState?.pendingAction ?? null;
	const confirmingAction = rowState?.confirmingAction ?? null;
	const rowBusy = null !== pendingAction;

	return (
		<div className="woodev-orders-actions">
			{ row.actions.map( ( action ) => {
				const tooltip = action.title || action.label;

				return (
					<span key={ action.action } className="woodev-orders-actions__item">
						<Tooltip text={ tooltip }>
							<Button
								// ⚠ A `Dashicon` ELEMENT, not the name as a bare string: `Button`'s
								// `icon` is typed `IconType`, which a plain `string` does not satisfy —
								// `tsc` refuses it even though it renders correctly at runtime, so jest
								// and the rig both stayed green while the typecheck gate went red.
								icon={ <Dashicon icon={ ACTION_ICONS[ action.action ] || FALLBACK_ACTION_ICON } /> }
								label={ action.label }
								showTooltip={ false }
								className={
									'woodev-orders-actions__button' +
									( action.destructive ? ' woodev-orders-actions__button--destructive' : '' )
								}
								isBusy={ pendingAction === action.action }
								disabled={ rowBusy }
								onClick={ () => onActionClick( row, action ) }
							/>
						</Tooltip>
						{ confirmingAction === action.action && (
							<Popover
								className="woodev-orders-actions__confirm"
								// ⚠ `bottom-end`, not `bottom center`: «Действие» is the LAST column, so a
								// centred popover runs off the right edge of the viewport — on the rig it
								// clipped the question mid-word and cut «Нет» in half. Anchoring the
								// popover's right edge to the button's keeps it on screen. Nothing in
								// jsdom can see this; it took a screenshot.
								placement="bottom-end"
								focusOnMount="firstElement"
								onFocusOutside={ () => onCancelConfirm( row.id ) }
							>
								<p className="woodev-orders-actions__confirm-text">
									{ sprintf(
										/* translators: %s: what the action does, e.g. "Отменить заказ у перевозчика". */
										__( '%s?', 'woodev-plugin-framework' ),
										tooltip
									) }
								</p>
								<div className="woodev-orders-actions__confirm-buttons">
									<Button
										variant="primary"
										isDestructive
										onClick={ () => onActionClick( row, action ) }
									>
										{ __( 'Да', 'woodev-plugin-framework' ) }
									</Button>
									<Button variant="tertiary" onClick={ () => onCancelConfirm( row.id ) }>
										{ __( 'Нет', 'woodev-plugin-framework' ) }
									</Button>
								</div>
							</Popover>
						) }
					</span>
				);
			} ) }
		</div>
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
	{ key: 'actions', label: __( 'Действие', 'woodev-plugin-framework' ) },
];

/** The action-cell callbacks {@link buildRow} needs, threaded through from `OrdersPage`. */
interface OrderActionsCallbacks {
	rowState?: RowActionState;
	onActionClick: ( row: OrderRow, action: OrderRowAction ) => void;
	onCancelConfirm: ( orderId: number ) => void;
}

/** Builds one `TableCard` row from a REST row — display cell + raw sort value each. */
function buildRow( row: OrderRow, actions: OrderActionsCallbacks ): WcTableRowCell[] {
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
		{
			display: (
				<ActionsCell
					row={ row }
					rowState={ actions.rowState }
					onActionClick={ actions.onActionClick }
					onCancelConfirm={ actions.onCancelConfirm }
				/>
			),
			// No meaningful ordering behind this column — nothing sorts on it server-side,
			// same reason the header itself carries no `isSortable`.
			value: '',
		},
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

/**
 * The «Data status» panel (#828 increment 8, SP-10 spec D9) — the third block
 * in the filter row, beside «Перевозчик» and the date range picker. Consumes
 * the seam `Orders_Controller::get_sync_status()` already exposes (#831,
 * increment 7's server half); this component only renders it.
 *
 * Degrades the same way {@link RoiPanel} does: no data yet, or the fetch
 * failed, and the block simply does not render rather than breaking the page.
 * A registry with no carriers at all is the one case that is not a loading
 * or error state — it is rendered, and it means there is nothing to show.
 *
 * The aggregate `last_updated` reads `null` the instant ANY registered
 * carrier has never synced (server-side decision, not this component's) —
 * overstating freshness is exactly the defect this panel exists to prevent,
 * so that case gets an honest sentence instead of a blank «Обновлено», and
 * the per-carrier breakdown beneath it — always rendered — is what shows
 * WHICH carrier is the reason.
 */
function DataStatusPanel() {
	const [ syncStatus, setSyncStatus ] = useState<SyncStatusResponse | null>( null );

	useEffect( () => {
		let cancelled = false;

		fetchSyncStatus()
			.then( ( res ) => {
				if ( ! cancelled ) {
					setSyncStatus( res );
				}
			} )
			// Degrades like `RoiPanel` — no error notice of its own; the block
			// just does not render (`syncStatus` stays `null`).
			.catch( () => undefined );

		return () => {
			cancelled = true;
		};
	}, [] );

	if ( ! syncStatus || 0 === syncStatus.carriers.length ) {
		return null;
	}

	/**
	 * ⚠ Shaped after WooCommerce's OWN «Data status» block, captured from the rig
	 * 09.09.2026 rather than approximated: a label above a bordered bar, and inside it
	 * two `label above value` columns («Last updated» / «Next update»). The operator
	 * asked for that block, not for something in the same row — the first attempt was a
	 * heading plus prose, and its long sentence could not fit the row at all, so flex
	 * dropped the whole block onto its own line.
	 *
	 * The per-carrier breakdown that sentence carried is not lost: it moves into the
	 * bar's tooltip, which is the only place it can live without deciding the width of
	 * the filter row.
	 */
	const lastUpdated = formatSyncTimestamp( syncStatus.last_updated );

	/**
	 * The soonest scheduled update across carriers — «when will anything refresh»,
	 * which is the question the column asks. A carrier on webhooks alone carries no
	 * `next_update` and simply does not participate; when none does, the column says so
	 * rather than rendering an empty cell.
	 */
	const nextUpdates = syncStatus.carriers
		.map( ( carrier ) => carrier.next_update )
		.filter( ( value ): value is number => 'number' === typeof value );
	const nextUpdate = nextUpdates.length > 0 ? formatSyncTimestamp( Math.min( ...nextUpdates ) ) : null;

	/**
	 * ⚠ The aggregate reads `null` the moment ANY carrier has never synced (a server-side
	 * decision, see `Orders_Controller::get_sync_status()`), so «Ни разу» here is a
	 * statement about the WHOLE table, not about one carrier. The tooltip is what says
	 * which carrier is the reason — without it the value would be true but unactionable.
	 */
	const breakdown = syncStatus.carriers
		.map( ( carrier ) => {
			const synced =
				null === carrier.last_updated
					? __( 'ни разу не синхронизировалось', 'woodev-plugin-framework' )
					: formatSyncTimestamp( carrier.last_updated ).title;
			const scheduled =
				null === carrier.next_update
					? __( 'по расписанию не обновляется', 'woodev-plugin-framework' )
					: formatSyncTimestamp( carrier.next_update ).title;

			return `${ carrier.label }: ${ synced }, ${ scheduled }`;
		} )
		.join( '\n' );

	return (
		<div className="woodev-orders-sync">
			<div className="woodev-orders-sync__label">
				{ __( 'Статус данных', 'woodev-plugin-framework' ) }
			</div>
			{ /*
			 * `role="status"` + `aria-live="polite"` mirror WooCommerce's own bar: the
			 * values arrive after a fetch, and a screen reader has to hear them without
			 * the focus moving.
			 */ }
			<div
				className="woodev-orders-sync__bar"
				role="status"
				aria-live="polite"
				aria-atomic="true"
				title={ breakdown }
			>
				<div className="woodev-orders-sync__content">
					<span className="woodev-orders-sync__item">
						<span className="woodev-orders-sync__item-label">
							{ __( 'Обновлено', 'woodev-plugin-framework' ) }
						</span>
						<span className="woodev-orders-sync__item-value">
							{ null === syncStatus.last_updated
								? __( 'Ни разу', 'woodev-plugin-framework' )
								: lastUpdated.title }
						</span>
					</span>
					<span className="woodev-orders-sync__item">
						<span className="woodev-orders-sync__item-label">
							{ __( 'Обновится', 'woodev-plugin-framework' ) }
						</span>
						<span className="woodev-orders-sync__item-value">
							{ null === nextUpdate
								? __( 'Не по расписанию', 'woodev-plugin-framework' )
								: nextUpdate.title }
						</span>
					</span>
				</div>
			</div>
		</div>
	);
}

/**
 * The «Все (134) | Новые (7)» scope links directly above the table (#841).
 *
 * ⚠ **The NUMBERS are the requirement, not the switching.** The operator chose this
 * form over a toggle on 11.09.2026, with an ASCII mock in front of him, for one
 * stated reason: the count is visible, so the merchant can check «Новые (7)» against
 * the badge in the admin menu with their own eyes. A control that switched scope
 * without showing both counts would satisfy the mechanics and lose the point.
 *
 * Both numbers come from ONE response (`scope_counts`,
 * `Orders_Controller::build_scope_counts()`), and that too is the requirement rather
 * than an optimisation — two round trips can answer from two different states of the
 * table, and a pair of links whose numbers can contradict each other is worse than
 * no links.
 *
 * ⚠ **Real `href`s, not buttons.** Same rule as every other filter on this page: the
 * scope lives in the URL, so the view is linkable and the browser's back button works
 * on it. `getNewPath()` builds the href and `Link type="wc-admin"` prefixes it and
 * keeps the click inside the single-page app — the two are a documented pair, and
 * hand-building that prefix would be a guess. Missing either one means an older
 * runtime, and then this control does not render rather than rendering a dead link
 * (the same degrade rule {@link DataStatusPanel} and {@link RoiPanel} follow).
 *
 * The shape is WordPress's own counted-scope row («Все (134) | Новые (7)»), including
 * marking the current one `current` the way a WP list table does. The separator is
 * drawn in CSS rather than written into the DOM, so a screen reader hears two links
 * and not a stray pipe.
 */
function ScopeLinks( {
	scope,
	counts,
}: {
	scope: string;
	counts: OrdersScopeCounts;
} ) {
	const Link = window.wc?.components?.Link;
	const navigation = window.wc?.navigation;
	const getNewPath = navigation?.getNewPath;

	if ( ! Link || ! navigation || ! getNewPath ) {
		return null;
	}

	const path = navigation.getPath();
	const query = navigation.getQuery();

	const scopes: { value: string; label: string; count: number }[] = [
		{ value: 'all', label: __( 'Все', 'woodev-plugin-framework' ), count: counts.all },
		{ value: NEW_SCOPE, label: __( 'Новые', 'woodev-plugin-framework' ), count: counts.new },
	];

	return (
		<ul className="woodev-orders__scopes">
			{ scopes.map( ( entry ) => {
				const isCurrent = entry.value === scope;

				return (
					<li key={ entry.value } className="woodev-orders__scope">
						<Link
							href={ getNewPath( scopeQuery( entry.value ), path, query ) }
							type="wc-admin"
							className={
								isCurrent
									? 'woodev-orders__scope-link current'
									: 'woodev-orders__scope-link'
							}
							aria-current={ isCurrent ? 'page' : undefined }
						>
							{ entry.label }{ ' ' }
							{ /*
							 * Rendered at zero as well — a count that disappears when it
							 * reaches 0 is exactly the moment the merchant most needs to
							 * read it, because «Новые (0)» is the answer «нет новых
							 * заказов», while a missing number reads as a broken control.
							 */ }
							<span className="woodev-orders__scope-count">
								({ entry.count })
							</span>
						</Link>
					</li>
				);
			} ) }
		</ul>
	);
}

export default function OrdersPage() {
	const providers = getProviders();
	const hasCarrierFilter = providers.length > 1;

	const [ urlFilters, setUrlFilters ] = useState<UrlFilters>( () => readUrlFilters( getQuery() ) );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( DEFAULT_PER_PAGE );
	const [ orderby, setOrderby ] = useState( 'date' );
	const [ order, setOrder ] = useState<'ASC' | 'DESC'>( 'DESC' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ rows, setRows ] = useState<OrderRow[] | null>( null );
	const [ total, setTotal ] = useState( 0 );
	/**
	 * #841 — both scope-link numbers, straight from the response that built the rows.
	 * `null` means "we do not have them", which is why {@link ScopeLinks} does not
	 * render then: showing a number the page had to invent would defeat the whole
	 * reason the operator chose counted links.
	 */
	const [ scopeCounts, setScopeCounts ] = useState<OrdersScopeCounts | null>( null );
	/**
	 * #855 — one number per carrier, from the response that built the rows, so the
	 * carrier picker's counts follow the period and every other active filter instead of
	 * standing for "the whole table, forever" the way the inlined bootstrap ones did.
	 *
	 * `null` means «мы ещё не знаем», and that is NOT zero: before the first response the
	 * options render with no number at all rather than with «(0)», which would be a
	 * measurement the page never took.
	 */
	const [ carrierCounts, setCarrierCounts ] = useState<Record<string, number> | null>( null );
	const [ error, setError ] = useState( '' );
	/**
	 * #824 — one {@link RowActionState} per order id, only for rows that currently have
	 * something to show (pending or awaiting confirm); a row absent from this map is
	 * neither. A plain object rather than a `Map` because it is only ever read/written
	 * through `setState`, the same way every other piece of state on this page is.
	 */
	const [ actionRowStates, setActionRowStates ] = useState<Record<number, RowActionState>>( {} );
	/**
	 * #824 — the last row action's outcome, shown in the SAME `Notice` slot the fetch
	 * error above already uses rather than a second mechanism. Unlike `error`, this is
	 * dismissible: it reports a single past event, not a condition the table is still in,
	 * so there is nothing wrong with the merchant clearing it themselves.
	 */
	const [ actionNotice, setActionNotice ] = useState<{ status: 'success' | 'error'; text: string } | null>(
		null
	);

	const searchDebounce = useRef<ReturnType<typeof setTimeout> | null>( null );
	/**
	 * #824 round 2 (MEDIUM 4) — bumped every time the fetch effect below starts a NEW
	 * GET, so {@link performAction} can tell whether the view it fired an action from
	 * is still the current one by the time the action's response comes back.
	 *
	 * Sequence this guards: click «Выгрузить» on order 42 in «Все»; switch to «Новые»
	 * while the POST is in flight; the new GET (which bumps this ref) legitimately
	 * excludes 42; the POST then resolves — without the guard it would still write the
	 * exported row 42 into the rows array, resurrecting it into a table it no longer
	 * belongs in. The NOTICE still shows either way: the action really did happen and
	 * the merchant must be told. Only the row swap is conditional.
	 */
	const fetchGeneration = useRef( 0 );

	// Every control in the filter row — both `FilterPicker`s, `DateRangeFilterPicker`,
	// `AdvancedFilters` — changes the URL by NAVIGATING rather than calling back with a
	// value, so the only way to learn about a pick, or about the browser's back button,
	// is the history.
	//
	// ⚠ TWO steps, and it is not a style choice. `addHistoryListener` monkey-patches
	// `window.history.pushState` (`packages/js/navigation/src/index.js:134`) and fires its
	// `pushstate` event BEFORE delegating to the real `pushState`:
	//
	//     history.pushState = function ( state ) {
	//         window.dispatchEvent( pushStateEvent );       // listeners run HERE
	//         return pushState.apply( history, arguments ); // the URL changes AFTER
	//     };
	//
	// so calling `getQuery()` inside the listener reads the PREVIOUS URL and the page is
	// permanently one navigation behind — measured on the rig 09.09.2026: pressing «Filter»
	// wrote `delivery_status_is=pending` into the address bar and the table went on showing
	// every order. Raising a flag here and reading the query in the effect below puts the
	// read after the current call stack, by which time the URL has settled. This is exactly
	// the shape WooCommerce's own `useQuery()` hook uses (same file, :216) and for exactly
	// this reason.
	const [ locationChanged, setLocationChanged ] = useState( false );

	useEffect( () => {
		const navigation = window.wc?.navigation;

		if ( ! navigation ) {
			return;
		}

		// The listener returns its own unlisten function (verified against the live runtime).
		return navigation.addHistoryListener( () => setLocationChanged( true ) );
	}, [] );

	// A change to any filter starts at page 1 (requirement #4) — `filtersEqual` decides "any".
	useEffect( () => {
		if ( ! locationChanged ) {
			return;
		}

		const query = getQuery();

		setUrlFilters( ( current ) => {
			const next = readUrlFilters( query );

			/**
			 * ⚠ Returning `current` — the SAME reference — is what stops a navigation that
			 * changed no filter from refetching the table (#850, operator on the rig
			 * 11.09.2026: the «Расширенные фильтры» toggle sent an identical request).
			 *
			 * `readUrlFilters()` builds a fresh object every time, and React bails out of a
			 * state update by `Object.is`, not by contents — so handing back an equal-but-new
			 * object IS a state change. The fetch effect below lists `urlFilters` among its
			 * dependencies, so it re-ran, `setRows( null )` dropped the table into its loading
			 * skeleton, and the same query came back with the same rows.
			 *
			 * `filter=advanced` is not one of the nine fields `readUrlFilters()` reads, which
			 * is why this shows up on a control that selects nothing at all. The verdict was
			 * already being computed here — it was spent on the page reset and thrown away for
			 * the identity, which is the whole defect.
			 */
			if ( filtersEqual( current, next ) ) {
				return current;
			}

			setPage( 1 );

			return next;
		} );

		setLocationChanged( false );
	}, [ locationChanged ] );

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

		fetchGeneration.current += 1;

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
			statusNot: urlFilters.statusNot,
			deliveryStatus: urlFilters.deliveryStatus,
			deliveryStatusNot: urlFilters.deliveryStatusNot,
			hasTracking: urlFilters.hasTracking,
			hasPickupPoint: urlFilters.hasPickupPoint,
			// #841: «Новые» IS `is_exported=false`, and «Все» is the absence of the arg
			// rather than `true` — `filters.ts` owns that mapping because the tri-state
			// is a REST contract, not a display choice.
			isExported: isExportedForScope( urlFilters.scope ),
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setRows( ( res && res.rows ) || [] );
				setTotal( ( res && res.total ) || 0 );
				// An older server sends no `scope_counts` at all; `null` is «not
				// stated», which hides the links rather than showing them zeros.
				setScopeCounts( ( res && res.scope_counts ) || null );
				// #855: same rule for the carrier counts — «not stated» leaves the
				// picker's options unnumbered rather than numbered zero.
				setCarrierCounts( ( res && res.carrier_counts ) || null );
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
				// #841: the last response's counts described a table that is no longer
				// on screen, and a stale «Новые (7)» above an empty table is exactly
				// the number the merchant would carry over to the menu badge and
				// mistrust. Dropping the links is the honest answer; the carrier
				// picker and the toggle stay mounted, so the view is still escapable.
				setScopeCounts( null );
				// #855: and the carrier counts described the same dead table. Dropping
				// them leaves the picker's options unnumbered — the control still works,
				// it just stops claiming to know how many orders each carrier has.
				setCarrierCounts( null );
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

	/**
	 * #824 — sends one row action. Success swaps the row IN PLACE (never a refetch: a
	 * refetch can return a different page of a moving dataset and the merchant loses
	 * their place) and shows the server's message; failure leaves the row untouched and
	 * shows the server's message as an error. Both branches clear this row's
	 * pending/confirm state, whether the call succeeded or not.
	 *
	 * ⚠ The scope counts and carrier counts above the table are computed by the SAME
	 * request that built the rows, and a successful export changes which bucket the order
	 * is in — replacing one row does not update them. Left stale until the next natural
	 * refetch on purpose: recomputing them client-side would defeat the reason they are
	 * server-computed at all (#841/#855 — the merchant checks them against the menu
	 * badge), and staleness here is honest, not silent.
	 */
	const performAction = ( row: OrderRow, action: OrderRowAction ) => {
		setActionRowStates( ( current ) => ( {
			...current,
			[ row.id ]: { pendingAction: action.action, confirmingAction: null },
		} ) );

		// Captured NOW, before the request goes out — compared against the live ref
		// when the response comes back, so a refetch that lands first (the merchant
		// switched scope/filter/page while the action was in flight) is detected.
		const generation = fetchGeneration.current;

		performOrderAction( row.id, action.action )
			.then( ( res ) => {
				setActionNotice( { status: 'success', text: res.message } );

				// The action really did happen — the notice above always shows. Only the
				// row swap is conditional: a table refetched since this action started is
				// no longer the view this row belongs in.
				if ( fetchGeneration.current === generation ) {
					setRows( ( current ) =>
						current ? current.map( ( r ) => ( r.id === row.id ? res.row : r ) ) : current
					);
				}
				setActionRowStates( ( current ) => {
					const next = { ...current };
					delete next[ row.id ];
					return next;
				} );
			} )
			.catch( ( err: { message?: string } ) => {
				setActionNotice( {
					status: 'error',
					text:
						( err && err.message ) ||
						__( 'Не удалось выполнить действие.', 'woodev-plugin-framework' ),
				} );
				setActionRowStates( ( current ) => {
					const next = { ...current };
					delete next[ row.id ];
					return next;
				} );
			} );
	};

	/**
	 * A `destructive` action's first click never reaches the network — it only flips
	 * this row into the inline «Да / Нет» confirm state. A second click on the SAME
	 * action (now rendered as «Да») is what {@link ActionsCell} routes back here as the
	 * real confirmation, so a non-destructive action and a confirmed destructive one
	 * both end up calling {@link performAction} the same way.
	 */
	const onActionClick = ( row: OrderRow, action: OrderRowAction ) => {
		if ( action.destructive && actionRowStates[ row.id ]?.confirmingAction !== action.action ) {
			setActionRowStates( ( current ) => ( {
				...current,
				[ row.id ]: { pendingAction: null, confirmingAction: action.action },
			} ) );
			return;
		}

		performAction( row, action );
	};

	/** «Нет» on the inline confirm — drops the row back to its normal, un-confirming state. */
	const onCancelConfirm = ( orderId: number ) => {
		setActionRowStates( ( current ) => {
			const next = { ...current };
			delete next[ orderId ];
			return next;
		} );
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
		// `SCOPE_PARAM` (#841) belongs to neither picker's config, so it would survive
		// `getAllFilterParams()` on its own — it is listed for the same reason as the
		// rest: «which work queue am I in» is independent of carrier, and the list is
		// where that intent is written down.
		staticParams: [ FILTER_PARAM, SCOPE_PARAM, ...DATE_AND_ADVANCED_PARAMS ],
		showFilters: () => true,
		defaultValue: ALL_CARRIERS,
		/**
		 * #855: the number comes from the RESPONSE, not from the inlined bootstrap, so
		 * it describes the same orders the table beside it does — pick «С начала
		 * недели» and «СДЭК (3)» means three this week, not three ever.
		 *
		 * ⚠ No number at all until a response has arrived, and «(0)» is not the same
		 * statement. `undefined` here is «мы ещё не считали»; zero is «посчитали, и их
		 * нет». Rendering the second while meaning the first is how a merchant reads a
		 * loading page as an empty shop.
		 */
		filters: providers.map( ( p ) => {
			const count = carrierCounts ? carrierCounts[ p.id ] : undefined;

			return {
				label: undefined === count ? p.label : `${ p.label } (${ count })`,
				value: p.id,
			};
		} ),
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
	const advancedFiltersConfig = buildAdvancedFiltersConfig(
		DELIVERY_STATUS_LABELS,
		orderStatusOptions,
		getReachableDeliveryStatuses()
	);

	const TableCard = window.wc?.components?.TableCard;
	const FilterPicker = window.wc?.components?.FilterPicker;
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
	// only narrows `dateApi`/`currency` etc. from the actual condition guarding
	// that JSX, not from a boolean copy of it — so this is only for the wrapper
	// `<div>`'s own visibility.
	/**
	 * The advanced block is revealed by the display-mode picker's LAST option
	 * (#835 — its own `FILTER_PARAM`, split from the carrier picker), never
	 * standing open. Read from the URL like every other filter here —
	 * `FilterPicker` navigates instead of calling back.
	 */
	const advancedOpen = isAdvancedFiltersOpen( getQuery() );

	// The display-mode picker (#835) is offered whenever `FilterPicker` exists, so it
	// subsumes the carrier picker's own condition — `hasCarrierFilter` only decides
	// whether the CARRIER picker renders, further down, not whether the row does.
	const hasAnyFilterControl = Boolean(
		( FilterPicker && navigation ) ||
			( navigation && dateApi ) ||
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
			{ /* #824 — one row action's outcome, in the SAME slot as the fetch error above. */ }
			{ actionNotice && (
				<Notice
					status={ actionNotice.status }
					isDismissible
					onRemove={ () => setActionNotice( null ) }
				>
					{ actionNotice.text }
				</Notice>
			) }
			{ hasAnyFilterControl && (
				<div className="woodev-orders__filters">
					<div className="woodev-orders__header">
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
					 * #855: OUR control, not `DateRangeFilterPicker` — the default
					 * period on this page is «всё время», which theirs cannot offer
					 * (its preset list comes from `@woocommerce/date`'s module export,
					 * not from a prop). It reuses their calendar for the custom range
					 * and their class names for the shape.
					 *
					 * Degrades — renders nothing for this one control — when `wc-date`
					 * is unavailable (an older WooCommerce), the same rule `RoiPanel`
					 * already follows, rather than crashing the whole page over one
					 * missing filter. Without `wc.date` there is nothing to resolve a
					 * period INTO, so a picker that still rendered would write a URL
					 * that filters nothing.
					 */ }
					{ navigation && dateApi && (
						<PeriodPicker
							query={ getQuery() }
							dateApi={ dateApi }
							onUpdate={ ( patch ) => {
								navigation.updateQueryString?.(
									patch,
									navigation.getPath(),
									navigation.getQuery()
								);
							} }
						/>
					) }
					</div>
					{ /*
					 * ⚠ The «Статус данных» block is a SIBLING of the pickers, not one of
					 * them — that is how WooCommerce lays its own out, measured on the rig
					 * 09.09.2026: `.woocommerce-analytics-report-header` is the flex row, and
					 * it holds `.woocommerce-filters` and the status wrapper side by side.
					 * Putting it INSIDE the pickers row is what made it wrap onto its own
					 * line (operator, same day). It manages its own visibility.
					 */ }
					<DataStatusPanel />
					</div>
					{ /*
					 * The display MODE is a TOGGLE, not a picker — operator, 09.09.2026, on the
					 * rig. While «Показать» held one axis (a specific carrier OR a pointwise
					 * filter across all of them) a list was the honest control. Splitting the
					 * carrier out onto its own picker (#835) left this one with exactly two
					 * states, and a two-state list is a wasted click plus a false promise of a
					 * third option. The control type carries part of the meaning (Rule 10a).
					 *
					 * It sits on its OWN row BENEATH the two pickers, not between them — operator,
					 * 09.09.2026, after seeing it wedged in the middle. A toggle has no label above
					 * it, so in a row of labelled selects it reads as something that fell out of
					 * alignment rather than as a control of its own.
					 *
					 * ⚠ Turning it OFF must also clear the advanced filters, and that is now OUR
					 * job: the clearing used to come free from `FilterPicker.update()`'s
					 * hard-coded `param === 'filter'` branch, which no longer runs. See
					 * `advancedFiltersToggleQuery()`.
					 */ }
					{ navigation && (
						<div className="woodev-orders__mode-toggle">
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Расширенные фильтры', 'woodev-plugin-framework' ) }
								checked={ advancedOpen }
								onChange={ ( next: boolean ) => {
									navigation.updateQueryString?.(
										advancedFiltersToggleQuery( next ),
										navigation.getPath(),
										navigation.getQuery()
									);
								} }
							/>
						</div>
					) }
					{ /*
					 * NOT a permanently visible region: revealed by the display-mode TOGGLE
					 * above (#835, operator 09.09.2026), the way Analytics reveals its own.
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
			{ /*
			 * ⚠ The scope links sit BETWEEN the filter row and the table — their own
			 * row, outside `.woodev-orders__filters` entirely (#841, operator
			 * 11.09.2026, ASCII mock). NOT in the row of pickers: he has corrected
			 * that row's geometry twice in two days, and a counted-link pair is not a
			 * filter control anyway — it is the same «Все (134) | Новые (7)» row an
			 * ordinary WordPress list puts directly above its table.
			 */ }
			{ scopeCounts && <ScopeLinks scope={ urlFilters.scope } counts={ scopeCounts } /> }
			<TableCard
				className="woodev-orders"
				title={ __( 'Заказы доставки', 'woodev-plugin-framework' ) }
				headers={ HEADERS }
				rows={
					null === rows
						? []
						: rows.map( ( row ) =>
								buildRow( row, {
									rowState: actionRowStates[ row.id ],
									onActionClick,
									onCancelConfirm,
								} )
						  )
				}
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
