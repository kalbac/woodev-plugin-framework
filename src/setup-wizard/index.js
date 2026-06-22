/**
 * Setup Wizard — entry point.
 *
 * Mounts <App> into #woodev-setup-wizard-root. Bails silently when the mount
 * element or the window.woodevSetupWizard bootstrap object is absent
 * (server-side guard). All wizard data + REST root + nonce come from the PHP
 * bootstrap, so no props are threaded here.
 *
 * Classic JSX runtime: createElement is imported and used directly (no JSX),
 * so no react/jsx-runtime handle is emitted (WP 6.3+ compatible). See
 * babel.config.js and ADR-007.
 *
 * @package woodev-plugin-framework
 */

import './style.scss';

import { createElement, createRoot } from '@wordpress/element';
import App from './app';

const rootElement = document.getElementById( 'woodev-setup-wizard-root' );

if ( rootElement && window.woodevSetupWizard ) {
	createRoot( rootElement ).render( createElement( App ) );
}
