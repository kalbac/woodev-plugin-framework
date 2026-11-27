/**
 * An answer that is not the plugin's: a proxy or a bot filter in front of the shop may replace an answer with a body of
 * its own. Every refusal of the plugin's routes carries a message, so an answer without one was replaced, and its HTTP
 * status is all there is to show. `apiFetch` drops that status when it parses an answer itself, hence the middleware.
 *
 * The admin scripts write the same sentence, from `Foreign_Answer_Text` in PHP.
 *
 * @package woodev-plugin-framework
 */

import { __, sprintf } from '@wordpress/i18n';
import type { APIFetchMiddleware } from '@wordpress/api-fetch';

/**
 * @param status The HTTP status of the answer.
 */
export function foreignAnswerMessage( status: number ): string {
	return sprintf(
		/* translators: %d: the HTTP status code of the answer. */
		__(
			'Ответ пришёл не от плагина (HTTP %d) — возможно, его подменила защита или другой сервис перед сайтом.',
			'woodev-plugin-framework'
		),
		status
	);
}

/** What a replaced answer is rejected with: whatever its body held, plus the sentence and the status. */
function foreignAnswer( status: number, body: object | null = null ) {
	return { code: 'invalid_json', ...body, message: foreignAnswerMessage( status ), httpStatus: status };
}

function isResponse( value: unknown ): value is Response {
	return typeof ( value as Partial< Response > | null )?.json === 'function';
}

/** A successful answer: its JSON, or the rejection of a page served in its place (a bot check). */
async function readAnswer( answer: unknown ): Promise< unknown > {
	if ( ! isResponse( answer ) ) {
		return answer;
	}
	if ( answer.status === 204 ) {
		return null;
	}

	try {
		return await answer.json();
	} catch {
		throw foreignAnswer( answer.status );
	}
}

/** A failed request: the server's own body when it explains itself. No answer at all (network, abort) passes through. */
async function readFailure( error: unknown ): Promise< never > {
	if ( ! isResponse( error ) ) {
		throw error;
	}

	const body = ( await error.json().catch( () => null ) ) as { message?: unknown } | null;

	throw body?.message ? body : foreignAnswer( error.status, body );
}

/**
 * An `apiFetch` middleware under which a failed request of the matching routes always carries a message. It parses the
 * answer itself, so `apiFetch` still renews an expired nonce: it reads `code` from what this rejects with.
 *
 * @param routes Matches the `path` or `url` of the requests to look after; every other request is left alone.
 */
export function nameForeignAnswers( routes: RegExp ): APIFetchMiddleware {
	return ( options, next ) => {
		if ( options.parse === false || ! routes.test( options.path ?? options.url ?? '' ) ) {
			return next( options );
		}

		return next( { ...options, parse: false } ).then( readAnswer, readFailure );
	};
}
