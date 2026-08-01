/**
 * Woodev Yandex Map Provider — draws the map canvas ONLY: camera, markers, clustering.
 *
 * SP-5 presentation rework (D-3, D-4, D-5, D-7, D-11): the operator rejected the previous
 * version of this file, which owned a ymaps balloon, a viewport-synced drawer control and a
 * type-filter control — point information belongs in a side panel the framework itself
 * renders, not markup owned by one specific map library. Everything that used to live here —
 * the point card, the co-located tab bar, the search results, the type filter menu — now
 * lives in `pickup-panels.js`. This file's whole job is: build a ymaps map, put a marker per
 * GROUP on it via `ymaps.ObjectManager`, and move the camera. It never renders a point field.
 *
 * A "group" is `{ key, lat, lng, typeCode, size, points: [...] }` — several co-located points
 * (a PVZ and a postamat in the same building) folded into ONE marker by
 * `pickup-geo.js`'s `groupByPosition()`. This file receives GROUPS, never raw points, and
 * knows only each group's SIZE (for the count badge) — never a point's name, address or any
 * other display field.
 *
 * THIS FILE NO LONGER FETCHES ANYTHING ITSELF. The old version pulled points through an
 * injected `dataSource` (`fetchPoints`/`fetchDetails`) and decided when to call it. That is
 * now the caller's job (Task 20's mount wiring): this provider only reports WHERE the camera
 * is — `boundsChange( bbox )` under `strategy: 'viewport'`, once right after the initial
 * viewport resolves and again on every `boundschange` — and the caller decides when/how to
 * fetch and hands the result back through `setPoints( groups )`. Under `strategy: 'bulk'` no
 * bbox is ever emitted; the caller fetches once by `config.locality` on its own schedule, same
 * as before. BOTH strategies still register a `boundschange` listener, though: the panel's
 * "points currently in the viewport" list (`visibleChange`) has to track a camera the customer
 * is free to pan/zoom themselves under `bulk` too, even though nothing needs re-fetching there.
 *
 * PUBLIC SURFACE: `init( container, config )`, `setPoints( groups )`, `focusGroup( key )`,
 * `setTypeFilter( codes )`, `setMargin( open, width )`, `getFocusedKey()`,
 * `resolveAddress( displayName )`, `focusAddress( latLng, label )`, `clearAddress()`,
 * `on( event, cb )`, `destroy()`. Events out:
 * `pointClick( key )`, `boundsChange( bbox )`, `bboxTooWide()`, `visibleChange( keys )`,
 * `nothingNearby( { distanceMeters, name } )`, `searchResults( { points, addresses } )`,
 * `error( { code, message } )`. `bbox` is the flat `[lat1,lng1,lat2,lng2]` shape
 * `pickup-datasource.js`'s `serializePointsQuery()` expects (see {@see flattenBounds}).
 *
 * CONFIG is FLAT — the merge `pickup-mount.js`'s `buildProviderConfig()` builds from
 * `mapConfig` (`scriptUrl`, `ns`, `hasApiKey`, `lang`, `layers`, `copyrights`) plus
 * `strategy`/`locality`/`i18n`, and (Task 20) the plugin-level `defaultLocation`
 * (`{ center: [lat,lng], zoom }`, ALWAYS present — a required plugin argument),
 * `pointIcons` (`{ typeCode: { default, active } }`, `active` always filled),
 * `accentColor` and `searchNearestCount` (Task 19, D-6 — the PHP-side default of 3, filterable
 * server-side via `woodev_pickup_search_nearest_count`; see {@see focusAddress}). This file reads
 * all of these at the top level of `config` — never nested.
 *
 * TWO LESSONS THIS FILE HAS ALREADY TAUGHT (s46 — a browser found both, green tests did not):
 *
 * 1. YMAPS CAMERA MOVES ARE ASYNCHRONOUS. `map.setBounds()` animates and resolves once the
 *    move COMPLETES, not when it starts. Reading `map.getBounds()` right after calling
 *    `setBounds()` without awaiting its promise returns the PRE-move viewport — which once
 *    produced a planet-wide bbox the server's cap correctly refused, reporting "no points" for
 *    a locality that had them. This lesson recurred once already inside THIS rewrite, in
 *    {@see setPoints}'s own `bulk` camera fit: an un-awaited fit let `visibleChange` read the
 *    map's PRE-fit bounds, so a locality whose points sit outside the technical placeholder
 *    viewport reported an empty sidebar over a map that was actually full of pins — see that
 *    method's own comment. Every `setBounds()` call in this file is awaited. Two moves are
 *    also not guaranteed to resolve in call order (animation duration depends on distance), so
 *    concurrent camera commands need sequencing — see {@see focusGroup}'s `_focusSeq`.
 * 2. A PLACEMARK FOLDED INTO A CLUSTER HAS NO BALLOON OF ITS OWN, and whether a group is
 *    clustered depends on the current zoom, so the same group can work at one zoom and break
 *    at another. This file owns no balloon any more, so that specific crash is gone — but the
 *    DEGENERATE case survives: two groups sitting on IDENTICAL coordinates share one
 *    pixel-grid cell at EVERY zoom level, so `checkZoomRange` can never separate them and
 *    zooming is futile. {@see focusGroup} carries the guard for this (the "Russian Post"
 *    guard — spec §7.5): before collapsing the camera to a cluster's location, check whether
 *    every feature folded into it shares one coordinate, and skip the move entirely when it
 *    does. The check runs again AFTER the move resolves, since ymaps' own report of what is
 *    clustered can change once the camera settles.
 *
 * ICONS ARE AN HTML LAYOUT, NOT `iconLayout: 'default#image'` (D-5): a plain image marker
 * cannot show the group-count badge or express focused/unfocused state as a CSS class, so
 * every feature gets a custom `templateLayoutFactory` layout ({@see buildMarkerLayoutClass}).
 * ymaps cannot read pixel sizes from CSS and needs them for hit-testing, so `iconImageSize` /
 * `iconImageOffset` are passed alongside — {@see ICON_BOX} for the resting state,
 * {@see ICON_BOX_ACTIVE} for the group {@see focusGroup} last focused. If either box ever
 * disagrees with the CSS (`pickup.css`), clicks land in the wrong place — the CSS must use the
 * SAME pixel values. A group whose `typeCode` has no entry in `config.pointIcons` still gets a
 * marker — {@see WoodevYandexMapProvider#_renderMarker} adds a `--unknown` modifier class
 * instead of leaving an empty/broken `<img>`; it is never invisible or unclickable. D-5's full
 * contract is up to FOUR urls per type (default/active × the plugin's own choice of which it
 * actually supplies) — {@see focusGroup} writes `data-state="active"|"resting"` onto the
 * marker root AND swaps in `pointIcons[type].active` for the image, so a plugin that supplies
 * distinct images (the Yandex reference) gets a real image swap, and a plugin that supplies
 * only `default` (CDEK's own approach — `active` mirrors `default` server-side, see
 * `Pickup_Handler::normalized_point_icons()`) gets the SAME image drawn larger, both driven by
 * the one `data-state` attribute Task 21's CSS keys off.
 *
 * ADDRESS SEARCH (Task 19, D-6): the customer types THEIR OWN address — the search box's
 * placeholder is literally "Ваш адрес" — not a pickup-point search term. None of the three
 * reference implementations get this right: CDEK searches only the already-loaded point pool
 * (a home address matches nothing, so search almost never finds anything); Yandex and Russian
 * Post geocode the typed address and RECENTRE the camera on it, so a customer whose nearest point
 * is not already in frame sees an empty map and concludes there are none. This file does neither:
 * `ymaps.control.SearchControl` keeps its ENGINE (`search()`, `getResultsCount()`,
 * `showResult()`) and loses its CHROME — its default view is replaced by an inert
 * `templateLayoutFactory` layout ({@see _buildSearchControl}), so ymaps' own dropdown never
 * appears; the panels are the only results surface. `options.provider.geocode` is fully custom
 * ({@see _searchGeocodeProvider}): every keystroke filters the loaded pool for free via
 * `pickup-geo.matchPoints()` AND queries `ymaps.suggest()` for address suggestions.
 *
 * THIS FILE DOES NOT RENDER THE RESULTS OR KNOW THE PANELS EXIST (D-3: no map-library file
 * renders point information) — `{ points, addresses }` is handed to the `SearchControl` engine
 * as the geocode provider's RETURN value (so `search()`/`getResultsCount()`/`showResult()` keep
 * working) AND, separately, emitted as a `searchResults` EVENT carrying the identical object —
 * that event is the seam: Task 20's mount wires `provider.on( 'searchResults', panels.renderSearchResults )`,
 * so this file never calls into `pickup-panels.js` itself. `searchResults` fires on EVERY
 * resolved keystroke, including one that matches nothing — an empty `{ points: [], addresses: [] }`
 * still has to reach the panels, or a narrowed-down query leaves the PREVIOUS (now stale) results
 * on screen. `suggest()` is called on EVERY keystroke; `ymaps.geocode()` is called from this flow
 * EXACTLY ONCE, only when an address suggestion is actually picked ({@see resolveAddress}) —
 * geocoding on every keystroke would burn the merchant's API quota for no benefit a free-text
 * filter does not already give. Picking a POINT result opens its card (Task 20's job, via the
 * panels' own `searchPointPicked`); picking an ADDRESS result drops a "your address" pin and fits
 * the camera to the address PLUS the `config.searchNearestCount` nearest groups
 * ({@see focusAddress}) — NEVER to the address alone, which is exactly the "empty map" failure
 * this design avoids. `N` defaults to {@see DEFAULT_SEARCH_NEAREST_COUNT} and is deliberately a
 * geometry-based count, not a kilometre radius: network density varies between CITIES of one
 * carrier far more than between carriers, so a fixed per-plugin radius could never track it,
 * while fitting to N nearest points adapts automatically. When even the nearest group is farther
 * than {@see NEARBY_THRESHOLD_M}, no fit happens at all — `nothingNearby` is emitted instead,
 * naming that nearest group's own distance, so the customer sees an explicit "nothing here",
 * never a silently empty viewport.
 *
 * THE "YOUR ADDRESS" PIN IS NEVER A GROUP: it is a plain `ymaps.Placemark` added directly to
 * `map.geoObjects` ({@see _setAddressPin}), completely outside the `ObjectManager` every group
 * lives in — so it can never appear in the list panel (`setPoints()`/`_emitVisibleChange()` only
 * ever read `_groupsByKey`), the type filter (`setTypeFilter()` only ever touches
 * `objectManager`), or a `focusAddress()` nearest-N computation (which also only ever reads
 * `_groupsByKey`). The panels' own reset control (`«Сбросить»`) emits NO event of its own — it
 * just calls `setAnchor( null )` internally — so THIS file exposes {@see clearAddress} rather
 * than relying on one: it is the provider that owns BOTH the pin and the `searchResults` state,
 * so it is the provider that must guarantee neither outlives the search it belongs to —
 * {@see clearAddress} drops the pin AND emits an empty `searchResults`, in one call, so Task 20's
 * mount does not have to remember two separate "clear" calls.
 *
 * `objectManager.setFilter()`, NEVER A REBUILD, drives {@see setTypeFilter}: rebuilding the
 * manager would tear down and recreate every feature, losing the camera state this file just
 * fought to keep asynchronously correct and detaching every click binding along with it.
 *
 * `geoObjectOpenBalloonOnClick: false` / `clusterHasBalloon: false` are load-bearing, not
 * decorative: the panels own the point card's DOM now, and ymaps opening a balloon of its own
 * on top of it would fight the framework's own rendering.
 *
 * `pickup-geo.js` is enqueued alongside this file (Task 20 wires the script dependency) and
 * used for its bounds/colour arithmetic ({@see geo}) rather than this file re-implementing it.
 *
 * SCRIPT LOADING IS IDEMPOTENT ACROSS SESSIONS, NOT PER-INSTANCE — see {@see loadYmapsScript},
 * UNCHANGED from the previous version of this file: a retry always constructs a fresh provider
 * instance, so the loading promise is cached in MODULE scope, keyed by `config.ns`.
 *
 * DESTROY IS IDEMPOTENT AND SAFE BEFORE `init()` SETTLES: `_destroyed` is checked at every
 * async continuation this file adds (script load, initial viewport, every `focusGroup()` move)
 * before touching `this.map` — a `destroy()` racing an in-flight `init()`/`focusGroup()` never
 * throws or half-builds a map nobody wants any more.
 *
 * UMD-ish dual export (matches every sibling SP-5 frontend file):
 *   - Browser global: window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider
 *   - CommonJS:       module.exports = WoodevYandexMapProvider  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	// See pickup-panels.js's own docblock for the identical fallback — this file is required
	// (CommonJS, jest) before `window.WoodevPickupGeo` would exist in a browser, and loaded as a
	// plain <script> (global) in production, so both paths are supported.
	var geo = ( 'undefined' !== typeof window && window.WoodevPickupGeo ) ||
		( 'function' === typeof require ? require( './pickup-geo' ) : null );

	/** @type {number} technical placeholder center, used ONLY when config.defaultLocation is
	 *  somehow absent — the contract guarantees it is always present; see the file docblock. */
	var DEFAULT_CENTER = [ 0, 0 ];

	/** @type {number} technical placeholder zoom paired with {@see DEFAULT_CENTER}. */
	var DEFAULT_ZOOM = 2;

	/** @type {number} minimum zoom the map allows the customer to reach (Task 18, D-7). */
	var MIN_ZOOM = 8;

	/** @type {number} maximum zoom the map allows the customer to reach. */
	var MAX_ZOOM = 18;

	/**
	 * Maximum span, in degrees, either side of the viewport bbox may have before a
	 * `boundschange` is reported as too wide to fetch instead of fetched (Task 18, D-4). The
	 * server enforces the SAME cap — this file mirrors it so the customer sees "zoom in", not a
	 * false "no points here" for a locality that has them but was asked for at planet scale.
	 *
	 * @type {number}
	 */
	var BBOX_CAP_DEGREES = 10;

	/**
	 * Fallback cluster icon colour, used only when `config.accentColor` fails
	 * {@see geo.safeColor}'s validation — matches the reference implementation's own cluster
	 * colour exactly, so an unbranded install still gets a colour that reads as "map", not an
	 * error state.
	 *
	 * @type {string}
	 */
	var CLUSTER_ICON_COLOR_FALLBACK = '#FCE000';

	/**
	 * Marker hit-box for the RESTING state — must match `pickup.css`'s own dimensions for the
	 * `.woodev-pickup-marker` box exactly, or clicks land in the wrong place (see the file
	 * docblock's "ICONS ARE AN HTML LAYOUT" section).
	 *
	 * @type {{size: number[], offset: number[]}}
	 */
	var ICON_BOX = { size: [ 45, 45 ], offset: [ -22, -23 ] };

	/**
	 * Marker hit-box for the FOCUSED state (the group {@see WoodevYandexMapProvider#focusGroup}
	 * most recently focused) — a taller pin shape, matching the reference implementation's own
	 * active-marker treatment.
	 *
	 * @type {{size: number[], offset: number[]}}
	 */
	var ICON_BOX_ACTIVE = { size: [ 50, 70 ], offset: [ -25, -40 ] };

	/**
	 * Default number of nearest groups {@see focusAddress} fits the camera to when
	 * `config.searchNearestCount` is absent — the framework default for the PHP-side
	 * `woodev_pickup_search_nearest_count` filter (Task 19, D-6; see the file docblock's
	 * "ADDRESS SEARCH" section for why this is a geometry-based count, not a kilometre radius).
	 *
	 * @type {number}
	 */
	var DEFAULT_SEARCH_NEAREST_COUNT = 3;

	/**
	 * Distance, in metres, beyond which the nearest loaded group to a searched address is
	 * treated as "nothing nearby" — {@see focusAddress} emits `nothingNearby` instead of fitting
	 * the camera to a point so far away the map would read as broken (Task 19, D-6).
	 *
	 * @type {number}
	 */
	var NEARBY_THRESHOLD_M = 50000;

	/**
	 * Number of address suggestions requested from `ymaps.suggest()` per keystroke
	 * (Task 19, D-6).
	 *
	 * @type {number}
	 */
	var SUGGEST_RESULT_COUNT = 5;

	// -------------------------------------------------------------------------
	// Small pure helpers
	// -------------------------------------------------------------------------

	/**
	 * Flattens a ymaps bounds pair `[[lat1,lng1],[lat2,lng2]]` into the flat
	 * `[lat1,lng1,lat2,lng2]` array `pickup-datasource.js`'s `serializePointsQuery()` expects
	 * for `bounds`, and the same shape `boundsChange` emits.
	 *
	 * @param {Array} bounds `[[Number, Number], [Number, Number]]`.
	 * @returns {Array} `[Number, Number, Number, Number]`.
	 */
	function flattenBounds( bounds ) {
		return [ bounds[ 0 ][ 0 ], bounds[ 0 ][ 1 ], bounds[ 1 ][ 0 ], bounds[ 1 ][ 1 ] ];
	}

	/**
	 * Extracts a usable bounds pair from a `ymaps.geocode()` result's first hit, or null when
	 * the result is empty/malformed — the caller degrades to `config.defaultLocation` either
	 * way, never throws.
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

	/**
	 * Extracts the first hit's own coordinates from a `ymaps.geocode()` result — used by
	 * {@see WoodevYandexMapProvider#resolveAddress} to place the "your address" pin and anchor
	 * the nearest-N fit, as opposed to {@see extractGeocodeBounds}'s use of the SAME shape of
	 * result for the initial-viewport bounding box. Returns null for an empty/malformed result;
	 * the caller degrades to doing nothing rather than throwing or guessing a location.
	 *
	 * @param {Object} result
	 * @returns {Array|null} `[lat, lng]`, or null.
	 */
	function extractGeocodeCoordinates( result ) {
		var geoObjects = result && result.geoObjects;
		var first = geoObjects && 'function' === typeof geoObjects.get ? geoObjects.get( 0 ) : null;
		var geometry = first && first.geometry;
		var coordinates = geometry && 'function' === typeof geometry.getCoordinates
			? geometry.getCoordinates()
			: null;

		return Array.isArray( coordinates ) ? coordinates : null;
	}

	/**
	 * The first feature's coordinates in a `getObjectState().cluster` — the point this file
	 * collapses the camera bounds to when un-clustering a group (see {@see focusGroup}). Which
	 * specific member of the cluster anchors the zoom does not matter for un-clustering: a
	 * degenerate (zero-area) bbox with `checkZoomRange: true` forces the deepest zoom the map
	 * allows AT that spot, and once deep enough every genuinely distinct coordinate in the
	 * cluster separates, regardless of which one centred the move.
	 *
	 * @param {Object} cluster
	 * @returns {Array|null} `[lat, lng]`, or null when the cluster carries no features.
	 */
	function clusterAnchorCoordinates( cluster ) {
		var features = ( cluster && cluster.features ) || [];

		return features.length > 0 ? features[ 0 ].geometry.coordinates : null;
	}

	/**
	 * The "Russian Post" guard (spec §7.5): true when EVERY feature folded into `cluster`
	 * shares one coordinate — meaning no camera move, however deep, can ever separate them, so
	 * {@see focusGroup} must not try. False for an empty cluster (nothing to guard against).
	 *
	 * @param {Object} cluster
	 * @returns {boolean}
	 */
	function isSingleCoordinateCluster( cluster ) {
		var features = ( cluster && cluster.features ) || [];

		if ( 0 === features.length ) {
			return false;
		}

		var first = features[ 0 ].geometry.coordinates;

		return features.every( function( feature ) {
			var coords = feature.geometry.coordinates;

			return coords[ 0 ] === first[ 0 ] && coords[ 1 ] === first[ 1 ];
		} );
	}

	// -------------------------------------------------------------------------
	// Script loading — module-scope cache, unchanged from the previous version of this file
	// -------------------------------------------------------------------------

	/** @type {Object.<string, Promise>} pending/settled script loads, keyed by `config.ns`. */
	var scriptPromises = {};

	/**
	 * Loads `config.scriptUrl` and resolves with the ready ymaps namespace object
	 * (`window[config.ns]`). Idempotent across every provider instance and every session on the
	 * page — see the file docblock's "SCRIPT LOADING" section.
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
	// Marker icon layout — an HTML layout, not iconLayout: 'default#image' (D-5)
	// -------------------------------------------------------------------------

	/**
	 * Builds the per-feature marker layout class via `ymaps.templateLayoutFactory.createClass`.
	 * `build()`/`clear()` are kept THIN: all real rendering lives in
	 * {@see WoodevYandexMapProvider#_renderMarker}, independently unit-testable without going
	 * through ymaps' own layout machinery — matches the pattern the previous version of this
	 * file used for its balloon layout.
	 *
	 * @param {Object}                 ymaps
	 * @param {WoodevYandexMapProvider} provider
	 * @returns {Function}
	 */
	function buildMarkerLayoutClass( ymaps, provider ) {
		return ymaps.templateLayoutFactory.createClass(
			'<div class="woodev-pickup-marker"></div>',
			{
				build: function() {
					this.constructor.superclass.build.call( this );
					provider._renderMarker( this.getElement(), this.getData() );
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
		this.objectManager = null;
		this.config = null;
		this.container = null;

		/** @type {Function|null} the shared marker layout class — built once, in init(). */
		this._iconLayoutClass = null;

		this.handlers = {
			pointClick: [],
			boundsChange: [],
			bboxTooWide: [],
			visibleChange: [],
			nothingNearby: [],
			searchResults: [],
			error: [],
		};

		/** @type {Object.<string, Object>} the current groups, by key — see {@see setPoints}. */
		this._groupsByKey = {};

		/** @type {string|null} the group key {@see focusGroup} last successfully focused. */
		this._focusedKey = null;

		/** @type {Object|null} the `ymaps.control.SearchControl` built in init() — see
		 *  {@see _buildSearchControl}. */
		this.searchControl = null;

		/** @type {Object|null} the "your address" pin — a plain `ymaps.Placemark`, NEVER a
		 *  group inside the ObjectManager (see the file docblock's "ADDRESS SEARCH" section).
		 *  Null when no address search is currently active. */
		this._addressPin = null;

		/** @type {number} bumped on every {@see focusGroup} call — discards a stale
		 *  continuation when a later call's camera move resolves before an earlier one's. */
		this._focusSeq = 0;

		/** @type {*} the area id {@see setMargin} last got back from `map.margin.addArea()`,
		 *  or null when nothing is currently reserved — see that method's own docblock. */
		this._marginAreaId = null;

		this._destroyed = false;
	}

	/**
	 * Registers a handler for one of this provider's events — see the file docblock's
	 * "PUBLIC SURFACE" section for the full list.
	 *
	 * @param {string}   event
	 * @param {Function} cb
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.on = function( event, cb ) {
		if ( this.handlers[ event ] && 'function' === typeof cb ) {
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
	 * Initializes the map inside `container`. `hasApiKey === false` or a script/ready failure
	 * emits a `map_script` error and draws nothing; otherwise the map and the object manager are
	 * built and, under `strategy: 'viewport'`, the initial viewport is resolved and the
	 * `boundschange` watcher is armed. This file never calls `setPoints()` itself — the caller
	 * (Task 20's mount) does, once it has fetched the groups for whatever viewport/locality this
	 * provider reports.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config the MERGED provider config — see the file docblock.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.init = function( container, config ) {
		var self = this;

		this.container = container;
		this.config = config || {};

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
				self._buildObjectManager();
				self._buildSearchControl();

				return self._resolveInitialViewport();
			},
			function() {
				if ( ! self._destroyed ) {
					self.emit( 'error', { code: 'map_script', message: '' } );
				}
			}
		).then( function() {
			if ( self._destroyed || ! self.map ) {
				return;
			}

			if ( 'viewport' === self.config.strategy ) {
				// Fire once for the viewport just resolved, THEN start listening — in that
				// order, so this initial call is never immediately followed by a redundant
				// second one from a listener that was not registered yet.
				self._checkAndEmitBounds();
				self.map.events.add( 'boundschange', function() {
					self._checkAndEmitBounds();
				} );

				return;
			}

			// bulk: nothing to (re)fetch on pan/zoom — the whole locality is already loaded —
			// but the panel's own "points currently in the viewport" list must still track a
			// camera the customer is free to move themselves. See the file docblock.
			self.map.events.add( 'boundschange', function() {
				self._emitVisibleChange();
			} );
		} );
	};

	/**
	 * Builds the map and adds its custom tile layers/copyrights (D-8) — everything that does
	 * not depend on point data.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildMap = function() {
		var ymaps = this.ymaps;
		var config = this.config;
		var defaultLocation = config.defaultLocation || { center: DEFAULT_CENTER, zoom: DEFAULT_ZOOM };

		this.map = new ymaps.Map(
			this.container,
			{ center: defaultLocation.center, zoom: defaultLocation.zoom, controls: [] },
			{ suppressMapOpenBlock: true, minZoom: MIN_ZOOM, maxZoom: MAX_ZOOM }
		);

		this.map.controls.add( new ymaps.control.ZoomControl(), { position: { left: 70, bottom: 70 } } );

		this._addLayers( config.layers );
		this._addCopyrights( config.copyrights );
	};

	/**
	 * Adds the plugin's optional custom tile layers (D-8) — an arbitrary ymaps layer descriptor
	 * `{ url, projection }`; `projection`, when a recognised name (e.g. `'sphericalMercator'`),
	 * resolves to the matching `ymaps.projection.*` object, mirroring the reference
	 * implementation's own `map.layers.add( new ym.Layer( url, { projection } ) )` call.
	 *
	 * @param {Array} layers
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._addLayers = function( layers ) {
		var self = this;

		( layers || [] ).forEach( function( layer ) {
			if ( ! layer || 'string' !== typeof layer.url ) {
				return;
			}

			var options = {};
			var projection = self.ymaps.projection;

			if ( 'string' === typeof layer.projection && projection && projection[ layer.projection ] ) {
				options.projection = projection[ layer.projection ];
			}

			self.map.layers.add( new self.ymaps.Layer( layer.url, options ) );
		} );
	};

	/**
	 * Adds the plugin's optional custom map copyright/attribution strings (D-8) via ymaps' own
	 * `copyrights.add()` — never `innerHTML`; these are plugin-author-supplied construction
	 * values, not customer input, but this is still ymaps' own attribution surface, not ours.
	 *
	 * @param {Array} copyrights
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._addCopyrights = function( copyrights ) {
		var self = this;

		( copyrights || [] ).forEach( function( text ) {
			if ( 'string' === typeof text ) {
				self.map.copyrights.add( text );
			}
		} );
	};

	/**
	 * Builds the `ObjectManager` every group becomes one feature of (D-4, D-11). Filtering
	 * (D-10) goes through `setFilter()`, never a rebuild — see {@see setTypeFilter}.
	 * `geoObjectOpenBalloonOnClick`/`clusterHasBalloon` are both `false`: the panels own the
	 * point card's DOM, ymaps must not open anything of its own on top of it.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildObjectManager = function() {
		var self = this;
		var clusterColor = geo.safeColor( this.config.accentColor, CLUSTER_ICON_COLOR_FALLBACK );

		this._iconLayoutClass = buildMarkerLayoutClass( this.ymaps, this );

		this.objectManager = new this.ymaps.ObjectManager( {
			clusterize: true,
			clusterIconColor: clusterColor,
			geoObjectOpenBalloonOnClick: false,
			clusterHasBalloon: false,
		} );

		this.objectManager.objects.events.add( 'click', function( e ) {
			self.emit( 'pointClick', e.get( 'objectId' ) );
		} );

		this.map.geoObjects.add( this.objectManager );
	};

	/**
	 * Builds the address-search control (Task 19, D-6). `ymaps.control.SearchControl` keeps its
	 * ENGINE (`search()`, `getResultsCount()`, `showResult()`) and loses its default CHROME: the
	 * panels own the results markup (`renderSearchResults()`), so the control's own view is
	 * replaced with an inert `templateLayoutFactory` layout that renders nothing of its own.
	 * `options.provider.geocode` is fully custom ({@see _searchGeocodeProvider}), never ymaps'
	 * own default (address-only) provider — see the file docblock's "ADDRESS SEARCH" section.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildSearchControl = function() {
		var self = this;
		var inertLayout = this.ymaps.templateLayoutFactory.createClass( '<div></div>', {} );

		this.searchControl = new this.ymaps.control.SearchControl( {
			provider: {
				geocode: function( request, options ) {
					return self._searchGeocodeProvider( request, options );
				},
			},
			layout: inertLayout,
			resultsLayout: inertLayout,
			noPlacemark: true,
		} );

		this.map.controls.add( this.searchControl );
	};

	/**
	 * The `SearchControl`'s custom geocode provider (Task 19, D-6): matches `request` against
	 * the already-loaded point pool via `pickup-geo.matchPoints()` — instant, free, no network —
	 * AND queries `ymaps.suggest()` for address suggestions, returned together for the
	 * `SearchControl` engine (`search()`/`getResultsCount()`/`showResult()` — kept, never
	 * reimplemented here) to consume. `suggest()` only, NEVER `geocode()`, from this path — see
	 * the file docblock's "ADDRESS SEARCH" section for why: geocoding on every keystroke would
	 * burn the merchant's quota, and a suggestion is resolved to real coordinates exactly once,
	 * on selection, by {@see resolveAddress}.
	 *
	 * ALSO emits `searchResults` with the SAME `{ points, addresses }` object — this is the seam
	 * to the panels (Task 20's mount wires `provider.on( 'searchResults', panels.renderSearchResults )`),
	 * kept as an event rather than a direct call so this file stays ignorant of the panels'
	 * existence (D-3: no map-library file renders point information). Both the `resultsLayout`
	 * (an inert `templateLayoutFactory` layout, see {@see _buildSearchControl}) and this event
	 * exist for the SAME reason: ymaps' own dropdown must never appear, because the panels are
	 * the only results surface. Emitted EVERY time this resolves, including a query that matches
	 * nothing — an empty `{ points: [], addresses: [] }` must still reach the panels, or a
	 * narrowed-down search leaves the PREVIOUS (now stale) results on screen.
	 *
	 * `boundedBy` biases the suggestions to the map's CURRENT viewport — read fresh on every
	 * call, since the customer may have panned between keystrokes — so a customer in Kazan is not
	 * offered Moscow streets. Simply omitted before the map has ever resolved a viewport of its
	 * own, rather than passing an undefined/degenerate box.
	 *
	 * @param {string} request free-text query, as typed.
	 * @returns {Promise<{points: Array, addresses: Array}>}
	 */
	WoodevYandexMapProvider.prototype._searchGeocodeProvider = function( request ) {
		var self = this;
		var matches = geo.matchPoints( this._allPoints(), request );
		var suggestOptions = { results: SUGGEST_RESULT_COUNT };

		if ( this.map ) {
			suggestOptions.boundedBy = this.map.getBounds();
		}

		return this.ymaps.suggest( request, suggestOptions ).then( function( addresses ) {
			var results = { points: matches, addresses: addresses || [] };

			self.emit( 'searchResults', results );

			return results;
		} );
	};

	/**
	 * Flattens every currently-loaded group's points into one array — the pool
	 * {@see _searchGeocodeProvider} searches. Rebuilt on every call rather than cached: the pool
	 * changes on every {@see setPoints}, and this runs once per keystroke, not once per point.
	 *
	 * @returns {Array}
	 */
	WoodevYandexMapProvider.prototype._allPoints = function() {
		var groupsByKey = this._groupsByKey;
		var points = [];

		Object.keys( groupsByKey ).forEach( function( key ) {
			points = points.concat( groupsByKey[ key ].points || [] );
		} );

		return points;
	};

	/**
	 * Resolves the map's initial viewport for `strategy: 'viewport'` (Task 18, D-7) — a no-op
	 * for `strategy: 'bulk'`, which fits the camera to whatever {@see setPoints} is first called
	 * with instead (see that method). A known `config.locality` is geocoded and its bounds
	 * APPLIED (awaited — see the file docblock's first lesson); an empty locality, or a geocode
	 * that resolves nothing, falls back to `config.defaultLocation` via `map.setCenter()`.
	 * Neither fallback path emits an `error` — an unresolved geocode is not a broken map, just a
	 * wider first viewport.
	 *
	 * `config.locality` MUST be a geocodable place name, not an opaque carrier code —
	 * `ymaps.geocode()` is a free-text geocoder; see `pickup-mount.js`'s own
	 * `buildProviderConfig()` docblock for the field-source contract this relies on.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._resolveInitialViewport = function() {
		var self = this;

		if ( 'viewport' !== this.config.strategy ) {
			return Promise.resolve();
		}

		var locality = this.config.locality;

		if ( ! locality ) {
			this._applyDefaultLocation();

			return Promise.resolve();
		}

		return this.ymaps.geocode( locality ).then(
			function( result ) {
				if ( self._destroyed ) {
					return undefined;
				}

				var bounds = extractGeocodeBounds( result );

				if ( ! bounds ) {
					self._applyDefaultLocation();

					return undefined;
				}

				// setBounds() is ASYNCHRONOUS — always RETURNED/awaited, never fire-and-forgotten.
				// See the file docblock's first lesson: dropping this return lets the very next
				// step (emitting boundsChange from the PRE-move viewport) run before the camera
				// has actually moved.
				return self.map.setBounds( bounds, { checkZoomRange: true } );
			},
			function() {
				if ( ! self._destroyed ) {
					self._applyDefaultLocation();
				}
			}
		);
	};

	/**
	 * Centres the map on `config.defaultLocation` — the shared fallback for every "could not
	 * resolve a real viewport" case under `strategy: 'viewport'` (see {@see _resolveInitialViewport}).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyDefaultLocation = function() {
		var location = this.config.defaultLocation;

		if ( location ) {
			this.map.setCenter( location.center, location.zoom );
		}
	};

	/**
	 * Checks the map's CURRENT bounds against the server's own bbox cap (D-4) and emits
	 * exactly one of `boundsChange`/`bboxTooWide` accordingly — called once for the initial
	 * viewport and again on every `boundschange`, under `strategy: 'viewport'` only.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._checkAndEmitBounds = function() {
		var bounds = this.map.getBounds();
		var latSpan = Math.abs( bounds[ 1 ][ 0 ] - bounds[ 0 ][ 0 ] );
		var lngSpan = Math.abs( bounds[ 1 ][ 1 ] - bounds[ 0 ][ 1 ] );

		if ( latSpan > BBOX_CAP_DEGREES || lngSpan > BBOX_CAP_DEGREES ) {
			this.emit( 'bboxTooWide', null );

			return;
		}

		this.emit( 'boundsChange', flattenBounds( bounds ) );
	};

	/**
	 * Replaces the drawn groups with `groups` — a full rebuild (`removeAll()` then `add()`),
	 * never an incremental diff: the caller (Task 20's mount) is the one that decides what the
	 * current full set is (including any cross-fetch de-duplication), so this file always draws
	 * exactly what it was handed.
	 *
	 * Under `strategy: 'bulk'` a non-empty set also fits the camera to it via {@see geo}'s
	 * `boundsFor()` — the ONLY place this file fits the camera to loaded data, matching the
	 * previous version's `bulk` behaviour. That fit is AWAITED before `visibleChange` is
	 * emitted — see the file docblock's first lesson: emitting it from the map's PRE-fit bounds
	 * would report an empty (or stale) visible set for a locality whose points sit outside the
	 * technical placeholder viewport, and `bulk` has no fetch-driven refresh cycle to correct it
	 * later the way `viewport` does. A rebuild also resets every feature back to its resting
	 * options/properties, so a group that is STILL the focused one after this call gets its
	 * active state re-applied; a focused group that is GONE from the new set clears
	 * `getFocusedKey()` instead — see {@see _setMarkerState}.
	 *
	 * @param {Array} groups
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.setPoints = function( groups ) {
		var self = this;
		var list = groups || [];

		this._groupsByKey = {};

		var features = list.map( function( group ) {
			self._groupsByKey[ group.key ] = group;

			return self._buildFeature( group );
		} );

		this.objectManager.removeAll();
		this.objectManager.add( features );

		if ( this._focusedKey && ! Object.prototype.hasOwnProperty.call( this._groupsByKey, this._focusedKey ) ) {
			// A refetch that no longer contains the currently focused group must not leave a
			// stale key behind — the caller has nothing left to visually mark as active.
			this._focusedKey = null;
		} else if ( this._focusedKey ) {
			this._setMarkerState( this._focusedKey, 'active' );
		}

		if ( 'bulk' === this.config.strategy && list.length > 0 ) {
			var anchor = [ list[ 0 ].lat, list[ 0 ].lng ];

			// setBounds() is ASYNCHRONOUS — awaited, exactly like _resolveInitialViewport()'s
			// own call. See the docblock comment above and the file docblock's first lesson.
			this.map.setBounds( geo.boundsFor( anchor, list ), { checkZoomRange: true } ).then( function() {
				if ( self._destroyed ) {
					return;
				}

				self._emitVisibleChange();
			} );

			return;
		}

		this._emitVisibleChange();
	};

	/**
	 * Builds a feature's `properties` bag from a group — shared by {@see _buildFeature} (the
	 * initial `add()`) and {@see _setMarkerState} (an in-place `setObjectProperties()` update),
	 * so the two never drift into building this shape two different ways. `state` defaults to
	 * `'resting'`; {@see _setMarkerState} overwrites it when re-sending this same shape for a
	 * focus change.
	 *
	 * `iconHref`/`iconHrefActive` are resolved from `config.pointIcons[group.typeCode]` — both
	 * empty for an unrecognised type, in which case {@see _renderMarker} still draws a
	 * (modifier-classed) marker, never an invisible/broken one. `active` is guaranteed filled
	 * server-side whenever the type is known at all (mirroring `default` when the plugin
	 * supplied only one image — D-5, `Pickup_Handler::normalized_point_icons()`), so
	 * `iconHrefActive` is never a broken/empty URL for a KNOWN type.
	 *
	 * @param {Object} group
	 * @returns {Object}
	 */
	WoodevYandexMapProvider.prototype._buildProperties = function( group ) {
		var icons = ( this.config.pointIcons && this.config.pointIcons[ group.typeCode ] ) || null;

		return {
			groupSize: group.size,
			typeCode: group.typeCode,
			iconHref: icons ? icons.default : '',
			iconHrefActive: icons ? icons.active : '',
			state: 'resting',
		};
	};

	/**
	 * Builds one ObjectManager feature for a group. `options.iconImageHref` is deliberately
	 * never set anywhere in this file — see the file docblock's "ICONS ARE AN HTML LAYOUT"
	 * section.
	 *
	 * @param {Object} group
	 * @returns {Object} a GeoJSON-ish ObjectManager feature.
	 */
	WoodevYandexMapProvider.prototype._buildFeature = function( group ) {
		return {
			type: 'Feature',
			id: group.key,
			geometry: { type: 'Point', coordinates: [ group.lat, group.lng ] },
			properties: this._buildProperties( group ),
			options: {
				iconLayout: this._iconLayoutClass,
				iconImageSize: ICON_BOX.size,
				iconImageOffset: ICON_BOX.offset,
			},
		};
	};

	/**
	 * Renders one marker's DOM: an `<img>` for the icon matching the CURRENT `state`
	 * (`iconHrefActive` when `'active'`, `iconHref` otherwise — omitted, with a `--unknown`
	 * modifier class, when that URL is empty), plus a count badge for a group of more than one
	 * point. `data-state` is written onto the root so Task 21's CSS can key off
	 * `[data-state="active"]` for the size/style change {@see ICON_BOX_ACTIVE}'s hit-box
	 * already reserves room for (D-5). Deliberately independent of ymaps' own layout `build()`
	 * machinery — directly unit-testable, matching the pattern the previous version of this
	 * file used for its balloon body.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      data the layout's `getData()` result (`.properties` is a ymaps
	 *                            get/set data manager).
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._renderMarker = function( container, data ) {
		var properties = data.properties;
		var groupSize = properties.get( 'groupSize' );
		var state = properties.get( 'state' ) || 'resting';
		var isActive = 'active' === state;
		var iconHref = isActive ? properties.get( 'iconHrefActive' ) : properties.get( 'iconHref' );
		var root = container.querySelector( '.woodev-pickup-marker' ) || container;
		var isGroup = groupSize > 1;

		root.setAttribute( 'data-state', state );
		root.classList.toggle( 'woodev-pickup-marker--group', isGroup );
		root.classList.toggle( 'woodev-pickup-marker--unknown', ! iconHref );
		root.innerHTML = '';

		if ( iconHref ) {
			var img = document.createElement( 'img' );

			img.className = 'woodev-pickup-marker__image';
			img.src = iconHref;
			img.alt = '';
			root.appendChild( img );
		}

		if ( isGroup ) {
			var badge = document.createElement( 'span' );

			badge.className = 'woodev-pickup-marker__badge';
			badge.textContent = String( groupSize );
			root.appendChild( badge );
		}
	};

	/**
	 * Emits `visibleChange` with the keys of every currently-loaded group whose position falls
	 * inside the map's CURRENT bounds — a plain point-in-rectangle test against
	 * {@see WoodevYandexMapProvider#_groupsByKey}, not a query against ymaps' own object model
	 * (ObjectManager exposes no equivalent of the previous version's `geoQuery(...).searchInside()`
	 * over a plain Clusterer). Called after every {@see setPoints}.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._emitVisibleChange = function() {
		var bounds = this.map.getBounds();
		var minLat = Math.min( bounds[ 0 ][ 0 ], bounds[ 1 ][ 0 ] );
		var maxLat = Math.max( bounds[ 0 ][ 0 ], bounds[ 1 ][ 0 ] );
		var minLng = Math.min( bounds[ 0 ][ 1 ], bounds[ 1 ][ 1 ] );
		var maxLng = Math.max( bounds[ 0 ][ 1 ], bounds[ 1 ][ 1 ] );
		var groupsByKey = this._groupsByKey;
		var keys = [];

		Object.keys( groupsByKey ).forEach( function( key ) {
			var group = groupsByKey[ key ];

			if ( group.lat >= minLat && group.lat <= maxLat && group.lng >= minLng && group.lng <= maxLng ) {
				keys.push( key );
			}
		} );

		this.emit( 'visibleChange', keys );
	};

	/**
	 * Filters the drawn groups by type (D-10) via `objectManager.setFilter()` — NEVER a
	 * rebuild; see the file docblock. `codes` of `null`/an empty array clears the filter (every
	 * group matches).
	 *
	 * @param {Array|null} codes `type.code`s to show, or `null`/empty for "all types".
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.setTypeFilter = function( codes ) {
		var list = Array.isArray( codes ) && codes.length > 0 ? codes : null;

		this.objectManager.setFilter( function( objectId, object ) {
			if ( ! list ) {
				return true;
			}

			var properties = object && object.properties;
			var typeCode = properties ? properties.typeCode : undefined;

			return -1 !== list.indexOf( typeCode );
		} );
	};

	/**
	 * Reserves (or releases) the screen area the framework's own sidebar panel covers, via
	 * ymaps' native `map.margin.addArea()`/`removeArea()` (Task 20) — never a plain
	 * `map.margin = [...]` array assignment, which is not this API's shape (see ADR-010 and
	 * the design spec's own sidebar geometry: `map.margin.addArea({ right: <width>, top: 0,
	 * height: '100%' })`). Task 20's mount calls this from the panels' own `listToggle`
	 * event; every `setBounds()` camera move in this file that passes `useMapMargin: true`
	 * (see {@see focusGroup}) already reads whatever THIS method most recently reserved, so
	 * an un-clustered point ends up clear of the open panel instead of centred underneath it.
	 *
	 * The previous reservation, if any, is always released FIRST — opening twice in a row,
	 * or closing when nothing is reserved, never leaks a stale area. A no-op before `init()`
	 * has built a map, or once `destroy()` has torn it down (`this.map`/`this.map.margin` is
	 * then null/absent) — mirrors every other post-destroy guard in this file.
	 *
	 * @param {boolean} open  whether the sidebar panel is now open.
	 * @param {number}  width the panel's current width, in CSS pixels — ignored when `open`
	 *                        is false.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.setMargin = function( open, width ) {
		if ( ! this.map || ! this.map.margin ) {
			return;
		}

		if ( null !== this._marginAreaId ) {
			this.map.margin.removeArea( this._marginAreaId );
			this._marginAreaId = null;
		}

		if ( open ) {
			this._marginAreaId = this.map.margin.addArea( { right: width, top: 0, height: '100%' } );
		}
	};

	// -------------------------------------------------------------------------
	// Address search (Task 19, D-6) — see the file docblock's "ADDRESS SEARCH" section
	// -------------------------------------------------------------------------

	/**
	 * Resolves a chosen address SUGGESTION to real coordinates — the ONLY place this file calls
	 * `ymaps.geocode()` from the search flow, and it does so EXACTLY ONCE per selection (see the
	 * file docblock). `displayName` is the suggestion's own text — the caller (Task 20's mount)
	 * holds the `addresses` array {@see _searchGeocodeProvider} returned and passes back the
	 * entry the customer picked. An empty/malformed geocode result is a silent no-op, matching
	 * every other "geocode degrades quietly" path in this file (see
	 * {@see _resolveInitialViewport}) — no camera move, no pin, no `nothingNearby`.
	 *
	 * @param {string} displayName
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.resolveAddress = function( displayName ) {
		var self = this;

		return this.ymaps.geocode( displayName ).then( function( result ) {
			if ( self._destroyed ) {
				return undefined;
			}

			var coordinates = extractGeocodeCoordinates( result );

			if ( ! coordinates ) {
				return undefined;
			}

			return self.focusAddress( coordinates, displayName );
		} );
	};

	/**
	 * Frames the map on a resolved address: drops the "your address" pin
	 * ({@see _setAddressPin}) and fits the camera to the address PLUS the
	 * `config.searchNearestCount` nearest groups (default {@see DEFAULT_SEARCH_NEAREST_COUNT})
	 * — NEVER to the address alone; see the file docblock's "ADDRESS SEARCH" section for why.
	 * When the nearest group is farther than {@see NEARBY_THRESHOLD_M}, no fit happens at all —
	 * `nothingNearby` is emitted instead, naming that nearest group's own distance and
	 * (already-`esc_html()`-escaped) name, so the customer sees an explicit "nothing here" rather
	 * than a silently empty viewport. With NO groups currently loaded, this is a no-op beyond
	 * dropping the pin — there is nothing to fit to or report as "nearest".
	 *
	 * The nearest-N computation reads ONLY the currently loaded groups ({@see _groupsByKey}) —
	 * the address pin itself is never a candidate; see {@see _setAddressPin}'s own docblock.
	 *
	 * `setBounds()` is ASYNCHRONOUS — this method RETURNS it directly, exactly like
	 * {@see _resolveInitialViewport}'s own successful branch, so a caller that awaits this
	 * promise sees the POST-fit camera, never the pre-fit one (the file docblock's first lesson).
	 *
	 * @param {number[]} latLng `[lat, lng]`, the resolved address location.
	 * @param {string}   label  the address text, used only to place the pin.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusAddress = function( latLng, label ) {
		this._setAddressPin( latLng, label );

		var groupsByKey = this._groupsByKey;
		var groups = Object.keys( groupsByKey ).map( function( key ) {
			return groupsByKey[ key ];
		} );
		var count = 'number' === typeof this.config.searchNearestCount
			? this.config.searchNearestCount
			: DEFAULT_SEARCH_NEAREST_COUNT;
		var nearestGroups = geo.nearest( groups, latLng, count );

		if ( 0 === nearestGroups.length ) {
			return Promise.resolve();
		}

		var closest = nearestGroups[ 0 ];
		var closestDistance = geo.distanceMeters( latLng, [ closest.lat, closest.lng ] );

		if ( closestDistance > NEARBY_THRESHOLD_M ) {
			var closestPoint = closest.points && closest.points[ 0 ];

			// `key` (Task 20): lets the mount focus/open THIS exact group when the
			// customer accepts the "show it anyway" offer — the group's own identity
			// token, never its (display-only, non-unique) name. See
			// pickup-panels.js's own note on `showNearestRequested`.
			this.emit( 'nothingNearby', {
				key: closest.key,
				distanceMeters: closestDistance,
				name: ( closestPoint && closestPoint.name ) || '',
			} );

			return Promise.resolve();
		}

		// setBounds() is ASYNCHRONOUS — awaited (returned), exactly like every other camera
		// move in this file. See the file docblock's first lesson.
		return this.map.setBounds( geo.boundsFor( latLng, nearestGroups ), { checkZoomRange: true } );
	};

	/**
	 * Drops (or moves) the "your address" pin — a plain `ymaps.Placemark` added directly to
	 * `map.geoObjects`, NEVER a feature inside the `ObjectManager`: unlike a group, it must never
	 * appear in the list panel ({@see setPoints}/{@see _emitVisibleChange} only ever read
	 * `_groupsByKey`), the type filter (`setTypeFilter()` only ever touches `objectManager`), or
	 * the nearest-N computation ({@see focusAddress} only ever reads `_groupsByKey` too). The
	 * previous pin, if any, is removed first via {@see _removeAddressPin} — one address search
	 * replaces the last, it never accumulates pins from earlier searches.
	 *
	 * Deliberately calls {@see _removeAddressPin}, NOT the public {@see clearAddress}: the latter
	 * also emits an EMPTY `searchResults` (see its own docblock), which must fire only when the
	 * customer actually clears a search, never as a side effect of PICKING one — this method runs
	 * on every successful pick.
	 *
	 * @param {number[]} latLng `[lat, lng]`.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._setAddressPin = function( latLng ) {
		this._removeAddressPin();

		if ( ! this.map || ! this.ymaps || 'function' !== typeof this.ymaps.Placemark ) {
			return;
		}

		this._addressPin = new this.ymaps.Placemark( latLng, {}, {} );
		this.map.geoObjects.add( this._addressPin );
	};

	/**
	 * Removes the "your address" pin from the map, if one is currently shown, and forgets it —
	 * the shared primitive {@see _setAddressPin} (replacing a pin) and {@see clearAddress}
	 * (clearing the search entirely) both build on. Idempotent: a call with no pin currently
	 * shown is a safe no-op.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._removeAddressPin = function() {
		if ( this._addressPin && this.map ) {
			this.map.geoObjects.remove( this._addressPin );
		}

		this._addressPin = null;
	};

	/**
	 * Clears the address search entirely: removes the "your address" pin ({@see _removeAddressPin})
	 * AND emits an EMPTY `searchResults` (`{ points: [], addresses: [] }`). The panels' own reset
	 * control (`«Сбросить»`) emits NO event of its own — it only calls `setAnchor( null )`
	 * internally (see the file docblock's "ADDRESS SEARCH" section) — so THIS method is what Task
	 * 20's mount wiring calls when the customer clears the search: the provider is the sole
	 * producer of BOTH the pin and the `searchResults` state ({@see _searchGeocodeProvider}), so
	 * the provider is the one that must guarantee neither outlives the search it belongs to — a
	 * caller that only dropped the pin would still leave stale search rows on screen. Idempotent:
	 * a call with nothing currently shown (no pin, no prior results) is a safe no-op beyond the
	 * unconditional `searchResults` emit, which every caller can rely on firing every time.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.clearAddress = function() {
		this._removeAddressPin();

		this.emit( 'searchResults', { points: [], addresses: [] } );
	};

	/**
	 * Focuses group `key`: marks it visually active (swaps its icon hit-box to
	 * {@see ICON_BOX_ACTIVE}, its icon image to `iconHrefActive`, and `data-state` to
	 * `'active'` — see {@see _setMarkerState} — reverting the previously focused group, if any,
	 * back to resting) and, when it is currently folded into a ymaps cluster, moves the camera
	 * to un-cluster it. The move is skipped — focus still applies directly, exactly like the
	 * `MAX_ZOOM` case below — when every feature in that cluster shares one coordinate, since no
	 * move could ever separate them (the "Russian Post" guard, spec §7.5; see the file
	 * docblock's second lesson), or when the map is ALREADY at `MAX_ZOOM`, since a group cannot
	 * be zoomed in on any further — a pointless camera command the customer would only see as a
	 * stutter. `setBounds()` is called with the exact options spec §7.5 gives: `zoomMargin: 0`
	 * and `useMapMargin: true` keep the un-clustered point inside the area the panels leave free
	 * via `map.margin`, so it does not end up centred underneath the open sidebar where the
	 * customer cannot see it.
	 *
	 * `attemptedMove` gates the POST-move re-check: it only matters (and only runs) when a real
	 * camera move was actually attempted — a group focused WITHOUT moving (co-located, or
	 * already at max zoom) applies immediately, it is never re-evaluated against a "did the
	 * move actually un-cluster it" check that never had a move to evaluate. When a move WAS
	 * attempted and ymaps still reports the SAME degenerate state once it settles, focus is not
	 * applied — see the file docblock's second lesson.
	 *
	 * SEQUENCED against a slower-to-resolve EARLIER call via `_focusSeq`: two ymaps camera moves
	 * are not guaranteed to resolve in the order they were started (animation duration depends
	 * on distance travelled), so a stale continuation must never apply its OWN (now outdated)
	 * focus on top of a more recent call's. `mySeq` captures `_focusSeq` at call time; the
	 * continuation only proceeds if `_focusSeq` is still `mySeq` once the move (or the
	 * synchronous "nothing to move" case) settles.
	 *
	 * @param {string} key
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusGroup = function( key ) {
		var self = this;
		var mySeq = ++this._focusSeq;
		var state = this.objectManager.getObjectState( key );
		var mover = Promise.resolve();
		var attemptedMove = false;

		if ( state && state.isClustered && ! isSingleCoordinateCluster( state.cluster )
			&& this.map.getZoom() < MAX_ZOOM ) {
			var target = clusterAnchorCoordinates( state.cluster );

			if ( target ) {
				attemptedMove = true;
				mover = this.map.setBounds(
					[ target, target ],
					{ checkZoomRange: true, zoomMargin: 0, useMapMargin: true }
				);
			}
		}

		return mover.then( function() {
			if ( self._destroyed || mySeq !== self._focusSeq ) {
				return;
			}

			if ( attemptedMove ) {
				var settled = self.objectManager.getObjectState( key );

				if ( settled && settled.isClustered && isSingleCoordinateCluster( settled.cluster ) ) {
					return;
				}
			}

			self._applyFocus( key );
		} );
	};

	/**
	 * Applies the visual "focused" state to `key` and reverts the previously focused group (if
	 * any, and if different) back to its resting state.
	 *
	 * @param {string} key
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyFocus = function( key ) {
		var previous = this._focusedKey;

		if ( previous && previous !== key ) {
			this._setMarkerState( previous, 'resting' );
		}

		this._setMarkerState( key, 'active' );
		this._focusedKey = key;
	};

	/**
	 * Sets one feature's icon hit-box AND, when the group's own data is still known
	 * (`_groupsByKey`), its rendered state (`data-state` and which icon URL is shown — D-5),
	 * via `objectManager.objects.setObjectOptions()`/`setObjectProperties()` — the documented
	 * way to update an already-added feature in place, without a `removeAll()`/`add()` rebuild.
	 *
	 * The hit-box updates UNCONDITIONALLY (it needs no group data, only the fixed box
	 * constants); the properties update is skipped when the group is unknown — defensive only,
	 * `setPoints()` always populates `_groupsByKey` before any `focusGroup()` a real caller
	 * would issue.
	 *
	 * @param {string} key
	 * @param {string} state `'active'` or `'resting'`.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._setMarkerState = function( key, state ) {
		var objects = this.objectManager && this.objectManager.objects;

		if ( ! objects ) {
			return;
		}

		var box = 'active' === state ? ICON_BOX_ACTIVE : ICON_BOX;

		if ( 'function' === typeof objects.setObjectOptions ) {
			objects.setObjectOptions( key, {
				iconImageSize: box.size,
				iconImageOffset: box.offset,
			} );
		}

		var group = this._groupsByKey[ key ];

		if ( group && 'function' === typeof objects.setObjectProperties ) {
			var properties = this._buildProperties( group );

			properties.state = state;

			objects.setObjectProperties( key, properties );
		}
	};

	/**
	 * The group key {@see focusGroup} most recently, successfully focused — `null` before the
	 * first call, or once that group is no longer among the currently drawn ones (see
	 * {@see setPoints}).
	 *
	 * @returns {string|null}
	 */
	WoodevYandexMapProvider.prototype.getFocusedKey = function() {
		return this._focusedKey;
	};

	/**
	 * Tears the provider down: removes the object manager, destroys the map, resets every
	 * internal collection. Idempotent — a second call, or a call before `init()`/`focusGroup()`
	 * has settled, is a safe no-op (every async continuation checks `_destroyed` first).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.destroy = function() {
		if ( this._destroyed ) {
			return;
		}

		this._destroyed = true;

		if ( this.map ) {
			if ( this.objectManager ) {
				this.map.geoObjects.remove( this.objectManager );
			}

			try {
				this.map.destroy();
			} catch ( e ) {
				// Defensive — a map already torn down by ymaps itself must not fail this call.
			}
		}

		this.map = null;
		this.objectManager = null;
		this.ymaps = null;
		this.container = null;
		this._iconLayoutClass = null;
		this._groupsByKey = {};
		this._focusedKey = null;
		this.searchControl = null;
		this._addressPin = null;
		this._marginAreaId = null;
		this.handlers = {
			pointClick: [],
			boundsChange: [],
			bboxTooWide: [],
			visibleChange: [],
			nothingNearby: [],
			searchResults: [],
			error: [],
		};
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
