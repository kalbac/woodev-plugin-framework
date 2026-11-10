/**
 * REST client for the settings page (woodev/v1/settings).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { getBootstrap } from './bootstrap';

/**
 * Fetches the aggregated, cap-filtered tab schema — the Woodev page's tabs, or, in a body embedded in a marketplace
 * plugin's page, the tabs of that plugin (`?placement=`).
 *
 * @return {Promise<Object>} { tabs: [...] }
 */
export function fetchSchema() {
	const { restRoot, nonce, placement } = getBootstrap();
	const separator = restRoot && restRoot.includes( '?' ) ? '&' : '?';
	return apiFetch( {
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
	return apiFetch( {
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
	return apiFetch( {
		url: `${ restRoot }/${ providerId }/connection/${ connectionId }/test`,
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
	return apiFetch( {
		url: `${ restRoot }/${ providerId }/tool/${ toolId }/run`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { args },
	} );
}
