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
}

/** The full response envelope `Orders_Controller::get_items()` returns. */
export interface OrdersResponse {
	rows: OrderRow[];
	total: number;
	total_pages: number;
}

/** One entry of the inlined provider list (`Orders_Registry::build_bootstrap_providers()`). */
export interface OrdersProvider {
	id: string;
	label: string;
	count: number;
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
 * registered carrier, each already carrying a count (see
 * `Orders_Registry::build_bootstrap_providers()`).
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

	return apiFetch<OrdersResponse>( {
		url: `${ restRoot }?${ params.toString() }`,
		method: 'GET',
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
