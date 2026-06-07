/**
 * Woodev Shipping — warehouse admin UI behaviour.
 *
 * Progressive enhancement for the warehouse CRUD screen rendered by
 * {@see \Woodev\Framework\Shipping\Admin\Warehouse_Admin}. The screen is fully
 * functional without JavaScript (plain forms posting to admin-post.php); this only
 * guards the destructive delete action behind a confirmation prompt.
 *
 * @since 1.5.0
 */
( function () {
	'use strict';

	/**
	 * Confirms an intent before submitting a delete form.
	 *
	 * @param {Event} event submit event from a delete form
	 * @return {void}
	 */
	function confirmDelete( event ) {
		var message = 'Delete this warehouse? This cannot be undone.';

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	}

	/**
	 * Wires confirmation onto every delete form on the page.
	 *
	 * @return {void}
	 */
	function init() {
		var forms = document.querySelectorAll( '.woodev-warehouse-delete' );

		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', confirmDelete );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
