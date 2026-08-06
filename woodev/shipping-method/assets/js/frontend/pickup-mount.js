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
 * `setPoints( groups, options )` hands it and reports camera/selection events, but
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
 * THE SIX `woodev_pickup_*` EVENTS are native, bubbling `CustomEvent`s fired
 * on `document.body` — exactly like `woodev-modal.js`'s own `woodev_modal_*`
 * events (see that file's docblock for why `jQuery.trigger()` would be
 * invisible to a plain `addEventListener`, and this file's own docblock above
 * on `updated_checkout` for the identical asymmetry): `woodev_pickup_map_ready`
 * (`{ fieldId, provider }` — D-14 names both: `provider` is the ACTIVE provider's
 * id, the only way an integrator hooking a specific map can tell which one just
 * initialised) once a session's `init()` resolves, `woodev_pickup_points_loaded` after
 * EVERY successful fetch this file makes (the initial bulk load, every
 * viewport refetch, every type-filter refetch, every {@see refresh()} call —
 * never just the first), `woodev_pickup_point_select_requested` when a
 * confirmation leaves for the server and `woodev_pickup_point_select_resolved`
 * when its answer lands (see {@see handleSelection}/{@see finishSelection} —
 * BOTH fire on a refusal and on a transport failure too, not only on the happy
 * path), `woodev_pickup_point_selected` once an ACCEPTED point has actually
 * been applied, and `woodev_pickup_error` specifically for a PROVIDER-level
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
 * with the rest of the modal body). Exactly TWO handlers are bound to
 * long-lived, session-independent targets, and they are exactly the two
 * `destroy()` unbinds by hand — nothing else takes them away:
 * `woodev_modal_closed` on `document.body`, which the staleness guard needs
 * because a dismissed dialog is not otherwise reported to this file at all
 * (see {@see handleModalClosed}); and a jQuery `updated_checkout` waiter on
 * the same node, bound only while a post-selection checkout refresh is in
 * flight and holding the card's busy state until it settles (see
 * {@see refreshCheckout}/{@see dropRefreshWaiter} — `one()` self-cleans ONLY
 * if the event actually fires, which a failed checkout ajax, or a session torn
 * down mid-round-trip, is free not to do). Everything else hangs off
 * `provider`/`panels`, both torn down (or dereferenced) together. So the
 * existing "two clicks never leave two providers alive" guarantee extends to
 * the panels, to every event wired through them, and to both of those
 * listeners, unchanged.
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
	 * The six `document.body` `CustomEvent` names this file fires — see the file
	 * docblock's own section on them. Native, bubbling events, never a jQuery
	 * `.trigger()` — see {@see fireDocumentEvent}.
	 *
	 * @type {string}
	 */
	var EVENT_MAP_READY = 'woodev_pickup_map_ready';
	var EVENT_POINTS_LOADED = 'woodev_pickup_points_loaded';
	var EVENT_POINT_SELECTED = 'woodev_pickup_point_selected';
	var EVENT_ERROR = 'woodev_pickup_error';

	/*
	 * The two confirmation events (2026-08-06 spec D-2). OBSERVATIONAL: the framework neither
	 * waits for a listener nor lets one veto — the veto path is `woodev_modal_before_close`,
	 * which already exists. `_resolved` is what a plugin listens to in order to write the
	 * chosen point's street/house/postcode into the checkout address fields when the merchant
	 * enabled that (spec D-14) — the framework never does it and offers no switch for it.
	 *
	 * `_resolved` means "the server answered AND the answer still applies", NOT "the point was
	 * applied": it fires on all three outcomes the customer can still see (accepted, refused,
	 * request failed), before this file has written anything, and carries the outcome —
	 * `result` for a verdict, `error` for a transport failure, exactly one of them non-null. A
	 * listener that only cares about an ACCEPTED point, applied, wants
	 * `woodev_pickup_point_selected` instead, which still fires exactly where it always did:
	 * after the field is written and before the modal is asked to close.
	 *
	 * THERE IS A FOURTH, SILENT OUTCOME, AND `_requested` IS THEREFORE NOT ALWAYS PAIRED: an
	 * answer discarded by the staleness guard (spec D-9 — the card moved on, the dialog was
	 * dismissed, the session was torn down) fires NOTHING. `_requested` has already gone out
	 * for that confirmation, so anything pairing the two — analytics, a plugin tracking
	 * in-flight confirmations — must treat a `_requested` with no `_resolved` as a normal,
	 * expected state and not wait on it forever. Deliberate, and the alternative is worse: a
	 * plugin acting on `_resolved` writes the chosen point's address into the checkout fields
	 * (D-14), and doing that for a point the framework has just thrown away would leave the
	 * customer with an address for somewhere they are not collecting from.
	 */
	var EVENT_SELECT_REQUESTED = 'woodev_pickup_point_select_requested';
	var EVENT_SELECT_RESOLVED  = 'woodev_pickup_point_select_resolved';

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

		/*
		 * Two codes, one message. `rest_cookie_invalid_nonce` is WordPress's own, raised by
		 * `rest_cookie_check_errors()` BEFORE any permission_callback runs — which is why a
		 * route declared `__return_true` can still 403 (#157). `woodev_pickup_invalid_nonce`
		 * is ours, from the select route's permission callback, for the cases WordPress lets
		 * through (no nonce header at all).
		 */
		rest_cookie_invalid_nonce: 'stalePage',
		woodev_pickup_invalid_nonce: 'stalePage',
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
	 * The i18n KEY counterpart to {@see errorMessage} above — used by callers that hand a key to
	 * `panels.showMessage()` (Task 17, spec V-5) rather than a pre-resolved string. `showMessage()`
	 * looks the string up itself (same as every other label this file reads), so resolving a KEY
	 * here — not text — is what lets a plugin's `woodev_pickup_map_i18n` override apply to a
	 * mapped error exactly the same way it applies to the generic one. Falls back to the generic
	 * `'error'` key in exactly the same two cases `errorMessage()` falls back to its generic
	 * string: an unmapped code, or a mapped key a plugin's filter blanked out.
	 *
	 * @param {Object}      config
	 * @param {Object|null} reason `{ status, code, message }`, or null/undefined.
	 * @returns {string}
	 */
	function errorMessageKey( config, reason ) {
		var code = reason && reason.code;
		var key = code ? ERROR_MESSAGE_KEYS[ code ] : null;

		return ( key && text( config, key ) ) ? key : 'error';
	}

	/**
	 * Reads a three-state flag: the domain's answer when it gave one, the plugin's configured
	 * default when it did not.
	 *
	 * NEVER `||` — an explicit `false` from the domain is a DECISION ("do not close this one"),
	 * and `||` would silently convert it into a `true` default, handing control straight back
	 * to the setting the domain had just overridden. Only `null`/`undefined` mean "the domain
	 * said nothing"; the select route serialises an unspoken flag as JSON `null` and this
	 * treats both spellings identically, so the server never has to prune keys (spec D-5, §4.2
	 * — the identical trap fixed in s40's fail-closed parity work).
	 *
	 * Written as an explicit null/undefined test rather than `??`: these files ship to a
	 * shopper's browser VERBATIM (no bundler, no transpile — see the file docblock), and an
	 * unsupported operator is a parse error that kills the whole script, not a degraded
	 * behaviour. The semantics below are `??`'s, exactly.
	 *
	 * @param {*}       spoken   the response's own value: bool, null, or undefined.
	 * @param {boolean} fallback the plugin's configured default.
	 * @returns {boolean}
	 */
	function resolveFlag( spoken, fallback ) {
		if ( undefined === spoken || null === spoken ) {
			return true === fallback;
		}

		return true === spoken;
	}

	/**
	 * The i18n key for a failed CONFIRMATION — the stale-page message when the failure was a
	 * nonce one, the confirmation-specific failure otherwise.
	 *
	 * Deliberately not {@see errorMessageKey}: that one falls back to the generic `error`
	 * string, which is written for a failed points FETCH ("не удалось загрузить пункты") and
	 * would be actively misleading under a button the customer just pressed to confirm one.
	 *
	 * @param {Object}      config
	 * @param {Object|null} reason `{ status, code, message }`.
	 * @returns {string}
	 */
	function selectionErrorKey( config, reason ) {
		return 'stalePage' === errorMessageKey( config, reason ) ? 'stalePage' : 'selectFailed';
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
	 * `searchLayoutEl` (Task 12, spec V-6) is the ONE argument here that is not read straight off
	 * `config` — it is a DOM element `openSession()` builds ONCE, via `panels.buildSearchLayout()`
	 * (null under `ownsChrome`, or when the plugin disabled search), and hands in explicitly.
	 * Building it is the panels' job (D-3: no map-library file renders point information); handing
	 * the already-built ELEMENT through this flat merge — rather than the map provider reaching
	 * into `panels` itself — is what keeps `map-provider-yandex.js` ignorant of the panels'
	 * existence, exactly like every other plugin-author-facing value this function forwards.
	 *
	 * @param {Object}           config         the full mount config (`window.woodev_pickup_config_*`).
	 * @param {HTMLElement|null} searchLayoutEl see above.
	 * @returns {Object}
	 */
	function buildProviderConfig( config, searchLayoutEl ) {
		// Everything the provider reads off the config it is handed. `mapConfig` carries only
		// what the ACTIVE PROVIDER contributed PHP-side (`scriptUrl`, `ns`, `hasApiKey`, `lang`,
		// `layers`, `copyrights`); the four keys below sit at the TOP level of the mount config
		// — `accentColor` deliberately so (D-15: the checkout trigger button needs it too, and
		// "brand accent" is not a ymaps concept) — and must be forwarded explicitly.
		//
		// Omitting them was silent and total: with no `defaultLocation` the map opened at its
		// technical [0,0]/zoom-2 placeholder in the Atlantic instead of the buyer's city, and
		// because ObjectManager creates overlays ONLY for visible objects, there were no markers
		// — and the sidebar, driven by the same bounds test, was empty too. With no `pointIcons`
		// every marker that did render was an empty box.
		return shallowMerge( config.mapConfig || {}, {
			strategy: config.strategy,
			i18n: config.i18n,
			locality: resolveLocality( config ),
			defaultLocation: config.defaultLocation,
			pointIcons: config.pointIcons,
			accentColor: config.accentColor,
			searchNearestCount: config.searchNearestCount,
			searchLayoutEl: searchLayoutEl || null,
		} );
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
	 * Both the provider config AND the bulk points query read through here, and that is the
	 * point: the bulk strategy addresses its query by locality, so the two answers must come
	 * from one place. They did not once — the bulk fetch omitted the locality entirely and the
	 * server, given a query naming neither a locality nor a bbox, correctly returned nothing.
	 * The customer saw an empty map in a city full of points, with no error anywhere.
	 *
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {string}
	 */
	function resolveLocality( config ) {
		var cityField = document.getElementById( resolveAddressTarget( config ) + '_city' );

		return cityField && 'string' === typeof cityField.value ? cityField.value : '';
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
		// `config.modal` sizes the dialog before any content exists (spec V-1) — the PHP
		// handler emits it ({@see Pickup_Handler::get_js_config()}); this file only forwards
		// it, exactly like every other config value it never invents a default for.
		var modalSize = ( config && config.modal ) || {};

		var Modal = window.WoodevModal;
		var modal = new Modal( {
			modalId: PICKUP_MODAL_ID,
			title: text( config, 'modalTitle' ),
			closeLabel: text( config, 'close' ),
			retryLabel: text( config, 'retry' ),
			returnFocusTo: triggerEl,
			width: modalSize.width,
			bodyHeight: modalSize.bodyHeight,
		} );

		modal.open();
		modal.showLoading( text( config, 'loading' ) );

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

		var realDataSource = DataSourceFactory( {
			restRoot: config.restRoot,
			nonce: function() {
				return currentNonce( config );
			},
		} );

		// Resolved fresh from `window` on every open, exactly like ProviderCtor/DataSourceFactory
		// above — never a module-load-time constant — so a test can swap it, and so a real page
		// (where Pickup_Handler::enqueue_assets() now declares `woodev-pickup-panels` a hard
		// dependency of this script — see that method's own "LOAD ORDER" note) always sees it set.
		var PanelsCtor = window.WoodevPickupPanels;

		/** @type {boolean} true when the active provider owns the WHOLE container — see D-3. */
		var ownsChrome = !! ( config.mapConfig && config.mapConfig.ownsChrome );

		/** @type {boolean} has a non-empty point set EVER been drawn this session? */
		var hasDrawnPoints = false;

		/** @type {boolean} true once this session has made its ONE attempt to restore a
		 *  previously chosen point ({@see restoreSelection}) — set on the FIRST points-drawn
		 *  continuation and never reset (not even by a retry's fresh `start()`). Without this
		 *  gate, calling {@see restoreSelection} from inside {@see fetchAndSetPoints} would run
		 *  it on EVERY successful fetch — under `strategy: 'viewport'` that is every pan
		 *  (`boundsChange`), every type-filter change, and every {@see refresh()} call, not just
		 *  the session's opening draw. That would re-open the sidebar and yank the camera back
		 *  to the restored point after every one of them, fighting a customer who panned away on
		 *  purpose. See Task 12's discrepancy (a). */
		var selectionRestoreAttempted = false;

		/** @type {boolean} true once this session has been torn down — guards every async
		 *  continuation below against acting on a dead session (a fetch/init resolving after
		 *  Escape/backdrop close, or after {@see refresh} is called post-close). */
		var destroyed = false;

		/** @type {Object|null} the CURRENT live provider instance — reassigned on every (re-)start(). */
		var provider = null;

		/** @type {Object|null} the framework's own panels shell — null when `ownsChrome`
		 *  (constructed at most ONCE per session; see the docblock above). */
		var panels = null;

		/** @type {HTMLElement|null} the `SearchControl` layout element (Task 12, spec V-6),
		 *  built ONCE from `panels.buildSearchLayout()` — see the docblock above and
		 *  `buildProviderConfig()`'s own note on why THIS file builds it, never the provider.
		 *  Null under `ownsChrome`, or when the plugin disabled search. Reused across every
		 *  retry within this session (a retry rebuilds the map provider, never the panels — the
		 *  layout is the panels' own DOM and a fresh provider simply mounts it again). */
		var searchLayoutEl = null;

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

		/** @type {string|null} the point id a confirmation is currently in flight for — the
		 *  staleness guard's whole state (spec D-9). Set when the request leaves, cleared when
		 *  its answer is applied AND whenever the thing it was about stops being current (the
		 *  card moving to another point; see the `cardOpened` listener). {@see finishSelection}
		 *  applies an answer only while this still names the point that answer is about. */
		var pendingSelectionId = null;

		/** @type {Function|null} the pending `updated_checkout` handler {@see refreshCheckout}
		 *  bound through jQuery, held only so {@see dropRefreshWaiter} can take it off again —
		 *  the second (and last) long-lived binding a session makes; see the file docblock. */
		var refreshWaiter = null;

		/** @type {boolean} Task 16 (spec V-4 stage 2/3): has THIS start() cycle's busy overlay
		 *  already been cleared? Reset at the top of every {@see start} call (initial open AND
		 *  every retry — each re-runs the FULL "map drawn → points in flight → points in" sequence —
		 *  see the file docblock's "RETRY NEVER RE-init()S" section), and flipped true by
		 *  {@see clearInitialBusy} the first time this cycle's opening fetch (or the "bbox too
		 *  wide" terminal state, which never fetches at all) settles — never again after that, so a
		 *  LATER refetch (a type-filter change, `refresh()`, a subsequent viewport pan) never
		 *  re-shows or re-hides the overlay a customer has already moved past. */
		var busyClearedThisStart = false;

		/**
		 * Clears the stage-wide busy overlay {@see start} opened once the map was drawn — the
		 * stage 2 → stage 3 transition (spec V-4). Idempotent per {@see start} cycle via
		 * `busyClearedThisStart` (see that flag's own docblock), so calling it from more than one
		 * settle path (a successful fetch, a failed one, and the `bboxTooWide` terminal state all
		 * call it — see their own call sites below) never toggles the overlay back on after a LATER
		 * fetch. A no-op under `ownsChrome` (no `panels` ever exists there) and once the session is
		 * destroyed (the panels' own DOM may already be gone).
		 *
		 * @returns {void}
		 */
		function clearInitialBusy() {
			if ( busyClearedThisStart ) {
				return;
			}

			busyClearedThisStart = true;

			if ( panels && ! destroyed ) {
				panels.setBusy( false );
			}
		}

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
		 * The degrade path for a `fetchAndSetPoints()`/`bboxTooWide` outcome specifically (Task
		 * 17, spec V-5) — see those call sites below. A dataSource fetch failing, coming back
		 * empty, or a bbox too wide to fetch at all NEVER implies the map/provider itself is
		 * broken: once `panels` exist (`!ownsChrome`), the customer can still pan/search/filter
		 * regardless of what one fetch returned — the map canvas and the framework's own list/
		 * search/filter chrome both stay fully live. `panels.showMessage()` is non-destructive BY
		 * CONSTRUCTION (a small card over the map, never a replacement for the interface — see
		 * that method's own docblock), so unlike the OLD `modal.showNotice()`-based version of
		 * this function, there is no `hasDrawnPoints`-gated choice to make any more: the card
		 * shows every time, drawn content or not. Only a genuine PROVIDER-level `error` (the map/
		 * embed itself failing — nothing at all is usable then) still goes through {@see degrade}'s
		 * destructive/non-destructive split. With no panels (`ownsChrome`), neither
		 * `fetchAndSetPoints` nor the `bboxTooWide` handler ever runs (see their own docblocks) —
		 * the fallback to {@see degrade} below is defensive only.
		 *
		 * @param {string} key an i18n key `panels.showMessage()` resolves itself.
		 * @returns {void}
		 */
		function showFetchMessage( key ) {
			if ( panels ) {
				panels.showMessage( key );

				return;
			}

			degrade( text( config, key ), null );
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
		 * Restores a previously chosen point once points have actually been drawn (spec D-15).
		 *
		 * Called ONCE per session, gated by `selectionRestoreAttempted` at the single call site
		 * below — see that flag's own docblock for why. It runs from the points-drawn
		 * continuation and not at session open because it needs the drawn groups: the camera
		 * move and the group key only exist once `setPoints()` has actually run. Three of the
		 * four things this has to do are already primitives — `focusGroup()` writes the
		 * marker's own `data-state="active"` as its side effect, `openList()` makes the
		 * sidebar's visibility deterministic, and `setSelectedId()` drives both the CTA label
		 * and the row highlight.
		 *
		 * A point that is no longer in the results restores NOTHING, silently: the map opens in
		 * its ordinary default view and the field is left alone for the checkout-processing
		 * backstop to judge (spec D-15). No fourth empty-state message — the three that exist
		 * (`emptyLocality`/`emptyInView`/`noResults`) are deliberately distinct.
		 *
		 * The group lookup goes through {@see findGroupByPointId} — the SAME helper
		 * `searchPointPicked` and the `cardOpened` listener already use for "I have a point id,
		 * I need its group" — rather than a second, independent implementation. That helper
		 * reads the session's own `groupsByKey` from the closure (already reassigned by the time
		 * this runs — see {@see fetchAndSetPoints}), so this function takes no argument either.
		 *
		 * @returns {void}
		 */
		/**
		 * The group {@see restoreSelection} is ABOUT to focus on this pass, or null when this pass
		 * restores nothing (already attempted, no panels, no stored point, or a stored point that
		 * is not among the drawn groups). Read BEFORE `setPoints()` for one reason only: to tell
		 * the provider to skip its `bulk` camera fit, which would otherwise move the camera to the
		 * whole loaded set a beat before the restore moves it again to one point.
		 *
		 * Two moves there is not merely wasteful. s52's rig pass measured the second one landing
		 * while ymaps was still rebuilding its ObjectManager overlays for the first, which parks
		 * the newly un-clustered marker at ymaps' own off-screen sentinel until some later zoom
		 * change re-lays it out: right camera, right `data-state`, no visible pin. See
		 * `map-provider-yandex.js`'s `setPoints()` docblock for the mechanism.
		 *
		 * The lookup is deliberately NOT passed on to {@see restoreSelection} — that function
		 * stays the single source of truth for what restoring actually does, including the cases
		 * this one returns null for (a stored point missing from the results still updates the
		 * sidebar's selected id).
		 *
		 * @returns {Object|null}
		 */
		function pendingRestoreGroup() {
			if ( selectionRestoreAttempted || ! panels ) {
				return null;
			}

			var selectedId = fieldValue( config.fieldId );

			return selectedId ? findGroupByPointId( selectedId ) : null;
		}

		function restoreSelection() {
			var selectedId = fieldValue( config.fieldId );

			if ( ! selectedId || ! panels ) {
				return;
			}

			panels.setSelectedId( selectedId );

			var group = findGroupByPointId( selectedId );

			if ( ! group ) {
				return;
			}

			panels.openList();

			if ( provider && 'function' === typeof provider.focusGroup ) {
				provider.focusGroup( group.key, { zoom: true } );
			}
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
		/**
		 * Builds the BULK strategy's points query.
		 *
		 * Bulk addresses its query by locality — there is no bbox yet when it runs, and
		 * `Point_Query` refuses a request naming neither. The locality is read LIVE on every
		 * call rather than captured once at open time, so `refresh()` (which exists exactly
		 * because the customer can change things while the map is open) re-reads a city they
		 * edited since.
		 *
		 * @returns {Object}
		 */
		function bulkQuery() {
			return { locality: resolveLocality( config ), types: currentTypeFilter };
		}

		function fetchAndSetPoints( query ) {
			return realDataSource.fetchPoints( query ).then(
				function( points ) {
					// Task 16 (spec V-4): THIS fetch is the one stage 2's overlay was waiting on —
					// win, lose, or empty, the customer gets an answer and the map becomes usable.
					// A no-op past the first settle of this start() cycle (see the flag's own docblock).
					clearInitialBusy();

					if ( destroyed ) {
						return points;
					}

					var groups = geo.groupByPosition( points );
					var byKey = {};

					groups.forEach( function( group ) {
						byKey[ group.key ] = group;
					} );
					groupsByKey = byKey;

					// `{ fit: false }` on the pass that is about to restore a chosen point — the
					// restore's own camera move is the only one that should happen. See
					// {@see pendingRestoreGroup} for why two moves here are actively harmful.
					provider.setPoints( groups, { fit: ! pendingRestoreGroup() } );

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

						// Task 17 (spec V-5): a LATER fetch settling with real points must not leave
						// an earlier empty/error card sitting over a map that has since drawn them.
						if ( panels ) {
							panels.hideMessage();

							// Task 12 (spec D-15): the ONE attempt this session makes to restore a
							// previously chosen point — see `selectionRestoreAttempted`'s own docblock
							// for why this must not run on every points-drawn continuation.
							if ( ! selectionRestoreAttempted ) {
								selectionRestoreAttempted = true;
								restoreSelection();
							}
						}
					} else {
						// `emptyLocality` (a locality genuinely has none) vs `emptyInView` (the
						// current viewport does) — the SAME shared function backs both the bulk
						// strategy's one-shot fetch and the viewport strategy's per-bbox
						// `boundsChange` fetch (see the call sites below), so the key is chosen from
						// `config.strategy`, not hardcoded to either. Distinct from `noResults`,
						// which stays reserved for the search view finding nothing (spec V-5).
						showFetchMessage( 'bulk' === config.strategy ? 'emptyLocality' : 'emptyInView' );
					}

					return points;
				},
				function( reason ) {
					// See the resolve branch above — a failed fetch settles stage 2 exactly like a
					// successful one does; the customer gets the error card, not a map stuck
					// non-interactive forever over a request that will never come back.
					clearInitialBusy();

					if ( ! destroyed ) {
						showFetchMessage( errorMessageKey( config, reason ) );
					}

					return Promise.reject( reason );
				}
			);
		}

		/**
		 * Confirms a selection with the server, then applies whatever the domain decided
		 * ({@see finishSelection}). Entered regardless of WHICH side reported the selection:
		 * the panels' card CTA under `!ownsChrome`, or the provider's own `select` under
		 * `ownsChrome` — an embed reports its own selection directly, see
		 * `map-provider-embedded.js`.
		 *
		 * NOTHING IS APPLIED BEFORE THE SERVER ANSWERS (2026-08-06 spec, D-1): the field, the
		 * trigger label, `woodev_pickup_point_selected` and the close all wait for the round
		 * trip, because the domain may refuse this point for this cart — and a field written
		 * first and retracted afterwards is worse than one written a beat later.
		 *
		 * Under `ownsChrome` (an embedded provider reports its own selection and there is no
		 * card of ours to lock or re-render) the round trip still happens, but every panels
		 * call below is skipped — see the guards.
		 *
		 * A consequence, deliberate: with no card there is no lock, so nothing here stops an
		 * embedded provider reporting a SECOND selection while the first is still in flight —
		 * both round trips would run, and the later answer would win. Debouncing its own
		 * confirm UI is that provider's job, exactly as rendering it is (D-3: the framework
		 * draws no chrome of its own around a provider that already owns the whole picker, and
		 * cannot lock a button it never drew).
		 *
		 * @param {Object} point
		 * @returns {void}
		 */
		function handleSelection( point ) {
			var pointId = String( point && point.id );

			/*
			 * Already confirmed: this is the «Продолжить оформление» click, not a new choice.
			 * Nothing is asked again — the point is accepted, the field already holds it, and
			 * the only thing left is to close (spec D-11). The same is true of a REOPENED
			 * picker showing the previously chosen point, whose CTA reads that way from the
			 * first render.
			 */
			if ( fieldValue( config.fieldId ) === pointId ) {
				if ( modal.close( 'select' ) ) {
					closeSession( config.fieldId );
				}

				return;
			}

			fireDocumentEvent( EVENT_SELECT_REQUESTED, { fieldId: config.fieldId, point: point } );

			if ( panels ) {
				panels.setSelectionBusy( true );
			}

			pendingSelectionId = pointId;

			realDataSource.selectPoint( {
				pointId: pointId,
				fieldId: config.fieldId,
			} ).then(
				function( result ) {
					finishSelection( point, result, null );
				},
				function( reason ) {
					finishSelection( point, null, reason );
				}
			);
		}

		/**
		 * Applies one settled confirmation — success or failure, both land here so the busy
		 * state is released in exactly one place.
		 *
		 * THE STALENESS GUARD. An answer is applied only while it is still about something
		 * current: this session is alive, and the card still shows the point the request was
		 * sent for. Locking the card stops a customer from starting a SECOND confirmation, but
		 * it cannot stop the paths that are not clicks inside it, and spec D-9 requires all of
		 * them covered: the map underneath the lock stays live (a marker click swaps the card
		 * to another point through `pointClick` → `openCard()`); Escape, the backdrop and the
		 * close button dismiss the dialog out from under the request (see
		 * {@see handleModalClosed}); and a fresh click on the checkout trigger tears this whole
		 * session down and opens another. An answer that outlives what it was about is
		 * discarded, never applied to whatever happens to be on screen now (spec D-9/D-10: the
		 * request is not aborted — the server may already have written the point into the WC
		 * session, and aborting would stop us listening without undoing any of that. The client
		 * and the server can therefore end up disagreeing; D-10 accepts that explicitly and
		 * still says to ignore the answer).
		 *
		 * The busy release is deliberately OUTSIDE that guard: it is the caller's obligation on
		 * all three settlement paths — accepted, refused, and discarded-as-stale (see
		 * `Panels.prototype.setSelectionBusy`'s own docblock). Releasing it only on the two
		 * applied paths would leave a card that took a stale answer locked for the rest of the
		 * session, with a dead CTA reading «Проверяем…» over a request that already came back.
		 *
		 * @param {Object}      point
		 * @param {Object|null} result the server's verdict, or null when the request failed.
		 * @param {Object|null} reason the transport failure, or null on success.
		 * @returns {void}
		 */
		function finishSelection( point, result, reason ) {
			var pointId = String( point && point.id );
			var current = ! destroyed && pendingSelectionId === pointId;

			if ( pendingSelectionId === pointId ) {
				pendingSelectionId = null;
			}

			// Not under `current`: see the docblock. Skipped only when there is no card to
			// release — no panels at all (`ownsChrome`), or a session already torn down, whose
			// panels have been destroyed along with their DOM.
			if ( panels && ! destroyed ) {
				panels.setSelectionBusy( false );
			}

			if ( ! current ) {
				return;
			}

			fireDocumentEvent( EVENT_SELECT_RESOLVED, {
				fieldId: config.fieldId,
				point: point,
				result: result,
				error: reason,
			} );

			if ( ! result ) {
				// Transport failure: nothing about the point was refused, so nothing is
				// remembered and the CTA stays alive (spec D-6/D-7).
				if ( panels ) {
					panels.showSelectionError( text( config, selectionErrorKey( config, reason ) ) );
				}

				return;
			}

			if ( ! result.allowed ) {
				if ( panels ) {
					panels.setPointVerdict( pointId, {
						allowed: false,
						reason: 'string' === typeof result.reason ? result.reason : null,
					} );
				}

				return;
			}

			// `result.point` is a CORRECTED point, not a flag — the domain learned something
			// during confirmation that the listing did not know (a refined address, a fixed
			// postcode). Absent means "keep the point you already have", never "clear it".
			var accepted = result.point && 'object' === typeof result.point ? result.point : point;
			var defaults = config.selection || {};

			applySelection( config, accepted );
			syncTriggerLabel( config );

			if ( panels ) {
				panels.setSelectedId( pointId );
			}

			fireDocumentEvent( EVENT_POINT_SELECTED, { fieldId: config.fieldId, point: accepted } );

			// Close BEFORE refresh (spec §5.2): the customer gets immediate feedback and the
			// recalculation runs behind a closed modal. Only tear the session down when the
			// close actually TOOK — a `woodev_modal_before_close` listener may veto it, and
			// `closeSession` must not run against a modal that is still open.
			var closed = false;

			if ( resolveFlag( result.close, defaults.close ) ) {
				closed = modal.close( 'select' );

				if ( closed ) {
					closeSession( config.fieldId );
				}
			}

			if ( resolveFlag( result.refresh_checkout, defaults.refreshCheckout ) ) {
				// `panels` only while the modal is still open. When we have just closed, they
				// are already destroyed with the rest of the session — re-locking a card the
				// customer cannot see, and waiting on an `updated_checkout` to unlock it again,
				// would be work against a dead object for no visible effect.
				refreshCheckout( closed ? null : panels );
			}
		}

		/**
		 * Fires WooCommerce's `update_checkout` and, when a card is still on screen, holds its
		 * busy state until the ajax settles — otherwise «Продолжить оформление» is clickable in
		 * the middle of a totals update (spec §5.2). A no-op without jQuery (WooCommerce's own
		 * event is jQuery-only — see the file docblock's note on the identical asymmetry for
		 * `updated_checkout`).
		 *
		 * @param {Object|null} openPanels the panels to hold busy, or null when nothing is on
		 *                                 screen to hold.
		 * @returns {void}
		 */
		function refreshCheckout( openPanels ) {
			if ( ! window.jQuery ) {
				return;
			}

			if ( openPanels ) {
				openPanels.setSelectionBusy( true );

				// Superseded before it ever fired (WooCommerce answered the previous refresh
				// with an ajax error, say — `updated_checkout` is not guaranteed on every
				// build). Drop it rather than stack a second one on the same target.
				dropRefreshWaiter();

				refreshWaiter = function() {
					refreshWaiter = null;

					// The session can be torn down between `update_checkout` and
					// `updated_checkout` — WooCommerce's round trip is real network time, and a
					// customer is free to dismiss the dialog or re-open the picker during it.
					// `dropRefreshWaiter()` in destroy() normally means this never runs at all;
					// this guard is what covers the one case it cannot (jQuery gone by then).
					if ( ! destroyed ) {
						openPanels.setSelectionBusy( false );
					}
				};

				window.jQuery( document.body ).one( 'updated_checkout', refreshWaiter );
			}

			window.jQuery( document.body ).trigger( 'update_checkout' );
		}

		/**
		 * Unbinds the pending `updated_checkout` waiter, if any.
		 *
		 * A `one()` handler that never fires is not self-cleaning: it stays on `document.body`
		 * holding this closure — and the whole `panels`/DOM graph it captures — alive for the
		 * life of the document. `updated_checkout` firing is NOT guaranteed (a failed checkout
		 * ajax, or a session torn down before the round trip returns), so the binding has to be
		 * dropped by hand.
		 *
		 * @returns {void}
		 */
		function dropRefreshWaiter() {
			if ( refreshWaiter && window.jQuery ) {
				window.jQuery( document.body ).off( 'updated_checkout', refreshWaiter );
			}

			refreshWaiter = null;
		}

		/**
		 * The staleness guard's last three paths (spec D-9 names four: a card moved onto
		 * another point — handled by the `cardOpened` listener below — plus Escape, the
		 * backdrop and the close button, all three of which land HERE).
		 *
		 * None of them is a click inside the card, so the lock cannot intercept any of them,
		 * and none of them tells this file anything on its own: `closeSession()` is NOT called
		 * when the customer dismisses the dialog, so `destroyed` stays false and the session
		 * stays registered until the next trigger click. `woodev_modal_closed` is the one
		 * signal all three share — `WoodevModal.prototype.close()` emits it whatever asked for
		 * the close, ours included.
		 *
		 * Filtered by `modalId` only, since the event carries no reference to the instance that
		 * fired it. Two pickup dialogs open at once is not a reachable state (the dialog is
		 * modal, with a backdrop over the trigger that would open the second), and were it ever
		 * to become one, the failure direction is the safe one: another pickup dialog closing
		 * would discard THIS confirmation's answer, never apply a wrong one.
		 *
		 * Our own successful close reaches this too — harmlessly: {@see finishSelection} clears
		 * the pending id before it ever asks the modal to close.
		 *
		 * @param {CustomEvent} event
		 * @returns {void}
		 */
		function handleModalClosed( event ) {
			if ( ! event.detail || PICKUP_MODAL_ID !== event.detail.modalId ) {
				return;
			}

			pendingSelectionId = null;
		}

		// The ONE `document.body` listener this file's sessions register — see the file
		// docblock's "EVERY LISTENER THIS FILE ATTACHES DIES WITH THE SESSION" section for why
		// that is otherwise avoided, and `destroy()` below for the removal that keeps the
		// guarantee intact.
		document.body.addEventListener( 'woodev_modal_closed', handleModalClosed );

		if ( ! ownsChrome ) {
			panels = new PanelsCtor( modal.getContainer(), buildPanelsConfig( config ) );
			panels.render();

			// Built ONCE here, never inside start() — see searchLayoutEl's own docblock above.
			// null when the plugin disabled search (`config.search === false`); `_buildSearchControl()`
			// then skips building a control at all (Task 12, spec V-6).
			searchLayoutEl = panels.buildSearchLayout();

			// `cardOpened` is still the single funnel every route to a card passes through — a
			// marker, a sidebar row, a search result, "show the nearest" — but, as of the
			// live-review round 2 fix (D6), it no longer treats them identically. Spec V-10
			// ("a marker click and a sidebar row click must behave identically") is OVERRULED:
			// neither reference behaves that way (see the plan's "Reference truth" table) — a
			// marker click pans only (the customer already sees roughly where it is; slamming
			// the camera to max zoom on every tap was the operator's original bug report), every
			// other origin centres AND zooms, since none of those started from a point already
			// visible on screen. `origin` (threaded through `openCard()`/`cardOpened` by every
			// caller below) is what lets this ONE listener still decide per-call instead of
			// forking into several near-identical ones.
			panels.on( 'cardOpened', function( payload ) {
				// The staleness guard's other half (spec D-9). Locking the card stops a second
				// confirmation from STARTING; it does not freeze the map underneath it, where a
				// marker click still routes through `pointClick` → `openCard()` and swaps the
				// card to a different point while the first one's answer is still in flight.
				// From that moment the answer is about a point the card no longer shows, and
				// dropping the pending id here is what makes {@see finishSelection} see that.
				if ( null !== pendingSelectionId && String( payload.pointId ) !== pendingSelectionId ) {
					pendingSelectionId = null;
				}

				if ( payload.group && provider && 'function' === typeof provider.focusGroup ) {
					provider.focusGroup( payload.group.key, { zoom: 'marker' !== payload.origin } );
				}
			} );

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

			// zoom (Task 14, spec V-13): our own zoom control's two buttons emit a signed step;
			// the provider owns the actual camera zoom (`zoomBy()`), matching every other
			// map-behaviour call this file forwards rather than implements itself.
			panels.on( 'zoom', function( payload ) {
				if ( provider && 'function' === typeof provider.zoomBy ) {
					provider.zoomBy( payload.step );
				}
			} );

			// `query`, not `displayName` (live-review round 4). A suggestion now carries BOTH: the
			// SHORT form the customer reads ("Чертановская улица, 66к1") and the FULL one the
			// geocoder needs ("Россия, Москва, Чертановская улица, 66к1"). Resolving the short form
			// would hand the geocoder a street with no city — exactly the ambiguity `strictBounds`
			// exists to prevent everywhere else in the provider. `displayName` remains the fallback
			// for a provider whose suggestions carry no separate `query` (see
			// `map-provider-yandex.js`'s own `projectSuggestion()`, which falls back the same way
			// when `value` is missing).
			panels.on( 'searchAddressPicked', function( index ) {
				var address = lastAddresses[ index ];

				if ( ! address || ! provider || 'function' !== typeof provider.resolveAddress ) {
					return;
				}

				var query = 'string' === typeof address.query && address.query.length > 0
					? address.query
					: address.displayName;

				if ( 'string' === typeof query && query.length > 0 ) {
					provider.resolveAddress( query );
				}
			} );

			panels.on( 'searchPointPicked', function( pointId ) {
				var group = findGroupByPointId( pointId );

				if ( ! group ) {
					return;
				}

				// focusGroup() runs via the 'cardOpened' event openCard() emits — see that
				// listener above. origin: 'search' (D6) — a search hit, like every other
				// non-marker origin, centres AND zooms.
				panels.openCard( group, pointId, 'search' );
			} );

			// showNearestRequested (extra wiring, D-6): the "show it anyway" button on the
			// panels' own "nothing nearby" state — `info.key` identifies the nearest group (see
			// map-provider-yandex.js's own `focusAddress()`).
			panels.on( 'showNearestRequested', function( info ) {
				var group = info && info.key ? groupsByKey[ info.key ] : null;

				if ( ! group ) {
					return;
				}

				// focusGroup() runs via the 'cardOpened' event openCard() emits — see that
				// listener above. origin: 'nearest' (D6) — centres AND zooms, same as every
				// other non-marker origin.
				panels.openCard( group, null, 'nearest' );
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

			// searchType/searchSubmit/searchReset (Task 12, spec V-6): the two-events-two-costs
			// design `map-provider-yandex.js`'s own docblock documents under "ADDRESS SEARCH".
			// `searchType` fires on every debounced keystroke and never touches the GEOCODER — it
			// matches the already-loaded pool for free AND asks `ymaps.suggest()`, which is the
			// cheap address-completion service, not the billed geocoder.
			//
			// `suggestAddresses()` (live-review round 4) is what makes our result list read like the
			// reference's. The operator typed the exact address "Чертановская 66к1" and got back
			// "Russian Federation, Moscow, Serpukhovsko-Timiryazevskaya Line, Chertanovskaya metro
			// station" where Yandex.Delivery gives "Чертановская улица, 66к1". Two causes, both in
			// the provider: we had dropped `suggest()` entirely and left only the geocoder — which
			// will happily rank a metro station above a house number — and we displayed the full
			// postal form. The reference keeps ymaps' own suggest panel alive for exactly this;
			// since our chrome is entirely our own (D-3), the provider reproduces it explicitly.
			//
			// It RETURNS its results rather than emitting `searchResults` — deliberately, and the
			// distinction is load-bearing. `searchResults` drives `renderSearchResults()`, the
			// COMPLETED-search renderer, the one allowed to print "Поиск не дал результатов.".
			// Routing typing through it is precisely the round-3 defect ("начинаешь писать адрес …
			// появляется «Поиск не дал результатов.» и висит"): typing a street the geocoder has not
			// been asked about yet is the normal case, so that verdict was usually a lie. Preview and
			// verdict stay apart by construction here, not by convention.
			panels.on( 'searchType', function( payload ) {
				if ( ! panels || ! provider ) {
					return;
				}

				if ( 'function' === typeof provider.suggestAddresses ) {
					provider.suggestAddresses( payload.query ).then( function( results ) {
						if ( destroyed || ! panels ) {
							return;
						}

						// `lastAddresses` must be refreshed HERE as well as in the `searchResults`
						// listener below. That listener used to be the only writer, back when every
						// address list arrived as an event; suggestions now arrive as a RETURN value
						// (see this handler's own comment), so without this line
						// `searchAddressPicked` would look the picked index up in whatever the last
						// COMPLETED search left behind — resolving the wrong address, or none.
						lastAddresses = ( results && results.addresses ) || [];

						panels.previewSearchResults( results );
					} ).catch( function() {} );

					return;
				}

				// A provider with no `suggestAddresses()` (the embedded provider, or any future one
				// that owns its own chrome) still gets the free local matches — the address column
				// is simply empty rather than the whole preview being dead.
				if ( 'function' === typeof provider.matchLoadedPoints ) {
					panels.previewSearchResults( { points: provider.matchLoadedPoints( payload.query ), addresses: [] } );
				}
			} );

			// `searchSubmit` (Enter/the magnifier) is the ONLY path that spends the merchant's
			// geocoding quota — it runs the control's own `search()`, which invokes
			// `map-provider-yandex.js`'s bounded geocode provider and, on resolution, emits ONE
			// of `searchResults`/`searchCleared`/`addressMatchedPoint` (all wired below) with the
			// outcome. `setSearchBusy( true )` marks the submit button in-flight for exactly the
			// same reason the button exists at all (work item 5, live-review round 2): a real
			// network round trip takes real time, and every one of those three outcomes clears it
			// again — see their own listeners below — so the button can never be left stuck
			// disabled regardless of which one the search actually resolves down. Only fired when
			// a search is genuinely about to run; a provider with no `searchControl` never
			// answers with any of the three events, and marking busy without a matching clear
			// would strand the button forever.
			panels.on( 'searchSubmit', function( payload ) {
				if ( provider && provider.searchControl && 'function' === typeof provider.searchControl.search ) {
					panels.setSearchBusy( true );
					provider.searchControl.search( payload.query );
				}
			} );

			// `searchReset` clears the input/results DOM itself (pickup-panels.js's own job) —
			// this file's half is dropping whatever provider-side search state belongs to it: the
			// "your address" pin and the stale `searchResults`, both owned by `clearAddress()`
			// (see map-provider-yandex.js's own docblock on why that file, not this one, owns it).
			panels.on( 'searchReset', function() {
				if ( provider && 'function' === typeof provider.clearAddress ) {
					provider.clearAddress();
				}
			} );

			// Task 17 (spec V-5): the message card's own retry control (an empty/failed fetch —
			// see `showFetchMessage()`) — wired ONCE here, like every other `panels.on(...)` call
			// in this block, never inside `start()` itself (a retry re-runs `start()`, it does not
			// re-wire the panels, which are constructed exactly once per session; see the docblock
			// above). `start()` is a function DECLARATION, hoisted above this call, so referencing
			// it here — before its own definition further down — is safe.
			panels.on( 'retryRequested', function() {
				start();
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

			// Task 16 (spec V-4): every start() — the initial open AND every retry — runs through
			// the full "map drawn → points in flight → points in" sequence again, so the flag that
			// gates {@see clearInitialBusy} resets here too, not just once per session.
			busyClearedThisStart = false;

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
				// A marker click funnels through the SAME 'cardOpened' listener a sidebar row
				// does — but tagged `origin: 'marker'`, so that listener's pan/zoom split (D6,
				// above) treats it as a PAN only, never a re-centre-and-zoom. Spec V-10
				// ("identical path") is overruled — see the 'cardOpened' listener's own comment.
				provider.on( 'pointClick', function( key ) {
					var group = groupsByKey[ key ];

					if ( group ) {
						panels.openCard( group, null, 'marker' );
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
					// is asking the customer to use. See showFetchMessage()'s own docblock for the
					// identical shared-container reasoning.
					//
					// Task 16 (spec V-4): this can be the FIRST thing the provider ever reports
					// after init() resolves — no fetch happens for a bbox this wide, so
					// fetchAndSetPoints() (the usual place stage 2 clears) never runs at all. Without
					// this call the overlay would sit there forever, blocking the very "zoom in" the
					// message asks for.
					clearInitialBusy();
					showFetchMessage( 'zoomIn' );
				} );

				provider.on( 'searchResults', function( results ) {
					lastAddresses = ( results && results.addresses ) || [];
					panels.renderSearchResults( results );
					// A completed search is one of the three outcomes `searchSubmit` (above) put
					// the button in flight for — see that listener's own comment.
					panels.setSearchBusy( false );
				} );

				// searchCleared (D1a, live-review round 2 — the "crossik" bug): `clearAddress()`
				// no longer round-trips through an EMPTY `searchResults` to signal "cleared" — see
				// `map-provider-yandex.js`'s own docblock on why that used to re-open the results
				// box the customer had just closed and print "не найдено" at them. This IS the
				// box's actual close path now. Also releases whatever busy state `searchSubmit`
				// may have set — a search that resolves down the clear route (rather than a
				// completed one) must not leave the submit button stuck disabled either.
				provider.on( 'searchCleared', function() {
					panels.hideSearchResults();
					panels.setSearchBusy( false );
				} );

				// addressMatchedPoint (late addition, live-review round 2): the searched address
				// landed within SAME_PLACE_THRESHOLD_M of one of our own points
				// (`map-provider-yandex.js`'s own `focusAddress()`) — treat the search as having
				// selected that POINT outright, not just moved the camera near it. Routes through
				// the SAME 'cardOpened' funnel above with `origin: 'search'`, which both opens the
				// sidebar card AND (via the pan/zoom split, D6) centres-and-zooms the camera onto
				// it — `focusAddress()` deliberately does neither itself in this case (see that
				// method's own docblock), so this is the ONLY place either happens. An unknown key
				// (defensive: `groupsByKey` is rebuilt on every fetch, so a stale key from an
				// in-flight geocode racing a refetch is possible, however unlikely) is a silent
				// no-op, matching every other "key not found in the current pool" guard in this
				// block (see `showNearestRequested` above).
				provider.on( 'addressMatchedPoint', function( info ) {
					var group = info && info.key ? groupsByKey[ info.key ] : null;

					panels.setSearchBusy( false );

					if ( group ) {
						panels.openCard( group, null, 'search' );
					}
				} );

				// addressFocused: the provider's own confirmation that the "your address" pin
				// just dropped (see map-provider-yandex.js's own docblock on this event) — the
				// panels' distance anchor and `nearestTo` header move to the SAME address, so
				// the sidebar sorts from where the customer searched, not the map centre
				// (D-6). Fires whether or not any group turned out to be near it; the
				// `nothingNearby` state (wired above) is a SEPARATE, list-body-level concern.
				//
				// `openList()` alongside it: `setAnchor()` only re-sorts the list BODY, it does
				// not touch which panel is visible. Without this, picking an address while a
				// point's card happened to be open left that stale card on screen — the newly
				// sorted list existed, just invisible behind it. Spec: "the sidebar opens
				// automatically" is not conditional on nothing else being open.
				provider.on( 'addressFocused', function( info ) {
					panels.setAnchor( info.latLng, info.label );
					panels.openList();
				} );
			}

			// Under `ownsChrome` the provider IS the whole interface and gets the dialog body.
			// Otherwise it gets the panels' own map element — a child of `.woodev-pickup-stage`
			// (spec V-3). Handing it the body instead would make its canvas a SIBLING of the
			// stage, so the page would carry two `.woodev-pickup-map` elements: the panels'
			// (empty, sized by the stage) and the provider's (unsized, outside the stage's
			// positioning context, and therefore not covered by any of the panels' geometry).
			var mapHost = ownsChrome ? modal.getContainer() : panels.getMapElement();

			var initResult = provider.init( mapHost, buildProviderConfig( config, searchLayoutEl ), realDataSource );

			Promise.resolve( initResult ).then( function() {
				if ( destroyed ) {
					return;
				}

				// The map is drawable now, so the "loading" overlay opened with the dialog has
				// done its job. Without it the customer got 1-2 seconds of an empty dialog
				// carrying nothing but its own title while the map script downloaded.
				modal.hideLoading();

				// D8/п.5,п.8 (live-review round 2): `setMargin()` used to run for the FIRST time
				// only from the panels' own `listToggle` event, so ymaps had NO margin reservation
				// at all until the customer opened the sidebar once — the very first camera move
				// had nothing to avoid, and ymaps' own copyright strip rendered wherever it liked
				// (Yandex's ToS forbids covering it). The panels always start CLOSED — `render()`
				// never adds `is-open`; see `pickup-panels.js`'s own "ONE OPEN STATE, ON THE STAGE"
				// note — so `false`/`0` here is not a guess, it is that starting state made
				// explicit, established BEFORE the first camera move rather than left implicit
				// until whatever `listToggle` happens to fire first (below, unchanged).
				//
				// The references ALSO reserve a STATIC top-chrome strip for their search bar
				// (`{top:0,left:0,width:'100%',height:'64px'}` — see the plan's "Reference truth"
				// table). Doing that here would need a SECOND provider-level margin accessor:
				// `setMargin()`/`this._marginArea` (map-provider-yandex.js) own exactly ONE, sized
				// for the sidebar. Adding a second is a provider change — out of this file's scope
				// (T3 touches only pickup-mount.js) — deliberately NOT implemented; flagged back
				// as a follow-up rather than papered over from here.
				if ( panels && provider && 'function' === typeof provider.setMargin ) {
					provider.setMargin( false, 0 );
				}

				// Task 16 (spec V-4 stage 2): the modal's spinner just came down, but the pool the
				// map/list/search would act on has not arrived yet — a bare canvas with nothing to
				// show reads as "there are no points here", not "still loading". `panels` is null
				// under `ownsChrome`; an embed loads its own points invisibly to this file and has no
				// stage of its own to mark busy (see the file docblock's "THIS FILE, NOT THE
				// PROVIDER, NOW OWNS FETCHING" section).
				if ( panels ) {
					panels.setBusy( true );
				}

				fireDocumentEvent( EVENT_MAP_READY, { fieldId: config.fieldId, provider: config.provider } );

				// bulk fetches once, right here; viewport waits for the provider's own
				// boundsChange (wired above) — see the file docblock. Either way, whatever settles
				// first (a fetch resolving/rejecting, or a viewport starting out `bboxTooWide`) is
				// what clears stage 2 via {@see clearInitialBusy} — see those call sites.
				if ( ! ownsChrome && 'bulk' === config.strategy ) {
					fetchAndSetPoints( bulkQuery() ).catch( function() {} );
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
				return fetchAndSetPoints( bulkQuery() ).catch( function() {} );
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

				// The TWO long-lived targets a session binds to (see {@see handleModalClosed}
				// and {@see refreshCheckout}) — and therefore the two this file has to unbind by
				// hand, since nothing else takes either away. Left attached, every session ever
				// opened on this page would keep a listener, and its whole closure, alive for
				// the life of the document.
				document.body.removeEventListener( 'woodev_modal_closed', handleModalClosed );
				dropRefreshWaiter();

				if ( provider && 'function' === typeof provider.destroy ) {
					provider.destroy();
				}

				// The panels hold a pending search debounce and the listener map this session
				// registered on them. `modal.destroy()` takes the DOM away but not the timer,
				// which would then fire against a dead instance — and, on a fast reopen, past a
				// live one built from the same trigger.
				if ( panels && 'function' === typeof panels.destroy ) {
					panels.destroy();
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
