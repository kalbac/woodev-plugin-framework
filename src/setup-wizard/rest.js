/**
 * Setup Wizard — REST client.
 *
 * Talks to the neutral woodev/v1 setup routes using the full restRoot + nonce
 * provided by the PHP bootstrap (window.woodevSetupWizard). No middleware: each
 * call passes the absolute URL and the X-WP-Nonce header explicitly.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Reads the PHP bootstrap lazily (never at module load), so importing this file
 * has no side effect and the entry-point guard in index.js stays meaningful.
 *
 * @return {Object} { restRoot, nonce } (empty when the bootstrap is absent).
 */
function bootstrap() {
	return window.woodevSetupWizard || {};
}

/**
 * Persists one step's values.
 *
 * @param {string} stepId step id.
 * @param {Object} values field id => value.
 * @return {Promise} REST promise.
 */
export function saveStep( stepId, values ) {
	const { restRoot, nonce } = bootstrap();

	return apiFetch( {
		url: `${ restRoot }/steps/${ stepId }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}

/**
 * Finalizes the wizard (completed or skipped).
 *
 * @param {string} state 'completed' (default) or 'skipped'.
 * @return {Promise} REST promise.
 */
export function complete( state = 'completed' ) {
	const { restRoot, nonce } = bootstrap();

	return apiFetch( {
		url: `${ restRoot }/complete`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { state },
	} );
}
