/**
 * What a failed action request means for the dialog.
 *
 * The server's own sentence is always the one shown; this only decides which of four things happened:
 *
 * - `fields`   — 422: the input was refused, with a sentence per field; the form opens again;
 * - `conflict` — 409: current state refuses the action; nothing ran;
 * - `rejected` — any other answer from the server (403, 404, 5xx…);
 * - `unknown`  — no usable answer at all (network, timeout, a non-JSON page): a mutation may have run.
 *
 * @package woodev-plugin-framework
 */

export type FailureKind = 'fields' | 'conflict' | 'rejected' | 'unknown';

export interface Failure {
	kind: FailureKind;
	/** HTTP status; 0 when no answer arrived. */
	status: number;
	/** The server's sentence; empty when it sent none. */
	message: string;
	/** Field id → sentence, for a 422. */
	fieldErrors: Record< string, string >;
}

interface RestError {
	message?: unknown;
	data?: { status?: unknown; errors?: unknown };
}

function fieldErrorsOf( errors: unknown ): Record< string, string > {
	if ( typeof errors !== 'object' || errors === null ) {
		return {};
	}

	return Object.fromEntries(
		Object.entries( errors as Record< string, unknown > ).filter(
			( entry ): entry is [ string, string ] => typeof entry[ 1 ] === 'string'
		)
	);
}

/**
 * @param error What `apiFetch` rejected with.
 */
export function describeFailure( error: unknown ): Failure {
	const rest = ( error ?? {} ) as RestError;
	const status = typeof rest.data?.status === 'number' ? rest.data.status : 0;
	const message = typeof rest.message === 'string' ? rest.message : '';

	if ( status === 0 ) {
		// Not a REST error body: the request or its answer was lost, so what the server did is unknown.
		return { kind: 'unknown', status, message: '', fieldErrors: {} };
	}
	if ( status === 422 ) {
		return { kind: 'fields', status, message, fieldErrors: fieldErrorsOf( rest.data?.errors ) };
	}

	return { kind: status === 409 ? 'conflict' : 'rejected', status, message, fieldErrors: {} };
}

/**
 * Whether the rows may have changed although the request failed: no answer, or the server failed after starting.
 *
 * @param failure A described failure.
 */
export function mayHaveChangedRows( failure: Failure ): boolean {
	return failure.kind === 'unknown' || failure.status >= 500;
}
