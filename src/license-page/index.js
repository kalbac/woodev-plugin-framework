/**
 * License Page — entry point.
 *
 * Wires apiFetch middlewares (root URL + nonce) and mounts the <App> into
 * #woodev-licenses-app. Bails silently when the mount element or the
 * window.woodevLicenses bootstrap object is absent (server-side guard).
 *
 * Classic JSX runtime: all JSX files MUST import { createElement, Fragment }
 * from '@wordpress/element'. The classic runtime compiles <Foo /> to
 * createElement(Foo) — no react/jsx-runtime import is emitted, so the WP
 * dependency stays at wp-element (WP 6.3+ compatible). See babel.config.js
 * and ADR-007 for the rationale.
 *
 * @package woodev-plugin-framework
 */

import './style.scss';

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime (babel.config.js).
import { createElement, Fragment, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './app';

const rootElement = document.getElementById( 'woodev-licenses-app' );

if ( rootElement && window.woodevLicenses ) {
	// Wire REST root URL so paths like '/woodev/v1/...' resolve correctly.
	apiFetch.use( apiFetch.createRootURLMiddleware( window.woodevLicenses.restRoot ) );

	// Wire the wp_rest nonce so core verifies the request.
	apiFetch.use( apiFetch.createNonceMiddleware( window.woodevLicenses.restNonce ) );

	createRoot( rootElement ).render(
		<App plugins={ window.woodevLicenses.plugins || [] } />
	);
}
