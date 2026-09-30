/**
 * Woodev Shipping — order-edit metabox action buttons (card #1012).
 *
 * The carrier metabox lives INSIDE WooCommerce's order form, and HTML forbids a form inside a form:
 * the browser drops the inner `<form>` opening tag and the inner `</form>` closes the OUTER order
 * form, so the status select and every other main-column field stopped being submitted. The view
 * (`admin/views/html-admin-order-metabox.php`) therefore renders each action as a plain
 * `<button type="button">` carrying its whole payload as data attributes, and this file turns a
 * click into a POST to the same `admin-post.php` handler the nested form used to target.
 *
 * PHP-DRIVEN (AGENT-RULES Rule 9): nothing here knows an action name, a nonce or a sentence. The
 * button says where to post (`data-post-url`), what to post (`data-post-action`, `data-order-id`,
 * `data-nonce`, `data-woodev-order-action`) and — for a destructive action only — what to ask
 * (`data-confirm`). The request carries exactly the fields the old form did: `_wpnonce`, `action`,
 * `order_id` and `woodev_shipping_order_action`.
 *
 * The form is built on `document.body`, OUTSIDE the order form, so submitting it never touches the
 * order form's own fields. A disabled button (an order another manager is editing, #1000) is never
 * submitted, and a second click while the first POST is in flight is ignored.
 *
 * @see woodev/shipping-method/admin/views/html-admin-order-metabox.php
 * @see woodev/shipping-method/admin/class-shipping-admin-order.php::handle_order_action()
 */
( function () {
	'use strict';

	var SELECTOR = 'button[data-woodev-order-action]';

	/** True once a POST has been started — the page is about to navigate away. */
	var submitting = false;

	/**
	 * Builds the detached form for a button and appends it to `<body>`.
	 *
	 * @param {HTMLButtonElement} button the clicked action button.
	 * @return {HTMLFormElement} the form, appended to `document.body` and not yet submitted.
	 */
	function buildForm( button ) {
		var form = document.createElement( 'form' );

		form.method = 'post';
		form.action = button.getAttribute( 'data-post-url' ) || '';
		form.style.display = 'none';

		[
			[ '_wpnonce', button.getAttribute( 'data-nonce' ) ],
			[ 'action', button.getAttribute( 'data-post-action' ) ],
			[ 'order_id', button.getAttribute( 'data-order-id' ) ],
			[ 'woodev_shipping_order_action', button.getAttribute( 'data-woodev-order-action' ) ],
		].forEach( function ( pair ) {
			var input = document.createElement( 'input' );

			input.type = 'hidden';
			input.name = pair[ 0 ];
			input.value = pair[ 1 ] || '';
			form.appendChild( input );
		} );

		document.body.appendChild( form );

		return form;
	}

	/**
	 * Handles a click anywhere in the document; acts only on an action button.
	 *
	 * @param {MouseEvent} event the click.
	 * @return {void}
	 */
	function onClick( event ) {
		var target = event.target;
		var button = target && target.closest ? target.closest( SELECTOR ) : null;

		if ( ! button || button.disabled || submitting ) {
			return;
		}

		var question = button.getAttribute( 'data-confirm' );

		if ( question && ! window.confirm( question ) ) {
			return;
		}

		event.preventDefault();
		submitting = true;
		buildForm( button ).submit();
	}

	document.addEventListener( 'click', onClick );

	// -------------------------------------------------------------------------
	// CommonJS (jest) — the pieces, for direct unit testing.
	// -------------------------------------------------------------------------

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = {
			buildForm: buildForm,
			onClick: onClick,
		};
	}
}() );
