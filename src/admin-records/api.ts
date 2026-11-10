/**
 * REST client of one records table: `woodev/v1/admin/records/{owner}/{table}`.
 *
 * Cookie auth and the `wp_rest` nonce are added by `@wordpress/api-fetch`; the server checks capability, account and
 * object access. Only the read routes are here — preview/apply and activity belong to `actions/` and `activity/`,
 * which build on {@link RecordsApi.path}.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { contextParams, listParams, type RestParam } from './query';
import type { DefinitionEnvelope, ListEnvelope, RecordsContext, RecordsQuery } from './types';

function withParams( path: string, params: RestParam[] ): string {
	const search = new URLSearchParams( params ).toString();

	return search === '' ? path : `${ path }?${ search }`;
}

/**
 * The sentence of a failed request: the server's own message, or nothing when it sent none. Never built from the
 * error code.
 *
 * @param error What `apiFetch` rejected with.
 */
export function errorMessage( error: unknown ): string {
	const message = ( error as { message?: unknown } | null )?.message;

	return typeof message === 'string' ? message : '';
}

/**
 * Whether a rejection is the abort of a read the table itself gave up on.
 *
 * @param error What `apiFetch` rejected with.
 */
export function isAbort( error: unknown ): boolean {
	return ( error as { name?: unknown } | null )?.name === 'AbortError';
}

export interface ChildrenPage {
	page: number;
	perPage: number;
}

export type RecordsApi = ReturnType< typeof createRecordsApi >;

/**
 * @param owner The plugin that registered the table.
 * @param table The table id.
 */
export function createRecordsApi( owner: string, table: string ) {
	const base = `/woodev/v1/admin/records/${ encodeURIComponent( owner ) }/${ encodeURIComponent( table ) }`;
	const get = < T >( path: string, params: RestParam[], signal?: AbortSignal ) =>
		apiFetch< T >( { path: withParams( path, params ), signal } );

	return {
		/** Base path of the table's routes, for the action and activity clients. */
		path: base,
		definition: ( account: string, signal?: AbortSignal ) =>
			get< DefinitionEnvelope >( `${ base }/definition`, contextParams( account ), signal ),
		rows: ( context: RecordsContext, query: RecordsQuery, signal?: AbortSignal ) =>
			get< ListEnvelope >( base, listParams( context, query ), signal ),
		children: ( context: RecordsContext, parentId: string, page: ChildrenPage, signal?: AbortSignal ) =>
			get< ListEnvelope >(
				// A row id is path-safe as PHP sends it (`[A-Za-z0-9_.:-]`); encoding its `:` would miss the route.
				`${ base }/${ parentId }/children`,
				[
					...contextParams( context.account_id ),
					[ 'page', String( page.page ) ],
					[ 'per_page', String( page.perPage ) ],
				],
				signal
			),
	};
}
