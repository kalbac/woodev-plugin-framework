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
	var SCROLL_LOCK_CLASS = 'woodev-modal-lock';

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
		var titleId = 'woodev-modal-title-' + idSeq;

		// `woodev-modal` is the shell's ROOT marker — the ancestor every descendant's
		// box-sizing reset and any consumer-side `.woodev-modal` query hang off — while
		// `woodev-modal-backdrop` styles this SAME node as the fixed, full-screen overlay.
		// One element, two roles, both real classes on it (see woodev-modal.css, D-13).
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'woodev-modal woodev-modal-backdrop';

		var dialog = document.createElement( 'div' );
		dialog.className = 'woodev-modal__content';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', titleId );

		var header = document.createElement( 'div' );
		header.className = 'woodev-modal__header';

		var title = document.createElement( 'h2' );
		title.id = titleId;
		title.className = 'woodev-modal__title';
		title.textContent = self._title;

		var closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'woodev-modal__close';
		closeButton.setAttribute( 'aria-label', self._closeLabel );
		closeButton.textContent = '×'; // '×' — decorative, aria-label carries the meaning.

		var body = document.createElement( 'div' );
		body.className = 'woodev-modal__body';

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
			self.close( 'button' );
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
				self.close( 'escape' );
				return;
			}
			if ( key === 'Tab' || 9 === event.keyCode ) {
				trapTab( self, event );
			}
		};
		document.addEventListener( 'keydown', self._onKeydown, true );

		self._onBackdropClick = function( event ) {
			if ( event.target === self._backdrop ) {
				self.close( 'backdrop' );
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
		p.className = 'woodev-modal__message ' + modifierClass;
		p.textContent = message;
		self._body.appendChild( p );

		return p;
	}

	/**
	 * Detach the dialog from <body>, unlock scroll, unbind the open-only
	 * listeners, and return focus to `returnFocusTo`. The DOM-teardown half of
	 * `close()` — split out so `destroy()` can run the same unconditional
	 * cleanup without going through the cancelable `before_close` gate (a
	 * forced disposal must not be vetoable by a consumer's listener).
	 *
	 * @param {WoodevModal} self
	 * @returns {void}
	 */
	function teardownDialog( self ) {
		unbindOpenListeners( self );

		if ( self._backdrop.parentNode ) {
			self._backdrop.parentNode.removeChild( self._backdrop );
		}
		document.body.classList.remove( SCROLL_LOCK_CLASS );
		self._isOpen = false;

		if ( self._returnFocusTo && typeof self._returnFocusTo.focus === 'function' ) {
			self._returnFocusTo.focus();
		}
	}

	/**
	 * Dispatches a framework modal event on `document.body`.
	 *
	 * A native CustomEvent with `bubbles: true` is seen by BOTH `addEventListener` and jQuery's
	 * `.on()`. The reverse does not hold: `jQuery.trigger()` on a custom type creates no native
	 * event, so a jQuery-dispatched event would be invisible to `addEventListener`. See
	 * `pickup-mount.js`'s docblock on `updated_checkout` for the same asymmetry.
	 *
	 * @param {string}  type       event name.
	 * @param {Object}  detail     event payload.
	 * @param {boolean} cancelable whether `preventDefault()` is honoured by the caller.
	 * @returns {boolean} false when a listener cancelled a cancelable event.
	 */
	function emit( type, detail, cancelable ) {
		var event = new CustomEvent( type, {
			detail: detail,
			bubbles: true,
			cancelable: !! cancelable,
		} );

		return document.body.dispatchEvent( event );
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
	 * @property {string}      [modalId]       Public id carried on every emitted event's
	 *                                         `detail.modalId` (D-14) so listeners can filter
	 *                                         by instance — WooCommerce's `target` argument.
	 * @property {string}      [title]         Dialog title (rendered as text, never markup).
	 * @property {string}      [closeLabel]    Accessible label for the close button.
	 * @property {string}      [retryLabel]    Label for the retry control in showError().
	 * @property {HTMLElement} [returnFocusTo] Element to refocus when the modal closes.
	 * @property {Object}      [context]       Arbitrary payload forwarded verbatim on
	 *                                         `woodev_modal_opened`'s `detail.context` (D-14).
	 *                                         Defaults to `{}` so the payload shape is stable
	 *                                         even when a caller omits it.
	 */

	/**
	 * @param {WoodevModalOptions} [options]
	 * @constructor
	 */
	function WoodevModal( options ) {
		var opts = options || {};

		this._modalId = opts.modalId || '';
		this._title = opts.title || '';
		this._closeLabel = opts.closeLabel || 'Закрыть';
		this._retryLabel = opts.retryLabel || 'Повторить';
		this._returnFocusTo = opts.returnFocusTo || null;
		this._context = opts.context || {};

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
	 * Fires `woodev_modal_opened` last, after the DOM is in place and focus is
	 * trapped, so a listener can safely query the rendered tree (D-14).
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

		emit( 'woodev_modal_opened', { modalId: this._modalId, context: this._context } );
	};

	/**
	 * Close the dialog: detach it from <body>, unlock scroll, unbind the
	 * open-only listeners, and return focus to `returnFocusTo`. A harmless
	 * no-op when the modal is not currently open (never opened, already
	 * closed, or destroyed).
	 *
	 * Fires the cancelable `woodev_modal_before_close` event first (D-14) — a
	 * listener that calls `preventDefault()` aborts the close before any
	 * teardown happens: nothing is detached, no focus is released, no scroll
	 * lock is removed. Only when the event is not cancelled does teardown run
	 * and `woodev_modal_closed` fire, both carrying `{ modalId, reason }`.
	 *
	 * Every internal close path (Esc, backdrop click, header close button)
	 * calls this method with its own reason; there is no second teardown path.
	 *
	 * @param {string} [reason] Why the dialog is closing — 'escape' | 'backdrop' |
	 *                          'button' | 'select' (T18) | any caller-supplied value.
	 *                          Defaults to 'button'.
	 * @returns {boolean} true once the dialog is closed (or already was); false
	 *                     when a `before_close` listener vetoed the close.
	 */
	WoodevModal.prototype.close = function( reason ) {
		if ( this._isDestroyed || ! this._isOpen ) {
			return false;
		}

		var payload = { modalId: this._modalId, reason: reason || 'button' };

		if ( ! emit( 'woodev_modal_before_close', payload, true ) ) {
			return false;
		}

		teardownDialog( this );
		emit( 'woodev_modal_closed', payload );

		return true;
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

		renderMessage( this, message, 'woodev-modal__message--error' );

		if ( typeof onRetry === 'function' ) {
			var retryButton = document.createElement( 'button' );
			retryButton.type = 'button';
			retryButton.className = 'woodev-modal__retry';
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

		renderMessage( this, message, 'woodev-modal__message--empty' );
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
		notice.className = 'woodev-modal__notice';
		notice.setAttribute( 'role', 'alert' );

		var text = document.createElement( 'span' );
		text.className = 'woodev-modal__notice-message';
		text.textContent = message;
		notice.appendChild( text );

		if ( typeof onRetry === 'function' ) {
			var retryButton = document.createElement( 'button' );
			retryButton.type = 'button';
			retryButton.className = 'woodev-modal__notice-retry';
			retryButton.textContent = this._retryLabel;
			retryButton.addEventListener( 'click', onRetry );
			notice.appendChild( retryButton );
		}

		var self = this;
		var dismissButton = document.createElement( 'button' );
		dismissButton.type = 'button';
		dismissButton.className = 'woodev-modal__notice-dismiss';
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
	 * Deliberately bypasses `close()`'s cancelable `before_close` gate and
	 * calls the same `teardownDialog()` helper directly: destroy() is a
	 * forced, permanent disposal, not one of the D-14 dismissal reasons, and
	 * a consumer's `before_close` listener must not be able to veto it.
	 *
	 * @returns {void}
	 */
	WoodevModal.prototype.destroy = function() {
		if ( this._isDestroyed ) {
			return;
		}

		if ( this._isOpen ) {
			teardownDialog( this );
		}

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
