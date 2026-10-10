/**
 * Woodev Shipping — «the parcel is already on its way» warning on the order-edit screen (card #1204).
 *
 * Setting a WooCommerce order to «Отменён» does not stop a parcel the carrier is already delivering. PHP knows
 * whether this order's parcel is in that state and only then enqueues this file, with the sentence and the status
 * value to watch for in `window.woodevShippingInTransit` — nothing here knows a status name or a word of text
 * (AGENT-RULES Rule 9). When the manager picks that status in the order's status select, a non-blocking inline
 * warning appears under it; picking another status removes it. It never touches the form: saving is never blocked.
 *
 * The status select is a selectWoo field, which announces a pick through jQuery only — a native `change` listener
 * would never hear it (gotcha `jquery-trigger-change-fires-no-native-event`), so this binds through jQuery.
 *
 * @see woodev/shipping-method/admin/orders/class-orders-registry.php::enqueue_in_transit_warning()
 */
( function ( $ ) {
	'use strict';

	var CLASS_NAME = 'woodev-in-transit-warning';

	/**
	 * Shows the warning under the select while it holds the watched status, removes it otherwise.
	 *
	 * @param {jQuery} $select the order status select.
	 * @param {{status: string, message: string}} config the PHP-supplied settings.
	 * @return {void}
	 */
	function sync( $select, config ) {
		var $container = $select.parent();

		$container.find( '.' + CLASS_NAME ).remove();

		if ( String( $select.val() ) !== config.status ) {
			return;
		}

		$( '<p></p>' )
			.addClass( CLASS_NAME + ' notice notice-warning inline' )
			.attr( 'role', 'status' )
			.css( { margin: '8px 0 0', padding: '6px 10px' } )
			.text( config.message )
			.appendTo( $container );
	}

	/**
	 * Binds the warning to the order status select.
	 *
	 * @param {{status: string, message: string}|undefined} config the PHP-supplied settings.
	 * @return {boolean} whether a select was found and bound.
	 */
	function init( config ) {
		var $select;

		if ( ! $ || ! config || ! config.status || ! config.message ) {
			return false;
		}

		$select = $( '#order_status' );

		if ( 0 === $select.length ) {
			return false;
		}

		$select.on( 'change', function () {
			sync( $select, config );
		} );

		return true;
	}

	if ( $ ) {
		$( function () {
			init( window.woodevShippingInTransit );
		} );
	}

	// -------------------------------------------------------------------------
	// CommonJS (jest) — the pieces, for direct unit testing.
	// -------------------------------------------------------------------------

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = {
			init: init,
			sync: sync,
		};
	}
}( window.jQuery ) );
