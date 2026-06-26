/**
 * Settings page React entry — mounts into #woodev-settings-app.
 *
 * Classic JSX runtime: createElement used directly (no JSX syntax).
 *
 * @package woodev-plugin-framework
 */

import { createElement, createRoot } from '@wordpress/element';
import App from './app';
import './style.scss';

const rootElement = document.getElementById( 'woodev-settings-app' );

if ( rootElement && window.woodevSettings ) {
	createRoot( rootElement ).render( createElement( App ) );
}
