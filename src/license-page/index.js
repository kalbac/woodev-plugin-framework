/**
 * License Page — minimal placeholder entry point.
 *
 * NOTE: This file is a compilable placeholder only.
 * Task s6-p4 replaces the contents with the full license-management UI.
 *
 * Classic JSX runtime: all JSX files in this package MUST import
 * { createElement, Fragment } from '@wordpress/element'. The classic runtime
 * compiles <Foo /> to createElement(Foo) — no react/jsx-runtime import is
 * emitted, so the WP dependency stays at wp-element (WP 6.3+ compatible).
 * See babel.config.js and ADR-007 for the rationale.
 *
 * @package woodev-plugin-framework
 */

import './style.scss';
// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime (babel.config.js).
import { createElement, Fragment, createRoot } from '@wordpress/element';

const rootElement = document.getElementById( 'woodev-licenses-app' );

if ( rootElement ) {
	createRoot( rootElement ).render(
		<div className="woodev-licenses-grid" />
	);
}
