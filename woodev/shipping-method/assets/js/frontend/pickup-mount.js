/**
 * Woodev Pickup Mount — mounts the pickup-point picker into the §8 checkout
 * field-layer anchor.
 *
 * Plain bootstrap script, ES5-safe, no build step — enqueued directly (see
 * `woodev-pickup-mount` in class-pickup-handler.php) as the final assembly
 * point of SP-5: it places a trigger button inside the anchor §8 deliberately
 * leaves empty (`[data-woodev-pickup-slot="<fieldId>"]`), and on click opens
 * a picker session. This file is the CLASSIC checkout (`[woocommerce_checkout]`)
 * mount.
 *
 * THE SESSION ITSELF LIVES IN `pickup-session.js` (SP-11 C-2b, #1089; operator
 * decision D-4 A): the modal shell, the map provider, the panels, the fetch
 * orchestration, the confirmation guards and the `woodev_pickup_*` events were
 * `openSession()` in this file until the WooCommerce block checkout needed the
 * same picker. They moved out unchanged, and the sections of this docblock that
 * described them moved with them. What stayed here is what is CLASSIC about the
 * picker — the DOM: the slots and triggers, the §8 field/store writes, the
 * address replacement, the locality read off the city field, the `woodev/v1`
 * select route and WooCommerce's jQuery `update_checkout`. {@see classicHost}
 * hands exactly those to the session as its host; the block checkout hands it
 * its own (`src/checkout-blocks/pickup-host.ts`). Names this docblock still
 * cites that are not defined below — `refreshCheckout`, `dropRefreshWaiter`,
 * `handleModalClosed`, `invalidateSelection`, `REFRESH_TIMEOUT_MS`,
 * `buildProviderConfig`, `finishSelection` — are the session's.
 *
 * THE §8 ANCHOR IS RE-PLACED, NOT REUSED, ON `updated_checkout`: WooCommerce
 * replaces the whole shipping-methods HTML fragment on every checkout totals
 * refresh, and `checkout-field-classic.js`'s own `placeSlot()` re-inserts (or,
 * when the old node was detached along with that fragment, recreates) the
 * slot div. Anything mounted into the old node is gone with it, so this file
 * re-mounts on every `updated_checkout` too — deferred ~60ms so it runs AFTER
 * §8's own `updated_checkout` handler has finished re-placing the anchor (see
 * `checkout-field-classic.js`'s own handler for that ordering). Mounting is
 * idempotent: a slot that already holds a trigger is left alone.
 *
 * A LIVE SESSION IS TRACKED IN MODULE SCOPE, KEYED BY FIELD ID — NOT per
 * button: the anchor (and the button mounted into it) can be torn down and
 * recreated by §8 WHILE a session is open (the same `updated_checkout` re-render
 * described above). A session held only in the old button's own click-handler
 * closure would be orphaned the moment that button is discarded — the new
 * button starts from a blank slate and the next click would open a SECOND
 * modal/provider while the first is still attached to `document.body`. Keying
 * by field id instead means whichever button's click handler fires next always
 * finds and tears down the SAME live session, regardless of which button
 * (old or new) is currently mounted.
 *
 * `updated_checkout` IS A jQuery CUSTOM EVENT, not a native DOM one —
 * WooCommerce fires it via `$(document.body).trigger('updated_checkout')`.
 * jQuery only calls a native DOM method for event types an element actually
 * exposes as a method (`click()`, `focus()`, ...); `updated_checkout` is not
 * one, so jQuery never touches `dispatchEvent()` for it — only handlers bound
 * THROUGH jQuery ever see it. This file therefore binds through
 * `window.jQuery` when it is present (guaranteed on every real WooCommerce
 * checkout page — `checkout-field-classic.js` itself declares a hard `jquery`
 * dependency, and this file's own PHP enqueue now declares it too), falling
 * back to a plain native event of the same name so this file stays testable
 * without a real jQuery build loaded (see {@see onCheckoutUpdated}).
 *
 * THE VALUE-WRITE RULE (bitten twice already, see `checkout-field-classic.js`'s
 * own suggest-takeover fix and the A2 gate it protects): a selected point's
 * data is NEVER written straight to a DOM field with nothing else. `billing_city`
 * is a §8 takeover select with a bounded `<option>` set — assigning a value with
 * no matching option silently does nothing — and `billing_state` is owned
 * entirely by WooCommerce (`woocommerce_states` filter). Every write here goes
 * through {@see writeField}, which resolves the OWNING store — per field id,
 * every time, never a single store resolved once and reused — via
 * `WoodevCheckoutFieldStore.getStoreForField()` (Task 12's own store-registry
 * addition). This matters concretely: the pickup field itself (`config.fieldId`)
 * is §8-managed and gets a real store to write through, but `billing_address_1`/
 * `billing_postcode` are plain WooCommerce core fields NO §8 config registers —
 * `getStoreForField()` correctly returns `null` for them, and the write degrades
 * to DOM-only (logged) rather than silently succeeding against a store no §8
 * consumer ever reads. `billing_city` typically IS §8-managed (a takeover
 * target), so it gets the real store treatment. Whichever the case, the DOM
 * field is always kept in sync too — the actual checkout POST WooCommerce
 * submits serializes the form fields themselves, never this module's copy.
 *
 * ADDRESS REPLACEMENT TARGET IS RESOLVED AT WRITE TIME, NOT BAKED IN: the PHP
 * config only ever carries `replaceAddress.billingOnly` (a store-wide, stable
 * setting), never a resolved `billing`/`shipping` target — see
 * `class-pickup-handler.php`'s own docblock. `ship_to_different_address` is a
 * LIVE checkbox the customer can tick after the page renders; resolving the
 * target once at config-build time would go stale the moment they do, and
 * writing into the wrong fieldset would silently clobber a genuinely separate
 * billing address. This file re-applies the same rule
 * {@see \Woodev\Framework\Shipping\Pickup\Address_Target::resolve()} encodes,
 * against the checkbox's CURRENT state, every time a point is selected.
 *
 * EVERY WRITTEN FIELD GETS A REAL `change` (plus `change.select2` when it is a
 * select2-enhanced `<select>`), mirroring EXACTLY how §8's own
 * `updated_checkout` restore does it (`checkout-field-classic.js`): setting
 * `.value` alone leaves a select2-enhanced field's rendered label stale (the
 * combobox keeps showing the OLD city while the underlying value is the new
 * one), and WooCommerce's own `update_checkout` — which refreshes totals and
 * the WC session address — never fires without a real `change` either.
 *
 * `refresh()` NOW ALSO RUNS AUTOMATICALLY ON A GENUINE CART CHANGE (#238), not only through
 * {@see getSession}: a SECOND, permanent `updated_checkout` subscriber — bound once at module
 * scope, alongside the `mountAll()` one at the bottom of this file, never per-session — walks
 * `sessions` on every event and calls `refresh()` on whichever ones report their modal OPEN via
 * {@see WoodevModal#isOpen} ({@see handleCartChanged}). A DISMISSED session
 * (`handleModalClosed()` below runs only `invalidateSelection()` — the entry survives in
 * `sessions` until the next trigger click) is deliberately left untouched: refreshing it would
 * fire a live carrier request for a picker the customer can no longer see, against the
 * merchant's quota, for nothing the next trigger click would not already rebuild from scratch.
 * The subscriber is DEBOUNCED ({@see CART_CHANGE_DEBOUNCE_MS}) — `refresh()` has no reentrancy
 * guard of its own, so an undebounced burst (WooCommerce fires `updated_checkout` more than
 * once per totals recalculation) would independently wipe the point pool once per event.
 *
 * THE SUBSCRIBER MUST ALSO IGNORE ITS OWN ECHO: {@see refreshCheckout} triggers WooCommerce's
 * `update_checkout` after a selection that leaves the modal open, and WooCommerce answers with
 * the very `updated_checkout` this subscriber listens for — without suppression, confirming a
 * point would immediately wipe the pool it was just drawn into, on every single selection.
 *
 * SUPPRESSION KEEPS NO STATE OF ITS OWN. A session already knows when a checkout refresh it
 * caused is outstanding — that is exactly what `refreshWaiter`/`refreshTimer` are, and
 * {@see dropRefreshWaiter} settles them on every path there is: WooCommerce answering,
 * {@see REFRESH_TIMEOUT_MS} expiring, a newer refresh superseding this one, `destroy()`.
 * {@see isSelfRefreshInFlight} is a read of that and nothing else. An earlier design used a
 * dedicated one-shot boolean, and a separate lifetime made two defects unavoidable: the flag
 * was not tied to the request that set it, so it consumed whichever `updated_checkout` happened
 * to arrive first; and nothing cleared it when WooCommerce never answered AT ALL, so it stayed
 * armed for as long as the picker stayed open and silently ate that session's next genuine cart
 * change. Deriving the answer removes both by construction.
 *
 * THE READ HAPPENS AT EVENT TIME, in {@see handleCartChanged} (once per raw event, undebounced),
 * never in {@see flushCartChangeRefresh}'s debounced body — and that rests on a BINDING ORDER
 * nothing at the call site shows. jQuery dispatches handlers in bind order; this subscriber is
 * bound once at module load, long before any session's `one()` waiter can be, so it runs FIRST
 * and still sees the waiter outstanding. By the time a debounce timer fires, that waiter has
 * settled and the state reads identically for an echo and for a genuine change. Do NOT "simplify"
 * the check down into the debounced body.
 *
 * THE RESIDUAL IS ACCEPTED AND DELIBERATE: an `updated_checkout` carries no origin, so a genuine
 * cart change landing while our own refresh is outstanding is suppressed too. It is not lost —
 * a cart change always produces an `updated_checkout`, and the first event also settles the
 * waiter, so the NEXT one is honoured. One event of delay, never a dropped update.
 *
 * UNDER `ownsChrome` NO WAITER IS EVER BOUND (`refreshCheckout()` is handed a null `panels`),
 * so an echo is not suppressed there at all. Harmless, because {@see refresh} is itself a no-op
 * under `ownsChrome` — the embed loads its own points and this file fetches nothing for it — so
 * the unsuppressed event reaches a function that does nothing. If `refresh()` ever gains an
 * `ownsChrome` behaviour, this stops being harmless and needs a waiter (or an equivalent) there.
 *
 * UMD-ish dual export (matches woodev-modal.js/pickup-datasource.js), plus a
 * `mountAll()` re-export purely so a test can drive one mount pass directly
 * instead of only through the deferred event hooks, and `getSession( fieldId )`
 * (Task 20) so external code (e.g. a payment-method-change listener) can reach
 * the currently open session's {@see refresh()} without this file knowing
 * anything about payment methods itself:
 *   - Browser global: window.WoodevPickupMount = { mountAll, getSession }
 *   - CommonJS:       module.exports = { mountAll, getSession }  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/**
	 * `pickup-geo.js`'s exports — read off `window` when it was loaded as a sibling
	 * `<script>` (the real, enqueued browser case: `Pickup_Handler::enqueue_assets()`
	 * declares it a hard dependency of this file), otherwise required directly by
	 * relative path — the case a jest test exercises. Mirrors the identical fallback
	 * in `pickup-panels.js`/`map-provider-yandex.js`.
	 *
	 * @type {Object}
	 */
	var geo = ( 'undefined' !== typeof window && window.WoodevPickupGeo ) ||
		( 'function' === typeof require ? require( './pickup-geo' ) : null );

	/** @type {string} prefix of every `woodev_pickup_config_{suffix}` JS config global. */
	var CONFIG_PREFIX = 'woodev_pickup_config_';

	/** @type {string} marker class on the one trigger button mounted per slot. */
	var TRIGGER_CLASS = 'woodev-pickup-trigger';

	/**
	 * Marker class on the chosen-point address block mounted alongside a trigger (issue
	 * #274 item 2).
	 *
	 * ONE per FIELD, not one per slot, since issue #308 item 4 (adversarial review of
	 * #274 item 3): with a field mounting a trigger into every one of its `[data-woodev-
	 * pickup-slot]` anchors at once, an address block in EVERY slot showed the customer
	 * the exact same «Выбранный пункт выдачи: …» paragraph twice, a few pixels apart —
	 * the operator approved two buttons, never a doubled address line. See {@see
	 * ADDRESS_PLACEMENT}/{@see resolveAddressSlot} for which slot gets it.
	 *
	 * @type {string}
	 */
	var ADDRESS_CLASS = 'woodev-pickup-chosen-address';

	/**
	 * Which `data-woodev-pickup-placement` value carries the chosen-address block when a
	 * field mounts into more than one slot at once (issue #274 item 3 / #308 item 4's
	 * fix) — `'review'` (after the shipping-methods list), never `'rate'`.
	 *
	 * This constant only ever DECIDES anything in the multi-slot configuration, which
	 * since issue #323 is no longer the framework's default: `Checkout_Config` now sends
	 * `['rate']` alone, so an ordinary checkout mounts ONE slot and
	 * {@see resolveAddressSlot} resolves it through its "take the only mounted slot"
	 * branch, whatever this constant says. Left pointing at `'review'` because the
	 * multi-slot case is reachable through `woodev_pickup_slot_placements` and its
	 * behaviour there is unchanged and covered by tests. A single, explicit constant,
	 * read only by {@see resolveAddressSlot}, is what makes flipping it a one-line change
	 * rather than a hunt through {@see mountOne}/{@see mountSlot}.
	 *
	 * @type {string}
	 */
	var ADDRESS_PLACEMENT = 'review';

	/** @type {number} defer, in ms, after `updated_checkout` before re-mounting — see the file docblock. */
	var MOUNT_DEFER_MS = 60;

	/**
	 * Debounce window, in ms, for the cart-change refresh subscriber (#238) — see
	 * {@see handleCartChanged}. WooCommerce fires `updated_checkout` in bursts during a single
	 * totals recalculation, and `refresh()` has no reentrancy guard of its own (see its own
	 * docblock): every call independently wipes the point pool and detail memo. Collapsing a
	 * burst into ONE refresh per session is this constant's whole job —
	 * `pickup-datasource.js`'s own 300ms trailing debounce (`:80`, `:468-479`) already
	 * collapses the resulting NETWORK calls, but only after `refresh()` has already run the
	 * pool reset that many times.
	 *
	 * @type {number}
	 */
	var CART_CHANGE_DEBOUNCE_MS = 300;

	/**
	 * Live sessions, keyed by field id — module scope, not per-button. See the
	 * file docblock for why: a button (and any state closed over only by ITS OWN
	 * click handler) can be discarded and recreated by §8 while a session is
	 * open, and this map is what lets the NEXT click — on whichever button is
	 * currently mounted — still find and tear down the SAME session.
	 *
	 * @type {Object.<string, {modal: Object, refresh: Function, isSelfRefreshInFlight: Function, destroy: Function}>}
	 */
	var sessions = {};

	/**
	 * The locality each field's CURRENT value was applied under, keyed by field id (#271).
	 *
	 * The picker's persistence keys a remembered point by `[locality][type]`
	 * ({@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection}), so a value applied in one
	 * locality says nothing about another. On a reload the server restores the value for the
	 * CURRENT locality, which is why a mount can seed this from the rendered pair; live on the
	 * page, though, nothing was clearing the field when the customer changed locality, so the
	 * trigger kept reading a non-empty field and offering «выбрать другой пункт выдачи» for a
	 * locality where nothing had been chosen.
	 *
	 * This is a REMEMBERED PREVIOUS VALUE, not an event count: the locality field emits plenty
	 * of changes that do not change it (WooCommerce's own `update_checkout` churn, and this
	 * module's own {@see applyAddressReplacement} writing the point's locality straight back
	 * into it), and clearing on the event rather than on the transition would have the picker
	 * cancel its own selection the moment it applied one.
	 *
	 * @type {Object.<string, string>}
	 */
	var appliedLocality = {};

	/**
	 * The chosen point's address to show next to the trigger, keyed by field id (issue #274
	 * item 2).
	 *
	 * Seeded ONCE per field id — guarded by {@see Object.prototype.hasOwnProperty}, the same
	 * "first sighting: adopt as baseline" discipline {@see appliedLocality} already uses —
	 * from `config.chosenAddress`, the PHP side's own resolution of whatever
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection} has remembered for the
	 * checkout's current (locality, type) pair (`Pickup_Handler::resolve_chosen_address()`).
	 * Guarded rather than reseeded on every {@see mountAll} pass for the identical reason
	 * `appliedLocality` is: `mountAll()` runs again after this module's OWN
	 * {@see refreshCheckout} self-triggered `updated_checkout`, and reseeding unconditionally
	 * would clobber a live, in-session selection's address back to the page-load value on
	 * every confirmation.
	 *
	 * {@see applySelection} overwrites the entry directly from the point just confirmed — the
	 * point's `short_address`, ALREADY the derived view {@see Pickup_Point::from_array()}
	 * computes once at the server boundary (issue #263) and the select route's
	 * `to_browser_array()` always sends non-blank whenever `address` is non-blank. No second,
	 * JS-side fallback is layered on top of it here.
	 *
	 * A field whose stored selection predates this feature (id only, no address —
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection::recall_address}'s own
	 * degrade) seeds `''` here, same as a field with nothing remembered at all; either way
	 * {@see syncTriggerLabel} shows no address block, never a blank one — see that function's
	 * own `hasValue` gate.
	 *
	 * @type {Object.<string, string>}
	 */
	var chosenAddress = {};

	/**
	 * Scratch element {@see decodeEscapedAddress} round-trips an already-escaped string through —
	 * module-scope singleton, same shape as `pickup-panels.js`'s own `titleDecodeEl`. Never
	 * attached to `document`, so its `innerHTML` writes touch nothing a customer could see.
	 *
	 * @type {HTMLElement|null}
	 */
	var addressDecodeEl = ( 'undefined' !== typeof document ) ? document.createElement( 'div' ) : null;

	/**
	 * Decodes HTML entities in an already-escaped point field for use in a PLAIN-TEXT sink
	 * (`strongEl.textContent` in {@see syncTriggerLabel}) — the `chosenAddress` counterpart of
	 * `pickup-panels.js`'s own `decodeForTitle()` (issue #274 item 1 follow-up; see that
	 * function's docblock for the underlying round-trip and why `textContent` needs it:
	 * `textContent` never re-parses its argument as markup, so an escaped `&quot;` would
	 * otherwise show the customer a literal `&quot;` instead of `"`).
	 *
	 * This file keeps its own copy rather than importing `pickup-panels.js`'s — that helper is
	 * module-private there (never part of the `WoodevPickupPanels` export), and this file has no
	 * build step to share a module through; `decodeForTitle()`'s own docblock already accepts the
	 * identical duplication for `pickup-geo.js`.
	 *
	 * @param {string} value Already HTML-escaped text (e.g. a REST-sourced `short_address`).
	 * @returns {string}
	 */
	function decodeEscapedAddress( value ) {
		if ( '' === value || ! addressDecodeEl ) {
			return value;
		}

		addressDecodeEl.innerHTML = value; // eslint-disable-line -- server-escaped; read back via textContent below.

		return addressDecodeEl.textContent;
	}

	/**
	 * Field ids whose selection is being applied RIGHT NOW — {@see applySelection} only.
	 *
	 * Strictly synchronous: set, used, and cleared inside one function with no `await`, no
	 * timer, and no network call in between, so unlike #238's `echoExpected` it has no lifetime
	 * of its own to get stuck in. It exists because {@see applyAddressReplacement} writes the
	 * chosen point's locality into the address field and fires a real `change` — which reaches
	 * {@see handleLocalityChanged} synchronously, while the field still holds the value being
	 * applied and `appliedLocality` still names the previous locality. Without this the picker
	 * would clear the selection it had just made.
	 *
	 * @type {Object.<string, boolean>}
	 */
	var applyingSelection = {};

	/**
	 * Which event worlds the #271 locality watcher is already bound in — see
	 * {@see bindLocalityWatchers} for why it needs both, and why binding twice is safe.
	 *
	 * @type {{native: boolean, jquery: boolean}}
	 */
	var localityWatchersBound = { native: false, jquery: false };

	/**
	 * Field ids awaiting a debounced cart-change refresh (#238) — accumulated by
	 * {@see handleCartChanged} at EVENT time (echo suppression is decided there too, per
	 * session, via `isSelfRefreshInFlight()` — NOT in the debounced body, where the state it
	 * reads has already settled), then drained together once {@see CART_CHANGE_DEBOUNCE_MS} of
	 * quiet passes. See {@see flushCartChangeRefresh}.
	 *
	 * @type {Object.<string, boolean>}
	 */
	var pendingCartChangeRefresh = {};

	/**
	 * The debounce timer backing {@see handleCartChanged} — restarted on every raw
	 * `updated_checkout`, so a burst of them collapses into one {@see flushCartChangeRefresh}
	 * call. `null` when no refresh is currently pending.
	 *
	 * @type {number|null}
	 */
	var cartChangeDebounceTimer = null;

	// -------------------------------------------------------------------------
	// The surface-neutral session (SP-11 C-2b, #1089)
	// -------------------------------------------------------------------------

	/**
	 * `pickup-session.js`'s exports — the picker session itself (`open()`), plus the two
	 * helpers this file shares with it. Read off `window` when it was loaded as a sibling
	 * `<script>` (`Pickup_Handler::enqueue_assets()` declares `woodev-pickup-session` a hard
	 * dependency of this file), otherwise required by relative path — the jest case. The
	 * same fallback {@see geo} uses.
	 *
	 * @type {{open: Function, text: Function, fireDocumentEvent: Function}}
	 */
	var pickupSession = ( 'undefined' !== typeof window && window.WoodevPickupSession ) ||
		( 'function' === typeof require ? require( './pickup-session' ) : null );

	/**
	 * Reads an i18n string off a config — empty string when absent/blank, NEVER a JS-side
	 * hardcoded default. See `pickup-session.js`'s own `text()` docblock.
	 *
	 * @type {function(Object, string): string}
	 */
	var text = pickupSession.text;

	/**
	 * Fires one of the `woodev_pickup_*` events — a native, bubbling `CustomEvent` on
	 * `document.body`. See `pickup-session.js`'s own `fireDocumentEvent()` docblock.
	 *
	 * @type {function(string, Object): void}
	 */
	var fireDocumentEvent = pickupSession.fireDocumentEvent;

	/**
	 * Binds a handler for WooCommerce's `updated_checkout` — through jQuery when
	 * present (every real checkout page), a plain native event of the same name
	 * otherwise (keeps this file testable without a real jQuery build loaded).
	 * See the file docblock for why a native `addEventListener` alone can never
	 * observe a jQuery-triggered custom event type.
	 *
	 * @param {Function} handler
	 * @returns {void}
	 */
	function onCheckoutUpdated( handler ) {
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', handler );

			return;
		}

		document.body.addEventListener( 'updated_checkout', handler );
	}

	/**
	 * Collects every registered pickup config currently on `window` — several
	 * plugins (one `woodev_pickup_config_{suffix}` global each) may coexist, same
	 * as the §8 checkout-field-layer configs.
	 *
	 * @returns {Object[]}
	 */
	function collectConfigs() {
		return Object.keys( window ).filter( function( key ) {
			return 0 === key.indexOf( CONFIG_PREFIX );
		} ).map( function( key ) {
			return window[ key ];
		} ).filter( function( config ) {
			return config && 'string' === typeof config.fieldId && config.fieldId.length > 0;
		} );
	}

	/**
	 * Whether the given field is a `<select>` — the only element shape that can
	 * silently reject a value with no matching `<option>`.
	 *
	 * @param {HTMLElement} field
	 * @returns {boolean}
	 */
	function isSelectField( field ) {
		return !! field.tagName && 'SELECT' === field.tagName.toUpperCase();
	}

	/**
	 * Adds a missing `<option>` to a `<select>` before its value is set — a
	 * bounded-option assignment with no matching option silently does nothing
	 * (the same fix §8 already applies to its own suggest-takeover). A no-op
	 * when a matching option already exists.
	 *
	 * @param {HTMLSelectElement} select
	 * @param {string}            value
	 * @param {string}            label
	 * @returns {void}
	 */
	function ensureOption( select, value, label ) {
		for ( var i = 0; i < select.options.length; i++ ) {
			if ( select.options[ i ].value === value ) {
				return;
			}
		}

		var option = document.createElement( 'option' );

		option.value = value;
		option.text = label;
		select.appendChild( option );
	}

	/**
	 * Reads a field's current `.value` — `''` when the field does not exist, mirroring
	 * {@see text}'s "absent means blank, never undefined" discipline. Used both to decide
	 * the trigger button's label ({@see syncTriggerLabel}) and to seed the panels' own
	 * `setSelectedId()` at session-open time, so a re-entrant picker (the customer already
	 * chose a point earlier) reads correctly from the very first render, not only after a
	 * NEW selection is made.
	 *
	 * @param {string} fieldId
	 * @returns {string}
	 */
	function fieldValue( fieldId ) {
		var field = document.getElementById( fieldId );

		return field && 'string' === typeof field.value ? field.value : '';
	}

	/**
	 * Seeds {@see chosenAddress} for one field id from the PHP-resolved
	 * `config.chosenAddress` (`Pickup_Handler::resolve_chosen_address()`, issue #274 item 2)
	 * — guarded to run only once per field id; see that map's own docblock for why.
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function seedChosenAddress( config ) {
		if ( Object.prototype.hasOwnProperty.call( chosenAddress, config.fieldId ) ) {
			return;
		}

		chosenAddress[ config.fieldId ] = 'string' === typeof config.chosenAddress ? config.chosenAddress : '';
	}

	/**
	 * The remembered pickup selection per LOCALITY KEY, per field id (issue #349) —
	 * `{ fieldId: { localityKey: { id, address } } }`.
	 *
	 * Seeded from `config.selections` (`Pickup_Handler::resolve_remembered_selections()`, the
	 * same session-backed `[locality][type]` map `chosenAddress` and the restored field value
	 * come out of) and kept current by {@see rememberSelectionLocally} on every confirmed pick.
	 * Both halves are needed and neither is redundant: the seed covers localities chosen BEFORE
	 * this page loaded, the local mirror covers the ones chosen since.
	 *
	 * @type {Object.<string, Object.<string, {id: string, address: string}>>}
	 */
	var rememberedSelections = {};

	/**
	 * Seeds {@see rememberedSelections} for one field id from `config.selections` — guarded to
	 * run once per field id, exactly like {@see seedChosenAddress}, so a later mount pass never
	 * discards what this page has since remembered locally.
	 *
	 * Shape-guarded entry by entry rather than trusted wholesale: this is server-rendered JSON,
	 * but an entry with no usable id would later be "restored" as an empty selection, which is
	 * indistinguishable from clearing one — the failure would look like the very bug this fixes.
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function seedRememberedSelections( config ) {
		if ( Object.prototype.hasOwnProperty.call( rememberedSelections, config.fieldId ) ) {
			return;
		}

		var seeded = {};
		var source = config && config.selections;

		if ( source && 'object' === typeof source ) {
			Object.keys( source ).forEach( function( localityKey ) {
				var entry = source[ localityKey ];

				if ( ! localityKey || ! entry || 'string' !== typeof entry.id || ! entry.id ) {
					return;
				}

				seeded[ localityKey ] = {
					id: entry.id,
					address: 'string' === typeof entry.address ? entry.address : '',
				};
			} );
		}

		rememberedSelections[ config.fieldId ] = seeded;
	}

	/**
	 * Mirrors a just-confirmed selection into {@see rememberedSelections} under the locality key
	 * it was filed against — see {@see applySelection}'s own call site.
	 *
	 * Keyed on {@see resolveLocalityKey}, which is what the server's own
	 * `Provider_Selection_Scope::current_locality()` files under for a plugin inside the
	 * Location Provider layer. A plugin OUTSIDE that layer gets `resolveLocality()`'s DOM read
	 * here, which its own `Selection_Scope` may or may not agree with — a mismatch simply means
	 * nothing is ever found to restore, i.e. exactly today's behaviour, never a wrong point.
	 *
	 * @param {Object} config
	 * @param {string} pointId
	 * @param {string} address
	 * @returns {void}
	 */
	function rememberSelectionLocally( config, pointId, address ) {
		if ( ! pointId ) {
			return;
		}

		var localityKey = resolveLocalityKey( config );

		if ( ! localityKey ) {
			return;
		}

		// Seeded HERE and in {@see restoreRememberedSelection} rather than at mount time, on
		// purpose: `woodev_location_applied` can reach this module before `mountAll()` has run
		// for a given config (script order is not ours to assume), and a restore that ran
		// against an unseeded map would silently find nothing. The guard inside makes it
		// idempotent, so calling it from both entry points costs nothing.
		seedRememberedSelections( config );

		if ( ! rememberedSelections[ config.fieldId ] ) {
			rememberedSelections[ config.fieldId ] = {};
		}

		rememberedSelections[ config.fieldId ][ localityKey ] = {
			id: pointId,
			address: 'string' === typeof address ? address : '',
		};
	}

	/**
	 * Restores the point remembered for `localityKey`, if any — #176's own agreed behaviour,
	 * stated on {@see handleLocalityChanged} ("returning to the previous locality restores the
	 * point chosen there") and, until issue #349, implemented only by a full page render.
	 *
	 * A no-op when nothing is remembered for that locality: the clearing
	 * {@see handleLocalityChanged} already did is then the correct final state, and this must
	 * NOT re-apply a point belonging to the locality the customer just left.
	 *
	 * Also a no-op when the field already holds that exact id — the ordinary case on the very
	 * first `woodev_location_applied` after a reload, where the server already restored both the
	 * value and the label. Re-writing it would fire a redundant `change` (and with it an
	 * `update_checkout`) on every single page load.
	 *
	 * @param {Object} config
	 * @param {string} localityKey
	 * @returns {void}
	 */
	function restoreRememberedSelection( config, localityKey ) {
		if ( ! localityKey || applyingSelection[ config.fieldId ] ) {
			return;
		}

		seedRememberedSelections( config );

		var remembered = ( rememberedSelections[ config.fieldId ] || {} )[ localityKey ];

		if ( ! remembered || ! remembered.id || fieldValue( config.fieldId ) === remembered.id ) {
			return;
		}

		chosenAddress[ config.fieldId ] = remembered.address;

		writeAndFireChange( config.fieldId, remembered.id );
		syncTriggerLabel( config );
	}

	/**
	 * The `aria-label` context {@see syncTriggerLabel} appends to a trigger button's visible
	 * text, keyed by the slot's own `data-woodev-pickup-placement` (issue #308 item 4 —
	 * adversarial review of #274 item 3: two identically-labelled buttons for the same
	 * field). `null` for anything else — a placement value this file does not recognise, or
	 * no attribute at all (a single-slot field has nothing to disambiguate FROM, so its
	 * button keeps its plain visible text as its own accessible name).
	 *
	 * @param {Object} config
	 * @param {?string} placement `slot.getAttribute( 'data-woodev-pickup-placement' )`.
	 * @returns {?string}
	 */
	function placementAriaContext( config, placement ) {
		if ( 'review' === placement ) {
			return text( config, 'triggerReviewContext' );
		}

		if ( 'rate' === placement ) {
			return text( config, 'triggerRateContext' );
		}

		return null;
	}

	/**
	 * Syncs EVERY mounted trigger button's label, and its chosen-point address block (issue
	 * #274 item 2), to whether `config.fieldId` currently holds a value —
	 * `i18n.triggerChange` ("Выбрать другой пункт выдачи") once a point is already selected,
	 * `i18n.trigger` otherwise. Called at mount time (a checkout reload after an earlier
	 * selection) and again right after a NEW selection is applied — see
	 * {@see Pickup_Handler::get_js_config()}'s own docblock note on `triggerChange` being
	 * this file's responsibility.
	 *
	 * Runs across EVERY slot currently mounted for this field id (`querySelectorAll`, not
	 * the first match) — issue #274 item 3 lets one field mount a trigger into more than one
	 * anchor at once (`woocommerce_review_order_after_shipping`-equivalent AND
	 * `woocommerce_after_shipping_rate`-equivalent), and a second trigger left out of sync
	 * with the first (stale label, stale/missing address, stale disabled state) is worse than
	 * not mounting it at all. A slot with no trigger currently mounted in it is skipped
	 * (defensive — §8 can discard/recreate an anchor between calls, see the file docblock).
	 *
	 * The address block shows ONLY alongside a non-empty field value: gating display on
	 * `hasValue` — not merely on whether {@see chosenAddress} happens to still hold an entry
	 * — is what keeps a stale address from surviving past {@see handleLocalityChanged}
	 * clearing the field without this module ever needing to remember to clear the map entry
	 * too. `chosenAddress[fieldId]` itself may legitimately be `''` (nothing remembered, or a
	 * pre-#274 id-only entry — {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection::recall_address()}'s
	 * own degrade); either way the block simply stays hidden, never rendered blank.
	 *
	 * Also refreshes every button's `aria-label` (issue #308 item 4 — adversarial review of
	 * #274 item 3): with two triggers mounted for the same field, both show the IDENTICAL
	 * visible text, so a screen-reader user tabbing between them hears two indistinguishable
	 * button names. `aria-label` is set to the same visible text PLUS the placement's own
	 * i18n context ({@see placementAriaContext}) — never a replacement, so this changes
	 * nothing a SIGHTED customer reads — and refreshed here, on every sync, so it never goes
	 * stale after `button.textContent` flips between `trigger`/`triggerChange` below.
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function syncTriggerLabel( config ) {
		seedChosenAddress( config );

		var slots = document.querySelectorAll( '[data-woodev-pickup-slot="' + config.fieldId + '"]' );
		var hasValue = !! fieldValue( config.fieldId );
		var addressText = hasValue ? ( chosenAddress[ config.fieldId ] || '' ) : '';

		Array.prototype.forEach.call( slots, function( slot ) {
			var button = slot.querySelector( '.' + TRIGGER_CLASS );

			if ( ! button ) {
				return;
			}

			var label = text( config, hasValue ? 'triggerChange' : 'trigger' );

			button.textContent = label;

			var ariaContext = placementAriaContext( config, slot.getAttribute( 'data-woodev-pickup-placement' ) );

			if ( ariaContext ) {
				button.setAttribute( 'aria-label', label + ', ' + ariaContext );
			} else {
				button.removeAttribute( 'aria-label' );
			}

			var addressEl = slot.querySelector( '.' + ADDRESS_CLASS );

			if ( ! addressEl ) {
				return;
			}

			var strongEl = addressEl.querySelector( 'strong' );

			if ( addressText ) {
				if ( strongEl ) {
					strongEl.textContent = addressText;
				}

				addressEl.style.display = '';
			} else {
				if ( strongEl ) {
					strongEl.textContent = '';
				}

				addressEl.style.display = 'none';
			}
		} );
	}

	/**
	 * The freshest REST nonce available: the node WooCommerce replaces on every
	 * `update_checkout` (see `Pickup_Handler::print_nonce_node()`), falling back to the one
	 * baked into the page-load config when that node is absent — a plugin on a non-checkout
	 * surface, or a theme that dropped `wp_footer`.
	 *
	 * @param {Object} config
	 * @returns {string}
	 */
	function currentNonce( config ) {
		var node = config.nonceNodeId ? document.getElementById( config.nonceNodeId ) : null;
		var live = node && node.dataset ? node.dataset.woodevPickupNonce : '';

		return live || String( config.nonce || '' );
	}

	/**
	 * Resolves the store that owns a given field id, or null when none does
	 * (either a genuinely unmanaged field like `billing_address_1`, or the
	 * checkout-field-store script did not load).
	 *
	 * @param {string} fieldId
	 * @returns {Object|null}
	 */
	function resolveStore( fieldId ) {
		var factory = window.WoodevCheckoutFieldStore;

		return factory && 'function' === typeof factory.getStoreForField ? factory.getStoreForField( fieldId ) : null;
	}

	/**
	 * Writes one field's value THROUGH its OWNING store — resolved fresh for
	 * THIS field id, never a single store resolved once and reused across
	 * several fields, since a plugin's §8 config may manage some of the fields
	 * this file writes but not others (see the file docblock). A `<select>`
	 * first gets a missing option added — see {@see ensureOption} — so the
	 * assignment actually takes. The DOM field is always mirrored too — the
	 * actual checkout POST WooCommerce submits serializes the form fields
	 * themselves, never this module's copy.
	 *
	 * A field with no owning store still gets written to the DOM — degraded,
	 * not silently dropped — but logs, since that value has no store-side
	 * safety net restoring it after a later `updated_checkout`.
	 *
	 * @param {string} fieldId
	 * @param {string} value
	 * @param {string} [label]
	 * @returns {HTMLElement|null} the written DOM field, or null when it does not exist.
	 */
	function writeField( fieldId, value, label ) {
		var store = resolveStore( fieldId );

		if ( store ) {
			store.setValue( fieldId, value );
		} else if ( window.console && 'function' === typeof console.warn ) {
			console.warn( '[woodev-pickup-mount] no §8-managed store owns field "' + fieldId + '"; DOM only.' );
		}

		var field = document.getElementById( fieldId );

		if ( ! field ) {
			return null;
		}

		if ( isSelectField( field ) ) {
			ensureOption( field, value, 'undefined' === typeof label || null === label ? value : label );
		}

		field.value = value;

		return field;
	}

	/**
	 * Fires a REAL native `change` on a field, plus `change.select2` when it is
	 * select2/selectWoo-enhanced — EXACTLY mirroring how §8's own
	 * `updated_checkout` restore does it (`checkout-field-classic.js`), never an
	 * invented variant. Required, not cosmetic, for two independent reasons:
	 *
	 * - `checkout-field-classic.js`'s delegated change handler treats an event
	 *   with a truthy `originalEvent` (jQuery's name for the underlying native
	 *   Event it normalized) as user-meaningful regardless of value; a plain
	 *   `jQuery(...).trigger('change')` with no real Event behind it would only
	 *   count as meaningful when the value happens to be non-empty.
	 * - A select2-enhanced `<select>` renders its OWN combobox label separately
	 *   from the underlying `<select>`'s value; setting `.value` alone leaves
	 *   the rendered label showing the OLD choice. `change.select2` is
	 *   select2's own namespaced re-render hook — a plain native `change` alone
	 *   does not reach it.
	 * - WooCommerce's own `update_checkout` (refreshing totals and the WC
	 *   session address) is itself bound to a real `change`.
	 *
	 * @param {HTMLElement} field
	 * @returns {void}
	 */
	function fireFieldChange( field ) {
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		if ( window.jQuery && field.classList && field.classList.contains( 'select2-hidden-accessible' ) ) {
			window.jQuery( field ).trigger( 'change.select2' );
		}
	}

	/**
	 * Writes a field through {@see writeField} and, when it exists in the DOM,
	 * fires its change through {@see fireFieldChange}.
	 *
	 * @param {string} fieldId
	 * @param {string} value
	 * @param {string} [label]
	 * @returns {void}
	 */
	function writeAndFireChange( fieldId, value, label ) {
		var field = writeField( fieldId, value, label );

		if ( field ) {
			fireFieldChange( field );
		}
	}

	/**
	 * Resolves which fieldset ("billing" or "shipping") a selected point's
	 * address should be written into — re-applying
	 * {@see \Woodev\Framework\Shipping\Pickup\Address_Target::resolve()}'s rule
	 * against the LIVE "ship to a different address" checkbox, since the config
	 * only ever carries the stable `billingOnly` half. See the file docblock.
	 *
	 * @param {Object} config
	 * @returns {string} `'billing'` or `'shipping'`.
	 */
	function resolveAddressTarget( config ) {
		var replaceAddress = ( config && config.replaceAddress ) || {};

		if ( replaceAddress.billingOnly ) {
			return 'billing';
		}

		var checkbox = document.querySelector( '[name="ship_to_different_address"]' );

		return checkbox && checkbox.checked ? 'shipping' : 'billing';
	}

	/**
	 * Reads the customer's CURRENT city off the resolved address target.
	 *
	 * Live on every call, never cached: the customer can edit the city field or tick "ship to
	 * a different address" after the page rendered, and `refresh()` exists precisely so a
	 * stale answer is never reused. Returns `''`, never `undefined`, when the field is absent
	 * or blank — a provider reads that as "no known locality" and degrades, rather than having
	 * to guard against a missing key.
	 *
	 * ONLY {@see buildProviderConfig}'s map-centering/address-search `locality` reads through
	 * here now (Task 15; issue #159) — the map provider (`map-provider-yandex.js`) needs a
	 * GEOCODABLE PLACE NAME (it feeds this straight into `ymaps.geocode()`; see
	 * `buildProviderConfig`'s own "GEOCODABILITY CONSTRAINT" section), which a Location
	 * Provider layer KEY (`provider_id:native_id`) is not. The BULK POINTS QUERY moved to
	 * {@see resolveLocalityKey} instead — see that function's own docblock for why a DOM read
	 * was #159 itself: the server addresses points by the layer's own record/key, never by a
	 * city name the browser happened to have lying around in a `<select>`.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {string}
	 */
	function resolveLocality( config ) {
		var cityField = document.getElementById( resolveAddressTarget( config ) + '_city' );

		return cityField && 'string' === typeof cityField.value ? cityField.value : '';
	}

	/**
	 * The Location Provider layer's current locality KEY for each provider-backed field,
	 * keyed by field id (Task 15; issue #159) — refreshed live by
	 * {@see handleLocationApplied} listening for `location-cascade.js`'s own
	 * `woodev_location_applied` event, seeded once from `config.location.current.key`
	 * (`Pickup_Handler::get_js_config()`'s own page-load resolution) by
	 * {@see resolveLocalityKey} on first read — same "first sighting: adopt as baseline"
	 * discipline {@see appliedLocality}/{@see chosenAddress} already use.
	 *
	 * @type {Object.<string, string>}
	 */
	var resolvedLocalityKey = {};

	/**
	 * Resolves the CURRENT Location Provider layer locality key the bulk points query
	 * addresses by (Task 15; issue #159) — the actual fix for #159's own title: a DOM-read
	 * city string (what {@see resolveLocality} still is, for a DIFFERENT purpose — see that
	 * function's own docblock) is never trustworthy as a SERVER addressing key, because the
	 * customer's city `<select>`/typeahead value is whatever a plugin's own §8 field wiring
	 * happens to store there (a name, a FIAS code, a region id — see `resolveLocality`'s own
	 * "GEOCODABILITY CONSTRAINT" note on `buildProviderConfig`), never guaranteed to be the
	 * SAME namespaced key {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope}
	 * and {@see \Woodev\Framework\Shipping\Pickup\Point_Query} agree on server-side.
	 *
	 * `config.location` is PRESENT only for a plugin whose {@see Pickup_Handler} was wired
	 * with a `$plugin` (Task 15) — see that class' own `location_config_block()` docblock for
	 * why the block is OMITTED, not merely empty, for a plugin that has not opted in. Falls
	 * back to {@see resolveLocality}'s DOM read in that case, preserving this file's
	 * PRE-#159 behaviour byte for byte for a plugin that has not wired the Location Provider
	 * layer at all — this fallback is not a workaround, it is the contract: a plugin outside
	 * the layer never sees `config.location` and must keep working exactly as before.
	 *
	 * AN EMPTY KEY IS NEVER SENT AS THE ADDRESSING LOCALITY (review finding F1, second half,
	 * rig-verified): `''` is the layer's own "refusing to answer" sentinel (gotcha
	 * `an-empty-domain-key-is-not-a-key`, restated on `Pickup_Handler::location_config_block()`'s
	 * own docblock) — the customer either has no current record yet, or (before this task's
	 * F1 server-side fix) a wired-but-unconfigured provider used to leak the block through
	 * anyway. Either way, `''` is not a locality any {@see Point_Query} can usefully address,
	 * so this function degrades to the SAME DOM read a plugin outside the layer gets, on
	 * EVERY call, not just the first — never caching a confident "no locality" answer that a
	 * later DOM edit (or a cascade clear — see `location-cascade.js`'s own `fireLocationApplied()`
	 * calls with no record, review finding F2) could no longer correct.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {string}
	 */
	function resolveLocalityKey( config ) {
		if ( ! config || ! config.location ) {
			return resolveLocality( config );
		}

		if ( ! Object.prototype.hasOwnProperty.call( resolvedLocalityKey, config.fieldId ) ) {
			var current = config.location.current;
			var settlementKey = config.location.settlementKey;

			// PREFERS `settlementKey` (issue #336), exactly as {@see handleLocationApplied}
			// prefers the event detail of the same name — the page-load config and the live
			// event have to speak ONE vocabulary or the map would address itself differently
			// before and after the customer's first pick. Falls back to `current.key` when the
			// chain holds no settlement (an address typed with no settlement ever picked): the
			// map must keep working there, which is the half of #336 that is deliberately NOT
			// the storage key's refuse-rather-than-fall-back rule.
			resolvedLocalityKey[ config.fieldId ] = 'string' === typeof settlementKey && settlementKey
				? settlementKey
				: ( current && 'string' === typeof current.key ? current.key : '' );
		}

		var key = resolvedLocalityKey[ config.fieldId ];

		return key ? key : resolveLocality( config );
	}

	/**
	 * Handles `woodev_location_applied` (Task 15; issue #159; `location-cascade.js`'s own
	 * event, fired once the customer's `/select` round-trip actually persisted) — updates
	 * {@see resolvedLocalityKey} for EVERY currently-registered, Location-Provider-backed
	 * pickup config, so {@see resolveLocalityKey} tracks the map's addressing locality
	 * without re-reading `config.location.current.key` (a PAGE-LOAD snapshot, never refreshed
	 * on its own).
	 *
	 * PREFERS `detail.settlementKey` (issue #336) over `detail.key`, falling back to `key` only
	 * when `settlementKey` is absent/empty (an older cascade build, or a chain with no
	 * settlement — e.g. an address typed without ever picking one, spec §4.4's
	 * `backwardsFill()`). This is DELIBERATELY THE OPPOSITE asymmetry from #334's storage-key
	 * rule (`Provider_Selection_Scope::current_locality()`, which REFUSES rather than falls
	 * back): a fallback KEY there would silently mis-file the customer's chosen pickup point,
	 * but a REFUSED map here would regress a picker that works today — see
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_location_record()}'s own
	 * docblock for the full reasoning, which this function mirrors client-side. `key` itself
	 * keeps its exact prior meaning — issue #309's `implicit` flag and other consumers ride on
	 * it — only the field THIS function adopts for `resolvedLocalityKey` changes.
	 *
	 * A field WITHOUT `config.location` (a plugin that has not wired the layer) is skipped —
	 * its own `resolveLocalityKey()` falls back to the DOM read regardless, so writing an
	 * entry here for it would only be dead state.
	 *
	 * @param {CustomEvent} event `detail: { key, level, settlementKey, implicit }` — `key` and
	 *                            `settlementKey` are read here.
	 * @returns {void}
	 */
	function handleLocationApplied( event ) {
		var detail = event && event.detail ? event.detail : {};
		var settlementKey = 'string' === typeof detail.settlementKey ? detail.settlementKey : '';
		var key = settlementKey ? settlementKey : ( 'string' === typeof detail.key ? detail.key : '' );

		collectConfigs().forEach( function( config ) {
			if ( config.location ) {
				resolvedLocalityKey[ config.fieldId ] = key;

				// Issue #349. THIS is where a selection can be restored, and the only place it
				// can: {@see handleLocalityChanged} runs off a `change` on the city field, which
				// fires BEFORE the cascade's `/select` round trip — so at that moment the new
				// locality's KEY is still the old one, and a lookup there would either miss or,
				// worse, restore the point belonging to the locality just left. This event is
				// fired precisely once the new record is persisted, i.e. once the identity of
				// the locality now on screen is settled and agreed with the server.
				restoreRememberedSelection( config, key );
			}
		} );
	}

	/**
	 * Writes a selected point's address/locality/postal code into the resolved
	 * fieldset — a no-op when `replaceAddress` is disabled. A field a
	 * `woodev_pickup_address_replacing` listener vetoed (deleted from the announced
	 * `fields`) is left untouched.
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {void}
	 */
	function applyAddressReplacement( config, point ) {
		var replaceAddress = ( config && config.replaceAddress ) || {};

		if ( ! replaceAddress.enabled ) {
			return;
		}

		var target = resolveAddressTarget( config );
		var address = point && 'string' === typeof point.address ? point.address : '';
		var locality = point && 'string' === typeof point.locality ? point.locality : '';
		var postalCode = point && 'string' === typeof point.postal_code ? point.postal_code : '';

		var fields = {};

		fields[ target + '_address_1' ] = address;
		fields[ target + '_city' ] = locality;
		fields[ target + '_postcode' ] = postalCode;

		// Issue #339, BEFORE the writes and never after them. These fields are shared with
		// `location-cascade.js`, which treats a value that no longer matches its confirmed
		// record as the customer having edited the field by hand — and drops the settlement
		// record, so the next address search leaves without `within`. It only takes a
		// different SPELLING of the same locality: the carrier answers «Москва» where the
		// provider said «Moscow» under an English account locale. The announcement lets the
		// cascade re-seed first; `dispatchEvent()` runs its listeners inline, so the re-seed
		// is complete before the `change` events below are fired. A page with no cascade on
		// it has no listener and this is an inert no-op.
		fireDocumentEvent( 'woodev_pickup_address_replacing', { fields: fields, fieldId: config.fieldId } );

		// Issue #961: a listener may VETO a field by deleting it from the announced `fields`
		// (`location-cascade.js` does, for a settlement the server guard holds to the
		// customer's own pick). Only what is still announced is written.
		var has = function( fieldId ) {
			return Object.prototype.hasOwnProperty.call( fields, fieldId );
		};

		if ( has( target + '_address_1' ) ) {
			writeAndFireChange( target + '_address_1', address );
		}

		if ( has( target + '_city' ) ) {
			writeAndFireChange( target + '_city', locality, locality );
		}

		if ( has( target + '_postcode' ) ) {
			writeAndFireChange( target + '_postcode', postalCode );
		}
	}

	/**
	 * Applies a selected point: writes its id into the §8 field, then — when
	 * enabled — the address replacement, and records the point's address for the
	 * trigger's own chosen-address block (issue #274 item 2).
	 *
	 * @param {Object}  config
	 * @param {Object}  point
	 * @param {boolean} addressEscaped Whether `point.short_address` is already HTML-escaped
	 *                                 (REST-sourced — {@see Pickup_Point::to_browser_array()})
	 *                                 and must be decoded before it reaches `chosenAddress`
	 *                                 (issue #274 item 1 follow-up), or is raw (an `ownsChrome`
	 *                                 embedded-widget point straight from
	 *                                 `map-provider-embedded.js`'s own `normalizePoint()`) and
	 *                                 must be stored untouched — see {@see finishSelection}'s own
	 *                                 call site for how the caller knows which it is.
	 * @returns {void}
	 */
	function applySelection( config, point, addressEscaped ) {
		var pointId = point && undefined !== point.id && null !== point.id ? String( point.id ) : '';

		// Guarded, not reordered: `applyAddressReplacement()` fires a real `change` on the
		// locality field, which reaches `handleLocalityChanged()` synchronously — see
		// {@see applyingSelection}. The write order itself is load-bearing for the A2 gate and
		// for every listener on the §8 field, so it stays exactly as it was.
		applyingSelection[ config.fieldId ] = true;

		try {
			writeAndFireChange( config.fieldId, pointId );
			applyAddressReplacement( config, point );
		} finally {
			delete applyingSelection[ config.fieldId ];
		}

		// Read AFTER the replacement, so this records the locality the field's value now
		// genuinely belongs to — which, with `replaceAddress` on, is the point's own.
		appliedLocality[ config.fieldId ] = resolveLocality( config );

		// `point.short_address` is ALREADY the derived view — `Pickup_Point::from_array()`
		// (issue #263) guarantees it non-blank whenever `address` is, so this is a straight
		// read, never a second `short_address || address` fallback layered on top of the one
		// the server already applied at its own boundary. {@see syncTriggerLabel}, called by
		// every caller of this function immediately after, is what actually renders it — into
		// `strongEl.textContent`, a plain-text sink, so an escaped source is decoded HERE, once,
		// rather than left for the render site to guess at (issue #274 item 1 follow-up: the
		// reload path's `config.chosenAddress` seeds this same map already-raw — see
		// {@see seedChosenAddress} — so `chosenAddress` itself is the single point both sources
		// are normalized to agree on: raw plain text, never escaped markup).
		var shortAddress = point && 'string' === typeof point.short_address ? point.short_address : '';

		chosenAddress[ config.fieldId ] = addressEscaped ? decodeEscapedAddress( shortAddress ) : shortAddress;

		// Issue #349: keep this page's own copy of the remembered map in step with the server's.
		// The server filed this point under the customer's CURRENT locality a moment ago (the
		// `/select` endpoint's own `Pickup_Selection::remember()`), and without mirroring it here
		// the restore below would only ever know about selections made before this page loaded —
		// so "pick a point, switch locality, switch back" would still come up empty until a
		// reload, which is the exact half of #176 that was missing.
		rememberSelectionLocally( config, pointId, chosenAddress[ config.fieldId ] );
	}

	/**
	 * Drops an applied selection when the customer changes locality (#271).
	 *
	 * The remembered point in `WC()->session` is deliberately NOT touched: the picker's agreed
	 * behaviour (#176) is that returning to the previous locality restores the point chosen
	 * there. Only the page's own applied state — the §8 field value and, through it, the
	 * trigger's label and the A2 gate — is dropped, because it names a point that does not
	 * belong to the locality now on screen. The server's `[locality][type]` map keeps every
	 * other entry, and nothing here posts a value that could overwrite one: `remember()` is
	 * only ever called from the selection endpoint, and `forget_all()` only on order creation.
	 *
	 * Clears on the TRANSITION, never on the event — see {@see appliedLocality}.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {void}
	 */
	function handleLocalityChanged( config ) {
		if ( applyingSelection[ config.fieldId ] ) {
			return;
		}

		var current = resolveLocality( config );

		// First sighting: adopt it as the baseline. The value on the page was restored by the
		// server for exactly this locality, so there is nothing to drop.
		if ( ! Object.prototype.hasOwnProperty.call( appliedLocality, config.fieldId ) ) {
			appliedLocality[ config.fieldId ] = current;

			return;
		}

		if ( current === appliedLocality[ config.fieldId ] ) {
			return;
		}

		appliedLocality[ config.fieldId ] = current;

		if ( ! fieldValue( config.fieldId ) ) {
			return;
		}

		writeAndFireChange( config.fieldId, '' );
		syncTriggerLabel( config );
	}

	// -------------------------------------------------------------------------
	// Trigger + picker session
	// -------------------------------------------------------------------------

	/**
	 * Tears down whatever session is currently tracked for a field id, if any —
	 * a harmless no-op otherwise, and safe even when that session's modal was
	 * already closed by the user via Escape/backdrop (every method involved is
	 * itself idempotent).
	 *
	 * @param {string} fieldId
	 * @returns {void}
	 */
	function closeSession( fieldId ) {
		var current = sessions[ fieldId ];

		if ( ! current ) {
			return;
		}

		delete sessions[ fieldId ];
		current.destroy();
	}

	/**
	 * The classic checkout's answers to `pickup-session.js`'s host contract (SP-11 C-2b,
	 * #1089) — every one of them is what `openSession()` did inline while it lived in this
	 * file, so the classic picker behaves exactly as before; only the address of the code
	 * changed.
	 *
	 * Built per OPEN, not per page: `returnFocusTo` is the button that was clicked, and every
	 * reader below is a LIVE read (the selected id, the locality, the nonce and `window.jQuery`
	 * are all looked up at call time — see the file docblock for why none of them may be
	 * captured once).
	 *
	 * @param {Object}      config
	 * @param {HTMLElement} triggerEl element focus returns to on close.
	 * @returns {Object}
	 */
	function classicHost( config, triggerEl ) {
		return {
			returnFocusTo: triggerEl,

			getSelectedId: function() {
				return fieldValue( config.fieldId );
			},

			getLocality: function() {
				return resolveLocality( config );
			},

			getLocalityKey: function() {
				return resolveLocalityKey( config );
			},

			getNonce: function() {
				return currentNonce( config );
			},

			// The `woodev/v1` select route, through the session's own dataSource.
			confirmSelection: function( point, dataSource ) {
				return dataSource.selectPoint( {
					pointId: String( point && point.id ),
					fieldId: config.fieldId,
				} );
			},

			applySelection: function( point, addressEscaped ) {
				applySelection( config, point, addressEscaped );
				syncTriggerLabel( config );
			},

			close: function() {
				closeSession( config.fieldId );
			},

			// WooCommerce's own checkout refresh is jQuery-only — see the file docblock's note on
			// `updated_checkout`. `once()` hands back the unbind the session's `dropRefreshWaiter()`
			// runs: a `one()` handler that never fires is not self-cleaning.
			checkoutRefresh: {
				isAvailable: function() {
					return !! window.jQuery;
				},

				once: function( handler ) {
					window.jQuery( document.body ).one( 'updated_checkout', handler );

					return function() {
						if ( window.jQuery ) {
							window.jQuery( document.body ).off( 'updated_checkout', handler );
						}
					};
				},

				trigger: function() {
					window.jQuery( document.body ).trigger( 'update_checkout' );
				},
			},
		};
	}

	/**
	 * Which top-level config key feeds which accent custom property on the trigger — the SAME
	 * three properties, fed by the SAME server-resolved values, that `pickup-panels.js`'s
	 * `applyAccentColor()` sets on `.woodev-pickup-stage` for the modal (issue #379).
	 *
	 * @type {Object<string,string>}
	 */
	var TRIGGER_ACCENT_PROPERTIES = {
		accentColor: '--woodev-pickup-accent',
		accentFillColor: '--woodev-pickup-accent-fill',
		accentContrastColor: '--woodev-pickup-accent-contrast',
	};

	/**
	 * Hands the trigger button the accent the modal already wears. The modal's host is
	 * `.woodev-pickup-stage`, and custom properties only reach DESCENDANTS — the trigger lives in
	 * the checkout's own DOM, outside any stage, so it needs the properties on ITSELF. Same
	 * discipline as `applyAccentColor()`: CSSOM `style.setProperty()` only (D-15), every value
	 * re-validated through `geo.safeColor()`. A value that is absent or unsafe is simply NOT written,
	 * so the var() fallbacks in `pickup.css` (`#06aedd` / `#047a9b` / `#fff` — the very literals the
	 * modal's own defaults pin) paint instead: no accent set → the button looks exactly like the
	 * modal's default. The darken/WCAG derivation is NOT recomputed here; the fill/contrast pair
	 * arrives already derived (`Pickup_Handler::resolve_accent_fill_color()` /
	 * `resolve_accent_contrast_color()`).
	 *
	 * @param {HTMLElement} button
	 * @param {Object}      config
	 * @returns {void}
	 */
	function applyTriggerAccent( button, config ) {
		if ( ! geo ) {
			return;
		}

		Object.keys( TRIGGER_ACCENT_PROPERTIES ).forEach( function( key ) {
			var colour = geo.safeColor( config && config[ key ], '' );

			if ( colour ) {
				button.style.setProperty( TRIGGER_ACCENT_PROPERTIES[ key ], colour );
			}
		} );
	}

	/**
	 * The trigger button's class list (issue #379): `button` + our own class + the theme's button
	 * class when the server sent one (`config.themeButtonClass` — `wp-element-button` on a block
	 * theme, `''` on a classic one; `Pickup_Handler::resolve_theme_button_class()`). That is how
	 * WooCommerce styles its own buttons: the site decides the SHAPE, `pickup.css` keeps only the
	 * accent colour and the states. Re-validated here to a class-name token list — a value from
	 * a page global never reaches `className` unchecked — and a missing, empty or non-string value
	 * leaves the list exactly as it was before the key existed.
	 *
	 * @param {Object} config
	 * @returns {string}
	 */
	function triggerClassName( config ) {
		var classes = [ 'button', TRIGGER_CLASS ];
		var themeClass = config && 'string' === typeof config.themeButtonClass ? config.themeButtonClass : '';

		themeClass.split( /\s+/ ).forEach( function( token ) {
			token = token.replace( /[^A-Za-z0-9_-]/g, '' );

			if ( token && -1 === classes.indexOf( token ) ) {
				classes.push( token );
			}
		} );

		return classes.join( ' ' );
	}

	/**
	 * Mounts a trigger button into ONE §8 anchor, wiring the button's click handler.
	 * Idempotent — an anchor that already holds a `TRIGGER_CLASS` button is left
	 * untouched, so this is safe to call on every `mountAll()` pass without ever
	 * attaching a second click listener to the same button (which would open two
	 * concurrent sessions from a single click).
	 *
	 * At most one session is ever open per field id, regardless of which of its slots the
	 * click came from (issue #274 item 3: a field may now mount into more than one anchor
	 * at once) — a click ALWAYS tears down whatever session {@see sessions} currently
	 * tracks for this field (a no-op the first time, and a harmless no-op too when that
	 * session was already closed by the user via Escape/backdrop, or orphaned by §8
	 * recreating an anchor — see the file docblock) before opening a fresh one. The clicked
	 * BUTTON — not always the first one mounted — is what focus returns to on close, since
	 * the session's host ({@see classicHost}) is handed THIS slot's own button, captured in its
	 * own closure.
	 *
	 * The chosen-address block (issue #274 item 2) is NOT built here — see {@see
	 * ensureAddressBlock}, called by {@see mountOne} for only ONE of this field's slots
	 * (issue #308 item 4).
	 *
	 * @param {Object}      config
	 * @param {HTMLElement} slot One `[data-woodev-pickup-slot]` anchor for this field id.
	 * @returns {void}
	 */
	function mountSlot( config, slot ) {
		if ( slot.querySelector( '.' + TRIGGER_CLASS ) ) {
			return;
		}

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = triggerClassName( config );

		applyTriggerAccent( button, config );

		button.addEventListener( 'click', function( event ) {
			event.preventDefault();
			closeSession( config.fieldId );
			sessions[ config.fieldId ] = pickupSession.open( config, classicHost( config, button ) );
		} );

		slot.appendChild( button );
	}

	/**
	 * Picks which of a field's currently mounted slots carries the chosen-address block
	 * (issue #274 item 2 / #308 item 4 — the operator approved two buttons, never a
	 * doubled address line a few pixels apart). {@see ADDRESS_PLACEMENT} wins when a slot
	 * with that `data-woodev-pickup-placement` exists; otherwise the FIRST mounted slot,
	 * in DOM order, takes over — the address must show up SOMEWHERE, never nowhere just
	 * because the preferred anchor was never mounted. A field with only one slot always
	 * resolves to that slot, whatever its placement.
	 *
	 * Since issue #323 that second branch is the ORDINARY path, not the edge case it was
	 * written as: the framework default is now `['rate']` alone, so a normal checkout has
	 * exactly one slot and it is not the `ADDRESS_PLACEMENT` one.
	 *
	 * @param {NodeList|HTMLElement[]} slots This field's currently mounted
	 *                                       `[data-woodev-pickup-slot]` anchors.
	 * @returns {?HTMLElement}
	 */
	function resolveAddressSlot( slots ) {
		var list = Array.prototype.slice.call( slots );

		for ( var i = 0; i < list.length; i++ ) {
			if ( ADDRESS_PLACEMENT === list[ i ].getAttribute( 'data-woodev-pickup-placement' ) ) {
				return list[ i ];
			}
		}

		return list.length ? list[ 0 ] : null;
	}

	/**
	 * Builds the chosen-point address block (issue #274 item 2) inside `slot`, when it is
	 * not already there. Idempotent, same discipline as {@see mountSlot}'s own
	 * `TRIGGER_CLASS` guard — a re-mount pass (a checkout reload after an earlier
	 * selection, or §8 recreating THIS field's `resolveAddressSlot()` anchor) must not
	 * rebuild a live block and lose whatever {@see syncTriggerLabel} already wrote into
	 * its `<strong>` child.
	 *
	 * @param {Object}      config
	 * @param {HTMLElement} slot The slot {@see resolveAddressSlot} picked for this field.
	 * @returns {void}
	 */
	function ensureAddressBlock( config, slot ) {
		if ( slot.querySelector( '.' + ADDRESS_CLASS ) ) {
			return;
		}

		// Hidden until {@see syncTriggerLabel} decides there is something to show. Built
		// with a stable `<strong>` child that function only ever re-fills the text of —
		// never rebuilt per sync, so a repeated sync pass touches no more of the DOM than
		// the text it actually changed.
		var address = document.createElement( 'p' );

		address.className = ADDRESS_CLASS;
		address.style.display = 'none';
		address.appendChild( document.createTextNode( text( config, 'chosenPointAddress' ) + ' ' ) );
		address.appendChild( document.createElement( 'strong' ) );

		slot.appendChild( address );
	}

	/**
	 * Mounts a trigger into EVERY §8 anchor currently rendered for one config's field id
	 * (issue #274 item 3 — a field may occupy more than one placement at once), the
	 * chosen-address block into exactly ONE of them (issue #308 item 4 — see {@see
	 * resolveAddressSlot}), then syncs every mounted trigger to the field's current value.
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function mountOne( config ) {
		var slots = document.querySelectorAll( '[data-woodev-pickup-slot="' + config.fieldId + '"]' );

		Array.prototype.forEach.call( slots, function( slot ) {
			mountSlot( config, slot );
		} );

		var addressSlot = resolveAddressSlot( slots );

		if ( addressSlot ) {
			ensureAddressBlock( config, addressSlot );
		}

		// A re-mount after an earlier selection (a full checkout reload, or §8 recreating
		// an anchor mid-session) must read `i18n.triggerChange`, not always `i18n.trigger` —
		// see {@see syncTriggerLabel}. Called once for ALL of this field's slots, not once
		// per slot above — {@see syncTriggerLabel} already iterates every one of them itself.
		syncTriggerLabel( config );
	}

	/**
	 * Dispatches one `change` anywhere in the document to whichever configs read their
	 * locality off the field that changed (#271).
	 *
	 * Delegated on `document.body` rather than bound to the locality field itself, because
	 * §8's takeover REPLACES that field (`<input>` → `<select>`, `ensureSelect()`) and a direct
	 * listener would be discarded along with the old node — the same reason §8's own adapter
	 * delegates.
	 *
	 * @param {Event|Object} event a native `change`, or jQuery's normalized event object.
	 * @returns {void}
	 */
	function handleAddressFieldChanged( event ) {
		var changedId = event && event.target && event.target.id ? event.target.id : '';

		if ( ! changedId ) {
			return;
		}

		collectConfigs().forEach( function( config ) {
			if ( changedId === resolveAddressTarget( config ) + '_city' ) {
				handleLocalityChanged( config );
			}
		} );
	}

	/**
	 * Binds {@see handleAddressFieldChanged} in BOTH event worlds — idempotently, and re-tried
	 * on every {@see mountAll} pass so jQuery is picked up whenever it appears.
	 *
	 * Both bindings are needed, and the rig is what proved it. A jQuery `.trigger( 'change' )`
	 * dispatches NO native DOM event, and that is how a real city selection arrives:
	 * select2/selectWoo — which is what §8's suggest takeover turns the locality field into —
	 * reports a pick with exactly that call. So a plain `addEventListener` never saw the one
	 * change that matters most, while the jest test that "proved" the watcher worked dispatched
	 * a native `Event` and passed. Same asymmetry the file docblock already records for
	 * `updated_checkout`, in the opposite direction: there, jQuery-only; here, both, because
	 * this module's own {@see writeAndFireChange} fires a REAL native event that a
	 * jQuery-only binding on a page without jQuery could not see either.
	 *
	 * Binding both means a native `change` reaches the handler twice when jQuery is loaded.
	 * That is harmless BY CONSTRUCTION, not by luck: {@see handleLocalityChanged} keys off the
	 * locality TRANSITION, so the second call finds the baseline already updated and returns
	 * without touching anything.
	 *
	 * @returns {void}
	 */
	function bindLocalityWatchers() {
		if ( ! localityWatchersBound.native ) {
			localityWatchersBound.native = true;

			document.body.addEventListener( 'change', handleAddressFieldChanged );
		}

		if ( localityWatchersBound.jquery || ! window.jQuery ) {
			return;
		}

		var $body = window.jQuery( document.body );

		// A jQuery double thin enough to lack `.on()` is a legitimate shape here (see the
		// harness in `tests/js/pickup-mount.test.js`), so this is a capability check, not a
		// paranoid one — and it must not latch `jquery` as bound when it declines.
		if ( ! $body || 'function' !== typeof $body.on ) {
			return;
		}

		localityWatchersBound.jquery = true;

		$body.on( 'change', handleAddressFieldChanged );
	}

	/**
	 * Mounts every currently-registered config's trigger — the single entry
	 * point both the deferred `updated_checkout` handler and the initial boot
	 * call below use.
	 *
	 * @returns {void}
	 */
	function mountAll() {
		bindLocalityWatchers();

		collectConfigs().forEach( function( config ) {
			mountOne( config );

			// #271's safety net, and its baseline seed on the very first pass. Covers the
			// locality changes no `change` on the city field can report: WooCommerce
			// re-rendering the address block server-side, and the "ship to a different
			// address" checkbox, which switches which FIELD the locality is read from
			// ({@see resolveAddressTarget}) without touching either field's value.
			handleLocalityChanged( config );
		} );
	}

	/**
	 * Returns the currently open session for a field id, or null when none is open — the
	 * external hook onto {@see refresh()} (Task 20's own docblock: e.g. a payment-method
	 * change elsewhere on the page) without that caller needing to know anything about
	 * `sessions` being module-private.
	 *
	 * @param {string} fieldId
	 * @returns {{modal: Object, refresh: Function, isSelfRefreshInFlight: Function, destroy: Function}|null}
	 */
	function getSession( fieldId ) {
		return sessions[ fieldId ] || null;
	}

	/**
	 * Refreshes every session accumulated in {@see pendingCartChangeRefresh} — the debounced
	 * half of {@see handleCartChanged}, run once {@see CART_CHANGE_DEBOUNCE_MS} of quiet has
	 * passed since the last raw `updated_checkout`.
	 *
	 * Re-checks `isOpen()` here too, not just at event time in `handleCartChanged`: a session
	 * added to the pending set can still close — or be torn down and replaced by a fresh
	 * trigger click — before this timer fires.
	 *
	 * @returns {void}
	 */
	function flushCartChangeRefresh() {
		cartChangeDebounceTimer = null;

		var pending = pendingCartChangeRefresh;

		pendingCartChangeRefresh = {};

		Object.keys( pending ).forEach( function( fieldId ) {
			var session = sessions[ fieldId ];

			if ( session && session.modal.isOpen() ) {
				session.refresh();
			}
		} );
	}

	/**
	 * The module-scope `updated_checkout` subscriber that wires #232's cart-change verdict
	 * invalidation to a real signal (#238) — see the file docblock's "REFRESH() NOW ALSO RUNS
	 * AUTOMATICALLY ON A GENUINE CART CHANGE" section.
	 *
	 * Runs on EVERY raw event, undebounced — echo suppression is decided HERE, at event time,
	 * per session, via {@see isSelfRefreshInFlight}, never inside {@see flushCartChangeRefresh}'s
	 * debounced body: by the time a debounce timer fires, a session's own `refreshCheckout()`
	 * waiter has already settled, so a check made there could never tell an echo from a genuine
	 * change. The read works here, and only here, because this subscriber is bound at module
	 * load and therefore runs BEFORE the per-session waiter jQuery dispatches next — see the
	 * file docblock's note on that binding order.
	 *
	 * A DISMISSED session (modal closed, `destroyed` still false — see
	 * {@see handleModalClosed}) is skipped entirely: no echo check, no pending entry, nothing.
	 * The next trigger click rebuilds it from scratch; refreshing it here would fire a live
	 * carrier request the customer cannot see, against the merchant's quota, for a picker they
	 * already dismissed.
	 *
	 * @returns {void}
	 */
	function handleCartChanged() {
		Object.keys( sessions ).forEach( function( fieldId ) {
			var session = sessions[ fieldId ];

			if ( ! session.modal.isOpen() ) {
				return;
			}

			if ( session.isSelfRefreshInFlight() ) {
				return;
			}

			pendingCartChangeRefresh[ fieldId ] = true;
		} );

		if ( null !== cartChangeDebounceTimer ) {
			window.clearTimeout( cartChangeDebounceTimer );
		}

		cartChangeDebounceTimer = window.setTimeout( flushCartChangeRefresh, CART_CHANGE_DEBOUNCE_MS );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	onCheckoutUpdated( function() {
		window.setTimeout( mountAll, MOUNT_DEFER_MS );
	} );

	// #238: a second, independent, permanent `updated_checkout` subscriber — see
	// {@see handleCartChanged}. Deliberately its own onCheckoutUpdated() registration rather
	// than folded into the one above: the two run on unrelated schedules (a flat 60ms defer vs
	// a restarting debounce) and over unrelated state (§8 anchors vs `sessions`).
	onCheckoutUpdated( handleCartChanged );

	// Task 15 (issue #159): `location-cascade.js`'s own event, a NATIVE CustomEvent (see
	// {@see handleLocationApplied}'s own docblock for why no jQuery world applies here) —
	// bound once, module scope, exactly like the `woodev_modal_closed` listener below.
	document.body.addEventListener( 'woodev_location_applied', handleLocationApplied );

	// Initial mount: on a real checkout page this script runs in the footer,
	// after §8's own ready handler has already placed every anchor, but the
	// SAME deferred call is used here too rather than a special-cased
	// synchronous one — one mounting code path, not two.
	window.setTimeout( mountAll, MOUNT_DEFER_MS );

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	var api = { mountAll: mountAll, getSession: getSession };

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupMount = api;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}

}() );
