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

/**
 * Runs one step action (a server-side operation bound to a step).
 *
 * Resolves with the action's structured answer `{ status: 'success'|'error', message, data }` —
 * a negative business outcome ("the key is not valid") is a resolved `status: 'error'`, not a
 * rejection; only an unexpected failure rejects. A destructive action carries `confirmed: true`,
 * set by the caller after the merchant confirmed it.
 *
 * @param {string}  stepId    step id.
 * @param {string}  actionId  action id.
 * @param {Object}  values    the merchant's edits to the step's fields (the server overlays them on the stored values).
 * @param {boolean} confirmed whether the merchant confirmed a destructive action.
 * @return {Promise} REST promise.
 */
export function runAction( stepId, actionId, values = {}, confirmed = false ) {
	const { restRoot, nonce } = bootstrap();

	return apiFetch( {
		url: `${ restRoot }/steps/${ stepId }/actions/${ actionId }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: confirmed ? { values, confirmed: true } : { values },
	} );
}
