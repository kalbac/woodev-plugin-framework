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
	 * The country currently in play for `node` — reads `#shipping_country` for a
	 * shipping-section node, `#billing_country` for everything else (spec §4.4 amendment,
	 * Finding 1). Read LIVE at call time, same convention as the rest of this module's own
	 * live-scope reads (see {@see scopeKeyFor}).
	 *
	 * @param {{section?: string}} node
	 * @returns {string}
	 */
	function countryFor( node ) {
		return countryValue( countryFieldIdFor( node ) );
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

		var country = countryFor( node );

		// The D15 level gate belongs HERE, not only inside attachOne(): the reconcile in
		// applyCountryArbitration() decides detach-vs-attach purely from this predicate, so a
		// gate that lives only on the attach path can never DETACH. Concretely — a customer
		// on RU with an attached address widget who switches to AM (a country we serve, but
		// with city-only data) would keep a widget that can never return anything, because
		// the country itself is still supported and the section is still visible.
		return isCountrySupported( entry, country ) && isLevelServed( entry, country, node.level );
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
	 * entry per level found, in `LEVELS` order, skipping absent links (spec §4.4). The FIRST
	 * field declaring a given level wins if more than one does (deterministic, mirrors the
	 * tie-break precedent in `checkout-field-store.js`'s own `getStoreForField()`). Each node
	 * also carries its OWN `section` (Finding 1) — the field's own §8 `section` key, straight
	 * from the SAME `class-checkout-fields.php::normalize()` value `checkout-field-classic.js`
	 * and `class-checkout-handler.php::inject()` already key off — so a node can be scoped by
	 * the RIGHT country field even when different nodes of the same entry live in different
	 * sections.
	 *
	 * @param {Object.<string, Object>} fields
	 * @returns {Array<{level: string, fieldId: string, section: string}>}
	 */
	function buildChain( fields ) {
		var byLevel = {};

		Object.keys( fields || {} ).forEach( function( id ) {
			var field = fields[ id ];

			if ( field && 'location' === field.source_kind && LEVELS.indexOf( field.location_level ) !== -1 && ! byLevel[ field.location_level ] ) {
				byLevel[ field.location_level ] = { fieldId: id, section: field.section };
			}
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
			// fieldId -> { el, api } for every CURRENTLY attached typeahead widget.
			widgets: {},
			// Single-flight /select queue state (Finding 2) — see enqueueSelect()/sendNextSelect().
			pendingRecord: null,
			selectInFlight: false,
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
	 * Each suggestion is given its `value` here — the string the widget writes into the input
	 * on selection, derived for THIS node's level ({@see fieldValueFor}); the widget itself is
	 * level-agnostic and would otherwise fall back to the provider's own list `label`, which
	 * carries ancestors the field must not repeat. Assigned onto the parsed response object
	 * directly: it is freshly parsed JSON owned by this closure, and `record` — the only part
	 * that round-trips back to `/select` — is left untouched.
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
				country: countryFor( node ),
				within: scopeKeyFor( entry, node.level ),
			} );

			return fetchJson( url, { method: 'GET', headers: nonceHeader( entry ) } ).then( function( body ) {
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

			// Same derivation a direct pick at that level gets ({@see fieldValueFor}) — a
			// backwards-filled field and a directly picked one must not read differently.
			writeSilently( entry, node.fieldId, fieldValueFor( record, ancestorLevel ) );
		} );

		if ( record.postcode && entry.postcodeFieldId ) {
			writeSilently( entry, entry.postcodeFieldId, record.postcode );
		}
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
				settleSelect( entry, !! ( body && false !== body.persisted ) );
			},
			function( error ) {
				logError( error );
				settleSelect( entry, false );
			}
		);
	}

	/**
	 * Frees the single-flight slot for `entry` and either forwards to the next queued
	 * selection (a newer one arrived while this request was in flight — this response is
	 * stale by construction, so it never fires the trigger) or, when nothing newer is queued,
	 * treats this response as FINAL: fires `update_checkout` iff `shouldTrigger`.
	 *
	 * @param {Object}  entry
	 * @param {boolean} shouldTrigger Whether THIS response, if final, should fire the trigger
	 *                                (a successful persist with `persisted !== false`).
	 * @returns {void}
	 */
	function settleSelect( entry, shouldTrigger ) {
		entry.selectInFlight = false;

		if ( entry.pendingRecord ) {
			sendNextSelect( entry );
			return;
		}

		if ( shouldTrigger && window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
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
			enqueueSelect( entry, record );
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
		if ( ! isLevelServed( entry, countryFor( node ), node.level ) ) {
			return;
		}

		var el = document.getElementById( node.fieldId );

		if ( ! el || 'function' !== typeof window.WoodevLocationTypeahead ) {
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

		var api = window.WoodevLocationTypeahead( el, {
			fetch: fetchFor( entry, node ),
			onSelect: onSelectFor( entry, node ),
			// Server-supplied (translated, filterable via `woodev_location_i18n`) — never a
			// literal here: this string reaches the customer, so it follows the same route
			// every other user-facing string in this layer takes.
			emptyText: 'string' === typeof emptyKey ? emptyKey : '',
		} );

		entry.widgets[ node.fieldId ] = { el: el, api: api };
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
	 * @param {Object} entry
	 * @param {string} countryFieldId
	 * @returns {void}
	 */
	function clearCountryScope( entry, countryFieldId ) {
		entry.allNodes.forEach( function( node ) {
			if ( countryFieldIdFor( node ) !== countryFieldId ) {
				return;
			}

			if ( node.level ) {
				entry.records[ node.level ] = null;
			}

			entry.store.setValue( node.fieldId, '' );
			entry.resolved[ node.fieldId ] = '';

			var el = document.getElementById( node.fieldId );

			if ( el ) {
				el.value = '';
			}
		} );
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
	 * @param {Event|Object} event Native `Event` or a jQuery Event — both expose `.target`.
	 * @returns {void}
	 */
	function handleFieldChanged( event ) {
		var target = event && event.target;
		var id = target && target.id ? target.id : '';
		var name = target && target.name ? target.name : '';

		if ( COUNTRY_FIELD_IDS.indexOf( id ) !== -1 ) {
			var country = cascadeKey( target.value );

			entries.forEach( function( entry ) {
				if ( entry.resolved[ id ] === country ) {
					return; // programmatic churn or a re-selection of the same country.
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
			}

			clearDescendants( entry, info.index );
		} );
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

			if ( ! current || current.el !== live ) {
				detachOne( entry, node.fieldId );
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

		// Seed the COUNTRY fields' remembered values too — without this, WooCommerce's own
		// programmatic `change` on the country field during checkout init compares against
		// `undefined`, reads as a real transition, and empties a restored address on every
		// page load ({@see handleFieldChanged}, gotcha
		// `a-programmatic-parent-change-must-not-run-a-destructive-cascade`).
		COUNTRY_FIELD_IDS.forEach( function( countryFieldId ) {
			var el = document.getElementById( countryFieldId );

			if ( el ) {
				entry.resolved[ countryFieldId ] = cascadeKey( el.value );
			}
		} );

		var current = entry.location.current;

		if ( current && current.key && current.level ) {
			entry.records[ current.level ] = { key: current.key };
		}
	}

	function boot() {
		entries.forEach( function( entry ) {
			prefill( entry );
			attachAll( entry ); // per-node gated internally via isNodeActive()
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
