/**
 * The cancel dialog of a marketplace posting on the order screens (the order metabox, either order storage).
 *
 * One dialog serves every `.woodev-mp-cancel` button of the page. A button carries the order id, its nonce and the two AJAX actions;
 * the dialog carries the endpoint and the texts. Plain DOM calls only (`textContent`, never `innerHTML`). A successful cancellation
 * reloads the page, which then shows the order as the marketplace reports it.
 */
( function () {
	'use strict';

	var dialog = document.getElementById( 'woodev-mp-cancel-dialog' );

	if ( ! dialog ) {
		return;
	}

	var select     = document.getElementById( 'woodev-mp-cancel-reason' );
	var messageRow = document.getElementById( 'woodev-mp-cancel-message-row' );
	var message    = document.getElementById( 'woodev-mp-cancel-message' );
	var status     = document.getElementById( 'woodev-mp-cancel-status' );
	var confirm    = document.getElementById( 'woodev-mp-cancel-confirm' );
	var order      = null;
	var otherReason = 0;

	function post( action, extra ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'order_id', order.id );
		body.append( 'nonce', order.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

		return fetch( dialog.getAttribute( 'data-url' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function say( text ) {
		status.textContent = text;
	}

	function failure( answer ) {
		say( answer && answer.data && answer.data.message ? answer.data.message : dialog.getAttribute( 'data-failed' ) );
	}

	function syncMessage() {
		messageRow.hidden = parseInt( select.value, 10 ) !== otherReason;
	}

	function open( button ) {
		order = {
			id: button.getAttribute( 'data-order-id' ),
			nonce: button.getAttribute( 'data-nonce' ),
			reasonsAction: button.getAttribute( 'data-reasons-action' ),
			cancelAction: button.getAttribute( 'data-cancel-action' )
		};
		select.textContent = '';
		select.disabled = true;
		confirm.disabled = true;
		message.value = '';
		messageRow.hidden = true;
		say( dialog.getAttribute( 'data-loading' ) );
		dialog.showModal();

		post( order.reasonsAction ).then( function ( answer ) {
			if ( ! answer || ! answer.success ) {
				failure( answer );
				return;
			}

			otherReason = answer.data.other_reason;
			answer.data.reasons.forEach( function ( reason ) {
				var option = document.createElement( 'option' );

				option.value = reason.id;
				option.textContent = reason.title;
				select.appendChild( option );
			} );
			select.disabled = false;
			confirm.disabled = false;
			say( '' );
			syncMessage();
		} ).catch( function () {
			say( dialog.getAttribute( 'data-failed' ) );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( dialog.getAttribute( 'data-button' ) ) : null;

		if ( button ) {
			event.preventDefault();
			open( button );
		}
	} );
	select.addEventListener( 'change', syncMessage );
	document.getElementById( 'woodev-mp-cancel-close' ).addEventListener( 'click', function () {
		dialog.close();
	} );
	confirm.addEventListener( 'click', function () {
		confirm.disabled = true;
		say( dialog.getAttribute( 'data-sending' ) );

		post( order.cancelAction, { reason_id: select.value, message: message.value } ).then( function ( answer ) {
			if ( answer && answer.success ) {
				window.location.reload();
				return;
			}

			failure( answer );
			confirm.disabled = false;
		} ).catch( function () {
			say( dialog.getAttribute( 'data-failed' ) );
			confirm.disabled = false;
		} );
	} );
}() );
