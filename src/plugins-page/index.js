/**
 * «Плагины» page — entry point. Wires apiFetch (root URL + nonce) and mounts
 * <App> into #woodev-extensions-app. Bails silently when the mount node or the
 * window.woodevExtensions bootstrap object is absent (server-side guard).
 *
 * Classic JSX runtime: every JSX file imports { createElement, Fragment } from
 * '@wordpress/element'. The classic runtime compiles <Foo /> to createElement(Foo)
 * — no react/jsx-runtime import is emitted, so the WP dependency stays at
 * wp-element (WP 6.3+ compatible). See babel.config.js and ADR-007.
 *
 * @package woodev-plugin-framework
 */

import './style.scss';

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime (babel.config.js).
import { createElement, Fragment, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './app';

const rootElement = document.getElementById( 'woodev-extensions-app' );

if ( rootElement && window.woodevExtensions ) {
	// Wire REST root URL so paths like '/woodev/v1/...' resolve correctly.
	apiFetch.use( apiFetch.createRootURLMiddleware( window.woodevExtensions.restRoot ) );

	// Wire the wp_rest nonce so core verifies the request.
	apiFetch.use( apiFetch.createNonceMiddleware( window.woodevExtensions.restNonce ) );

	createRoot( rootElement ).render(
		<App config={ window.woodevExtensions } />
	);
}
