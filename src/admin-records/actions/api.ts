/**
 * REST client of a table's action routes. Preview and the options read may be aborted; apply never is — a
 * mutation the browser gave up on may still have run, and the flow treats it that way (see `failure.ts`).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { RecordsApi } from '../api';
import type { RecordsContext } from '../types';
import type { ApplyResponse, FieldOptionsPage, Payload, PreviewResponse } from './types';

export interface OptionsRequest {
	/** The row the options are read for; empty for a bulk action. */
	rowId: string;
	search: string;
	cursor: string;
}

export type ActionsApi = ReturnType< typeof createActionsApi >;

/**
 * @param api The table's records client; its base path is the actions' too.
 */
export function createActionsApi( api: RecordsApi ) {
	const route = ( action: string ) => `${ api.path }/actions/${ encodeURIComponent( action ) }`;
	const body = ( context: RecordsContext, ids: string[], payload: Payload ) => ( {
		// The account the server resolved for this table, always named: a write has no default account.
		context: { account_id: context.account_id },
		ids,
		payload,
	} );

	return {
		/** Read-only plan. Changes nothing on the server. */
		preview: ( context: RecordsContext, action: string, ids: string[], payload: Payload, signal?: AbortSignal ) =>
			apiFetch< PreviewResponse >( {
				path: `${ route( action ) }/preview`,
				method: 'POST',
				data: body( context, ids, payload ),
				signal,
			} ),
		/** Replans from current state and applies. `confirmed` is the merchant's explicit yes. */
		apply: ( context: RecordsContext, action: string, ids: string[], payload: Payload ) =>
			apiFetch< ApplyResponse >( {
				path: route( action ),
				method: 'POST',
				data: { ...body( context, ids, payload ), confirmed: true },
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
