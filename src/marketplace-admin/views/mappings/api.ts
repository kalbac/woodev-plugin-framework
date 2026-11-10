/**
 * REST client of the mappings view: `woodev/v1/admin/marketplace/{plugin id}/mappings`.
 *
 * Cookie auth and the `wp_rest` nonce are added by `@wordpress/api-fetch`; the server checks the capability. A failure is
 * the server's `{ code, message, data: { errors } }`, and {@see errorLines} turns it into the sentences to show.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type {
	AttributeRow,
	CategoryChoice,
	CheckStep,
	DictionaryValue,
	MappingDetail,
	MappingsState,
	ProfileDetail,
	SaveResult,
} from './types';

type Query = Record< string, string | number | null | undefined >;

function withQuery( path: string, query: Query = {} ): string {
	const params = new URLSearchParams();

	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== null && value !== undefined && value !== '' ) {
			params.set( key, String( value ) );
		}
	} );

	const text = params.toString();

	return text === '' ? path : `${ path }?${ text }`;
}

/**
 * The sentences of a failed request: the server's list when it sent one, else its message, else the fallback.
 *
 * @param error    What `apiFetch` rejected with.
 * @param fallback Sentence for a failure that carries none.
 */
export function errorLines( error: unknown, fallback: string ): string[] {
	const failure = error as { message?: string; data?: { errors?: string[] } } | null;
	const lines = failure?.data?.errors?.filter( Boolean ) ?? [];

	if ( lines.length > 0 ) {
		return lines;
	}

	return [ failure?.message || fallback ];
}

export type MappingsApi = ReturnType< typeof createApi >;

/**
 * @param pluginId The marketplace plugin's id (the manifest's `pluginId`).
 */
export function createApi( pluginId: string ) {
	const base = `/woodev/v1/admin/marketplace/${ encodeURIComponent( pluginId ) }/mappings`;
	const send = < T >( method: string, path: string, data?: unknown ) => apiFetch< T >( { path: base + path, method, data } );

	return {
		state: () => apiFetch< MappingsState >( { path: base } ),
		searchCategories: ( search: string ) =>
			apiFetch< { results: CategoryChoice[] } >( { path: withQuery( `${ base }/categories`, { search } ) } ),
		fields: ( category: string, mappingId: number | null ) =>
			apiFetch< { attributes: AttributeRow[] } >( {
				path: withQuery( `${ base }/fields`, { category, mapping_id: mappingId } ),
			} ),
		dictionary: ( category: string, field: string, search: string ) =>
			apiFetch< { results: DictionaryValue[] } >( { path: withQuery( `${ base }/dictionary`, { category, field, search } ) } ),
		mapping: ( id: number ) => apiFetch< MappingDetail >( { path: `${ base }/mappings/${ id }` } ),
		saveMapping: ( id: number | null, body: Record< string, unknown > ) =>
			send< SaveResult >( 'POST', id === null ? '/mappings' : `/mappings/${ id }`, body ),
		copyMapping: ( id: number ) => send< SaveResult >( 'POST', `/mappings/${ id }/copy`, {} ),
		deleteMapping: ( id: number ) => send< SaveResult >( 'DELETE', `/mappings/${ id }` ),
		check: ( id: number, offset: number, run: string ) => send< CheckStep >( 'POST', `/mappings/${ id }/check`, { offset, run } ),
		profile: ( id: number ) => apiFetch< ProfileDetail >( { path: `${ base }/profiles/${ id }` } ),
		saveProfile: ( id: number | null, body: Record< string, unknown > ) =>
			send< SaveResult >( 'POST', id === null ? '/profiles' : `/profiles/${ id }`, body ),
		deleteProfile: ( id: number ) => send< SaveResult >( 'DELETE', `/profiles/${ id }` ),
	};
}
