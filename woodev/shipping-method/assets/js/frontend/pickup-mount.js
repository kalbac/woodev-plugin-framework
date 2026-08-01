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
 * WHY THIS FILE — NOT THE (FUTURE) MAP PROVIDER — OWNS THE MODAL'S
 * ERROR/EMPTY/NOTICE STATES: a provider's `init()` only ever receives a bare
 * `container` DOM node (see below), never the modal instance — it has no way
 * to call `showError()`/`showEmpty()`/`showNotice()` itself. This file
 * therefore wraps the `dataSource` it hands to `init()` (see
 * {@see wrapDataSource}): a `fetchPoints()` rejection is mapped from the
 * dataSource's `{status, code, message}` shape to an i18n message (never the
 * raw code); a genuinely empty resolution (`[]`) is NOT an error — see
 * `pickup-datasource.js`'s own docblock. Both are still returned/rejected to
 * the caller unchanged, so a provider that wants to react further (e.g.
 * clearing a drawer) still can.
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
 * a FRESH one, re-wires `select`/`error` on it, and only then calls `init()`.
 *
 * UMD-ish dual export (matches woodev-modal.js/pickup-datasource.js), plus a
 * `mountAll()` re-export purely so a test can drive one mount pass directly
 * instead of only through the deferred event hooks:
 *   - Browser global: window.WoodevPickupMount = { mountAll: mountAll }
 *   - CommonJS:       module.exports = { mountAll: mountAll }  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/** @type {string} prefix of every `woodev_pickup_config_{suffix}` JS config global. */
	var CONFIG_PREFIX = 'woodev_pickup_config_';

	/** @type {string} marker class on the one trigger button mounted per slot. */
	var TRIGGER_CLASS = 'woodev-pickup-trigger';

	/** @type {number} defer, in ms, after `updated_checkout` before re-mounting — see the file docblock. */
	var MOUNT_DEFER_MS = 60;

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
	 * @type {Object.<string, {modal: Object, destroy: Function}>}
	 */
	var sessions = {};

	// -------------------------------------------------------------------------
	// Small helpers
	// -------------------------------------------------------------------------

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
	// dataSource wrapping — see the file docblock for why THIS file, not the
	// provider, owns the error/empty/notice modal states.
	// -------------------------------------------------------------------------

	/**
	 * Wraps a real dataSource so this file can observe a `fetchPoints()`
	 * outcome, while still handing the provider the SAME resolved value or
	 * rejection reason it would have gotten from the unwrapped dataSource — a
	 * provider that wants to react further (e.g. clear its own drawer) still
	 * can. `fetchDetails()` passes through untouched: a balloon-detail failure
	 * is the provider's own concern.
	 *
	 * @param {Object}   dataSource real `{ fetchPoints, fetchDetails }`.
	 * @param {Function} onPoints   called with the resolved point array.
	 * @param {Function} onError    called with the rejection reason.
	 * @returns {Object}
	 */
	function wrapDataSource( dataSource, onPoints, onError ) {
		return {
			fetchPoints: function( query ) {
				return dataSource.fetchPoints( query ).then(
					function( points ) {
						onPoints( points );

						return points;
					},
					function( reason ) {
						onError( reason );

						return Promise.reject( reason );
					}
				);
			},
			fetchDetails: function( pointId ) {
				return dataSource.fetchDetails( pointId );
			},
		};
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
	 * Opens the picker for one config: the modal shell, the resolved provider,
	 * and the wrapped dataSource. Tracks, across any number of retries within
	 * this one session, whether the provider has EVER resolved a non-empty
	 * point set (`hasDrawnPoints`) — see the file docblock's "NON-DESTRUCTIVE
	 * DEGRADATION" section for why that gates `showError()`/`showEmpty()`
	 * (nothing drawn yet, replacing the body is fine) against `showNotice()`
	 * (something IS drawn; only a non-destructive banner is acceptable).
	 *
	 * @param {Object}      config
	 * @param {HTMLElement} triggerEl element focus returns to on close.
	 * @returns {{modal: Object, destroy: Function}}
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

		if ( 'function' !== typeof ProviderCtor ) {
			modal.showError( text( config, 'error' ) );

			return { modal: modal, destroy: function() { modal.destroy(); } };
		}

		var DataSourceFactory = window.WoodevPickupDataSource;

		if ( 'function' !== typeof DataSourceFactory ) {
			modal.showError( text( config, 'error' ) );

			return { modal: modal, destroy: function() { modal.destroy(); } };
		}

		var realDataSource = DataSourceFactory( { restRoot: config.restRoot, nonce: config.nonce } );

		/** @type {boolean} has the provider EVER resolved a non-empty point set this session? */
		var hasDrawnPoints = false;

		/** @type {Object|null} the CURRENT live provider instance — reassigned on every (re-)start(). */
		var provider = null;

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
		 * Destroys the current provider (when one exists) and constructs +
		 * wires + `init()`s a fresh one. NEVER re-`init()`s the same instance —
		 * see the file docblock.
		 *
		 * @returns {void}
		 */
		function start() {
			if ( provider && 'function' === typeof provider.destroy ) {
				provider.destroy();
			}

			provider = new ProviderCtor();

			provider.on( 'select', function( point ) {
				applySelection( config, point );
				closeSession( config.fieldId );
			} );

			provider.on( 'error', function( reason ) {
				degrade( errorMessage( config, reason ), start );
			} );

			var dataSource = wrapDataSource(
				realDataSource,
				function( points ) {
					if ( points.length > 0 ) {
						hasDrawnPoints = true;

						return;
					}

					degrade( text( config, 'noResults' ), null );
				},
				function( reason ) {
					degrade( errorMessage( config, reason ), start );
				}
			);

			provider.init( modal.getContainer(), buildProviderConfig( config ), dataSource );
		}

		start();

		return {
			modal: modal,
			destroy: function() {
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
		button.textContent = text( config, 'trigger' );

		button.addEventListener( 'click', function( event ) {
			event.preventDefault();
			closeSession( config.fieldId );
			sessions[ config.fieldId ] = openSession( config, button );
		} );

		slot.appendChild( button );
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

	var api = { mountAll: mountAll };

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupMount = api;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}

}() );
