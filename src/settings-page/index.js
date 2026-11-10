/**
 * Settings page React entry — mounts into #woodev-settings-app.
 *
 * Classic JSX runtime: createElement used directly (no JSX syntax).
 *
 * @package woodev-plugin-framework
 */

import { createElement, createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import App from './app';
import { PAGE_VIEW_FILTER, resolveSettingsView } from './embedded-view';
import './style.scss';

const rootElement = document.getElementById( 'woodev-settings-app' );

if ( rootElement && window.woodevSettings ) {
	createRoot( rootElement ).render( createElement( App ) );
}

// Inside a marketplace plugin's own page the routing bundle renders the body: answer for its `settings` pages.
addFilter( PAGE_VIEW_FILTER, 'woodev/settings-page', resolveSettingsView );
