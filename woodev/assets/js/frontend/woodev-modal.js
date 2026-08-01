/**
 * Woodev Modal — vanilla generic dialog shell.
 *
 * Plain constructor, ES5-safe, no jQuery, no Backbone, no build step — this
 * file is enqueued directly (see `woodev-modal` in class-pickup-handler.php,
 * its first consumer), never bundled.
 *
 * Why vanilla instead of `wc-backbone-modal`:
 *   - A WC blocks checkout adapter needs the same dialog inside WooCommerce's
 *     React checkout, where `wc-backbone-modal` is not available. One shell
 *     serves both surfaces.
 *   - It puts no Backbone/underscore dependency on the storefront.
 *   - It survives stores where `wc-backbone-modal` (or one of its
 *     dependencies) is not loaded — a case hit in production before.
 *
 * The shell owns the dialog chrome only: aria contract, focus trap/return,
 * scroll lock, backdrop/Escape dismissal, and the error/empty degradation
 * states. Everything *inside* `getContainer()` belongs to whatever consumer
 * mounts into it — `destroy()` tears the whole subtree down so a consumer
 * never has to worry about stale nodes on reopen.
 *
 * UMD-ish dual export (matches checkout-field-store.js):
 *   - Browser global: window.WoodevModal = WoodevModal
 *   - CommonJS:       module.exports = WoodevModal  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/** @type {number} module-level counter — guarantees unique ids across instances. */
	var idSeq = 0;

	/** @type {string} class toggled on <body> while any instance is open. */
	var SCROLL_LOCK_CLASS = 'woodev-pickup-modal-lock';

	/** @type {string} selector for elements the focus trap considers tabbable. */
	var FOCUSABLE_SELECTOR = [
		'a[href]',
		'button:not([disabled])',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])',
	].join( ', ' );

	/**
	 * Build the (initially detached) dialog DOM once, at construction time.
	 *
	 * The same nodes are reused for the lifetime of the instance — open()/
	 * close() only attach/detach the backdrop from <body>, they never
	 * recreate it. That keeps `getContainer()`'s return value stable across
	 * an open/close cycle, which is the contract the map provider relies on.
	 *
	 * @param {WoodevModal} self
	 * @returns {void}
	 */
	function buildDom( self ) {
		idSeq += 1;
		var titleId = 'woodev-pickup-modal-title-' + idSeq;

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'woodev-pickup-modal-backdrop';

		var dialog = document.createElement( 'div' );
		dialog.className = 'woodev-pickup-modal';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', titleId );

		var header = document.createElement( 'div' );
		header.className = 'woodev-pickup-modal__header';

		var title = document.createElement( 'h2' );
		title.id = titleId;
		title.className = 'woodev-pickup-modal__title';
		title.textContent = self._title;

		var closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'woodev-pickup-modal__close';
		closeButton.setAttribute( 'aria-label', self._closeLabel );
		closeButton.textContent = '×'; // '×' — decorative, aria-label carries the meaning.

		var body = document.createElement( 'div' );
		body.className = 'woodev-pickup-modal__body';

		header.appendChild( title );
		header.appendChild( closeButton );
		dialog.appendChild( header );
		dialog.appendChild( body );
		backdrop.appendChild( dialog );

		self._backdrop = backdrop;
		self._dialog = dialog;
		self._titleEl = title;
		self._closeButton = closeButton;
		self._body = body;

		self._onCloseClick = function() {
			self.close();
		};
		closeButton.addEventListener( 'click', self._onCloseClick );
	}

	/**
	 * Return the currently tabbable elements inside the dialog, in DOM order.
	 *
	 * @param {WoodevModal} self
	 * @returns {HTMLElement[]}
	 */
	function focusableElements( self ) {
		return Array.prototype.slice.call( self._dialog.querySelectorAll( FOCUSABLE_SELECTOR ) );
	}

	/**
	 * Keep Tab from ever leaving the dialog. Forward from the last focusable
	 * element goes to the first; Shift+Tab from the first goes to the last.
	 *
	 * @param {WoodevModal} self
	 * @param {KeyboardEvent} event
	 * @returns {void}
	 */
	function trapTab( self, event ) {
		var focusable = focusableElements( self );
		if ( focusable.length === 0 ) {
			event.preventDefault();
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey ) {
			if ( document.activeElement === first || focusable.indexOf( document.activeElement ) === -1 ) {
				event.preventDefault();
				last.focus();
			}
		} else if ( document.activeElement === last || focusable.indexOf( document.activeElement ) === -1 ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Attach the listeners that only make sense while the dialog is open:
	 * Escape/Tab handling on `document`, and backdrop-click dismissal.
	 *
	 * @param {WoodevModal} self
	 * @returns {void}
	 */
	function bindOpenListeners( self ) {
		self._onKeydown = function( event ) {
			var key = event.key || '';
			if ( key === 'Escape' || key === 'Esc' || 27 === event.keyCode ) {
				self.close();
				return;
			}
			if ( key === 'Tab' || 9 === event.keyCode ) {
				trapTab( self, event );
			}
		};
		document.addEventListener( 'keydown', self._onKeydown, true );

		self._onBackdropClick = function( event ) {
			if ( event.target === self._backdrop ) {
				self.close();
			}
		};
		self._backdrop.addEventListener( 'click', self._onBackdropClick );
	}

	/**
	 * Remove the listeners bound in {@see bindOpenListeners}.
	 *
	 * @param {WoodevModal} self
	 * @returns {void}
	 */
	function unbindOpenListeners( self ) {
		if ( self._onKeydown ) {
			document.removeEventListener( 'keydown', self._onKeydown, true );
			self._onKeydown = null;
		}
		if ( self._onBackdropClick ) {
			self._backdrop.removeEventListener( 'click', self._onBackdropClick );
			self._onBackdropClick = null;
		}
	}

	/**
	 * Clear the modal body and render a single message node into it — the
	 * shared plumbing behind showError()/showEmpty(). Always REPLACES the
	 * body's content (never appends alongside whatever was there before),
	 * because a stale, half-drawn provider state next to an error message
	 * is exactly the confusing half-state this shell exists to avoid.
	 *
	 * @param {WoodevModal} self
	 * @param {string} message
	 * @param {string} modifierClass BEM modifier, e.g. '--error'.
	 * @returns {HTMLElement} the created message paragraph, for callers that
	 *                        want to append a retry control after it.
	 */
	function renderMessage( self, message, modifierClass ) {
		self._body.innerHTML = ''; // safe: clears children only, no untrusted markup is inserted.

		var p = document.createElement( 'p' );
		p.className = 'woodev-pickup-modal__message ' + modifierClass;
		p.textContent = message;
		self._body.appendChild( p );

		return p;
	}

	/**
	 * Removes the current dismissible notice (see {@see WoodevModal#showNotice}),
	 * if one is showing. A harmless no-op otherwise — used both by the notice's own
	 * dismiss button and by showNotice() itself so a second call never stacks a
	 * second banner alongside the first.
	 *
	 * @param {WoodevModal} self
	 * @returns {void}
	 */
	function dismissNotice( self ) {
		if ( self._notice && self._notice.parentNode ) {
			self._notice.parentNode.removeChild( self._notice );
		}

		self._notice = null;
	}

	/**
	 * @typedef {Object} WoodevModalOptions
	 * @property {string}      [title]         Dialog title (rendered as text, never markup).
	 * @property {string}      [closeLabel]    Accessible label for the close button.
	 * @property {string}      [retryLabel]    Label for the retry control in showError().
	 * @property {HTMLElement} [returnFocusTo] Element to refocus when the modal closes.
	 */

	/**
	 * @param {WoodevModalOptions} [options]
	 * @constructor
	 */
	function WoodevModal( options ) {
		var opts = options || {};

		this._title = opts.title || '';
		this._closeLabel = opts.closeLabel || 'Закрыть';
		this._retryLabel = opts.retryLabel || 'Повторить';
		this._returnFocusTo = opts.returnFocusTo || null;

		this._isOpen = false;
		this._isDestroyed = false;
		this._notice = null;

		buildDom( this );
	}

	/**
	 * Open the dialog: attach it to <body>, lock scroll, bind the open-only
	 * listeners, and move focus to the close button. Idempotent — calling
	 * open() while already open is a no-op, so it can never produce two
	 * dialogs in the DOM.
	 *
	 * @returns {void}
	 */
	WoodevModal.prototype.open = function() {
		if ( this._isDestroyed || this._isOpen ) {
			return;
		}

		document.body.appendChild( this._backdrop );
		document.body.classList.add( SCROLL_LOCK_CLASS );
		bindOpenListeners( this );
		this._isOpen = true;

		this._closeButton.focus();
	};

	/**
	 * Close the dialog: detach it from <body>, unlock scroll, unbind the
	 * open-only listeners, and return focus to `returnFocusTo`. A harmless
	 * no-op when the modal is not currently open (never opened, already
	 * closed, or destroyed).
	 *
	 * @returns {void}
	 */
	WoodevModal.prototype.close = function() {
		if ( this._isDestroyed || ! this._isOpen ) {
			return;
		}

		unbindOpenListeners( this );

		if ( this._backdrop.parentNode ) {
			this._backdrop.parentNode.removeChild( this._backdrop );
		}
		document.body.classList.remove( SCROLL_LOCK_CLASS );
		this._isOpen = false;

		if ( this._returnFocusTo && typeof this._returnFocusTo.focus === 'function' ) {
			this._returnFocusTo.focus();
		}
	};

	/**
	 * The element the map provider mounts into. Stable across the instance's
	 * whole lifecycle (same node before the first open(), while open, and
	 * after close()) — the provider can hold this reference and rely on it
	 * never being swapped out from under it. Returns null once destroyed.
	 *
	 * @returns {HTMLElement|null}
	 */
	WoodevModal.prototype.getContainer = function() {
		return this._isDestroyed ? null : this._body;
	};

	/**
	 * Replace the body with an error message and, when `onRetry` is given, a
	 * retry control that invokes it. Used for: the map script failing to
	 * load or the key being rejected, a points fetch failing without an
	 * already-drawn set to fall back to, and a details fetch failing with
	 * nothing else to show.
	 *
	 * @param {string}   message
	 * @param {Function} [onRetry]
	 * @returns {void}
	 */
	WoodevModal.prototype.showError = function( message, onRetry ) {
		if ( this._isDestroyed ) {
			return;
		}

		renderMessage( this, message, 'woodev-pickup-modal__message--error' );

		if ( typeof onRetry === 'function' ) {
			var retryButton = document.createElement( 'button' );
			retryButton.type = 'button';
			retryButton.className = 'woodev-pickup-modal__retry';
			retryButton.textContent = this._retryLabel;
			retryButton.addEventListener( 'click', onRetry );
			this._body.appendChild( retryButton );
		}
	};

	/**
	 * Replace the body with an explicit "nothing here" message — used for
	 * zero points in the locality/bbox. Deliberately has no retry control:
	 * there is nothing to retry, only a different search to try.
	 *
	 * @param {string} message
	 * @returns {void}
	 */
	WoodevModal.prototype.showEmpty = function( message ) {
		if ( this._isDestroyed ) {
			return;
		}

		renderMessage( this, message, 'woodev-pickup-modal__message--empty' );
	};

	/**
	 * Shows a dismissible banner ALONGSIDE the body — a sibling of
	 * `getContainer()`'s node, never inside it — so whatever a map provider has
	 * already drawn into its container (a live map, a viewport's worth of
	 * placemarks) is left completely untouched. This is the NON-destructive
	 * counterpart to showError()/showEmpty(): those two exist for when there is
	 * nothing worth preserving yet; this one exists for when there is — a
	 * customer who has already panned to a drawn map and hits a transient
	 * failure, or pans into an empty patch, keeps their map and gets a banner,
	 * never a wiped-blank body.
	 *
	 * Only ever one notice at a time: a second call replaces the first rather
	 * than stacking banners.
	 *
	 * @param {string}   message
	 * @param {Function} [onRetry] when given, renders a retry control that
	 *                             invokes it; omitted for a state with nothing
	 *                             to retry (e.g. a genuinely empty viewport).
	 * @returns {void}
	 */
	WoodevModal.prototype.showNotice = function( message, onRetry ) {
		if ( this._isDestroyed ) {
			return;
		}

		dismissNotice( this );

		var notice = document.createElement( 'div' );
		notice.className = 'woodev-pickup-modal__notice';
		notice.setAttribute( 'role', 'alert' );

		var text = document.createElement( 'span' );
		text.className = 'woodev-pickup-modal__notice-message';
		text.textContent = message;
		notice.appendChild( text );

		if ( typeof onRetry === 'function' ) {
			var retryButton = document.createElement( 'button' );
			retryButton.type = 'button';
			retryButton.className = 'woodev-pickup-modal__notice-retry';
			retryButton.textContent = this._retryLabel;
			retryButton.addEventListener( 'click', onRetry );
			notice.appendChild( retryButton );
		}

		var self = this;
		var dismissButton = document.createElement( 'button' );
		dismissButton.type = 'button';
		dismissButton.className = 'woodev-pickup-modal__notice-dismiss';
		dismissButton.setAttribute( 'aria-label', this._closeLabel );
		dismissButton.textContent = '×'; // '×' — decorative, aria-label carries the meaning.
		dismissButton.addEventListener( 'click', function() {
			dismissNotice( self );
		} );
		notice.appendChild( dismissButton );

		this._notice = notice;
		this._dialog.insertBefore( notice, this._body );
	};

	/**
	 * Tear the instance down completely: close it (if open), remove the
	 * close-button listener, and drop every internal reference. After
	 * destroy(), every other method is a no-op — reopening a destroyed
	 * instance is not supported; callers construct a new one.
	 *
	 * @returns {void}
	 */
	WoodevModal.prototype.destroy = function() {
		if ( this._isDestroyed ) {
			return;
		}

		this.close();

		if ( this._onCloseClick ) {
			this._closeButton.removeEventListener( 'click', this._onCloseClick );
			this._onCloseClick = null;
		}

		this._isDestroyed = true;
		this._backdrop = null;
		this._dialog = null;
		this._titleEl = null;
		this._closeButton = null;
		this._body = null;
		this._notice = null;
		this._returnFocusTo = null;
	};

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevModal = WoodevModal;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevModal;
	}

}() );
