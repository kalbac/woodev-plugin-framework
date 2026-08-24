/**
 * Woodev Location Cascade — field-graph wiring for the location provider layer
 * (spec §4.4/D2/D7, plan Tasks 11-13).
 *
 * Builds ON TOP OF the §8 checkout-field store (`checkout-field-store.js`, Task 10 of the
 * 2026-07-06 plan) rather than a parallel state world: the store already owns canonical
 * field values, this module adds LOCATION semantics on top — record objects, scoping,
 * persistence, backwards fill, per-country attach/detach, and (Task 12) a client-side wrap of
 * WC's OWN Address Autocomplete provider registry for mixed-country stores (see the
 * "WC Address Autocomplete suppression" section below).
 *
 * RENDERER SEAM (Task 13, spec D7 — "mode is presentation, provider is data"; issue #380 —
 * `location.mode` became TWO independent axes, `{ region, settlement }`, resolved PER LEVEL;
 * issue #448 — the per-level mapping is EXPLICIT, not residual): this module owns exactly ONE
 * renderer itself — the baseline typeahead ({@see attachOne}'s fallback path, gated by the D15
 * `isLevelServed()` chain) — and otherwise only knows how to ASK for one:
 * {@see resolveModeRenderer} looks up `window.WoodevLocationRenderers[axisMode(+':'+level)]`,
 * where `axisMode` ({@see axisModeForLevel}) is the REGION axis for the region node, the
 * SETTLEMENT axis for the settlement node, and NO axis at all for address (there is no third
 * axis to configure — see that function's own docblock) — a registry `location-select-modes.js`
 * populates with the `related-list` and `ajax-select2` renderers, unchanged by the split (this
 * cascade re-keys the LOOKUP, never the registry itself). This file never imports that module,
 * never branches on its presence, and never
 * inspects what a resolved renderer actually does with the `fetch`/`onSelect`/scoping
 * primitives {@see attachOne} hands it — the cascade stays entirely ignorant of WHICH
 * presentation a field gets, only of the fixed chain and persistence contract every
 * presentation must honour identically (D8's persist-then-trigger route via the SAME
 * `onSelectFor()`, never a duplicated one).
 *
 * STORE SHARING (discovered from `class-checkout-handler.php::enqueue_assets()`, not spelled
 * out in the plan — contradiction noted in the Task 11 report): this file is enqueued with a
 * hard dependency on `woodev-checkout-field-classic`, which ALREADY calls
 * `WoodevCheckoutFieldStore.createStore( config )` synchronously, at its own top-level scope,
 * for every `window.woodev_checkout_field_config_*` global — including the SAME config object
 * this file also discovers. Calling `createStore()` again here would silently build a SECOND,
 * diverging store for the same fields. This file therefore reaches for the EXISTING instance
 * via `WoodevCheckoutFieldStore.getStoreForField()` first (the store-registry lookup that
 * factory function's own docblock previews for exactly this cross-file consumption shape),
 * and only falls back to `createStore()` when no existing store owns any of this config's own
 * fields yet — keeping this module usable in isolation too (e.g. under test, or for a plugin
 * whose location fields carry no OTHER §8 semantics of their own).
 *
 * CHAIN ASSEMBLY IS LEVEL-DRIVEN, NOT `depends_on`-DRIVEN: unlike a generic §8 options/suggest
 * cascade (which reads `depends_on` off each field descriptor), a location-kind field carries
 * no `depends_on` at all (see `class-field.php::source_location()` and
 * `CheckoutConfigTest`'s own field-presence-variant fixtures) — the framework maps whichever
 * location-kind fields the plugin declared onto the FIXED chain
 * country → region → settlement → address, skipping absent links (spec §4.4), purely from each
 * field's own `location_level`. The postcode field is not itself declared through
 * `source_location()` at all (D13: "derived, write-only", and it is a plain native WooCommerce
 * field, never one this module's own config carries) — its id is derived from whichever
 * chain field is deepest-present, by stripping that field's own WC-convention suffix
 * (`_state`/`_city`/`_address_1`) and appending `postcode` — this reproduces WooCommerce's own
 * `billing_postcode`/`shipping_postcode` naming without a further PHP contract addition. A
 * plugin using non-conventional field ids simply gets no postcode participation — degrades to
 * "no postcode wiring", never an error.
 *
 * PERSIST THEN TRIGGER (D8): selecting a suggestion POSTs the FULL record, round-tripped
 * untouched, to `/select`; ONLY once that resolves (and the server actually persisted it —
 * `persisted !== false`) does this module fire `jQuery(document.body).trigger('update_checkout')`
 * ITSELF. WooCommerce does not save a partial address until every required TEXT field in the
 * block is filled (gotcha `wc-does-not-save-the-address-until-every-required-text-field-is-filled`),
 * so `updated_checkout` may never arrive on its own — this direct trigger bypasses only WC's
 * own client-side gate, never the server's authority. A failed or unpersisted `/select` never
 * fires the trigger (a misleading "everything's fine" refresh) but also never reverts the
 * field's DOM value — the widget already wrote it before this module's `onSelect` ever runs
 * (see `location-typeahead.js`'s own docblock), so the customer's visible choice survives a
 * transient guest-session failure (gotcha `guest-session-write-needs-the-cart-cookie`).
 *
 * DEPENDENT CLEARING is keyed on a REMEMBERED PARENT VALUE, not on "a change event fired" —
 * mirrors gotcha `a-programmatic-parent-change-must-not-run-a-destructive-cascade` exactly:
 * WooCommerce fires programmatic `change` events carrying a field's OWN current value while
 * initializing the checkout, and this module's own dual-world binding (see below) can even
 * deliver ONE such event to the SAME handler twice. Both cases are absorbed by comparing the
 * new value against `resolved[fieldId]` — genuinely unchanged is always a no-op, by
 * construction, regardless of how many times or through which world the event arrives.
 *
 * ADDRESS LOCK (issue #337): when — and ONLY when — this entry's chain carries BOTH a
 * settlement and an address field AND the provider serves the `address` level for that field's
 * own country, the address input is `disabled` until a settlement record is actually confirmed.
 * It is a UX gate, client-side only, with nothing explaining it; the full reasoning, including
 * why the settlement is not simply derived out of the address record instead, lives on
 * {@see isAddressLocked} and {@see refreshAddressLock}. Every state transition that can change
 * the answer re-applies it — see those two functions' own call sites.
 *
 * ISSUE #350 AMENDS #337 rather than replacing it: a settlement the provider will never suggest
 * — the customer typed a real but uncarried town and a completed search proved it, never merely
 * abandoned mid-search — stands down the lock instead of leaving it on forever with no exit. See
 * {@see isAddressLocked}'s own amendment section and {@see onAbandonFor} for the mechanism.
 *
 * BOTH EVENT WORLDS (gotcha `jquery-trigger-change-fires-no-native-event`): a jQuery
 * `.trigger('change')` (how select2/selectWoo and much of WooCommerce's own churn report a
 * change) dispatches NO native DOM event, so a delegated `addEventListener('change')` alone
 * would miss it. This module binds a native listener AND a jQuery one on `document.body`,
 * re-trying the jQuery half on every mount pass (jQuery is a declared hard script dependency
 * of this file per the enqueue wiring above, but the check stays defensive/idempotent rather
 * than assumed).
 *
 * NO EXPORTS, PURE BOOTSTRAP — mirrors `checkout-field-classic.js` exactly: this file runs its
 * own boot the moment it is evaluated (gated only on `document.readyState`, not on jQuery's
 * async ready queue, since — unlike that file — this one must keep working even in a jQuery-
 * less test harness for its own non-jQuery-producer paths).
 *
 * `woodev_location_applied` (Task 15; issue #159; `implicit` added issue #309, spec D11/§4.6;
 * `settlementKey` added issue #336):
 * a native, bubbling `CustomEvent` on `document.body`, `detail: { key, level, settlementKey,
 * implicit }`, fired from {@see settleSelect} on the SAME "this response is final and
 * persisted" condition as the `update_checkout` trigger above — see that function's own
 * docblock — AND from {@see prefill} on boot, when `config.location.current` already names a
 * record (see below). This is a NATIVE event, never a jQuery `.trigger()` (unlike
 * `update_checkout`, which WooCommerce itself only ever fires through jQuery): this module is
 * the event's own producer, so there is no third-party dispatch mechanism to accommodate the
 * way `pickup-mount.js`'s dual-world `updated_checkout` binding has to. `pickup-mount.js`'s own
 * `resolveLocalityKey()` is the intended consumer of `key`/`level` — it tracks the customer's
 * current Location Provider layer key without ever reading a checkout DOM field.
 *
 * `detail.settlementKey` (issue #336) is a SEPARATE field, never a replacement for `key`:
 * issue #309's `implicit` flag and other consumers ride on `key` meaning "the record THIS
 * event describes", which must stay the current/just-persisted record at whatever level it
 * sits at. `settlementKey` instead always names the settlement the customer has actually
 * picked, if any — see {@see fireLocationApplied}'s own docblock for exactly how it is
 * derived and why. `pickup-mount.js`'s pickup map prefers it over `key`, falling back to
 * `key` only when `settlementKey` is absent/empty, mirroring
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_location_record()}'s own
 * settlement-preferred, current-record-fallback rule server-side.
 *
 * `detail.implicit` (issue #309; spec D11/§4.6) is this event's SECOND consumer surface:
 * `config.location.implicit` had ZERO consumers anywhere in this codebase before this — a
 * first attempt reflected it onto a `data-woodev-location-implicit` DOM attribute instead,
 * which measurably could not work (destroyed by {@see attachOne}'s DOM-replacing renderers
 * whenever `attachAll()` runs right after {@see prefill} in {@see boot}; never re-applied
 * after a checkout re-render since {@see reconcileAfterCheckoutUpdate} never calls
 * {@see prefill} again; permanently diverging between two entries that share one customer
 * record; never cleared by {@see clearCountryScope}; and impossible to signal at all for a
 * record whose level has no field in a given entry's own chain, spec §4.4). The event has
 * none of those problems by construction — it depends on no DOM node existing or surviving
 * anything. `implicit` is `true` ONLY on the boot fire, and only when
 * `config.location.implicit` says so; every OTHER fire (an explicit `/select` persisting, or
 * the record becoming unknown — see {@see fireLocationApplied}'s own docblock) is always
 * `false`, because a persisted `/select` is never itself an implicit write
 * (`Location_Controller::handle_select_request()` never writes implicit — spec D11) and an
 * unknown record has nothing to flag as a default guess. This never gates scoping,
 * addressing, or rate calculation (those stay implicit-agnostic by design, spec D11's other
 * half) — it exists purely so a plugin/theme can tell an implicit default apart from the
 * customer's own choice for ITS OWN "please choose your locality" wording, without the
 * framework inventing that wording itself.
 *
 * @file
 * @since 2.1.0
 */

( function() {
	'use strict';

	var PREFIX = 'woodev_checkout_field_config_';
	var LOG = '[woodev-location-cascade]';

	/**
	 * The two §8 checkout sections that carry their OWN address/country (spec §4.4 amendment,
	 * PR-C review Finding 1): `'billing'` (and the `'order'` default — see
	 * `Checkout_Fields::normalize()`) reads/observes `#billing_country`; `'shipping'` reads/
	 * observes `#shipping_country`. Every chain/postcode node remembers which of these two
	 * ids is ITS OWN — see {@see buildChain} — so a mixed-section entry (unusual, but the §8
	 * field API permits it field-by-field via `Field::set_section()`) is scoped correctly
	 * node-by-node rather than by one guessed-at "the" country for the whole entry.
	 *
	 * @type {{billing: string, shipping: string}}
	 */
	var COUNTRY_FIELD_ID = { billing: 'billing_country', shipping: 'shipping_country' };

	/** @type {string[]} both known country field ids — a change to EITHER re-runs arbitration. */
	var COUNTRY_FIELD_IDS = [ COUNTRY_FIELD_ID.billing, COUNTRY_FIELD_ID.shipping ];

	/** @type {string[]} the fixed cascade order (spec §4.4). */
	var LEVELS = [ 'region', 'settlement', 'address' ];

	/** @type {Object.<string, string>} WC-convention field-id suffix per level, for postcode derivation. */
	var LEVEL_SUFFIX = { region: 'state', settlement: 'city', address: 'address_1' };

	/** @type {string} marks the address input while it is locked (issue #337) — see {@see refreshAddressLock}. */
	var LOCKED_CLASS = 'woodev-location-locked';

	/**
	 * Marks the ONE synthetic `<option>` {@see applyValueToElement} may own on a given `<select>`
	 * — reused across calls instead of appended anew each time (issue #462 round 2), so a region
	 * changed twice never leaves a stale, deselected option littering the node the customer's
	 * form submits.
	 *
	 * @type {string}
	 */
	var SYNTHETIC_OPTION_ATTR = 'data-woodev-location-synthetic';

	var factory = window.WoodevCheckoutFieldStore;

	if ( ! factory || 'function' !== typeof factory.createStore ) {
		return;
	}

	// -------------------------------------------------------------------------
	// Small helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalizes a DOM/store value to a comparison string — mirrors
	 * `checkout-field-classic.js`'s own `cascadeKey()` exactly (same reasoning: `.value` is
	 * always a string already for a text input, but `undefined`/`null` must collapse to `''`
	 * so an absent element compares equal to an empty one).
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function cascadeKey( value ) {
		return undefined === value || null === value ? '' : String( value );
	}

	/**
	 * Logs an error with this module's prefix, never throwing.
	 *
	 * @param {*} error
	 * @returns {void}
	 */
	function logError( error ) {
		if ( window.console && 'function' === typeof console.error ) {
			console.error( LOG, error );
		}
	}

	/**
	 * Builds a `key=value&...` query string, omitting empty/absent params entirely — an
	 * omitted `within` is how "no scope" is expressed to the REST controller (never an empty
	 * string, which `build_scope()` would treat as a literal, if harmless, value).
	 *
	 * @param {string}                       base
	 * @param {Object.<string, string|null>} params
	 * @returns {string}
	 */
	function buildUrl( base, params ) {
		var parts = [];

		Object.keys( params ).forEach( function( key ) {
			var value = params[ key ];

			if ( undefined !== value && null !== value && '' !== value ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
			}
		} );

		return parts.length ? base + '?' + parts.join( '&' ) : base;
	}

	/**
	 * Performs one JSON request and resolves with the parsed body — a non-OK response still
	 * resolves the fetch itself (per the Fetch API), so this rejects explicitly on `!ok` (the
	 * body, parsed if possible, becomes the rejection reason).
	 *
	 * @param {string} url
	 * @param {Object} init
	 * @returns {Promise<*>}
	 */
	function fetchJson( url, init ) {
		return fetch( url, init ).then( function( response ) {
			return response.json().then(
				function( body ) {
					return response.ok ? body : Promise.reject( body );
				},
				function() {
					return response.ok ? {} : Promise.reject( {} );
				}
			);
		} );
	}

	/**
	 * The `X-WP-Nonce` header for a location REST call — the SAME `wp_rest` nonce for both
	 * `/suggest` and `/select` (`class-checkout-config.php`'s own docblock: "deliberately the
	 * SAME... nonce").
	 *
	 * @param {Object} entry
	 * @returns {Object}
	 */
	function nonceHeader( entry ) {
		return { 'X-WP-Nonce': entry.location.nonce || '' };
	}

	/**
	 * Reads a country `<select>`'s current value by field id — `''` when the field is absent
	 * from the DOM (e.g. `#shipping_country` before WooCommerce has rendered the shipping
	 * fieldset at all).
	 *
	 * @param {string} fieldId
	 * @returns {string}
	 */
	function countryValue( fieldId ) {
		var el = document.getElementById( fieldId );

		return el ? ( el.value || '' ) : '';
	}

	/**
	 * The country field id `node` (or any `{section}`-carrying object) is scoped by — see
	 * {@see COUNTRY_FIELD_ID}. `'order'` (the §8 default section — native WC fields this
	 * framework merely enhances, e.g. a takeover on `billing_city`) and any other non-
	 * `'shipping'` value fall back to `'billing'`, matching the convention this module
	 * exclusively used before this section-awareness existed.
	 *
	 * @param {{section?: string}} node
	 * @returns {string}
	 */
	function countryFieldIdFor( node ) {
		return 'shipping' === ( node && node.section ) ? COUNTRY_FIELD_ID.shipping : COUNTRY_FIELD_ID.billing;
	}

	/**
	 * The section a country-field id itself governs — `'shipping'` for `#shipping_country`,
	 * `'billing'` for everything else, including `#billing_country` and any id this module was
	 * never told about. The inverse of {@see countryFieldIdFor} (fieldId -> section rather than
	 * node -> fieldId), needed wherever a caller only has the field id a `change` event fired on
	 * (see {@see handleFieldChanged}) and must resolve `entry`'s EFFECTIVE country for it via
	 * {@see countryFor}, which itself takes a `{section}`-shaped node.
	 *
	 * PR #320 review, finding 6: deliberately does NOT consult the OTHER country field (a
	 * shipping-section id never falls back to `#billing_country`'s value, or vice-versa) — a
	 * shipping-section field present but unselected falls straight through to `entry.location.
	 * defaultCountry`, the SAME store-wide fallback billing itself uses when its own field is
	 * unselected, never billing's CURRENT selection. Two reasons this is the right call, not an
	 * oversight: (1) it is what WooCommerce's own checkout markup already does — both country
	 * `<select>` elements independently default their selected `<option>` to the store's base
	 * country (`WC_Countries::get_country_dropdown_options()`), neither one is ever rendered
	 * pre-filled FROM the other; (2) {@see countryFieldIdFor}'s own docblock (Finding 1 of the
	 * PR-C review this file already carries) establishes section isolation as a first-class rule
	 * specifically so a shipping-section field is never arbitrated against `#billing_country` —
	 * a billing-first shipping fallback would reintroduce exactly the cross-section coupling
	 * that finding removed, and asymmetrically (billing would still never consult shipping).
	 *
	 * @param {string} countryFieldId
	 * @returns {string}
	 */
	function sectionForCountryFieldId( countryFieldId ) {
		return COUNTRY_FIELD_ID.shipping === countryFieldId ? 'shipping' : 'billing';
	}

	/**
	 * The country currently in play for `node` (issue #296's own fallback chain — the operator's
	 * own wording: "поле чекаута → настройка WooCommerce → RU", the last step through a filter,
	 * no separate option) — reads `#shipping_country` for a shipping-section node,
	 * `#billing_country` for everything else (spec §4.4 amendment, Finding 1), read LIVE at call
	 * time, same convention as the rest of this module's own live-scope reads (see
	 * {@see scopeKeyFor}).
	 *
	 * STEP 1 (the live checkout field) is everything {@see countryValue} answers: a genuinely
	 * present, non-empty value. STEPS 2+3 (the WooCommerce store's own base country, else a
	 * PHP-filterable `RU`) are NOT resolved here at all — a shop with no country field on
	 * checkout, or one whose country `<select>` is present but still unselected, used to make
	 * {@see isCountrySupported} reject an empty string outright, so NO widget ever attached and
	 * the whole location layer went silently dead with no signal why. Both remaining steps are
	 * already merged into ONE value server-side by
	 * `Location_Service::resolve_default_country()` and handed down as `entry.location.defaultCountry`
	 * next to `countries`/`levels` (`class-checkout-config.php::build_location_block()`) — the
	 * SAME method the `/suggest` REST route falls back to for an empty `country` request param,
	 * so both sides of the client/server boundary answer identically by construction. This
	 * function therefore has exactly ONE fallback of its own to make, never a second guess at
	 * what WooCommerce's setting or the RU default might be.
	 *
	 * THE SAME EFFECTIVE VALUE FEEDS THE DESTRUCTIVE-CLEAR GATE (PR #320 review, finding 1):
	 * {@see prefill} seeds `entry.resolved[countryFieldId]` through this function, and
	 * {@see handleFieldChanged} re-derives the SAME thing to compare against on every country
	 * `change` — never the raw `el.value` either side used to read. A country `<select>` that
	 * starts unselected (`el.value === ''`, WooCommerce's own "Select a country / region…"
	 * placeholder under "No location by default") makes the widget attach on the `RU`/store
	 * fallback exactly like this function already resolves; comparing the RAW DOM value instead
	 * would seed `''` and then read the customer's very first explicit pick of THAT SAME country
	 * (`'' -> 'RU'`) as a real transition, wiping the address the fallback had already scoped
	 * every suggestion by. Routing both sides through this one function keeps the seed and the
	 * comparison — and the widget's own attach/scope reads — answering the identical question.
	 *
	 * @param {Object}              entry
	 * @param {{section?: string}}  node
	 * @returns {string}
	 */
	function countryFor( entry, node ) {
		var live = countryValue( countryFieldIdFor( node ) );

		if ( live ) {
			return live;
		}

		return ( entry.location && entry.location.defaultCountry ) || '';
	}


	/**
	 * The country whose owner map governs an ownership decision about `record` (issue #352
	 * follow-up, post-review finding P1) — `record`'s OWN `country` field when it carries one,
	 * falling back to {@see countryFor}'s live-field read only for the defensive case of a
	 * record with none.
	 *
	 * WHY THE RECORD'S OWN COUNTRY, NOT THE LIVE FIELD: {@see mayEnterChain} and
	 * {@see backwardsFill} both ask "who owns this level in the country THIS RECORD belongs
	 * to" — resolving that via the live country `<select>` instead answers the WRONG owner map
	 * when the country changed between the moment the suggestion was fetched and the moment it
	 * was clicked (a real, if narrow, window: typeahead results are already in flight before a
	 * click). Every record that reaches this module already passed through
	 * `Location_Record::from_array()` server-side, which REQUIRES `country` and upper-cases it
	 * (that method's own docblock: "Required: … `country` … case-normalized to upper-case on
	 * the way in") — so the fallback below is defensive only, never observed to actually fire.
	 * `owners` is keyed by the SAME upper-case WC country codes `class-checkout-config.php`
	 * iterates to build it, so no case normalization is needed at the {@see levelOwner} lookup
	 * either; verified against both sources rather than assumed.
	 *
	 * NOT A FIX FOR A STALE CROSS-COUNTRY RECORD: a record fetched for one country and clicked
	 * after the customer already switched the country field to another is issue #346, and is
	 * deliberately left alone here — this function only makes sure the OWNERSHIP CHECK itself
	 * consults the right country while the record is still live, not whether the record itself
	 * is still valid to apply at all.
	 *
	 * @param {Object} entry
	 * @param {{section?: string}} node
	 * @param {Object} record
	 * @returns {string}
	 */
	function countryForRecord( entry, node, record ) {
		if ( record && 'string' === typeof record.country && record.country ) {
			return record.country;
		}

		return countryFor( entry, node );
	}

	/**
	 * Whether the live "ship to a different address" checkbox is checked — read by its stable
	 * `name` attribute, the SAME selector `pickup-mount.js`'s own `resolveAddressTarget()`
	 * already uses for this exact control, not a new convention. A checkout with NO such
	 * checkbox at all (a theme/flow override without the classic WC toggle) is treated as
	 * permissively "checked" — this module has no reason to disable a shipping-section field
	 * that has no toggle gating it in the first place.
	 *
	 * @returns {boolean}
	 */
	function shipToDifferentAddressChecked() {
		var checkbox = document.querySelector( '[name="ship_to_different_address"]' );

		return ! checkbox || checkbox.checked;
	}

	/**
	 * Which section is CURRENTLY the customer's actual delivery address (review finding F3) —
	 * `'shipping'` when the toggle is checked, `'billing'` otherwise, INCLUDING when no toggle
	 * exists at all. Deliberately the OPPOSITE default from {@see shipToDifferentAddressChecked}
	 * for an absent checkbox: that function answers "should a shipping-section WIDGET stay
	 * attached", and permissively says yes so a gate-less shipping field keeps working: this
	 * function answers "whose address key is the record", and a checkout with no toggle at all
	 * has only ever had one address — billing — same convention `pickup-mount.js`'s own
	 * `resolveAddressTarget()` already uses for exactly this decision (this module deliberately
	 * mirrors it rather than reusing `shipToDifferentAddressChecked()`, whose "permissive"
	 * default answers the wrong question here).
	 *
	 * @returns {string} `'shipping'` or `'billing'`.
	 */
	function activeAddressSection() {
		var checkbox = document.querySelector( '[name="ship_to_different_address"]' );

		return checkbox && checkbox.checked ? 'shipping' : 'billing';
	}

	/**
	 * Whether `section` is the one currently determining the customer's delivery locality
	 * (review finding F3) — see {@see activeAddressSection}. `'shipping'` matches only the
	 * active shipping section; every other value (`'billing'`, the §8 `'order'` default, or
	 * `undefined`) is normalized to `'billing'`, mirroring {@see countryFieldIdFor}'s own
	 * fallback for the same set of section values.
	 *
	 * @param {string} [section]
	 * @returns {boolean}
	 */
	function isActiveAddressSection( section ) {
		return activeAddressSection() === ( 'shipping' === section ? 'shipping' : 'billing' );
	}

	/**
	 * Whether `node` should currently have a typeahead widget attached: its own country must
	 * be one the entry covers, AND — for a shipping-section node specifically — the customer
	 * must have opted into "ship to a different address" at all (a shipping-section field
	 * hidden behind an unchecked toggle is not actually in play, regardless of what
	 * `#shipping_country` happens to hold).
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string, section?: string}} node
	 * @returns {boolean}
	 */
	function isNodeActive( entry, node ) {
		if ( 'shipping' === node.section && ! shipToDifferentAddressChecked() ) {
			return false;
		}

		var country = countryFor( entry, node );

		if ( ! isCountrySupported( entry, country ) ) {
			return false;
		}

		// Task 13 / spec D7's ONE necessary exception to the D15 level gate below — see
		// isRelatedListRegionNode()'s own docblock for why `isLevelServed()` cannot answer
		// this case at all (by the SERVER's own design, not an oversight here).
		if ( isRelatedListRegionNode( entry, node ) ) {
			return true;
		}

		// The D15 level gate belongs HERE, not only inside attachOne(): the reconcile in
		// applyCountryArbitration() decides detach-vs-attach purely from this predicate, so a
		// gate that lives only on the attach path can never DETACH. Concretely — a customer
		// on RU with an attached address widget who switches to AM (a country we serve, but
		// with city-only data) would keep a widget that can never return anything, because
		// the country itself is still supported and the section is still visible.
		return isLevelServed( entry, country, node.level );
	}

	/**
	 * The REGION axis value for `entry` (issue #380 — the location layer now publishes TWO
	 * independent axes, `entry.location.mode = { region, settlement }`, instead of one shared
	 * mode string). `'typeahead'` when the entry carries no `location.mode` at all (an older
	 * server config, or a test fixture that never set one) — the same floor default the server
	 * side's own clamp falls back to.
	 *
	 * @param {Object} entry
	 * @returns {string}
	 */
	function regionAxisMode( entry ) {
		return ( entry.location && entry.location.mode && entry.location.mode.region ) || 'typeahead';
	}

	/**
	 * The SETTLEMENT axis value for `entry` (issue #380). See {@see regionAxisMode}'s own
	 * docblock — same shape, same default.
	 *
	 * @param {Object} entry
	 * @returns {string}
	 */
	function settlementAxisMode( entry ) {
		return ( entry.location && entry.location.mode && entry.location.mode.settlement ) || 'typeahead';
	}

	/**
	 * Whether `node` is the region level of an entry whose REGION AXIS is `related-list` (spec
	 * D7; Task 13; issue #294 arbitration; issue #380 — re-keyed from the single shared mode
	 * string to the region axis specifically, independent of whatever the settlement axis
	 * carries) — the ONE case where {@see isLevelServed}'s "region" answer is structurally
	 * unable to describe reality and must not be consulted at all.
	 *
	 * Per `class-checkout-config.php::build_location_block()`'s own docblock: `levels[country]
	 * .region` reads `false` for EVERY country whose region `<select>` already has WooCommerce
	 * states registered — REGARDLESS of whether those states came from a genuine conflict
	 * (some other source already owns the field) or from THIS layer's OWN region-axis
	 * `related-list` injecting them on purpose (`Location_Provider_Registry::inject_related_list_states()`,
	 * itself gated on the region axis alone since issue #380). The server's own docblock says
	 * the client "must NOT try to tell the two apart... it does not need to" — and it does not,
	 * because the real gate for the region node under a `related-list` region axis is not "does
	 * the D15 suggest chain want this level" at all, it is "did WooCommerce actually render a
	 * `<select>` here", which {@see attachOne}'s own `related-list:region` renderer checks
	 * directly against the live DOM (`el.tagName`) — this predicate only decides whether that
	 * renderer gets a chance to run.
	 *
	 * @param {Object} entry
	 * @param {{level: string}} node
	 * @returns {boolean}
	 */
	function isRelatedListRegionNode( entry, node ) {
		return 'related-list' === regionAxisMode( entry ) && 'region' === node.level;
	}

	/**
	 * The axis mode that governs `level`'s renderer lookup, or `null` when `level` has no axis
	 * of its own at all (issue #448).
	 *
	 * ONLY `region` and `settlement` are configurable axes (spec D7/issue #380) — the settings
	 * UI never offers a mode for any other level, `address` included, and there is no field in
	 * `entry.location.mode` for one. `address`'s own contract is fixed: text, or text-with-
	 * suggestions when that option is on (see {@see attachOne}'s baseline typeahead path) — it
	 * is a FLOOR, never a level with a mode of its own to inherit.
	 *
	 * Previously this fell through to {@see settlementAxisMode} for "every node that isn't
	 * region" — a deliberate backward-compatible shim for the OLD single shared mode string
	 * (see the #448 issue and this function's git history for the reasoning that no longer
	 * applies). That let a bare registry key like `registry['ajax-select2']` — registered with
	 * no level suffix, because `ajax-select2`'s widget is the same shape for any field it
	 * enhances — attach to the address field too, turning it into a select nobody configured
	 * (operator, rig pass 21.08.2026, issue #448). The mapping is explicit now: `region` takes
	 * the region axis, `settlement` takes the settlement axis, everything else — currently only
	 * `address`, since {@see LEVELS} names exactly these three — takes none.
	 *
	 * @param {Object} entry
	 * @param {string} level
	 * @returns {string|null}
	 */
	function axisModeForLevel( entry, level ) {
		if ( 'region' === level ) {
			return regionAxisMode( entry );
		}

		if ( 'settlement' === level ) {
			return settlementAxisMode( entry );
		}

		return null;
	}

	/**
	 * Resolves the mode-specific renderer for `node`, if Task 13's `location-select-modes.js`
	 * registered one — spec D7's "mode is presentation, provider is data": this cascade must
	 * not know WHICH renderer a field uses, only how to ask the registry for one.
	 *
	 * MODE RESOLUTION IS PER-LEVEL (issue #380, re-keyed from a single shared mode string to
	 * two independent axes) and EXPLICIT, not residual (issue #448) — see
	 * {@see axisModeForLevel}'s own docblock for which level takes which axis, and why address
	 * takes none. A node whose level has no axis never reaches the registry at all.
	 *
	 * Lookup order per level, once an axis mode is known: `{axisMode}:{level}` (a renderer
	 * specific to one field kind under one axis value — e.g. `related-list`'s region native-
	 * `<select>` watcher, which shares nothing with its own settlement renderer) first, then the
	 * bare `{axisMode}` (a renderer that serves every level uniformly — `ajax-select2`'s select2
	 * widget is the same shape for region or settlement). Returns `null` when nothing is
	 * registered for either key, when `location-select-modes.js` never loaded at all, or when
	 * the level has no axis to look up in the first place — {@see attachOne}'s baseline
	 * typeahead path is the fallback in every case, never a special case of this function.
	 *
	 * @param {Object} entry
	 * @param {{level: string}} node
	 * @returns {function(Element, Object): ({detach: Function}|null)|null}
	 */
	function resolveModeRenderer( entry, node ) {
		var mode = axisModeForLevel( entry, node.level );

		if ( null === mode ) {
			return null;
		}

		var registry = window.WoodevLocationRenderers || {};

		if ( 'function' === typeof registry[ mode + ':' + node.level ] ) {
			return registry[ mode + ':' + node.level ];
		}

		if ( 'function' === typeof registry[ mode ] ) {
			return registry[ mode ];
		}

		return null;
	}

	/**
	 * Formats one record component (`{ name, type }`) as display text — `type + ' ' + name`,
	 * trimmed. Used for the STREET part of an address value, where the type is part of the
	 * name in ordinary use ("ул Тверская" reads as an address, "Тверская" does not).
	 *
	 * Region and settlement values deliberately do NOT go through this — see
	 * {@see fieldValueFor}.
	 *
	 * @param {Object} component
	 * @returns {string}
	 */
	function formatComponent( component ) {
		var type = component && component.type ? String( component.type ) : '';
		var name = component && component.name ? String( component.name ) : '';

		return ( type + ' ' + name ).trim();
	}

	/**
	 * The text a location field of `level` should CARRY — derived from the record's own
	 * components, never its `label`.
	 *
	 * A provider's `label` exists to tell two suggestions apart IN THE LIST, so it carries
	 * ancestors: DaData labels a settlement `'Московская обл., г Жуковский'`. Writing that
	 * into a "Населённый пункт" field is wrong twice over — it repeats the region the region
	 * field already holds, and it hands the shipping carrier a locality name no carrier
	 * dictionary contains (operator, s70 rig pass: the field must read `'Жуковский'`).
	 *
	 * So each level derives its own value from its own component:
	 *
	 * - `region` / `settlement` → the component's bare `name`. The TYPE is dropped on purpose:
	 *   the operator's carriers reject a locality whose name carries its prefix ("г Жуковский"
	 *   returns nothing where "Жуковский" resolves), and nothing is lost by dropping it —
	 *   the record's `key` carries identity, this string is only what the customer and the
	 *   carrier read. Whether a prefix should come back for particular carriers is a separate,
	 *   still-open question (operator, same pass); it belongs to whoever maps a record onto a
	 *   carrier's own dictionary — the plugin's `Location_Adapter`, not this field value.
	 * - `address` → street (WITH its type, see {@see formatComponent}) plus house and block,
	 *   joined `', '` — i.e. everything BELOW the settlement, and nothing above it.
	 *
	 * Falls back to the record's `label` only when the derivation yields nothing at all (a
	 * provider returning no component at the level it was asked about). That fallback is the
	 * lesser of two evils, not a second convention: a field left blank right after the
	 * customer picked something in it reads as the pick having failed.
	 *
	 * @param {Object} record
	 * @param {string} level
	 * @returns {string}
	 */
	function fieldValueFor( record, level ) {
		if ( ! record ) {
			return '';
		}

		var parts;

		if ( 'address' === level ) {
			parts = [ formatComponent( record.street ), record.house, record.block ];
		} else {
			parts = [ record[ level ] && record[ level ].name ];
		}

		var value = parts.filter( function( part ) {
			return part && String( part ).trim();
		} ).map( function( part ) {
			return String( part ).trim();
		} ).join( ', ' );

		return value || ( 'string' === typeof record.label ? record.label : '' );
	}

	// -------------------------------------------------------------------------
	// Config discovery + chain assembly
	// -------------------------------------------------------------------------

	/**
	 * Builds the ordered chain of location-kind fields ACTUALLY present in `fields` — one
	 * entry per level found, in `LEVELS` order, skipping absent links (spec §4.4). Each node
	 * also carries its OWN `section` (Finding 1) — the field's own §8 `section` key, straight
	 * from the SAME `class-checkout-fields.php::normalize()` value `checkout-field-classic.js`
	 * and `class-checkout-handler.php::inject()` already key off — so a node can be scoped by
	 * the RIGHT country field even when different nodes of the same entry live in different
	 * sections.
	 *
	 * MORE THAN ONE FIELD CAN NOW CLAIM THE SAME LEVEL (issue #458): a Location-Provider
	 * field's `billing`/`shipping` fan-out (AGENT-RULES.md Rule 7b,
	 * `class-checkout-handler.php::effective_fields()`) means `config.fields` can carry BOTH
	 * a `billing_city` and a `shipping_city` at `location_level: 'settlement'` whenever the
	 * store does not force shipping to the billing address. This module still drives exactly
	 * ONE live widget per level — see the file docblock's CHAIN ASSEMBLY section — so the
	 * field whose `section` matches {@see activeAddressSection} wins the level; the OTHER one
	 * is left as a plain, functioning WC text field with no attached widget (degrades to "no
	 * live suggest for that field", never an error). Called at boot ({@see buildEntry}) AND
	 * again, live, on every "ship to a different address" toggle ({@see
	 * rebuildChainForActiveSection}) — a full independent second cascade for the non-winning
	 * section (both columns live at once) remains out of scope, a separate architecture fork
	 * left for the operator; this function only ever re-picks WHICH ONE column is live, never
	 * runs both. When section can't decide (both or neither candidate matches the active
	 * section) the FIRST field found wins (deterministic, mirrors the tie-break precedent in
	 * `checkout-field-store.js`'s own `getStoreForField()`).
	 *
	 * @param {Object.<string, Object>} fields
	 * @returns {Array<{level: string, fieldId: string, section: string}>}
	 */
	function buildChain( fields ) {
		var byLevel = {};
		var activeSection = activeAddressSection();

		Object.keys( fields || {} ).forEach( function( id ) {
			var field = fields[ id ];

			if ( ! field || 'location' !== field.source_kind || LEVELS.indexOf( field.location_level ) === -1 ) {
				return;
			}

			var level = field.location_level;
			var existing = byLevel[ level ];

			// Keep the existing winner unless THIS field is the one that actually matches the
			// active address section and the existing one does not — see this function's own
			// docblock (issue #458).
			if ( existing && ( existing.section === activeSection || field.section !== activeSection ) ) {
				return;
			}

			byLevel[ level ] = { fieldId: id, section: field.section };
		} );

		var chain = [];

		LEVELS.forEach( function( level ) {
			if ( byLevel[ level ] ) {
				chain.push( { level: level, fieldId: byLevel[ level ].fieldId, section: byLevel[ level ].section } );
			}
		} );

		return chain;
	}

	/**
	 * Derives the postcode NODE (id + section) from the DEEPEST present chain field's own
	 * WC-convention suffix (see the file docblock) — `null` when no chain field follows that
	 * convention (a plugin using non-standard ids simply gets no postcode participation). The
	 * postcode field's own section is always the SAME as the chain node it was derived from
	 * (`shipping_address_1` → `shipping_postcode`, never a mismatched section).
	 *
	 * @param {Array<{level: string, fieldId: string, section: string}>} chain
	 * @returns {{fieldId: string, section: string}|null}
	 */
	function derivePostcodeNode( chain ) {
		var i, node, suffix, fieldId;

		for ( i = chain.length - 1; i >= 0; i-- ) {
			node = chain[ i ];
			suffix = LEVEL_SUFFIX[ node.level ];
			fieldId = node.fieldId;

			if ( suffix && fieldId.length > suffix.length && suffix === fieldId.slice( -suffix.length ) ) {
				return { fieldId: fieldId.slice( 0, fieldId.length - suffix.length ) + 'postcode', section: node.section };
			}
		}

		return null;
	}

	/**
	 * Releases the address lock this module put on `fieldId`, for a node that is LEAVING the
	 * chain ({@see rebuildChainForActiveSection}).
	 *
	 * {@see refreshAddressLock} only ever reaches `chainNodeForLevel( entry, 'address' )` — the
	 * address node the chain currently holds. So once a column swap moves that node, the field
	 * left behind keeps `disabled` and {@see LOCKED_CLASS} forever: nothing walks it again.
	 * MEASURED on the rig (24.08.2026): after checking "ship to a different address",
	 * `billing_address_1` stayed `disabled` with the locked class while `shipping_address_1`
	 * became the live one — i.e. a REQUIRED billing field the customer could no longer fill,
	 * and a disabled input is not submitted at all. The lock is meant to say "pick a settlement
	 * first", which is only ever a statement about the ACTIVE column.
	 *
	 * Guarded on the class rather than on `disabled` alone, so this can only ever clear a lock
	 * THIS module set — never a `disabled` that WooCommerce, a theme or another plugin owns.
	 *
	 * @param {string} fieldId
	 * @returns {void}
	 */
	function releaseAddressLockOn( fieldId ) {
		var el = document.getElementById( fieldId );

		if ( ! el || ! el.classList || ! el.classList.contains( LOCKED_CLASS ) ) {
			return;
		}

		el.disabled = false;
		el.classList.remove( LOCKED_CLASS );
	}

	/**
	 * Re-derives `entry.chain` (and the `entry.allNodes`/`entry.postcodeFieldId` it feeds) for
	 * whichever section {@see activeAddressSection} NOW reports (issue #458 round 3) —
	 * {@see buildChain} itself reads that live, so calling it again after the "ship to a
	 * different address" toggle flips picks a fresh per-level winner instead of the one frozen
	 * at boot ({@see buildEntry}). Without this, {@see applyCountryArbitration} — which only ever
	 * walks `entry.chain` — keeps detaching the now-inactive section's widget forever, and the
	 * newly-active section's field was never IN the chain to begin with, so it never gets
	 * attached either: after one toggle, neither address column has a live widget.
	 *
	 * A no-op whenever the winner did not actually move (same fieldId at every level) — called
	 * unconditionally from {@see handleLayoutRelevantChange} for all three of its triggers, since
	 * only the toggle can ever change the winner and the diff below is cheap.
	 *
	 * Detaches the OUTGOING node's widget (if attached) before swapping the chain in: the rebuilt
	 * `entry.chain`/`entry.allNodes` no longer carries that node, so
	 * {@see applyCountryArbitration}'s per-node reconcile would never revisit it again and its
	 * widget would leak rather than being torn down. The incoming node is deliberately left for
	 * `applyCountryArbitration()` (called right after this, in the same handler) to attach —
	 * this function only ever swaps which fields are IN the chain, never attaches or detaches the
	 * winner itself, so the two functions can never both attach it (no double-attach).
	 *
	 * Per-LEVEL state — `entry.records`, `entry.unresolved`, `entry.clearedByEdit` — already keys
	 * by LEVEL, not by fieldId (see {@see buildEntry}'s own comments), so it survives the swap on
	 * its own. That is NOT sufficient, and believing it was is what Rule 7c had to spell out:
	 * `entry.resolved` keys by FIELD id, and the incoming column's field was never seeded, so the
	 * very next `change` on it compares its live text against `undefined`, reads as a real
	 * transition, and drops the level's record — the customer gets filled fields plus a re-locked
	 * address field, exactly the failure #337 and #459 were about. {@see
	 * carryChainStateToIncomingNodes} closes that, and is why this function is not a widget rebind
	 * alone. It is still never the destructive cascade {@see clearDescendants} runs for an actual
	 * edit (gotcha `a-programmatic-parent-change-must-not-run-a-destructive-cascade`).
	 *
	 * ISSUE #490 ROUND 2: the OUTGOING node's per-level TEXT is captured into `outgoingLevelText`
	 * HERE, BEFORE the `detachOne()` loop below runs — never read live inside
	 * {@see carryChainStateToIncomingNodes} itself, which is why that function takes the map as a
	 * plain argument instead of reading the DOM on its own. `document.getElementById( fieldId )`
	 * after `detachOne()` has run is safe ONLY for a widget whose `detach()` leaves the SAME
	 * element in the DOM (the baseline typeahead, and {@see attachRelatedListRegion}'s native-
	 * `<select>` watcher — both only unbind listeners). It is NOT safe for a `buildSelectField()`-
	 * based renderer (`ajax-select2`, `related-list:settlement` — `location-select-modes.js`):
	 * that widget REPLACES the field's original `<input>` with a fresh `<select>` on attach and,
	 * on `detach()`, swaps the ORIGINAL `<input>` back in VERBATIM — its own docblock is explicit
	 * that this restore is never synced with whatever the customer picked in the `<select>`. So a
	 * read taken after `detachOne()` for one of these levels finds the stale pre-attach `<input>`
	 * — typically empty — never the picked text, regardless of what {@see applyValueToElement}'s
	 * value-space understands. Measured on the rig (issue #490 round 2): this is exactly why
	 * settlement (always `ajax-select2` or `related-list:settlement` in production) carried in
	 * NEITHER direction even after round 1's fix, while region (always
	 * {@see attachRelatedListRegion}'s native-`<select>` watcher, never swapped) carried in both.
	 * `entry.widgets[ fieldId ].el` is read here instead of `document.getElementById()` for
	 * exactly this reason — {@see attachOne}'s own docblock already tracks it as "the LIVE
	 * element" specifically because a DOM-replacing renderer's `el` and the field's own id can
	 * diverge; reading it while the widget is STILL attached (i.e., before this loop's own
	 * `detachOne()` call) always gets the picked value, uniformly across every renderer.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function rebuildChainForActiveSection( entry ) {
		var newChain = buildChain( entry.config.fields );

		var changed = newChain.length !== entry.chain.length || newChain.some( function( node, i ) {
			return ! entry.chain[ i ] || node.fieldId !== entry.chain[ i ].fieldId;
		} );

		if ( ! changed ) {
			return;
		}

		var newPostcodeNode = derivePostcodeNode( newChain );
		var newAllNodes = newChain.concat( newPostcodeNode ? [ { level: null, fieldId: newPostcodeNode.fieldId, section: newPostcodeNode.section } ] : [] );
		var keptFieldIds = newAllNodes.map( function( node ) {
			return node.fieldId;
		} );

		// Issue #490 round 2 — see this function's own docblock for why this is captured from
		// the STILL-ATTACHED widget, before detachOne() below can lose it.
		var outgoingLevelText = {};

		entry.allNodes.forEach( function( node ) {
			if ( keptFieldIds.indexOf( node.fieldId ) === -1 ) {
				if ( node.level ) {
					var widget = entry.widgets[ node.fieldId ];
					var liveEl = widget ? widget.el : document.getElementById( node.fieldId );

					outgoingLevelText[ node.level ] = liveEl ? cascadeKey( liveEl.value ) : '';
				}

				detachOne( entry, node.fieldId );
				releaseAddressLockOn( node.fieldId );
			}
		} );

		var previousAllNodes = entry.allNodes;

		entry.chain = newChain;
		entry.allNodes = newAllNodes;
		entry.postcodeFieldId = newPostcodeNode ? newPostcodeNode.fieldId : null;

		carryChainStateToIncomingNodes( entry, previousAllNodes, newAllNodes, outgoingLevelText );
	}

	/**
	 * Carries the chain's state onto the fields that just JOINED it — the second half of a
	 * column swap, required by AGENT-RULES.md Rule 7c ("the chain's RECORDS must move with it,
	 * not just the widget"). Called only from {@see rebuildChainForActiveSection}, only for nodes
	 * whose fieldId was not already in the chain.
	 *
	 * WooCommerce does NOT copy one column's address into the other in the DOM. Read from its own
	 * source: `checkout.js` binds `#ship-to-different-address input` to `trigger_update_checkout`
	 * and to `ship_to_different_address`, which only slides the shipping fieldset open or shut,
	 * and `update_order_review` replaces the order-review and payment fragments — never the
	 * address fieldsets. The copy Rule 7c refers to is `WC_Checkout::get_posted_address_data()`,
	 * server-side and at submit time. So on the client, the incoming column is whatever the
	 * customer left in it, usually empty; carrying is ours to do.
	 *
	 * Per incoming node, exactly one of three things happens:
	 *
	 * 1. **Empty field, carried record** — write the carried text in silently
	 *    ({@see writeSilently}, fed from `outgoingLevelText` — the per-level text
	 *    {@see rebuildChainForActiveSection} captured from the outgoing node's LIVE element
	 *    BEFORE detaching its widget, falling back to {@see fieldValueFor} only when that level
	 *    had no outgoing node at all — see that function's own docblock for why the capture
	 *    cannot happen here, after the fact). {@see writeSilently} seeds `entry.resolved` as part
	 *    of the write, which is what makes the following `change` harmless.
	 * 2. **Field already carrying the customer's own text** — leave the text alone (checking
	 *    "ship to a different address" after typing a genuinely different shipping address must
	 *    not overwrite it) and seed `entry.resolved`/the store from that live value, exactly like
	 *    {@see prefill} does at boot.
	 * 3. **...and that text disagrees with the carried record** — additionally drop the level's
	 *    record, AND stop carrying anything BELOW it. This is not a new rule: it is the module's
	 *    standing invariant, applied at swap time instead of being left to whichever `change`
	 *    fires first ({@see handleFieldChanged}: "the field's own record no longer matches its
	 *    text"). An identity that lies about the text is the defect class #339 and #350 were
	 *    both about.
	 *
	 * THE DESCENDANT HALF OF RULE 3 IS NOT OPTIONAL (round 4 critic, HIGH). Dropping only the
	 * contradicted level's own record leaves its descendants describing a locality the customer
	 * has just disowned, and — worse — branch 1 would then WRITE one in: billing holds a picked
	 * `Москва` plus a picked address, the customer types `Жуковский` into `shipping_city` and
	 * leaves `shipping_address_1` empty, then toggles. The settlement node takes branch 3, but
	 * the address node still sees `records.address` and silently fills the incoming column with a
	 * street belonging to the OTHER city — and because `resolved` is now seeded, no later `change`
	 * ever runs {@see clearDescendants} to repair it. So a contradiction blocks every deeper level:
	 * their records and `unresolved` markers are dropped and nothing is written into their fields.
	 *
	 * Blocking drops IDENTITY, never text the customer can see: a descendant field that already
	 * holds something keeps it, exactly as {@see clearDescendants}'s own #350 amendment
	 * (operator decision, 17.08.2026) keeps downstream TEXT when the level above turns out
	 * unresolvable. This stays a rebind, never a destructive cascade.
	 *
	 * The postcode node has no record of its own, so it carries the OUTGOING postcode field's
	 * live value instead — the same string {@see backwardsFill} would have written there, read
	 * directly off `document.getElementById( previousPostcodeId )` here (never captured into
	 * `outgoingLevelText`): postcode is never a `buildSelectField()`-swapped field — no renderer
	 * in `location-select-modes.js` targets it — so it carries none of the level nodes' restore
	 * hazard and a post-detach DOM read is safe exactly as it always was. A blocked carry
	 * withholds it too: that postcode belongs to the disowned locality.
	 *
	 * @param {Object} entry
	 * @param {Array<{level: ?string, fieldId: string, section: string}>} previousAllNodes
	 * @param {Array<{level: ?string, fieldId: string, section: string}>} newAllNodes
	 * @param {Object.<string, string>} outgoingLevelText Per-LEVEL text
	 *   {@see rebuildChainForActiveSection} captured from the outgoing node's live element before
	 *   detaching it; absent for a level that had no outgoing node.
	 * @returns {void}
	 */
	function carryChainStateToIncomingNodes( entry, previousAllNodes, newAllNodes, outgoingLevelText ) {
		var previousIds = previousAllNodes.map( function( node ) {
			return node.fieldId;
		} );
		var previousPostcodeId = null;

		previousAllNodes.forEach( function( node ) {
			if ( ! node.level ) {
				previousPostcodeId = node.fieldId;
			}
		} );

		// Set by rule 3 below. `newAllNodes` is in LEVELS order with the postcode last, so once a
		// level's carried identity has been contradicted every node visited afterwards IS a
		// descendant of it.
		var carryBlocked = false;

		newAllNodes.forEach( function( node ) {
			// Runs BEFORE the "stayed in the chain" skip: a descendant that kept its field still
			// carries an identity the contradicted ancestor above it has just invalidated.
			if ( carryBlocked && node.level ) {
				entry.records[ node.level ] = null;
				entry.unresolved[ node.level ] = null;
			}

			if ( previousIds.indexOf( node.fieldId ) !== -1 ) {
				return; // stayed in the chain — its own field state was never orphaned by the swap.
			}

			var el = document.getElementById( node.fieldId );

			if ( ! el ) {
				return;
			}

			if ( carryBlocked ) {
				// Seed the change-gate from whatever the field already holds, and write NOTHING:
				// anything this level could have carried describes the disowned locality.
				entry.store.setValue( node.fieldId, el.value );
				entry.resolved[ node.fieldId ] = cascadeKey( el.value );

				return;
			}

			var record = node.level ? entry.records[ node.level ] : null;
			var carried;

			if ( node.level ) {
				carried = record ? ( outgoingLevelText[ node.level ] || fieldValueFor( record, node.level ) ) : '';
			} else {
				var previousEl = previousPostcodeId ? document.getElementById( previousPostcodeId ) : null;
				carried = previousEl ? cascadeKey( previousEl.value ) : '';
			}

			if ( '' === cascadeKey( el.value ) && '' !== carried ) {
				writeSilently( entry, node.fieldId, carried );
				return;
			}

			entry.store.setValue( node.fieldId, el.value );
			entry.resolved[ node.fieldId ] = cascadeKey( el.value );

			if ( record && cascadeKey( el.value ) !== carried ) {
				entry.records[ node.level ] = null;
				entry.unresolved[ node.level ] = null;
				carryBlocked = true;
			}
		} );
	}

	/**
	 * Resolves the store instance for `config` — an EXISTING one via `getStoreForField()` when
	 * `checkout-field-classic.js` (or an earlier boot pass) already created it for one of these
	 * SAME fields, else a fresh one. See the file docblock's STORE SHARING section.
	 *
	 * @param {Object}   config
	 * @param {string[]} fieldIds
	 * @returns {Object}
	 */
	function resolveStore( config, fieldIds ) {
		if ( 'function' === typeof factory.getStoreForField ) {
			for ( var i = 0; i < fieldIds.length; i++ ) {
				var existing = factory.getStoreForField( fieldIds[ i ] );

				if ( existing ) {
					return existing;
				}
			}
		}

		return factory.createStore( config );
	}

	/**
	 * Builds one cascade entry from a matching config global.
	 *
	 * @param {Object} config
	 * @returns {Object}
	 */
	function buildEntry( config ) {
		var chain = buildChain( config.fields );
		var postcodeNode = derivePostcodeNode( chain );
		var postcodeFieldId = postcodeNode ? postcodeNode.fieldId : null;
		var allNodes = chain.concat( postcodeNode ? [ { level: null, fieldId: postcodeNode.fieldId, section: postcodeNode.section } ] : [] );
		var fieldIds = Object.keys( config.fields || {} );

		return {
			config: config,
			location: config.location,
			store: resolveStore( config, fieldIds ),
			chain: chain,
			postcodeFieldId: postcodeFieldId,
			allNodes: allNodes,
			// Per-field remembered value the field is currently CONSISTENT with — gates
			// destructive clearing exactly like `checkout-field-classic.js`'s own `resolved`.
			resolved: {},
			// Per-LEVEL confirmed record (only chain levels; postcode never has one of its own).
			records: {},
			// Per-LEVEL text a COMPLETED search already proved the provider has nothing for
			// (issue #350) — `{ [level]: string|null }`, written by {@see onAbandonFor}, cleared
			// by a successful pick at that level ({@see onSelectFor}) or by the field's own text
			// changing to something else ({@see handleFieldChanged}). See {@see isAddressLocked}'s
			// own #350 amendment, its only consumer.
			unresolved: {},
			// Per-LEVEL snapshot of what {@see clearDescendants} most recently wiped as a DIRECT
			// result of editing THAT level's own field — `{ [level]: {fieldId: previousValue}|null }`
			// (issue #350 follow-up, operator decision 17.08.2026: the customer keeps their
			// downstream TEXT when the edit above it turns out unresolvable — only the IDENTITY,
			// `records[level]`, is genuinely gone). Written by {@see clearDescendants} itself
			// (never anywhere else), consumed and cleared by {@see restoreClearedDescendants}
			// (called from {@see onAbandonFor}), and discarded early by a successful pick at that
			// level ({@see onSelectFor}) so a stale snapshot from BEFORE the pick can never be
			// restored against some later, unrelated abandon.
			clearedByEdit: {},
			// fieldId -> { el, api } for every CURRENTLY attached widget (typeahead OR one of
			// Task 13's mode-specific renderers — see attachOne()/resolveModeRenderer()).
			widgets: {},
			// Single-flight /select queue state (Finding 2) — see enqueueSelect()/sendNextSelect().
			pendingRecord: null,
			selectInFlight: false,
			// Task 13 / #295 finding 1: the fieldId the MOST RECENT selection came from (any
			// level — the /select queue is per ENTRY, not per node) and the currently-shown
			// "your choice was not saved" notice element, if any — see
			// showNotPersistedNotice()/clearNotPersistedNotice().
			lastSelectedFieldId: null,
			notPersistedNotice: null,
		};
	}

	var entries = Object.keys( window ).filter( function( key ) {
		return 0 === key.indexOf( PREFIX );
	} ).map( function( key ) {
		return window[ key ];
	} ).filter( function( config ) {
		return config && config.fields && config.location;
	} ).map( buildEntry );

	if ( ! entries.length ) {
		return;
	}

	// -------------------------------------------------------------------------
	// Scoping
	// -------------------------------------------------------------------------

	/**
	 * The chain node for `level`, or `null` when that level's field is absent (spec §4.4:
	 * absent fields narrow the chain without breaking it).
	 *
	 * @param {Object} entry
	 * @param {string} level
	 * @returns {{level: string, fieldId: string}|null}
	 */
	function chainNodeForLevel( entry, level ) {
		for ( var i = 0; i < entry.chain.length; i++ ) {
			if ( entry.chain[ i ].level === level ) {
				return entry.chain[ i ];
			}
		}

		return null;
	}

	/**
	 * The locality key to scope a suggest call at `level` by — the IMMEDIATE parent level's
	 * OWN chosen record key, only when that parent field EXISTS in the chain and has a
	 * confirmed record carrying a key ("exists and is filled", spec §4.4 — "filled" means
	 * resolved to a real record, not merely non-empty text, since only a record has a KEY the
	 * REST `within` param can use). `null` (country-wide) otherwise — never a further
	 * ancestor: address is scoped by locality only, never by region even when both are present
	 * (spec §4.4: "Address suggestions are scoped by locality"). A record is either the result
	 * of a direct selection at that level or a restored `config.location.current` seed (Task 11
	 * "restore state without re-fetching") — both carry a usable `.key`.
	 *
	 * @param {Object} entry
	 * @param {string} level
	 * @returns {string|null}
	 */
	function scopeKeyFor( entry, level ) {
		var idx = LEVELS.indexOf( level );
		var parentLevel = idx > 0 ? LEVELS[ idx - 1 ] : null;

		if ( ! parentLevel || ! chainNodeForLevel( entry, parentLevel ) ) {
			return null;
		}

		var record = entry.records[ parentLevel ];

		return record && record.key ? record.key : null;
	}

	// -------------------------------------------------------------------------
	// Fetch / select for one chain node
	// -------------------------------------------------------------------------

	/**
	 * Builds the `fetch(query, opts)` callback handed to the Task 10 widget for one chain node —
	 * scope is read LIVE at call time (never captured at attach time), so a parent selection
	 * made after attach is honoured on the very next keystroke.
	 *
	 * Each suggestion is given its `value` here — the string the widget writes into the input
	 * on selection, derived for THIS node's level ({@see fieldValueFor}); the widget itself is
	 * level-agnostic and would otherwise fall back to the provider's own list `label`, which
	 * carries ancestors the field must not repeat. Assigned onto the parsed response object
	 * directly: it is freshly parsed JSON owned by this closure, and `record` — the only part
	 * that round-trips back to `/select` — is left untouched.
	 *
	 * issue #449 (second half): `opts.signal`, when supplied, is forwarded verbatim onto the
	 * underlying `fetch()`'s own `init.signal` — the Fetch API's own idiom for a caller-owned
	 * `AbortController` (the smallest change to this transport, and the shape both consumers of
	 * this callback already wrap in a promise rather than a jqXHR-like abortable object). The
	 * baseline typeahead ({@see location-typeahead.js}'s `attachTypeahead()`) does not pass one —
	 * it already discards a superseded response via its own generation counter (that file's own
	 * STALE-RESPONSE DISCARD section) rather than aborting the request, so `opts` is genuinely
	 * OPTIONAL here, never assumed present.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string}} node
	 * @returns {function(string, {signal?: AbortSignal}=): Promise<Array>}
	 */
	function fetchFor( entry, node ) {
		return function( query, opts ) {
			var url = buildUrl( entry.location.endpoints.suggest, {
				q: query,
				level: node.level,
				country: countryFor( entry, node ),
				within: scopeKeyFor( entry, node.level ),
			} );

			var init = { method: 'GET', headers: nonceHeader( entry ) };

			if ( opts && opts.signal ) {
				init.signal = opts.signal;
			}

			return fetchJson( url, init ).then( function( body ) {
				var suggestions = body && Array.isArray( body.suggestions ) ? body.suggestions : [];

				suggestions.forEach( function( suggestion ) {
					if ( suggestion ) {
						suggestion.value = fieldValueFor( suggestion.record, node.level );
					}
				} );

				return suggestions;
			} );
		};
	}

	/**
	 * Builds the `list()` callback handed to a Task 13 `related-list` renderer for one chain
	 * node — the `/location/list` analog of {@see fetchFor}: same live-scope semantics
	 * (country/within read at call time, never captured at attach time) and the SAME `value`
	 * stamping via {@see fieldValueFor}, so a `related-list` select's own value space is derived
	 * in exactly the one place `fetchFor()` already derives it for `ajax-select2` — never
	 * re-derived (or forgotten) at the presentation site (issue #463: `attachRelatedListSettlement()`
	 * previously called `options.fetchJson()` against `/location/list` directly and never stamped
	 * `.value` at all, so `buildSelectField()`'s own `applyEntries()` fell back to `entry.key` —
	 * the raw provider key — exactly the #455 disease on the `ajax-select2` branch that PR already
	 * closed).
	 *
	 * issue #449 (second half): deliberately does NOT accept an `opts.signal` the way
	 * {@see fetchFor} now does. `attachRelatedListSettlement()` calls this ONCE, on attach/country
	 * change — never per keystroke — so there is no rapid-fire supersession to cancel: by the time
	 * a second call could ever happen, the field (and any renderer watching it) has already been
	 * torn down and rebuilt for the new country. Revisit only if a caller starts invoking this
	 * more than once per attach.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string}} node
	 * @returns {function(): Promise<Array>}
	 */
	function listFor( entry, node ) {
		return function() {
			var url = buildUrl( entry.location.endpoints.list, {
				level: node.level,
				country: countryFor( entry, node ),
				within: scopeKeyFor( entry, node.level ),
			} );

			return fetchJson( url, { method: 'GET', headers: nonceHeader( entry ) } ).then( function( body ) {
				var localities = body && Array.isArray( body.localities ) ? body.localities : [];

				localities.forEach( function( locality ) {
					if ( locality ) {
						locality.value = fieldValueFor( locality.record, node.level );
					}
				} );

				return localities;
			} );
		};
	}

	/**
	 * Writes `value` onto `el` — an `<input>` gets a plain `.value` assignment (unchanged);
	 * a `<select>` (issue #460) needs an actually SELECTED `<option>`, or the field is
	 * silently omitted from the next `update_checkout` POST.
	 *
	 * A mode-specific renderer (Task 13; `location-select-modes.js`) replaces a chain field's
	 * plain `<input>` with a `<select>` whose OWN `<option>` VALUE space this module
	 * deliberately never learns (the D7 "mode is presentation" seam — see the file docblock's
	 * RENDERER SEAM section): `ajax-select2`'s underlying `<select>` starts with NO options at
	 * all (populated lazily, per keystroke, into select2's own detached UI — see that file's
	 * own `buildSelectField()` docblock), and `related-list`'s registers real WooCommerce
	 * state options whose VALUE is `wc_strtoupper( trim( label ) )`, never a bare component
	 * name (`class-checkout-config.php::build_location_block()`'s own "related-list region
	 * seam" docblock). {@see fieldValueFor} — this module's ONLY value vocabulary — produces
	 * neither. Setting `.value` directly on a `<select>` with no matching `<option>` selects
	 * NOTHING (`selectedIndex` becomes `-1`, per the HTMLSelectElement value setter steps), so
	 * a backwards-filled region or settlement never reaches WooCommerce at all, regardless of
	 * what the DOM briefly showed — measured on the rig: the store's own `RU:*` "no state"
	 * default survives untouched.
	 *
	 * Three tries, in order: (1) an EXISTING option whose `value` already matches — the
	 * ordinary case for anything this module already wrote itself; (2) an existing option
	 * whose visible TEXT matches — `related-list` pre-populates the WHOLE country's real,
	 * WC-canonical options up front (see that mode's own seam), so a text match finds and
	 * selects the CORRECT registered value, never a guess; (3) only when neither exists (the
	 * `ajax-select2` case above, where no real option ever precedes a live search) — a synthetic
	 * `<option>` carrying `value` verbatim as both its value and its text, marked with
	 * {@see SYNTHETIC_OPTION_ATTR} and REUSED on every later call rather than appended anew (a
	 * region changed twice must not leave a stale, deselected option behind — issue #462 round
	 * 2). `ajax-select2` is offered by this layer only for a country WooCommerce's own state
	 * list has NOTHING registered for (`levels[country]['region']`'s own derivation), so a
	 * synthetic option is a value WooCommerce's checkout processing accepts and stores as
	 * written — strictly better than the status quo (a field submitting nothing at all), never
	 * a claim that this is the value the field's own widget would have produced from a live
	 * pick.
	 *
	 * A selected `<option>` alone is not enough once select2/selectWoo has enhanced the field
	 * (issue #462 round 2 — Codex critic, s86): the widget renders from a snapshot it only
	 * re-pulls on a `change` event it hears itself
	 * (`Select2.prototype._registerDomEvents`'s `this.$element.on('change.select2', ...)`,
	 * `selectWoo.full.js:5345-5354`), so a bare `selectedIndex` assignment (tries 1, 2, and the
	 * reuse half of try 3) updates the real `<select>` but leaves the WIDGET showing whatever it
	 * last rendered — stale or empty — while the field silently posts the newly restored value.
	 * A freshly APPENDED `<option>` (try 3's final fallback) is, IN PRINCIPLE, exactly what
	 * selectWoo's own separate `MutationObserver({childList:true, subtree:false})` watches for —
	 * REUSING an already-present node is what that `subtree:false` scope cannot see, an append
	 * always can (`Select2.prototype._syncSubtree`, `selectWoo.full.js:5573-5611`). Issue #465
	 * (symptom B) is what makes this branch call {@see refreshSelectWooWidget} too, unconditionally,
	 * rather than resting on that observer alone — see the append branch's own comment for why.
	 * So every branch — matched-option select, synthetic reuse, AND fresh append — now explicitly
	 * re-fires the widget via {@see refreshSelectWooWidget}.
	 *
	 * ISSUE #465 (rig, s86, region "only updates once per reload"): `refreshSelectWooWidget()`'s
	 * `change.select2` trigger makes selectWoo RE-RUN its render pass, but that pass —
	 * `SelectAdapter.prototype.current()` -> `item($option)` (`selectWoo.full.js:3167-3180`,
	 * `3352-3396`) — returns `$.data($option[0], 'data')` VERBATIM when that key is already set,
	 * never rebuilding it from the option's live `value`/`text`. `item()` itself is what SETS
	 * that key, the first time anything reads the node (the initial append, read by selectWoo's
	 * own `MutationObserver` re-sync above — correct, since the node is fresh). Every later call
	 * through the SYNTHETIC-REUSE branch below mutates that SAME node's `value`/`textContent` in
	 * place (issue #462 round 2's own fix for option accumulation) without ever touching the
	 * cache, so `refreshSelectWooWidget()`'s trigger re-renders the widget from data describing
	 * the PREVIOUS fill, not the one just written — the field posts correctly; only the widget
	 * lies. `$.removeData(option, 'data')` — the exact key `item()`/`.option()` write to
	 * (`selectWoo.full.js:3347,3393`) — forces the next `item()` call to rebuild from the DOM,
	 * which by then already carries the new value/text. The two matched-option tries (1, 2)
	 * never mutate an option's `value`/`text` — they select an option someone ELSE already built
	 * correctly for THIS value — so their own cache, if any, was never wrong and needs no
	 * invalidation.
	 *
	 * @see docs-internal/research/2026-08-21-select2-location-fields.md
	 * @param {Element} el
	 * @param {string}  value
	 * @returns {void}
	 */
	function applyValueToElement( el, value ) {
		if ( 'SELECT' !== el.tagName ) {
			el.value = value;
			return;
		}

		var options = el.options || [];
		var i;

		for ( i = 0; i < options.length; i++ ) {
			if ( options[ i ].value === value ) {
				el.selectedIndex = i;
				refreshSelectWooWidget( el );
				return;
			}
		}

		for ( i = 0; i < options.length; i++ ) {
			if ( options[ i ].textContent === value ) {
				el.selectedIndex = i;
				refreshSelectWooWidget( el );
				return;
			}
		}

		for ( i = 0; i < options.length; i++ ) {
			if ( options[ i ].hasAttribute( SYNTHETIC_OPTION_ATTR ) ) {
				options[ i ].value = value;
				options[ i ].textContent = value;

				// Issue #465: this node is REUSED (issue #462 round 2), so select2/selectWoo's
				// own `item()` cache from a PREVIOUS fill is still attached to it — see this
				// function's own docblock. Clearing the exact key selectWoo itself reads/writes
				// (`$.data(el, 'data')`) forces its next render to rebuild from the value/text
				// just written above, instead of replaying a stale fill.
				if ( window.jQuery ) {
					window.jQuery.removeData( options[ i ], 'data' );
				}

				el.selectedIndex = i;
				refreshSelectWooWidget( el );
				return;
			}
		}

		var option = document.createElement( 'option' );

		option.value = value;
		option.textContent = value;
		option.setAttribute( SYNTHETIC_OPTION_ATTR, '' );

		el.appendChild( option );
		el.selectedIndex = el.options.length - 1;

		// Issue #465, symptom B: a freshly appended option is, in principle, exactly what
		// selectWoo's own MutationObserver watches for (`Select2.prototype._syncSubtree`,
		// `selectWoo.full.js:5573-5611` — re-pulls `current()` whenever an added node's
		// `.selected` is `true`, which it already is by the time that observer's callback runs,
		// since `el.selectedIndex` was set synchronously above, before the microtask queue
		// flushes). This call site is specifically {@see clearDescendants}/{@see clearCountryScope}
		// silently blanking a field THROUGH THIS FUNCTION for the first time (issue #465's own
		// fix) — the observer theory above is unverified against a real widget for a clear that
		// follows immediately after attach, and `refreshSelectWooWidget()` is a proven no-op
		// whenever nothing needs it (see its own docblock), so this branch gets the same explicit
		// nudge as the three above rather than leaning on an unverified race.
		refreshSelectWooWidget( el );
	}

	/**
	 * Re-renders a select2/selectWoo widget after a SILENT `selectedIndex` change on its
	 * underlying `<select>` (see {@see applyValueToElement}'s own docblock for why this is
	 * needed at all) — WITHOUT tripping this module's own change-gate ({@see bindChangeWorlds},
	 * {@see handleFieldChanged}).
	 *
	 * The trigger is NAMESPACED — `change.select2`, never a bare `change` — deliberately: jQuery
	 * only invokes a handler whose OWN namespace is a superset of the triggered one
	 * (`jQuery.event.dispatch`'s `event.rnamespace.test( handleObj.namespace )`, verified against
	 * `node_modules/jquery/dist/jquery.js`), so this reaches ONLY select2's own internal
	 * `change.select2` binding (`selectWoo.full.js:5348`) — never this module's own delegated
	 * `change` listener, which is bound with NO namespace on both event worlds and would
	 * otherwise misread a silent restore as a user-driven parent change (the exact failure mode
	 * `writeSilently()`'s own docblock documents). A jQuery `.trigger()` never dispatches a real
	 * native DOM event either way (gotcha `jquery-trigger-change-fires-no-native-event`, this
	 * file's own docblock), so the native half of the change-gate is untouched regardless.
	 *
	 * A no-op with no jQuery loaded (plain `<select>`, this file's own jQuery-less test paths) —
	 * mirrors {@see triggerCheckoutUpdate}'s own guard — and a no-op with jQuery loaded but no
	 * select2 ever bound to `el` (an ordinary WC `<select>`, or this file's jsdom test
	 * environment, which has no select2 package at all): a namespaced trigger with no matching
	 * listener does nothing.
	 *
	 * @param {Element} el
	 * @returns {void}
	 */
	function refreshSelectWooWidget( el ) {
		if ( window.jQuery ) {
			window.jQuery( el ).trigger( 'change.select2' );
		}
	}

	/**
	 * Writes `value` into `fieldId`'s store slot AND its live DOM element WITHOUT dispatching
	 * any event — the write path for backwards fill and the `updated_checkout` safety-net
	 * restore, both of which must NOT be mistaken for a user-driven parent change by this same
	 * module's own change-gate (see the file docblock). Also seeds `resolved[fieldId]` to the
	 * SAME value, so a later genuine event comparing against it correctly sees "unchanged".
	 *
	 * The live DOM write goes through {@see applyValueToElement} (issue #460) rather than a
	 * bare `.value =` — see that function's own docblock for why a `<select>` needs more than
	 * that to actually submit what this call intends.
	 *
	 * @param {Object} entry
	 * @param {string} fieldId
	 * @param {string} value
	 * @returns {void}
	 */
	function writeSilently( entry, fieldId, value ) {
		entry.store.setValue( fieldId, value );
		entry.resolved[ fieldId ] = cascadeKey( value );

		var el = document.getElementById( fieldId );

		if ( el ) {
			applyValueToElement( el, value );
		}
	}

	/**
	 * Backwards fill (spec §4.4): writes region/settlement/postcode from a settlement- or
	 * address-level record's OWN embedded components — no second lookup. Only levels STRICTLY
	 * BEFORE the selected one are filled (selecting a settlement never touches address).
	 *
	 * SKIPS A FOREIGN-OWNED ANCESTOR (issue #352, Variant A): an ancestor level with a KNOWN
	 * owner ({@see levelOwner}) that DIFFERS from `record.provider_id` is left untouched — this
	 * is the measured bug's field-text half. A mixed provider chain (e.g. the active provider
	 * serving `region`/`settlement`, the bundled DaData fallback serving `address`) used to let
	 * the customer pick their settlement from one provider («Москва», Cyrillic) and their street
	 * from the other, whose OWN record for that same locality carries a different spelling under
	 * a different account locale («Moscow» — gotcha `a-locality-display-name-is-not-an-identifier`)
	 * — this function would then overwrite the settlement field's correct text with the address
	 * provider's own, wrong one. An ancestor level with NO known owner (`''` — unserved, or an
	 * older config carrying no `owners` map at all) still fills exactly as before this fix:
	 * nobody owns it, so nothing is being clobbered by writing there.
	 *
	 * `record.postcode` is NEVER gated by ownership — postcode is not a chain LEVEL (it has no
	 * entry in {@see LEVELS}/`owners`), and a foreign provider's postcode for the customer's own
	 * street is correct data the order wants regardless of which provider resolved the settlement
	 * above it.
	 *
	 * OWNERSHIP IS JUDGED BY THE RECORD'S OWN COUNTRY (issue #352 follow-up, P1), via
	 * {@see countryForRecord} — not the live country field {@see countryFor} alone would read —
	 * so a country change between fetching the suggestion and clicking it cannot make this
	 * function consult the wrong country's owner map. See {@see countryForRecord}'s own
	 * docblock for the full reasoning and what this deliberately does NOT fix (issue #346).
	 *
	 * @param {Object} entry
	 * @param {{level: string, section?: string}} node   The node that was just selected — its
	 *                                                    `level` is the level just selected;
	 *                                                    its `section` is used only as
	 *                                                    {@see countryForRecord}'s fallback when
	 *                                                    `record` carries no `country` of its own.
	 * @param {Object} record The selected record.
	 * @returns {void}
	 */
	function backwardsFill( entry, node, record ) {
		var level = node.level;
		var idx = LEVELS.indexOf( level );
		// Issue #352 follow-up (P1): the RECORD's own country, not the live field — see
		// {@see countryForRecord}'s own docblock for why.
		var country = countryForRecord( entry, node, record );

		LEVELS.forEach( function( ancestorLevel, i ) {
			if ( i >= idx ) {
				return;
			}

			var ancestorNode = chainNodeForLevel( entry, ancestorLevel );
			var component = record[ ancestorLevel ];

			if ( ! ancestorNode || ! component ) {
				return;
			}

			var owner = levelOwner( entry, country, ancestorLevel );

			if ( owner && owner !== record.provider_id ) {
				return;
			}

			// Same derivation a direct pick at that level gets ({@see fieldValueFor}) — a
			// backwards-filled field and a directly picked one must not read differently.
			writeSilently( entry, ancestorNode.fieldId, fieldValueFor( record, ancestorLevel ) );
		} );

		if ( record.postcode && entry.postcodeFieldId ) {
			writeSilently( entry, entry.postcodeFieldId, record.postcode );
		}
	}

	/**
	 * Handles `woodev_pickup_address_replacing` (issue #339) — `pickup-mount.js` announces,
	 * one synchronous event BEFORE it happens, that it is about to write a selected pickup
	 * point's OWN address into the shared WooCommerce address fields (SP-5's
	 * `applyAddressReplacement()`).
	 *
	 * WHY AN ANNOUNCEMENT AND NOT SOMETHING THIS MODULE COULD RECOGNISE ALONE: by the time
	 * the write arrives it is indistinguishable from a human edit. It carries a different
	 * SPELLING of the same locality — the carrier answers «Москва» while the provider said
	 * «Moscow» under an English account locale (gotcha
	 * `a-locality-display-name-is-not-an-identifier`) — so {@see handleFieldChanged} reads
	 * "the field's own record no longer matches its text", drops the settlement record, and
	 * the next address search leaves without `within`, country-wide (#339). That rule is
	 * right and stays; what was missing is that THIS write is not the customer's.
	 *
	 * RE-SEEDING, NOT SUPPRESSING, IS THE WHOLE FIX. The point's address is exactly what must
	 * reach the order — a point may legitimately stand in a NEIGHBOURING settlement (ordinary
	 * for New Moscow), so writing the customer's own locality there instead would fix the
	 * search scope by corrupting the delivery address. The values land; only the CONFIRMED
	 * RECORD survives untouched, because the customer's own pick is what scopes the next
	 * search.
	 *
	 * Deliberately NOT done by running the point's address through
	 * `Location_Provider::normalize()` first: measured on the rig 16.08.2026 (see #339), that
	 * returns the key of the DEEPEST resolved object — a house or a street, never the
	 * settlement — costs a paid, uncached Clean API call per selection, is an OPTIONAL
	 * provider capability whose absence THROWS rather than returning null, and on a
	 * carrier-shaped string can silently answer a different address entirely
	 * (`Москва Внуково Центральная 6` → `г Москва, ул Центральная, д 6`, `qc = 3`).
	 *
	 * Scoped to the announced write and nothing after it: {@see writeSilently} re-seeds
	 * `entry.resolved` for these exact values only, so the very next genuine manual edit
	 * invalidates normally.
	 *
	 * @internal
	 *
	 * @param {CustomEvent} event `detail: { fields: { fieldId: value } }`.
	 * @returns {void}
	 */
	function handlePickupAddressReplacing( event ) {
		var fields = event && event.detail ? event.detail.fields : null;

		if ( ! fields || 'object' !== typeof fields ) {
			return;
		}

		entries.forEach( function( entry ) {
			Object.keys( fields ).forEach( function( fieldId ) {
				// Another entry's section, or not a cascade field at all (the announcement
				// names WooCommerce field ids, which only PARTLY overlap this entry's chain).
				if ( ! nodeInfo( entry, fieldId ) ) {
					return;
				}

				writeSilently( entry, fieldId, String( fields[ fieldId ] ) );
			} );
		} );
	}

	/**
	 * D8 + single-flight ordering (PR-C review, Finding 2): POSTs `record` to `/select`. Only
	 * ever ONE `/select` request is in flight per ENTRY at a time — the server holds exactly
	 * ONE current-location slot per entry's own `Location_Service`
	 * (`Location_Controller::handle_select_request()` → `set_customer_record()`), so two
	 * concurrent POSTs for the SAME entry (even from DIFFERENT chain nodes/levels — a region
	 * pick and a settlement pick race the SAME slot) can be answered out of order by the
	 * server, letting an OLDER pick overwrite a NEWER one. This module never lets that happen:
	 * a selection made while a request is already in flight replaces `entry.pendingRecord`
	 * (see {@see enqueueSelect}) instead of firing a second concurrent request; a
	 * selection queued-but-not-yet-sent is superseded again by a later one, so any selection
	 * except the LAST one made before the in-flight request settles is never sent to the
	 * server at all. `jQuery(document.body).trigger('update_checkout')` fires only for the
	 * response that finds no newer pending selection waiting when it settles — i.e. only ever
	 * for the customer's FINAL choice, never once per superseded intermediate one (see
	 * {@see settleSelect}). A failed request or `persisted: false` (e.g. a guest whose session
	 * cookie has not initialized yet) never fires the trigger, but — critically — never jams
	 * the queue either: {@see settleSelect} always frees the single-flight slot and dequeues
	 * whatever is pending, on success OR failure alike.
	 *
	 * GUARANTEE: once the customer stops selecting, the record persisted server-side equals
	 * their MOST RECENT selection, and `update_checkout` fires exactly once for it.
	 *
	 * @param {Object} entry
	 * @param {Object} record
	 * @returns {void}
	 */
	function enqueueSelect( entry, record ) {
		entry.pendingRecord = record;

		if ( entry.selectInFlight ) {
			return; // a request is already in flight — it will pick this up in settleSelect().
		}

		sendNextSelect( entry );
	}

	/**
	 * Dequeues `entry.pendingRecord` (if any) and sends it — the sole place that actually
	 * issues a `/select` POST. A no-op when nothing is queued (the single-flight slot simply
	 * stays free until the next {@see enqueueSelect} call).
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function sendNextSelect( entry ) {
		var record = entry.pendingRecord;

		if ( ! record ) {
			return;
		}

		entry.pendingRecord = null;
		entry.selectInFlight = true;

		var url = entry.location.endpoints.select;
		var headers = nonceHeader( entry );

		headers[ 'Content-Type' ] = 'application/json';

		fetchJson( url, { method: 'POST', headers: headers, body: JSON.stringify( { record: record } ) } ).then(
			function( body ) {
				// `persisted` is always present on a successful response (Location_Controller::
				// handle_select_request()'s own return type — never ambiguous), so `!shouldTrigger`
				// here means exactly one thing: the server answered, honestly, that it could not
				// write the record (#295 finding 1 — typically a guest whose session/cart cookie
				// has not initialized yet, gotcha `guest-session-write-needs-the-cart-cookie`).
				var persisted = !! ( body && false !== body.persisted );

				// Issue #330 (spec §7): the response carries the server's own rebuilt `chain`
				// alongside `current`/`persisted` — adopting it here (whether or not THIS
				// request itself persisted) means a server-side chain repair can never be
				// stranded behind a client still scoping suggest calls by a stale parent key.
				// Runs before settleSelect() so nothing downstream of it (a stale-response
				// forward to the next queued send, or this response's own final trigger/event)
				// can run against records this adoption was about to overwrite anyway.
				adoptChain( entry, body && body.chain );

				// Issue #337: the server's own chain is authoritative ({@see adoptChain}), so a
				// repair that DROPPED the settlement level must re-lock the address field the
				// optimistic pick above already unlocked.
				refreshAddressLock( entry );

				settleSelect( entry, persisted, ! persisted, record );
			},
			function( error ) {
				logError( error );
				// A network/parse failure is a DIFFERENT failure mode from an honest
				// `persisted: false` — the server never got to answer at all, so there is no
				// signal to "consume" here (#295 finding 1 is specifically about the EXISTING
				// `persisted` flag going unread, not about inventing a new one for transport
				// errors). Stays silent to the customer, same as before this task; the visible
				// field value still survives (the widget already wrote it before onSelect ran).
				settleSelect( entry, false, false, record );
			}
		);
	}

	/**
	 * Fires WooCommerce's own `update_checkout` — the ONE place this module fires it itself,
	 * because WooCommerce's OWN client-side gate does not reliably fire it off an address change
	 * on its own (gotcha `wc-does-not-save-the-address-until-every-required-text-field-is-filled`
	 * — `checkout.js`'s `maybe_update_checkout()` withholds it while ANY required TEXT field in
	 * the same block is still empty; see the file docblock's own D8/PERSIST THEN TRIGGER
	 * section). Two call sites share this exact trigger (issue #352): the ordinary
	 * persisted-`/select` path ({@see settleSelect}), and the foreign-provider-record path
	 * ({@see onSelectFor}, gated by {@see mayEnterChain}) — a record `mayEnterChain()` refuses
	 * never reaches `/select` at all, but {@see backwardsFill}'s SILENT writes (never dispatching
	 * an `input`/`change` event of their own — see {@see writeSilently}) may still have changed
	 * the address WooCommerce needs to re-price, so the checkout must still refresh even though
	 * nothing was persisted server-side for this pick.
	 *
	 * @returns {void}
	 */
	function triggerCheckoutUpdate() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	/**
	 * Frees the single-flight slot for `entry` and either forwards to the next queued
	 * selection (a newer one arrived while this request was in flight — this response is
	 * stale by construction, so it never fires the trigger NOR shows a notice for it) or,
	 * when nothing newer is queued, treats this response as FINAL: fires `update_checkout`
	 * iff `shouldTrigger`, or — Task 13 / #295 finding 1 — shows the "your choice was not
	 * saved" notice iff `notPersisted` (an honest `persisted: false` from the server, as
	 * opposed to a network failure, which stays silent — see {@see sendNextSelect}'s own
	 * comment on that distinction). A successful FINAL persist always clears any notice a
	 * PRIOR selection may have left behind ({@see clearNotPersistedNotice}), so a stale
	 * warning never survives past the outcome it described.
	 *
	 * FIRES `woodev_location_applied` (Task 15; issue #159) on the SAME "this response is
	 * final AND persisted" condition as `update_checkout` above — a NATIVE, bubbling
	 * `CustomEvent` on `document.body` (never a jQuery `.trigger()`, unlike
	 * `update_checkout`: this is OUR OWN event, so there is no third-party producer to
	 * accommodate — see {@see fireLocationApplied}), carrying `{ key, level }` off the
	 * JUST-PERSISTED record. This is what lets `pickup-mount.js`'s own
	 * `resolveLocalityKey()` track the customer's CURRENT locality key without reading
	 * a checkout DOM field at all — the whole point of #159. Fired for the SAME response
	 * `update_checkout` fires for (never a superseded intermediate one), so a listener
	 * never sees a STALE key overwrite a newer one either.
	 *
	 * ALSO FIRES `woodev_location_applied` WITH AN EMPTY KEY on `notPersisted` (review
	 * finding F2, rig-verified: this branch used to fire nothing at all). The DOM field
	 * already shows the customer's NEW choice — the widget writes it before `onSelect` ever
	 * runs, same as every other `/select` outcome — but the server never persisted it, so
	 * whatever key `pickup-mount.js`'s `resolveLocalityKey()` was caching is now unknown, not
	 * merely unrefreshed: continuing to address the points query by the OLD key would silently
	 * offer points for a locality the customer no longer sees reflected anywhere in the UI.
	 * `fireLocationApplied( null )` — see that function's own docblock for why a missing
	 * record already degrades to `key: ''` — is the same "the seam is refusing to answer"
	 * sentinel {@see Pickup_Handler::location_config_block()} uses server-side, and F1's own
	 * fix makes an empty key fall back to the DOM read, which is exactly the honest answer
	 * here (whatever the customer typed, not a locality the server has no record of).
	 *
	 * @param {Object}  entry
	 * @param {boolean} shouldTrigger Whether THIS response, if final, should fire the trigger
	 *                                (a successful persist with `persisted !== false`).
	 * @param {boolean} notPersisted  Whether THIS response, if final, should surface the
	 *                                not-saved notice (a successful response carrying an
	 *                                explicit `persisted: false` — never true together with
	 *                                `shouldTrigger`).
	 * @param {Object}  record        The record THIS response answered for — see
	 *                                {@see sendNextSelect}, which is this function's only
	 *                                caller.
	 * @returns {void}
	 */
	function settleSelect( entry, shouldTrigger, notPersisted, record ) {
		entry.selectInFlight = false;

		if ( entry.pendingRecord ) {
			sendNextSelect( entry );
			return;
		}

		if ( shouldTrigger ) {
			clearNotPersistedNotice( entry );

			// Issue #309: a persisted `/select` is ALWAYS an explicit customer choice
			// (`Location_Controller::handle_select_request()` never writes implicit — spec
			// D11) — `implicit` is unconditionally `false` here, regardless of what the
			// PREVIOUS state (boot's own fire, see {@see prefill}) said.
			fireLocationApplied( entry, record, false );
			triggerCheckoutUpdate();

			return;
		}

		if ( notPersisted ) {
			showNotPersistedNotice( entry );
			// Nothing was persisted, so there is no record to flag as a default guess —
			// `false`, same as every other "the record is now unknown" fire below.
			fireLocationApplied( entry, null, false );
		}
	}

	/**
	 * Fires `woodev_location_applied` (Task 15; issue #159) — see {@see settleSelect}'s own
	 * docblock for when and why. A native, bubbling `CustomEvent` on `document.body`,
	 * mirroring `pickup-mount.js`'s own `fireDocumentEvent()` exactly (that file's docblock
	 * explains why a native event, never a jQuery `.trigger()`, is what a plain
	 * `addEventListener` can see).
	 *
	 * `record.key`/`record.level` absent or non-string degrade to `''` — the same
	 * "the seam is refusing to answer, not naming a locality" sentinel the whole layer
	 * already uses (gotcha `an-empty-domain-key-is-not-a-key`); a listener must never
	 * treat an event with an empty `key` as a real locality.
	 *
	 * `implicit` (issue #309; spec D11/§4.6) has NO default — every call site states its own
	 * intent explicitly, on purpose: this is the ONE piece of this event's contract that is
	 * NOT derivable from `record` alone (a persisted `/select` never carries its own implicit
	 * flag — spec D11 — so `record.implicit` would always read `undefined` even where the
	 * caller genuinely means `true`, e.g. {@see prefill}'s own boot fire). See the file
	 * docblock's own section on `detail.implicit` for which callers pass which value and why.
	 *
	 * **`implicit` is meaningful only together with a non-empty `key`.** The clearing paths
	 * ({@see clearCountryScope}, an edit without a pick) report `implicit: false` while the
	 * SERVER record — which those paths deliberately never clear, because they POST nothing to
	 * `/select` — may still hold the implicit default that rates are computed from. That is
	 * safe only because those same events carry `key: ''`, so the sentinel rule above already
	 * tells a listener to ignore them. A consumer that reads `implicit` ALONE, without
	 * checking `key`, will conclude the customer made a choice they never made.
	 *
	 * `detail.settlementKey` (issue #336) is ALWAYS `entry.records['settlement']`'s own key when
	 * one exists, REGARDLESS of `record`/`level` — it is not "the settlement component of THIS
	 * event's record", it is "whichever settlement the customer has actually picked so far",
	 * exactly what {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_location_record()}
	 * now prefers server-side. `entry.records.settlement` is kept current by {@see adoptChain}
	 * (boot restore AND every `/select` response) and by {@see onSelectFor} on a direct settlement
	 * pick — never by {@see backwardsFill}, which only ever writes DOM text, never a record (spec
	 * §4.4) — so an address typed without ever picking a settlement correctly yields `''` here,
	 * same sentinel as `key`. `pickup-mount.js` prefers this field and falls back to `key` when it
	 * is absent/empty — see that file's own `handleLocationApplied()` docblock for why the map's
	 * rule is the OPPOSITE of the storage key's (#334): a fallback there is a needed recovery, not
	 * a mis-file.
	 *
	 * @param {Object}  entry
	 * @param {Object}  record   The just-persisted record (D8's own full, round-tripped
	 *                           shape), or `null` when the current locality is now unknown.
	 * @param {boolean} implicit Whether the record this event describes is a default guess
	 *                           rather than the customer's own choice.
	 * @returns {void}
	 */
	function fireLocationApplied( entry, record, implicit ) {
		var key = record && 'string' === typeof record.key ? record.key : '';
		var level = record && 'string' === typeof record.level ? record.level : '';
		var settlementRecord = entry && entry.records ? entry.records.settlement : null;

		// PUBLISHED ONLY WHEN THE SERVER HAS VOUCHED FOR IT, on two independent counts:
		//
		// 1. `key` must be non-empty. An empty `key` is this event's own "the customer's
		//    locality is UNKNOWN right now" sentinel (review findings F1/F2), and it exists
		//    so `pickup-mount.js` degrades to its DOM read instead of acting on state the
		//    layer cannot vouch for. Publishing a settlement beside it would hand the picker
		//    a confident answer precisely where the honest one is "I do not know", silently
		//    bypassing the fallback F1 added.
		// 2. The settlement record must be `confirmed` — i.e. adopted from the SERVER's own
		//    chain ({@see adoptChain}), not the optimistic write {@see onSelectFor} makes
		//    before the round trip resolves. Adversarial review's case: the customer picks a
		//    settlement, then an address in a DIFFERENT locality, and `/select` answers
		//    `persisted: true` but carries no usable chain — the optimistic settlement then
		//    survives and the map would query the locality the customer just left.
		//
		// Both reduce to the same rule: this event never names a locality the layer cannot
		// stand behind, and `pickup-mount.js`'s DOM fallback is the designed degradation.
		var settlementKey = '' !== key && settlementRecord && true === settlementRecord.confirmed && 'string' === typeof settlementRecord.key
			? settlementRecord.key
			: '';

		document.body.dispatchEvent(
			new CustomEvent( 'woodev_location_applied', {
				detail: { key: key, level: level, settlementKey: settlementKey, implicit: !! implicit },
				bubbles: true,
			} )
		);
	}

	/**
	 * Shows the "your choice was not saved" notice for `entry` — Task 13 / #295 finding 1: the
	 * server has always answered `persisted: false` honestly, but until now nothing on the
	 * client read it beyond skipping `update_checkout` (see this file's own D8 section above).
	 * Anchored right after the field the customer's MOST RECENT selection came from
	 * ({@see entry.lastSelectedFieldId}) — persistence is an ENTRY-level outcome (the
	 * single-flight `/select` queue in {@see enqueueSelect} is per entry, not per node), so
	 * there is no field this is MORE specifically about than "whichever one the customer just
	 * used". A missing anchor (the field no longer in the document — a country switch tore
	 * the section down between the select and this response landing) is a silent no-op: there
	 * is nowhere left to put the message, and the field itself is gone anyway.
	 *
	 * Text comes from `entry.location.i18n.notPersisted` — server-supplied, translated,
	 * filterable via `woodev_location_i18n` (`class-checkout-config.php::build_location_block()`),
	 * same convention as `noResults`/`noResultsAddress`: this string reaches the customer, so
	 * it is never a literal here. An older config without the key (or a filter that cleared
	 * it) degrades to silence rather than inventing a string client-side.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function showNotPersistedNotice( entry ) {
		clearNotPersistedNotice( entry );

		var anchor = entry.lastSelectedFieldId && document.getElementById( entry.lastSelectedFieldId );

		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		var i18n = entry.location.i18n || {};
		var text = 'string' === typeof i18n.notPersisted ? i18n.notPersisted : '';

		if ( '' === text ) {
			return;
		}

		var notice = document.createElement( 'p' );

		notice.className = 'woodev-location-notice';
		notice.setAttribute( 'role', 'alert' );
		notice.textContent = text;

		anchor.parentNode.insertBefore( notice, anchor.nextSibling );

		entry.notPersistedNotice = notice;
	}

	/**
	 * Removes the notice {@see showNotPersistedNotice} inserted for `entry`, if any — a safe
	 * no-op otherwise (nothing shown, or it was already removed).
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function clearNotPersistedNotice( entry ) {
		var notice = entry.notPersistedNotice;

		if ( notice && notice.parentNode ) {
			notice.parentNode.removeChild( notice );
		}

		entry.notPersistedNotice = null;
	}

	/**
	 * Builds the `onSelect(item)` callback handed to the Task 10 widget for one chain node.
	 *
	 * ONLY POSTS `/select` FOR THE CURRENTLY ACTIVE SECTION (review finding F3): the Location
	 * Provider layer stores exactly ONE customer record server-side —
	 * {@see \Woodev\Framework\Shipping\Location\Location_Service} has no notion of "billing's
	 * locality" vs "shipping's locality", only "the customer's current locality" — so a pick
	 * made in the section that is NOT presently the delivery address (e.g. a billing
	 * correction made while "ship to a different address" is checked, so shipping is the
	 * live delivery target) must never overwrite it: doing so is exactly what let a bulk
	 * points query and the map's own live DOM-read centering ({@see resolveLocality} in
	 * `pickup-mount.js`) end up describing two different cities. The LOCAL state (the field's
	 * own record, backwards fill) is still updated regardless of section — an inactive
	 * section's address is still worth remembering client-side for if it becomes active
	 * later — only the SERVER round trip (and, with it, the `woodev_location_applied` event
	 * and `update_checkout` trigger) is gated on {@see isActiveAddressSection}.
	 *
	 * ONLY ENTERS THE SERVER-SIDE CHAIN WHEN {@see mayEnterChain} SAYS SO (issue #352, Variant
	 * A): a record DEEPER than `settlement` whose own provider is not the one that owns the
	 * `settlement` level never reaches `/select` at all — posting it would let the server's
	 * `is_within()` ancestor-kinship check (issue #334, unchanged) amputate the settlement record
	 * a DIFFERENT provider persisted, and that record is the one thing
	 * `Provider_Selection_Scope::current_locality()` reads. See {@see mayEnterChain}'s own
	 * docblock for why the rule stops at `settlement` and must not be broadened to every
	 * shallower level. The LOCAL state (`entry.records[node.level]`,
	 * {@see backwardsFill}, {@see refreshAddressLock}) still updates regardless — only the round
	 * trip is refused. {@see triggerCheckoutUpdate} still fires for a refused pick (see that
	 * function's own docblock for why WooCommerce needs the nudge even with nothing persisted),
	 * but neither `/select` nor `woodev_location_applied` do — see {@see mayEnterChain}'s own
	 * docblock for what a refusal deliberately leaves alone.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string, section?: string}} node
	 * @returns {function(Object): void}
	 */
	function onSelectFor( entry, node ) {
		return function( item ) {
			var record = item && item.record ? item.record : null;

			if ( ! record ) {
				return;
			}

			entry.records[ node.level ] = record;
			// Task 13 / #295 finding 1: remembers WHICH field to anchor a future "not saved"
			// notice to — see showNotPersistedNotice()'s own docblock for why this is an
			// entry-level, not node-level, concept.
			entry.lastSelectedFieldId = node.fieldId;
			// Issue #350: a real pick proves the CURRENT text is no longer "known unresolved" —
			// see {@see onAbandonFor}'s own docblock for what this flag means and why it must not
			// survive past the pick that disproves it.
			entry.unresolved[ node.level ] = null;
			// Issue #350 follow-up: the SAME native `change` that just ran ahead of this callback
			// already had {@see clearDescendants} snapshot whatever it wiped below this level, in
			// case an abandon needed to restore it. A real pick is not an abandon — the address
			// staying cleared for a genuinely NEW settlement is correct, ordinary behaviour — so
			// that snapshot is discarded here, never restored. See {@see restoreClearedDescendants}'s
			// own docblock for why this mirrors that function's own discard on the abandon path.
			entry.clearedByEdit[ node.level ] = null;

			backwardsFill( entry, node, record );

			// Issue #337: a settlement pick unlocks the address field on the SPOT, off the
			// optimistic record above — never only once `/select` comes back. Waiting for the
			// round trip would leave the customer looking at a field that stays blocked for as
			// long as the network takes, right after doing the one thing that unblocks it.
			refreshAddressLock( entry );

			if ( isActiveAddressSection( node.section ) ) {
				if ( mayEnterChain( entry, node, record ) ) {
					enqueueSelect( entry, record );
				} else {
					// Issue #352: a foreign-provider record never reaches /select, so
					// nothing downstream of it (update_checkout, the "not saved" notice)
					// fires on ITS behalf either — but backwardsFill()'s silent writes above
					// may still have changed the address WooCommerce needs to re-price, and
					// nothing else will ever ask it to. See triggerCheckoutUpdate()'s own
					// docblock.
					triggerCheckoutUpdate();
				}
			}
		};
	}

	/**
	 * Builds the `onAbandon({ query, resolved })` callback handed to the Task 10 widget for one
	 * chain node (issue #350) — the widget's own file docblock explains WHEN this runs: a blur
	 * that leaves the query resolved to exactly zero suggestions (`resolved: true`), after
	 * flushing/chaining onto whatever fetch was pending or in flight, OR a blur whose text never
	 * reached `minChars` at all (`resolved: false`) — the widget never even ASKED the provider
	 * about it, so there is nothing "completed" to report, but the customer is left in the exact
	 * same dead end either way (17.08.2026 follow-up: a settlement name genuinely shorter than
	 * `minChars` used to have no exit from #337's lock at all, because it could never produce a
	 * completed zero-result search to begin with).
	 *
	 * THE BUG THIS CLOSES: `handleFieldChanged()` already drops `entry.records[level]` for a
	 * typed-but-never-picked edit (see that function's own docblock) — for the settlement level
	 * that alone re-locks the address field via issue #337's rule, and #337 has no exit for a
	 * town the provider genuinely does not carry: such a town will never produce a suggestion to
	 * click, so the lock would stay on forever and the order could never be completed (operator
	 * decision, 17.08.2026). This callback records the ONE fact {@see isAddressLocked}'s own
	 * amendment needs to recognise that dead end and stand down: "the provider has nothing to
	 * offer for this EXACT text" — whether that was proven by a completed search or by the text
	 * never being eligible for one.
	 *
	 * `detail.resolved` IS DELIBERATELY READ NO FURTHER THAN THIS DOCBLOCK — informational only,
	 * on purpose, not an oversight: this callback stores the SAME marker (`entry.unresolved[level]`)
	 * for BOTH `true` and `false`, and {@see isAddressLocked}'s own amendment reads that marker
	 * without ever asking which one produced it. That is the right call, not a shortcut, because
	 * the amendment's own question — "can this exact typed text ever produce a suggestion to
	 * click?" — has the same answer, no, in both cases: `resolved: true` means the provider was
	 * asked and had nothing; `resolved: false` means the widget never even got to ask, but a
	 * customer cannot click a suggestion that was never requested either. Distinguishing the two
	 * here would gate the SAME lock on HOW the dead end was discovered rather than on whether one
	 * exists — a distinction with no customer-visible difference to make. A future caller that
	 * genuinely needs the distinction (e.g. different messaging for "try a longer name" vs "we
	 * don't carry this") can still read `detail.resolved` itself; nothing here prevents that.
	 *
	 * NEVER CLEARS A FIELD VALUE ITSELF, AND RESTORES WHAT ANOTHER FUNCTION ALREADY DID (operator's
	 * own point 3: "a customer left without city, region and address cannot place an order",
	 * sharpened 17.08.2026 into "keeps the TEXT, only the IDENTITY is gone"). `handleFieldChanged()`'s
	 * `clearDescendants()` call already did whatever record/descendant-value clearing a typed edit
	 * ever does — this function runs strictly AFTER that (blur fires after the native `change` a
	 * text edit already triggered) and never repeats or reaches around it. What it adds is
	 * {@see restoreClearedDescendants}, which puts back ONLY the TEXT that same clear wiped for
	 * THIS level, never the record ({@see clearDescendants} already, correctly, nulled it, and
	 * this never un-nulls it) — see that function's own docblock for the full contract, including
	 * why a field the customer has since typed into themselves is never overwritten. The region
	 * field is a PARENT of settlement and nothing in this path — old or new — ever touches it.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string, section?: string}} node
	 * @returns {function({query: string, resolved: boolean}): void}
	 */
	function onAbandonFor( entry, node ) {
		return function( detail ) {
			entry.unresolved[ node.level ] = detail && 'string' === typeof detail.query ? detail.query : '';

			// Issue #350 follow-up (operator decision 17.08.2026): the customer keeps their
			// DOWNSTREAM TEXT — only the identity clearDescendants() already dropped for this
			// level stays dropped. See {@see restoreClearedDescendants}'s own docblock for the
			// full contract (text-only, never a field already holding the customer's OWN newer
			// text, snapshot consumed either way).
			restoreClearedDescendants( entry, node.level );

			// Same rule as a direct pick (issue #337): every state transition that can change
			// the lock's own answer re-applies it on the spot, never only on some later event.
			refreshAddressLocks();
		};
	}

	// -------------------------------------------------------------------------
	// Attach / detach (per-NODE country + section arbitration, D15 unsupported-level gate)
	// -------------------------------------------------------------------------

	/**
	 * Whether `country` is covered by the layer for THIS entry.
	 *
	 * @param {Object} entry
	 * @param {string} country
	 * @returns {boolean}
	 */
	function isCountrySupported( entry, country ) {
		return Array.isArray( entry.location.countries ) && entry.location.countries.indexOf( country ) !== -1;
	}

	/**
	 * Whether a level is served for a given country.
	 *
	 * `config.location.levels` is keyed BY COUNTRY (`{ RU: { region: true, … }, AM: { … } }`),
	 * not by level, because DaData's coverage is genuinely per country: it serves street data
	 * for RU/BY/KZ/UZ and city-only data everywhere else. A flat per-level map would have to
	 * lie in one direction or the other, and the lie is expensive — an address field that
	 * looks alive and always answers nothing.
	 *
	 * @param {Object} entry
	 * @param {string} country ISO-3166 alpha-2.
	 * @param {string} level
	 * @returns {boolean}
	 */
	function isLevelServed( entry, country, level ) {
		var byCountry = entry.location.levels;

		if ( ! byCountry || ! country || ! byCountry[ country ] ) {
			return false;
		}

		return !! byCountry[ country ][ level ];
	}

	/**
	 * The id of the provider that owns `level` for `country` (issue #352, Variant A) — `''`
	 * (never throws, never `undefined`) when unknown/unserved OR when `entry.location.owners`
	 * is absent entirely (an older cached config, a plugin/test harness that builds the location
	 * block itself without this key, or a config predating this fix) — an ABSENT map degrades to
	 * EXACTLY today's behaviour, never a hard failure: every caller below treats `''` as "nobody
	 * to conflict with", which is precisely the single-provider-chain answer this module already
	 * gave before `owners` existed.
	 *
	 * Mirrors {@see isLevelServed}'s own shape exactly (same `byCountry`/`country`/`level`
	 * lookup), reading `entry.location.owners` — the sibling map
	 * `class-checkout-config.php::build_location_block()` populates from
	 * `Location_Service::get_level_owners_for_country()`, `owners[c][l] === ''` EXACTLY when
	 * `levels[c][l] === false` for the same country/level (that method's own docblock).
	 *
	 * @param {Object} entry
	 * @param {string} country ISO-3166 alpha-2.
	 * @param {string} level
	 * @returns {string}
	 */
	function levelOwner( entry, country, level ) {
		var byCountry = entry.location && entry.location.owners;

		if ( ! byCountry || ! country || ! byCountry[ country ] ) {
			return '';
		}

		var owner = byCountry[ country ][ level ];

		return 'string' === typeof owner ? owner : '';
	}

	/**
	 * Whether `record` may enter the SERVER-SIDE location chain via `/select` (issue #352,
	 * operator decision "Variant A") — the fix for the mixed-provider-chain bug: a store can run
	 * an active provider (e.g. a CDEK-backed one) for `region`/`settlement` and the bundled
	 * DaData fallback for `address` alone (the exact rig configuration the bug was found on).
	 * `Customer_Location_Store::rebuild_chain()` keeps a shallower STORED record only when the
	 * new one can PROVE kinship with it (`Location_Record::is_within()`, which requires every
	 * ancestor to share the SAME provider — issue #334, deliberately kept UNCHANGED by this fix:
	 * a Moscow settlement must not survive a Saint-Petersburg address, and that rule cannot tell
	 * "different provider" apart from "different place"). A DaData address record posted on top
	 * of a CDEK settlement can therefore never prove kinship — posting it anyway silently
	 * amputates the settlement (and, with it, the pickup layer's own locality key) the instant
	 * the server applies that rule. See `class-checkout-config.php::build_location_block()`'s own
	 * `owners` docblock for the server-side half of this reasoning.
	 *
	 * THE RULE IS NARROWER THAN "owns every served level down to `node.level`" — IT ONLY LOOKS
	 * AT `settlement` (operator decision, #352 rescope, post-review). An EARLIER version of this
	 * function refused a record unless its provider owned every served level from the shallowest
	 * one down to and including `node.level`. That was an over-generalization of the operator's
	 * actual words — «чужая АДРЕСНАЯ запись в цепочку не попадает вовсе — адрес от провайдера,
	 * который не владеет уровнем НП, живёт только как ТЕКСТ полей» — and it caused a REGRESSION
	 * an adversarial review caught: a store whose active provider serves only `region`, with the
	 * bundled DaData fallback serving `settlement`/`address`, refused the customer's OWN
	 * settlement pick (foreign `region` owner) exactly as it refused a genuinely foreign one. The
	 * settlement record then never posted, {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope::current_locality()}
	 * (`class-provider-selection-scope.php`) kept answering `''` because it reads the SETTLEMENT
	 * record STRICTLY and refuses when there is none, and the customer's pickup point could never
	 * be filed or restored — strictly WORSE than the unfixed #352 bug, which at least posted the
	 * settlement and let `rebuild_chain()` drop only the unprovable region.
	 *
	 * `settlement` is the anchor for exactly that reason: it is the ONE level a live consumer
	 * reads strictly and breaks without. `record` may enter the chain when EITHER (a) its own
	 * `node.level` sits at `settlement` or shallower (`region`/`settlement` never conflict with a
	 * shallower foreign owner — there is nothing shallower than `region` to protect, and a
	 * `settlement` pick is itself the thing being protected), OR (b) `settlement`'s KNOWN owner
	 * ({@see levelOwner} — `''` means unserved, or an absent `owners` map; either way, nobody to
	 * conflict with) equals `record.provider_id`. Do NOT re-broaden this to every served level —
	 * that is the exact change that regressed.
	 *
	 * WHAT THIS DOES NOT GATE (see {@see onSelectFor}'s own call site): `entry.records[node.level]`
	 * (the LOCAL record the address widget/map read) and {@see refreshAddressLock} both still run
	 * regardless of this predicate's answer, and {@see backwardsFill} makes its OWN,
	 * per-ancestor-level ownership decision — only the `/select` POST into the server chain is
	 * gated here.
	 *
	 * @param {Object} entry
	 * @param {{level: string, section?: string}} node
	 * @param {Object} record
	 * @returns {boolean}
	 */
	function mayEnterChain( entry, node, record ) {
		var idx = LEVELS.indexOf( node.level );
		var settlementIdx = LEVELS.indexOf( 'settlement' );

		if ( idx <= settlementIdx ) {
			return true;
		}

		var country = countryForRecord( entry, node, record );
		var owner = levelOwner( entry, country, 'settlement' );

		return ! owner || owner === record.provider_id;
	}

	/**
	 * Attaches a typeahead widget to one chain node, UNLESS its level is unsupported for that
	 * node's own country per `config.location.levels` (D15) — an unsupported level stays a
	 * plain native input; it still fully participates in the clearing gate below, just with no
	 * widget of its own. Callers are responsible for the country/section gate
	 * ({@see isNodeActive}); this function additionally applies the per-country D15 gate,
	 * which is why it resolves the node's country itself rather than trusting the caller.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string, section?: string}} node
	 * @returns {void}
	 */
	function attachOne( entry, node ) {
		var el = document.getElementById( node.fieldId );

		if ( ! el ) {
			return;
		}

		var i18n = entry.location.i18n || {};

		// The ADDRESS level says something different when it finds nothing (operator, s70):
		// "nothing found" under a street field reads as a delivery refusal, and a street the
		// provider simply does not carry is the ordinary case at that level rather than a
		// failure. Falls back to the generic string when the server did not supply one.
		var emptyKey = 'address' === node.level && 'string' === typeof i18n.noResultsAddress
			? i18n.noResultsAddress
			: i18n.noResults;

		// Handed to WHICHEVER renderer attaches — a Task 13 mode-specific one or the baseline
		// typeahead below get the EXACT SAME `fetch`/`onSelect` (spec: "assert the shared code
		// path, not a copy" — a related-list/ajax-select2 pick persists through the identical
		// `onSelectFor()` → backwardsFill()/enqueueSelect() route every other level already
		// uses), plus this module's own generic request/scoping primitives, so a renderer never
		// reimplements suggest/list URL building, JSON fetching, or live country/parent scoping
		// — it only decides HOW to present them. Still fully mode-ignorant on THIS side: none of
		// these primitives know or care which renderer, if any, ends up using them.
		var options = {
			fetch: fetchFor( entry, node ),
			// Issue #463: the `/location/list` analog of `fetch` above — same live scope, same
			// `fieldValueFor()` value stamping — for a Task 13 `related-list` renderer that needs
			// the FULL scoped list rather than a per-keystroke suggest query.
			list: listFor( entry, node ),
			onSelect: onSelectFor( entry, node ),
			// Issue #350: OPTIONAL for the widget (a mode-specific Task 13 renderer is free to
			// ignore it, same as every other primitive here) — see {@see onAbandonFor}'s own
			// docblock.
			onAbandon: onAbandonFor( entry, node ),
			// Server-supplied (translated, filterable via `woodev_location_i18n`) — never a
			// literal here: this string reaches the customer, so it follows the same route
			// every other user-facing string in this layer takes.
			emptyText: 'string' === typeof emptyKey ? emptyKey : '',
			// Issue #405: a DIFFERENT server-supplied string from `emptyText` above — "the
			// source could not answer" is not "searched, found nothing", and conflating the
			// two at checkout is exactly the bug #405 closes. Same server-supplied/filterable
			// route as `emptyText`; see `location-typeahead.js`'s own `errorText` docblock for
			// when this actually renders (a rejected/thrown `fetch()`, never a resolved-empty
			// one).
			errorText: 'string' === typeof i18n.unavailable ? i18n.unavailable : '',
			node: node,
			location: entry.location,
			country: function() {
				return countryFor( entry, node );
			},
			parentKey: function() {
				return scopeKeyFor( entry, node.level );
			},
			buildUrl: buildUrl,
			fetchJson: fetchJson,
			nonceHeader: function() {
				return nonceHeader( entry );
			},
		};

		var renderer = resolveModeRenderer( entry, node );

		if ( renderer ) {
			var api = renderer( el, options );

			if ( api ) {
				// A DOM-replacing renderer (Task 13's select2-backed ones swap the plain
				// `<input>` for a real `<select>` — select2 cannot enhance anything else)
				// reports the LIVE element back via `api.el`; a renderer that never touches
				// the DOM shape (the related-list region watcher) simply omits it and `el`
				// (captured above, before the renderer ran) is still correct. Storing the
				// wrong one here would make reconcileAfterCheckoutUpdate()'s own "was this
				// node replaced by a checkout re-render" check misfire on every single pass.
				entry.widgets[ node.fieldId ] = { el: api.el || el, api: api };

				return;
			}
			// A renderer declining (returns a falsy value — e.g. `related-list`'s region
			// watcher finding this country's field is a plain `<input>`, not the `<select>`
			// its own mode should have produced) falls through to the baseline below, exactly
			// like a mode with nothing registered for this node at all.
		}

		// Baseline: text+typeahead, gated by the D15 per-country/level chain (spec D7: "text+
		// typeahead always" is the FLOOR, never conditional on a special renderer existing).
		if ( ! isLevelServed( entry, countryFor( entry, node ), node.level ) || 'function' !== typeof window.WoodevLocationTypeahead ) {
			return;
		}

		entry.widgets[ node.fieldId ] = { el: el, api: window.WoodevLocationTypeahead( el, options ) };
	}

	/**
	 * Detaches the widget currently attached to `fieldId`, if any — a safe no-op when nothing
	 * is attached there. Extracted so both a full teardown ({@see detachAll}) and a per-node
	 * reconcile ({@see applyCountryArbitration}, {@see reconcileAfterCheckoutUpdate}) share the
	 * exact same try/catch + registry-cleanup shape.
	 *
	 * @param {Object} entry
	 * @param {string} fieldId
	 * @returns {void}
	 */
	function detachOne( entry, fieldId ) {
		var widget = entry.widgets[ fieldId ];

		if ( ! widget ) {
			return;
		}

		try {
			widget.api.detach();
		} catch ( e ) {
			logError( e );
		}

		delete entry.widgets[ fieldId ];
	}

	/**
	 * Attaches every chain node whose {@see isNodeActive} check currently passes — used only
	 * at boot, where nothing is attached yet so there is nothing to reconcile away from.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function attachAll( entry ) {
		entry.chain.forEach( function( node ) {
			if ( isNodeActive( entry, node ) ) {
				attachOne( entry, node );
			}
		} );
	}

	function detachAll( entry ) {
		Object.keys( entry.widgets ).forEach( function( fieldId ) {
			detachOne( entry, fieldId );
		} );
	}

	/**
	 * Re-runs arbitration for one entry, PER NODE (Finding 1 amendment — previously this was
	 * one blanket detach-everything/reattach-everything pass keyed off a single guessed-at
	 * "the" country, which is exactly why a shipping-section field used to be arbitrated
	 * against `#billing_country`). Each chain node is now gated independently by
	 * {@see isNodeActive}: a node whose desired attached-state already matches its actual one
	 * is left completely untouched — attaching a node that should stay attached, or detaching
	 * one that should stay detached, is a no-op — so e.g. a billing-country change never tears
	 * down an unrelated, still-valid shipping-section widget's in-progress typeahead session.
	 * Never touches field values — "switch back → re-attached with state intact" (Task 11
	 * spec) still holds, now per node.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function applyCountryArbitration( entry ) {
		entry.chain.forEach( function( node ) {
			var active = isNodeActive( entry, node );
			var attached = !! entry.widgets[ node.fieldId ];

			if ( attached && ! active ) {
				detachOne( entry, node.fieldId );
			} else if ( ! attached && active ) {
				attachOne( entry, node );
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Address lock (issue #337)
	// -------------------------------------------------------------------------

	/**
	 * Whether the address field must currently be LOCKED — the customer cannot type into it
	 * until they have actually PICKED a settlement (issue #337, operator decision 16.08.2026).
	 *
	 * WHY A LOCK, AND NOT A DERIVED SETTLEMENT: the pickup layer keys the customer's chosen
	 * point off the SETTLEMENT record ({@see \Woodev\Framework\Shipping\Pickup\
	 * Provider_Selection_Scope::current_locality()}), so an address picked with no settlement
	 * ever chosen produces a chain with no settlement record at all — the pickup choice is then
	 * never written and does not survive a reload (measured on the rig, issue #337). Deriving
	 * the settlement back out of the address record was considered and REJECTED: an address row
	 * carries TWO candidate ancestors (`city_fias_id` / `settlement_fias_id`) and only the
	 * customer knows which one is theirs (gotcha
	 * `a-derived-ancestor-is-not-the-one-the-customer-picked`; #339 measured the same thing from
	 * the other end). The lock removes the state BY CONSTRUCTION instead of guessing a way out
	 * of it afterwards.
	 *
	 * BOTH CONDITIONS MUST HOLD — in every other case the address field stays an ORDINARY input
	 * and is never locked (the operator's own narrowing of the rule):
	 *
	 * 1. SETTLEMENT AND ADDRESS ARE LINKED — both levels are present in THIS entry's own chain.
	 *    This is the level-driven equivalent of a §8 `depends_on`: a location-kind field carries
	 *    no `depends_on` at all (see the file docblock's CHAIN ASSEMBLY section), and the chain
	 *    is exactly what {@see scopeKeyFor} already reads to decide whether the address level is
	 *    scoped BY the settlement one — so "linked" and "scoped by" are one and the same fact
	 *    here. An entry with no settlement field has nothing to wait for, and locking it would
	 *    be a dead end rather than a gate.
	 * 2. THE PROVIDER SERVES `address` FOR THIS NODE'S OWN COUNTRY — read PER LEVEL out of
	 *    `config.location.levels[country]` (D15), never from the fact that a provider is active
	 *    at all: a provider with no address suggestions leaves the customer free-typing a
	 *    street, and no settlement is needed for that. {@see isNodeActive} is consulted rather
	 *    than {@see isLevelServed} directly, because it carries that per-level check TOGETHER
	 *    with the country/section gates the very same field is attached under — so the lock and
	 *    the widget can never disagree about which country's coverage applies, or lock a
	 *    shipping-section field the customer has not even opted into.
	 *
	 * The lock is then ON exactly while no settlement record is confirmed — {@see scopeKeyFor},
	 * the SAME answer that decides whether an address `/suggest` may carry a `within`. A field
	 * locked here is precisely a field whose suggestions would otherwise search country-wide.
	 *
	 * ISSUE #350 AMENDMENT (operator decision, 17.08.2026) — ONE EXCEPTION, NARROWER THAN IT
	 * LOOKS: the rule above assumes a customer who has not picked a settlement yet still CAN —
	 * eventually typing enough of a real locality's name produces a suggestion to click. That
	 * assumption fails for a town the active provider does not carry at all: no suggestion for it
	 * will ever exist, so the lock this function returns would never lift and the order could
	 * never be completed — a customer typing a real address in a real, unlisted village is not a
	 * mistake to be blocked, it is the ordinary case the lock was never meant to catch. So: when
	 * the settlement field's CURRENT text exactly matches `entry.unresolved.settlement` — a
	 * COMPLETED search ({@see onAbandonFor}) already proved the provider has nothing for this
	 * exact string — the address field stays unlocked despite no settlement record existing.
	 * `entry.unresolved.settlement` is cleared on the two events that can PROVE it stale — a real
	 * pick ({@see onSelectFor}), or {@see handleFieldChanged} observing the field's own text
	 * actually change — never "the instant" the text stops matching in some more general sense: a
	 * purely PROGRAMMATIC value change that dispatches no event of its own (e.g.
	 * {@see writeSilently}, used for backwards fill and the pickup-address-replacing
	 * announcement) touches neither path, and could in principle leave the marker stale. What
	 * actually keeps that safe is the comparison itself being read off the LIVE DOM element rather
	 * than a captured value (same reason {@see refreshAddressLock} always re-reads too): a stale
	 * marker only ever matters if the live text still equals it, and a silent write that changes
	 * the text without dispatching an event makes them differ on the spot —
	 * {@see settlementTextIsKnownUnresolved} already stops matching before the marker is ever
	 * cleared.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string, section?: string}} node The address chain node.
	 * @returns {boolean}
	 */
	function isAddressLocked( entry, node ) {
		if ( ! chainNodeForLevel( entry, 'settlement' ) || ! isNodeActive( entry, node ) ) {
			return false;
		}

		if ( null === scopeKeyFor( entry, 'address' ) ) {
			return ! settlementTextIsKnownUnresolved( entry );
		}

		return false;
	}

	/**
	 * Whether the settlement field's CURRENT live text is exactly the string a completed search
	 * already proved the provider has nothing for (issue #350) — {@see isAddressLocked}'s own
	 * amendment, its only caller. `''` never counts, even if `entry.unresolved.settlement` were
	 * somehow also `''` — an empty field is simply "nothing typed yet", not a proven dead end.
	 *
	 * @param {Object} entry
	 * @returns {boolean}
	 */
	function settlementTextIsKnownUnresolved( entry ) {
		var node = chainNodeForLevel( entry, 'settlement' );
		var el = node ? document.getElementById( node.fieldId ) : null;
		var text = el ? el.value : '';

		return !! text && text === entry.unresolved.settlement;
	}

	/**
	 * Applies {@see isAddressLocked} to whichever element currently carries the address field —
	 * read from the live document every time, never a captured node, since WooCommerce replaces
	 * the address fragment wholesale on `updated_checkout` ({@see reconcileAfterCheckoutUpdate}).
	 *
	 * NOTHING EXPLAINS THIS PARTICULAR LOCK. No `title`, no `aria-*` description, no message
	 * beside the field.
	 *
	 * THIS IS AN EXCEPTION, NOT A PROJECT RULE — do not cite it as one (operator, 18.08.2026:
	 * «это не строгое правило проекта. Частный случай для конкретного кейса… Я бы вообще назвал
	 * это исключением. Там где подсказки нужны, мы их показываем»). The default is to EXPLAIN a
	 * blocked control. Silence is right here for one specific reason: the customer sees the
	 * causality with their own eyes — the field unlocks the instant they pick a settlement, so
	 * the trigger and its effect are one visible action apart, exactly like the pickup-type
	 * filter checkboxes that produced the original remark (#243). Where that causality is NOT
	 * visible the same operator has demanded text and rejected silence: an empty suggestion list
	 * got «Поиск не дал результатов…» precisely because a spinner followed by nothing is
	 * indistinguishable from a breakage. An earlier version of this docblock generalised the
	 * remark into a standing rule and that reading was wrong twice over.
	 *
	 * But blocked must still LOOK blocked: `disabled` on its own is not a visual
	 * signal — measured on the rig, the theme's own `input` rule overrides the browser's default
	 * greying completely, leaving a field that looks exactly like its editable neighbour and just
	 * refuses to type, which reads as broken rather than as blocked. Hence the {@see LOCKED_CLASS}
	 * marker and the ONE rule `location.css` carries for it — deliberately the single exception to
	 * that file's own "never style the checkout input" discipline, because a locked field is the
	 * one state where the input must NOT look like every other one.
	 *
	 * CLIENT-SIDE ONLY, LIKE EVERY OTHER GATE IN THIS LAYER (`refreshGate()`'s own rule in
	 * `checkout-field-classic.js`): the server stays the authority. Rendering the attribute
	 * server-side would additionally make a JS failure permanent — an address field nothing
	 * could ever unlock.
	 *
	 * A disabled input is not serialized into WooCommerce's `update_checkout` POST or the order
	 * submission. That takes nothing away in-session: every path that drops the settlement
	 * record also clears the address VALUE ({@see clearDescendants}, {@see clearCountryScope}),
	 * so a locked field is an empty field. The one state where it is not is a session created
	 * BEFORE this rule existed (an address picked while no settlement ever was — exactly what
	 * #337 is about): there the restored value is greyed out and left behind on submit, and the
	 * recovery is the one the rule asks for anyway — picking the settlement, which clears the
	 * descendants and unlocks the field for a fresh pick. The announced pickup-address write
	 * ({@see handlePickupAddressReplacing}) is not a counter-example either: it only ever
	 * follows a pickup SELECTION, which the pickup layer refuses to persist without a settlement
	 * key at all (gotcha `an-empty-domain-key-is-not-a-key`) — i.e. only ever while unlocked.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function refreshAddressLock( entry ) {
		var node = chainNodeForLevel( entry, 'address' );
		var el = node ? document.getElementById( node.fieldId ) : null;

		if ( ! el ) {
			return;
		}

		var locked = isAddressLocked( entry, node );

		el.disabled = locked;
		// The class is what location.css can see — `disabled` alone is NOT a visual signal:
		// measured on the rig, the theme's own `input` rule (its own background/border/colour)
		// overrides the browser's default greying entirely, leaving a field that looks ordinary
		// and merely refuses to type. That reads as broken, which is a different thing from
		// blocked. See `location.css`'s own rule for this class.
		if ( el.classList ) {
			el.classList.toggle( LOCKED_CLASS, locked );
		}
	}

	/**
	 * {@see refreshAddressLock} for every entry — the shape the global (entry-agnostic) event
	 * handlers use.
	 *
	 * @returns {void}
	 */
	function refreshAddressLocks() {
		entries.forEach( function( entry ) {
			refreshAddressLock( entry );
		} );
	}

	// -------------------------------------------------------------------------
	// WC Address Autocomplete suppression — client half (Task 12, spec D2)
	// -------------------------------------------------------------------------
	//
	// WooCommerce's own Address Autocomplete (`window.wc.addressAutocomplete`, since WC 9.9.0)
	// is a PUBLIC namespace, but NOT a documented contract — there is no `@wordpress/...`
	// package, no versioned API, just a global object a script happens to leave behind. This
	// section touches it anyway, on the measured shape recorded in gotcha
	// `wc-address-autocomplete-hosts-only-address1-and-flattens-identity` (`providers` — an
	// object keyed by provider id, each entry FROZEN via `Object.freeze()` at registration —
	// `serverProviders`, and a live-reading arbitration loop). RE-VERIFY THIS SHAPE ON EVERY WC
	// MAJOR BUMP; nothing here is guaranteed to survive one.
	//
	// Full-country stores never reach this code at all: when EVERY WC selling country is inside
	// our own provider chain, `Checkout_Handler::maybe_suppress_wc_address_providers()` (PHP)
	// filters `woocommerce_address_providers` to `[]` server-side, so WC never even enqueues its
	// autocomplete scripts and `window.wc.addressAutocomplete` never exists. This client-side
	// half exists for the MIXED-country case the server-side full kill must not touch: our own
	// countries must stand down from WC's autocomplete, everyone else's must keep using it.
	//
	// TIMING: a registered provider object is frozen, so its OWN `canSearch` cannot be
	// overwritten — but WC's arbitration loop (`address-autocomplete.js`'s `setActiveProvider()`)
	// reads `window.wc.addressAutocomplete.providers[ id ]` FRESH on every country change, never
	// a value captured once. Replacing the REGISTRY SLOT with a delegating clone is therefore
	// timing-safe: the very next country change picks up the replacement with no event or
	// re-registration needed on our side.

	/** @type {boolean} whether the wrap has already been applied this page load. */
	var wcSuppressionApplied = false;

	/**
	 * The union, across every entry, of the countries OUR layer covers — the set WC's own
	 * autocomplete must stand down for.
	 *
	 * @returns {string[]}
	 */
	function ourCountries() {
		var seen = {};

		entries.forEach( function( entry ) {
			var countries = Array.isArray( entry.location.countries ) ? entry.location.countries : [];

			countries.forEach( function( country ) {
				seen[ country ] = true;
			} );
		} );

		return Object.keys( seen );
	}

	/**
	 * Builds a delegating clone of `provider`: `Object.create( provider )` puts the (frozen)
	 * original on the clone's prototype chain, so every property/method NOT overridden here
	 * (`search`, `select`, `id`, `name`, `branding_html`, ...) still resolves straight through to
	 * it. Only `canSearch` is shadowed as an OWN property of the clone, defined via
	 * `Object.defineProperty()` rather than plain assignment: in strict mode (this file is
	 * `'use strict'`), `clone.canSearch = ...` would THROW — `provider.canSearch` is a
	 * non-writable inherited property (frozen), and the `[[Set]]` semantics for an inherited
	 * non-writable data property reject the assignment even though the OWN object being written
	 * to is not itself frozen. `defineProperty()` bypasses `[[Set]]` entirely and installs a
	 * fresh, independent own property on the clone — legal regardless of what the prototype says.
	 *
	 * @param {Object}   provider
	 * @param {string[]} suppressed Country codes to answer `false` for, unconditionally.
	 * @returns {Object}
	 */
	function wrapProvider( provider, suppressed ) {
		var clone = Object.create( provider );

		Object.defineProperty( clone, 'canSearch', {
			value: function( country ) {
				if ( suppressed.indexOf( country ) !== -1 ) {
					return false;
				}

				return provider.canSearch( country );
			},
			writable: true,
			enumerable: true,
			configurable: true,
		} );

		return clone;
	}

	/**
	 * Applies the suppression wrap once. Fenced: does nothing (and nothing throws) when
	 * `window.wc.addressAutocomplete` is absent — the feature is off, or an older WC — leaving
	 * `wcSuppressionApplied` false so a later call (see {@see handleLayoutRelevantChange}) can retry.
	 * Also does nothing, but marks itself done, when our own config carries no countries at all
	 * (nothing to suppress).
	 *
	 * @returns {void}
	 */
	function suppressWcAddressAutocomplete() {
		if ( wcSuppressionApplied ) {
			return;
		}

		var wc = window.wc;

		if ( ! wc || ! wc.addressAutocomplete || ! wc.addressAutocomplete.providers ) {
			return;
		}

		var suppressed = ourCountries();

		if ( ! suppressed.length ) {
			wcSuppressionApplied = true;
			return;
		}

		var registry = wc.addressAutocomplete.providers;

		Object.keys( registry ).forEach( function( id ) {
			registry[ id ] = wrapProvider( registry[ id ], suppressed );
		} );

		wcSuppressionApplied = true;
	}

	// -------------------------------------------------------------------------
	// Dependent clearing (downward only, remembered-parent gate)
	// -------------------------------------------------------------------------

	/**
	 * Finds `fieldId`'s position + level within `entry.allNodes`, or `null` when it is not one
	 * of this entry's own chain/postcode nodes.
	 *
	 * @param {Object} entry
	 * @param {string} fieldId
	 * @returns {{index: number, level: string|null}|null}
	 */
	function nodeInfo( entry, fieldId ) {
		for ( var i = 0; i < entry.allNodes.length; i++ ) {
			if ( entry.allNodes[ i ].fieldId === fieldId ) {
				return { index: i, level: entry.allNodes[ i ].level };
			}
		}

		return null;
	}

	/**
	 * Clears every node STRICTLY AFTER `fromIndex` — DOM value, store value, the remembered-
	 * value gate, and (for a chain level) its own confirmed record. Never dispatches events
	 * (mirrors `checkout-field-classic.js`'s own `cascadeChild()` — a destructive clear must
	 * not itself cascade further).
	 *
	 * THIS ALWAYS RUNS — issue #350 follow-up (operator decision 17.08.2026) does NOT weaken this
	 * function or its call site: it runs synchronously off the native `change` a text edit fires,
	 * long before any provider answer exists, and it must keep unconditionally clearing on the
	 * ORDINARY path — adopting a different settlement has to wipe the old address, or the customer
	 * ends up with a Tverskaya street address filed under a village they just typed over it. What
	 * changes instead is a SEPARATE snapshot-and-restore step downstream of here
	 * ({@see restoreClearedDescendants}, called only from {@see onAbandonFor}): when `fromIndex`
	 * names a CHAIN level (one that can itself carry a widget an abandon can fire from —
	 * `entry.allNodes[fromIndex].level` is non-null; a postcode-only edit has no such level and no
	 * `onAbandon` to ever ask for a restore), the pre-clear value of every node this call is about
	 * to blank is captured into `entry.clearedByEdit[editedLevel]` FIRST, so a later abandon that
	 * proves the edit unresolvable can put the customer's downstream TEXT back — never the record
	 * this function correctly nulled two lines below, which stays gone regardless (the identity
	 * belonged to the settlement the customer just abandoned, not to whatever settlement comes
	 * next). Overwrites whatever this same level's OWN previous snapshot held — see
	 * {@see restoreClearedDescendants}'s own docblock for why an overwrite, never an accumulation,
	 * is the right rule here.
	 *
	 * @param {Object} entry
	 * @param {number} fromIndex
	 * @returns {void}
	 */
	function clearDescendants( entry, fromIndex ) {
		var editedNode = entry.allNodes[ fromIndex ];
		var editedLevel = editedNode ? editedNode.level : null;
		var snapshot = editedLevel ? {} : null;

		for ( var i = fromIndex + 1; i < entry.allNodes.length; i++ ) {
			var node = entry.allNodes[ i ];
			var el = document.getElementById( node.fieldId );

			if ( snapshot ) {
				// Captured BEFORE the clear below, off the live DOM — the text the customer
				// actually saw, not whatever the store happens to mirror.
				snapshot[ node.fieldId ] = el ? el.value : '';
			}

			if ( node.level ) {
				entry.records[ node.level ] = null;
			}

			entry.store.setValue( node.fieldId, '' );
			entry.resolved[ node.fieldId ] = '';

			if ( el ) {
				// Issue #465, symptom B: a bare `.value = ''` on a select2-enhanced descendant
				// clears the field's REAL value but leaves the WIDGET showing whatever it last
				// rendered — {@see applyValueToElement} is the same silent write path
				// {@see writeSilently} already uses for exactly this reason (its own namespaced
				// `change.select2` refresh never trips this module's OWN change-gate).
				applyValueToElement( el, '' );
			}
		}

		if ( editedLevel ) {
			entry.clearedByEdit[ editedLevel ] = snapshot;
		}
	}

	/**
	 * Restores whatever {@see clearDescendants} most recently wiped as a direct result of editing
	 * `level`'s own field — issue #350 follow-up (operator decision 17.08.2026), called only from
	 * {@see onAbandonFor} once a search for that edit's exact text (or a below-`minChars` text too
	 * short to search at all) has proved it unresolvable. TEXT ONLY, never identity:
	 * `entry.records[...]` for those descendant levels was already set `null` by
	 * {@see clearDescendants} and stays that way here — the record belonged to the settlement the
	 * customer just abandoned, and nothing about a downstream field's text coming back re-confirms
	 * it for whatever settlement comes next.
	 *
	 * A FIELD ALREADY HOLDING NEW TEXT IS LEFT ALONE — read live off the DOM, never a captured
	 * value, so a customer who typed their own address WHILE the abandon was still resolving (the
	 * whole point of this flow is that it runs after an async round trip) is never overwritten:
	 * their text always wins over a restore of the OLD one.
	 *
	 * THE SNAPSHOT IS CONSUMED, NOT REUSABLE — cleared to `null` unconditionally before this
	 * function returns, whether or not anything existed to restore. {@see clearDescendants} is the
	 * only writer, and it already overwrites the same key on every subsequent edit at this level,
	 * so an explicit clear here exists only to close the one gap that overwrite cannot: a snapshot
	 * left behind by an edit that a REAL PICK resolved (never reaching this function at all) must
	 * not survive to be replayed against some later, unrelated abandon — {@see onSelectFor} already
	 * discards it for that reason on its own path; this one discards it on the abandon path itself,
	 * so neither leaves a stale snapshot for the other to trip over.
	 *
	 * @param {Object} entry
	 * @param {string} level
	 * @returns {void}
	 */
	function restoreClearedDescendants( entry, level ) {
		var snapshot = entry.clearedByEdit[ level ];

		entry.clearedByEdit[ level ] = null;

		if ( ! snapshot ) {
			return;
		}

		Object.keys( snapshot ).forEach( function( fieldId ) {
			var previousValue = snapshot[ fieldId ];

			if ( ! previousValue ) {
				return; // nothing was actually wiped for this field — nothing to restore.
			}

			var el = document.getElementById( fieldId );

			if ( el && el.value ) {
				return; // the customer already typed something of their own here — theirs wins.
			}

			// Silent, like clearDescendants() itself — a restore must not look like a fresh
			// customer edit and re-trigger this same module's own change-gate.
			writeSilently( entry, fieldId, previousValue );
		} );
	}

	/**
	 * Clears every node GOVERNED BY `countryFieldId` — the country is the ROOT of the chain
	 * (`country → region → settlement → address` + postcode, spec §4.4), so a real country
	 * transition clears all of them, by the same rule any other parent transition already
	 * follows.
	 *
	 * Section-aware on purpose: `#billing_country` governs only billing-section nodes and
	 * `#shipping_country` only shipping-section ones (the same per-node `section` the
	 * scoping and attach paths already key off), so changing the billing country never
	 * empties a shipping address the customer entered separately.
	 *
	 * Why clearing is right and keeping was not: the values left behind name a locality in
	 * the country the customer just left. "Moscow / Moscow / 101000" under Uzbekistan is not
	 * a stale nicety — it is an address that cannot exist, it is what the shipping
	 * calculation sees, and the confirmed records still scope the next `/suggest` by a region
	 * of the OLD country. Observed by the operator on the rig (s70): switching RU → UZ left
	 * every field filled with the Moscow values.
	 *
	 * ALSO INVALIDATES THE LOCATION PROVIDER KEY (review finding F2, rig-verified) when the
	 * cleared section is the one currently determining the customer's delivery address (see
	 * {@see isActiveAddressSection}): a real country transition abandons whatever locality the
	 * layer's shared customer record still names, but this function POSTS NOTHING to `/select`
	 * (see the file docblock's own note on that), so nothing else would ever tell
	 * `pickup-mount.js`'s `resolveLocalityKey()` the cached key no longer matches what the DOM
	 * shows. Firing `woodev_location_applied` with no record (`key: ''`) is that signal — F1's
	 * own fix makes an empty key fall back to the DOM read, which after this clear is exactly
	 * the honest, empty answer. A clear in the INACTIVE section is a no-op for this purpose:
	 * that section was never feeding the shared record to begin with (see
	 * {@see isActiveAddressSection}'s own docblock).
	 *
	 * @param {Object} entry
	 * @param {string} countryFieldId
	 * @returns {void}
	 */
	function clearCountryScope( entry, countryFieldId ) {
		var section = sectionForCountryFieldId( countryFieldId );
		var cleared = false;

		entry.allNodes.forEach( function( node ) {
			if ( countryFieldIdFor( node ) !== countryFieldId ) {
				return;
			}

			cleared = true;

			if ( node.level ) {
				entry.records[ node.level ] = null;
			}

			entry.store.setValue( node.fieldId, '' );
			entry.resolved[ node.fieldId ] = '';

			var el = document.getElementById( node.fieldId );

			if ( el ) {
				// Issue #465, symptom B — same reasoning as {@see clearDescendants}'s own fix.
				applyValueToElement( el, '' );
			}
		} );

		if ( cleared && isActiveAddressSection( section ) ) {
			// Nothing is known to be implicit about a cleared record — false, same reasoning
			// as {@see settleSelect}'s own `notPersisted` branch.
			fireLocationApplied( entry, null, false );
		}
	}

	/**
	 * Delegated `change` handler for BOTH event worlds (see the file docblock). Routes a
	 * country-field change (`#billing_country` OR `#shipping_country` — Finding 1: BOTH are
	 * observed now, not just billing) and a "ship to a different address" toggle to
	 * arbitration; otherwise, for every entry owning this field id, gates a destructive
	 * downward clear on a REAL remembered-value transition.
	 *
	 * A country change ALSO clears that country's own section ({@see clearCountryScope}) —
	 * but only on a REAL transition, gated on the remembered previous value exactly like
	 * every other parent. WooCommerce fires programmatic `change` events on the country
	 * field while initialising the checkout, carrying the value the field already has
	 * (gotcha `a-programmatic-parent-change-must-not-run-a-destructive-cascade`); without
	 * the gate this would wipe a restored address on every single page load, which is the
	 * exact failure #272 already cost this project once.
	 *
	 * A CHAIN-LEVEL TEXT EDIT WITHOUT A PICK ALSO INVALIDATES THE LOCATION PROVIDER KEY
	 * (review finding F2, rig-verified) when it happens in the active address section (see
	 * {@see isActiveAddressSection}): the customer typed a new value into a level field and
	 * moved on WITHOUT choosing a suggestion (`onSelectFor()`'s pick path never ran), so the
	 * field's own confirmed record just went `null` a few lines above — the server's last
	 * persisted record no longer matches what the DOM shows, and nothing else here posts
	 * `/select` to correct it. Same `woodev_location_applied` / `fireLocationApplied( null )`
	 * signal {@see clearCountryScope} uses, for the same reason — see that function's own
	 * docblock. Never fired for a postcode-only edit (`info.level` is `null` there; postcode
	 * is not a locality).
	 *
	 * DEFERRED ONE MICROTASK, AND RE-CHECKED, so a GENUINE pick through the widget never
	 * trips this: {@see selectViaFake} in this file's own tests (mirroring
	 * `location-typeahead.js`'s real `selectItem()`) writes the field value and dispatches
	 * this SAME native `change` BEFORE calling `onSelect()` — so THIS handler always runs
	 * first, sees the field's value already changed, and would otherwise read every pick as
	 * a "typed but not picked" edit. `onSelectFor()`'s own callback runs synchronously right
	 * after this handler returns and sets `entry.records[level]` back to the real record —
	 * by the time a queued microtask runs, that has already happened for a genuine pick
	 * (record non-null again) but never for an actually-abandoned edit (record still
	 * `null`), which is exactly the distinction this needs and the DOM alone cannot make.
	 *
	 * @param {Event|Object} event Native `Event` or a jQuery Event — both expose `.target`.
	 * @returns {void}
	 */
	function handleFieldChanged( event ) {
		var target = event && event.target;
		var id = target && target.id ? target.id : '';
		var name = target && target.name ? target.name : '';

		if ( COUNTRY_FIELD_IDS.indexOf( id ) !== -1 ) {
			var section = sectionForCountryFieldId( id );

			// PR #320 review, finding 1: the EFFECTIVE country per entry (live field, else
			// `entry.location.defaultCountry` — see {@see countryFor}'s own docblock), never the
			// raw `target.value` — computed per ENTRY (not once, up front) since two entries can
			// carry different `defaultCountry` values. `target.value` IS already this field's
			// live DOM value by the time a `change` handler runs, so {@see countryFor} reading
			// the DOM here answers the SAME thing `target.value` would for a present, non-empty
			// selection — it only additionally covers the field-absent/unselected case the raw
			// read got wrong.
			entries.forEach( function( entry ) {
				var country = cascadeKey( countryFor( entry, { section: section } ) );

				if ( entry.resolved[ id ] === country ) {
					return; // programmatic churn or a re-selection of the same effective country.
				}

				entry.resolved[ id ] = country;
				clearCountryScope( entry, id );
			} );

			handleLayoutRelevantChange();
			return;
		}

		if ( 'ship_to_different_address' === name ) {
			handleLayoutRelevantChange();
			return;
		}

		if ( ! id ) {
			return;
		}

		entries.forEach( function( entry ) {
			var info = nodeInfo( entry, id );

			if ( ! info ) {
				return;
			}

			var newValue = cascadeKey( target.value );

			if ( entry.resolved[ id ] === newValue ) {
				return; // no real transition — WC-style no-op churn OR a duplicate delivery.
			}

			entry.resolved[ id ] = newValue;
			entry.store.setValue( id, target.value );

			if ( info.level ) {
				entry.records[ info.level ] = null; // the field's own record no longer matches its text.
				// Issue #350: whatever the text was before, this IS a real transition (the
				// `entry.resolved[id] === newValue` guard above already ruled out a no-op) — so
				// the field no longer carries the exact string a completed search once proved
				// unresolved, even if the customer typed right back to nothing in particular.
				// {@see onAbandonFor} re-sets this for the NEW text, if and when its own search
				// completes and again finds nothing.
				entry.unresolved[ info.level ] = null;

				var level = info.level;
				var section = entry.allNodes[ info.index ].section;

				Promise.resolve().then( function() {
					if ( null === entry.records[ level ] && isActiveAddressSection( section ) ) {
						// Same "nothing known to be implicit about an unknown record" reasoning
						// as {@see clearCountryScope}.
						fireLocationApplied( entry, null, false );
					}
				} );
			}

			clearDescendants( entry, info.index );
		} );

		// Issue #337. Fired for EVERY entry, not only those that matched a node above: a
		// settlement record can be dropped by this handler (a text edit without a pick) and the
		// address field must go back to locked in the same pass, while a field this entry does
		// not own leaves {@see refreshAddressLock} a no-op anyway.
		refreshAddressLocks();
	}

	/**
	 * Re-runs arbitration for every entry — triggered by a change to `#billing_country`,
	 * `#shipping_country`, or the "ship to a different address" checkbox (Finding 1: any of
	 * these three can change which nodes should be active, and {@see applyCountryArbitration}
	 * is itself a per-node no-op wherever nothing actually changed, so re-running it broadly
	 * on any of the three is cheap and never thrashes an unaffected widget).
	 *
	 * @returns {void}
	 */
	function handleLayoutRelevantChange() {
		// Opportunistic retry (see the suppression section's own docblock): a page whose WC
		// autocomplete script happens to execute after this one still gets suppressed the first
		// time the customer touches the country field — no-op once already applied.
		suppressWcAddressAutocomplete();

		entries.forEach( function( entry ) {
			// Issue #458 round 3: re-derive the chain BEFORE arbitrating — a no-op unless the
			// "ship to a different address" toggle actually moved a level's winner (see
			// rebuildChainForActiveSection()'s own docblock for why this must run first).
			rebuildChainForActiveSection( entry );
			applyCountryArbitration( entry );
		} );

		// Issue #337: the address level's own coverage is per COUNTRY (D15) and the section
		// gate moves with the "ship to a different address" toggle, so both of this function's
		// triggers can flip the lock's own preconditions, not just which widgets are attached.
		refreshAddressLocks();
	}

	/** @type {{native: boolean, jquery: boolean}} which event worlds are currently bound. */
	var changeWorldsBound = { native: false, jquery: false };

	/**
	 * Binds the delegated `change` listener in both event worlds — re-tries the jQuery half on
	 * every call so it is picked up whenever jQuery becomes available (gotcha
	 * `jquery-trigger-change-fires-no-native-event`'s own code shape).
	 *
	 * @returns {void}
	 */
	function bindChangeWatchers() {
		if ( ! changeWorldsBound.native ) {
			changeWorldsBound.native = true;
			document.body.addEventListener( 'change', handleFieldChanged );
		}

		if ( changeWorldsBound.jquery || ! window.jQuery ) {
			return;
		}

		var $body = window.jQuery( document.body );

		if ( ! $body || 'function' !== typeof $body.on ) {
			return; // a jQuery double thin enough to lack `.on()` — a legitimate capability check.
		}

		changeWorldsBound.jquery = true;
		$body.on( 'change', handleFieldChanged );
	}

	// -------------------------------------------------------------------------
	// Checkout re-render (`updated_checkout`)
	// -------------------------------------------------------------------------

	/**
	 * Re-verifies every chain node of `entry` is attached to the node CURRENTLY in the
	 * document — WooCommerce can replace the whole address fragment on `updated_checkout`
	 * (see the file docblock), leaving a previously-attached widget's instance pointing at a
	 * detached node. A REPLACED node is detached and re-attached fresh; a node that vanished
	 * entirely is just detached. Also restores a field's value from the store as a SAFETY NET
	 * — only when the LIVE element is empty but the store still holds a value (mirrors
	 * `checkout-field-classic.js`'s own `updated_checkout` handler: "restore is a safety net,
	 * not an overwrite").
	 *
	 * The restore runs BEFORE the re-attach, not after (issue #460): a mode-specific renderer
	 * that SWAPS the node (`ajax-select2`'s `<input>` → `<select>`, same as
	 * `location-select-modes.js`'s own `buildSelectField()`) reads its seed value off the LIVE
	 * element at attach time (issue #447's "capture BEFORE the `<input>` is detached"), so a
	 * value written onto `live` only AFTER `attachOne()` already ran lands on the element the
	 * renderer just replaced — orphaned, invisible, and gone the moment it is garbage
	 * collected. Restoring first means the renderer's OWN seed read sees it, exactly like a
	 * server-rendered value would.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function reconcileAfterCheckoutUpdate( entry ) {
		entry.chain.forEach( function( node ) {
			if ( ! isNodeActive( entry, node ) ) {
				return;
			}

			var live = document.getElementById( node.fieldId );
			var current = entry.widgets[ node.fieldId ];

			if ( ! live ) {
				detachOne( entry, node.fieldId );
				return;
			}

			var stored = entry.store.getValue( node.fieldId );

			if ( ! live.value && undefined !== stored && null !== stored && '' !== stored ) {
				live.value = stored;
			}

			if ( ! current || current.el !== live ) {
				detachOne( entry, node.fieldId );
				attachOne( entry, node );
			}
		} );

		// Issue #337: a re-render hands back a FRESH element, carrying the server's markup and
		// none of the lock this module applied to the node it replaced.
		refreshAddressLock( entry );
	}

	function handleCheckoutUpdated() {
		entries.forEach( reconcileAfterCheckoutUpdate );
	}

	/**
	 * Binds the `updated_checkout` subscriber — jQuery-preferred, native fallback for
	 * testability, mirroring `pickup-mount.js`'s own `onCheckoutUpdated()` exactly:
	 * `updated_checkout` is a jQuery CUSTOM event in production (WC fires it via
	 * `$(document.body).trigger(...)`, which never calls `dispatchEvent()` for a non-native
	 * event type), so only a THROUGH-jQuery binding ever sees a real WooCommerce refresh; the
	 * native fallback exists purely so this file stays testable without a real jQuery build
	 * loaded.
	 *
	 * @returns {void}
	 */
	function bindCheckoutUpdatedWatcher() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', handleCheckoutUpdated );
			return;
		}

		document.body.addEventListener( 'updated_checkout', handleCheckoutUpdated );
	}

	/**
	 * Binds the `country_to_state_changed` subscriber (issue #460) — reuses the EXACT same
	 * `reconcileAfterCheckoutUpdate()` an `updated_checkout` re-render already runs, because the
	 * defect this closes is the SAME shape: WooCommerce's OWN `country-select.js` rebuilds
	 * `#billing_state`/`#shipping_state`/`#calc_shipping_state` on the country field's `change`/
	 * `refresh` — unconditionally `.replaceWith()`-ing whatever currently occupies that id,
	 * including a `<select>` a mode-specific renderer here (`ajax-select2`) built for a country
	 * with NO WooCommerce state list — and fires `country_to_state_changed` right after, on
	 * EVERY branch, whether or not anything this layer owns was touched. `updated_checkout`
	 * never fires as part of this: the rebuild is synchronous client-side DOM churn, not a
	 * server round-trip, so a listener bound only to `updated_checkout` (the prior state of
	 * this file) never runs at all for this rebuild — this module's own widget is simply gone,
	 * empty, with nothing to notice.
	 *
	 * Read `country-select.js` itself (WooCommerce core,
	 * `assets/js/frontend/country-select.js`) before assuming this is a narrow patch: its
	 * "country has no WC state list" branch (the RU case) builds the replacement node from
	 * `$statebox.attr(...)` alone and NEVER reads back the `value` it captured earlier in the
	 * same handler — there is no code path in core that could carry a value across that
	 * specific branch, which is why surviving the rebuild (carrying `data-input-classes` etc.
	 * onto our own `<select>`) cannot work here: the fresh node core builds is unconditionally
	 * empty regardless of what the replaced node carried. Re-seeding from `entry.store` after
	 * the fact — the same store {@see prefill} already populated before this rebuild ever
	 * happened — is the only side of this that can restore the value.
	 *
	 * Harmless for the country field's OWN `<select>`/`<input class="country_to_state">` (this
	 * layer never attaches anything to `billing_country`/`shipping_country`) and for a
	 * WC-managed state field (a country WC DOES carry states for): `isWcManagedField()` /
	 * `isNodeActive()`'s own gating already keeps this layer off those — see
	 * `checkout-field-classic.js`'s own `isWcManagedField()` docblock for why. Re-running the
	 * reconcile for every entry on every `country_to_state_changed` is a no-op for whichever
	 * entry's fields the event did not touch, exactly like `handleCheckoutUpdated()` already is
	 * for an `updated_checkout` that replaced a different section's fragment.
	 *
	 * @returns {void}
	 */
	function bindCountryToStateChangedWatcher() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'country_to_state_changed', handleCheckoutUpdated );
			return;
		}

		document.body.addEventListener( 'country_to_state_changed', handleCheckoutUpdated );
	}

	// -------------------------------------------------------------------------
	// Boot / prefill
	// -------------------------------------------------------------------------

	/**
	 * Adopts a chain object (`{ level: { key, level } }`, spec §7) into `entry.records`,
	 * overwriting whichever LEVELS the chain names — shared by {@see prefill} (the boot-time
	 * seed, issue #330) and {@see sendNextSelect} (a `/select` response's own rebuilt chain), so
	 * a server-side chain repair can never leave the client scoping a suggest call by a parent
	 * key that no longer exists server-side.
	 *
	 * Absent or malformed `chain` (an older server that has not shipped the field yet, or simply
	 * `undefined`/`null`) is a silent no-op — never throws, and leaves whatever `entry.records`
	 * already held untouched, which is what lets both callers degrade to their own pre-chain
	 * behaviour. A per-level entry with no usable string `key` is skipped INDIVIDUALLY rather
	 * than aborting the whole chain or being stored as a broken `{}` record — {@see scopeKeyFor}
	 * already treats a record with no `.key` as absent, but leaving one sitting in
	 * `entry.records[level]` would still make that level LOOK confirmed to a future caller that
	 * only checks presence, not shape.
	 *
	 * @param {Object} entry
	 * @param {*}      chain `{ [level]: { key, level } }` per spec §7, or anything else.
	 * @returns {void}
	 */
	function adoptChain( entry, chain ) {
		if ( ! chain || 'object' !== typeof chain ) {
			return;
		}

		var adopted = {};

		Object.keys( chain ).forEach( function( level ) {
			var node = chain[ level ];

			// Only the cascade's OWN levels, so a rogue/extra key cannot plant a record
			// under a level nothing here will ever read back.
			if ( LEVELS.indexOf( level ) !== -1 && node && 'string' === typeof node.key && node.key ) {
				// `confirmed` marks PROVENANCE, not validity: this record came from the
				// SERVER's own chain, so it is one the server actually holds — unlike the
				// optimistic write `onSelectFor()` makes before any round trip resolves.
				// {@see fireLocationApplied} publishes a settlement key only for a confirmed
				// record, so an optimistic one can never be handed to `pickup-mount.js` as
				// the map's addressing locality (adversarial review).
				adopted[ level ] = { key: node.key, confirmed: true };
			}
		} );

		// NOTHING usable in it — absent field, `[]` from a server that has no chain to
		// report (a guest whose session never initialized, `persisted: false`), or every
		// entry malformed. Leave `entry.records` ALONE: the client's own in-session
		// memory of what the customer picked is the best state anyone has here, and
		// wiping it would break exactly the guest flow issue #324 was about.
		if ( 0 === Object.keys( adopted ).length ) {
			return;
		}

		// A NON-EMPTY chain is AUTHORITATIVE (adversarial review): the server proved it
		// holds state, so a level it does NOT name is a level it dropped — an
		// ancestor-compatibility repair, or a deeper level superseded by a shallower
		// pick. Adopting additively would leave that stale record here and the client
		// would keep sending a `within` the server refuses to resolve, silently falling
		// back to a country-wide search — the very seam this whole change exists to
		// close.
		LEVELS.forEach( function( level ) {
			entry.records[ level ] = adopted[ level ] || null;
		} );
	}

	/**
	 * Seeds `resolved[]` from each node's rendered DOM value (so WooCommerce's own init-time
	 * programmatic `change` — carrying the value already there — is a no-op, not a destructive
	 * clear) and, when `config.location.current` names an existing customer record, seeds a
	 * partial record (`{ key }` only — the config block never carries full components, see
	 * `class-checkout-config.php::build_location_block()`) for that level so a child scope
	 * fetch can use it WITHOUT re-fetching (Task 11 "restore state without re-fetching").
	 *
	 * ALSO FIRES `woodev_location_applied` (issue #309; spec D11/§4.6) — give
	 * `entry.location.implicit` its first real consumer: this is the ONE call site that can
	 * ever fire the event with `implicit: true`, see the file docblock's own section on
	 * `detail.implicit` for why. Fired unconditionally whenever a current record exists,
	 * regardless of which section `entry` belongs to or whether ITS chain even carries a
	 * field for `current.level` (spec §4.4 explicitly permits a record whose level has no
	 * matching field) — unlike the old DOM-attribute attempt, this needs no field to exist at
	 * all. Two entries sharing one customer record (e.g. a billing entry and a shipping entry
	 * both wired to the SAME `Location_Service`) each fire their own, identically-valued
	 * event; a listener maintaining ONE piece of state for "is the customer's current
	 * locality implicit" (never one per entry) sees no divergence, because there IS no
	 * per-entry state any more for it to diverge from.
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function prefill( entry ) {
		entry.allNodes.forEach( function( node ) {
			var el = document.getElementById( node.fieldId );

			if ( el ) {
				entry.store.setValue( node.fieldId, el.value );
				entry.resolved[ node.fieldId ] = cascadeKey( el.value );
			}
		} );

		// Seed the COUNTRY fields' remembered values too — without this, WooCommerce's own
		// programmatic `change` on the country field during checkout init compares against
		// `undefined`, reads as a real transition, and empties a restored address on every
		// page load ({@see handleFieldChanged}, gotcha
		// `a-programmatic-parent-change-must-not-run-a-destructive-cascade`).
		//
		// SEEDED FROM THE EFFECTIVE COUNTRY, NOT THE RAW DOM VALUE (PR #320 review, finding 1):
		// {@see countryFor} — the SAME resolution the widget itself attached under — not
		// `el.value` directly. A field present but unselected (`el.value === ''`) must seed the
		// fallback it is ALREADY effectively scoped by (`RU` or `entry.location.defaultCountry`),
		// not `''` — seeding the raw empty value made the customer's first explicit pick of that
		// very same country read as a transition and destructively clear the address the
		// fallback had already been scoping every suggestion by (see {@see countryFor}'s own
		// docblock for the full reproduction).
		COUNTRY_FIELD_IDS.forEach( function( countryFieldId ) {
			var el = document.getElementById( countryFieldId );

			if ( el ) {
				entry.resolved[ countryFieldId ] = cascadeKey(
					countryFor( entry, { section: sectionForCountryFieldId( countryFieldId ) } )
				);
			}
		} );

		var current = entry.location.current;

		if ( current && current.key && current.level ) {
			// Issue #330 (spec §7): seed EVERY level the customer has actually picked, not only
			// `current`'s own — this is the fix. `entry.records[ current.level ]` is set
			// UNCONDITIONALLY straight after, never gated on whether the chain covered it, so an
			// absent/malformed `chain` (older server, or none at all — {@see adoptChain} is then
			// a silent no-op) degrades to EXACTLY today's single-level seed, and `current` always
			// wins over its own chain entry for its own level regardless.
			adoptChain( entry, entry.location.chain );

			// `confirmed` for the same reason every chain entry above carries it: this seed
			// is the SERVER's own rendered config block, not an optimistic client write —
			// {@see fireLocationApplied} may publish it as the map's addressing locality.
			// Without it this line would DOWNGRADE the record adoptChain() just confirmed
			// for this very level.
			entry.records[ current.level ] = { key: current.key, confirmed: true };

			// The event still fires for `current` ONLY, unchanged — the chain is restoration
			// plumbing for scoping (see scopeKeyFor()), never a second source of "the customer's
			// current locality" for a listener like pickup-mount.js's own resolveLocalityKey().
			// `entry.records.settlement` is already seeded by adoptChain() above, so
			// fireLocationApplied() picks up settlementKey from it correctly even on this boot fire.
			fireLocationApplied( entry, { key: current.key, level: current.level }, !! entry.location.implicit );
		}
	}

	function boot() {
		entries.forEach( function( entry ) {
			prefill( entry );
			attachAll( entry ); // per-node gated internally via isNodeActive()
			// Issue #337: the lock's state is decided HERE, on the records {@see prefill} just
			// restored — a customer who already picked a settlement must find the address field
			// live immediately after a reload, never only after some first event nudges it.
			refreshAddressLock( entry );
		} );

		suppressWcAddressAutocomplete();
		bindChangeWatchers();
		bindCheckoutUpdatedWatcher();
		bindCountryToStateChangedWatcher();

		// Issue #339. A native CustomEvent, like `woodev_location_applied` going the other
		// way — `dispatchEvent()` runs every listener INLINE, so the re-seed is already done
		// by the time pickup-mount.js's own write fires its `change`.
		document.body.addEventListener( 'woodev_pickup_address_replacing', handlePickupAddressReplacing );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

}() );
