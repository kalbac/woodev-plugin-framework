/**
 * Woodev Location Cascade — field-graph wiring for the location provider layer
 * (spec §4.4/D2, plan Tasks 11-12).
 *
 * Builds ON TOP OF the §8 checkout-field store (`checkout-field-store.js`, Task 10 of the
 * 2026-07-06 plan) rather than a parallel state world: the store already owns canonical
 * field values, this module adds LOCATION semantics on top — record objects, scoping,
 * persistence, backwards fill, per-country attach/detach, and (Task 12) a client-side wrap of
 * WC's OWN Address Autocomplete provider registry for mixed-country stores (see the
 * "WC Address Autocomplete suppression" section below).
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
 * @file
 * @since 2.1.0
 */

( function() {
	'use strict';

	var PREFIX = 'woodev_checkout_field_config_';
	var LOG = '[woodev-location-cascade]';
	var COUNTRY_FIELD_ID = 'billing_country';

	/** @type {string[]} the fixed cascade order (spec §4.4). */
	var LEVELS = [ 'region', 'settlement', 'address' ];

	/** @type {Object.<string, string>} WC-convention field-id suffix per level, for postcode derivation. */
	var LEVEL_SUFFIX = { region: 'state', settlement: 'city', address: 'address_1' };

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
	 * Reads the current checkout country — hardcoded to `#billing_country`, mirroring
	 * `checkout-field-classic.js`'s own `currentCountry()` exactly (same established
	 * convention, not a new one).
	 *
	 * @returns {string}
	 */
	function currentCountry() {
		var el = document.getElementById( COUNTRY_FIELD_ID );

		return el ? ( el.value || '' ) : '';
	}

	/**
	 * Formats one record component (`{ name, type }`) as display text — `type + ' ' + name`,
	 * trimmed. Matches the SAME convention the server uses for a record's own `label`
	 * (`Location_Record`'s own Task 1 test fixture: `region: { name: 'Москва', type: 'г' }` →
	 * `label: 'г Москва'`), so a backwards-filled field renders exactly as a direct suggestion
	 * pick at that level would have.
	 *
	 * @param {Object} component
	 * @returns {string}
	 */
	function formatComponent( component ) {
		var type = component && component.type ? String( component.type ) : '';
		var name = component && component.name ? String( component.name ) : '';

		return ( type + ' ' + name ).trim();
	}

	// -------------------------------------------------------------------------
	// Config discovery + chain assembly
	// -------------------------------------------------------------------------

	/**
	 * Builds the ordered chain of location-kind fields ACTUALLY present in `fields` — one
	 * entry per level found, in `LEVELS` order, skipping absent links (spec §4.4). The FIRST
	 * field declaring a given level wins if more than one does (deterministic, mirrors the
	 * tie-break precedent in `checkout-field-store.js`'s own `getStoreForField()`).
	 *
	 * @param {Object.<string, Object>} fields
	 * @returns {Array<{level: string, fieldId: string}>}
	 */
	function buildChain( fields ) {
		var byLevel = {};

		Object.keys( fields || {} ).forEach( function( id ) {
			var field = fields[ id ];

			if ( field && 'location' === field.source_kind && LEVELS.indexOf( field.location_level ) !== -1 && ! byLevel[ field.location_level ] ) {
				byLevel[ field.location_level ] = id;
			}
		} );

		var chain = [];

		LEVELS.forEach( function( level ) {
			if ( byLevel[ level ] ) {
				chain.push( { level: level, fieldId: byLevel[ level ] } );
			}
		} );

		return chain;
	}

	/**
	 * Derives the postcode field id from the DEEPEST present chain field's own WC-convention
	 * suffix (see the file docblock) — `null` when no chain field follows that convention (a
	 * plugin using non-standard ids simply gets no postcode participation).
	 *
	 * @param {Array<{level: string, fieldId: string}>} chain
	 * @returns {string|null}
	 */
	function derivePostcodeFieldId( chain ) {
		var i, node, suffix, fieldId;

		for ( i = chain.length - 1; i >= 0; i-- ) {
			node = chain[ i ];
			suffix = LEVEL_SUFFIX[ node.level ];
			fieldId = node.fieldId;

			if ( suffix && fieldId.length > suffix.length && suffix === fieldId.slice( -suffix.length ) ) {
				return fieldId.slice( 0, fieldId.length - suffix.length ) + 'postcode';
			}
		}

		return null;
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
		var postcodeFieldId = derivePostcodeFieldId( chain );
		var allNodes = chain.concat( postcodeFieldId ? [ { level: null, fieldId: postcodeFieldId } ] : [] );
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
			// fieldId -> { el, api } for every CURRENTLY attached typeahead widget.
			widgets: {},
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
	 * Builds the `fetch(query)` callback handed to the Task 10 widget for one chain node —
	 * scope is read LIVE at call time (never captured at attach time), so a parent selection
	 * made after attach is honoured on the very next keystroke.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string}} node
	 * @returns {function(string): Promise<Array>}
	 */
	function fetchFor( entry, node ) {
		return function( query ) {
			var url = buildUrl( entry.location.endpoints.suggest, {
				q: query,
				level: node.level,
				country: currentCountry(),
				within: scopeKeyFor( entry, node.level ),
			} );

			return fetchJson( url, { method: 'GET', headers: nonceHeader( entry ) } ).then( function( body ) {
				return body && Array.isArray( body.suggestions ) ? body.suggestions : [];
			} );
		};
	}

	/**
	 * Writes `value` into `fieldId`'s store slot AND its live DOM element WITHOUT dispatching
	 * any event — the write path for backwards fill and the `updated_checkout` safety-net
	 * restore, both of which must NOT be mistaken for a user-driven parent change by this same
	 * module's own change-gate (see the file docblock). Also seeds `resolved[fieldId]` to the
	 * SAME value, so a later genuine event comparing against it correctly sees "unchanged".
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
			el.value = value;
		}
	}

	/**
	 * Backwards fill (spec §4.4): writes region/settlement/postcode from a settlement- or
	 * address-level record's OWN embedded components — no second lookup. Only levels STRICTLY
	 * BEFORE the selected one are filled (selecting a settlement never touches address).
	 *
	 * @param {Object} entry
	 * @param {string} level  The level that was just selected.
	 * @param {Object} record The selected record.
	 * @returns {void}
	 */
	function backwardsFill( entry, level, record ) {
		var idx = LEVELS.indexOf( level );

		LEVELS.forEach( function( ancestorLevel, i ) {
			if ( i >= idx ) {
				return;
			}

			var node = chainNodeForLevel( entry, ancestorLevel );
			var component = record[ ancestorLevel ];

			if ( ! node || ! component ) {
				return;
			}

			writeSilently( entry, node.fieldId, formatComponent( component ) );
		} );

		if ( record.postcode && entry.postcodeFieldId ) {
			writeSilently( entry, entry.postcodeFieldId, record.postcode );
		}
	}

	/**
	 * D8: POSTs the full record to `/select`, then — ONLY once that resolves AND the server
	 * actually persisted it — fires `jQuery(document.body).trigger('update_checkout')` itself.
	 * A failed request or `persisted: false` (e.g. a guest whose session cookie has not
	 * initialized yet) skips the trigger silently: the customer's visible choice (already
	 * written to the DOM by the widget before this ever runs) is never reverted, and no
	 * misleading "everything's fine" checkout refresh is fired.
	 *
	 * @param {Object} entry
	 * @param {Object} record
	 * @returns {void}
	 */
	function persistThenTrigger( entry, record ) {
		var url = entry.location.endpoints.select;
		var headers = nonceHeader( entry );

		headers[ 'Content-Type' ] = 'application/json';

		fetchJson( url, { method: 'POST', headers: headers, body: JSON.stringify( { record: record } ) } ).then(
			function( body ) {
				if ( body && false === body.persisted ) {
					return;
				}

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'update_checkout' );
				}
			},
			function( error ) {
				logError( error );
			}
		);
	}

	/**
	 * Builds the `onSelect(item)` callback handed to the Task 10 widget for one chain node.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string}} node
	 * @returns {function(Object): void}
	 */
	function onSelectFor( entry, node ) {
		return function( item ) {
			var record = item && item.record ? item.record : null;

			if ( ! record ) {
				return;
			}

			entry.records[ node.level ] = record;

			backwardsFill( entry, node.level, record );
			persistThenTrigger( entry, record );
		};
	}

	// -------------------------------------------------------------------------
	// Attach / detach (per-country arbitration, D15 unsupported-level gate)
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
	 * Attaches a typeahead widget to one chain node, UNLESS its level is unsupported per
	 * `config.location.levels` (D15) — an unsupported level stays a plain native input; it
	 * still fully participates in the clearing gate below, just with no widget of its own.
	 *
	 * @param {Object} entry
	 * @param {{level: string, fieldId: string}} node
	 * @returns {void}
	 */
	function attachOne( entry, node ) {
		if ( ! entry.location.levels || ! entry.location.levels[ node.level ] ) {
			return;
		}

		var el = document.getElementById( node.fieldId );

		if ( ! el || 'function' !== typeof window.WoodevLocationTypeahead ) {
			return;
		}

		var api = window.WoodevLocationTypeahead( el, {
			fetch: fetchFor( entry, node ),
			onSelect: onSelectFor( entry, node ),
		} );

		entry.widgets[ node.fieldId ] = { el: el, api: api };
	}

	function attachAll( entry ) {
		entry.chain.forEach( function( node ) {
			attachOne( entry, node );
		} );
	}

	function detachAll( entry ) {
		Object.keys( entry.widgets ).forEach( function( fieldId ) {
			try {
				entry.widgets[ fieldId ].api.detach();
			} catch ( e ) {
				logError( e );
			}

			delete entry.widgets[ fieldId ];
		} );
	}

	/**
	 * Re-runs per-country arbitration for one entry: detach whatever is currently attached,
	 * then re-attach fresh if the CURRENT country is supported. Never touches field values —
	 * "switch back → re-attached with state intact" (Task 11 spec).
	 *
	 * @param {Object} entry
	 * @returns {void}
	 */
	function applyCountryArbitration( entry ) {
		detachAll( entry );

		if ( isCountrySupported( entry, currentCountry() ) ) {
			attachAll( entry );
		}
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
	 * `wcSuppressionApplied` false so a later call (see {@see handleCountryChanged}) can retry.
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
	 * @param {Object} entry
	 * @param {number} fromIndex
	 * @returns {void}
	 */
	function clearDescendants( entry, fromIndex ) {
		for ( var i = fromIndex + 1; i < entry.allNodes.length; i++ ) {
			var node = entry.allNodes[ i ];

			if ( node.level ) {
				entry.records[ node.level ] = null;
			}

			entry.store.setValue( node.fieldId, '' );
			entry.resolved[ node.fieldId ] = '';

			var el = document.getElementById( node.fieldId );

			if ( el ) {
				el.value = '';
			}
		}
	}

	/**
	 * Delegated `change` handler for BOTH event worlds (see the file docblock). Routes a
	 * country-field change to arbitration; otherwise, for every entry owning this field id,
	 * gates a destructive downward clear on a REAL remembered-value transition.
	 *
	 * @param {Event|Object} event Native `Event` or a jQuery Event — both expose `.target`.
	 * @returns {void}
	 */
	function handleFieldChanged( event ) {
		var target = event && event.target;
		var id = target && target.id ? target.id : '';

		if ( ! id ) {
			return;
		}

		if ( COUNTRY_FIELD_ID === id ) {
			handleCountryChanged();
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
			}

			clearDescendants( entry, info.index );
		} );
	}

	/** @type {string|null} module-scope remembered country — shared across every entry. */
	var resolvedCountry = null;

	function handleCountryChanged() {
		var key = cascadeKey( currentCountry() );

		// Opportunistic retry (see the suppression section's own docblock): a page whose WC
		// autocomplete script happens to execute after this one still gets suppressed the first
		// time the customer touches the country field — no-op once already applied.
		suppressWcAddressAutocomplete();

		if ( resolvedCountry === key ) {
			return;
		}

		resolvedCountry = key;

		entries.forEach( function( entry ) {
			applyCountryArbitration( entry );
		} );
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
	 * @param {Object} entry
	 * @returns {void}
	 */
	function reconcileAfterCheckoutUpdate( entry ) {
		if ( ! isCountrySupported( entry, currentCountry() ) ) {
			return;
		}

		entry.chain.forEach( function( node ) {
			var live = document.getElementById( node.fieldId );
			var current = entry.widgets[ node.fieldId ];

			if ( ! live ) {
				if ( current ) {
					try {
						current.api.detach();
					} catch ( e ) {
						logError( e );
					}

					delete entry.widgets[ node.fieldId ];
				}

				return;
			}

			if ( ! current || current.el !== live ) {
				if ( current ) {
					try {
						current.api.detach();
					} catch ( e ) {
						logError( e );
					}
				}

				attachOne( entry, node );
			}

			var stored = entry.store.getValue( node.fieldId );

			if ( ! live.value && undefined !== stored && null !== stored && '' !== stored ) {
				live.value = stored;
			}
		} );
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

	// -------------------------------------------------------------------------
	// Boot / prefill
	// -------------------------------------------------------------------------

	/**
	 * Seeds `resolved[]` from each node's rendered DOM value (so WooCommerce's own init-time
	 * programmatic `change` — carrying the value already there — is a no-op, not a destructive
	 * clear) and, when `config.location.current` names an existing customer record, seeds a
	 * partial record (`{ key }` only — the config block never carries full components, see
	 * `class-checkout-config.php::build_location_block()`) for that level so a child scope
	 * fetch can use it WITHOUT re-fetching (Task 11 "restore state without re-fetching").
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

		var current = entry.location.current;

		if ( current && current.key && current.level ) {
			entry.records[ current.level ] = { key: current.key };
		}
	}

	function boot() {
		resolvedCountry = cascadeKey( currentCountry() );

		entries.forEach( function( entry ) {
			prefill( entry );

			if ( isCountrySupported( entry, currentCountry() ) ) {
				attachAll( entry );
			}
		} );

		suppressWcAddressAutocomplete();
		bindChangeWatchers();
		bindCheckoutUpdatedWatcher();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

}() );
