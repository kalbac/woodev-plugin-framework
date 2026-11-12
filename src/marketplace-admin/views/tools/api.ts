/**
 * REST client of the tools view: `woodev/v1/admin/marketplace/{plugin id}/tools`.
 *
 * Cookie auth and the `wp_rest` nonce are added by `@wordpress/api-fetch`; the server checks the capability and refuses a
 * disabled tool with 409 and its sentence.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { ToolPreview, ToolRunResult, ToolsState } from './types';

export type ToolsApi = ReturnType< typeof createToolsApi >;

/**
 * @param pluginId The marketplace plugin's id (the manifest's `pluginId`).
 */
export function createToolsApi( pluginId: string ) {
	const base = `/woodev/v1/admin/marketplace/${ encodeURIComponent( pluginId ) }/tools`;

	return {
		state: ( accountId: number | null ) =>
			apiFetch< ToolsState >( {
				path: accountId === null ? base : `${ base }?account_id=${ accountId }`,
			} ),
		preview: ( tool: string, accountId: number | null ) =>
			apiFetch< ToolPreview >( {
				path: `${ base }/${ encodeURIComponent( tool ) }/preview`,
				method: 'POST',
				data: accountId === null ? {} : { account_id: accountId },
			} ),
		run: ( tool: string, accountId: number | null ) =>
			apiFetch< ToolRunResult >( {
				path: `${ base }/${ encodeURIComponent( tool ) }/run`,
				method: 'POST',
				data: accountId === null ? { confirmed: true } : { account_id: accountId, confirmed: true },
			} ),
	};
}
