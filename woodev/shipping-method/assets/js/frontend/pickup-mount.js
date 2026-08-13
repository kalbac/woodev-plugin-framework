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
 * `setPoints( groups, options )` hands it (`options.focus`, a group key, means
 * "open the map AT this one, marked active, instead of fitting the whole set" —
 * spec D-15's restore; see `map-provider-yandex.js`'s own `setPoints()` docblock
 * for why the camera must move BEFORE the drawing) and reports camera/selection events, but
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
 * down mid-round-trip, is free not to do; that waiter is therefore paired with a
 * {@see REFRESH_TIMEOUT_MS} timer, so the hold is bounded even when WooCommerce
 * never answers at all). Everything else hangs off
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
	 * fix) — the framework's chosen default is the `'review'` anchor (after the
	 * shipping-methods list), never `'rate'`. A single, explicit constant, read only by
	 * {@see resolveAddressSlot}, is what makes flipping this default later — should the
	 * operator ever want the address next to the rate instead — a one-line change rather
	 * than a hunt through {@see mountOne}/{@see mountSlot}.
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
	 * How long, in ms, a post-selection checkout refresh may hold the card's busy lock before it is
	 * released regardless — see {@see refreshCheckout}.
	 *
	 * NOT a UX timer: the release the customer normally gets is WooCommerce's own
	 * `updated_checkout`, which lands in well under a second. This is the bound on the case where
	 * that event never arrives at all — the checkout ajax failed, was aborted by a newer one, or
	 * the build simply does not fire it — where the only alternative is a CTA reading «Проверяем…»
	 * for the rest of the session over a refresh that already gave up, and a `one()` waiter left
	 * bound to `document.body` holding the whole panels graph alive with it. Generous on purpose:
	 * cutting a refresh that is merely slow would unlock the CTA in the middle of a totals update,
	 * which is the exact thing the lock exists to prevent (spec §5.2).
	 *
	 * @type {number}
	 */
	var REFRESH_TIMEOUT_MS = 10000;

	/**
	 * How long, in ms, the confirmation round trip runs before the dialog's busy overlay appears
	 * under `ownsChrome` — see {@see acquireSelectionBusy}.
	 *
	 * The reason is not the usual one. A delay like this normally exists to stop a spinner
	 * FLASHING on a fast answer; here it exists because of the carrier's own widget — though both
	 * motives want the same mechanism, and an answer that beats the timer does cancel it, so a
	 * fast confirmation shows no overlay at all. That outcome is correct rather than merely
	 * tolerable: in that case the widget's own button-disable WAS the whole signal, and it was
	 * enough.
	 *
	 * The widget disables its «Забрать здесь» button the instant it is pressed. That is the
	 * customer's first and most local acknowledgement of their own click, right where they
	 * clicked — and an overlay raised in the same frame paints over it before anyone can register
	 * it (operator, on the rig: «оверлей так быстро появляется, что пользователь даже не замечает,
	 * что кнопка стала disabled»). This window belongs to the button, not to us.
	 *
	 * THE COST, STATED: {@see acquireSelectionBusy}'s overlay is also what physically intercepts a
	 * second click (issue #260's other half), so for these 500 ms that interception is not there.
	 * What covers the gap is the same widget behaviour the delay exists for — a disabled button
	 * cannot be clicked again. That makes the gap safe for a carrier whose widget disables its own
	 * confirm control (measured on Почта's, s63) and merely narrow, not closed, for one that does
	 * not. A carrier embed that leaves its button live through its own confirmation is already
	 * offering a double submit of its own; this is bounded at half a second.
	 *
	 * @type {number}
	 */
	var SELECTION_BUSY_DELAY_MS = 500;

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
	 * `ownsChrome` picks between `selectFailed` ("Попробуйте ещё раз") and its embedded
	 * counterpart `selectFailedEmbedded` (#297) — NOT between two spellings of the same idea.
	 * Under the framework's own panels the confirm CTA is the framework's OWN button, alive
	 * again the moment {@see releaseSelectionBusy} runs, so "try again" describes a real,
	 * available action. Under `ownsChrome` the confirm control belongs to the carrier's own
	 * widget — measured on Почта's, s70: it disables itself the instant it is pressed and
	 * never re-enables — so the SAME words would invite the customer to repeat a press they no
	 * longer have. `stalePage` is exempt from this split on purpose: reloading the page is
	 * available in both modes alike, so it never needed a second spelling.
	 *
	 * @param {Object}      config
	 * @param {Object|null} reason     `{ status, code, message }`.
	 * @param {boolean}     ownsChrome whether the framework draws no chrome of its own for
	 *                                 this session (see {@see openSession}'s own `ownsChrome`).
	 * @returns {string}
	 */
	function selectionErrorKey( config, reason, ownsChrome ) {
		if ( 'stalePage' === errorMessageKey( config, reason ) ) {
			return 'stalePage';
		}

		return ownsChrome ? 'selectFailedEmbedded' : 'selectFailed';
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
	 * @param {Object} config the full mount config (`window.woodev_pickup_config_*`).
	 * @returns {string}
	 */
	function resolveLocalityKey( config ) {
		if ( ! config || ! config.location ) {
			return resolveLocality( config );
		}

		if ( ! Object.prototype.hasOwnProperty.call( resolvedLocalityKey, config.fieldId ) ) {
			var current = config.location.current;

			resolvedLocalityKey[ config.fieldId ] = current && 'string' === typeof current.key ? current.key : '';
		}

		return resolvedLocalityKey[ config.fieldId ];
	}

	/**
	 * Handles `woodev_location_applied` (Task 15; issue #159; `location-cascade.js`'s own
	 * event, fired once the customer's `/select` round-trip actually persisted) — updates
	 * {@see resolvedLocalityKey} for EVERY currently-registered, Location-Provider-backed
	 * pickup config, so {@see resolveLocalityKey} tracks the customer's real current locality
	 * without re-reading `config.location.current.key` (a PAGE-LOAD snapshot, never refreshed
	 * on its own).
	 *
	 * A field WITHOUT `config.location` (a plugin that has not wired the layer) is skipped —
	 * its own `resolveLocalityKey()` falls back to the DOM read regardless, so writing an
	 * entry here for it would only be dead state.
	 *
	 * @param {CustomEvent} event `detail: { key, level }` — only `key` is read here.
	 * @returns {void}
	 */
	function handleLocationApplied( event ) {
		var key = event && event.detail && 'string' === typeof event.detail.key ? event.detail.key : '';

		collectConfigs().forEach( function( config ) {
			if ( config.location ) {
				resolvedLocalityKey[ config.fieldId ] = key;
			}
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
	 * @returns {{modal: Object, refresh: Function, isSelfRefreshInFlight: Function, destroy: Function}}
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
		// #238: these two degraded sessions never call refreshCheckout(), so no waiter of
		// theirs can ever be in flight — a permanent `false` is exactly as correct as reading
		// a closure variable that would never change.
		var noopSelfRefreshInFlight = function() { return false; };

		if ( 'function' !== typeof ProviderCtor ) {
			modal.showError( text( config, 'error' ) );

			return {
				modal: modal,
				refresh: noopRefresh,
				isSelfRefreshInFlight: noopSelfRefreshInFlight,
				destroy: function() { modal.destroy(); },
			};
		}

		var DataSourceFactory = window.WoodevPickupDataSource;

		if ( 'function' !== typeof DataSourceFactory ) {
			modal.showError( text( config, 'error' ) );

			return {
				modal: modal,
				refresh: noopRefresh,
				isSelfRefreshInFlight: noopSelfRefreshInFlight,
				destroy: function() { modal.destroy(); },
			};
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
		 *  continuation (from whichever of that continuation's two restore call sites actually
		 *  fires; see them both) and never reset (not even by a retry's fresh `start()`). Without this
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

		/**
		 * #234 — the UNION of every listing this session, by `point.id`, under
		 * `strategy: 'viewport'` ONLY. `bulk` fetches once and its listing is authoritative,
		 * so it never reads or writes this.
		 *
		 * Why the pool lives HERE and not in the provider: `setPoints()`'s own docblock
		 * already assigns cross-fetch de-duplication to the caller ("the caller is the one
		 * that decides what the current full set is"), so accumulating is this file's job
		 * and the provider contract is unchanged. That also keeps every other provider —
		 * `map-provider-embedded.js` today, anything added later — free of it.
		 *
		 * Insertion order is meaningful: it is what {@see trimPointPool} evicts by when a
		 * domain has bounded the pool. Plain object key order preserves insertion order for
		 * string keys, which every `point.id` is coerced to on the way in.
		 *
		 * MUST be cleared together with the detail memo — see {@see resetPointPool}.
		 *
		 * @type {Object.<string, Object>}
		 */
		var pointPool = Object.create( null );

		/**
		 * The pooled ids in TRUE INSERTION ORDER — what {@see trimPointPool} evicts by (#234).
		 *
		 * A separate array rather than `Object.keys( pointPool )`, because that is NOT insertion
		 * order: JavaScript orders integer-like string keys NUMERICALLY and ahead of every other
		 * key, and this carrier's ids are exactly that (`'111543'`). Evicting by `Object.keys()`
		 * would therefore drop the LOWEST-numbered id — which has nothing to do with age, and on
		 * a mixed pool can be the point the customer just panned to. Found by adversarial review.
		 *
		 * @type {Array.<string>}
		 */
		var poolOrder = [];

		/**
		 * Bumped by every {@see resetPointPool}. A listing captures it when it goes out and is
		 * DISCARDED on arrival if it moved — see {@see fetchAndSetPoints}.
		 *
		 * Why a counter and not a boolean "was reset": two resets can bracket one request, and a
		 * boolean cleared by the second would let the request through. Same reason
		 * `pendingSelectionToken` is a token rather than a point id (s57's ABA hole).
		 *
		 * @type {number}
		 */
		var poolGeneration = 0;

		/**
		 * How many groups the provider last reported as being inside the frame (#234).
		 *
		 * The mount deliberately does NOT compute this itself: `_groupsInsideBounds()` is the
		 * ONE definition of "in frame" in this codebase, unified in #167 precisely so two
		 * inequality chains over the same rectangle cannot disagree. This is a cached read of
		 * that answer, not a second opinion.
		 *
		 * @type {number}
		 */
		var visibleGroupCount = 0;

		/** @type {Array} the address suggestions from the LAST `searchResults` event — what
		 *  `searchAddressPicked( index )` indexes into. */
		var lastAddresses = [];

		/** @type {Array|null} the currently selected type-filter codes, or null for "all". */
		var currentTypeFilter = null;

		/** @type {Array|null} the last viewport bbox reported via `boundsChange` (`strategy:
		 *  'viewport'` only) — what a type-filter change or {@see refresh} re-fetches against. */
		var lastBbox = null;

		/** @type {string|null} the point the card currently shows, as the `cardOpened` funnel last
		 *  reported it — the staleness guard for {@see refreshPointDetails}. A plain id, not a
		 *  generation, because unlike a confirmation a detail fetch is idempotent and re-opening
		 *  the SAME point is a legitimate reason to accept a late answer. */
		var cardPointId = null;

		/** @type {Object.<string, boolean>} point ids whose details have already landed this
		 *  listing — see {@see refreshPointDetails}. Emptied on every successful listing fetch,
		 *  because that is the moment the cart (and so the verdict) may have changed under us. */
		var detailedPoints = {};

		/** @type {Object.<string, Object>} the RAW detail record for every point whose details
		 *  have landed, by id — re-applied over each rebuilt listing by {@see fetchAndSetPoints}.
		 *
		 *  Without this the enrichment is lost on the very next pan: `geo.groupByPosition()`
		 *  rebuilds groups from the SPARSE listing, and `setVisible()`'s identity healing then
		 *  re-points the open card at that sparse group — so the card's own content appeared and
		 *  then vanished a beat later, which is exactly what the customer saw (#232). Details are
		 *  strictly more informed than a listing, verdict included, so they win on merge.
		 *
		 *  Emptied together with `detailedPoints`, and for the same reason — see
		 *  {@see forgetPointDetails}. */
		var detailsById = {};

		/** @type {number} mints one unique, monotonic token per confirmation this session sends.
		 *  Never reset, never reused — that is the whole point (see `pendingSelectionToken`). */
		var selectionTokens = 0;

		/** @type {number} the token of the confirmation the staleness guard (spec D-9) currently
		 *  holds — `0` when none is in flight for the card.
		 *
		 *  A GENERATION, NOT A POINT ID. The guard used to store the point id alone, which made it
		 *  an ABA test: a confirmation the guard had already dropped and a LATER one for the SAME
		 *  point were indistinguishable, so the first one's answer was applied as though it were
		 *  the second's — and cleared the second's marker on the way out, so the answer the
		 *  customer was actually waiting on was the one thrown away. A token is unique per
		 *  request, so "is this answer still the one the card is waiting for?" has exactly one
		 *  true answer no matter how many confirmations name the same point.
		 *
		 *  IT ALSO OWNS THE CARD'S BUSY LOCK. `setSelectionBusy()` never self-balances (see its
		 *  own docblock in `pickup-panels.js`), and the two obvious disciplines are both wrong:
		 *  releasing on EVERY settlement lets a stale answer unlock the card a LIVE confirmation
		 *  still holds, allowing a second overlapping submit; releasing only on the applied paths
		 *  leaves a card that took a discarded answer locked forever, with a dead CTA reading
		 *  «Проверяем…» over a request that already came back. The rule that satisfies both: the
		 *  lock belongs to the token, and it is released by whoever ENDS that token's ownership —
		 *  {@see invalidateSelection} when something drops it (the card moving on, the dialog
		 *  being dismissed, the session being destroyed), {@see finishSelection} when the answer
		 *  it owns actually settles. A token that no longer owns the lock never touches it. */
		var pendingSelectionToken = 0;

		/** @type {string|null} the point id `pendingSelectionToken`'s confirmation is about — read
		 *  ONLY by the `cardOpened` listener, to tell "the card moved onto another point" from
		 *  "the card re-rendered on the same one". Never the guard's identity; see above. */
		var pendingSelectionPointId = null;

		/** @type {number|null} the pending {@see SELECTION_BUSY_DELAY_MS} timer that will raise the
		 *  dialog's busy overlay under `ownsChrome`, or null when none is waiting. Lives beside the
		 *  selection token rather than inside {@see acquireSelectionBusy} because a confirmation can
		 *  end BEFORE the overlay was ever shown — a fast answer, a dismissed dialog, a destroyed
		 *  session — and {@see releaseSelectionBusy} is what must cancel it in every one of those
		 *  cases. Only ever non-null under `ownsChrome`; with panels the card owns the busy state
		 *  and there is no timer at all. */
		var selectionBusyTimer = null;

		/** @type {number} mints one unique, monotonic token per detail fetch this session sends —
		 *  the same device `selectionTokens` above provides for confirmations, for the same
		 *  reason. See `verdictPendingToken`. */
		/** @type {Object.<string, boolean>} point ids whose detail fetch is IN FLIGHT right now —
		 *  a different question from `detailedPoints` above, which records what has already
		 *  LANDED for the current listing and is wiped on every successful one.
		 *
		 *  Both are needed. Wiping `detailedPoints` is correct (a new listing means a possibly
		 *  new cart, so a landed verdict is history), but it was also the only thing stopping a
		 *  second request for a point already being fetched — and since a successful listing now
		 *  re-asks for the open card, a customer panning quickly produced a listing, a wipe, and
		 *  another request, per pan, all for the same point, all in flight together. This map
		 *  survives the wipe, so "already asking" stays true until the answer actually arrives.
		 *  Found by the Codex critic pass on this branch. */
		var detailsInFlight = {};

		var verdictTokens = 0;

		/** @type {number} issue #223: the token of the {@see refreshPointDetails} fetch that
		 *  currently owns the card's `panels.setVerdictPending( true )` lock — `0` when none does.
		 *  A SEPARATE lock owner from `pendingSelectionToken` above (see
		 *  {@see Panels.prototype.setVerdictPending}'s own docblock for why the two locks must
		 *  never be merged into one flag): released ONLY by whoever ends ITS OWN ownership — the
		 *  fetch itself settling ({@see refreshPointDetails}), the card moving to a different
		 *  point before it does (the `cardOpened` listener, below), or the session being torn
		 *  down ({@see releaseVerdictPending}'s call in `destroy()`).
		 *
		 *  A TOKEN, NOT A POINT ID — this started as an id and was corrected, exactly as
		 *  `pendingSelectionToken` had to be. Two fetches for the SAME point overlap in an
		 *  ordinary flow: one starts when the card opens, and the camera move that open causes
		 *  triggers a listing whose success clears `detailedPoints` and re-asks for the same
		 *  point. Keyed on the id, the FIRST settling would match and release a lock the SECOND
		 *  still needs, and its older answer could overwrite the newer one. */
		var verdictPendingToken = 0;

		/** @type {string|null} the point id `verdictPendingToken`'s fetch is about — read ONLY by
		 *  the `cardOpened` listener, to tell "the card moved onto another point" from "the card
		 *  re-rendered on the same one". Never the guard's identity; see above. Mirrors
		 *  `pendingSelectionPointId`'s relationship to `pendingSelectionToken` exactly. */
		var verdictPendingPointId = null;

		/** @type {Function|null} the pending `updated_checkout` handler {@see refreshCheckout}
		 *  bound through jQuery, held only so {@see dropRefreshWaiter} can take it off again —
		 *  the second (and last) long-lived binding a session makes; see the file docblock. */
		var refreshWaiter = null;

		/** @type {number|null} the {@see REFRESH_TIMEOUT_MS} timer backing that waiter — the
		 *  bounded failure path for a refresh WooCommerce never answers. */
		var refreshTimer = null;

		/** @type {Object|null} the panels whose card lock the in-flight checkout refresh is
		 *  holding, so {@see dropRefreshWaiter} can release it without re-deriving which object
		 *  was locked (it may not be `panels` — the refresh is skipped entirely when the modal
		 *  has just closed; see {@see finishSelection}'s own call site). */
		var refreshBusyPanels = null;

		/** @type {boolean} Task 16 (spec V-4 stage 2/3): has THIS start() cycle's busy overlay
		 *  already been cleared? Reset at the top of every {@see start} call (initial open AND
		 *  every retry — each re-runs the FULL "map drawn → points in flight → points in" sequence —
		 *  see the file docblock's "RETRY NEVER RE-init()S" section), and flipped true by
		 *  {@see clearInitialBusy} the first time this cycle's opening fetch (or the "bbox too
		 *  wide" terminal state, which never fetches at all) settles — never again after that, so a
		 *  LATER refetch (a type-filter change, `refresh()`, a subsequent viewport pan) never
		 *  re-shows or re-hides the overlay a customer has already moved past. */
		var busyClearedThisStart = false;

		/** @type {number} Issues #222/#224: count of in-flight background requests — a bbox
		 *  refetch ({@see fetchAndSetPoints}) and a lazy point-detail fetch ({@see refreshPointDetails},
		 *  issue #219) both bump/drop it, and they genuinely overlap (the customer can open a card
		 *  while a pan's refetch is still in flight), so this is a COUNTER, not a boolean — the
		 *  first of two overlapping requests to settle must not switch
		 *  {@see window.WoodevPickupPanels}'s shared indicator ({@see Panels#setLoading}) off while
		 *  the other is still running. UNLIKE `busyClearedThisStart` above, this is never reset
		 *  per {@see start} cycle — it is a plain in-flight tally, and a request that outlives a
		 *  retry's fresh `start()` call (it cannot: every request this counts is scoped to ONE
		 *  `provider`/`panels` pair that a retry destroys and rebuilds) would need to keep counting
		 *  down regardless. See {@see bumpLoading}/{@see dropLoading}. */
		var loadingCount = 0;

		/**
		 * Marks one more background request as in-flight (issues #222/#224) — the 0→1 transition is
		 * what actually turns the shared indicator on; a second, third, … overlapping request only
		 * bumps the count, and does NOT touch `panels.setLoading()` again (it is already on).
		 *
		 * `panels` is null under `ownsChrome` (an embedded provider owns its own chrome, see the
		 * file docblock) and `destroyed` once the session has been torn down — both guarded exactly
		 * like {@see clearInitialBusy} does, so this never touches a `panels` instance that may not
		 * exist or whose DOM may already be gone. The counter itself still increments regardless of
		 * either guard, so {@see dropLoading} always sees the matching decrement for every
		 * {@see bumpLoading} call, however this session ends.
		 *
		 * @returns {void}
		 */
		function bumpLoading() {
			loadingCount += 1;

			if ( 1 === loadingCount && panels && ! destroyed ) {
				panels.setLoading( true );
			}
		}

		/**
		 * The counterpart to {@see bumpLoading} — called on EVERY settle path of every request
		 * that called it (both branches of {@see fetchAndSetPoints}'s `dataSource.fetchPoints()`
		 * chain, both branches of {@see refreshPointDetails}'s `dataSource.fetchDetails()` chain,
		 * success AND failure alike), so the counter always returns to zero, including when a
		 * request fails — a counter that never reaches zero would pin the indicator on forever
		 * (the exact shape a staleness guard elsewhere in this file was once burned by). Only the
		 * 1→0 transition actually turns the indicator off, which is the whole reason this is a
		 * counter and not a boolean: a request that settles while ANOTHER is still in flight (the
		 * overlap case #222/#224 exists for) must leave the indicator showing.
		 *
		 * Never lets the counter go negative — defensive only; every call site pairs exactly one
		 * {@see bumpLoading} with exactly one {@see dropLoading} per request (a settled Promise
		 * invokes exactly one of its two callbacks exactly once, so within a single request there
		 * is no way to double-drop).
		 *
		 * @returns {void}
		 */
		function dropLoading() {
			if ( loadingCount > 0 ) {
				loadingCount -= 1;
			}

			if ( 0 === loadingCount && panels && ! destroyed ) {
				panels.setLoading( false );
			}
		}

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
		 * Reports the outcome of OUR OWN confirmation request when the framework draws no
		 * chrome of its own — the `ownsChrome`/embedded case, where `panels` is null (#265).
		 *
		 * Both refusal branches of {@see finishSelection} route their message through here,
		 * because both were completely silent: each was written as `if ( panels ) { … }` with
		 * the `return` immediately after, so under `ownsChrome` the customer got no word at
		 * all — indistinguishable from «the dialog just did not close», which is exactly how it
		 * was read while verifying #260.
		 *
		 * `showNotice()` deliberately, not `showError()`: the latter replaces the dialog BODY,
		 * which under `ownsChrome` is the carrier's own widget/iframe. One point being refused
		 * is no reason to destroy the whole picker — the customer must stay free to pick
		 * another, and tearing the frame down would also mean re-running the carrier's
		 * handshake. Same non-destructive/destructive split {@see degrade} already makes.
		 *
		 * Not a contradiction of D-3 (the framework does not draw the list or the card): saying
		 * how OUR OWN request ended is not drawing a carrier's UI, and the framework already
		 * reports its own outcomes this way on the {@see degrade} path.
		 *
		 * @param {string} message a resolved, human-readable string — never an i18n key.
		 * @returns {void}
		 */
		function announceWithoutPanels( message ) {
			if ( destroyed || ! message ) {
				return;
			}

			modal.showNotice( message );
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
		 * The group this pass is about to restore (spec D-15), or null when it restores nothing:
		 * the restore already happened this session, there are no panels, no point is stored in
		 * the field, or the stored point is not among the groups just built.
		 *
		 * Read BEFORE `setPoints()`, because the CAMERA half of restoring is `setPoints()`'s own
		 * job now — `setPoints( groups, { focus: key } )` settles the camera on the group first
		 * and draws second. s52's rig pass is why: with the drawing first, the restore's camera
		 * move (however carefully sequenced) crossed ymaps' first ObjectManager layout and left
		 * the restored marker parked at ymaps' own off-screen sentinel — right camera, right
		 * `data-state`, no visible pin. See `map-provider-yandex.js`'s `setPoints()` docblock.
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

		/**
		 * The PANELS half of restoring a previously chosen point (spec D-15) — the camera and the
		 * marker's own `data-state="active"` are `setPoints( groups, { focus } )`'s job, not this
		 * function's (see {@see pendingRestoreGroup}).
		 *
		 * RUNS BEFORE THAT `setPoints()` CALL, NOT AFTER IT, whenever there is a group to restore.
		 * `openCard()` below is what reserves the sidebar's screen area on its way in
		 * (`setStageOpen()` → `listToggle` → `provider.setMargin()`), and the focus move
		 * `setPoints()` issues passes `useMapMargin: true` — so this has to have happened already
		 * or that option reads an empty reservation and centres the point on the WHOLE map,
		 * half of it under the panel this call is opening. The call site carries the measurements.
		 *
		 * Called ONCE per session, gated by `selectionRestoreAttempted` at both call sites — see
		 * that flag's own docblock for why. `setSelectedId()` drives both the CTA
		 * label and the row highlight, and it MUST run before the card opens: `renderCard()` reads
		 * `_selectedId` to decide whether the CTA says «Выбрать» or «Продолжить оформление», and
		 * the whole point of the operator's 06.08.2026 decision is that the reopened picker shows
		 * the latter straight away.
		 *
		 * OPENS THE CARD, NOT THE LIST (operator decision, 06.08.2026 — supersedes spec §5.3's
		 * `openList()`): reopening with a point already chosen must show that point's DETAILS and
		 * its «Продолжить оформление» button immediately, not a sidebar list the customer has to
		 * click through again. The accepted consequence is that the list behind the card holds a
		 * single row — `setPoints( groups, { focus } )` opens the map at MAX_ZOOM and the sidebar
		 * list is viewport-filtered (`visibleChange` → `setVisible()`), so only the restored marker
		 * is in view. That is the zoom's doing, not a bug, and the zoom stays.
		 *
		 * `'restore'` IS THE ORIGIN THAT MEANS "THE CAMERA IS SOMEBODY ELSE'S JOB". Every other
		 * origin makes the `cardOpened` listener below call `provider.focusGroup()`; this one must
		 * not, because the camera half of this pass goes out as `setPoints( groups, { focus } )` —
		 * BEFORE the draw, which is the one order that does not park the restored marker's overlay
		 * off screen (s52; see {@see pendingRestoreGroup} and the two ymaps gotchas it cites). A
		 * second camera move here would re-enter that race for no gain. The sidebar half is still
		 * `openCard()`'s own `setStageOpen()` → `listToggle` → `provider.setMargin()`, unchanged —
		 * and now that this whole function runs ahead of `setPoints()`, that reservation is what
		 * the single move reads through `useMapMargin: true`.
		 *
		 * The card is opened on `selectedId` specifically, never on the group's first point: a
		 * co-located group can hold several points and the one the customer chose is the one whose
		 * tab must be showing.
		 *
		 * A point that is no longer in the results (`group` null) still marks the id — the map
		 * opens in its ordinary default view, the sidebar stays closed, and the field is left
		 * alone for the checkout-processing backstop to judge. No fourth empty-state message —
		 * the three that exist (`emptyLocality`/`emptyInView`/`noResults`) are deliberately
		 * distinct.
		 *
		 * @param {Object|null} group whatever {@see pendingRestoreGroup} resolved for this pass.
		 * @returns {void}
		 */
		function restoreSelection( group ) {
			var selectedId = fieldValue( config.fieldId );

			if ( ! selectedId || ! panels ) {
				return;
			}

			panels.setSelectedId( selectedId );

			if ( ! group ) {
				return;
			}

			panels.openCard( group, selectedId, 'restore' );
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
		 * `locality` is {@see resolveLocalityKey}'s answer (Task 15; issue #159), NOT
		 * {@see resolveLocality}'s DOM read — see that function's own docblock for why: the
		 * server addresses points by the Location Provider layer's own record/key when a
		 * plugin has wired it, falling back to the pre-#159 DOM read otherwise.
		 *
		 * @returns {Object}
		 */
		function bulkQuery() {
			return { locality: resolveLocalityKey( config ), types: currentTypeFilter };
		}

		/**
		 * Pulls one point's FULL record and merges it over the sparse one the card is showing —
		 * the missing half of the viewport strategy (issue #219).
		 *
		 * A `STRATEGY_VIEWPORT` source may answer `fetch_points()` without the constraint inputs
		 * (`accepts_cod`, `max_weight`); `Pickup_Controller::get_point_data()` re-runs
		 * `Constraint_Checker` over the full record, so the response carries a REAL verdict where
		 * the listing carried a permissive-by-omission one. Without this call the customer is
		 * offered every point as selectable and only learns otherwise at confirmation.
		 *
		 * `bulk` never calls this: its listing already carried the full record, so a request per
		 * card open would be pure waste against the merchant's carrier quota.
		 *
		 * ONCE PER POINT PER LISTING. Re-opening a card, switching tabs inside a co-located group
		 * and re-entering from the map all funnel through `cardOpened`, and re-entering the SAME
		 * point learns nothing new.
		 *
		 * (That sentence was a FOSSIL until #233: a tab click only re-rendered the card and never
		 * emitted `cardOpened`, so the second point of a co-located group was never fetched at
		 * all and kept its permissive listing verdict forever. `buildTabs()` now reports the move
		 * like every other route does — see its own comment.) The memo is emptied whenever a listing fetch succeeds, because that is the
		 * moment the cart — and therefore the verdict — may have moved underneath us.
		 *
		 * DEGRADES TO EXACTLY TODAY'S BEHAVIOUR ON FAILURE, which is why it stays quiet: the
		 * SELECT route runs `fetch_details()` + `Constraint_Checker` itself
		 * ({@see handle_select_request}), so a refused point is still refused — the refusal simply
		 * arrives when the customer clicks rather than when the card opens. Blocking the card on a
		 * request that only ever IMPROVES what it already shows would trade a working picker for a
		 * spinner.
		 *
		 * @param {string} pointId
		 * @returns {void}
		 */
		function refreshPointDetails( pointId ) {
			var id = String( pointId );

			// `detailsInFlight` is checked alongside the landed-memo, and `destroyed` alongside
			// both: the first stops a second request for a point already being asked about (see
			// that map's own docblock — the per-listing memo cannot, because a successful listing
			// wipes it), the second stops a listing that settles DURING teardown from acquiring a
			// lock the session has already released on its way out.
			if (
				'viewport' !== config.strategy ||
				! id ||
				destroyed ||
				detailedPoints[ id ] ||
				detailsInFlight[ id ]
			) {
				return;
			}

			detailedPoints[ id ] = true;
			detailsInFlight[ id ] = true;
			bumpLoading();

			// Issue #223: locks the card's CTA for the window this fetch is in flight — the card
			// is still showing the sparse listing's permissive-by-omission verdict, which is
			// exactly what this request may be about to overturn. A SEPARATE lock from the
			// confirmation one (`pendingSelectionToken`) — see `verdictPendingToken`'s own
			// docblock and `Panels.prototype.setVerdictPending`'s for why the two must never be
			// merged. Both call sites of this function (the `cardOpened` listener below, and the
			// re-ask a successful listing triggers — see `fetchAndSetPoints()`) only ever pass the
			// CURRENT `cardPointId`, so acquiring the lock here is always acquiring it for the
			// point the card is showing right now.
			//
			// A TOKEN, NOT THE POINT ID — the same correction `pendingSelectionToken` above already
			// had to make, for the same reason. TWO fetches for the SAME point genuinely overlap:
			// this one starts when the card opens, then the camera move that card open causes
			// triggers a listing, and that listing's success clears `detailedPoints` and re-asks
			// for the very same point (see `fetchAndSetPoints()`). Keyed on the id, the FIRST
			// fetch settling would match `verdictPendingPointId` and release a lock the SECOND one
			// still needs held — reopening the exact window #223 exists to close — and its older
			// answer could also be applied over the newer one. A token is unique per request, so
			// "is the answer that just arrived still the one the card is waiting on?" has exactly
			// one true answer no matter how many fetches name the same point.
			var myVerdictToken = ++verdictTokens;

			verdictPendingToken = myVerdictToken;
			verdictPendingPointId = id;

			if ( panels && ! destroyed ) {
				panels.setVerdictPending( true );
			}

			// `realDataSource.fetchDetails( id )` is still called SYNCHRONOUSLY, right here, exactly
			// as before this fix — several call sites (this file's own tests among them) rely on the
			// dataSource being asked THIS TICK, before anything awaits. What changed is the `try`:
			// a dataSource that throws SYNCHRONOUSLY (before ever returning a promise) used to skip
			// the `.then( resolve, reject )` pair below entirely — `bumpLoading()` already ran,
			// `dropLoading()` never would, and the shared indicator would stay `is-loading` forever
			// (the exact failure mode {@see dropLoading}'s own docblock rules out). Catching the
			// throw and converting it to an already-rejected promise routes it through the SAME
			// reject handler an async rejection already gets — `dropLoading()` plus the memo
			// eviction below — rather than duplicating that cleanup a second time here.
			var detailsPromise;

			try {
				detailsPromise = realDataSource.fetchDetails( id );
			} catch ( error ) {
				detailsPromise = Promise.reject( error );
			}

			detailsPromise.then(
				function( point ) {
					dropLoading();
					delete detailsInFlight[ id ];

					// Non-negotiable per issue #223: release on EVERY outcome, success included —
					// but ONLY when THIS fetch still owns the lock. Two things can have taken it
					// since: the card moving to another point (whose own `refreshPointDetails()`
					// re-acquired it), or a listing re-asking for this SAME point. Releasing
					// unconditionally would stomp a still-live lock the moment this abandoned
					// fetch happens to settle — the s53 staleness-guard shape this deliberately
					// does not repeat. See `releaseVerdictPending()`.
					var ownsLock = myVerdictToken === verdictPendingToken;

					if ( ownsLock ) {
						releaseVerdictPending();
					}

					// The card can move to another point while this is in flight — a marker click
					// and a sidebar row both swap it without waiting for anything. Applying then
					// would write one point's record over whatever the customer is now reading.
					//
					// Deliberately NOT also gated on `ownsLock`. That would look symmetrical and
					// would be wrong: a customer who leaves this point and comes BACK while the
					// request is still travelling has released the lock on the way out and, thanks
					// to `detailsInFlight`, starts no new request on the way back — so this answer
					// arrives owning no lock, about the point the card is showing, with nothing
					// else on its way. Discarding it would throw away the only verdict anyone is
					// going to fetch. Ownership decides who may RELEASE THE LOCK; `cardPointId`
					// decides whose answer may LAND. Two questions, two guards.
					if ( destroyed || ! panels || id !== cardPointId ) {
						return;
					}

					// Remembered so the next listing rebuild can re-apply it — see
					// `detailsById`'s own docblock for the flash this prevents.
					detailsById[ id ] = point;

					panels.updatePoint( id, point );
				},
				function() {
					dropLoading();
					delete detailsInFlight[ id ];

					// Same non-negotiable release, same ownership guard — see the resolve branch
					// above. A failed fetch must not leave the card locked forever either.
					if ( myVerdictToken !== verdictPendingToken ) {
						// SUPERSEDED. Release nothing (a live fetch holds the lock) and, just as
						// importantly, evict nothing: `detailedPoints[ id ]` now belongs to that
						// LIVE request, and clearing it here would let a third fetch start for a
						// point already being fetched.
						return;
					}

					releaseVerdictPending();

					// Retryable: the memo is what makes the next card open ask again, and the
					// point keeps its permissive listing verdict until something says otherwise.
					delete detailedPoints[ id ];
				}
			);
		}

		/**
		 * The pooled points, in insertion order.
		 *
		 * @since 2.0.2
		 * @returns {Array} never null.
		 */
		function poolValues() {
			return poolOrder.map( function( id ) {
				return pointPool[ id ];
			} );
		}

		/**
		 * Bounds the pool to `config.maxAccumulatedPoints` when the domain asked for a bound
		 * (#234). A missing/zero/negative value means UNLIMITED and this returns immediately —
		 * that is the shipped default, and it is measured-safe rather than assumed: see the
		 * design doc's measurement table.
		 *
		 * Evicts OLDEST-INSERTED first — walking {@see poolOrder}, NOT `Object.keys( pointPool )`,
		 * which is not insertion order at all for the numeric ids this carrier uses (see that
		 * array's own docblock). Skips any point the customer would notice losing:
		 *
		 *  - the current selection — the field's own value; losing it would strand the
		 *    checkout on a point the map can no longer draw;
		 *  - the open card's point — {@see cardPointId}; a card whose point vanished mid-read
		 *    is the #232 defect wearing a different hat.
		 *
		 * The cap is a TARGET, not a guarantee: if every pooled point is protected the pool stays
		 * above `max`, by design — evicting the point the customer has open, or the one they
		 * already chose, to honour a number would be the worse failure. Bounded in practice by
		 * there being at most two protected ids.
		 *
		 * "In frame" is deliberately NOT a third exemption: it would need a rectangle test of
		 * our own, and the frame is exactly where a re-listing puts points back a moment later
		 * anyway.
		 *
		 * @since 2.0.2
		 * @returns {void}
		 */
		function trimPointPool() {
			var max = parseInt( config.maxAccumulatedPoints, 10 );

			if ( ! max || max < 1 ) {
				return;
			}

			var over = poolOrder.length - max;

			if ( over < 1 ) {
				return;
			}

			var selected = fieldValue( config.fieldId );
			var protectedIds = Object.create( null );

			if ( selected ) {
				protectedIds[ String( selected ) ] = true;
			}

			if ( cardPointId ) {
				protectedIds[ String( cardPointId ) ] = true;
			}

			// Walks {@see poolOrder} — TRUE insertion order, oldest first. Rebuilds the order
			// array in one pass rather than splicing per eviction, so trimming stays linear.
			var kept = [];

			poolOrder.forEach( function( id ) {
				if ( over < 1 || id in protectedIds ) {
					kept.push( id );

					return;
				}

				delete pointPool[ id ];
				over -= 1;
			} );

			poolOrder = kept;
		}

		/**
		 * Merges one listing into the pool and returns the full set to draw (#234).
		 *
		 * THE LISTING WINS on conflict: it is the fresher carrier record. Whatever a detail
		 * fetch already learned is re-applied AFTER grouping, from `detailsById`, so a sparse
		 * re-merged record cannot erase it — that ordering is load-bearing, see
		 * {@see fetchAndSetPoints}.
		 *
		 * A point with no `id` is passed through un-pooled rather than dropped: `id` is the
		 * pool's whole identity and a record without one cannot be de-duplicated. Such a point is
		 * therefore visible for THIS listing only and is gone on the next one — it does not join
		 * the union. That asymmetry is deliberate and it is also unreachable in practice:
		 * `Pickup_Point::from_array()` REQUIRES `id` and returns null without it, so no such
		 * record can reach the browser through the framework's own contract. The branch is
		 * defensive, not a supported shape — pooling it under a synthesised key would trade an
		 * impossible case for a real one, re-adding an indistinguishable duplicate on every
		 * listing forever. (Adversarial review flagged the original wording here, which claimed
		 * union semantics this branch does not provide.)
		 *
		 * @since 2.0.2
		 * @param {Array} points this listing's points.
		 * @returns {Array} every point to draw.
		 */
		function mergeIntoPool( points ) {
			var passthrough = [];

			( points || [] ).forEach( function( point ) {
				if ( ! point || null === point.id || undefined === point.id ) {
					if ( point ) {
						passthrough.push( point );
					}

					return;
				}

				var id = String( point.id );

				if ( ! ( id in pointPool ) ) {
					poolOrder.push( id );
				}

				pointPool[ id ] = point;
			} );

			trimPointPool();

			return poolValues().concat( passthrough );
		}

		/**
		 * Empties the pool.
		 *
		 * INVARIANT: every caller that clears the pool must also clear the detail memo, and
		 * vice versa — {@see forgetPointDetails}. `geo.groupByPosition()` does not deep-copy,
		 * so the detail fields re-applied in {@see fetchAndSetPoints} land on the pooled point
		 * objects themselves; dropping `detailsById` while keeping the pool would strand those
		 * fields with nothing left to re-derive them from, and dropping the pool while keeping
		 * `detailsById` would re-apply a verdict computed against a cart that has since moved.
		 *
		 * @since 2.0.2
		 * @returns {void}
		 */
		function resetPointPool() {
			pointPool = Object.create( null );
			poolOrder = [];
			poolGeneration += 1;
		}

		function fetchAndSetPoints( query ) {
			bumpLoading();

			// #234: the generation this listing belongs to. Anything that empties the pool while
			// this request is in flight makes its answer describe a state nobody is looking at
			// any more — see the check in the resolve branch.
			var myGeneration = poolGeneration;

			// `realDataSource.fetchPoints( query )` is still called SYNCHRONOUSLY, right here — see
			// {@see refreshPointDetails}'s identical `try` immediately above for why: several
			// callers (the provider's own `boundsChange`, this file's tests) expect the dataSource
			// to be asked THIS TICK, and deferring the call itself (e.g. via a bare
			// `Promise.resolve().then( function() { return realDataSource.fetchPoints( query ); } )`
			// wrapper) would push it a microtask later than every one of them observes today.
			// What the `try` adds is a safety net for a dataSource that throws SYNCHRONOUSLY (before
			// ever returning a promise) — without it, `bumpLoading()`'s increment above is never
			// balanced by a `dropLoading()`, because the exception unwinds the call stack straight
			// past the `.then( resolve, reject )` pair below. Catching it and converting it to an
			// already-rejected promise routes it through that SAME reject branch instead — the exact
			// cleanup (`dropLoading()`, `clearInitialBusy()`, the degrade UI, the `Promise.reject()`
			// re-throw) an async rejection already gets, not a second copy of it. Every existing
			// caller of this function chains `.catch( function() {} )` onto its return value, so the
			// settled-promise contract they observe is unchanged either way.
			var pointsPromise;

			try {
				pointsPromise = realDataSource.fetchPoints( query );
			} catch ( error ) {
				pointsPromise = Promise.reject( error );
			}

			return pointsPromise.then(
				function( points ) {
					dropLoading();

					// Task 16 (spec V-4): THIS fetch is the one stage 2's overlay was waiting on —
					// win, lose, or empty, the customer gets an answer and the map becomes usable.
					// A no-op past the first settle of this start() cycle (see the flag's own docblock).
					clearInitialBusy();

					if ( destroyed ) {
						return points;
					}

					// #234: a reset happened while this was travelling. Merging now would put the
					// pre-reset carrier answer back into a pool that was deliberately emptied —
					// permanently, since nothing removes a pooled point. `dropLoading()` and
					// `clearInitialBusy()` above have already run, so the customer's spinner state
					// is correct; there is simply nothing here worth drawing.
					//
					// NOT viewport-only, though only `viewport` accumulates (adversarial review,
					// 09.08.2026). The first version exempted `bulk` on the reasoning that a late
					// listing is still the best answer available — which is false once a reset has
					// happened: the reset means a NEWER fetch went out, and if that one has already
					// drawn, letting the older one through replaces fresh data with stale. A
					// generation only moves when something invalidated the previous answer, so the
					// guard is about staleness, not about which strategy is running.
					if ( myGeneration !== poolGeneration ) {
						return points;
					}

					// DELIBERATELY NOT WIPED HERE (#232). This used to clear `detailedPoints` on
					// every successful listing, on the reasoning that a fresh listing carries a
					// freshly computed verdict. But a bbox refetch is not a cart change: panning
					// the map cannot alter the weight or the payment method, and wiping on every
					// pan meant the open card re-requested its details each time, showing its own
					// content for a beat and then losing it again. What DOES change the cart is a
					// checkout update, and that arrives as {@see refresh} — which is where the
					// memo is cleared now, via {@see forgetPointDetails}.

					// #234: under `viewport` the drawn set is the UNION of every listing this
					// session, so the customer never loses points by zooming out and back. Under
					// `bulk` there is one listing and it is authoritative — the pool is bypassed
					// entirely rather than being a union of one, so `bulk` keeps its exact
					// previous behaviour including "a later listing REPLACES the set".
					var drawable = 'viewport' === config.strategy ? mergeIntoPool( points ) : points;
					var groups = geo.groupByPosition( drawable );

					// Re-apply what detail fetches already learned, BEFORE anything draws or
					// renders these groups (#232). `geo.groupByPosition()` builds them from the
					// sparse listing, so without this the map, the sidebar and the open card all
					// fall back to the permissive-by-omission verdict a detail fetch had already
					// overturned — visibly, as content that appears and then disappears.
					groups.forEach( function( group ) {
						group.points.forEach( function( point ) {
							var stored = detailsById[ String( point.id ) ];

							if ( ! stored ) {
								return;
							}

							Object.keys( stored ).forEach( function( key ) {
								// `id` is never overwritten, matching `Panels#updatePoint()`'s own
								// rule: a record re-keying a point is the one corruption here that
								// nothing downstream would detect.
								if ( 'id' !== key ) {
									point[ key ] = stored[ key ];
								}
							} );
						} );
					} );

					var byKey = {};

					groups.forEach( function( group ) {
						byKey[ group.key ] = group;
					} );
					groupsByKey = byKey;

					// Resolved BEFORE the draw: on the pass that restores a chosen point the
					// provider opens the map AT that point (camera first, features second) rather
					// than fitting the whole set and being moved off it a beat later — see
					// {@see pendingRestoreGroup}.
					var restoreGroup = pendingRestoreGroup();

					// THE PANELS HALF RUNS FIRST ON THE RESTORE PASS, and only there. Opening the
					// card is also what RESERVES the sidebar's screen area — `openCard()` →
					// `setStageOpen()` → `listToggle` → `provider.setMargin()` — and the ONE camera
					// move this pass makes goes out from the `setPoints()` call immediately below,
					// carrying `useMapMargin: true`. Issued before the reservation existed, that
					// option had nothing to read: the restored point centred on the map's GEOMETRIC
					// middle, half of it underneath the panel about to slide over it. Rig-measured
					// on a 1024px map with a 320px panel: marker centre x=640 (the full map's
					// midpoint) before this ordering, x=480 (the midpoint of the strip still
					// visible beside the panel) after it — see {@see restoreSelection}.
					//
					// The one-shot flag is claimed HERE rather than in the `points.length > 0`
					// branch below, whose own guarded call now only ever sees the `null` group (the
					// stored point is gone) — that case reserves nothing, moves no camera, and must
					// keep its position under `points.length > 0` so an empty fetch cannot burn the
					// session's single restore attempt on nothing.
					if ( restoreGroup ) {
						selectionRestoreAttempted = true;
						restoreSelection( restoreGroup );
					}

					provider.setPoints( groups, restoreGroup ? { focus: restoreGroup.key } : null );

					if ( panels ) {
						// #234: from the DRAWN set, not this listing — a chip that vanishes
						// because the customer panned past the last point of its type, while
						// points of that type are still drawn, is the same defect this issue
						// is about, one surface over.
						panels.setTypes( extractTypes( drawable ) );
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
							//
							// By the time this runs, `restoreGroup` is necessarily NULL: a non-null
							// one already restored above, ahead of `setPoints()`, and claimed the
							// flag on its way past (see that block for why it has to go first). What
							// is left for here is the "stored point is gone" case — mark the id and
							// nothing else — which has no card to open, no margin to reserve and no
							// camera move to precede, and which must stay gated behind
							// `points.length > 0` so an empty fetch does not consume the attempt.
							if ( ! selectionRestoreAttempted ) {
								selectionRestoreAttempted = true;
								restoreSelection( restoreGroup );
							}
						}
					} else if ( 'bulk' === config.strategy || 0 === visibleGroupCount ) {
						// `emptyLocality` (a locality genuinely has none) vs `emptyInView` (the
						// current viewport does) — the SAME shared function backs both the bulk
						// strategy's one-shot fetch and the viewport strategy's per-bbox
						// `boundsChange` fetch, so the key is chosen from `config.strategy`, not
						// hardcoded to either. Distinct from `noResults`, which stays reserved for
						// the search view finding nothing (spec V-5).
						//
						// #234: under `viewport` an empty listing no longer means an empty screen.
						// A listing can come back empty for a frame the pool still has points in —
						// rig-measured on live Russian Post, where one frame answered 0 after 16.9s
						// while its neighbours answered 1500+. Printing "nothing here" over drawn
						// markers is worse than printing nothing, so the message waits until the
						// provider itself reports an empty frame. `bulk` keeps its old behaviour
						// exactly: it has no frame-driven refetch to correct a wrong message later.
						showFetchMessage( 'bulk' === config.strategy ? 'emptyLocality' : 'emptyInView' );
					}

					// Issue #225's own gap: `detailedPoints = {}` above is correct — the cart may
					// have changed under us — but nothing ELSE re-asks for a card that is ALREADY
					// open when this listing lands. Before this, an open card fell back to the
					// sparse, permissive listing verdict (Part 1/2's fix keeps that card SHOWING,
					// but showing stale data is still the bug this line closes) until the customer
					// happened to close and reopen it. Runs LAST, after any restore pass above:
					// `restoreSelection()`'s own `openCard()` call (when it ran) already drove
					// `refreshPointDetails()` through the `cardOpened` listener while the memo was
					// still fresh, so `detailedPoints[ cardPointId ]` is already `true` and this
					// call is a harmless, guard-caught no-op for that pass — never a double fetch.
					// Unconditional on `points.length` — an open card's point can legitimately have
					// panned OUT of this exact listing (Part 1's "keep the stale object" case) and
					// still deserves a fresh verdict for whatever cart change just happened.
					if ( panels && cardPointId ) {
						refreshPointDetails( cardPointId );
					}

					return points;
				},
				function( reason ) {
					// See the resolve branch above — a failed fetch settles stage 2 exactly like a
					// successful one does; the customer gets the error card, not a map stuck
					// non-interactive forever over a request that will never come back.
					clearInitialBusy();
					dropLoading();

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
		 * Issue #260 supersedes what this docblock used to record as a deliberate consequence —
		 * that with no card there was no lock either, so an embedded provider could report a
		 * SECOND selection while the first was still in flight (measured: two round trips, the
		 * later answer winning), and that debouncing its own confirm UI was the provider's job.
		 * The real cost of that position turned out to be borne by the CUSTOMER, not the
		 * provider: with no card there was also no sign of work at all, so the person most
		 * likely to click twice was the one who could not tell the first click had registered.
		 * {@see acquireSelectionBusy} now puts the dialog's own loading overlay up for the
		 * duration, which both reports the state and physically intercepts the second click —
		 * see that function's docblock for why this does not breach D-3.
		 *
		 * @param {Object} point
		 * @returns {void}
		 */
		/**
		 * Releases the card's confirmation lock. A no-op with no card to release — no panels at
		 * all (`ownsChrome`), or a session already torn down, whose panels have been destroyed
		 * along with their DOM.
		 *
		 * @returns {void}
		 */
		function releaseSelectionBusy() {
			// UNCONDITIONALLY FIRST, ahead of the `destroyed` guard below: a still-pending
			// {@see SELECTION_BUSY_DELAY_MS} timer must be cancelled even when the session is
			// already gone, or it fires into a dead session and paints an overlay onto a dialog
			// nobody is looking at, with nothing left that would ever take it down again.
			if ( null !== selectionBusyTimer ) {
				window.clearTimeout( selectionBusyTimer );
				selectionBusyTimer = null;
			}

			if ( destroyed ) {
				return;
			}

			if ( panels ) {
				panels.setSelectionBusy( false );

				return;
			}

			if ( ownsChrome ) {
				modal.hideLoading();
			}
		}

		/**
		 * Marks a confirmation round trip as started — the acquire half of
		 * {@see releaseSelectionBusy}, and the one place that decides WHICH surface says so.
		 *
		 * Under `!ownsChrome` that surface is the card: `setSelectionBusy( true )` turns its CTA
		 * into «Проверяем доступность…» and locks it, which is also what stops a second submit.
		 *
		 * Under `ownsChrome` there is no card — and before issue #260 that meant `panels` was null,
		 * this call was a silent no-op, and the customer got NO sign of work at all: the carrier's
		 * widget disables its own button, and then the map simply sits there until the modal
		 * disappears. Rig-measured on the live Почта widget: `select_requested +1 ms` →
		 * `select_resolved +8067 ms`, all of it our confirmation round trip, which D-1 makes
		 * mandatory (nothing is applied before the server answers, because the domain may refuse
		 * the point). The 8 seconds themselves are the rig, not us — that stand's own baseline is
		 * 8.7-11.1 s for an empty `/wp-json/` — but the MISSING SIGNAL does not depend on the
		 * speed, and it survives into production, where a real `Point_Source::fetch_details()`
		 * goes out to the carrier's API.
		 *
		 * The dialog's own loading overlay is what answers it — NOT new chrome around the
		 * provider. That distinction is what keeps D-3 intact: D-3 says the framework draws no
		 * list and no card for a provider that owns the whole picker; it does not say the
		 * framework may not report the state of ITS OWN request on ITS OWN dialog.
		 * `WoodevModal#showLoading()` is additive by construction — it overlays the body without
		 * touching the body's children (see its docblock), so the provider's iframe underneath is
		 * untouched and keeps its own state.
		 *
		 * IT ALSO CLOSES THE SECOND HALF OF #260, and that is not a side effect worth losing: with
		 * no card there is no lock either, so an embedded provider could report a SECOND selection
		 * while the first was still travelling — measured, two round trips, the later answer
		 * winning. The overlay is `position: absolute; inset: 0` with a background and no
		 * `pointer-events: none`, so it physically intercepts the click that would start the
		 * second one. A customer who cannot see that anything is happening is exactly the customer
		 * who clicks again.
		 *
		 * @returns {void}
		 */
		function acquireSelectionBusy() {
			if ( panels ) {
				panels.setSelectionBusy( true );

				return;
			}

			if ( ownsChrome ) {
				// `confirming` («Проверяем…»), never `checkingAvailability` («Проверяем
				// доступность…»): the two read almost alike but name DIFFERENT operations, and
				// this one is the confirmation round trip. `checkingAvailability` belongs to
				// issue #223's lazy detail fetch, a state that cannot even occur here — it is
				// driven by the card, and under `ownsChrome` there is no card. The card's own
				// CTA makes the same distinction, in `pickup-panels.js`'s `renderCard()`.
				//
				// Held back by {@see SELECTION_BUSY_DELAY_MS} so the carrier widget's own
				// button-disable is visible first — see that constant for why, and for what the
				// delay costs. The timer is CANCELLED, never merely ignored, by
				// {@see releaseSelectionBusy}, which every path out of a confirmation runs through.
				selectionBusyTimer = window.setTimeout( function() {
					selectionBusyTimer = null;

					if ( destroyed ) {
						return;
					}

					modal.showLoading( text( config, 'confirming' ) );
				}, SELECTION_BUSY_DELAY_MS );
			}
		}

		/**
		 * Releases the card's verdict-pending lock (issue #223), if this session currently holds
		 * one — a no-op otherwise. The single entry point for every path that makes an in-flight
		 * detail fetch stop being about the point the card currently shows: the fetch itself
		 * settling ({@see refreshPointDetails}, both outcomes), the card moving to a different
		 * point before it does (the `cardOpened` listener, below), and the session being torn
		 * down.
		 *
		 * Mirrors {@see invalidateSelection}'s own "release on the move, not on the settle"
		 * discipline: waiting for a stale fetch to settle before releasing would leave the card —
		 * now showing a DIFFERENT point that may not need a fetch of its own at all — locked for
		 * however long the abandoned request takes to come back, over a state that no longer
		 * describes what is on screen.
		 *
		 * @returns {void}
		 */
		/**
		 * Forgets everything learned from detail fetches — both the "already asked this listing"
		 * memo and the stored records themselves (#232).
		 *
		 * Called from {@see refresh} ONLY, because a checkout update is the one event that can
		 * actually invalidate a verdict: the cart weight or the chosen payment method may have
		 * moved, and every stored `selectable` was computed against the old one. A bbox refetch
		 * is explicitly NOT such an event — see the note where the old per-listing wipe used to
		 * be, in {@see fetchAndSetPoints}.
		 *
		 * Clearing the memo is what makes the open card re-ask on the next listing: the re-ask
		 * at the end of {@see fetchAndSetPoints} is guard-caught into a no-op while the memo
		 * still holds the point, and becomes a real request once this has run.
		 *
		 * @returns {void}
		 */
		function forgetPointDetails() {
			detailedPoints = {};
			detailsById    = {};
		}

		function releaseVerdictPending() {
			if ( 0 === verdictPendingToken ) {
				return;
			}

			verdictPendingToken = 0;
			verdictPendingPointId = null;

			if ( panels && ! destroyed ) {
				panels.setVerdictPending( false );
			}
		}

		/**
		 * Drops whatever confirmation the staleness guard currently holds, releasing the card lock
		 * that came with it — the single entry point for every path that makes an in-flight
		 * confirmation stop being about anything current (spec D-9): the card moving to another
		 * point, the dialog being dismissed, the session being destroyed.
		 *
		 * Releasing the lock HERE rather than when the dropped answer eventually lands is
		 * invariant 1 of `pendingSelectionToken`'s docblock: the card must be usable for whatever
		 * it shows now, immediately, not after a round trip whose answer is going to be thrown
		 * away. It is also what makes invariant 2 affordable — {@see finishSelection} can then
		 * leave the lock strictly alone unless it still owns it.
		 *
		 * @returns {void}
		 */
		function invalidateSelection() {
			if ( 0 === pendingSelectionToken ) {
				return;
			}

			pendingSelectionToken = 0;
			pendingSelectionPointId = null;

			releaseSelectionBusy();
		}

		/**
		 * Whether `token`'s answer is still the one this session's card is waiting for.
		 *
		 * @param {number} token
		 * @returns {boolean}
		 */
		function ownsSelection( token ) {
			return ! destroyed && pendingSelectionToken === token;
		}

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

			var token = ++selectionTokens;

			/*
			 * THE GUARD IS ARMED BEFORE THE EVENT GOES OUT, NOT AFTER. `fireDocumentEvent()` is
			 * `dispatchEvent()`, which runs every listener INLINE before it returns — a listener
			 * is free to dismiss the dialog (or open another card) from inside this call, and both
			 * of those reach {@see invalidateSelection}. Assigning the guard afterwards would
			 * overwrite that invalidation with a marker for a picker the customer has already
			 * walked away from, and the answer would then be applied to it.
			 */
			pendingSelectionToken = token;
			pendingSelectionPointId = pointId;

			acquireSelectionBusy();

			fireDocumentEvent( EVENT_SELECT_REQUESTED, { fieldId: config.fieldId, point: point } );

			// The request leaves even when a `_requested` listener just invalidated it: the event
			// is OBSERVATIONAL and grants no veto (D-2 — the veto path is
			// `woodev_modal_before_close`). What the invalidation buys is that the ANSWER is
			// discarded, exactly as it is for every other D-9 path.
			realDataSource.selectPoint( {
				pointId: pointId,
				fieldId: config.fieldId,
			} ).then(
				function( result ) {
					finishSelection( token, point, result, null );
				},
				function( reason ) {
					finishSelection( token, point, null, reason );
				}
			);
		}

		/**
		 * Applies one settled confirmation — success or failure, both land here so the busy
		 * state is released in exactly one place.
		 *
		 * THE STALENESS GUARD. An answer is applied only while it is still the one this session's
		 * card is waiting for — {@see ownsSelection}, matched on the unique token
		 * {@see handleSelection} minted for THIS request, never on the point id (see
		 * `pendingSelectionToken`'s own docblock for the ABA race an id-only guard is). Locking
		 * the card stops a customer from starting a SECOND confirmation, but it cannot stop the
		 * paths that are not clicks inside it, and spec D-9 requires all of them covered: the map
		 * underneath the lock stays live (a marker click swaps the card to another point through
		 * `pointClick` → `openCard()`); Escape, the backdrop and the close button dismiss the
		 * dialog out from under the request (see {@see handleModalClosed}); and a fresh click on
		 * the checkout trigger tears this whole session down and opens another. Every one of them
		 * runs through {@see invalidateSelection}. An answer that outlives what it was about is
		 * discarded, never applied to whatever happens to be on screen now (spec D-9/D-10: the
		 * request is not aborted — the server may already have written the point into the WC
		 * session, and aborting would stop us listening without undoing any of that. The client
		 * and the server can therefore end up disagreeing; D-10 accepts that explicitly and
		 * still says to ignore the answer).
		 *
		 * THE GUARD IS RUN TWICE, once on each side of `woodev_pickup_point_select_resolved`.
		 * That event is dispatched synchronously — every listener runs INLINE before
		 * `fireDocumentEvent()` returns — so a listener is free to dismiss the dialog or destroy
		 * the session between the check and the apply. Re-running the same check afterwards is
		 * what stops the field write, the trigger relabel, the panels calls and the checkout
		 * refresh from all landing on a picker that stopped existing mid-function.
		 *
		 * THE BUSY RELEASE IS INSIDE THE GUARD, and that is not the same as the old "release only
		 * on the applied paths" mistake this file already fixed once. The lock belongs to the
		 * TOKEN: whoever ends a token's ownership releases it, so an answer this function
		 * discards has already had its lock released by whatever discarded it
		 * ({@see invalidateSelection}), while a LIVE confirmation's lock is never released by a
		 * stale one settling. Both of `setSelectionBusy()`'s obligations hold — no discarded
		 * answer leaves a dead CTA reading «Проверяем…», and no overlapping submit becomes
		 * possible while a request is still out.
		 *
		 * @param {number}      token  the confirmation's own token, from {@see handleSelection}.
		 * @param {Object}      point
		 * @param {Object|null} result the server's verdict, or null when the request failed.
		 * @param {Object|null} reason the transport failure, or null on success.
		 * @returns {void}
		 */
		function finishSelection( token, point, result, reason ) {
			var pointId = String( point && point.id );

			if ( ! ownsSelection( token ) ) {
				return;
			}

			fireDocumentEvent( EVENT_SELECT_RESOLVED, {
				fieldId: config.fieldId,
				point: point,
				result: result,
				error: reason,
			} );

			// A synchronous `_resolved` listener may have dismissed the dialog or torn the session
			// down while the event was being dispatched — see the docblock.
			if ( ! ownsSelection( token ) ) {
				return;
			}

			pendingSelectionToken = 0;
			pendingSelectionPointId = null;

			releaseSelectionBusy();

			if ( ! result ) {
				// Transport failure: nothing about the point was refused, so nothing is
				// remembered and the CTA stays alive (spec D-6/D-7).
				if ( panels ) {
					panels.showSelectionError( text( config, selectionErrorKey( config, reason, false ) ) );
				} else {
					// #265: with no panels (`ownsChrome`) this branch used to be entirely
					// silent — the customer pressed «Забрать здесь», waited out the round
					// trip, watched #260's overlay clear, and got nothing at all.
					//
					// `ownsChrome: true` here (#297) is what picks `selectFailedEmbedded` over
					// `selectFailed` — see {@see selectionErrorKey}'s own docblock for why the
					// same "Попробуйте ещё раз" wording is wrong under a carrier widget whose
					// confirm control does not come back.
					announceWithoutPanels( text( config, selectionErrorKey( config, reason, true ) ) );
				}

				return;
			}

			if ( ! result.allowed ) {
				if ( panels ) {
					panels.setPointVerdict( pointId, {
						allowed: false,
						reason: 'string' === typeof result.reason ? result.reason : null,
					} );
				} else {
					// #265, the other silent branch. Same precedence the panels' own verdict
					// warning uses (`pickup-panels.js`: `selectable.reason || 'blocked'`):
					// the domain's own words when it supplied any, the framework's generic
					// refusal otherwise.
					announceWithoutPanels(
						'string' === typeof result.reason && result.reason
							? result.reason
							: text( config, 'blocked' )
					);
				}

				return;
			}

			// `result.point` is a CORRECTED point, not a flag — the domain learned something
			// during confirmation that the listing did not know (a refined address, a fixed
			// postcode). Absent means "keep the point you already have", never "clear it".
			var resultPoint = result.point && 'object' === typeof result.point ? result.point : null;
			var accepted = resultPoint || point;
			var defaults = config.selection || {};

			// `accepted.short_address`'s escaping depends on where it actually came from, not on
			// which mode is active (issue #274 item 1 follow-up — the adversarial review's
			// "intra-mode" case). A corrected `result.point` is ALWAYS REST-escaped —
			// `Pickup_Controller::to_response_point()` builds it through `to_browser_array()`
			// unconditionally, `ownsChrome` or not. Absent a correction, `accepted` is the
			// original `point` this session started with, and THAT one's escaping tracks how it
			// reached `handleSelection()`: through `panels.on( 'select', … )` (panels render
			// REST-fetched, therefore escaped, listing data) when panels exist, or through
			// `provider.on( 'select', … )` — under `ownsChrome`, the ONLY source, straight from
			// `map-provider-embedded.js`'s own `normalizePoint()`, which is raw.
			var addressEscaped = !! resultPoint || !! panels;

			applySelection( config, accepted, addressEscaped );
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
		 * THE HOLD IS BOUNDED. `updated_checkout` is the release the customer normally gets, and
		 * it is NOT guaranteed to arrive: the checkout ajax can fail, be aborted by a newer one,
		 * or be answered by a build that never fires it. With that event as the only release, the
		 * CTA stays locked for the rest of the session over a refresh that already gave up, and
		 * the `one()` waiter stays bound to `document.body` holding the whole panels graph alive.
		 * A {@see REFRESH_TIMEOUT_MS} timer is therefore armed alongside the waiter, and both
		 * settle through the SAME {@see dropRefreshWaiter} — whichever gets there first cancels
		 * the other, so the lock is released exactly once either way.
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
				// Superseded before it ever fired (WooCommerce answered the previous refresh
				// with an ajax error, say). Drop it — releasing whatever lock it still held —
				// rather than stack a second one on the same target. Before this refresh takes
				// the lock, so the release below never cancels the acquire above it.
				dropRefreshWaiter();

				refreshBusyPanels = openPanels;
				openPanels.setSelectionBusy( true );

				// Both settle paths are the same call: `one()` self-cleans when it actually
				// fires, and `dropRefreshWaiter()` is idempotent, so running it from inside the
				// handler only releases the lock and cancels the timer.
				refreshWaiter = function() {
					dropRefreshWaiter();
				};

				window.jQuery( document.body ).one( 'updated_checkout', refreshWaiter );

				refreshTimer = window.setTimeout( function() {
					refreshTimer = null;
					dropRefreshWaiter();
				}, REFRESH_TIMEOUT_MS );
			}

			// #238 echo suppression needs NOTHING set here: the waiter armed just above IS the
			// "a refresh we caused is in flight" signal {@see isSelfRefreshInFlight} reads, and
			// it is armed before the trigger below can produce anything to suppress.
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}

		/**
		 * Is a checkout refresh THIS session caused still in flight (#238)? Read-only, with no
		 * lifetime of its own: `refreshWaiter` already IS the answer — armed by
		 * {@see refreshCheckout} at the moment it triggers `update_checkout`, and cleared by
		 * {@see dropRefreshWaiter} on every settle path there is (WooCommerce answering,
		 * {@see REFRESH_TIMEOUT_MS} expiring, a newer refresh superseding this one, and
		 * `destroy()`).
		 *
		 * Deriving it is the whole fix. A dedicated token needs its own clearing rules, and the
		 * one this replaced had no rule for "WooCommerce never answered at all" — so it stayed
		 * armed for as long as the picker stayed open and silently ate that session's next
		 * genuine cart change. Nothing here can outlive the request that armed it.
		 *
		 * Called once per raw `updated_checkout` by {@see handleCartChanged}, at EVENT time.
		 * Never call it from the debounced body — see the file docblock on the binding order
		 * that is what makes an event-time read able to tell the two apart at all.
		 *
		 * @returns {boolean} true while our own refresh is outstanding, i.e. this event may be
		 *                    its echo.
		 */
		function isSelfRefreshInFlight() {
			return null !== refreshWaiter;
		}

		/**
		 * Settles the pending checkout refresh, if any: cancels its timeout, unbinds its
		 * `updated_checkout` waiter, and releases the card lock it was holding.
		 *
		 * A `one()` handler that never fires is not self-cleaning: it stays on `document.body`
		 * holding this closure — and the whole `panels`/DOM graph it captures — alive for the
		 * life of the document. `updated_checkout` firing is NOT guaranteed (a failed checkout
		 * ajax, or a session torn down before the round trip returns), so the binding has to be
		 * dropped by hand.
		 *
		 * The lock is released here rather than only in the waiter for the same reason it is
		 * released in {@see invalidateSelection} rather than only in {@see finishSelection}: this
		 * IS the settle point, and `setSelectionBusy()` never self-balances (see its own docblock
		 * in `pickup-panels.js`). `destroy()` calls this BEFORE flipping `destroyed`, so a session
		 * torn down mid-refresh hands its panels back unlocked instead of frozen.
		 *
		 * @returns {void}
		 */
		function dropRefreshWaiter() {
			if ( null !== refreshTimer ) {
				window.clearTimeout( refreshTimer );
				refreshTimer = null;
			}

			if ( refreshWaiter && window.jQuery ) {
				window.jQuery( document.body ).off( 'updated_checkout', refreshWaiter );
			}

			refreshWaiter = null;

			if ( refreshBusyPanels ) {
				var held = refreshBusyPanels;

				refreshBusyPanels = null;

				if ( ! destroyed ) {
					held.setSelectionBusy( false );
				}
			}
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
		 * the pending token before it ever asks the modal to close.
		 *
		 * @param {CustomEvent} event
		 * @returns {void}
		 */
		function handleModalClosed( event ) {
			if ( ! event.detail || PICKUP_MODAL_ID !== event.detail.modalId ) {
				return;
			}

			invalidateSelection();
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
			// visible on screen, and `'restore'` (06.08.2026) moves the camera not at all, because
			// its move already went out ahead of the draw. `origin` (threaded through
			// `openCard()`/`cardOpened` by every caller below) is what lets this ONE listener still
			// decide per-call instead of forking into several near-identical ones.
			panels.on( 'cardOpened', function( payload ) {
				// The staleness guard's other half (spec D-9). Locking the card stops a second
				// confirmation from STARTING; it does not freeze the map underneath it, where a
				// marker click still routes through `pointClick` → `openCard()` and swaps the
				// card to a different point while the first one's answer is still in flight.
				// From that moment the answer is about a point the card no longer shows, and
				// dropping the pending confirmation here is what makes {@see finishSelection}
				// see that — and what hands the card back UNLOCKED for the point it now shows,
				// rather than leaving it frozen until an answer nobody wants finally lands.
				if ( 0 !== pendingSelectionToken && String( payload.pointId ) !== pendingSelectionPointId ) {
					invalidateSelection();
				}

				// Issue #223's own half of the same idea, one paragraph up: the PREVIOUS point's
				// in-flight detail fetch, if any, is no longer about what the card shows — release
				// its lock NOW rather than waiting for that fetch to settle (which could be
				// seconds away — the rig measured 5-10s — or never), matching the exact
				// "release on the move" discipline `invalidateSelection()` just applied above.
				// `refreshPointDetails()` below re-acquires the lock fresh if THIS point still
				// needs a fetch this listing.
				if ( 0 !== verdictPendingToken && verdictPendingPointId !== String( payload.pointId ) ) {
					releaseVerdictPending();
				}

				// Recorded BEFORE the early `'restore'` return below — a restored card shows a
				// point like any other, and its details are just as worth having. It is also what
				// {@see refreshPointDetails} checks its own late answer against.
				cardPointId = String( payload.pointId );

				refreshPointDetails( payload.pointId );

				// `'restore'` is the ONE origin that moves no camera at all (operator decision,
				// 06.08.2026 — the reopened picker shows the chosen point's CARD now, not the
				// list). Its camera move already went out, ahead of the draw, as
				// `setPoints( groups, { focus } )` — see {@see restoreSelection}. Falling through
				// to `focusGroup()` here would issue a SECOND move on top of it, re-entering the
				// s52 draw-vs-move race the ordering exists to avoid, for a camera that is already
				// exactly where this move would put it.
				// `'restore'` and `'tab'` are the two origins that move no camera at all.
				// `'restore'`'s move already went out ahead of the draw, as
				// `setPoints( groups, { focus } )` — see {@see restoreSelection}; falling through
				// would issue a SECOND move on top of it and re-enter the s52 draw-vs-move race.
				// `'tab'` (#233) needs none either: every point in a co-located group shares one
				// coordinate, so the camera is already exactly where a focus would put it.
				if ( 'restore' === payload.origin || 'tab' === payload.origin ) {
					return;
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
				//
				// #234: the pool goes first. The SERVER is what applies the type filter on this
				// strategy, so points pooled under the previous filter are not a subset of the
				// new answer — a union across two different filters describes no query anyone
				// made. Paired with forgetPointDetails() per resetPointPool()'s invariant.
				resetPointPool();
				forgetPointDetails();

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

			// The RETURN leg of this pair — the provider reporting that the camera has reached
			// either end of its zoom range — cannot be wired here: `provider` is still null at
			// this point (only `start()` constructs one), and `start()` REPLACES it on every
			// retry, so a subscription made once out here would be dropped by the first retry
			// anyway. It lives beside the other `provider.on()` calls in `start()`.

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

			var searchSubmitInFlight = false;

			// `searchSubmit` (Enter/the magnifier) RESOLVES what the dropdown is already showing —
			// it does not start a search of its own (#179). It used to call the SearchControl's
			// `search()`, which ran `map-provider-yandex.js`'s bounded GEOCODE provider, while the
			// list the customer was looking at had come from `suggest()`. Two services, one
			// question, and the geocoder ranks POIs above street addresses: typing «Чертановская
			// 66» offered five house numbers, pressing the magnifier replaced them with
			// «Chertanovskaya metro station». That is the same defect the gotcha
			// `ymaps-suggest-not-geocode-for-address-lists` recorded in s51 — its fix moved the
			// TYPING path onto `suggest()` and left this one behind.
			//
			// So: re-ask `suggestAddresses()` (free, and authoritative for what is on screen),
			// take its best hit, and hand it to the SAME `resolveAddress()` a click on a row uses.
			// One geocode is still spent — on RESOLVING a chosen address, which is the one role
			// `bounding-the-address-resolve-breaks-the-normal-case` says it should have.
			//
			// The busy flag is owned by this chain from end to end rather than by whichever event
			// happens to answer (work item 5, live-review round 2): the flag goes up
			// synchronously, and comes down in the tail of the chain whatever the outcome —
			// resolved, nothing suggested, or a rejected round trip. Nothing can strand it.
			// Guarded on the provider actually being able to do both halves; the embedded
			// provider owns its own chrome and offers neither, and raising a flag nothing will
			// ever lower is exactly the bug this guard exists for.
			panels.on( 'searchSubmit', function( payload ) {
				if ( searchSubmitInFlight
					|| ! provider
					|| 'function' !== typeof provider.suggestAddresses
					|| 'function' !== typeof provider.resolveAddress ) {
					return;
				}

				searchSubmitInFlight = true;

				provider.suggestAddresses( payload.query ).then( function( results ) {
					if ( destroyed || ! panels ) {
						return undefined;
					}

					var addresses = ( results && results.addresses ) || [];

					// Keep `lastAddresses` in step for the same reason the `searchType` handler
					// does — `searchAddressPicked` indexes into it, and a submit that refreshed
					// the dropdown without refreshing this would resolve the wrong row next.
					lastAddresses = addresses;

					if ( ! addresses.length ) {
						// Say so through the SAME preview the typing path renders into, rather
						// than leaving the customer's list untouched and the map still.
						panels.previewSearchResults( {
							points: ( results && results.points ) || [],
							addresses: [],
						} );

						return undefined;
					}

					// Close the list, exactly as clicking a row does (`pickup-panels.js`'s own
					// address-row handler calls `hideSearchResults()` itself). Both routes end in
					// the same place — an address resolved, the camera moving — so leaving the box
					// hanging open over the map on one of them was simply an omission, reported by
					// the operator 07.08.2026. NOT done on the no-suggestions branch above: that
					// one renders "ничего не найдено" INTO this box, and closing it would swallow
					// the only answer the customer gets.
					panels.hideSearchResults();

					// The FULL `query` form, never the trimmed `displayName` — see the
					// `searchAddressPicked` handler above for why the trimmed one re-geocodes
					// ambiguously.
					return provider.resolveAddress( addresses[ 0 ].query || addresses[ 0 ].displayName );
				} ).catch( function() {} ).then( function() {
					searchSubmitInFlight = false;
				} );
			} );

			// `searchReset` clears the input/results DOM itself (pickup-panels.js's own job) —
			// this file's half is dropping whatever provider-side search state belongs to it: the
			// "your address" pin and the stale `searchResults`, both owned by `clearAddress()`
			// (see map-provider-yandex.js's own docblock on why that file, not this one, owns it).
			//
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

			// #234: a retry rebuilds the provider from scratch, so the drawn set restarts
			// from nothing too. Paired with forgetPointDetails() per resetPointPool()'s
			// stated invariant.
			resetPointPool();
			forgetPointDetails();
			visibleGroupCount = 0;

			// Task 16 (spec V-4): every start() — the initial open AND every retry — runs through
			// the full "map drawn → points in flight → points in" sequence again, so the flag that
			// gates {@see clearInitialBusy} resets here too, not just once per session.
			busyClearedThisStart = false;

			provider.on( 'select', handleSelection );

			// The return leg of the zoom control: the panels emit a signed step and know nothing
			// about map-library zoom levels (D-3), so the provider — which owns the camera — is
			// what decides a limit has been reached, and the panels only dim the button it names.
			// Registered HERE rather than beside the `panels.on( 'zoom' )` forward leg because
			// `start()` builds a fresh provider on every retry; a subscription made once outside
			// would be silently dropped by the first one. The fail-open is that a provider which
			// never emits this simply leaves both buttons live.
			provider.on( 'zoomChange', function( limits ) {
				if ( panels ) {
					panels.setZoomLimits( limits );
				}
			} );

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

					// #234: what the empty-frame message is decided from — see
					// {@see visibleGroupCount}. Counted from the RAW keys, not the mapped
					// groups: a key the mount cannot resolve still means the provider is
					// drawing something there, and suppressing the message is the safe
					// direction (a missing message beats a false one over a full map).
					visibleGroupCount = ( keys || [] ).length;

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
				} );

				// searchCleared (D1a, live-review round 2 — the "crossik" bug): `clearAddress()`
				// no longer round-trips through an EMPTY `searchResults` to signal "cleared" — see
				// `map-provider-yandex.js`'s own docblock on why that used to re-open the results
				// box the customer had just closed and print "не найдено" at them. This IS the
				// box's actual close path now.
				provider.on( 'searchCleared', function() {
					panels.hideSearchResults();
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
				//
				// Deliberately UNGUARDED against issue #260's confirmation overlay, which reuses
				// this same single overlay element ({@see acquireSelectionBusy}). `start()` runs
				// again on every retry, so "a retry lands while a confirmation is in flight"
				// looks like it would hide that overlay early — but the only route to a retry is
				// `degrade( …, start )`, and under `ownsChrome` `hasDrawnPoints` is permanently
				// false (nothing here ever fetches for an embedded provider), so `degrade()`
				// always takes the DESTRUCTIVE `modal.showError()` branch. That replaces the
				// dialog body outright — the overlay is already gone, along with the provider's
				// own iframe, before this line could ever run. A guard here would be code for a
				// state that cannot occur. If `degrade()` ever gains a non-destructive path under
				// `ownsChrome`, this becomes reachable and needs `if ( 0 === pendingSelectionToken )`.
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

			// THE cart-change event (#232) — see {@see forgetPointDetails} for why this is the
			// only place details are forgotten, and no longer every listing.
			//
			// #234: the pool goes with it, ALWAYS, and the two calls must never be separated —
			// see {@see resetPointPool}'s stated invariant. A pooled point's `selectable` was
			// computed against the cart that just changed.
			//
			// #248 — WHY THE DESTRUCTIVE HALF SITS ABOVE THE `viewport` CONDITION BELOW, AND
			// MUST STAY THERE. It reads like a hole: under `viewport` with `lastBbox` still
			// null the pool is emptied and nothing refetches it. It is not one, because
			//
			//     A NON-EMPTY POOL IMPLIES A NON-NULL `lastBbox`.
			//
			// The pool's only writer is a resolved listing ({@see mergeIntoPool}, reached only
			// under `viewport`), and every fetch that can reach it comes from one of three
			// places: the provider's `boundsChange` handler, which assigns `lastBbox` on the
			// line BEFORE it fetches; the `typeFilterChange` handler, itself wrapped in
			// `if ( lastBbox )`; and this function's own conditional half. `lastBbox = null`
			// happens in exactly ONE place — {@see start} — which empties the pool two lines
			// later in the same synchronous run, and bumps the pool generation, so a listing
			// still travelling across that reset is dropped on arrival rather than
			// repopulating the pool behind the nulled bbox.
			//
			// The one route that reasoning does not close here is a provider — `Map_Provider`
			// is a documented extension point — emitting `boundsChange` with a FALSY bbox,
			// which would leave `lastBbox` null while a fetch went out anyway. That is closed
			// one layer down instead: `pickup-datasource.js` OMITS a `bounds` that is not a
			// 4-element array, so the query arrives with no addressing mode and
			// `Point_Query::from_request()` refuses it — no points come back, the pool stays
			// empty, the implication holds.
			//
			// So in the window the card describes the wipe is a no-op on an empty pool, and
			// wherever the pool is NOT empty the refetch below always runs. Moving these two
			// calls under the condition would change nothing observable and would cost the
			// invariant "a cart change forgets everything the old cart was verdicted against,
			// unconditionally" — the one thing #232/#238 exist to guarantee. Pinned by the
			// `#248` block in `tests/js/pickup-mount.test.js`.
			forgetPointDetails();
			resetPointPool();

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
			isSelfRefreshInFlight: isSelfRefreshInFlight,
			destroy: function() {
				// EVERY card lock a session can be holding — an in-flight confirmation's, an
				// in-flight checkout refresh's, and (issue #223) an in-flight detail fetch's — is
				// released HERE, while `destroyed` is still false and the panels are still alive to
				// hear it. Neither `setSelectionBusy()` nor `setVerdictPending()` tracks why it was
				// called and neither self-balances (see their own docblocks in `pickup-panels.js`);
				// a `true` left unpaired locks every card the instance opens afterwards, and
				// nothing else in this file would ever pair it. Cheap insurance against a panels
				// object that outlives its session — a plugin holding its own reference, or a
				// future reuse of the instance.
				invalidateSelection();
				releaseVerdictPending();
				dropRefreshWaiter();

				destroyed = true;

				// The TWO long-lived targets a session binds to (see {@see handleModalClosed}
				// and {@see refreshCheckout}) — and therefore the two this file has to unbind by
				// hand, since nothing else takes either away. Left attached, every session ever
				// opened on this page would keep a listener, and its whole closure, alive for
				// the life of the document.
				document.body.removeEventListener( 'woodev_modal_closed', handleModalClosed );

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
	 * `openSession()` is handed THIS slot's own button, captured in its own closure.
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
		button.className = 'button ' + TRIGGER_CLASS;

		button.addEventListener( 'click', function( event ) {
			event.preventDefault();
			closeSession( config.fieldId );
			sessions[ config.fieldId ] = openSession( config, button );
		} );

		slot.appendChild( button );
	}

	/**
	 * Picks which of a field's currently mounted slots carries the chosen-address block
	 * (issue #274 item 2 / #308 item 4 — the operator approved two buttons, never a
	 * doubled address line a few pixels apart). {@see ADDRESS_PLACEMENT} wins when a slot
	 * with that `data-woodev-pickup-placement` exists; otherwise the FIRST mounted slot,
	 * in DOM order, takes over — a site that suppressed the `'review'` placement entirely
	 * (`woodev_pickup_slot_placements`) still needs the address to show up SOMEWHERE,
	 * never nowhere just because its preferred anchor was never mounted. A field with only
	 * one slot always resolves to that slot, whatever its placement.
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
