/**
 * REST client for the shipping orders page (woodev/v1/shipping/orders).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';

function bootstrap() {
	return window.woodevShippingOrders || {};
}

/**
 * Returns the inlined provider list — the aggregate entry first, then one per
 * registered carrier, each already carrying a count (see
 * `Orders_Registry::build_bootstrap_providers()`).
 *
 * @return {Array<{id: string, label: string, count: number}>} provider list.
 */
export function getProviders() {
	return bootstrap().providers || [];
}

/**
 * Fetches one page of rows for a carrier (or the aggregate).
 *
 * Only the params increment 1's REST route already accepts are sent — no new
 * REST param is invented here.
 *
 * @param {Object} args           request params.
 * @param {string} [args.carrier] provider id, or 'all' for the aggregate.
 * @param {number} [args.page]    1-based page number.
 * @param {number} [args.perPage] page size.
 * @param {string} [args.orderby] 'date' or 'ID'.
 * @param {string} [args.order]   'ASC' or 'DESC'.
 * @param {string} [args.search]  free-text search term.
 * @return {Promise<Object>} { rows, total, total_pages }.
 */
export function fetchOrders( {
	carrier = 'all',
	page = 1,
	perPage = 20,
	orderby = 'date',
	order = 'DESC',
	search = '',
} = {} ) {
	const { restRoot, nonce } = bootstrap();

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

	return apiFetch( {
		url: `${ restRoot }?${ params.toString() }`,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}
