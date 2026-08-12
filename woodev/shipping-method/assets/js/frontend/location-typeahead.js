/**
 * Woodev Location Typeahead — a neutral combobox widget for the location
 * provider layer (spec D6, plan Task 10).
 *
 * PROGRESSIVE ENHANCEMENT, THE WC WAY: the native `<input>` this module is
 * attached to is NEVER replaced or wrapped — {@see attachTypeahead} adds
 * `role="combobox"` plus the ARIA attributes a combobox needs, and inserts an
 * adjacent `<ul role="listbox">` sibling right after it. {@see detach}
 * removes every one of them, restoring whatever the input's own attributes
 * were BEFORE attach (not merely deleting them — a caller who had already
 * set e.g. `role` gets that value back, not a blank slate). This matters
 * concretely: per-country arbitration (Task 11) re-runs attach/detach on
 * every country change, so a leaky detach would accumulate stale ARIA state
 * across every switch a customer makes. See gotcha
 * `wc-address-autocomplete-hosts-only-address1-and-flattens-identity` for
 * why this shape — native input kept, combobox role + adjacent listbox,
 * cleanly removable — is copied from WooCommerce's OWN Address Autocomplete
 * widget rather than invented; our CSS class namespace (`woodev-location-*`)
 * and event wiring are our own.
 *
 * NO jQUERY IN THIS FILE, ON PURPOSE. This module dispatches real native
 * `input`/`change` events on selection (see {@see selectItem}) — jQuery
 * `.on()` DOES see a real native event (see gotcha
 * `jquery-trigger-change-fires-no-native-event`), so a jQuery-bound listener
 * elsewhere on the page (the cascade layer, Task 11) observes these
 * selections without this module ever touching `window.jQuery` itself. What
 * this module explicitly does NOT do is decide what a selection MEANS for
 * other fields (backwards fill, clearing descendants, persisting to the
 * server) — that domain logic, and any jQuery-world event producers it needs
 * to bridge (select2, WooCommerce's own `update_checkout`), belongs to
 * `location-cascade.js` (Task 11), which is the sole caller of
 * {@see attachTypeahead}.
 *
 * THE FETCH/ONSELECT CONTRACT: `fetch( query )` returns a `Promise` (or a
 * plain value — this module wraps it in `Promise.resolve()`) resolving to an
 * ARRAY of suggestion objects, each read for exactly two fields — `item.label`
 * (a string) for the rendered `<li>` text, and `item.value` (a string) for
 * what a selection WRITES INTO THE INPUT; every other property on the item is
 * opaque to this module and passed through to `onSelect( item )` UNTOUCHED. A
 * missing/non-string `label` renders as an empty string rather than
 * `undefined` — never throws; a missing/non-string `value` falls back to
 * `label`.
 *
 * The two are separate because they answer different questions and a provider
 * routinely disagrees about them: DaData labels a settlement suggestion
 * `'Московская обл., г Жуковский'` — exactly right for telling two Жуковских
 * apart in the list, and exactly wrong as the CONTENT of a "Населённый пункт"
 * field, which wants `'Жуковский'` and nothing else. This module stays neutral
 * about which is which: deriving the per-level value from the record's own
 * components is the caller's job (`location-cascade.js`'s `fieldValueFor()`),
 * because only the caller knows what LEVEL the field it enhanced represents.
 *
 * BUSY STATE: a search is not instantaneous, and between the keystroke and the
 * first rendered suggestion the widget would otherwise look inert — no chrome,
 * no list, nothing to say the field is working. So the module also inserts a
 * `<span class="woodev-location-spinner">` sibling (hidden at rest, same
 * lifecycle as the listbox: created on attach, removed on detach) and marks
 * the input `aria-busy` while a query is outstanding. "Outstanding" starts at
 * the moment an eligible query SCHEDULES its debounce, not when the request
 * leaves — otherwise the first 250ms of every search, the part that feels
 * slowest because nothing has happened yet, would still show nothing at all.
 *
 * STALE-RESPONSE DISCARD: every debounced fetch is tagged with a
 * monotonically increasing generation number; a response is applied only
 * when its generation still matches the CURRENT one at resolution time.
 * Bumped on every new fetch issued, and — via {@see closeListbox} — whenever
 * the listbox closes for any reason: the input dropping below `minChars`, a
 * completed selection (click or Enter), Escape, blur, an outside click, or
 * `detach()`. A selection dispatches its own synthetic `input` event (see
 * {@see selectItem}), which would otherwise schedule a fresh debounced fetch
 * for the label just picked; closing the listbox therefore always clears any
 * pending debounce timer too, not just the generation, so that fetch never
 * fires at all rather than firing and being discarded on arrival. Together
 * this guarantees an in-flight or about-to-be-scheduled request from before
 * any of those events can never paint a result the customer's current input
 * no longer describes — while a genuinely NEW `input` event afterwards (the
 * customer typing again) always schedules its own fresh generation via
 * {@see runFetch}, unaffected by this invalidation.
 *
 * XSS: every suggestion label reaches the DOM through `textContent`
 * ({@see renderItems}) — never `innerHTML` — so a label containing markup
 * renders as inert text.
 *
 * EMPTY RESULTS SAY SO. A completed search that returned nothing renders a
 * single non-selectable row carrying `options.emptyText`, rather than hiding
 * the listbox. This REVERSES the module's original behaviour, and the reason
 * the original was wrong is worth recording: it cited the operator's rule that
 * a blocked control is never explained — but that rule is about DISABLED
 * CONTROLS ("заблокирована и всё"), and an empty result set is not one. To the
 * customer, a panel that never opens is indistinguishable from a slow network
 * or a field that is simply broken; the one thing they cannot tell is the one
 * thing they need to know, which is that the search ran and found nothing
 * (operator, s70).
 *
 * The row is deliberately NOT an option: `role="presentation"`, its own class,
 * and — crucially — `items` stays `[]`, so ArrowDown/Enter cannot land on it
 * and a click on it selects nothing. It is a message inside the popup, not a
 * suggestion that happens to read like one.
 *
 * With no `emptyText` supplied the old behaviour stands (listbox hidden, no
 * chrome), so a caller that wants silence still gets it.
 *
 * UMD-ish dual export (matches pickup-datasource.js): the module IS the
 * factory function.
 *   - Browser global: window.WoodevLocationTypeahead = attachTypeahead
 *   - CommonJS:       module.exports = attachTypeahead  (for jest)
 *
 * @file
 * @since 2.1.0
 */

( function() {
	'use strict';

	/** @type {number} debounce interval, in ms, before an eligible query fires `fetch()`. */
	var DEBOUNCE_MS = 250;

	/** @type {number} default minimum input length before a search is issued. */
	var DEFAULT_MIN_CHARS = 2;

	/**
	 * ARIA/role attributes this module owns on the input for the lifetime of one
	 * attach — captured before attach and restored verbatim on detach. Does NOT
	 * include `aria-activedescendant`, which is purely dynamic (set/cleared as
	 * the active item changes) and never has a meaningful "before" value to
	 * restore — {@see detach} always just removes it.
	 *
	 * @type {string[]}
	 */
	var MANAGED_ATTRS = [ 'role', 'aria-autocomplete', 'aria-expanded', 'aria-controls', 'aria-haspopup' ];

	/** @type {number} module-scope counter for generating collision-free listbox/option ids. */
	var idCounter = 0;

	/**
	 * One live instance per attached input, so a second {@see attachTypeahead}
	 * call on the SAME input can cleanly detach the first before attaching
	 * fresh (see the file docblock — arbitration re-runs attach/detach per
	 * country, and a second attach must never leave the first instance's
	 * listeners/listbox alive alongside the new one).
	 *
	 * @type {WeakMap<HTMLElement, {detach: Function}>}
	 */
	var instances = new WeakMap();

	/**
	 * Snapshots the current value of each attribute in `names` on `el` — `null`
	 * for one that is not currently set, distinguishing "restore to this value"
	 * from "remove entirely" ({@see restoreAttrs}).
	 *
	 * @param {HTMLElement} el
	 * @param {string[]}    names
	 * @returns {Object.<string, string|null>}
	 */
	function captureAttrs( el, names ) {
		var snapshot = {};

		names.forEach( function( name ) {
			snapshot[ name ] = el.hasAttribute( name ) ? el.getAttribute( name ) : null;
		} );

		return snapshot;
	}

	/**
	 * Restores every attribute in `snapshot` to its captured value — removed
	 * entirely when the captured value is `null` (it was absent before attach),
	 * set back otherwise. Also unconditionally drops `aria-activedescendant`,
	 * which is never part of `snapshot` (see {@see MANAGED_ATTRS}) but is always
	 * this module's own dynamic addition.
	 *
	 * @param {HTMLElement}                     el
	 * @param {Object.<string, string|null>}    snapshot
	 * @returns {void}
	 */
	function restoreAttrs( el, snapshot ) {
		Object.keys( snapshot ).forEach( function( name ) {
			if ( null === snapshot[ name ] ) {
				el.removeAttribute( name );
			} else {
				el.setAttribute( name, snapshot[ name ] );
			}
		} );

		el.removeAttribute( 'aria-activedescendant' );
	}

	/**
	 * Whether `el` carries this module's own suggestion-item marker class.
	 *
	 * @param {EventTarget} el
	 * @returns {boolean}
	 */
	function isOptionElement( el ) {
		return !! ( el && el.classList && el.classList.contains( 'woodev-location-option' ) );
	}

	/**
	 * Attaches a neutral typeahead combobox to `input` — see the file docblock
	 * for the full contract. Attaching to an input that already has a live
	 * instance detaches that instance first, so this is always safe to call
	 * again without an explicit `detach()` in between.
	 *
	 * @param {HTMLInputElement} input
	 * @param {Object}           options
	 * @param {Function}         options.fetch    `function( query: string ): Promise<Array>`.
	 * @param {Function}         options.onSelect `function( item: Object ): void`, called with the
	 *                                             raw selected suggestion object.
	 * @param {number}           [options.minChars] Minimum input length before a search fires.
	 *                                               Defaults to {@see DEFAULT_MIN_CHARS}.
	 * @param {string}           [options.emptyText] Message shown in the listbox when a completed
	 *                                                search returned nothing. Omitted/blank keeps
	 *                                                the listbox hidden instead.
	 * @returns {{detach: function(): void}}
	 */
	function attachTypeahead( input, options ) {
		var opts = options || {};
		var emptyText = 'string' === typeof opts.emptyText ? opts.emptyText : '';
		var fetchFn = 'function' === typeof opts.fetch ? opts.fetch : function() {
			return Promise.resolve( [] );
		};
		var onSelectFn = 'function' === typeof opts.onSelect ? opts.onSelect : function() {};
		var minChars = 'number' === typeof opts.minChars && opts.minChars >= 0 ? opts.minChars : DEFAULT_MIN_CHARS;

		var existing = instances.get( input );

		if ( existing ) {
			existing.detach();
		}

		idCounter += 1;
		var listboxId = 'woodev-location-listbox-' + idCounter;

		var listbox = document.createElement( 'ul' );
		listbox.setAttribute( 'id', listboxId );
		listbox.setAttribute( 'role', 'listbox' );
		listbox.className = 'woodev-location-listbox';
		listbox.hidden = true;

		var spinner = document.createElement( 'span' );
		spinner.className = 'woodev-location-spinner';
		// Decoration for a state already announced to assistive tech through the
		// input's own `aria-busy` — so it is hidden from the accessibility tree
		// rather than announced twice, once meaningfully and once as an empty span.
		spinner.setAttribute( 'aria-hidden', 'true' );
		spinner.hidden = true;

		if ( input.parentNode ) {
			input.parentNode.insertBefore( listbox, input.nextSibling );
			input.parentNode.insertBefore( spinner, input.nextSibling );
		}

		var originalAttrs = captureAttrs( input, MANAGED_ATTRS );

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listboxId );
		input.setAttribute( 'aria-haspopup', 'listbox' );

		/** @type {Array} the suggestions currently rendered. */
		var items = [];

		/** @type {number} index of the currently highlighted item, or -1 for none. */
		var activeIndex = -1;

		/** @type {number|null} pending debounce timer id. */
		var debounceTimer = null;

		/**
		 * Monotonic generation counter — see the file docblock's STALE-RESPONSE
		 * DISCARD section. Bumped on every new fetch issued, on the query
		 * dropping below `minChars`, and on detach.
		 *
		 * @type {number}
		 */
		var generation = 0;

		/** @type {boolean} true once detach() has run — guards a second detach() call. */
		var detached = false;

		/**
		 * Shows or hides the busy indicator — see the file docblock's BUSY STATE
		 * section. Idempotent: called on every path that starts or ends a search,
		 * including several that may already be in the target state (a second
		 * keystroke while a fetch is in flight, {@see closeListbox} running when
		 * nothing was pending).
		 *
		 * @param {boolean} busy
		 * @returns {void}
		 */
		function setBusy( busy ) {
			spinner.hidden = ! busy;

			if ( busy ) {
				input.setAttribute( 'aria-busy', 'true' );
			} else {
				input.removeAttribute( 'aria-busy' );
			}
		}

		/**
		 * Hides the listbox, empties it, and clears the active item — the shared
		 * end-state for "nothing to show" (closed by the user, empty results, a
		 * discarded/rejected fetch).
		 *
		 * ALSO invalidates any pending work (PR-C review, Finding 3): clears a
		 * scheduled-but-not-yet-fired debounce timer and bumps `generation` so an
		 * already-in-flight fetch can never paint a result once this runs. This
		 * matters concretely for {@see selectItem}, which dispatches a synthetic
		 * native `input` event as part of writing the picked label into the input —
		 * that event re-enters {@see handleInput} and schedules a fresh debounced
		 * fetch for the label just picked, exactly like a real keystroke would.
		 * Without this, ~250ms after a selection (or after Escape/blur/an outside
		 * click with a request still in flight) the listbox would reopen showing
		 * results for what the customer already chose or moved away from. Bumping
		 * generation unconditionally is safe for the "customer types again
		 * afterwards" case too: the very next real `input` event schedules its own
		 * new debounce timer and {@see runFetch} mints a fresh generation for it
		 * regardless of what this method already bumped it to.
		 *
		 * @returns {void}
		 */
		function closeListbox() {
			items = [];
			activeIndex = -1;

			listbox.hidden = true;

			while ( listbox.firstChild ) {
				listbox.removeChild( listbox.firstChild );
			}

			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );

			if ( null !== debounceTimer ) {
				clearTimeout( debounceTimer );
				debounceTimer = null;
			}

			generation += 1;

			// Every close is also the end of any search that was still outstanding —
			// the debounce timer above is gone and the generation bump has just
			// orphaned any in-flight response, so nothing is left that could ever
			// paint. A spinner surviving that would spin forever.
			setBusy( false );
		}

		/**
		 * Renders a fresh suggestion set. An empty (or non-array) result set
		 * hides the listbox with NO placeholder content — see the file
		 * docblock's EMPTY RESULTS section.
		 *
		 * @param {Array} newItems
		 * @returns {void}
		 */
		function renderItems( newItems ) {
			items = Array.isArray( newItems ) ? newItems : [];
			activeIndex = -1;

			while ( listbox.firstChild ) {
				listbox.removeChild( listbox.firstChild );
			}

			if ( 0 === items.length ) {
				input.removeAttribute( 'aria-activedescendant' );

				if ( '' === emptyText ) {
					listbox.hidden = true;
					input.setAttribute( 'aria-expanded', 'false' );

					return;
				}

				var empty = document.createElement( 'li' );

				// NOT `role="option"`, and `items` stays empty — see the file docblock: this
				// is a message inside the popup, and nothing about it may become selectable.
				empty.setAttribute( 'role', 'presentation' );
				empty.setAttribute( 'aria-live', 'polite' );
				empty.className = 'woodev-location-empty';
				empty.textContent = emptyText;

				listbox.appendChild( empty );
				listbox.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );

				return;
			}

			items.forEach( function( item, index ) {
				var li = document.createElement( 'li' );

				li.setAttribute( 'id', listboxId + '-option-' + index );
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				li.className = 'woodev-location-option';
				// textContent, NEVER innerHTML — a suggestion label is untrusted data
				// (provider-sourced, possibly reflecting raw user input back).
				li.textContent = item && 'string' === typeof item.label ? item.label : '';

				listbox.appendChild( li );
			} );

			listbox.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		/**
		 * Moves the highlighted item to `index`, clamped to the valid range, and
		 * reflects it via `aria-selected` on the options plus
		 * `aria-activedescendant` on the input.
		 *
		 * @param {number} index
		 * @returns {void}
		 */
		function setActiveIndex( index ) {
			var children = listbox.children;
			var i;

			for ( i = 0; i < children.length; i++ ) {
				children[ i ].setAttribute( 'aria-selected', 'false' );
			}

			activeIndex = Math.max( -1, Math.min( index, children.length - 1 ) );

			if ( activeIndex >= 0 ) {
				children[ activeIndex ].setAttribute( 'aria-selected', 'true' );
				input.setAttribute( 'aria-activedescendant', children[ activeIndex ].id );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		/**
		 * Applies a selection: writes the item's `value` (falling back to its
		 * `label` — see the file docblock's FETCH/ONSELECT CONTRACT for why these
		 * are two different strings) into the input as its new value, then
		 * fires real native `input`/`change` events (seen by both the
		 * native and jQuery event worlds — see the file docblock), closes the
		 * listbox, then hands the raw item to `onSelect()`. Order matters: the
		 * DOM is already consistent (value + closed listbox) by the time
		 * `onSelect()` — which may itself trigger further, possibly synchronous,
		 * DOM work in the cascade layer — runs.
		 *
		 * @param {Object} item
		 * @returns {void}
		 */
		function selectItem( item ) {
			var label = item && 'string' === typeof item.label ? item.label : '';
			var value = item && 'string' === typeof item.value ? item.value : label;

			input.value = value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			closeListbox();

			onSelectFn( item );
		}

		/**
		 * Issues one fetch for `query`, tagged with a fresh generation. Applies
		 * the result (success or failure) only if that generation is still
		 * current when it settles — see the file docblock.
		 *
		 * @param {string} query
		 * @returns {void}
		 */
		function runFetch( query ) {
			generation += 1;
			var myGeneration = generation;
			var result;

			try {
				result = fetchFn( query );
			} catch ( syncError ) {
				// A synchronous throw is treated exactly like a rejected fetch: nothing
				// to show — and nothing still outstanding, so the search is over.
				setBusy( false );

				return;
			}

			Promise.resolve( result ).then(
				function( results ) {
					// A response whose generation is stale must not clear the busy state
					// either: a NEWER search owns it now, and this one losing the race is
					// not that newer one finishing.
					if ( detached || myGeneration !== generation ) {
						return;
					}

					setBusy( false );
					renderItems( results );
				},
				function() {
					if ( detached || myGeneration !== generation ) {
						return;
					}

					// `closeListbox()` clears the busy state itself.
					closeListbox();
				}
			);
		}

		/**
		 * `input` event handler: (re)schedules the debounced fetch for a query
		 * at/above `minChars`, or immediately invalidates any in-flight fetch and
		 * closes the listbox when the query drops below it — see the file
		 * docblock's STALE-RESPONSE DISCARD section for why this path invalidates
		 * generation even though it issues no new fetch of its own ({@see
		 * closeListbox} is what actually bumps it).
		 *
		 * @returns {void}
		 */
		function handleInput() {
			var query = input.value;

			if ( null !== debounceTimer ) {
				clearTimeout( debounceTimer );
				debounceTimer = null;
			}

			if ( query.length < minChars ) {
				closeListbox();

				return;
			}

			// Busy from the moment the search is SCHEDULED, not when it is issued —
			// see the file docblock's BUSY STATE section.
			setBusy( true );

			debounceTimer = setTimeout( function() {
				debounceTimer = null;
				runFetch( query );
			}, DEBOUNCE_MS );
		}

		/**
		 * `keydown` handler: ArrowDown/ArrowUp move the active item (clamped, no
		 * wraparound), Enter selects the active item (a no-op with none active),
		 * Escape closes. Every key this handler acts on is `preventDefault()`ed;
		 * anything else passes through untouched.
		 *
		 * @param {KeyboardEvent} event
		 * @returns {void}
		 */
		function handleKeydown( event ) {
			if ( listbox.hidden && 'ArrowDown' !== event.key ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				if ( listbox.hidden || 0 === items.length ) {
					return;
				}

				event.preventDefault();
				setActiveIndex( activeIndex + 1 );

				return;
			}

			if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				setActiveIndex( activeIndex - 1 );

				return;
			}

			if ( 'Enter' === event.key ) {
				if ( activeIndex < 0 || activeIndex >= items.length ) {
					return;
				}

				event.preventDefault();
				selectItem( items[ activeIndex ] );

				return;
			}

			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeListbox();
			}
		}

		/**
		 * `mousedown` delegate on the listbox itself: selects the clicked option.
		 * `preventDefault()` stops the browser's default mousedown behaviour
		 * (which would blur the input), and `stopPropagation()` keeps this click
		 * from reaching {@see handleDocumentMousedown}, which would otherwise
		 * treat it as an outside click and close the listbox before the
		 * selection below ever runs.
		 *
		 * @param {MouseEvent} event
		 * @returns {void}
		 */
		function handleListboxMousedown( event ) {
			event.preventDefault();
			event.stopPropagation();

			var target = event.target;

			while ( target && target !== listbox && ! isOptionElement( target ) ) {
				target = target.parentNode;
			}

			if ( ! target || target === listbox ) {
				return;
			}

			var index = Array.prototype.indexOf.call( listbox.children, target );

			if ( index >= 0 && index < items.length ) {
				selectItem( items[ index ] );
			}
		}

		/**
		 * `mousedown` handler on `document`: a click landing outside the input
		 * AND outside the listbox closes it. A click inside the listbox never
		 * reaches this handler — {@see handleListboxMousedown} stops its
		 * propagation.
		 *
		 * @param {MouseEvent} event
		 * @returns {void}
		 */
		function handleDocumentMousedown( event ) {
			if ( input === event.target || input.contains( event.target ) ) {
				return;
			}

			closeListbox();
		}

		/**
		 * `blur` handler: the listbox is only ever relevant while the input is
		 * focused (e.g. Tab away without any mouse click at all, which the
		 * document mousedown listener would never see).
		 *
		 * @returns {void}
		 */
		function handleBlur() {
			closeListbox();
		}

		input.addEventListener( 'input', handleInput );
		input.addEventListener( 'keydown', handleKeydown );
		input.addEventListener( 'blur', handleBlur );
		listbox.addEventListener( 'mousedown', handleListboxMousedown );
		document.addEventListener( 'mousedown', handleDocumentMousedown );

		/**
		 * Tears the whole instance down: removes every listener this attach
		 * added, invalidates any still in-flight fetch permanently, removes both
		 * elements it inserted (listbox and spinner) from the DOM, and restores
		 * the input's original attributes.
		 * Safe to call more than once — every call after the first is a no-op.
		 *
		 * @returns {void}
		 */
		function detach() {
			if ( detached ) {
				return;
			}

			detached = true;
			generation += 1;

			if ( null !== debounceTimer ) {
				clearTimeout( debounceTimer );
				debounceTimer = null;
			}

			input.removeEventListener( 'input', handleInput );
			input.removeEventListener( 'keydown', handleKeydown );
			input.removeEventListener( 'blur', handleBlur );
			listbox.removeEventListener( 'mousedown', handleListboxMousedown );
			document.removeEventListener( 'mousedown', handleDocumentMousedown );

			setBusy( false );

			if ( listbox.parentNode ) {
				listbox.parentNode.removeChild( listbox );
			}

			if ( spinner.parentNode ) {
				spinner.parentNode.removeChild( spinner );
			}

			restoreAttrs( input, originalAttrs );

			instances.delete( input );
		}

		var api = { detach: detach };

		instances.set( input, api );

		return api;
	}

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevLocationTypeahead = attachTypeahead;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = attachTypeahead;
	}

}() );
