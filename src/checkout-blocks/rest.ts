/**
 * The chooser's calls to the framework's existing location REST layer (SP-11 C-1, #1087).
 *
 * `/location/suggest` is a public read; `/select` and `/forget` write the customer's saved chain and
 * are guarded by the `wp_rest` cookie nonce the integration published (`X-WP-Nonce`). That is NOT the
 * Store API's nonce — never substitute one for the other.
 *
 * Plain `fetch`, deliberately: `@wordpress/api-fetch` on the storefront carries WooCommerce's own
 * Store API middleware (its nonce and cart token), which must not ride along to `woodev/v1`.
 *
 * @package woodev-plugin-framework
 */

import type { LocationConfig, LocationRecord, SelectResult, Suggestion } from './types';

/** A `/suggest` call that could not be COMPLETED, as opposed to one that found nothing. */
export class SuggestUnavailableError extends Error {}

function endpointUrl( endpoint: string, params: Record< string, string > = {} ): string {
	const url = new URL( endpoint, window.location.href );

	for ( const [ key, value ] of Object.entries( params ) ) {
		url.searchParams.set( key, value );
	}

	return url.toString();
}

/**
 * Searches settlements in `country`. An empty list means «the search ran and found nothing»; a
 * thrown {@link SuggestUnavailableError} means it could not run (provider outage, rate limit).
 */
export async function suggest(
	config: LocationConfig,
	query: string,
	country: string,
	signal?: AbortSignal
): Promise< Suggestion[] > {
	let response: Response;

	try {
		response = await fetch( endpointUrl( config.endpoints.suggest, { q: query, level: 'settlement', country } ), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			signal,
		} );
	} catch ( error ) {
		if ( ( error as { name?: string } ).name === 'AbortError' ) {
			throw error;
		}

		throw new SuggestUnavailableError( 'network' );
	}

	if ( ! response.ok ) {
		throw new SuggestUnavailableError( String( response.status ) );
	}

	const body = ( await response.json() ) as { suggestions?: Suggestion[] };

	return Array.isArray( body.suggestions ) ? body.suggestions : [];
}

async function post( endpoint: string, nonce: string, body?: unknown ): Promise< Response > {
	return fetch( endpointUrl( endpoint ), {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': nonce },
		body: body === undefined ? undefined : JSON.stringify( body ),
	} );
}

/**
 * Persists `record` as the customer's chosen locality.
 *
 * Succeeds only when the server really kept it: `persisted: false` (a guest whose session has not
 * started) or a `cancelled` reply (a stale popular pick) must NOT be followed by a native address
 * write, or the rates would be calculated against a locality the server never saved.
 */
export async function selectRecord( config: LocationConfig, record: LocationRecord ): Promise< SelectResult > {
	let response: Response;

	try {
		response = await post( config.endpoints.select, config.nonce, { record } );
	} catch {
		return { ok: false, reason: 'failed' };
	}

	if ( ! response.ok ) {
		return { ok: false, reason: 'failed' };
	}

	const body = ( await response.json() ) as { persisted?: boolean; cancelled?: boolean; message?: string };

	if ( body.cancelled ) {
		return { ok: false, reason: 'cancelled', message: body.message };
	}

	return body.persisted === true ? { ok: true, persisted: true } : { ok: false, reason: 'not-persisted' };
}

/** Erases the customer's saved chain. Best effort: a failure leaves the server where it was. */
export async function forgetSelection( config: LocationConfig ): Promise< boolean > {
	try {
		const response = await post( config.endpoints.forget, config.nonce );

		return response.ok;
	} catch {
		return false;
	}
}
