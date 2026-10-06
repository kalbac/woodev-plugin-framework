/**
 * Tracks WooCommerce address updates so a foreign cart response cannot be credited as their answer
 * (#1118).
 *
 * WooCommerce 9.9.0 (`assets/js/data/shared-controls.ts:129-133,70-74`) and 11.1.x
 * (`packages/public-api/block-data/shared-controls.ts:129-136`) batch `update-customer` in a POST
 * to `/wc/store/v1/batch`; each request's outcome is in the same-index `responses` entry. If a
 * batch contains several updates, any successful `update-customer` item is the address reply; sibling
 * cart requests only count when the request shape is too unknown to classify safely.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware } from '@wordpress/api-fetch';

const BATCH_PATH = '/wc/store/v1/batch';
const UPDATE_CUSTOMER_PATH = '/wc/store/v1/cart/update-customer';

let replyVersion = 0;
let startedRequestCount = 0;
let lastCompletedRequestId = 0;

export interface CustomerDataRequestSnapshot {
	replyVersion: number;
	startedRequestCount: number;
	lastCompletedRequestId: number;
}

type BatchItem = { path?: unknown; url?: unknown };
type BatchRequestOptions = { requests?: unknown };
type BatchResponse = { responses?: unknown };

function requestPath( value: unknown ): string {
	if ( typeof value !== 'string' ) {
		return '';
	}

	const [ pathname, query = '' ] = value.split( '?' );
	const route = new URLSearchParams( query ).get( 'rest_route' );

	return route ?? pathname;
}

function batchIndexes( data: unknown ): number[] | null {
	if ( ! data || typeof data !== 'object' || ! Array.isArray( ( data as BatchRequestOptions ).requests ) ) {
		return null;
	}

	const requests = ( data as BatchRequestOptions ).requests as BatchItem[];
	const indexes = requests.reduce< number[] >( ( found, item, index ) => {
		if ( item && requestPath( item.path ?? item.url ) === UPDATE_CUSTOMER_PATH ) {
			found.push( index );
		}

		return found;
	}, [] );

	return indexes.length > 0 ? indexes : null;
}

function batchSucceeded( response: unknown, indexes: number[] ): boolean | null {
	if ( ! response || typeof response !== 'object' || ! Array.isArray( ( response as BatchResponse ).responses ) ) {
		return null;
	}

	const responses = ( response as BatchResponse ).responses as Array< { status?: unknown } | null >;
	const matching = indexes.map( ( index ) => responses[ index ] );

	if ( matching.some( ( item ) => ! item || typeof item.status !== 'number' ) ) {
		return null;
	}

	return matching.some( ( item ) => ( item?.status as number ) >= 200 && ( item?.status as number ) < 300 );
}

const addressUpdateMiddleware: APIFetchMiddleware = ( options, next ) => {
	const path = requestPath( options.path ?? options.url );
	const direct = path === UPDATE_CUSTOMER_PATH;
	const batched = path === BATCH_PATH ? batchIndexes( options.data ) : null;

	if ( ! direct && ! batched ) {
		return next( options );
	}

	const requestId = ++startedRequestCount;

	return next( options ).then( ( response ) => {
		// An unreadable response fails open so the hook retains legacy reply behavior.
		// Direct apiFetch resolves only for a successful HTTP response.
		if ( direct || batchSucceeded( response, batched as number[] ) !== false ) {
			replyVersion++;
			lastCompletedRequestId = Math.max( lastCompletedRequestId, requestId );
		}

		return response;
	} );
};

apiFetch.use( addressUpdateMiddleware );

/** Return the observed customer-data request state for one address push. */
export function customerDataRequestSnapshot(): CustomerDataRequestSnapshot {
	return {
		replyVersion,
		startedRequestCount,
		lastCompletedRequestId,
	};
}
