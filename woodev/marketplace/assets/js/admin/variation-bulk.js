/**
 * The notice of the marketplace's bulk actions in the variation panel of the product edit screen.
 *
 * WooCommerce's own script runs a bulk action by AJAX and then reloads the variations; it draws nothing for an action it does not
 * know. The server answers an action of ours with a marked comment carrying the notice (tone, text, optional link). This script
 * reads it and draws a WooCommerce-style inline notice above the variations. Nothing else of WooCommerce's flow is touched.
 */
( function ( $ ) {
	'use strict';

	var MARK = /<!--woodev-bulk:([\s\S]*?)-->/;
	var TONES = { success: 1, warning: 1, error: 1 };
	var ROW = 'woodev-mp-variation-notice';

	/**
	 * Finds the marketplace whose action the request was: one entry of `woodevMarketplaceVariationBulk` per plugin.
	 *
	 * @param {Object} settings The AJAX settings of the request.
	 * @return {Object|null} The entry, or null for any other request.
	 */
	function configOf( settings ) {
		var data = settings && typeof settings.data === 'string' ? settings.data : '';
		var all = window.woodevMarketplaceVariationBulk || [];
		var i;

		if ( -1 === data.indexOf( 'action=woocommerce_bulk_edit_variations' ) ) {
			return null;
		}

		for ( i = 0; i < all.length; i++ ) {
			if ( -1 !== data.indexOf( 'bulk_action=' + all[ i ].prefix ) ) {
				return all[ i ];
			}
		}

		return null;
	}

	/**
	 * Draws the notice, replacing the one of the previous action.
	 *
	 * @param {string} tone `success`, `warning` or `error`.
	 * @param {string} text The sentence.
	 * @param {string} url  Where the progress is seen, or ''.
	 * @param {string} link The text of the link.
	 */
	function show( tone, text, url, link ) {
		var $host = $( '#variable_product_options_inner' );
		var $notice = $( '<div class="notice inline"><p></p></div>' ).addClass( ROW + ' notice-' + ( TONES[ tone ] ? tone : 'warning' ) );

		$host = $host.length ? $host : $( '#variable_product_options' );
		$( '.' + ROW ).remove();
		$notice.find( 'p' ).text( text );

		if ( url && link ) {
			$notice.find( 'p' ).append( ' ' ).append( $( '<a></a>' ).attr( 'href', url ).text( link ) );
		}

		$host.prepend( $notice );
	}

	$( document ).on( 'ajaxSuccess', function ( event, xhr, settings ) {
		var found;
		var notice;

		if ( ! configOf( settings ) ) {
			return;
		}

		found = MARK.exec( ( xhr && xhr.responseText ) || '' );

		try {
			notice = found ? JSON.parse( found[ 1 ] ) : null;
		} catch ( e ) {
			notice = null;
		}

		if ( notice && notice.text ) {
			show( notice.tone, notice.text, notice.url, notice.link );
		}
	} );

	$( document ).on( 'ajaxError', function ( event, xhr, settings ) {
		var config = configOf( settings );

		if ( config ) {
			show( 'error', config.failed, '', '' );
		}
	} );
}( window.jQuery ) );
