/**
 * REST client of the diagnostics view: `woodev/v1/admin/marketplace/{plugin id}/diagnostics`.
 *
 * Cookie auth and the `wp_rest` nonce are added by `@wordpress/api-fetch`. While «Логирование» is off the report route
 * answers 403 with the sentence the page shows.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { DiagnosticsReport, DiagnosticsState } from './types';

export type DiagnosticsApi = ReturnType< typeof createDiagnosticsApi >;

/**
 * @param pluginId The marketplace plugin's id (the manifest's `pluginId`).
 */
export function createDiagnosticsApi( pluginId: string ) {
	const base = `/woodev/v1/admin/marketplace/${ encodeURIComponent( pluginId ) }/diagnostics`;

	return {
		state: () => apiFetch< DiagnosticsState >( { path: base } ),
		report: () => apiFetch< DiagnosticsReport >( { path: `${ base }/report` } ),
		setLogging: ( enabled: boolean ) =>
			apiFetch< { logging: { enabled: boolean } } >( {
				path: `${ base }/logging`,
				method: 'POST',
				data: { enabled },
			} ),
	};
}
