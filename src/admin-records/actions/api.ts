/**
 * REST client of a table's action routes. Preview and the options read may be aborted; apply never is — a
 * mutation the browser gave up on may still have run, and the flow treats it that way (see `failure.ts`).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { RecordsApi } from '../api';
import type { MatchingQuery, RecordsContext } from '../types';
import type { ActionTarget, ApplyResponse, FieldOptionsPage, Payload, PreviewResponse } from './types';

export interface OptionsRequest {
	/** The row the options are read for; empty for a bulk action. */
	rowId: string;
	search: string;
	cursor: string;
}

export type ActionsApi = ReturnType< typeof createActionsApi >;

/**
 * The `matching` of a request: the filters and the search, each only when the merchant set one, so a table without a
 * search box is never sent a search and an empty object means «the whole table».
 *
 * @param query The query frozen at the click.
 */
function matchingBody( query: MatchingQuery ) {
	return {
		...( Object.keys( query.filters ).length > 0 ? { filters: query.filters } : {} ),
		...( query.search === '' ? {} : { search: query.search } ),
	};
}

/**
 * @param api The table's records client; its base path is the actions' too.
 */
export function createActionsApi( api: RecordsApi ) {
	const route = ( action: string ) => `${ api.path }/actions/${ encodeURIComponent( action ) }`;
	// Exactly one target: ticked ids, or `matching` and no `ids` at all (the server refuses both together).
	const body = ( context: RecordsContext, target: ActionTarget, payload: Payload ) => ( {
		// The account the server resolved for this table, always named: a write has no default account.
		context: { account_id: context.account_id },
		...( Array.isArray( target ) ? { ids: target } : { matching: matchingBody( target.matching ) } ),
		payload,
	} );

	return {
		/** Read-only plan. Changes nothing on the server. */
		preview: ( context: RecordsContext, action: string, target: ActionTarget, payload: Payload, signal?: AbortSignal ) =>
			apiFetch< PreviewResponse >( {
				path: `${ route( action ) }/preview`,
				method: 'POST',
				data: body( context, target, payload ),
				signal,
			} ),
		/** Replans from current state and applies. `confirmed` is the merchant's explicit yes. */
		apply: ( context: RecordsContext, action: string, target: ActionTarget, payload: Payload ) =>
			apiFetch< ApplyResponse >( {
				path: route( action ),
				method: 'POST',
				data: { ...body( context, target, payload ), confirmed: true },
			} ),
		fieldOptions: (
			context: RecordsContext,
			action: string,
			field: string,
			request: OptionsRequest,
			signal?: AbortSignal
		) => {
			const params = new URLSearchParams();
			if ( context.account_id !== '' ) {
				params.set( 'context[account_id]', context.account_id );
			}
			( [ 'row_id', 'search', 'cursor' ] as const ).forEach( ( name ) => {
				const value = request[ name === 'row_id' ? 'rowId' : name ];
				if ( value !== '' ) {
					params.set( name, value );
				}
			} );
			const query = params.toString();

			return apiFetch< FieldOptionsPage >( {
				path: `${ route( action ) }/fields/${ encodeURIComponent( field ) }/options${ query === '' ? '' : `?${ query }` }`,
				signal,
			} );
		},
	};
}
