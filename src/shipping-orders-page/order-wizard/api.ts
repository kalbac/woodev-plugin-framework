/**
 * The order wizard's network layer (#969) — the I3 transport contract
 * (`Order_Editor_Controller`) plus the two WooCommerce searches steps ① and ③ use.
 *
 * Auth is the cookie session and the `wp_rest` nonce the page bootstrap injects, sent as
 * `X-WP-Nonce` like every other call on the page. The editor's answers are declared:
 * 401 not logged in, 403 no `edit_shop_orders`, 404 unknown / foreign order, 409 no longer
 * editable, 422 `data.errors` = `[ { field, code, message } ]`.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { getWizardContext } from '../rest';
import type { OrderPrefill, RatesResponse, SaveResult, ServerError } from './types';
import type { WcCustomerRecord } from './wizard-data';

/** A save / load that failed, reduced to what the wizard shows. */
export interface WizardRequestError {
	message: string;
	/** HTTP status when the server sent one (`data.status`), 0 for a transport failure. */
	status: number;
	/** The 422's per-field problems; empty for every other failure. */
	errors: ServerError[];
}

const isRequestError = ( value: unknown ): value is WizardRequestError =>
	!! value &&
	'object' === typeof value &&
	'string' === typeof ( value as WizardRequestError ).message &&
	'number' === typeof ( value as WizardRequestError ).status &&
	Array.isArray( ( value as WizardRequestError ).errors );

/**
 * Normalises what `apiFetch` rejects with (idempotent: an already normalised error is returned as it is). A REST `WP_Error` arrives as its JSON body
 * (`{ code, message, data: { status, errors? } }`); a network failure as a bare `Error`.
 *
 * @param {unknown} error whatever the promise rejected with.
 * @return {WizardRequestError} the parts the UI needs.
 */
export function toRequestError( error: unknown ): WizardRequestError {
	if ( isRequestError( error ) ) {
		return error;
	}

	// A transport failure (`TypeError: Failed to fetch`) has an English browser message the
	// manager cannot act on — a REST `WP_Error` body is a plain object, never an `Error`.
	if ( error instanceof Error ) {
		return { message: __( 'Не удалось выполнить запрос. Попробуйте ещё раз.', 'woodev-plugin-framework' ), status: 0, errors: [] };
	}

	const body = ( error && 'object' === typeof error ? error : {} ) as {
		message?: unknown;
		data?: { status?: unknown; errors?: unknown };
	};
	const status = Number( body.data?.status ) || 0;
	const errors = Array.isArray( body.data?.errors ) ? ( body.data?.errors as ServerError[] ) : [];

	return {
		message: 'string' === typeof body.message && body.message
			? body.message
			: __( 'Не удалось выполнить запрос. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
		status,
		errors,
	};
}

function headers(): Record<string, string> {
	const { nonce } = getWizardContext();

	return nonce ? { 'X-WP-Nonce': nonce } : {};
}

/**
 * `GET /shipping/orders/{id}/edit` — the prefill. Rejects with a {@link WizardRequestError}.
 *
 * @param {number} orderId the order.
 * @return {Promise<OrderPrefill>} the wizard's prefill.
 */
export function loadOrderPrefill( orderId: number ): Promise<OrderPrefill> {
	const { ordersRoot } = getWizardContext();

	return apiFetch<OrderPrefill>( {
		url: `${ ordersRoot }/${ orderId }/edit`,
		method: 'GET',
		headers: headers(),
	} ).catch( ( error: unknown ) => {
		throw toRequestError( error );
	} );
}

/**
 * `POST /shipping/orders` (create) or `PUT /shipping/orders/{id}` (update). Rejects with a
 * {@link WizardRequestError}; a 422 carries the per-field list in `errors`.
 *
 * @param {number|null}             orderId the order to update, `null` to create.
 * @param {Record<string, unknown>} payload the request body (`buildPayload()`).
 * @return {Promise<SaveResult>} the new / saved order.
 */
export function saveOrder( orderId: number | null, payload: Record<string, unknown> ): Promise<SaveResult> {
	const { ordersRoot } = getWizardContext();

	return apiFetch<SaveResult>( {
		url: null === orderId ? ordersRoot : `${ ordersRoot }/${ orderId }`,
		method: null === orderId ? 'POST' : 'PUT',
		headers: headers(),
		data: payload,
	} ).catch( ( error: unknown ) => {
		throw toRequestError( error );
	} );
}

/**
 * `POST /shipping/orders/rates` — the carriers' tariffs for the package steps ①–③ built
 * (`Rates_Controller`, I2a). Nothing is read from a cart or a session; the body carries the
 * lines at their edited prices, the destination and the chosen customer. Rejects with a
 * {@link WizardRequestError}: 422 for an unknown product / customer or a missing country.
 *
 * @param {Record<string, unknown>} payload the request body (`buildRatesRequest()`).
 * @return {Promise<RatesResponse>} the tariffs, grouped by carrier.
 */
export function fetchRates( payload: Record<string, unknown> ): Promise<RatesResponse> {
	const { ordersRoot } = getWizardContext();

	return apiFetch<RatesResponse>( {
		url: `${ ordersRoot }/rates`,
		method: 'POST',
		headers: headers(),
		data: payload,
	} ).catch( ( error: unknown ) => {
		throw toRequestError( error );
	} );
}

/** One row of a search dropdown. */
export interface SearchOption {
	id: string;
	label: string;
	/** A second line — price, SKU, a variation's attributes. */
	hint?: string;
	/** Present on a variable product: picking it lists these instead of choosing it. */
	loadChildren?: () => Promise<SearchOption[]>;
	/** What the caller gets back on a pick. */
	value: unknown;
}

const wcUrl = ( path: string, params: Record<string, string> ): string =>
	`${ getWizardContext().apiRoot.replace( /\/woodev\/v1$/, '' ) }${ path }?${ new URLSearchParams( params ).toString() }`;

/** The chosen product / variation, as the items step turns it into a line. */
export interface PickedProduct {
	product_id: number;
	variation_id: number;
	name: string;
	price: string;
}

interface WcProduct {
	id: number;
	name?: string;
	type?: string;
	sku?: string;
	price?: string;
	attributes?: Array<{ name?: string; option?: string }>;
	parent_id?: number;
}

const priceHint = ( product: WcProduct ): string =>
	[ product.sku ? `${ __( 'Артикул', 'woodev-plugin-framework' ) } ${ product.sku }` : '', product.price ? String( product.price ) : '' ]
		.filter( Boolean )
		.join( ' · ' );

/**
 * Product search through WooCommerce's own REST (`wc/v3/products`). A variable product is not
 * orderable itself — its option lists the variations, fetched only when it is picked.
 *
 * @param {string} query what the manager typed.
 * @return {Promise<SearchOption[]>} matches.
 */
export async function searchProducts( query: string ): Promise<SearchOption[]> {
	const products = await apiFetch<WcProduct[]>( {
		url: wcUrl( '/wc/v3/products', { search: query, per_page: '15', status: 'publish' } ),
		method: 'GET',
		headers: headers(),
	} );

	return ( Array.isArray( products ) ? products : [] ).map( ( product ) => {
		const name = product.name || `#${ product.id }`;

		if ( 'variable' === product.type ) {
			return {
				id: `p${ product.id }`,
				label: name,
				hint: __( 'Вариативный товар — выберите вариант', 'woodev-plugin-framework' ),
				value: null,
				loadChildren: async () => {
					const variations = await apiFetch<WcProduct[]>( {
						url: wcUrl( `/wc/v3/products/${ product.id }/variations`, { per_page: '50' } ),
						method: 'GET',
						headers: headers(),
					} );

					return ( Array.isArray( variations ) ? variations : [] ).map( ( variation ) => {
						const attributes = ( variation.attributes || [] ).map( ( a ) => a.option ).filter( Boolean ).join( ', ' );
						const picked: PickedProduct = {
							product_id: product.id,
							variation_id: variation.id,
							name: attributes ? `${ name } — ${ attributes }` : name,
							price: variation.price ? String( variation.price ) : '',
						};

						return {
							id: `v${ variation.id }`,
							label: picked.name,
							hint: priceHint( variation ),
							value: picked,
						};
					} );
				},
			};
		}

		const picked: PickedProduct = {
			product_id: product.id,
			variation_id: 0,
			name,
			price: product.price ? String( product.price ) : '',
		};

		return { id: `p${ product.id }`, label: name, hint: priceHint( product ), value: picked };
	} );
}

/**
 * Registered-customer search through WooCommerce's own REST (`wc/v3/customers`). Guests are
 * not customers and are not searched — a new person is typed into the form (O11).
 *
 * @param {string} query name / email / phone fragment.
 * @return {Promise<SearchOption[]>} matches.
 */
export async function searchCustomers( query: string ): Promise<SearchOption[]> {
	const customers = await apiFetch<WcCustomerRecord[]>( {
		url: wcUrl( '/wc/v3/customers', { search: query, per_page: '10', role: 'all' } ),
		method: 'GET',
		headers: headers(),
	} );

	return ( Array.isArray( customers ) ? customers : [] ).map( ( record ) => {
		const name = `${ record.first_name || '' } ${ record.last_name || '' }`.trim() || record.username || `#${ record.id }`;

		return { id: `u${ record.id }`, label: name, hint: record.email || '', value: record };
	} );
}
