/**
 * REST client for the settings page (woodev/v1/settings).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';

function bootstrap() {
	return window.woodevSettings || {};
}

/**
 * Fetches the aggregated, cap-filtered tab schema.
 *
 * @return {Promise<Object>} { tabs: [...] }
 */
export function fetchSchema() {
	const { restRoot, nonce } = bootstrap();
	return apiFetch( {
		url: restRoot,
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
	const { restRoot, nonce } = bootstrap();
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
	const { restRoot, nonce } = bootstrap();
	return apiFetch( {
		url: `${ restRoot }/${ providerId }/connection/${ connectionId }/test`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}
