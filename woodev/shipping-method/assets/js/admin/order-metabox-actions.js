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
 * AN ACTION WITH INPUT (#1180): a button that carries `data-fields` (a JSON list of the action's declared
 * fields) and `data-labels` (the dialog's own sentences) opens the framework modal shell (`WoodevModal`) with
 * the form first, and posts the values as `payload[<id>]` (a time range as `payload[<id>][from|to]`) beside the
 * usual fields. The browser checks `required` / `min` / `max` / `maxlength` while typing; the server checks
 * them again against the same declaration and flashes a notice on a miss.
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

	/** Counts the dialogs opened, so two never share a field id. */
	var dialogSeq = 0;

	/**
	 * Builds the detached form for a button and appends it to `<body>`.
	 *
	 * @param {HTMLButtonElement} button the clicked action button.
	 * @param {Array<Array<string>>} [extra] more `[ name, value ]` pairs to post — the payload of an action with fields.
	 * @return {HTMLFormElement} the form, appended to `document.body` and not yet submitted.
	 */
	function buildForm( button, extra ) {
		var form = document.createElement( 'form' );

		form.method = 'post';
		form.action = button.getAttribute( 'data-post-url' ) || '';
		form.style.display = 'none';

		[
			[ '_wpnonce', button.getAttribute( 'data-nonce' ) ],
			[ 'action', button.getAttribute( 'data-post-action' ) ],
			[ 'order_id', button.getAttribute( 'data-order-id' ) ],
			[ 'woodev_shipping_order_action', button.getAttribute( 'data-woodev-order-action' ) ],
		].concat( extra || [] ).forEach( function ( pair ) {
			var input = document.createElement( 'input' );

			input.type = 'hidden';
			input.name = pair[ 0 ];
			input.value = pair[ 1 ] || '';
			form.appendChild( input );
		} );

		document.body.appendChild( form );

		return form;
	}

	/** @param {string|null} text JSON text. @return {*} the parsed value, or null when it is not JSON. */
	function parseJson( text ) {
		try {
			return text ? JSON.parse( text ) : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * @param {string} tag       element name.
	 * @param {string} [className] class list.
	 * @param {string} [text]    text content (never markup).
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * Builds the input (or inputs) of one declared field.
	 *
	 * @param {Object} field  one entry of the action's `fields`.
	 * @param {string} uid    a page-unique prefix for ids.
	 * @param {Object} labels the dialog's sentences.
	 * @return {{wrap: HTMLElement, checks: Function}} the field's block; `checks()` runs the checks the browser has no attribute for.
	 */
	function buildField( field, uid, labels ) {
		var wrap = el( 'div', 'woodev-action-form__field woodev-action-form__field--' + field.type );
		var controlId = uid + '-' + field.id;
		var label = el( 'label', 'woodev-action-form__label', field.label );
		var name = 'payload[' + field.id + ']';
		var control;
		var checks = function () {};

		label.setAttribute( 'for', controlId );

		if ( field.required ) {
			var star = el( 'span', 'woodev-action-form__required', ' *' );

			star.setAttribute( 'title', labels.required || '' );
			label.appendChild( star );
		}

		wrap.appendChild( label );

		if ( 'select' === field.type ) {
			control = el( 'select', 'woodev-action-form__control' );

			if ( ! field.required || ! field.default ) {
				var blank = el( 'option', '', labels.none || '' );

				blank.value = '';
				control.appendChild( blank );
			}

			( field.options || [] ).forEach( function ( option ) {
				var node = el( 'option', '', option.label );

				node.value = option.value;
				control.appendChild( node );
			} );
			control.value = field.default || '';
		} else if ( 'textarea' === field.type ) {
			control = el( 'textarea', 'woodev-action-form__control' );
			control.rows = 3;
			control.maxLength = field.maxlength;
			control.value = field.default || '';
		} else if ( 'time_range' === field.type ) {
			control = el( 'div', 'woodev-action-form__range' );

			var inputs = {};

			[ 'from', 'to' ].forEach( function ( part ) {
				var span = el( 'span', 'woodev-action-form__range-part' );
				var input = el( 'input', 'woodev-action-form__control' );

				input.type = 'time';
				input.name = name + '[' + part + ']';
				input.required = !! field.required;
				input.value = ( field.default && field.default[ part ] ) || '';

				if ( field.min ) {
					input.min = field.min;
				}

				if ( field.max ) {
					input.max = field.max;
				}

				span.appendChild( el( 'span', 'woodev-action-form__range-label', labels[ part ] || part ) );
				span.appendChild( input );
				control.appendChild( span );
				inputs[ part ] = input;
			} );

			inputs.from.id = controlId;
			checks = function () {
				// A window must end after it starts, and an optional one is filled in whole or not at all.
				var from = inputs.from.value;
				var to = inputs.to.value;
				var message = '';

				if ( ( from || to ) && ( ! from || ! to || from >= to ) ) {
					message = labels.range || '';
				}

				inputs.to.setCustomValidity( message );
			};

			// Recomputed as either end is edited, not only on submit: the browser refuses a submit while a custom error
			// stands and fires no submit event then, so a check that ran only there could never clear its own error.
			[ inputs.from, inputs.to ].forEach( function ( input ) {
				input.addEventListener( 'input', checks );
				input.addEventListener( 'change', checks );
			} );
		} else {
			control = el( 'input', 'woodev-action-form__control' );
			control.type = 'date';
			control.value = field.default || '';

			if ( field.min ) {
				control.min = field.min;
			}

			if ( field.max ) {
				control.max = field.max;
			}
		}

		if ( 'time_range' !== field.type ) {
			control.id = controlId;
			control.name = name;
			control.required = !! field.required;
		}

		wrap.appendChild( control );

		return { wrap: wrap, checks: checks };
	}

	/**
	 * Opens the framework modal with the form of an action's declared fields; its submit posts the action.
	 *
	 * @param {HTMLButtonElement} button the clicked action button.
	 * @param {Array<Object>}     fields the action's declared fields.
	 * @return {void}
	 */
	function openFieldsDialog( button, fields ) {
		var labels = parseJson( button.getAttribute( 'data-labels' ) ) || {};
		var title = ( button.textContent || '' ).trim();

		if ( button._woodevFieldsModal ) {
			button._woodevFieldsModal.destroy();
		}

		var modal = new window.WoodevModal( {
			modalId: 'woodev-order-action-fields',
			title: title,
			closeLabel: labels.close || 'Close',
			returnFocusTo: button,
			width: 420,
		} );
		var form = el( 'form', 'woodev-action-form' );
		var uid = 'woodev-action-' + ( ++dialogSeq );
		var built = fields.map( function ( field ) {
			return buildField( field, uid, labels );
		} );
		var buttons = el( 'div', 'woodev-action-form__buttons' );
		var cancel = el( 'button', 'button', labels.cancel || '' );
		var submit = el( 'button', 'button button-primary', title );

		form.noValidate = false;
		built.forEach( function ( item ) {
			form.appendChild( item.wrap );
		} );

		cancel.type = 'button';
		submit.type = 'submit';
		buttons.appendChild( cancel );
		buttons.appendChild( submit );
		form.appendChild( buttons );

		cancel.addEventListener( 'click', function () {
			modal.close( 'button' );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( submitting ) {
				return;
			}

			built.forEach( function ( item ) {
				item.checks();
			} );

			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}

			var pairs = [];

			Array.prototype.forEach.call( form.elements, function ( input ) {
				if ( input.name && 0 === input.name.indexOf( 'payload[' ) ) {
					pairs.push( [ input.name, input.value ] );
				}
			} );

			submitting = true;
			buildForm( button, pairs ).submit();
		} );

		modal.getContainer().appendChild( form );
		button._woodevFieldsModal = modal;
		modal.open();
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

		var fields = parseJson( button.getAttribute( 'data-fields' ) );

		if ( fields && fields.length && window.WoodevModal ) {
			event.preventDefault();
			openFieldsDialog( button, fields );
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
			buildField: buildField,
			onClick: onClick,
		};
	}
}() );
