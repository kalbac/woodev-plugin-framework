/**
 * UI-kit gallery React entry — mounts into #woodev-ui-kit-app.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { createRoot } from '@wordpress/element';
import App from './app';
import './style.scss';

const rootElement = document.getElementById( 'woodev-ui-kit-app' );

if ( rootElement ) {
	createRoot( rootElement ).render( <App /> );
}
