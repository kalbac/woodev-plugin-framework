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
 * API's. No request holds the queue for longer than {@link QUEUE_WAIT_CAP_MS}: one that hangs costs
 * the queue that long, then the NEXT one goes — one, not everything that waited, so the rest stay
 * one after another behind it. A request aborted while it waits leaves at once (its fetch then fails
 * as aborted) without holding the queue; one aborted while it holds it lets the next one go.
 *
 * Registered as the OUTERMOST middleware (last `apiFetch.use()`), so the middlewares inside it —
 * core's nonce, the framework's own reply bookkeeping — see a request when it really leaves.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';
import { requestPath } from './customer-data-request';

/** The longest one request holds the queue — it is counted from the moment the request leaves, not from when it was asked for. */
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

/** The mutations waiting for their turn, oldest first: each is let go with the number of its turn. */
const waiting: Array< ( turn: number ) => void > = [];

/** The number of the turn that holds the queue now: `null` while no request does, `0` while it is handed over. */
let holder: number | null = null;

/** The numbers handed out so far. */
let turns = 0;

/** Ends `holder`'s turn after the cap. */
let watchdog: ReturnType< typeof setTimeout > | undefined;

/** Hands the queue to the oldest waiter, or frees it — the only place a request is let go. */
function advance(): void {
	const go = waiting.shift();

	if ( ! go ) {
		holder = null;

		return;
	}

	const turn = ++turns;

	holder = turn;
	watchdog = setTimeout( () => leave( turn ), QUEUE_WAIT_CAP_MS );
	go( turn );
}

/**
 * Ends `turn`: answered, aborted or out of time, whichever comes first — the rest are no-ops. The
 * next one goes a task later, so whoever asked for `turn` has handled its reply (a refreshed nonce,
 * the cart it returned) before the next request leaves.
 */
function leave( turn: number ): void {
	if ( holder === turn ) {
		clearTimeout( watchdog );
		holder = 0;
		setTimeout( advance, 0 );
	}
}

/** The turn `signal`'s request was given, or `null` when it was aborted before it got one. */
function waitForTurn( signal?: AbortSignal | null ): Promise< number | null > {
	return new Promise( ( resolve ) => {
		if ( signal?.aborted ) {
			resolve( null );

			return;
		}

		const quit = (): void => {
			waiting.splice( waiting.indexOf( go ), 1 );
			resolve( null );
		};
		const go = ( turn: number ): void => {
			signal?.removeEventListener( 'abort', quit );
			resolve( turn );
		};

		signal?.addEventListener( 'abort', quit );
		waiting.push( go );

		if ( holder === null ) {
			advance();
		}
	} );
}

/** The middleware: a Store API mutation leaves once the one before it has been answered. */
export const serializeStoreApiMutations: APIFetchMiddleware = ( options, next ) => {
	if ( ! isStoreApiMutation( options ) ) {
		return next( options );
	}

	const signal = options.signal;

	return waitForTurn( signal ).then( ( turn ) => {
		if ( turn === null ) {
			return next( options );
		}

		const done = (): void => {
			signal?.removeEventListener( 'abort', done );
			leave( turn );
		};

		signal?.addEventListener( 'abort', done );

		return Promise.resolve()
			.then( () => next( options ) )
			.then(
				( response ) => {
					done();

					return response;
				},
				( error: unknown ) => {
					done();

					throw error;
				}
			);
	} );
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
