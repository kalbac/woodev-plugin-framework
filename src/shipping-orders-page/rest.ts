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
	/**
	 * #1007: the WC order is cancelled but the carrier refused to cancel its shipment, so the
	 * request is still live on the carrier's side. Optional like `is_exported` — an older server
	 * sends no such field, and «not stated» must not be rendered as a failure.
	 */
	cancel_failed?: boolean;
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
 * carrier extra registered through the server-side filter. An available action can be
 * temporarily disabled when another manager holds the order's native WooCommerce edit lock.
 */
export interface OrderRowAction {
	/**
	 * `'export' | 'update' | 'cancel'`, or a carrier extra from the server-side filter — or `'edit'`
	 * (#972), which opens the order wizard on the client and is never sent to the action routes.
	 */
	action: string;
	/** Button text, already translated server-side. */
	label: string;
	/** Tooltip; `''` when there is none. */
	title: string;
	/** `true` => confirm before sending. */
	destructive: boolean;
	/** `true` when another manager holds the order's live WooCommerce edit lock. */
	disabled?: boolean;
	/** Display name of that other manager, present only with `disabled`. */
	lock_owner?: string;
	/**
	 * #1180: the input the action asks for before it runs. Absent for an action that needs none —
	 * which then behaves exactly as it always did: one click, straight to the server.
	 */
	fields?: OrderActionField[];
	/**
	 * The action's own icon: a Dashicons slug without the `dashicons-` prefix (`'upload'`, `'media-document'`).
	 * Absent => the neutral fallback glyph, never a gear.
	 */
	icon?: string;
	/**
	 * #1192: on a document action (`waybill`, `barcode`) — the carrier can print it for SEVERAL orders as one file,
	 * so the bulk picker offers the matching «Печать …» entry. Absent => single-order only.
	 */
	bulk?: boolean;
}

/** The `payload` an action with fields sends: field id → value (a time range is `{ from, to }`). */
export type OrderActionPayload = Record< string, string | OrderActionTimeRange >;

export interface OrderActionTimeRange {
	from: string;
	to: string;
}

/** What every declared field carries (#1180, `Order_Action_Fields::sanitize()`). */
interface OrderActionFieldBase {
	id: string;
	label: string;
	required: boolean;
}

/**
 * One input an action declares — a small closed set, not a form engine. The server sends every key of
 * its type (an absent `min` / `max` means no bound), and checks the same bounds again on submit.
 */
export type OrderActionField =
	| ( OrderActionFieldBase & { type: 'date'; default: string; min?: string; max?: string } )
	| ( OrderActionFieldBase & { type: 'select'; default: string; options: { value: string; label: string }[] } )
	| ( OrderActionFieldBase & { type: 'time_range'; default: OrderActionTimeRange; min?: string; max?: string } )
	| ( OrderActionFieldBase & { type: 'textarea'; default: string; maxlength: number } );

/** One error of a rejected payload (`data.errors` of the route's 422), the shape the wizard's routes use. */
export interface OrderActionFieldError {
	field: string;
	code: string;
	message: string;
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

/**
 * `window.woodevShippingOrders.wizard` — the order wizard's reference data
 * (`Orders_Registry::build_wizard_bootstrap()`, #969). Every key is optional: an older
 * server sends none of it, and the wizard then falls back to plain text inputs.
 */
export interface WizardBootstrap {
	/** The framework-owned heartbeat payload key for an order wizard edit lock. */
	editLockHeartbeatKey?: string;
	/** Country code → name, the shop's whole list. */
	countries?: Record<string, string>;
	/** Country code → { WooCommerce STATE CODE → name }; countries without regions are absent. */
	states?: Record<string, Record<string, string>>;
	defaultCountry?: string;
	currency?: { code?: string; symbol?: string };
	/**
	 * The pickup picker's JS config per carrier that has a pickup handler, keyed by PROVIDER id
	 * (`Orders_Registry::collect_wizard_pickup()`, #970). A carrier absent here has no picker; its
	 * pickup tariffs, if any, fall back to a typed point code. Typed loosely on purpose: the shape
	 * is the storefront's own picker config, read by `order-wizard/pickup-session.ts`.
	 */
	pickup?: Record<string, Record<string, unknown>>;
	/**
	 * The shop's ENABLED payment methods, gateway id → title (`Orders_Registry::build_wizard_payment_bootstrap()`,
	 * #971). Inlined because `wc/v3/payment_gateways` demands `manage_woocommerce`. An empty PHP array
	 * arrives as `[]`, so read it through `Object.entries`, never assume an object.
	 */
	paymentMethods?: Record<string, string>;
	/** WooCommerce order statuses, slug WITHOUT the `wc-` prefix → name. */
	orderStatuses?: Record<string, string>;
	/** Statuses an edit may not move an order into (`Order_Actions::FINAL_STATUSES`). */
	finalStatuses?: string[];
	/** Statuses «сразу выгрузить перевозчику» is offered in (`Order_Actions::EXPORTABLE_STATUSES`, #974). */
	exportableStatuses?: string[];
	/** Whether WooCommerce taxes are on — the totals the manager sees are then before tax. */
	taxesEnabled?: boolean;
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
	/** The order wizard's reference data (#969). */
	wizard?: WizardBootstrap;
	/** #1007: the background carrier exports running right now, as the server worded them. */
	exportsInProgress?: ExportsInProgress;
}

/**
 * The «orders being exported right now» figure: `text` is the FINISHED Russian sentence with
 * the right plural ('' when `count` is 0), so the page shows it verbatim and never words it.
 * `heartbeatKey` names the WordPress heartbeat payload key that keeps it current.
 */
export interface ExportsInProgress {
	count: number;
	text: string;
	heartbeatKey: string;
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
 * An order a bulk document leaves out (#1192): `code` is one of the server's `Document_Controller::SKIP_CODES`
 * (`not_found`, `not_shipping`, `not_exported`, `unsupported`, `carrier_skipped`).
 */
export interface SkippedOrder {
	id: number;
	code: string;
}

/** What `GET /shipping/orders/<id>/documents/<type>` can hand back (#1134). `skipped` is set by the bulk route only. */
export type OrderDocument =
	/** The carrier's PDF bytes, already fetched. */
	| { kind: 'file'; blob: Blob; filename: string; skipped?: SkippedOrder[] }
	/** A direct carrier link to open. */
	| { kind: 'link'; url: string; skipped?: SkippedOrder[] }
	/** The carrier is still preparing the document; try again after `retryAfter` seconds. */
	| { kind: 'pending'; message: string; retryAfter: number };

/** The `{ code, message }` a REST error carries, whichever way `apiFetch` surfaced it. */
interface DocumentError {
	message?: string;
	code?: string;
}

/** `apiFetch` with `parse: false` rejects with the raw `Response`; turn it into its JSON error body. */
async function documentError( error: unknown ): Promise<DocumentError> {
	if ( error && 'function' === typeof ( error as Response ).json ) {
		try {
			return ( await ( error as Response ).json() ) as DocumentError;
		} catch {
			return {};
		}
	}

	return ( error || {} ) as DocumentError;
}

/**
 * Appends a query string to a REST URL that may already hold one.
 *
 * On plain permalinks `rest_url()` is `…/index.php?rest_route=/woodev/v1/…`; a second `?` would end up INSIDE the
 * `rest_route` value and the route would not match, so the separator is `&` when a query is already there.
 */
function withQuery( url: string, query: string ): string {
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query }`;
}

/** Response header the bulk route names the orders left out in: `id:code,id:code`. */
const SKIPPED_HEADER = 'X-Woodev-Skipped';

function parseSkippedHeader( value: string | null ): SkippedOrder[] {
	if ( ! value ) {
		return [];
	}

	return value
		.split( ',' )
		.map( ( part ) => {
			const [ id, code ] = part.split( ':' );

			return { id: Number( id ), code: ( code || '' ).trim() };
		} )
		.filter( ( entry ) => entry.id > 0 );
}

/**
 * Reads what a documents route answered — one shape for the single and the bulk route: 202 «pending», a PDF, or a
 * carrier link as JSON.
 */
async function readDocumentResponse( response: Response, fallbackFilename: string ): Promise<OrderDocument> {
	if ( 202 === response.status ) {
		const body = ( await response.json() ) as { message?: string; retry_after?: number };

		return {
			kind: 'pending',
			message: body.message || '',
			retryAfter: Number( body.retry_after ) || Number( response.headers.get( 'Retry-After' ) ) || 5,
		};
	}

	if ( ( response.headers.get( 'Content-Type' ) || '' ).includes( 'application/pdf' ) ) {
		const disposition = response.headers.get( 'Content-Disposition' ) || '';
		const match = /filename="?([^";]+)"?/i.exec( disposition );
		const skipped = parseSkippedHeader( response.headers.get( SKIPPED_HEADER ) );

		return {
			kind: 'file',
			blob: await response.blob(),
			filename: match ? match[ 1 ] : fallbackFilename,
			...( skipped.length > 0 ? { skipped } : {} ),
		};
	}

	const body = ( await response.json() ) as { status?: string; url?: string; skipped?: SkippedOrder[] };

	if ( 'url' === body.status && 'string' === typeof body.url && '' !== body.url ) {
		return {
			kind: 'link',
			url: body.url,
			...( Array.isArray( body.skipped ) && body.skipped.length > 0 ? { skipped: body.skipped } : {} ),
		};
	}

	throw {} as DocumentError;
}

/** One GET to a documents route; a rejected request carries the server's own `{ code, message }`. */
async function getDocument( url: string, fallbackFilename: string ): Promise<OrderDocument> {
	const { nonce = '' } = bootstrap();

	let response: Response;

	try {
		response = ( await apiFetch( {
			url: withQuery( url, 'format=json' ),
			method: 'GET',
			headers: { 'X-WP-Nonce': nonce },
			parse: false,
		} ) ) as unknown as Response;
	} catch ( error ) {
		throw await documentError( error );
	}

	return readDocumentResponse( response, fallbackFilename );
}

/**
 * Fetches one carrier document for an order (#1134).
 *
 * Same `bootstrap()`/`apiFetch` wiring as every other call here — the nonce travels in the
 * `X-WP-Nonce` header, never in the URL. `format=json` asks the route to answer a carrier LINK as
 * JSON instead of a cross-origin 302 the browser would not let a script read.
 *
 * Resolves with the three things a merchant can be told: a file to save, a link to open, or
 * «ещё готовится» (ask again — see `pollUntilReady`). A rejection carries the server's own Russian `message`.
 */
export function fetchOrderDocument( orderId: number, type: string ): Promise<OrderDocument> {
	const { restRoot = '' } = bootstrap();

	return getDocument(
		`${ restRoot.replace( /\/+$/, '' ) }/${ orderId }/documents/${ encodeURIComponent( type ) }`,
		`order-${ orderId }-${ type }.pdf`
	);
}

/**
 * Fetches ONE document covering several orders (#1192) — `GET …/shipping/orders/documents/<type>?ids=1,2,3`. Same
 * outcomes as {@link fetchOrderDocument}; a file or link also names the orders it leaves out in `skipped`.
 */
export function fetchBulkDocument( ids: number[], type: string ): Promise<OrderDocument> {
	const { restRoot = '' } = bootstrap();

	return getDocument(
		withQuery( `${ restRoot.replace( /\/+$/, '' ) }/documents/${ encodeURIComponent( type ) }`, `ids=${ ids.join( ',' ) }` ),
		`orders-${ type }.pdf`
	);
}

/**
 * The REST root (`…/woodev/v1`) and nonce the order wizard talks to, plus its reference data.
 *
 * `restRoot` is the ORDERS route (`…/woodev/v1/shipping/orders`), which is what every other
 * call on this page appends its query to; the wizard needs the namespace root above it (the
 * location picker's `/location/suggest`, the editor's `/shipping/orders/{id}/edit`).
 */
export function getWizardContext(): { apiRoot: string; ordersRoot: string; nonce: string; wizard: WizardBootstrap } {
	const { restRoot = '', nonce = '', wizard = {} } = bootstrap();
	const ordersRoot = restRoot.replace( /\/+$/, '' );

	return {
		apiRoot: ordersRoot.replace( /\/shipping\/orders$/, '' ),
		ordersRoot,
		nonce,
		wizard,
	};
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
 * The background-export figure inlined at page load (#1007), or `null` when the bootstrap does
 * not carry a well-formed one (an older server, a cached page) — the notice then never shows.
 */
export function getExportsInProgress(): ExportsInProgress | null {
	const { exportsInProgress } = bootstrap();

	if (
		! exportsInProgress ||
		'number' !== typeof exportsInProgress.count ||
		! Number.isFinite( exportsInProgress.count ) ||
		exportsInProgress.count < 0 ||
		'string' !== typeof exportsInProgress.text ||
		'string' !== typeof exportsInProgress.heartbeatKey ||
		'' === exportsInProgress.heartbeatKey
	) {
		return null;
	}

	return exportsInProgress;
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
	/**
	 * #843: «Все / Любое» — how the advanced filters (delivery status, tracking, pickup
	 * point, order status) combine. Only `'any'` is ever sent; `'all'` and `undefined` send
	 * nothing, which is what the URL does too and what the server reads as `all`.
	 */
	match?: 'all' | 'any';
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
	match,
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

	if ( 'any' === match ) {
		params.set( 'match', 'any' );
	}

	return apiFetch<OrdersResponse>( {
		url: withQuery( restRoot, params.toString() ),
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
 *
 * `payload` (#1180) is the values of the action's declared `fields`; omit it for an action that has none.
 */
export function performOrderAction(
	orderId: number,
	action: string,
	payload?: OrderActionPayload
): Promise<OrderActionResult> {
	const { restRoot = '', nonce = '' } = bootstrap();

	return apiFetch<OrderActionResult>( {
		url: `${ restRoot.replace( /\/+$/, '' ) }/${ orderId }/actions/${ action }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		// #1180: only an action with fields has a body. A rejected payload answers 422 with
		// `data.errors` — {@link OrderActionFieldError}[] — and the action did not run.
		...( payload ? { data: { payload } } : {} ),
	} );
}

/**
 * `POST /shipping/orders/bulk/<action>`'s success envelope (#874, brief §3) — HTTP 200
 * even on a partial failure, since a bulk request routinely mixes orders the server's own
 * eligibility gate accepts and rejects.
 *
 * `skipped` is `requested - eligible` and is explicitly NOT an error — the brief is emphatic
 * about this, and neither this type nor the caller must treat it as one.
 */
export interface BulkActionResult {
	action: string;
	/** How many ids the caller sent. */
	requested: number;
	/** How many of them the server's own gate accepted. */
	eligible: number;
	/** `requested - eligible`. Not an error. */
	skipped: number;
	succeeded: number;
	failed: number;
	/** Freshly rebuilt rows, ONLY for orders that actually changed — swap these in place. */
	rows: OrderRow[];
	/**
	 * Both sentences are built SERVER-side and already pluralised (the framework owns every
	 * user-facing string on this route) — render them verbatim, never compose one from the
	 * numeric fields above. Either may be absent.
	 */
	messages: { success?: string; error?: string; warning?: string };
	/**
	 * #1000 — the orders skipped because another manager is editing them in the wizard, each with
	 * the server's own reason («Этот заказ уже редактируется пользователем …»). Optional: an older
	 * server sends none. Already counted in `skipped`; never in `failures`.
	 */
	locked?: Array<{ id: number; message: string }>;
}

/**
 * Performs one bulk action (#874) over a set of order ids — the framework's own
 * «Экспортировать» / «Обновить» / «Отменить», or a carrier extra the server-side filter
 * declares. Same `bootstrap()`/`apiFetch` wiring as {@link performOrderAction}, with a JSON
 * body rather than a path segment for the ids, matching this repo's own POST convention
 * (`settings-page/rest.js`'s `saveTab()`, `setup-wizard/rest.js`'s `saveStep()`).
 */
export function performBulkOrderAction( action: string, ids: number[] ): Promise<BulkActionResult> {
	const { restRoot = '', nonce = '' } = bootstrap();

	return apiFetch<BulkActionResult>( {
		url: `${ restRoot.replace( /\/+$/, '' ) }/bulk/${ action }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { ids },
	} );
}

/** `preview.billing` — the framework never sends the raw WC billing array, only what a shop owner reads. */
export interface OrderPreviewBilling {
	address: string;
	email: string;
	phone: string;
}

/** `preview.shipping` — same destination fields the row itself carries (`OrderRowShipping`), plus the raw address. */
export interface OrderPreviewShipping {
	address: string;
	method_title: string;
	destination_kind: string;
	destination_text: string;
}

/** One line item. An absent `sku` is `''`, never a dash or the word "null" (#875). */
export interface OrderPreviewItem {
	name: string;
	sku: string;
	quantity: number;
	formatted_total: string;
}

/**
 * `GET /shipping/orders/<id>/preview`'s response (#875, brief §4) — WooCommerce's own order
 * preview (`a.order-preview`) is the reference for the SHAPE (billing/shipping side by side,
 * then items, then actions); the fields themselves are ours, and the operator was explicit
 * that only what a shop owner actually needs goes in. `actions` is the SAME
 * {@link OrderRowAction} shape a row carries, run through the same single-action path.
 */
export interface OrderPreview {
	id: number;
	order_number: string;
	edit_url: string;
	date_created: string | null;
	status: OrderRowStatus;
	carrier: OrderRowCarrier | null;
	customer: OrderRowCustomer;
	billing: OrderPreviewBilling;
	shipping: OrderPreviewShipping;
	payment: OrderRowPayment;
	delivery_status: OrderRowDeliveryStatus;
	tracking: OrderRowTracking;
	items: OrderPreviewItem[];
	/** `''` when the order carries no note — never rendered as a dash or "null". */
	customer_note: string;
	actions: OrderRowAction[];
	/**
	 * #1180: lines a carrier plugin adds through `woodev_shipping_orders_preview_fields`. Optional —
	 * an older server sends none, and «not stated» renders nothing.
	 */
	extra_fields?: OrderPreviewExtraField[];
}

/** One extra line of the preview: a label and a value, optionally a link or a status badge. */
export interface OrderPreviewExtraField {
	label: string;
	value: string;
	url: string | null;
	tone?: string;
}

/**
 * Fetches one order's preview (#875). Fetched on the modal's OPEN, not with the table —
 * the detail is only ever needed for one order at a time, and the row list already carries
 * everything the table itself renders.
 */
export function fetchOrderPreview( orderId: number ): Promise<OrderPreview> {
	const { restRoot = '', nonce = '' } = bootstrap();

	return apiFetch<OrderPreview>( {
		url: `${ restRoot.replace( /\/+$/, '' ) }/${ orderId }/preview`,
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
