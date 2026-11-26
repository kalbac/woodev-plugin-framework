/**
 * The seller action dialog of a marketplace order on the order screens.
 *
 * One dialog serves every `.woodev-mp-action` button of the page. A button carries the order id, its nonce, the two AJAX actions,
 * the unit (empty for the whole order) and the action's declaration in `data-config`: the name, the sentence to confirm and the
 * fields. Plain DOM calls only (`textContent`, never `innerHTML`). After a run with nothing left to say the page is read again;
 * otherwise the dialog lists every unit that was not done, and the page is read again when it is closed.
 *
 * Each opening of the dialog is its own request token (`current`): an answer to a request made from an earlier opening changes
 * nothing on screen, which by then may belong to another order or unit.
 */
( function () {
	'use strict';

	var dialog = document.getElementById( 'woodev-mp-action-dialog' );

	if ( ! dialog ) {
		return;
	}

	// The shared dialog (`woodev-dialog.js`): «✕», «Отмена» and the backdrop close it, Esc comes with the native element.
	var handle  = window.WoodevDialog.attach( dialog );
	var title   = document.getElementById( 'woodev-mp-action-title' );
	var text    = document.getElementById( 'woodev-mp-action-text' );
	var fields  = document.getElementById( 'woodev-mp-action-fields' );
	var status  = document.getElementById( 'woodev-mp-action-status' );
	var lines   = document.getElementById( 'woodev-mp-action-lines' );
	var confirm = document.getElementById( 'woodev-mp-action-confirm' );
	var closer  = document.getElementById( 'woodev-mp-action-close' );
	var current = null;
	var reload  = false;

	function post( action, extra ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'order_id', current.order );
		body.append( 'nonce', current.nonce );
		body.append( 'seller_action', current.config.id );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

		return fetch( dialog.getAttribute( 'data-url' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function say( sentence ) {
		status.textContent = sentence;
	}

	function make( tag, className, content ) {
		var element = document.createElement( tag );

		element.className = className;

		if ( content ) {
			element.textContent = content;
		}

		return element;
	}

	function option( value, label ) {
		var element = make( 'option', '', label );

		element.value = value;

		return element;
	}

	function fill( select, options ) {
		select.textContent = '';
		select.appendChild( option( '', dialog.getAttribute( 'data-choose' ) ) );
		options.forEach( function ( entry ) {
			select.appendChild( option( entry.value, entry.label ) );
		} );
		select.disabled = false;
	}

	function load( select, field ) {
		var asked = current;

		select.disabled = true;
		select.appendChild( option( '', dialog.getAttribute( 'data-loading' ) ) );

		post( asked.optionsAction, { field: field.id } ).then( function ( answer ) {
			if ( asked !== current ) {
				return;
			}

			if ( ! answer || ! answer.success ) {
				failure( answer );
				return;
			}

			fill( select, answer.data.options );
		} ).catch( function () {
			if ( asked === current ) {
				say( dialog.getAttribute( 'data-failed' ) );
			}
		} );
	}

	function control( field ) {
		if ( field.type === 'textarea' ) {
			return make( 'textarea', 'large-text' );
		}

		if ( field.type === 'select' ) {
			return make( 'select', '' );
		}

		var input = make( 'input', 'large-text' );

		input.type         = 'text';
		input.autocomplete = 'off';
		input.spellcheck   = false;
		// A scanner types the code and sends Enter.
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				send();
			}
		} );

		return input;
	}

	function draw( field ) {
		var row   = make( 'p', 'woodev-mp-action__field' );
		var id    = 'woodev-mp-action-field-' + field.id;
		var label = make( 'label', '', field.label + ( field.required ? ' *' : '' ) );
		var input = control( field );
		var error = make( 'span', 'woodev-mp-action__error' );

		label.htmlFor = id;
		input.id      = id;
		input.setAttribute( 'data-field', field.id );
		error.setAttribute( 'role', 'alert' );
		error.setAttribute( 'data-error', field.id );
		row.appendChild( label );
		row.appendChild( input );

		if ( field.help ) {
			row.appendChild( make( 'span', 'description', field.help ) );
		}

		row.appendChild( error );
		fields.appendChild( row );

		if ( field.type === 'select' ) {
			if ( field.source ) {
				load( input, field );
			} else {
				fill( input, field.options );
			}
		}
	}

	function open( button ) {
		current = {
			order: button.getAttribute( 'data-order-id' ),
			nonce: button.getAttribute( 'data-nonce' ),
			runAction: button.getAttribute( 'data-run-action' ),
			optionsAction: button.getAttribute( 'data-options-action' ),
			unit: button.getAttribute( 'data-unit' ),
			config: JSON.parse( button.getAttribute( 'data-config' ) )
		};

		title.textContent   = current.config.label;
		text.textContent    = current.config.confirmation;
		text.hidden         = ! current.config.confirmation;
		fields.textContent  = '';
		lines.textContent   = '';
		confirm.textContent = current.config.label;
		confirm.hidden      = false;
		confirm.disabled    = false;
		confirm.classList.toggle( 'woodev-mp-action__danger', current.config.danger );
		closer.textContent  = closer.getAttribute( 'data-label' );
		say( '' );
		current.config.fields.forEach( draw );
		handle.open();
	}

	function failure( answer ) {
		var data = answer && answer.data ? answer.data : {};

		say( data.message ? data.message : dialog.getAttribute( 'data-failed' ) );
		Object.keys( data.errors || {} ).forEach( function ( id ) {
			var error = fields.querySelector( '[data-error="' + id + '"]' );

			if ( error ) {
				error.textContent = data.errors[ id ];
			}
		} );
	}

	function report( data ) {
		reload = reload || data.changed;

		// The dialog was closed while the request ran, or there is nothing to read: the page shows the new state.
		if ( data.lines.length === 0 || ! dialog.open ) {
			window.location.reload();
			return;
		}

		say( data.message );
		data.lines.forEach( function ( line ) {
			lines.appendChild( make( 'li', '', line ) );
		} );
		fields.textContent = '';
		text.hidden        = true;
		confirm.hidden     = true;
		closer.textContent = dialog.getAttribute( 'data-close' );
	}

	// An answer from an earlier opening of the dialog: nothing on screen is its business. When it changed something, the page is
	// read again, at once if no dialog is in the way, else when the dialog is closed.
	function late( data ) {
		if ( ! data || ! data.changed ) {
			return;
		}

		reload = true;

		if ( ! dialog.open ) {
			window.location.reload();
		}
	}

	function send() {
		if ( confirm.disabled || confirm.hidden ) {
			return;
		}

		var asked = current;
		var extra = { unit: asked.unit };

		Array.prototype.forEach.call( fields.querySelectorAll( '[data-field]' ), function ( input ) {
			extra[ 'fields[' + input.getAttribute( 'data-field' ) + ']' ] = input.value;
		} );
		Array.prototype.forEach.call( fields.querySelectorAll( '[data-error]' ), function ( error ) {
			error.textContent = '';
		} );
		confirm.disabled = true;
		say( dialog.getAttribute( 'data-sending' ) );

		post( asked.runAction, extra ).then( function ( answer ) {
			if ( asked !== current ) {
				late( answer && answer.success ? answer.data : null );
				return;
			}

			if ( answer && answer.success ) {
				report( answer.data );
				return;
			}

			failure( answer );
			confirm.disabled = false;
		} ).catch( function () {
			if ( asked !== current ) {
				return;
			}

			say( dialog.getAttribute( 'data-failed' ) );
			confirm.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( dialog.getAttribute( 'data-button' ) ) : null;

		if ( button ) {
			event.preventDefault();
			open( button );
		}
	} );
	confirm.addEventListener( 'click', send );
	dialog.addEventListener( 'close', function () {
		if ( reload ) {
			window.location.reload();
		}
	} );
}() );
