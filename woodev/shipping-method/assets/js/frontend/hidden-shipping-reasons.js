/**
 * Shows the admin-only shipping reasons returned by the Store API on block checkout.
 * This file is served directly and does not enter the React build.
 */
( function () {
	'use strict';

	const namespace = 'woodev-shipping-hidden-reasons';
	let rendered = '';

	function render() {
		if ( ! window.wp || ! wp.data || ! wp.data.select ) {
			return;
		}
		const cart = wp.data.select( 'wc/store/cart' )?.getCartData?.();
		const messages = cart?.extensions?.[ namespace ]?.messages || [];
		const target = document.querySelector( '.wc-block-components-shipping-rates-control' );
		if ( ! target ) {
			return;
		}
		const signature = JSON.stringify( messages );
		if ( signature === rendered && document.querySelector( '.woodev-hidden-shipping-reasons' ) ) {
			return;
		}
		rendered = signature;
		document.querySelector( '.woodev-hidden-shipping-reasons' )?.remove();
		if ( ! messages.length ) {
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'woocommerce-error woodev-hidden-shipping-reasons';
		list.setAttribute( 'role', 'status' );
		messages.forEach( ( message ) => {
			const item = document.createElement( 'li' );
			item.textContent = message;
			list.appendChild( item );
		} );
		target.insertAdjacentElement( 'afterend', list );
	}

	if ( window.wp?.data?.subscribe ) {
		wp.data.subscribe( render );
		document.addEventListener( 'DOMContentLoaded', render );
	}
} )();
