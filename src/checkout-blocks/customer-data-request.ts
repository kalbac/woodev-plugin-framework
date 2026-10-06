/**
 * Tracks successful WooCommerce address updates, so an unrelated cart response cannot be credited
 * as the answer to a customer-address push (#1118).
 *
 * WooCommerce 9.9 and 11.1 both send `updateCustomerData` through `apiFetchWithHeaders` to
 * `/wc/store/v1/cart/update-customer`; its response is taken into `wc/store/cart` immediately after
 * the apiFetch promise resolves. Matching that stable request path gives the lifecycle the reply
 * signal that the public cart selectors do not expose.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware } from '@wordpress/api-fetch';

const UPDATE_CUSTOMER_PATH = '/wc/store/v1/cart/update-customer';

let replyVersion = 0;

const addressUpdateMiddleware: APIFetchMiddleware = ( options, next ) => {
	const path = options.path ?? options.url;
	const requestPath = typeof path === 'string' ? path.split( '?' )[ 0 ] : '';

	if ( requestPath !== UPDATE_CUSTOMER_PATH ) {
		return next( options );
	}

	return next( options ).then( ( response ) => {
		replyVersion++;

		return response;
	} );
};

apiFetch.use( addressUpdateMiddleware );

/** The number of successful `/cart/update-customer` requests seen by apiFetch. */
export function customerDataReplyVersion(): number {
	return replyVersion;
}
