/**
 * The shared dialog of the plain-PHP marketplace screens (the product tab, the order metabox): a native `<dialog>` drawn with
 * `css/admin/woodev-dialog.css`, the same file the React screens' `WoodevDialog` component uses, so every dialog of the
 * marketplace admin looks the same.
 *
 * The native element brings the backdrop, the focus trap and Esc; this file adds what is the same in every dialog — the header
 * with the title and «✕», the body, an optional footer, a click on the backdrop closes it.
 *
 * Two ways in:
 *   - `WoodevDialog.build( { title, closeLabel, footer } )` builds the dialog and returns its handle; fill `handle.body`
 *     (and `handle.footer`, when asked for), call `handle.open()`;
 *   - `WoodevDialog.attach( dialogElement )` takes a `<dialog class="woodev-dialog">` that PHP printed with the same parts and
 *     wires it; any `[data-woodev-dialog-close]` inside closes it.
 *
 * Handle: `{ element, title, body, footer, open(), close() }` (`footer` is null when not asked for).
 *
 * The «✕» is an empty button: the stylesheet draws it. Plain DOM calls only (`textContent`, never `innerHTML`). ES5, no jQuery.
 *
 * @since 2.1.13
 */
( function ( root ) {
	'use strict';

	var sequence = 0;

	/**
	 * Creates an element with a class.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className Class.
	 * @return {HTMLElement} The element.
	 */
	function make( tag, className ) {
		var element = document.createElement( tag );

		element.className = className;

		return element;
	}

	/**
	 * Opens a dialog (`showModal()` where the browser has it).
	 *
	 * @param {HTMLDialogElement} element The dialog.
	 */
	function open( element ) {
		if ( element.open ) {
			return;
		}

		if ( typeof element.showModal === 'function' ) {
			element.showModal();
		} else {
			element.setAttribute( 'open', '' );
		}
	}

	/**
	 * Closes a dialog.
	 *
	 * @param {HTMLDialogElement} element The dialog.
	 */
	function close( element ) {
		if ( typeof element.close === 'function' ) {
			element.close();
		} else {
			element.removeAttribute( 'open' );
		}
	}

	/**
	 * Wires a dialog that has the shared parts: its close buttons and the backdrop.
	 *
	 * @param {HTMLDialogElement} element The `<dialog class="woodev-dialog">`.
	 * @return {Object} The handle.
	 */
	function attach( element ) {
		element.addEventListener(
			'click',
			function ( event ) {
				// A click on the backdrop lands on the dialog element itself, not on its content.
				if ( event.target === element || ( event.target.closest && event.target.closest( '[data-woodev-dialog-close]' ) ) ) {
					close( element );
				}
			}
		);

		return {
			element: element,
			title: element.querySelector( '.woodev-dialog__title' ),
			body: element.querySelector( '.woodev-dialog__body' ),
			footer: element.querySelector( '.woodev-dialog__footer' ),
			open: function () {
				open( element );
			},
			close: function () {
				close( element );
			}
		};
	}

	/**
	 * Builds a dialog and appends it to the page.
	 *
	 * @param {{title?: string, closeLabel?: string, footer?: boolean}} options `closeLabel` names the «✕» for screen readers;
	 *                                                                           `footer` adds an empty footer to fill.
	 * @return {Object} The handle.
	 */
	function build( options ) {
		var settings = options || {};
		var element  = make( 'dialog', 'woodev-dialog' );
		var header   = make( 'div', 'woodev-dialog__header' );
		var title    = make( 'h2', 'woodev-dialog__title' );
		var closer   = make( 'button', 'woodev-dialog__close' );

		sequence += 1;
		title.id          = 'woodev-dialog-title-' + sequence;
		title.textContent = settings.title || '';
		closer.type       = 'button';
		closer.setAttribute( 'aria-label', settings.closeLabel || 'OK' );
		closer.setAttribute( 'data-woodev-dialog-close', '' );
		element.setAttribute( 'aria-labelledby', title.id );

		header.appendChild( title );
		header.appendChild( closer );
		element.appendChild( header );
		element.appendChild( make( 'div', 'woodev-dialog__body' ) );

		if ( settings.footer ) {
			element.appendChild( make( 'div', 'woodev-dialog__footer' ) );
		}

		document.body.appendChild( element );

		return attach( element );
	}

	root.WoodevDialog = { build: build, attach: attach };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = root.WoodevDialog;
	}
}( window ) );
