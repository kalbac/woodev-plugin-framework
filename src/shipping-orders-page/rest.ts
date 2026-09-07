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
	adminUrl: string;
	providers: OrdersProvider[];
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

/** Request params `fetchOrders()` accepts — exactly what increment 1's REST route already takes. */
export interface FetchOrdersArgs {
	carrier?: string;
	page?: number;
	perPage?: number;
	orderby?: string;
	order?: string;
	search?: string;
}

/**
 * Fetches one page of rows for a carrier (or the aggregate).
 *
 * Only the params increment 1's REST route already accepts are sent — no new
 * REST param is invented here.
 */
export function fetchOrders( {
	carrier = 'all',
	page = 1,
	perPage = 20,
	orderby = 'date',
	order = 'DESC',
	search = '',
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

	return apiFetch<OrdersResponse>( {
		url: `${ restRoot }?${ params.toString() }`,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}
