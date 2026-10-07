/** WooCommerce zone forms: reflect the effective carrier default for leftovers visibility. */
( function ( $ ) {
	'use strict';
	function refresh() {
		document.querySelectorAll( 'select[data-woodev-packing-default]' ).forEach( function ( leftovers ) {
			var packing = document.getElementById( leftovers.id.replace( /_unpacked_algorithm$/, '_packing_algorithm' ) );
			if ( ! packing ) { return; }
			var mode = packing.value === 'default' || ! packing.value ? leftovers.getAttribute( 'data-woodev-packing-default' ) : packing.value;
			var row = leftovers.closest( 'tr' );
			if ( row ) { row.hidden = mode !== 'boxes'; }
		} );
	}
	$( document ).on( 'change wc_backbone_modal_loaded', refresh );
	$( refresh );
}( jQuery ) );
