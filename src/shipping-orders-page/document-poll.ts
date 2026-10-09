/**
 * Client-side wait for a carrier document (#1191).
 *
 * The documents routes never wait on the server (`Document_Source` forbids it): while the carrier is still
 * preparing the file they answer 202 «pending» with a `retry_after`. This asks again by itself, so the merchant
 * clicks once — the download starts as soon as the document is ready.
 *
 * The metabox (`order-metabox-actions.js`) is not bundled and carries a small ES5 copy of this loop with the same
 * numbers; change both together.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';

/** What a fetch of one document may answer while it is not ready. */
export interface PendingDocument {
	kind: 'pending';
	message: string;
	/** Seconds the server asks us to wait before asking again. */
	retryAfter: number;
}

/** Total time we keep asking before telling the merchant to come back later. */
export const POLL_MAX_WAIT_MS = 30000;

/** A server `retry_after` is respected but kept inside this window, so a bad value cannot stall or hammer. */
export const POLL_MIN_DELAY_MS = 1000;
export const POLL_MAX_DELAY_MS = 10000;

/** The `code` of the rejection when the cap ran out. */
export const POLL_TIMEOUT_CODE = 'woodev_document_timeout';

/** The `code` of the rejection when the wait was cancelled (page left, component unmounted). */
export const POLL_CANCELLED_CODE = 'woodev_document_cancelled';

export interface PollError {
	code: string;
	message: string;
}

export interface PollOptions {
	/** Aborting stops the wait at once and rejects with {@link POLL_CANCELLED_CODE}. */
	signal?: AbortSignal;
	/** Called for EVERY pending answer, with the 1-based number of the attempt that got it. */
	onPending?: ( attempt: number ) => void;
	/** Overrides {@link POLL_MAX_WAIT_MS}. */
	maxWaitMs?: number;
}

/** Whether a rejection is the quiet cancel — callers say nothing for it. */
export function isPollCancelled( error: unknown ): boolean {
	return Boolean( error ) && POLL_CANCELLED_CODE === ( error as PollError ).code;
}

function cancelled(): PollError {
	return { code: POLL_CANCELLED_CODE, message: '' };
}

function delayFor( retryAfter: number ): number {
	const asked = Number( retryAfter ) > 0 ? Number( retryAfter ) * 1000 : POLL_MIN_DELAY_MS;

	return Math.min( POLL_MAX_DELAY_MS, Math.max( POLL_MIN_DELAY_MS, asked ) );
}

/** Resolves after `ms`, or rejects at once when `signal` aborts (the timer is cleared either way). */
function wait( ms: number, signal?: AbortSignal ): Promise<void> {
	return new Promise( ( resolve, reject ) => {
		if ( signal?.aborted ) {
			reject( cancelled() );
			return;
		}

		const onAbort = () => {
			clearTimeout( timer );
			reject( cancelled() );
		};
		const timer = setTimeout( () => {
			signal?.removeEventListener( 'abort', onAbort );
			resolve();
		}, ms );

		signal?.addEventListener( 'abort', onAbort, { once: true } );
	} );
}

/**
 * Calls `fetchOnce` until it answers something other than «pending», honouring each `retryAfter`.
 *
 * Resolves with the first non-pending answer (a file, a link). Rejects with whatever `fetchOnce` rejects with
 * (the server's own `{ code, message }`), with {@link POLL_TIMEOUT_CODE} once the next wait would pass the cap, or
 * quietly with {@link POLL_CANCELLED_CODE} when `signal` aborts — also when it aborts while a request is in flight,
 * so a document that arrives after the merchant left is never saved.
 */
export async function pollUntilReady<T extends { kind: string }>(
	fetchOnce: () => Promise<T | PendingDocument>,
	options: PollOptions = {}
): Promise<Exclude<T, PendingDocument>> {
	const { signal, onPending, maxWaitMs = POLL_MAX_WAIT_MS } = options;
	const started = Date.now();

	for ( let attempt = 1; ; attempt++ ) {
		if ( signal?.aborted ) {
			throw cancelled();
		}

		const answer = await fetchOnce();

		if ( signal?.aborted ) {
			throw cancelled();
		}

		if ( 'pending' !== answer.kind ) {
			return answer as Exclude<T, PendingDocument>;
		}

		onPending?.( attempt );

		const delay = delayFor( ( answer as PendingDocument ).retryAfter );

		if ( Date.now() - started + delay > maxWaitMs ) {
			throw {
				code: POLL_TIMEOUT_CODE,
				message: __(
					'Документ всё ещё формируется. Попробуйте ещё раз через минуту.',
					'woodev-plugin-framework'
				),
			} as PollError;
		}

		await wait( delay, signal );
	}
}
