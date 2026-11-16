/**
 * REST client for the settings page (woodev/v1/settings).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { getBootstrap } from './bootstrap';

/**
 * Calls the REST API and turns «the answer is not JSON» into a message a merchant can act on.
 *
 * When a plugin or the theme takes a REST address over, WordPress answers with a rendered page, and api-fetch reports
 * it as `invalid_json` («The response is not a valid JSON response»). The merchant reads that as «the plugin is
 * broken»; the cause is almost always another plugin's rewrite rule (#1595).
 *
 * @param {Object} options api-fetch options.
 * @return {Promise<*>} the parsed response.
 */
export function request( options ) {
	return apiFetch( options ).catch( ( err ) => {
		if ( err && 'invalid_json' === err.code ) {
			throw {
				...err,
				message: __(
					'Сайт вернул страницу вместо ответа API — вероятен конфликт плагинов или правил перезаписи. Отключите по очереди плагины безопасности и кеширования и проверьте снова.',
					'woodev-plugin-framework'
				),
			};
		}
		throw err;
	} );
}

/**
 * Fetches the aggregated, cap-filtered tab schema — the Woodev page's tabs, or, in a body embedded in a marketplace
 * plugin's page, the tabs of that plugin (`?placement=`).
 *
 * @return {Promise<Object>} { tabs: [...] }
 */
export function fetchSchema() {
	const { restRoot, nonce, placement } = getBootstrap();
	const separator = restRoot && restRoot.includes( '?' ) ? '&' : '?';
	return request( {
		url: placement
			? `${ restRoot }${ separator }placement=${ encodeURIComponent(
					placement
			  ) }`
			: restRoot,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}

/**
 * Saves one tab's edited values.
 *
 * @param {string} providerId tab/provider id.
 * @param {Object} values     edited setting_id => value.
 * @return {Promise<Object>} { saved: true, provider }
 */
export function saveTab( providerId, values ) {
	const { restRoot, nonce } = getBootstrap();
	return request( {
		url: `${ restRoot }/${ providerId }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}

/**
 * Tests one connection section's credentials.
 *
 * @param {string} providerId   tab/provider id.
 * @param {string} connectionId connection section id.
 * @param {Object} values       edited setting_id => value.
 * @return {Promise<Object>} { success, message }
 */
export function testConnection( providerId, connectionId, values ) {
	const { restRoot, nonce } = getBootstrap();
	return request( {
		url: `${ restRoot }/${ providerId }/connection/${ connectionId }/verify`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}

/**
 * Runs one «Инструменты» tool.
 *
 * @param {string} providerId tab/provider id.
 * @param {string} toolId     tool id.
 * @param {Object} args       selector values keyed by selector name.
 * @return {Promise<Object>} { success, message }
 */
export function runTool( providerId, toolId, args ) {
	const { restRoot, nonce } = getBootstrap();
	return request( {
		url: `${ restRoot }/${ providerId }/tool/${ toolId }/run`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { args },
	} );
}
