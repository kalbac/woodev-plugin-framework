/**
 * REST client for the shipping orders page (woodev/v1/shipping/orders), and the
 * types for what it actually returns.
 *
 * The row shape mirrors `Order_Row_Builder::build()` (and its `build_customer()` /
 * `build_payment()` / `build_shipping()` / `build_tracking()` /
 * `resolve_delivery_status()` field groups) and `Orders_Controller::get_items()`'s
 * response envelope, read with Serena rather than inferred from a consumer.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';

/** `status` — the plain WC order status (increment 1; kept alongside `delivery_status`). */
export interface OrderRowStatus {
	slug: string;
	label: string;
}

/** `carrier` — the provider this row matched, or `null` when it could not be resolved. */
export interface OrderRowCarrier {
	id: string;
	label: string;
}

/** `customer` — M1: byte-for-byte identical shape across two shipped plugins. */
export interface OrderRowCustomer {
	name: string;
	email: string;
	phone: string;
	user_id: number;
	user_edit_url: string | null;
}

/** `payment` — method + total; `is_exported()`-gated hint deliberately excluded (2a). */
export interface OrderRowPayment {
	method_title: string;
	formatted_total: string;
	needs_payment: boolean;
}

/** `shipping` — method + total + the resolved destination (D3's one carrier seam). */
export interface OrderRowShipping {
	method_title: string;
	formatted_total: string;
	destination_kind: string;
	destination_text: string;
}

/** `tracking` — a missing number means BOTH fields null, never a dash (that is a display choice). */
export interface OrderRowTracking {
	number: string | null;
	url: string | null;
}

/** `type` — resolved via `Shipping_Method::get_delivery_type()`; never guessed. */
export type ShippingType = 'courier' | 'pickup' | 'postal' | 'unknown';

/** The nine canonical states from `Delivery_Status` plus its distinct `unknown`. */
export type DeliveryStatusCanonical =
	| 'pending'
	| 'created'
	| 'in_transit'
	| 'ready_for_pickup'
	| 'delivered'
	| 'returning'
	| 'returned'
	| 'failed'
	| 'cancelled'
	| 'unknown';

/** `delivery_status` — canonical label in the cell, raw carrier label alongside it. */
export interface OrderRowDeliveryStatus {
	canonical: DeliveryStatusCanonical;
	canonical_label: string;
	raw: string | null;
	raw_label: string | null;
}

/** One row of `GET woodev/v1/shipping/orders`, exactly as `Order_Row_Builder::build()` returns it. */
export interface OrderRow {
	id: number;
	order_number: string;
	edit_url: string;
	date_created: string | null;
	status: OrderRowStatus;
	carrier: OrderRowCarrier | null;
	customer: OrderRowCustomer;
	payment: OrderRowPayment;
	shipping: OrderRowShipping;
	type: ShippingType;
	tracking: OrderRowTracking;
	delivery_status: OrderRowDeliveryStatus;
	/**
	 * #824: whether the order has ever been exported to the carrier. Optional for the same
	 * reason as {@link OrdersResponse.scope_counts} and {@link OrdersResponse.carrier_counts} —
	 * a cached bundle can outlive a rollback, and «not stated» must not be rendered as `false`.
	 */
	is_exported?: boolean;
	/**
	 * #824: the row's action buttons, in server order. Optional for the same reason as
	 * `is_exported` above — an older server sends neither field, and «not stated» renders
	 * an empty cell, never an invented button.
	 */
	actions?: OrderRowAction[];
}

/**
 * The two numbers the «Все / Новые» scope links above the table render (#841),
 * `Orders_Controller::build_scope_counts()`.
 *
 * ⚠ Neither of these is `total`. `total` is the count for the scope the merchant is
 * currently IN and drives pagination; these two describe what EACH link would show,
 * so on «Новые» `total === scope_counts.new` while `scope_counts.all` is the larger
 * number the other link needs. Both respect every other active filter.
 *
 * They arrive in this response and not in one of their own on purpose: two round
 * trips can answer from two different states of the table, and a pair of links whose
 * numbers can contradict each other is worse than no links at all.
 */
export interface OrdersScopeCounts {
	all: number;
	new: number;
}

/**
 * One action offered on a row (#824), e.g. «Выгрузить» / «Обновить» / «Отменить» — or a
 * carrier extra registered through the server-side filter. Only AVAILABLE actions are
 * sent; there is no disabled state to render, so an action missing from this array is an
 * action that does not exist for this row, never one the merchant cannot currently use.
 */
export interface OrderRowAction {
	/** `'export' | 'update' | 'cancel'`, or a carrier extra from the server-side filter. */
	action: string;
	/** Button text, already translated server-side. */
	label: string;
	/** Tooltip; `''` when there is none. */
	title: string;
	/** `true` => confirm before sending. */
	destructive: boolean;
}

/** The full response envelope `Orders_Controller::get_items()` returns. */
export interface OrdersResponse {
	rows: OrderRow[];
	total: number;
	total_pages: number;
	/**
	 * Optional only because an older server does not send it (#841's server half and
	 * this bundle ship together, but a cached bundle can outlive a rollback). Absent
	 * means «not stated», and the scope links then do not render — a link showing a
	 * number it had to invent would defeat the entire point of the control, which is
	 * that the merchant can check the number against the badge in the menu.
	 */
	scope_counts?: OrdersScopeCounts;
	/**
	 * One number per carrier id — `all` plus every registered provider (#855),
	 * `Orders_Controller::build_carrier_counts()`.
	 *
	 * Computed from the SAME request as the rows, so they follow the period and every
	 * other active filter: with «С начала недели» picked, «СДЭК (3)» means three this
	 * week. The counts used to be inlined into the page bootstrap once per page load and
	 * therefore described the whole table forever, which made them disagree with the
	 * table under every filter the page has.
	 *
	 * Optional for the same reason as {@link OrdersScopeCounts}: a cached bundle can
	 * outlive a rollback, and «not stated» must not be rendered as «(0)».
	 */
	carrier_counts?: Record<string, number>;
}

/**
 * One entry of the inlined provider list (`Orders_Registry::build_bootstrap_providers()`).
 *
 * ⚠ NO COUNT (#855). What the page needs before its first fetch is WHICH carriers exist,
 * so the picker can render; how many orders each has is an answer to a question that
 * includes the current filters, and the bootstrap is written once per page load and
 * knows none of them. That number arrives with the rows — see
 * {@link OrdersResponse.carrier_counts}.
 */
export interface OrdersProvider {
	id: string;
	label: string;
}

/** `window.woodevShippingOrders`, inlined by `Orders_Registry::enqueue_assets()`. */
export interface ShippingOrdersBootstrap {
	restRoot: string;
	nonce: string;
	providers: OrdersProvider[];
	/**
	 * The canonical delivery statuses THIS SHOP can produce (#837 defect 4), built by
	 * `Orders_Registry::build_reachable_delivery_statuses()`. Optional on purpose: an
	 * older inlined bootstrap does not carry it, and the filter then degrades to
	 * offering every canonical state rather than offering none.
	 */
	deliveryStatuses?: string[];
}

declare global {
	interface Window {
		woodevShippingOrders?: ShippingOrdersBootstrap;
	}
}

function bootstrap(): Partial<ShippingOrdersBootstrap> {
	return window.woodevShippingOrders || {};
}

/**
 * Returns the inlined provider list — the aggregate entry first, then one per
 * registered carrier (see `Orders_Registry::build_bootstrap_providers()`). Ids and
 * labels only; the counts come back with the rows (#855).
 */
export function getProviders(): OrdersProvider[] {
	return bootstrap().providers || [];
}

/**
 * The canonical delivery statuses this shop can actually produce, or an EMPTY array
 * when the bootstrap does not say — two different answers the caller must not
 * conflate: empty means «not stated», and the filter then offers all of them. A shop
 * that genuinely produces none still gets `unknown` from the server, so a non-empty
 * list is never ambiguous.
 */
export function getReachableDeliveryStatuses(): string[] {
	return bootstrap().deliveryStatuses || [];
}

/**
 * Request params `fetchOrders()` accepts — increment 1's original set plus the
 * SP-10 spec D10/D11 filter row (increment 6's server half, already merged:
 * `Orders_Controller::register_routes()` — read with Serena, not inferred from
 * a consumer).
 */
export interface FetchOrdersArgs {
	carrier?: string;
	page?: number;
	perPage?: number;
	orderby?: string;
	order?: string;
	search?: string;
	/** ISO `YYYY-MM-DD`, inclusive lower bound on `date_created`. */
	after?: string;
	/** ISO `YYYY-MM-DD`, inclusive upper bound on `date_created`. */
	before?: string;
	/** Native WC order status slugs (the REST route's own `status` arg — an array, not a single value). */
	status?: string[];
	/** One canonical {@link DeliveryStatusCanonical} value, or '' for "no filter". */
	deliveryStatus?: string;
	/** #836: «не равен» — a separate REST arg, built server-side as a marker-bound NOT EXISTS group. */
	deliveryStatusNot?: string;
	/** #836: WC order statuses to EXCLUDE. */
	statusNot?: string[];
	/** #836: presence of a pickup point; PRESENCE of the arg decides, as with `hasTracking`. */
	hasPickupPoint?: boolean;
	/**
	 * `undefined` means "no filter" — distinct from `false`. The REST route's
	 * `has_tracking` arg carries no default; its PRESENCE, not its truthiness,
	 * decides whether the query applies it (D10), so this must stay a tri-state.
	 */
	hasTracking?: boolean;
	/**
	 * #841: whether the order has ever been exported to the carrier. The «Новые»
	 * scope link sends `false`; «Все» sends nothing at all. Tri-state for the same
	 * reason as `hasTracking` — the REST arg's PRESENCE is what decides.
	 */
	isExported?: boolean;
}

/**
 * Fetches one page of rows for a carrier (or the aggregate).
 */
export function fetchOrders( {
	carrier = 'all',
	page = 1,
	perPage = 20,
	orderby = 'date',
	order = 'DESC',
	search = '',
	after = '',
	before = '',
	status = [],
	statusNot = [],
	deliveryStatus = '',
	deliveryStatusNot = '',
	hasTracking,
	hasPickupPoint,
	isExported,
}: FetchOrdersArgs = {} ): Promise<OrdersResponse> {
	const { restRoot = '', nonce = '' } = bootstrap();

	const params = new URLSearchParams( {
		carrier,
		page: String( page ),
		per_page: String( perPage ),
		orderby,
		order,
	} );

	if ( search ) {
		params.set( 'search', search );
	}

	if ( after ) {
		params.set( 'after', after );
	}

	if ( before ) {
		params.set( 'before', before );
	}

	if ( status.length > 0 ) {
		// The REST route's `status` arg is a WP REST `array` type — a comma-separated
		// string is WordPress's own documented shorthand for it, not an invented one.
		params.set( 'status', status.join( ',' ) );
	}

	if ( statusNot.length > 0 ) {
		params.set( 'status_not', statusNot.join( ',' ) );
	}

	if ( deliveryStatus ) {
		params.set( 'delivery_status', deliveryStatus );
	}

	if ( deliveryStatusNot ) {
		params.set( 'delivery_status_not', deliveryStatusNot );
	}

	if ( undefined !== hasTracking ) {
		params.set( 'has_tracking', hasTracking ? 'true' : 'false' );
	}

	if ( undefined !== hasPickupPoint ) {
		params.set( 'has_pickup_point', hasPickupPoint ? 'true' : 'false' );
	}

	if ( undefined !== isExported ) {
		params.set( 'is_exported', isExported ? 'true' : 'false' );
	}

	return apiFetch<OrdersResponse>( {
		url: `${ restRoot }?${ params.toString() }`,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}

/** `POST /shipping/orders/<id>/actions/<action>`'s success envelope (#824). */
export interface OrderActionResult {
	/** The freshly rebuilt row for this order — swap it in place, never refetch the page. */
	row: OrderRow;
	/** A Russian sentence to show the merchant. */
	message: string;
}

/**
 * Performs one row action (#824 — «Выгрузить» / «Обновить» / «Отменить», or a carrier
 * extra). Same `bootstrap()`/`apiFetch` wiring as {@link fetchOrders}, so the nonce is
 * handled the same way.
 *
 * A rejection carries the server's own Russian `message` (`apiFetch` rejects with
 * `{ message?: string, code?: string }` on a REST error) — the caller must show it, never
 * swallow it.
 */
export function performOrderAction( orderId: number, action: string ): Promise<OrderActionResult> {
	const { restRoot = '', nonce = '' } = bootstrap();

	return apiFetch<OrderActionResult>( {
		url: `${ restRoot.replace( /\/+$/, '' ) }/${ orderId }/actions/${ action }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
	} );
}

/** One entry of `GET /shipping/orders/sync-status`'s `carriers` array. */
export interface SyncStatusCarrier {
	id: string;
	label: string;
	/** Unix timestamp (seconds), or `null` when this carrier has never synced. */
	last_updated: number | null;
	/** Unix timestamp (seconds), or `null` for a webhook-only carrier — it has no cron to report. */
	next_update: number | null;
}

/**
 * `GET /shipping/orders/sync-status`'s response (#828, `Orders_Controller::get_sync_status()`,
 * read with Serena). `last_updated` here is the AGGREGATE: `null` the moment any registered
 * carrier has never synced, not merely the oldest of the ones that have — see that method's
 * own docblock. `carriers` is what explains WHY, and is never empty unless no carrier is
 * registered at all.
 */
export interface SyncStatusResponse {
	last_updated: number | null;
	carriers: SyncStatusCarrier[];
}

/**
 * Fetches the delivery-status sync freshness (#828 increment 8). Same `bootstrap()`/`apiFetch`
 * wiring as {@link fetchOrders}, at a sibling route under the same REST root.
 */
export function fetchSyncStatus(): Promise<SyncStatusResponse> {
	const { restRoot = '', nonce = '' } = bootstrap();

	return apiFetch<SyncStatusResponse>( {
		url: `${ restRoot.replace( /\/+$/, '' ) }/sync-status`,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}
