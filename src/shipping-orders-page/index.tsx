/**
 * Shipping orders page React entry — mounts into #woodev-shipping-orders-app.
 *
 * Classic JSX runtime: createElement used directly (no JSX syntax).
 *
 * @package woodev-plugin-framework
 */

import { createElement, createRoot } from '@wordpress/element';
import App from './app';
import './style.scss';

const rootElement = document.getElementById( 'woodev-shipping-orders-app' );

if ( rootElement && window.woodevShippingOrders ) {
	createRoot( rootElement ).render( createElement( App ) );
}
