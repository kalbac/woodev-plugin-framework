/**
 * Woodev Yandex Map Provider — our own pickup-point map on Yandex Maps JS API 2.1.
 *
 * Implements the `Map_Provider` JS seam (see `pickup-mount.js`'s own docblock for the
 * contract): `init( container, config, dataSource )`, `on( 'select'|'error', cb )`,
 * `destroy()`. Everything drawn INSIDE `container` is this file's responsibility —
 * clustering, the balloon, the viewport-synced drawer, the type filter, address search.
 * The framework hands this provider a `dataSource` instead of a point list (see
 * `pickup-datasource.js`) precisely so ONE contract serves both a bulk carrier (fetch a
 * whole locality once) and a viewport carrier (fetch per bounding box, details lazily) —
 * this file is what actually branches on `config.strategy` to decide when to call it.
 *
 * PORTED, NOT COPIED, FROM THE REFERENCE PLUGIN
 * (`plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`).
 * The reference pulls points from its own AJAX layer and jQuery/Underscore/`wp.template`;
 * this file pulls exclusively through the injected `dataSource`, is vanilla (no `wp`
 * dependency — none of the sibling SP-5 files carry one either), and never re-derives a
 * selectability verdict the server already computed (see "SELECTABLE IS NEVER
 * RE-DERIVED" below).
 *
 * SCRIPT LOADING IS IDEMPOTENT ACROSS SESSIONS, NOT PER-INSTANCE: a retry (see
 * `pickup-mount.js`) always constructs a FRESH provider instance, so a per-instance
 * "have I loaded the script" flag would inject a second `<script>` tag on every retry.
 * {@see loadYmapsScript} instead caches the loading promise in MODULE scope, keyed by
 * `config.ns` — the first `init()` (ever, across every session on this page) injects the
 * tag; every later `init()`, in this or a later session, reuses the same promise. A
 * failed load clears its cache entry so a genuine retry (script blocked by an ad blocker
 * on attempt 1, network back on attempt 2) gets a fresh `<script>` tag rather than being
 * permanently wedged. When `window[config.ns]` already exists with a `.ready` method —
 * the exact case a second `init()` in the same page hits — loading resolves immediately
 * without touching the DOM at all.
 *
 * WHY THE INITIAL MAP STATE IS `[0, 0]` AT ZOOM 2, NEVER A REGIONAL DEFAULT: the Yandex
 * Maps constructor requires SOME initial center+zoom (there is no "no viewport" state in
 * their API). Spec §4.3/Task 13 forbid a hardcoded regional centre (a Moscow default is
 * domain leakage into a framework meant to serve any CIS city) — so {@see DEFAULT_CENTER}
 * is "null island" (0°, 0°), a point with no market bias, used purely as a technical
 * placeholder. It is ALWAYS overwritten before the customer would notice: under `bulk` by
 * `map.setBounds()` once points arrive, under `viewport` by a locality geocode when one is
 * known. Only when `viewport` has no locality AND geocoding fails does the customer
 * actually see this placeholder — the documented "fall back to the map's default state"
 * case, not a silent extra branch invented here.
 *
 * SELECTABLE IS NEVER RE-DERIVED: `point.selectable = { allowed, reason }` is computed
 * server-side by `Constraint_Checker` (spec §4.5) — this file only ever READS it to
 * disable the balloon's CTA and show `reason`. Re-implementing the COD/weight rules here
 * would create the exact mirrored-evaluator maintenance §4.5 explicitly rejects.
 *
 * ESCAPING: point display strings (`name`, `address`, `short_address`, `locality`,
 * `postal_code`, `phone`, `instruction`, `work_time`, `type.label`) arrive ALREADY
 * `esc_html()`-escaped by `Pickup_Point::to_browser_array()` (spec §10.1) — an entity
 * sequence like `&quot;`. Assigning that string via `textContent` would show the LITERAL
 * entity text (double-escaped garbage); assigning it into an HTML string that is then set
 * via `innerHTML` lets the browser's own HTML parser decode it correctly — exactly like
 * PHP echoing an `esc_html()`ed value into a template. Every OTHER string this file weaves
 * into balloon markup — `config.i18n.*` labels (plain, unescaped `__()` output) and
 * `point.selectable.reason` (plain, unescaped `Constraint_Checker` output) — is NOT
 * pre-escaped, so THIS file escapes it via {@see escapeHtml} before concatenation. Mixing
 * these two rules up in either direction is exactly the bug class this docblock exists to
 * prevent: double-escaping the point fields, or leaving the i18n/reason strings raw.
 * `point.id` is deliberately never rendered — see `Pickup_Point::to_browser_array()`'s own
 * docblock for why it is the one field left unescaped (it round-trips as an identity
 * token, never display text).
 *
 * VIEWPORT LAZY-DETAIL RE-RENDER: under `strategy: 'viewport'`, `_renderBalloon()` renders
 * synchronously with whatever point data it was called with (usually the sparse LIST
 * point), then kicks off `dataSource.fetchDetails( point.id )` and, on resolve, calls
 * ITSELF AGAIN with the fuller point — upgrading the CTA's enabled/disabled state and
 * every optional field in place. A `_balloonSeq` counter guards this: each `_renderBalloon`
 * call captures the counter's value at call time, and only applies its own async
 * follow-up (the detail re-render, or the detail-error banner) if the counter is STILL the
 * same when the promise settles — otherwise a slower-to-resolve detail fetch for a balloon
 * the customer has since closed/reopened/replaced would stomp newer content. A rejection
 * appends an inline error banner (`i18n.detailsError`) WITHOUT touching the already-rendered
 * CTA — the list point's own (already-known) verdict is what stays selectable, per spec
 * §4.9's "details fetch fails → balloon shows what the list already knows, selection stays
 * possible" rule. It never emits a provider-level `error` — that is reserved for failures
 * that break the WHOLE map, not one balloon.
 *
 * VIEWPORT DE-DUPLICATION: `knownIds` (point id → true) is never cleared between
 * `boundschange` fetches within one session — only `destroy()` clears it. Panning away and
 * back re-fetches the same points from the server (the dataSource has no cache of its
 * own — see its docblock) but {@see WoodevYandexMapProvider#_addPoints} skips any id
 * already drawn, so the map never grows a duplicate placemark for a point still visible
 * from an earlier fetch.
 *
 * DRAWER + TYPE FILTER AS REAL YMAPS CONTROLS: both are built through
 * {@see buildDockedControl}, a small wrapper around the documented
 * `ymaps.util.defineClass( ctor, ymaps.collection.Item, methods )` custom-control pattern
 * the reference implementation itself uses for its drawer — NOT a plain DOM overlay sitting
 * outside the map's own control layer, which would drift out of sync with pan/zoom-driven
 * layout and ignore `map.margin.addArea()`. The type filter is added lazily, the first time
 * a SECOND distinct `point.type.code` is observed (spec: "only when more than one distinct
 * type is present") — never removed again even if a later fetch happens to return only one
 * type, since a control disappearing mid-session under the customer's cursor is worse than
 * one that stays.
 *
 * OPENING A PLACEMARK'S BALLOON IS SEQUENCED, NOT JUST DESTROY-GUARDED: {@see
 * _openPlacemarkBalloon} un-clusters a point via `map.setBounds()`, which is asynchronous (see
 * that method's own docblock) — clicking one drawer/cluster-balloon item and then a second,
 * faster-to-resolve one before the first click's move finishes must never let the FIRST click's
 * now-stale continuation open its balloon on top of the second (most recent) click's. `_openSeq`
 * (bumped on every call, captured as `mySeq` at call time) guards this exactly the way
 * `_balloonSeq` guards a stale detail fetch above: only the continuation whose `mySeq` still
 * matches the current `_openSeq` when the move resolves is allowed to call
 * `placemark.balloon.open()`. The reference implementation solves the same problem with an
 * `isAnimating` instance flag consumed inside a per-placemark `balloonopen` listener this file
 * has no equivalent of (it drives a secondary `setCenter()` animation this file does not need,
 * since `setBounds()` already recentres — see the drawer's own click-handler comment); a
 * sequence counter is the direct translation of that same ordering guarantee into this file's
 * shape, not a port of `isAnimating` itself.
 *
 * DESTROY IS IDEMPOTENT AND SAFE BEFORE `init()` SETTLES: a `_destroyed` flag is checked at
 * every async continuation (script load, initial fetch, `boundschange` fetch, balloon
 * detail fetch) before touching `this.map`/DOM, so a `destroy()` racing an in-flight
 * `init()` never throws or half-builds a map nobody wants anymore. `destroy()` itself
 * removes the `window` `resize` listener it added (for the drawer's mobile bottom-sheet
 * class), detaches the clusterer, destroys the map, and resets every internal collection —
 * re-opening the modal always gets a BRAND NEW provider instance anyway (see
 * `pickup-mount.js`'s own "RETRY NEVER RE-`init()`S A LIVE PROVIDER INSTANCE" rule), so
 * nothing here needs to support being reused after `destroy()`.
 *
 * UMD-ish dual export (matches the sibling SP-5 frontend files):
 *   - Browser global: window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider
 *   - CommonJS:       module.exports = WoodevYandexMapProvider  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/** @type {number} technical placeholder center — see the file docblock. Never a regional default. */
	var DEFAULT_CENTER = [ 0, 0 ];

	/** @type {number} technical placeholder zoom paired with {@see DEFAULT_CENTER}. */
	var DEFAULT_ZOOM = 2;

	/** @type {number} maximum zoom the map allows the customer to reach. */
	var MAX_ZOOM = 18;

	/** @type {string} cluster icon colour, matching the reference implementation exactly. */
	var CLUSTER_ICON_COLOR = '#FCE000';

	/** @type {number} viewport width, in px, below which the drawer renders as a bottom sheet. */
	var MOBILE_BREAKPOINT = 782;

	// -------------------------------------------------------------------------
	// Small pure helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads an i18n string off the provider config — empty string when absent/blank,
	 * NEVER a JS-side hardcoded default. Mirrors `pickup-mount.js`'s own `text()` — see its
	 * docblock (I1): a missing key must render blank and loud, never a Russian string that
	 * happens to read the same and hides a PHP/JS key mismatch.
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
	 * Returns `value` when it is a non-empty string worth rendering, `''` otherwise —
	 * the single guard every "render this point field if present" branch below shares.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function safeField( value ) {
		return 'string' === typeof value ? value : '';
	}

	/**
	 * HTML-escapes a string THIS file computed or read from i18n/`selectable.reason` —
	 * never applied to a point display field, which arrives ALREADY escaped. See the file
	 * docblock's "ESCAPING" section for why these two cases must never be conflated.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Formats a GRAMS weight limit as kilograms with two decimals — the same unit and
	 * precision `Constraint_Checker::check()` uses for the `reason` message it may already
	 * be showing above this, so the two numbers on screen never disagree.
	 *
	 * No unit suffix is appended here: appending a hardcoded "кг" would be exactly the
	 * hardcoded-Russian-string mistake this file's docblock and the project's own rules
	 * forbid. The `maxWeight` i18n LABEL is expected to carry the unit in its own text.
	 *
	 * @param {number} grams
	 * @returns {string}
	 */
	function formatWeightKg( grams ) {
		return ( grams / 1000 ).toFixed( 2 );
	}

	/**
	 * Flattens a ymaps bounds pair `[[lat1,lng1],[lat2,lng2]]` into the flat
	 * `[lat1,lng1,lat2,lng2]` array `pickup-datasource.js`'s `serializePointsQuery()`
	 * expects for `bounds` — see that file's own docblock for the exact wire format
	 * `Point_Query::from_request()` parses.
	 *
	 * @param {Array} bounds `[[Number, Number], [Number, Number]]`.
	 * @returns {Array} `[Number, Number, Number, Number]`.
	 */
	function flattenBounds( bounds ) {
		return [ bounds[ 0 ][ 0 ], bounds[ 0 ][ 1 ], bounds[ 1 ][ 0 ], bounds[ 1 ][ 1 ] ];
	}

	/**
	 * Extracts a usable bounds pair from a `ymaps.geocode()` result's first hit, or null
	 * when the result is empty/malformed — the caller degrades to the map's default state
	 * either way (see the file docblock), so this never throws.
	 *
	 * @param {Object} result
	 * @returns {Array|null}
	 */
	function extractGeocodeBounds( result ) {
		var geoObjects = result && result.geoObjects;
		var first = geoObjects && 'function' === typeof geoObjects.get ? geoObjects.get( 0 ) : null;
		var properties = first && first.properties;
		var bounds = properties && 'function' === typeof properties.get ? properties.get( 'boundedBy' ) : null;

		return Array.isArray( bounds ) ? bounds : null;
	}

	// -------------------------------------------------------------------------
	// Script loading — module-scope cache, see the file docblock
	// -------------------------------------------------------------------------

	/** @type {Object.<string, Promise>} pending/settled script loads, keyed by `config.ns`. */
	var scriptPromises = {};

	/**
	 * Loads `config.scriptUrl` and resolves with the ready ymaps namespace object
	 * (`window[config.ns]`). Idempotent across every provider instance and every session
	 * on the page — see the file docblock's "SCRIPT LOADING" section.
	 *
	 * @param {Object} config
	 * @returns {Promise<Object>}
	 */
	function loadYmapsScript( config ) {
		var ns = config.ns;

		if ( ! scriptPromises[ ns ] ) {
			scriptPromises[ ns ] = new Promise( function( resolve, reject ) {
				if ( window[ ns ] && 'function' === typeof window[ ns ].ready ) {
					resolve( window[ ns ] );

					return;
				}

				var script = document.createElement( 'script' );

				script.src = config.scriptUrl;
				script.async = true;
				script.onload = function() {
					if ( ! window[ ns ] || 'function' !== typeof window[ ns ].ready ) {
						reject( new Error( 'woodev-pickup-map: ymaps namespace missing after script load' ) );

						return;
					}

					resolve( window[ ns ] );
				};
				script.onerror = function() {
					reject( new Error( 'woodev-pickup-map: ymaps script failed to load' ) );
				};

				document.head.appendChild( script );
			} ).catch( function( err ) {
				// Allow a genuine retry (a fresh provider instance, see pickup-mount.js) to try
				// loading again rather than being permanently wedged on a transient failure.
				delete scriptPromises[ ns ];

				return Promise.reject( err );
			} );
		}

		return scriptPromises[ ns ].then( function( api ) {
			return Promise.resolve( api.ready() ).then( function() {
				return api;
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Docked custom control helper — shared by the drawer and the type filter
	// -------------------------------------------------------------------------

	/**
	 * Builds a ymaps custom map control via the documented
	 * `ymaps.util.defineClass( ctor, ymaps.collection.Item, methods )` pattern — the same
	 * mechanism the reference implementation's own drawer uses. `buildFn( parentDomContainer )`
	 * is called once the control is actually attached to the map and ymaps hands back the DOM
	 * node it owns; it must return `{ element, teardown }`, where `teardown()` runs when the
	 * control is removed from the map (i.e. on `map.destroy()`).
	 *
	 * @param {Object}   ymaps
	 * @param {Function} buildFn `function( parentDomContainer ): { element: HTMLElement, teardown: Function }`.
	 * @returns {Object} a constructed control instance, ready for `map.controls.add()`.
	 */
	function buildDockedControl( ymaps, buildFn ) {
		var Control = ymaps.util.defineClass( function( options ) {
			Control.superclass.constructor.call( this, options );
		}, ymaps.collection.Item, {
			onAddToMap: function( map ) {
				Control.superclass.onAddToMap.call( this, map );

				var self = this;

				this.getParent().getChildElement( this ).then( function( parentDomContainer ) {
					var built = buildFn( parentDomContainer );

					self._builtElement = built.element;
					self._teardown = built.teardown || function() {};
					parentDomContainer.appendChild( built.element );
				} );
			},
			onRemoveFromMap: function( oldMap ) {
				if ( this._teardown ) {
					this._teardown();
				}

				if ( this._builtElement && this._builtElement.parentNode ) {
					this._builtElement.parentNode.removeChild( this._builtElement );
				}

				Control.superclass.onRemoveFromMap.call( this, oldMap );
			},
		} );

		return new Control();
	}

	// -------------------------------------------------------------------------
	// Balloon markup — a pure string builder, independently testable without ymaps
	// -------------------------------------------------------------------------

	/**
	 * Builds the balloon's inner HTML for one point. See the file docblock's "ESCAPING"
	 * section: point display fields are inserted AS-IS (already `esc_html()`ed
	 * server-side); every i18n label and `selectable.reason` is escaped HERE, since
	 * neither is pre-escaped.
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {string}
	 */
	function buildBalloonHtml( config, point ) {
		var selectable = ( point && point.selectable ) || { allowed: true, reason: null };
		var html = '';

		html += '<div class="woodev-pickup-balloon__title">' + safeField( point.name ) + '</div>';

		if ( safeField( point.postal_code ) ) {
			html += '<div class="woodev-pickup-balloon__postal">' + safeField( point.postal_code ) + '</div>';
		}

		html += '<div class="woodev-pickup-balloon__address">' + safeField( point.address ) + '</div>';

		if ( safeField( point.instruction ) ) {
			html += '<details class="woodev-pickup-balloon__howto">'
				+ '<summary class="woodev-pickup-balloon__howto-summary">'
				+ escapeHtml( text( config, 'howToGet' ) )
				+ '</summary>'
				+ '<div class="woodev-pickup-balloon__howto-content">' + safeField( point.instruction ) + '</div>'
				+ '</details>';
		}

		if ( Array.isArray( point.payment_methods ) && point.payment_methods.length > 0 ) {
			var paymentsValue = point.payment_methods.map( safeField ).join( ', ' );

			html += '<div class="woodev-pickup-balloon__payments">'
				+ '<span class="woodev-pickup-balloon__label">'
				+ escapeHtml( text( config, 'paymentMethods' ) )
				+ '</span> '
				+ '<span class="woodev-pickup-balloon__value">' + paymentsValue + '</span>'
				+ '</div>';
		}

		if ( safeField( point.phone ) ) {
			html += '<div class="woodev-pickup-balloon__phone">'
				+ '<span class="woodev-pickup-balloon__label">' + escapeHtml( text( config, 'phone' ) ) + '</span> '
				+ '<span class="woodev-pickup-balloon__value">' + safeField( point.phone ) + '</span>'
				+ '</div>';
		}

		if ( safeField( point.work_time ) ) {
			html += '<div class="woodev-pickup-balloon__worktime">'
				+ '<span class="woodev-pickup-balloon__label">' + escapeHtml( text( config, 'workTime' ) ) + '</span> '
				+ '<span class="woodev-pickup-balloon__value">' + safeField( point.work_time ) + '</span>'
				+ '</div>';
		}

		if ( null !== point.max_weight && undefined !== point.max_weight ) {
			html += '<div class="woodev-pickup-balloon__weight">'
				+ '<span class="woodev-pickup-balloon__label">' + escapeHtml( text( config, 'maxWeight' ) ) + '</span> '
				+ '<span class="woodev-pickup-balloon__value">' + formatWeightKg( point.max_weight ) + '</span>'
				+ '</div>';
		}

		html += '<div class="woodev-pickup-balloon__footer">';

		if ( ! selectable.allowed ) {
			html += '<div class="woodev-pickup-balloon__warning">'
				+ escapeHtml( selectable.reason || text( config, 'blocked' ) )
				+ '</div>';
		}

		html += '<button type="button" class="woodev-pickup-balloon__select"'
			+ ( selectable.allowed ? '' : ' disabled' )
			+ '>' + escapeHtml( text( config, 'select' ) ) + '</button>';

		html += '</div>';

		return html;
	}

	// -------------------------------------------------------------------------
	// Layout factories — thin ymaps adapters delegating to the provider instance
	// -------------------------------------------------------------------------

	/**
	 * Builds the individual-placemark balloon layout class via
	 * `ymaps.templateLayoutFactory.createClass` — the documented pattern for a fully
	 * custom balloon body. `build()`/`clear()` are kept THIN: all real rendering logic
	 * lives in {@see WoodevYandexMapProvider#_renderBalloon}, which is independently unit
	 * testable without going through ymaps' balloon-open machinery at all.
	 *
	 * @param {Object}                 ymaps
	 * @param {WoodevYandexMapProvider} provider
	 * @returns {Function}
	 */
	function buildBalloonLayoutClass( ymaps, provider ) {
		return ymaps.templateLayoutFactory.createClass(
			'<div class="woodev-pickup-balloon"></div>',
			{
				build: function() {
					this.constructor.superclass.build.call( this );

					var point = this.getData().properties.get( 'point' );

					provider._renderBalloon( this.getElement(), point );
				},
				clear: function() {
					this.constructor.superclass.clear.call( this );
				},
			}
		);
	}

	/**
	 * Builds the cluster balloon layout: a short, clickable list of the clustered points'
	 * names, each opening that point's OWN balloon (never re-implementing the individual
	 * balloon's content here — see {@see WoodevYandexMapProvider#_renderClusterBalloon}).
	 *
	 * @param {Object}                 ymaps
	 * @param {WoodevYandexMapProvider} provider
	 * @returns {Function}
	 */
	function buildClusterBalloonLayoutClass( ymaps, provider ) {
		return ymaps.templateLayoutFactory.createClass(
			'<div class="woodev-pickup-cluster-balloon"></div>',
			{
				build: function() {
					this.constructor.superclass.build.call( this );

					var data = this.getData();
					var geoObjects = ( data && data.geoObjects ) || [];

					provider._renderClusterBalloon( this.getElement(), geoObjects );
				},
				clear: function() {
					this.constructor.superclass.clear.call( this );
				},
			}
		);
	}

	// -------------------------------------------------------------------------
	// Provider
	// -------------------------------------------------------------------------

	/**
	 * @constructor
	 */
	function WoodevYandexMapProvider() {
		this.ymaps = null;
		this.map = null;
		this.clusterer = null;
		this.dataSource = null;
		this.config = null;
		this.container = null;

		this.handlers = { select: [], error: [] };

		/** @type {Object.<string, boolean>} point ids already drawn — see the file docblock. */
		this.knownIds = {};

		/** @type {Object.<string, Object>} live placemarks by point id, for the type filter. */
		this._placemarksById = {};

		/** @type {Object.<string, string>} distinct `type.code` → `type.label` seen so far. */
		this._typeSet = {};

		/** @type {string[]} `type.code`s in FIRST-SEEN order, for stable filter ordering. */
		this._typeList = [];

		this._filterControlAdded = false;
		this._filterControl = null;
		this._drawerControl = null;
		this._drawerListEl = null;
		this._drawerRootEl = null;
		this._balloonLayoutClass = null;

		/** @type {number} bumped on every `_renderBalloon()` call — see the file docblock. */
		this._balloonSeq = 0;

		/** @type {number} bumped on every `_openPlacemarkBalloon()` call — see the file docblock's
		 *  "OPENING A PLACEMARK'S BALLOON IS SEQUENCED" section and {@see _openPlacemarkBalloon}. */
		this._openSeq = 0;

		this._onResize = null;
		this._destroyed = false;

		/** @type {HTMLElement|null} the "loading" overlay node — see {@see _showLoading}. */
		this._loadingEl = null;
	}

	/**
	 * Registers a handler for `'select'` (called with the normalized point) or `'error'`
	 * (called with `{ code, message }`) — part of the `Map_Provider` JS contract.
	 *
	 * @param {string}   event
	 * @param {Function} cb
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.on = function( event, cb ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ].push( cb );
		}
	};

	/**
	 * Fires every handler registered for `event`.
	 *
	 * @param {string} event
	 * @param {*}      payload
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.emit = function( event, payload ) {
		( this.handlers[ event ] || [] ).forEach( function( cb ) {
			cb( payload );
		} );
	};

	/**
	 * Initializes the map inside `container`. See the file docblock for the full
	 * behaviour; in short: `hasApiKey === false` or a script/ready failure emits a
	 * `map_script` error and draws nothing; otherwise the map, clusterer and controls are
	 * built and the initial point set is loaded according to `config.strategy`.
	 *
	 * `i18n.loading` (spec §4.9 improvement) is shown inside `container` for exactly the
	 * span between the map being built and the INITIAL point fetch settling — not while the
	 * script itself loads (there is nothing to draw over yet), and not for later
	 * `boundschange` re-fetches under `viewport` (a live map with points already on it is
	 * never worth hiding again). See {@see _showLoading}/{@see _hideLoading}. Both of
	 * `_loadBulk()`/`_loadViewport()` always RESOLVE (never reject) their returned promise —
	 * a dataSource rejection is turned into an `error` EMIT, not a rejection — so a single
	 * `.then()` here is enough to hide the overlay in every case, success or failure alike.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config    the MERGED provider config `pickup-mount.js` builds
	 *                                (`mapConfig` + `strategy` + `i18n` + `locality`).
	 * @param {Object}      dataSource `{ fetchPoints, fetchDetails }`.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.init = function( container, config, dataSource ) {
		var self = this;

		this.container = container;
		this.config = config || {};
		this.dataSource = dataSource;

		if ( false === this.config.hasApiKey ) {
			this.emit( 'error', { code: 'map_script', message: '' } );

			return Promise.resolve();
		}

		return loadYmapsScript( this.config ).then(
			function( ymaps ) {
				if ( self._destroyed ) {
					return undefined;
				}

				self.ymaps = ymaps;
				self._buildMap();
				self._showLoading();

				return self._loadInitialPoints().then( function( result ) {
					self._hideLoading();

					return result;
				} );
			},
			function() {
				if ( ! self._destroyed ) {
					self.emit( 'error', { code: 'map_script', message: '' } );
				}
			}
		);
	};

	/**
	 * Builds the map, clusterer, and every control that does not depend on point data
	 * being loaded yet (zoom, search, drawer). The type filter is added lazily — see
	 * {@see WoodevYandexMapProvider#_maybeAddFilterControl}.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildMap = function() {
		var self = this;
		var ymaps = this.ymaps;

		this.map = new ymaps.Map(
			this.container,
			{ center: DEFAULT_CENTER, zoom: DEFAULT_ZOOM, controls: [] },
			{ suppressMapOpenBlock: true, maxZoom: MAX_ZOOM }
		);

		this._balloonLayoutClass = buildBalloonLayoutClass( ymaps, this );

		this.clusterer = new ymaps.Clusterer( {
			clusterIconColor: CLUSTER_ICON_COLOR,
			clusterBalloonContentLayout: buildClusterBalloonLayoutClass( ymaps, this ),
			hasHint: false,
		} );

		this.map.geoObjects.add( this.clusterer );

		this.map.controls.add( new ymaps.control.ZoomControl(), { position: { left: 70, bottom: 70 } } );

		this.map.controls.add( new ymaps.control.SearchControl( {
			options: {
				provider: {
					geocode: function( request ) {
						return ymaps.geocode( request, {
							boundedBy: self.clusterer.getBounds(),
							strictBounds: true,
						} );
					},
				},
				noPlacemark: true,
				resultsPerPage: 10,
				placeholderContent: text( this.config, 'search' ),
			},
		} ) );

		this.map.margin.addArea( { top: 0, left: 0, width: '100%', height: '64px' } );

		this._drawerControl = this._buildDrawerControl();
		this.map.controls.add( this._drawerControl, { float: 'none', position: { top: 0, right: 0 } } );

		this._bindResizeListener();
	};

	/**
	 * Dispatches the initial point load per `config.strategy` — the single place that
	 * reads it, so a future third strategy only needs a branch here.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._loadInitialPoints = function() {
		if ( 'viewport' === this.config.strategy ) {
			return this._loadViewport();
		}

		return this._loadBulk();
	};

	/**
	 * `bulk` strategy: fetch the whole locality ONCE, then fit the map to whatever came
	 * back. A rejection emits `error` with the reason UNCHANGED — the mount owns turning
	 * `{status, code, message}` into a human message (see `pickup-mount.js`'s own
	 * `errorMessage()`), so this file must never rewrap or paraphrase it.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._loadBulk = function() {
		var self = this;

		return this.dataSource.fetchPoints( { locality: this.config.locality || '' } ).then(
			function( points ) {
				if ( self._destroyed ) {
					return;
				}

				self._addPoints( points );

				if ( points.length > 0 ) {
					self.map.setBounds( self.clusterer.getBounds(), { checkZoomRange: true } );
				}
			},
			function( reason ) {
				if ( ! self._destroyed ) {
					self.emit( 'error', reason );
				}
			}
		);
	};

	/**
	 * `viewport` strategy: resolve an initial viewport (geocoding `config.locality` when
	 * known), fetch that viewport's points, THEN start listening for `boundschange` — in
	 * that order, so the initial `setBounds()` inside {@see _resolveInitialViewport} never
	 * triggers a redundant extra fetch through a listener that is not registered yet. The
	 * `dataSource` already debounces `fetchPoints()` at 300ms (see its own docblock); this
	 * method adds NO second debounce on top of it.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._loadViewport = function() {
		var self = this;

		return this._resolveInitialViewport().then( function() {
			if ( self._destroyed ) {
				return undefined;
			}

			return self._fetchViewport();
		} ).then( function() {
			if ( self._destroyed ) {
				return;
			}

			self.map.events.add( 'boundschange', function() {
				self._fetchViewport();
			} );
		} );
	};

	/**
	 * Resolves the map's initial viewport for the `viewport` strategy. A known locality is
	 * geocoded and its bounds applied; an EMPTY locality, or a geocode failure, is a
	 * deliberate, silent degradation to the map's already-constructed default state — see
	 * the file docblock's "WHY THE INITIAL MAP STATE IS [0, 0]" section. Neither case emits
	 * a provider `error`: an unresolved geocode is not a broken map, just a wider first
	 * fetch.
	 *
	 * `config.locality` MUST BE A GEOCODABLE PLACE NAME, NOT AN OPAQUE CODE, for this to
	 * resolve anything: `ymaps.geocode()` is Yandex's free-text geocoder — it has no idea
	 * what to do with a carrier city id or a FIAS code, and a lookup it cannot parse simply
	 * comes back empty, landing in the SAME silent fallback as no locality at all. Nothing
	 * here can detect or warn about that case — a malformed-but-non-empty string and a
	 * genuinely unresolvable place name are indistinguishable from this method's point of
	 * view. `pickup-mount.js` reads this value straight off the address target's city
	 * `<select>`'s `.value` (see its own `buildProviderConfig()` docblock); a plugin wiring
	 * THIS provider under `strategy: 'viewport'` must keep that field's option `value` a
	 * real place name for the initial viewport to ever center on the customer's city.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._resolveInitialViewport = function() {
		var self = this;
		var locality = this.config.locality;

		if ( ! locality ) {
			return Promise.resolve();
		}

		return this.ymaps.geocode( locality ).then(
			function( result ) {
				if ( self._destroyed ) {
					return;
				}

				var bounds = extractGeocodeBounds( result );

				if ( ! bounds ) {
					return;
				}

				// RETURN the setBounds promise — do not fire and forget. ymaps' setBounds() is
				// ASYNCHRONOUS (it animates the camera and resolves when the move completes), so
				// dropping its promise lets this method resolve while the map is still showing its
				// previous state. _loadViewport() then reads map.getBounds() immediately after and
				// gets the PRE-move viewport — the whole-world default — producing a planet-wide
				// bbox that the server's per-side cap correctly refuses, so the customer sees "no
				// points" for a locality that has them. Observed on the rig, and reachable only
				// with a working geocoder: with an invalid API key ymaps refuses geocoding, this
				// branch never runs, and the same empty result arrives for an unrelated reason.
				return self.map.setBounds( bounds, { checkZoomRange: true } );
			},
			function() {
				// Geocoding failure — fall back to the already-built default state; not an error.
			}
		);
	};

	/**
	 * Fetches points for the map's CURRENT bounds and adds them. Called once for the
	 * initial viewport and again on every `boundschange`.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._fetchViewport = function() {
		var self = this;
		var bounds = flattenBounds( this.map.getBounds() );

		return this.dataSource.fetchPoints( { bounds: bounds } ).then(
			function( points ) {
				if ( self._destroyed ) {
					return;
				}

				self._addPoints( points );
			},
			function( reason ) {
				if ( ! self._destroyed ) {
					self.emit( 'error', reason );
				}
			}
		);
	};

	/**
	 * Adds newly-fetched points to the map: skips any id already in {@see knownIds}
	 * (viewport de-duplication — see the file docblock), builds a placemark for each new
	 * one, collects its type for the lazy filter control, and refreshes the drawer.
	 *
	 * @param {Array} points
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._addPoints = function( points ) {
		var self = this;
		var toAdd = [];

		( points || [] ).forEach( function( point ) {
			if ( ! point || undefined === point.id || null === point.id ) {
				return;
			}

			var id = String( point.id );

			if ( Object.prototype.hasOwnProperty.call( self.knownIds, id ) ) {
				return;
			}

			self.knownIds[ id ] = true;
			self._collectType( point );

			var placemark = self._buildPlacemark( point );

			self._placemarksById[ id ] = placemark;
			toAdd.push( placemark );
		} );

		if ( toAdd.length > 0 ) {
			this.clusterer.add( toAdd );
		}

		this._maybeAddFilterControl();
		this._updateDrawer();
	};

	/**
	 * Builds one placemark for a point, wired to the shared balloon layout.
	 *
	 * @param {Object} point
	 * @returns {Object}
	 */
	WoodevYandexMapProvider.prototype._buildPlacemark = function( point ) {
		return new this.ymaps.Placemark(
			[ point.lat, point.lng ],
			{ point: point },
			{
				balloonShadow: false,
				balloonLayout: this._balloonLayoutClass,
				hideIconOnBalloonOpen: false,
				visible: true,
			}
		);
	};

	/**
	 * Records a point's `type.code`/`type.label` the first time it is seen — the type
	 * filter's data source.
	 *
	 * @param {Object} point
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._collectType = function( point ) {
		var type = point && point.type;

		if ( ! type || ! type.code ) {
			return;
		}

		if ( ! Object.prototype.hasOwnProperty.call( this._typeSet, type.code ) ) {
			this._typeSet[ type.code ] = type.label || type.code;
			this._typeList.push( type.code );
		}
	};

	/**
	 * Adds the type filter control the first time a SECOND distinct type is observed —
	 * never before (spec: only when more than one type is present), never removed again
	 * once added (see the file docblock).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._maybeAddFilterControl = function() {
		if ( this._filterControlAdded || this._typeList.length <= 1 ) {
			return;
		}

		this._filterControlAdded = true;
		this._filterControl = this._buildFilterControl();
		this.map.controls.add( this._filterControl, { float: 'none', position: { left: '16px', top: '60px' } } );
	};

	/**
	 * Builds the type filter control: one button per distinct type (label from
	 * `type.label`, already `esc_html()`ed — see the file docblock's escaping rule) plus
	 * an "all types" entry labelled from `i18n.allTypes`.
	 *
	 * @returns {Object}
	 */
	WoodevYandexMapProvider.prototype._buildFilterControl = function() {
		var self = this;

		return buildDockedControl( this.ymaps, function() {
			var root = document.createElement( 'div' );

			root.className = 'woodev-pickup-filter';

			var allButton = document.createElement( 'button' );

			allButton.type = 'button';
			allButton.className = 'woodev-pickup-filter__item woodev-pickup-filter__item--active';
			allButton.textContent = text( self.config, 'allTypes' );
			allButton.addEventListener( 'click', function() {
				self._applyTypeFilter( null, root );
			} );
			root.appendChild( allButton );

			self._typeList.forEach( function( code ) {
				var button = document.createElement( 'button' );

				button.type = 'button';
				button.className = 'woodev-pickup-filter__item';
				button.setAttribute( 'data-type-code', code );
				button.innerHTML = safeField( self._typeSet[ code ] );
				button.addEventListener( 'click', function() {
					self._applyTypeFilter( code, root );
				} );
				root.appendChild( button );
			} );

			return { element: root, teardown: function() {} };
		} );
	};

	/**
	 * Applies a type filter selection: toggles the active button, sets each placemark's
	 * `visible` option, rebuilds the clusterer from the now-visible set, and refreshes the
	 * drawer to match.
	 *
	 * @param {string|null} code `null` selects "all types".
	 * @param {HTMLElement} root the filter control's root element.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyTypeFilter = function( code, root ) {
		var self = this;
		var buttons = root.querySelectorAll( '.woodev-pickup-filter__item' );

		for ( var i = 0; i < buttons.length; i++ ) {
			var isAll = ! buttons[ i ].hasAttribute( 'data-type-code' );
			var isActive = null === code ? isAll : buttons[ i ].getAttribute( 'data-type-code' ) === code;

			buttons[ i ].classList.toggle( 'woodev-pickup-filter__item--active', isActive );
		}

		var visible = [];

		Object.keys( this._placemarksById ).forEach( function( id ) {
			var placemark = self._placemarksById[ id ];
			var point = placemark.properties.get( 'point' );
			var matches = null === code || ( point.type && point.type.code === code );

			placemark.options.set( 'visible', matches );

			if ( matches ) {
				visible.push( placemark );
			}
		} );

		this.clusterer.removeAll();
		this.clusterer.add( visible );
		this._updateDrawer();
	};

	/**
	 * Builds the viewport-synced drawer control: a list, docked top-right, holding only
	 * the points currently visible in the map's viewport — recomputed on every
	 * `boundschange` via {@see WoodevYandexMapProvider#_updateDrawer}.
	 *
	 * @returns {Object}
	 */
	WoodevYandexMapProvider.prototype._buildDrawerControl = function() {
		var self = this;

		return buildDockedControl( this.ymaps, function() {
			var root = document.createElement( 'div' );

			root.className = 'woodev-pickup-drawer';

			if ( self._isMobileViewport() ) {
				root.classList.add( 'woodev-pickup-drawer--sheet' );
			}

			var header = document.createElement( 'div' );

			header.className = 'woodev-pickup-drawer__header';
			header.textContent = text( self.config, 'drawerTitle' );

			var list = document.createElement( 'div' );

			list.className = 'woodev-pickup-drawer__list';

			root.appendChild( header );
			root.appendChild( list );

			self._drawerRootEl = root;
			self._drawerListEl = list;
			self._renderDrawerItems( [] );

			return {
				element: root,
				teardown: function() {
					self._drawerRootEl = null;
					self._drawerListEl = null;
				},
			};
		} );
	};

	/**
	 * Recomputes the drawer's contents from the placemarks currently visible in the
	 * map's viewport, via `ymaps.geoQuery(...).searchInside(map)` — the same mechanism
	 * the reference implementation uses.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._updateDrawer = function() {
		if ( ! this.map || ! this.clusterer || ! this.ymaps ) {
			return;
		}

		var visible = [];

		this.ymaps.geoQuery( this.clusterer.getGeoObjects() ).searchInside( this.map ).each( function( geoObject ) {
			visible.push( geoObject );
		} );

		this._renderDrawerItems( visible );
	};

	/**
	 * Renders the drawer's list from a set of visible placemarks. A no-op when the drawer
	 * has not (yet, or any longer) built its DOM — see {@see buildDockedControl}'s async
	 * `getChildElement()` resolution and its `teardown()` clearing these refs.
	 *
	 * @param {Array} placemarks
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._renderDrawerItems = function( placemarks ) {
		if ( ! this._drawerListEl ) {
			return;
		}

		var self = this;
		var list = this._drawerListEl;

		list.innerHTML = '';

		( placemarks || [] ).forEach( function( placemark ) {
			var point = placemark.properties.get( 'point' );
			var item = document.createElement( 'button' );

			item.type = 'button';
			item.className = 'woodev-pickup-drawer__item';
			// Pre-escaped point fields — see the file docblock's ESCAPING section.
			item.innerHTML = '<span class="woodev-pickup-drawer__item-name">' + safeField( point.name ) + '</span>'
				+ '<span class="woodev-pickup-drawer__item-address">'
				+ safeField( point.short_address || point.address )
				+ '</span>';

			item.addEventListener( 'click', function() {
				// No separate `setCenter()` — `_openPlacemarkBalloon()` recentres via `setBounds()`
				// as part of taking the placemark out of its cluster, and a second camera command
				// issued alongside it only fights the first.
				self._openPlacemarkBalloon( placemark );

				var active = list.querySelectorAll( '.woodev-pickup-drawer__item--active' );

				for ( var i = 0; i < active.length; i++ ) {
					active[ i ].classList.remove( 'woodev-pickup-drawer__item--active' );
				}

				item.classList.add( 'woodev-pickup-drawer__item--active' );
			} );

			list.appendChild( item );
		} );
	};

	/**
	 * Renders one point's balloon body into `container` (the placemark's balloon element,
	 * OR — under `strategy: 'viewport'` — the SAME element re-rendered once a detail fetch
	 * resolves). See the file docblock's "VIEWPORT LAZY-DETAIL RE-RENDER" section for the
	 * `_balloonSeq` staleness guard. Deliberately independent of ymaps' own balloon
	 * open/close machinery — this method only ever touches the DOM node it is given, so it
	 * is directly unit-testable.
	 *
	 * `isDetail` MUST be false/omitted on every call site outside this method itself — it
	 * exists solely so the DETAIL re-render (the one this method issues to itself once
	 * `fetchDetails()` resolves) does not turn around and kick off ANOTHER `fetchDetails()`
	 * call. Without this guard the recursive self-call below would fire on every render,
	 * including the one IT just produced — an unbounded chain of `fetchDetails()` calls that
	 * never settles into a rendered balloon (caught the hard way: an early version hung the
	 * entire jest process, not just one test, because each link in the chain resolves via a
	 * microtask, and an unbounded microtask chain starves the event loop's macrotask queue —
	 * including jest's own per-test timeout timer — so nothing ever times out either).
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      point
	 * @param {boolean}     [isDetail] internal — true only for this method's own re-render
	 *                                 of an already-fetched detail point.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._renderBalloon = function( container, point, isDetail ) {
		var self = this;
		var config = this.config;
		var mySeq = ++this._balloonSeq;

		// Render INTO the `.woodev-pickup-balloon` root when one is present, not over it.
		// ymaps' `templateLayoutFactory` builds this layout's template — the root div — and
		// then hands `getElement()` back as the element CONTAINING it, not the root itself.
		// Writing `innerHTML` straight onto that container therefore destroys the very root
		// the template just created, leaving the balloon body parented to a bare, class-less
		// `<ymaps>` node. That is invisible to behaviour and to this method's own unit tests
		// (which pass a plain `div`, so there is no root to lose) but breaks STYLING outright:
		// `pickup.css` scopes the balloon's custom properties to `.woodev-pickup-balloon`, so
		// without the root the select CTA renders with no accent background and the
		// unselectable-reason warning loses its tint and rule — the two things a customer most
		// needs to see. Falling back to the container keeps direct callers working unchanged.
		var root = container.querySelector( '.woodev-pickup-balloon' ) || container;

		root.innerHTML = buildBalloonHtml( config, point );

		var selectButton = root.querySelector( '.woodev-pickup-balloon__select' );

		if ( selectButton ) {
			selectButton.addEventListener( 'click', function() {
				if ( selectButton.disabled ) {
					return;
				}

				self.emit( 'select', point );
			} );
		}

		if ( 'viewport' === config.strategy && ! isDetail ) {
			this.dataSource.fetchDetails( point.id ).then(
				function( detail ) {
					if ( self._destroyed || mySeq !== self._balloonSeq ) {
						return;
					}

					self._renderBalloon( container, detail, true );
				},
				function() {
					if ( self._destroyed || mySeq !== self._balloonSeq ) {
						return;
					}

					self._appendBalloonDetailsError( container, config );
				}
			);
		}
	};

	/**
	 * Appends the "details failed to load" banner to an already-rendered balloon, WITHOUT
	 * touching the CTA the list point already rendered — see the file docblock's
	 * "VIEWPORT LAZY-DETAIL RE-RENDER" section for why selection stays possible.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._appendBalloonDetailsError = function( container, config ) {
		if ( container.querySelector( '.woodev-pickup-balloon__error' ) ) {
			return;
		}

		// Resolve the same root {@see WoodevYandexMapProvider#_renderBalloon} renders into —
		// see its comment. Inserting into `container` directly would place the banner as a
		// SIBLING of the balloon root rather than inside it, putting it outside the scope
		// `pickup.css` declares the balloon's custom properties on, so the banner would lose
		// its colour exactly when it is the only thing explaining a failed detail fetch.
		var root = container.querySelector( '.woodev-pickup-balloon' ) || container;

		var banner = document.createElement( 'div' );

		banner.className = 'woodev-pickup-balloon__error';
		banner.textContent = text( config, 'detailsError' );
		root.insertBefore( banner, root.firstChild );
	};

	/**
	 * Renders a cluster balloon: a short list of the clustered points' names, each opening
	 * that point's own (full) balloon on click.
	 *
	 * @param {HTMLElement} container
	 * @param {Array}       geoObjects placemarks contained in the cluster.
	 * @returns {void}
	 */
	/**
	 * Opens one placemark's balloon, whether it is currently drawn on its own or folded into a
	 * cluster.
	 *
	 * `placemark.balloon.open()` works ONLY for a placemark the clusterer is currently drawing
	 * individually. A placemark folded into a cluster has no balloon of its own, so calling
	 * `.open()` on it throws inside ymaps (`Cannot read properties of null (reading
	 * 'getGlobalPixelCenter')`) and takes the whole click handler down with it — the drawer item
	 * then does nothing at all, with only a console error to show for it.
	 *
	 * Whether a given point is clustered depends on zoom and on how close its neighbours happen
	 * to be, so this is data- and viewport-dependent: the same drawer item can work at one zoom
	 * level and throw at another. That is exactly why it survived the bulk-strategy rig pass —
	 * the point clicked there happened to be unclustered.
	 *
	 * `getObjectState()` is ymaps' own documented answer: it reports whether the object is shown
	 * and whether it is clustered, and for a clustered one the cluster's `activeObject` selects
	 * which item its balloon shows.
	 *
	 * SEQUENCED against a slower-to-resolve EARLIER call: `mySeq` captures `_openSeq` at call
	 * time; the async continuation only opens the balloon if `_openSeq` is still `mySeq` when the
	 * move resolves. Without this, clicking drawer item A then quickly item B — both below max
	 * zoom — could see A's `setBounds()` resolve AFTER B's (animation distance/duration are not
	 * FIFO), opening A's balloon on top of the customer's actual, most recent choice. See the
	 * file docblock's "OPENING A PLACEMARK'S BALLOON IS SEQUENCED" section.
	 *
	 * @param {Object} placemark
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._openPlacemarkBalloon = function( placemark ) {
		var self = this;
		var mySeq = ++this._openSeq;

		// Already as deep as the map goes: nothing clusters at max zoom, so open directly.
		if ( this.map.getZoom() >= MAX_ZOOM ) {
			placemark.balloon.open();

			return;
		}

		// Collapse the bounds to the placemark's OWN coordinates. `checkZoomRange` then resolves
		// that degenerate box to the deepest zoom the map allows at that spot — which is what
		// takes the placemark out of its cluster — and awaiting the returned promise is what
		// guarantees the clusterer has finished re-drawing before the balloon is opened. This is
		// the reference implementation's own approach
		// (`plugins-reference/woocommerce-yandex-delivery/.../wc-yandex-delivery-widget-map.js`,
		// `handlePlacemarkSelect()`), and it is deterministic where polling the clusterer's state
		// after successive zoom steps is not.
		//
		// `useMapMargin` keeps the result inside the area left free by `map.margin.addArea()`, so
		// the balloon does not open underneath the drawer.
		this.map.setBounds(
			[ placemark.geometry.getCoordinates(), placemark.geometry.getCoordinates() ],
			{ checkZoomRange: true, zoomMargin: 0, useMapMargin: true }
		).then( function() {
			if ( self._destroyed || mySeq !== self._openSeq ) {
				return;
			}

			placemark.balloon.open();
		} );
	};

	WoodevYandexMapProvider.prototype._renderClusterBalloon = function( container, geoObjects ) {
		var self = this;

		container.innerHTML = '';

		var list = document.createElement( 'div' );

		list.className = 'woodev-pickup-cluster-balloon__list';

		( geoObjects || [] ).forEach( function( placemark ) {
			var point = placemark.properties.get( 'point' );
			var item = document.createElement( 'button' );

			item.type = 'button';
			item.className = 'woodev-pickup-cluster-balloon__item';
			item.innerHTML = safeField( point && point.name );
			item.addEventListener( 'click', function() {
				self._openPlacemarkBalloon( placemark );
			} );

			list.appendChild( item );
		} );

		container.appendChild( list );
	};

	/**
	 * Whether the viewport is currently at/below the mobile breakpoint.
	 *
	 * @returns {boolean}
	 */
	WoodevYandexMapProvider.prototype._isMobileViewport = function() {
		return 'number' === typeof window.innerWidth && window.innerWidth <= MOBILE_BREAKPOINT;
	};

	/**
	 * Binds the `resize` listener that keeps the drawer's bottom-sheet class in sync with
	 * the viewport width. Tracked on the instance so {@see destroy} can remove exactly this
	 * listener — see the file docblock's "DESTROY IS IDEMPOTENT" section.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._bindResizeListener = function() {
		var self = this;

		this._onResize = function() {
			if ( ! self._drawerRootEl ) {
				return;
			}

			self._drawerRootEl.classList.toggle( 'woodev-pickup-drawer--sheet', self._isMobileViewport() );
		};

		window.addEventListener( 'resize', this._onResize );
	};

	/**
	 * Appends the `i18n.loading` overlay node into `container` — see {@see init}'s own
	 * docblock for exactly when this is called. A no-op when `container` is gone (defensive;
	 * `init()` only ever calls this after building the map, so `container` is always set at
	 * that point) or when a loading node is already present (idempotent, though nothing in
	 * this file currently calls it twice without an intervening {@see _hideLoading}).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._showLoading = function() {
		if ( ! this.container || this._loadingEl ) {
			return;
		}

		var el = document.createElement( 'div' );

		el.className = 'woodev-pickup-map-loading';
		el.textContent = text( this.config, 'loading' );

		this.container.appendChild( el );
		this._loadingEl = el;
	};

	/**
	 * Removes the loading overlay node added by {@see _showLoading}, if any. Idempotent —
	 * safe to call when nothing is showing (the normal case for every code path except the
	 * very first initial-fetch settlement) and safe to call from {@see destroy} even when
	 * `init()` never finished, which is exactly what keeps the node from lingering on a
	 * torn-down provider.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._hideLoading = function() {
		if ( this._loadingEl && this._loadingEl.parentNode ) {
			this._loadingEl.parentNode.removeChild( this._loadingEl );
		}

		this._loadingEl = null;
	};

	/**
	 * Tears the provider down: detaches the `resize` listener, removes the clusterer,
	 * destroys the map, and resets every internal collection. Idempotent — a second call,
	 * or a call before `init()` has settled, is a safe no-op (every async continuation
	 * checks `_destroyed` before touching anything — see the file docblock).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.destroy = function() {
		if ( this._destroyed ) {
			return;
		}

		this._destroyed = true;

		// Never let the loading overlay outlive the provider — a destroy() racing an
		// in-flight initial fetch (see init()'s own docblock) must not leave it stuck in
		// the DOM once nothing is left to hide it later.
		this._hideLoading();

		if ( this._onResize ) {
			window.removeEventListener( 'resize', this._onResize );
			this._onResize = null;
		}

		if ( this.map ) {
			if ( this.clusterer ) {
				this.map.geoObjects.remove( this.clusterer );
			}

			try {
				this.map.destroy();
			} catch ( e ) {
				// Defensive — mirrors the reference implementation's own destroy() guard;
				// a map already torn down by ymaps itself must not fail this call.
			}
		}

		this.map = null;
		this.clusterer = null;
		this.ymaps = null;
		this.dataSource = null;
		this.container = null;
		this._drawerControl = null;
		this._drawerListEl = null;
		this._drawerRootEl = null;
		this._filterControl = null;
		this._filterControlAdded = false;
		this._placemarksById = {};
		this.knownIds = {};
		this._typeSet = {};
		this._typeList = [];
		this.handlers = { select: [], error: [] };
	};

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupMapProviders = window.WoodevPickupMapProviders || {};
		window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevYandexMapProvider;
	}

}() );
