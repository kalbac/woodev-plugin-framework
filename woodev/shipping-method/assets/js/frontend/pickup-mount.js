/**
 * Woodev Pickup Mount — mounts the pickup-point picker into the §8 checkout
 * field-layer anchor.
 *
 * Plain bootstrap script, ES5-safe, no build step — enqueued directly (see
 * `woodev-pickup-mount` in class-pickup-handler.php) as the final assembly
 * point of SP-5: it places a trigger button inside the anchor §8 deliberately
 * leaves empty (`[data-woodev-pickup-slot="<fieldId>"]`), and on click opens
 * {@link WoodevModal}, resolves a {@link window.WoodevPickupMapProviders}
 * entry, and hands it a {@link WoodevPickupDataSource}. This file is the
 * CLASSIC checkout (`[woocommerce_checkout]`) mount — a later task in this
 * programme builds the equivalent for the WC blocks checkout, reusing the same
 * modal shell and dataSource.
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
 * THE PROVIDER CONFIG IS A MERGE, BUILT HERE, NOT PASSED THROUGH RAW: PHP's
 * `config.mapConfig` only ever carries what the ACTIVE MAP PROVIDER itself
 * contributes (for the Yandex provider: `scriptUrl`, `ns`, `hasApiKey` — see
 * `class-yandex-map-provider.php::get_js_config()`) — it deliberately knows
 * nothing about the picker's own strategy, i18n, or the customer's address.
 * {@see buildProviderConfig} shallow-merges three more things a provider
 * always needs on top of that: `strategy` and `i18n` (both already sitting on
 * the outer config §8/Task 8 already builds — no new PHP surface), and
 * `locality`, resolved LIVE by reading `{target}_city`'s CURRENT `.value` at
 * the moment the picker opens — through the SAME {@see resolveAddressTarget}
 * this file already uses for address replacement, never a second, potentially
 * diverging rule. Locality is deliberately NOT baked into the PHP config for
 * the identical reason `replaceAddress`'s target is not (see above): the
 * customer can change the city field, or tick "ship to a different address",
 * AFTER the page (and the PHP config with it) has already rendered, and a
 * value resolved once at render time would go stale the moment they do —
 * openSession() calls {@see buildProviderConfig} at OPEN time, and a retry
 * re-opens with a brand new provider (see "RETRY NEVER RE-init()S..." below),
 * so a locality change between retries is picked up too, not just once per
 * page load. An unresolved/empty city field yields `''`, never `undefined` —
 * a provider (e.g. the Yandex one, spec §4.3) treats an empty locality as "no
 * known locality" and degrades accordingly, rather than having to guard
 * against a missing key.
 *
 * EVERY WRITTEN FIELD GETS A REAL `change` (plus `change.select2` when it is a
 * select2-enhanced `<select>`), mirroring EXACTLY how §8's own
 * `updated_checkout` restore does it (`checkout-field-classic.js`): setting
 * `.value` alone leaves a select2-enhanced field's rendered label stale (the
 * combobox keeps showing the OLD city while the underlying value is the new
 * one), and WooCommerce's own `update_checkout` — which refreshes totals and
 * the WC session address — never fires without a real `change` either.
 *
 * WHY THIS FILE — NOT THE MAP PROVIDER — OWNS THE MODAL'S ERROR/EMPTY/NOTICE
 * STATES: a provider's `init()` only ever receives a bare `container` DOM
 * node (see below), never the modal instance — it has no way to call
 * `showError()`/`showEmpty()`/`showNotice()` itself. This file therefore
 * calls `dataSource.fetchPoints()` itself (see {@see fetchAndSetPoints} — the
 * ONE place this session ever does, per the file docblock's "THIS FILE, NOT
 * THE PROVIDER, NOW OWNS FETCHING" section): a rejection is mapped from the
 * dataSource's `{status, code, message}` shape to an i18n message (never the
 * raw code); a genuinely empty resolution (`[]`) is NOT an error — see
 * `pickup-datasource.js`'s own docblock — it degrades through the SAME
 * `degrade()` helper instead.
 *
 * NON-DESTRUCTIVE DEGRADATION ONCE A SET IS DRAWN: `showError()`/`showEmpty()`
 * REPLACE the modal body wholesale (see their own docblocks in
 * `woodev-modal.js`) — correct the FIRST time, when there is nothing yet worth
 * keeping, wrong afterwards: a customer who has already panned to a drawn map
 * must not lose it because a SUBSEQUENT viewport request 502s or comes back
 * empty (spec §4.9). Each session therefore tracks whether the provider has
 * EVER resolved a non-empty point set; before that, a failure/empty result
 * still uses `showError()`/`showEmpty()` (nothing is drawn, replacing is
 * right); after, it degrades through `showNotice()` instead — a dismissible
 * banner ALONGSIDE the body, never touching whatever the provider already drew.
 *
 * RETRY NEVER RE-`init()`S A LIVE PROVIDER INSTANCE: the provider contract is
 * `init / on / destroy` — re-`init()`ing an instance that already holds a map
 * handle bound to a (possibly now-orphaned) container is undefined by that
 * contract. A retry therefore always destroys the current provider, constructs
 * a FRESH one, re-wires every event on it, and only then calls `init()`. The
 * framework's own {@see window.WoodevPickupPanels} shell, in contrast, is
 * constructed ONCE per session (never per retry) — a map-provider failure has
 * nothing to do with the list/card chrome around it, so retrying rebuilds only
 * what actually failed.
 *
 * THIS FILE, NOT THE PROVIDER, NOW OWNS FETCHING (Task 20): the provider
 * contract shrank to `init( container, config )` — it draws whatever
 * `setPoints( groups )` hands it and reports camera/selection events, but
 * never calls the REST layer itself any more (see `map-provider-yandex.js`'s
 * own docblock, "THIS FILE NO LONGER FETCHES ANYTHING ITSELF"). This file is
 * therefore the fetch ORCHESTRATOR: under `strategy: 'bulk'` it fetches once,
 * right after `init()` resolves; under `strategy: 'viewport'` it waits for the
 * provider's own `boundsChange( bbox )` and fetches per-bbox from there. Every
 * fetch is funnelled through one place ({@see fetchAndSetPoints}) so the
 * "group, hand to the provider, tell the panels the types, fire the loaded
 * event, degrade on empty/error" sequence never has two competing
 * implementations. `Embedded_Map_Provider` (`mapConfig.ownsChrome: true`) is
 * the one exception — its carrier iframe loads its own points invisibly to
 * this file, so NONE of this fetch orchestration, and no
 * {@see window.WoodevPickupPanels} instance at all, is ever constructed for
 * it (D-3: the whole point of `ownsChrome` is that the framework renders
 * nothing of its own around a provider that already owns the full picker UI).
 *
 * THE FOUR `woodev_pickup_*` EVENTS are native, bubbling `CustomEvent`s fired
 * on `document.body` — exactly like `woodev-modal.js`'s own `woodev_modal_*`
 * events (see that file's docblock for why `jQuery.trigger()` would be
 * invisible to a plain `addEventListener`, and this file's own docblock above
 * on `updated_checkout` for the identical asymmetry): `woodev_pickup_map_ready`
 * (`{ fieldId, provider }` — D-14 names both: `provider` is the ACTIVE provider's
 * id, the only way an integrator hooking a specific map can tell which one just
 * initialised) once a session's `init()` resolves, `woodev_pickup_points_loaded` after
 * EVERY successful fetch this file makes (the initial bulk load, every
 * viewport refetch, every type-filter refetch, every {@see refresh()} call —
 * never just the first), `woodev_pickup_point_selected` right before the modal
 * is asked to close, and `woodev_pickup_error` specifically for a PROVIDER-level
 * `error` (map script failed to load, embed failed to load) — the kind that
 * breaks the whole map, not a transient dataSource fetch failure the existing
 * degrade-to-notice machinery already recovers from without needing to alarm
 * an external error reporter.
 *
 * `refresh()`, EXPOSED PER SESSION VIA {@see getSession}: re-runs whatever
 * fetch the CURRENT strategy/viewport/type-filter state describes — the hook a
 * payment-method change elsewhere on the page uses to get a fresh
 * server-computed `selectable` verdict on the SAME points without the
 * customer touching the map. Safe to call twice (each call is an independent
 * fetch; `pickup-datasource.js`'s own debounce collapses a rapid double-call
 * into one request) and safe to call after the session has already closed (a
 * no-op, guarded the same way every other post-close continuation in this
 * file is).
 *
 * EVERY LISTENER THIS FILE ATTACHS DIES WITH THE SESSION: the provider's own
 * event handlers are re-registered fresh on every `start()` (initial open AND
 * every retry) and go away when that provider instance is destroyed (a real
 * provider's own `destroy()` empties its handler arrays — see
 * `map-provider-yandex.js`/`map-provider-embedded.js`); the
 * {@see window.WoodevPickupPanels} handlers are registered exactly ONCE per
 * session and become unreachable once {@see closeSession} drops this file's
 * only reference to that `panels` instance (its DOM root is detached along
 * with the rest of the modal body). No handler here is ever bound to
 * `document.body` or any other long-lived, session-independent target — only
 * `provider`/`panels`, both torn down (or dereferenced) together — so the
 * existing "two clicks never leave two providers alive" guarantee extends to
 * the panels and every event wired through them, unchanged.
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

	/** @type {number} defer, in ms, after `updated_checkout` before re-mounting — see the file docblock. */
	var MOUNT_DEFER_MS = 60;

	/**
	 * The four `document.body` `CustomEvent` names this file fires — see the file
	 * docblock's own section on them. Native, bubbling events, never a jQuery
	 * `.trigger()` — see {@see fireDocumentEvent}.
	 *
	 * @type {string}
	 */
	var EVENT_MAP_READY = 'woodev_pickup_map_ready';
	var EVENT_POINTS_LOADED = 'woodev_pickup_points_loaded';
	var EVENT_POINT_SELECTED = 'woodev_pickup_point_selected';
	var EVENT_ERROR = 'woodev_pickup_error';

	/**
	 * Identifies this modal on every `woodev_modal_*` event, so a consumer can filter the
	 * pickup dialog out of the framework's generic modal stream — the role WooCommerce's own
	 * backbone modal gives its `target` argument. Spec D-14 fixes the literal value; changing
	 * it silently breaks every listener written against the documented name.
	 *
	 * @type {string}
	 */
	var PICKUP_MODAL_ID = 'woodev-pickup-map';

	/**
	 * Maps a dataSource error `code` to the i18n key `Pickup_Handler::get_js_config()`
	 * emits for it. A config missing the key (a wiring bug, not a normal runtime
	 * state — the PHP side emits all of these) falls back to the generic
	 * `i18n.error` message, itself falling back to an empty string — see
	 * {@see text} — rather than a JS-side hardcoded Russian string that would
	 * mask exactly that kind of bug by happening to read the same either way.
	 *
	 * @type {Object.<string, string>}
	 */
	var ERROR_MESSAGE_KEYS = {
		woodev_pickup_upstream_error: 'upstreamError',
		woodev_pickup_rate_limited: 'rateLimited',
		woodev_pickup_point_not_found: 'notFound',
	};

	/**
	 * Live sessions, keyed by field id — module scope, not per-button. See the
	 * file docblock for why: a button (and any state closed over only by ITS OWN
	 * click handler) can be discarded and recreated by §8 while a session is
	 * open, and this map is what lets the NEXT click — on whichever button is
	 * currently mounted — still find and tear down the SAME session.
	 *
	 * @type {Object.<string, {modal: Object, refresh: Function, destroy: Function}>}
	 */
	var sessions = {};

	// -------------------------------------------------------------------------
	// Small helpers
	// -------------------------------------------------------------------------

	/**
	 * Fires one of the four `woodev_pickup_*` events (see the file docblock) — a native,
	 * bubbling `CustomEvent` on `document.body`, exactly matching `woodev-modal.js`'s own
	 * `emit()`. Seen by both `addEventListener` and jQuery `.on()`; NEVER a jQuery
	 * `.trigger()`, which would be invisible to a plain `addEventListener` (see the file
	 * docblock's note on `updated_checkout` for the identical asymmetry).
	 *
	 * @param {string} type
	 * @param {Object} detail
	 * @returns {void}
	 */
	function fireDocumentEvent( type, detail ) {
		document.body.dispatchEvent( new CustomEvent( type, { detail: detail, bubbles: true } ) );
	}

	/**
	 * Reads an i18n string off a config — empty string when absent/blank, NEVER
	 * a JS-side hardcoded default. A missing key is therefore visibly blank in
	 * the UI (loud) rather than silently substituted by a string that happens to
	 * read the same as the real one, which would hide a PHP/JS i18n-key mismatch
	 * — see I1 in the SP-5 Task 12 review.
	 *
	 * @param {Object} config
	 * @param {string} key
	 * @returns {string}
	 */
	function text( config, key ) {
		var i18n = ( config && config.i18n ) || {};

		return 'string' === typeof i18n[ key ] && i18n[ key ].length > 0 ? i18n[ key ] : '';
	}

	/**
	 * Resolves the human message for a dataSource rejection — mapped by `code`
	 * to the i18n string `Pickup_Handler::get_js_config()` emits for it,
	 * otherwise the generic error message. NEVER the raw code itself.
	 *
	 * @param {Object}      config
	 * @param {Object|null} reason `{ status, code, message }`, or null/undefined.
	 * @returns {string}
	 */
	function errorMessage( config, reason ) {
		var code = reason && reason.code;
		var key = code ? ERROR_MESSAGE_KEYS[ code ] : null;
		var mapped = key ? text( config, key ) : '';

		return mapped || text( config, 'error' );
	}

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
	 * Syncs the trigger button's label to whether `config.fieldId` currently holds a value —
	 * `i18n.triggerChange` ("Выбрать другой пункт выдачи") once a point is already selected,
	 * `i18n.trigger` otherwise. Called at mount time (a checkout reload after an earlier
	 * selection) and again right after a NEW selection is applied — see
	 * {@see Pickup_Handler::get_js_config()}'s own docblock note on `triggerChange` being
	 * this file's responsibility. A no-op when no trigger is currently mounted for this field
	 * (defensive — §8 can discard/recreate the anchor between calls, see the file docblock).
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function syncTriggerLabel( config ) {
		var slot = document.querySelector( '[data-woodev-pickup-slot="' + config.fieldId + '"]' );
		var button = slot && slot.querySelector( '.' + TRIGGER_CLASS );

		if ( ! button ) {
			return;
		}

		button.textContent = text( config, fieldValue( config.fieldId ) ? 'triggerChange' : 'trigger' );
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
	 * Shallow-merges two plain objects into a NEW object — `overrides` wins on a key
	 * clash. A hand-rolled loop rather than `Object.assign()`, matching the manual-copy
	 * style the sibling SP-5 files already use (see e.g. this file's own `writeField()`
	 * or `pickup-datasource.js`'s `resolveAll()`), not because `Object.assign` would be
	 * unsafe here (this codebase already relies on runtime-only APIs like `fetch`/
	 * `Promise` — see `pickup-datasource.js`'s own docblock).
	 *
	 * @param {Object} base
	 * @param {Object} overrides
	 * @returns {Object}
	 */
	function shallowMerge( base, overrides ) {
		var result = {};
		var key;

		for ( key in base ) {
			if ( Object.prototype.hasOwnProperty.call( base, key ) ) {
				result[ key ] = base[ key ];
			}
		}

		for ( key in overrides ) {
			if ( Object.prototype.hasOwnProperty.call( overrides, key ) ) {
				result[ key ] = overrides[ key ];
			}
		}

		return result;
	}

	/**
	 * Builds the config object handed to the map provider's `init()` — see the file
	 * docblock's "THE PROVIDER CONFIG IS A MERGE" section for why this exists and why
	 * `locality` is resolved live rather than baked into the PHP config.
	 *
	 * GEOCODABILITY CONSTRAINT (viewport strategy only): `locality` below is the city
	 * field's `.value` VERBATIM. The §8 field-source contract only promises `{value,
	 * label}` — it never promises `value` is a human-readable place name. A plugin may
	 * legitimately use an opaque carrier city id or a FIAS code as `value` (a real fixture
	 * does exactly this: `billing_state` ships `value: '77'`, `label: 'Москва'`). Reading
	 * `.value` is still the right call: under `strategy: 'bulk'` the SAME plugin owns both
	 * this field and the dataSource, so whatever shape `value` takes is internally
	 * consistent end to end. Under `strategy: 'viewport'`, though, the Yandex provider
	 * feeds this string straight into `ymaps.geocode()` — a free-text geocoder that expects
	 * a place name, not a code (see `map-provider-yandex.js`'s own
	 * `_resolveInitialViewport()` docblock). A plugin wiring the Yandex provider under
	 * `viewport` MUST keep its city field's option `value` a geocodable place name.
	 * Getting this wrong is SILENT: the geocode simply resolves nothing, and the map opens
	 * at its technical `[0,0]`/zoom-2 fallback instead of the customer's city — nothing
	 * throws, rejects, or logs.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {Object}
	 */
	function buildProviderConfig( config ) {
		var target = resolveAddressTarget( config );
		var cityField = document.getElementById( target + '_city' );
		var locality = cityField && 'string' === typeof cityField.value ? cityField.value : '';

		return shallowMerge( config.mapConfig || {}, {
			strategy: config.strategy,
			i18n: config.i18n,
			locality: locality,
		} );
	}

	/**
	 * Builds the config object handed to {@see window.WoodevPickupPanels}'s constructor —
	 * the panels read `i18n`/`lang`/`accentColor` at the TOP level of their own config (see
	 * that file's docblock), but the outer mount config only carries `i18n`/`accentColor`
	 * there directly; `lang` sits inside `config.mapConfig.lang` (the active provider's own
	 * locale, see `map-provider-yandex.js`'s docblock). This merge is the ONE place that
	 * reconciles the two shapes — never duplicated inline at the panels' construction site.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {Object}
	 */
	function buildPanelsConfig( config ) {
		var mapConfig = config.mapConfig || {};

		return shallowMerge( config, {
			lang: 'string' === typeof mapConfig.lang ? mapConfig.lang : '',
		} );
	}

	/**
	 * Extracts the distinct `{ code, label }` point types present in `points`, first-seen
	 * order — the shape {@see window.WoodevPickupPanels}'s own `setTypes()` accumulates
	 * across calls (see that file's docblock: it never forgets a type once seen, even when a
	 * LATER call reports fewer). This file calls `setTypes()` after every successful fetch —
	 * {@see fetchAndSetPoints} — since a point's `type` is the only place that information
	 * exists; neither the provider nor the panels themselves have any other way to learn it.
	 *
	 * @param {Array} points
	 * @returns {Array}
	 */
	function extractTypes( points ) {
		var seen = {};
		var types = [];

		( points || [] ).forEach( function( point ) {
			var type = point && point.type;

			if ( ! type || 'string' !== typeof type.code || Object.prototype.hasOwnProperty.call( seen, type.code ) ) {
				return;
			}

			seen[ type.code ] = true;
			types.push( { code: type.code, label: type.label } );
		} );

		return types;
	}

	/**
	 * Writes a selected point's address/locality/postal code into the resolved
	 * fieldset — a no-op when `replaceAddress` is disabled.
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

		writeAndFireChange( target + '_address_1', address );
		writeAndFireChange( target + '_city', locality, locality );
		writeAndFireChange( target + '_postcode', postalCode );
	}

	/**
	 * Applies a selected point: writes its id into the §8 field, then — when
	 * enabled — the address replacement.
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {void}
	 */
	function applySelection( config, point ) {
		var pointId = point && undefined !== point.id && null !== point.id ? String( point.id ) : '';

		writeAndFireChange( config.fieldId, pointId );
		applyAddressReplacement( config, point );
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
	 * Opens the picker for one config: the modal shell, the resolved provider, and — unless
	 * `mapConfig.ownsChrome` — the framework's own {@see window.WoodevPickupPanels} shell,
	 * wired to the provider BOTH ways (see the file docblock's "THIS FILE, NOT THE PROVIDER,
	 * NOW OWNS FETCHING" and the four `woodev_pickup_*` events sections). Tracks, across any
	 * number of retries within this one session, whether a non-empty point set has EVER been
	 * drawn (`hasDrawnPoints`) — see the file docblock's "NON-DESTRUCTIVE DEGRADATION" section
	 * for why that gates `showError()`/`showEmpty()` (nothing drawn yet, replacing the body is
	 * fine) against `showNotice()` (something IS drawn; only a non-destructive banner is
	 * acceptable).
	 *
	 * `panels` is constructed ONCE here, never inside {@see start} — a map-provider retry has
	 * nothing to do with the list/card chrome around it (see the file docblock). `provider`,
	 * `groupsByKey`, `lastAddresses`, `currentTypeFilter` and `lastBbox` are all session-level
	 * state closed over by every function below, including the ones wired onto `panels`, so a
	 * retry's fresh `provider` is always the one those panel handlers act on — no stale
	 * reference to a destroyed instance is possible.
	 *
	 * @param {Object}      config
	 * @param {HTMLElement} triggerEl element focus returns to on close.
	 * @returns {{modal: Object, refresh: Function, destroy: Function}}
	 */
	function openSession( config, triggerEl ) {
		var Modal = window.WoodevModal;
		var modal = new Modal( {
			modalId: PICKUP_MODAL_ID,
			title: text( config, 'modalTitle' ),
			closeLabel: text( config, 'close' ),
			retryLabel: text( config, 'retry' ),
			returnFocusTo: triggerEl,
		} );

		modal.open();

		var providers = window.WoodevPickupMapProviders || {};
		var ProviderCtor = config && providers[ config.provider ];
		var noopRefresh = function() { return Promise.resolve(); };

		if ( 'function' !== typeof ProviderCtor ) {
			modal.showError( text( config, 'error' ) );

			return { modal: modal, refresh: noopRefresh, destroy: function() { modal.destroy(); } };
		}

		var DataSourceFactory = window.WoodevPickupDataSource;

		if ( 'function' !== typeof DataSourceFactory ) {
			modal.showError( text( config, 'error' ) );

			return { modal: modal, refresh: noopRefresh, destroy: function() { modal.destroy(); } };
		}

		var realDataSource = DataSourceFactory( { restRoot: config.restRoot, nonce: config.nonce } );

		// Resolved fresh from `window` on every open, exactly like ProviderCtor/DataSourceFactory
		// above — never a module-load-time constant — so a test can swap it, and so a real page
		// (where Pickup_Handler::enqueue_assets() now declares `woodev-pickup-panels` a hard
		// dependency of this script — see that method's own "LOAD ORDER" note) always sees it set.
		var PanelsCtor = window.WoodevPickupPanels;

		/** @type {boolean} true when the active provider owns the WHOLE container — see D-3. */
		var ownsChrome = !! ( config.mapConfig && config.mapConfig.ownsChrome );

		/** @type {boolean} has a non-empty point set EVER been drawn this session? */
		var hasDrawnPoints = false;

		/** @type {boolean} true once this session has been torn down — guards every async
		 *  continuation below against acting on a dead session (a fetch/init resolving after
		 *  Escape/backdrop close, or after {@see refresh} is called post-close). */
		var destroyed = false;

		/** @type {Object|null} the CURRENT live provider instance — reassigned on every (re-)start(). */
		var provider = null;

		/** @type {Object|null} the framework's own panels shell — null when `ownsChrome`
		 *  (constructed at most ONCE per session; see the docblock above). */
		var panels = null;

		/** @type {Object.<string, Object>} the last full-fetch groups, by key — resolves a
		 *  provider event's bare key/point-id back to the group object the panels need. */
		var groupsByKey = {};

		/** @type {Array} the address suggestions from the LAST `searchResults` event — what
		 *  `searchAddressPicked( index )` indexes into. */
		var lastAddresses = [];

		/** @type {Array|null} the currently selected type-filter codes, or null for "all". */
		var currentTypeFilter = null;

		/** @type {Array|null} the last viewport bbox reported via `boundsChange` (`strategy:
		 *  'viewport'` only) — what a type-filter change or {@see refresh} re-fetches against. */
		var lastBbox = null;

		/**
		 * Shows a message in whichever degradation state is appropriate: a
		 * dismissible notice (nothing lost) once a set has been drawn, otherwise
		 * the destructive whole-body state (there is nothing yet to lose) — an
		 * empty result never gets a retry control (see `showEmpty()`'s own
		 * docblock: there is nothing to retry, only a different search to try).
		 *
		 * @param {string}        message
		 * @param {Function|null} onRetry
		 * @returns {void}
		 */
		function degrade( message, onRetry ) {
			if ( hasDrawnPoints ) {
				modal.showNotice( message, onRetry || undefined );

				return;
			}

			if ( onRetry ) {
				modal.showError( message, onRetry );
			} else {
				modal.showEmpty( message );
			}
		}

		/**
		 * The degrade path for a `fetchAndSetPoints()` outcome specifically — see that
		 * function's own call sites. A dataSource fetch failing or coming back empty NEVER
		 * implies the map/provider itself is broken: once `panels` exist (`!ownsChrome`), the
		 * customer can still pan/search/filter — the map canvas and the framework's own list/
		 * search/filter chrome both stay fully live regardless of what one fetch returned. The
		 * destructive `showError()`/`showEmpty()` path (via {@see degrade}) would replace
		 * `modal.getContainer()`'s WHOLE body — which is where `panels`' own DOM root ALSO
		 * lives (see the docblock above) — wiping that chrome out from under the customer for
		 * no reason a dataSource hiccup justifies. This is therefore ALWAYS a non-destructive
		 * `showNotice()` once panels exist; only a genuine PROVIDER-level `error` (the map/embed
		 * itself failing — nothing at all is usable then) still goes through {@see degrade}'s
		 * `hasDrawnPoints`-gated escalation. With no panels (`ownsChrome`), this never runs at
		 * all — `fetchAndSetPoints` is never called for that branch — so the fallback to
		 * {@see degrade} below is defensive only.
		 *
		 * @param {string}        message
		 * @param {Function|null} onRetry
		 * @returns {void}
		 */
		function degradeFetch( message, onRetry ) {
			if ( panels ) {
				modal.showNotice( message, onRetry || undefined );

				return;
			}

			degrade( message, onRetry );
		}

		/**
		 * Finds the group (from the last full fetch) that owns point `pointId`, or null.
		 * Backs `searchPointPicked` — a list/search click only ever hands back a bare point
		 * id, never the group it belongs to.
		 *
		 * @param {string|number} pointId
		 * @returns {Object|null}
		 */
		function findGroupByPointId( pointId ) {
			var key;

			for ( key in groupsByKey ) {
				if ( Object.prototype.hasOwnProperty.call( groupsByKey, key )
					&& groupsByKey[ key ].points.some( function( point ) {
						return String( point.id ) === String( pointId );
					} )
				) {
					return groupsByKey[ key ];
				}
			}

			return null;
		}

		/**
		 * The ONE place this session ever calls `dataSource.fetchPoints()` — see the file
		 * docblock's "THIS FILE, NOT THE PROVIDER, NOW OWNS FETCHING" section. Groups the
		 * result, hands it to the provider, tells the panels which types are now known, fires
		 * `woodev_pickup_points_loaded`, and degrades on an empty/failed result — every caller
		 * below (`start()`'s initial load, a `boundsChange`/type-filter refetch, {@see refresh})
		 * goes through this instead of re-implementing any part of that sequence. Never called
		 * when `ownsChrome` (that branch never fetches at all — the embed loads its own points).
		 *
		 * @param {Object} query passed straight to `dataSource.fetchPoints()`.
		 * @returns {Promise<Array>} the built groups, or a rejection already fully handled
		 *                           (shown to the customer) by this function itself.
		 */
		function fetchAndSetPoints( query ) {
			return realDataSource.fetchPoints( query ).then(
				function( points ) {
					if ( destroyed ) {
						return points;
					}

					var groups = geo.groupByPosition( points );
					var byKey = {};

					groups.forEach( function( group ) {
						byKey[ group.key ] = group;
					} );
					groupsByKey = byKey;

					provider.setPoints( groups );

					if ( panels ) {
						panels.setTypes( extractTypes( points ) );
					}

					fireDocumentEvent( EVENT_POINTS_LOADED, {
						fieldId: config.fieldId,
						count: points.length,
						strategy: config.strategy,
					} );

					if ( points.length > 0 ) {
						hasDrawnPoints = true;
					} else {
						degradeFetch( text( config, 'noResults' ), null );
					}

					return points;
				},
				function( reason ) {
					if ( ! destroyed ) {
						degradeFetch( errorMessage( config, reason ), start );
					}

					return Promise.reject( reason );
				}
			);
		}

		/**
		 * Applies a selection regardless of WHICH side reported it (the panels' card CTA under
		 * `!ownsChrome`, or the provider's own `select` under `ownsChrome` — an embed reports
		 * its own selection directly, see `map-provider-embedded.js`): writes the field
		 * (§8/DOM), re-syncs the trigger button's label, fires `woodev_pickup_point_selected`,
		 * then closes the modal with reason `'select'` — the fourth close reason the modal
		 * already supports (D-14) — and only tears the session down when the close actually
		 * took (a `before_close` listener COULD veto it; `closeSession` must not run against a
		 * modal that is still open).
		 *
		 * @param {Object} point
		 * @returns {void}
		 */
		function handleSelection( point ) {
			applySelection( config, point );
			syncTriggerLabel( config );

			fireDocumentEvent( EVENT_POINT_SELECTED, { fieldId: config.fieldId, point: point } );

			if ( modal.close( 'select' ) ) {
				closeSession( config.fieldId );
			}
		}

		if ( ! ownsChrome ) {
			panels = new PanelsCtor( modal.getContainer(), buildPanelsConfig( config ) );
			panels.render();

			var alreadySelected = fieldValue( config.fieldId );

			if ( alreadySelected ) {
				panels.setSelectedId( alreadySelected );
			}

			panels.on( 'select', handleSelection );

			panels.on( 'typeFilterChange', function( codes ) {
				currentTypeFilter = codes;

				if ( 'bulk' === config.strategy ) {
					provider.setTypeFilter( codes );

					return;
				}

				// viewport: a client-side filter would show stale points outside the current
				// bbox — refetch with the SAME bbox and the new types instead (see the file
				// docblock's judgement-call note on getting this backwards).
				if ( lastBbox ) {
					fetchAndSetPoints( { bounds: lastBbox, types: codes } ).catch( function() {} );
				}
			} );

			panels.on( 'listToggle', function( state ) {
				if ( provider && 'function' === typeof provider.setMargin ) {
					provider.setMargin( state.open, state.width );
				}
			} );

			panels.on( 'searchAddressPicked', function( index ) {
				var address = lastAddresses[ index ];

				if ( address && 'string' === typeof address.displayName
					&& provider && 'function' === typeof provider.resolveAddress
				) {
					provider.resolveAddress( address.displayName );
				}
			} );

			panels.on( 'searchPointPicked', function( pointId ) {
				var group = findGroupByPointId( pointId );

				if ( ! group ) {
					return;
				}

				if ( provider && 'function' === typeof provider.focusGroup ) {
					provider.focusGroup( group.key );
				}

				panels.openCard( group, pointId );
			} );

			// showNearestRequested (extra wiring, D-6): the "show it anyway" button on the
			// panels' own "nothing nearby" state — `info.key` identifies the nearest group (see
			// map-provider-yandex.js's own `focusAddress()`).
			panels.on( 'showNearestRequested', function( info ) {
				var group = info && info.key ? groupsByKey[ info.key ] : null;

				if ( ! group ) {
					return;
				}

				if ( provider && 'function' === typeof provider.focusGroup ) {
					provider.focusGroup( group.key );
				}

				panels.openCard( group );
			} );

			// anchorCleared (extra wiring, D-6): the panels' own reset control calls
			// `setAnchor( null )` internally, which now emits this — see pickup-panels.js's own
			// docblock. The provider is the sole owner of BOTH the "your address" pin and the
			// searchResults state (`clearAddress()` drops both in one call) — see
			// map-provider-yandex.js's own docblock on why THIS file only has to make ONE call,
			// never track the pin itself.
			panels.on( 'anchorCleared', function() {
				if ( provider && 'function' === typeof provider.clearAddress ) {
					provider.clearAddress();
				}
			} );
		}

		/**
		 * Destroys the current provider (when one exists) and constructs + wires + `init()`s a
		 * fresh one. NEVER re-`init()`s the same instance — see the file docblock. Re-wires
		 * EVERY provider-side event fresh (the OLD instance's handlers die with it), including
		 * the `!ownsChrome`-only ones that call into `panels` — `panels` itself is untouched by
		 * a retry (constructed once, see the docblock above).
		 *
		 * @returns {void}
		 */
		function start() {
			if ( provider && 'function' === typeof provider.destroy ) {
				provider.destroy();
			}

			provider = new ProviderCtor();
			lastBbox = null;

			provider.on( 'select', handleSelection );

			provider.on( 'error', function( reason ) {
				// A provider-level error breaks the WHOLE map — the framework's own error
				// reporter needs to know, not just the customer-facing degrade UI below (see
				// the file docblock's note on this event).
				fireDocumentEvent( EVENT_ERROR, {
					fieldId: config.fieldId,
					code: reason && reason.code,
					message: reason && reason.message,
				} );

				degrade( errorMessage( config, reason ), start );
			} );

			if ( ! ownsChrome ) {
				provider.on( 'pointClick', function( key ) {
					var group = groupsByKey[ key ];

					if ( group ) {
						panels.openCard( group );
					}
				} );

				provider.on( 'visibleChange', function( keys ) {
					var groups = ( keys || [] )
						.map( function( key ) { return groupsByKey[ key ]; } )
						.filter( function( group ) { return !! group; } );

					panels.setVisible( groups );
				} );

				provider.on( 'boundsChange', function( bbox ) {
					lastBbox = bbox;
					fetchAndSetPoints( { bounds: bbox, types: currentTypeFilter } ).catch( function() {} );
				} );

				provider.on( 'nothingNearby', function( info ) {
					panels.showNothingNearby( info );
				} );

				provider.on( 'bboxTooWide', function() {
					// A too-wide bbox is a normal, transient viewport state — not an error, and
					// not something the destructive path (via degrade()) may ever answer with:
					// wiping the map/panels would destroy the very thing the "zoom in" message
					// is asking the customer to use. See degradeFetch()'s own docblock for the
					// identical shared-container reasoning.
					degradeFetch( text( config, 'zoomIn' ), null );
				} );

				provider.on( 'searchResults', function( results ) {
					lastAddresses = ( results && results.addresses ) || [];
					panels.renderSearchResults( results );
				} );

				// addressFocused: the provider's own confirmation that the "your address" pin
				// just dropped (see map-provider-yandex.js's own docblock on this event) — the
				// panels' distance anchor and `nearestTo` header move to the SAME address, so
				// the sidebar sorts from where the customer searched, not the map centre
				// (D-6). Fires whether or not any group turned out to be near it; the
				// `nothingNearby` state (wired above) is a SEPARATE, list-body-level concern.
				provider.on( 'addressFocused', function( info ) {
					panels.setAnchor( info.latLng, info.label );
				} );
			}

			var initResult = provider.init( modal.getContainer(), buildProviderConfig( config ), realDataSource );

			Promise.resolve( initResult ).then( function() {
				if ( destroyed ) {
					return;
				}

				fireDocumentEvent( EVENT_MAP_READY, { fieldId: config.fieldId, provider: config.provider } );

				// bulk fetches once, right here; viewport waits for the provider's own
				// boundsChange (wired above) — see the file docblock.
				if ( ! ownsChrome && 'bulk' === config.strategy ) {
					fetchAndSetPoints( { types: currentTypeFilter } ).catch( function() {} );
				}
			} );
		}

		/**
		 * Re-runs whatever fetch the CURRENT strategy/viewport/type-filter state describes —
		 * see the file docblock. A no-op once `ownsChrome` (nothing here ever fetches) or once
		 * the session is destroyed; otherwise always returns a settled-or-settling promise,
		 * never throws.
		 *
		 * @returns {Promise<void>}
		 */
		function refresh() {
			if ( destroyed || ownsChrome ) {
				return Promise.resolve();
			}

			if ( 'bulk' === config.strategy ) {
				return fetchAndSetPoints( { types: currentTypeFilter } ).catch( function() {} );
			}

			if ( lastBbox ) {
				return fetchAndSetPoints( { bounds: lastBbox, types: currentTypeFilter } ).catch( function() {} );
			}

			return Promise.resolve();
		}

		start();

		return {
			modal: modal,
			refresh: refresh,
			destroy: function() {
				destroyed = true;

				if ( provider && 'function' === typeof provider.destroy ) {
					provider.destroy();
				}

				modal.destroy();
			},
		};
	}

	/**
	 * Mounts one trigger button into one config's §8 anchor, wiring its click
	 * handler. Idempotent — a slot that already holds a `TRIGGER_CLASS` button
	 * is left untouched, so this is safe to call on every `mountAll()` pass
	 * without ever attaching a second click listener to the same button (which
	 * would open two concurrent sessions from a single click).
	 *
	 * At most one session is ever open per field id: a click ALWAYS tears down
	 * whatever session {@see sessions} currently tracks for this field (a no-op
	 * the first time, and a harmless no-op too when that session was already
	 * closed by the user via Escape/backdrop, or orphaned by §8 recreating the
	 * anchor — see the file docblock) before opening a fresh one.
	 *
	 * @param {Object} config
	 * @returns {void}
	 */
	function mountOne( config ) {
		var slot = document.querySelector( '[data-woodev-pickup-slot="' + config.fieldId + '"]' );

		if ( ! slot || slot.querySelector( '.' + TRIGGER_CLASS ) ) {
			return;
		}

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'button ' + TRIGGER_CLASS;

		button.addEventListener( 'click', function( event ) {
			event.preventDefault();
			closeSession( config.fieldId );
			sessions[ config.fieldId ] = openSession( config, button );
		} );

		slot.appendChild( button );

		// A re-mount after an earlier selection (a full checkout reload, or §8 recreating
		// the anchor mid-session) must read `i18n.triggerChange`, not always `i18n.trigger` —
		// see {@see syncTriggerLabel}.
		syncTriggerLabel( config );
	}

	/**
	 * Mounts every currently-registered config's trigger — the single entry
	 * point both the deferred `updated_checkout` handler and the initial boot
	 * call below use.
	 *
	 * @returns {void}
	 */
	function mountAll() {
		collectConfigs().forEach( mountOne );
	}

	/**
	 * Returns the currently open session for a field id, or null when none is open — the
	 * external hook onto {@see refresh()} (Task 20's own docblock: e.g. a payment-method
	 * change elsewhere on the page) without that caller needing to know anything about
	 * `sessions` being module-private.
	 *
	 * @param {string} fieldId
	 * @returns {{modal: Object, refresh: Function, destroy: Function}|null}
	 */
	function getSession( fieldId ) {
		return sessions[ fieldId ] || null;
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	onCheckoutUpdated( function() {
		window.setTimeout( mountAll, MOUNT_DEFER_MS );
	} );

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
