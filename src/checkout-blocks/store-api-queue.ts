/**
 * One Store API cart mutation at a time (#1357).
 *
 * WooCommerce keeps the cart's server state — the chosen rate, the customer's address, and the
 * pickup point the framework remembers — in ONE session row, read when a request starts and written
 * back WHOLE when it ends (`WC_Session_Handler::save_data()`). Two cart requests of the same page that
 * overlap on the server therefore lose an update: the one that ends last writes back the state it
 * read before the other one's change. Core sends such pairs routinely — its `focusout` address push
 * (1–2 s on a carrier that rates live) is still on the server when the shopper picks a rate or a
 * pickup point. Measured on the rig (WooCommerce 11.2.1) with no browser at all:
 *
 * - a rate chosen while an address push is in flight is gone once the push ends: the server, and
 *   then the store, are back on the rate the push started with (3/3; 2/2 kept when sent one after
 *   the other) — the pickup dialog the shopper had just opened is torn down with it;
 * - a pickup confirmation overlapped by a later cart request is gone from the session, the point it
 *   remembered included, while the form already shows the point's street (2/2).
 *
 * So every cart MUTATION this page sends to the Store API waits until the previous one has been
 * answered: the server sees them one after another, and their replies reach the store in the order
 * they were asked. Reads pass straight through, and so does every request that is not the Store
 * API's. A wait never blocks for longer than {@link QUEUE_WAIT_CAP_MS} — a request that hangs costs
 * the next one that long, then the queue moves on — and a request aborted while it waits leaves at
 * once (its fetch then fails as aborted).
 *
 * Registered as the OUTERMOST middleware (last `apiFetch.use()`), so the middlewares inside it —
 * core's nonce, the framework's own reply bookkeeping — see a request when it really leaves.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';
import { requestPath } from './customer-data-request';

/** The longest a mutation waits for the one before it. */
export const QUEUE_WAIT_CAP_MS = 15000;

/** The page-wide mark that the queue is installed — a second copy of the bundle must not add another. */
export const QUEUE_INSTALLED_FLAG = '__woodevStoreApiMutationQueue';

/** `batch`, `cart` and everything under it, `checkout` and everything under it — with or without a version. */
const MUTATED_ROUTE = /(?:^|\/)wc\/store\/(?:v\d+\/)?(?:batch|cart|checkout)(?:\/[^?#]*)?$/;

const READ_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

/** Whether `options` is a Store API request that changes the cart's server state. */
export function isStoreApiMutation( options: APIFetchOptions ): boolean {
	const method = String( options.method ?? 'GET' ).toUpperCase();

	return ! READ_METHODS.includes( method ) && MUTATED_ROUTE.test( requestPath( options.path ?? options.url ) );
}

/** Settles when the last mutation asked for has been answered. */
let last: Promise< void > = Promise.resolve();

/** Resolves once `previous` settles or the cap runs out, whichever is first. */
function turnAfter( previous: Promise< void > ): Promise< void > {
	return new Promise( ( resolve ) => {
		const timer = setTimeout( resolve, QUEUE_WAIT_CAP_MS );

		previous.then( () => {
			clearTimeout( timer );
			resolve();
		} );
	} );
}

/** `turn`, or earlier when `signal` aborts. */
function orAbort( turn: Promise< void >, signal?: AbortSignal | null ): Promise< void > {
	if ( ! signal ) {
		return turn;
	}

	return new Promise( ( resolve ) => {
		const go = (): void => {
			signal.removeEventListener( 'abort', go );
			resolve();
		};

		turn.then( go );

		if ( signal.aborted ) {
			go();
		} else {
			signal.addEventListener( 'abort', go );
		}
	} );
}

/** The middleware: a Store API mutation leaves once the one before it has been answered. */
export const serializeStoreApiMutations: APIFetchMiddleware = ( options, next ) => {
	if ( ! isStoreApiMutation( options ) ) {
		return next( options );
	}

	const turn = turnAfter( last );
	let release: () => void = () => undefined;

	// The next one goes when this one is answered AND its own turn came: one aborted while it waited
	// must not let the rest overtake the request it was waiting for.
	last = new Promise< void >( ( resolve ) => {
		release = resolve;
	} ).then( () => turn );

	return orAbort( turn, options.signal )
		.then( () => next( options ) )
		.then(
			( response ) => {
				release();

				return response;
			},
			( error: unknown ) => {
				release();

				throw error;
			}
		);
};

/**
 * Installs {@link serializeStoreApiMutations} once per page; `false` when a copy of it already is.
 * Call it AFTER every other framework middleware, so it is the outermost one.
 */
export function serializeStoreApiMutationsOnce(): boolean {
	const page = window as unknown as Record< string, unknown >;

	if ( page[ QUEUE_INSTALLED_FLAG ] === true ) {
		return false;
	}

	page[ QUEUE_INSTALLED_FLAG ] = true;
	apiFetch.use( serializeStoreApiMutations );

	return true;
}
