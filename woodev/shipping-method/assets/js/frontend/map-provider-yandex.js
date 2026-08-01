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
 * as before.
 *
 * PUBLIC SURFACE: `init( container, config )`, `setPoints( groups )`, `focusGroup( key )`,
 * `setTypeFilter( codes )`, `getFocusedKey()`, `on( event, cb )`, `destroy()`. Events out:
 * `pointClick( key )`, `boundsChange( bbox )`, `bboxTooWide()`, `visibleChange( keys )`,
 * `error( { code, message } )`. `bbox` is the flat `[lat1,lng1,lat2,lng2]` shape
 * `pickup-datasource.js`'s `serializePointsQuery()` expects (see {@see flattenBounds}).
 *
 * CONFIG is FLAT — the merge `pickup-mount.js`'s `buildProviderConfig()` builds from
 * `mapConfig` (`scriptUrl`, `ns`, `hasApiKey`, `lang`, `layers`, `copyrights`) plus
 * `strategy`/`locality`/`i18n`, and (Task 20) the plugin-level `defaultLocation`
 * (`{ center: [lat,lng], zoom }`, ALWAYS present — a required plugin argument),
 * `pointIcons` (`{ typeCode: { default, active } }`, `active` always filled) and
 * `accentColor`. This file reads all of these at the top level of `config` — never nested.
 *
 * TWO LESSONS THIS FILE HAS ALREADY TAUGHT (s46 — a browser found both, green tests did not):
 *
 * 1. YMAPS CAMERA MOVES ARE ASYNCHRONOUS. `map.setBounds()` animates and resolves once the
 *    move COMPLETES, not when it starts. Reading `map.getBounds()` right after calling
 *    `setBounds()` without awaiting its promise returns the PRE-move viewport — which once
 *    produced a planet-wide bbox the server's cap correctly refused, reporting "no points" for
 *    a locality that had them. Every `setBounds()` call in this file is awaited. Two moves are
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
 * instead of leaving an empty/broken `<img>`; it is never invisible or unclickable.
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
			error: [],
		};

		/** @type {Object.<string, Object>} the current groups, by key — see {@see setPoints}. */
		this._groupsByKey = {};

		/** @type {string|null} the group key {@see focusGroup} last successfully focused. */
		this._focusedKey = null;

		/** @type {number} bumped on every {@see focusGroup} call — discards a stale
		 *  continuation when a later call's camera move resolves before an earlier one's. */
		this._focusSeq = 0;

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

				return self._resolveInitialViewport();
			},
			function() {
				if ( ! self._destroyed ) {
					self.emit( 'error', { code: 'map_script', message: '' } );
				}
			}
		).then( function() {
			if ( self._destroyed || ! self.map || 'viewport' !== self.config.strategy ) {
				return;
			}

			// Fire once for the viewport just resolved, THEN start listening — in that order,
			// so this initial call is never immediately followed by a redundant second one from
			// a listener that was not registered yet.
			self._checkAndEmitBounds();
			self.map.events.add( 'boundschange', function() {
				self._checkAndEmitBounds();
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
	 * exactly what it was handed. Under `strategy: 'bulk'` a non-empty set also fits the camera
	 * to it via {@see geo}'s `boundsFor()` — the ONLY place this file fits the camera to loaded
	 * data, matching the previous version's `bulk` behaviour.
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

		// A refetch that no longer contains the currently focused group must not leave a stale
		// key behind — the caller has nothing left to visually mark as active.
		if ( this._focusedKey && ! Object.prototype.hasOwnProperty.call( this._groupsByKey, this._focusedKey ) ) {
			this._focusedKey = null;
		}

		this._emitVisibleChange();

		if ( 'bulk' === this.config.strategy && list.length > 0 ) {
			var anchor = [ list[ 0 ].lat, list[ 0 ].lng ];

			this.map.setBounds( geo.boundsFor( anchor, list ), { checkZoomRange: true } );
		}
	};

	/**
	 * Builds one ObjectManager feature for a group. The icon URL is resolved from
	 * `config.pointIcons[group.typeCode]` — absent for an unrecognised type, in which case
	 * {@see WoodevYandexMapProvider#_renderMarker} still draws a (modifier-classed) marker, never
	 * an invisible/broken one. `options.iconImageHref` is deliberately never set anywhere in
	 * this file — see the file docblock's "ICONS ARE AN HTML LAYOUT" section.
	 *
	 * @param {Object} group
	 * @returns {Object} a GeoJSON-ish ObjectManager feature.
	 */
	WoodevYandexMapProvider.prototype._buildFeature = function( group ) {
		var icons = ( this.config.pointIcons && this.config.pointIcons[ group.typeCode ] ) || null;

		return {
			type: 'Feature',
			id: group.key,
			geometry: { type: 'Point', coordinates: [ group.lat, group.lng ] },
			properties: {
				groupSize: group.size,
				typeCode: group.typeCode,
				iconHref: icons ? icons.default : '',
				iconHrefActive: icons ? icons.active : '',
			},
			options: {
				iconLayout: this._iconLayoutClass,
				iconImageSize: ICON_BOX.size,
				iconImageOffset: ICON_BOX.offset,
			},
		};
	};

	/**
	 * Renders one marker's DOM: an `<img>` for a known type's icon (omitted, with a
	 * `--unknown` modifier class, for an unrecognised `typeCode`), plus a count badge for a
	 * group of more than one point. Deliberately independent of ymaps' own layout `build()`
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
		var iconHref = properties.get( 'iconHref' );
		var root = container.querySelector( '.woodev-pickup-marker' ) || container;
		var isGroup = groupSize > 1;

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
	 * Focuses group `key`: marks it visually active (swaps its icon hit-box to
	 * {@see ICON_BOX_ACTIVE} and the previously focused group's back to {@see ICON_BOX}) and,
	 * when it is currently folded into a ymaps cluster, moves the camera to un-cluster it —
	 * unless every feature in that cluster shares one coordinate, in which case no move could
	 * ever separate them and none is attempted (the "Russian Post" guard, spec §7.5; see the
	 * file docblock's second lesson).
	 *
	 * SEQUENCED against a slower-to-resolve EARLIER call via `_focusSeq`: two ymaps camera moves
	 * are not guaranteed to resolve in the order they were started (animation duration depends
	 * on distance travelled), so a stale continuation must never apply its OWN (now outdated)
	 * focus on top of a more recent call's. `mySeq` captures `_focusSeq` at call time; the
	 * continuation only proceeds if `_focusSeq` is still `mySeq` once the move (or the
	 * synchronous "nothing to move" case) settles.
	 *
	 * The cluster check runs TWICE: once before moving (to decide whether to move at all), and
	 * again on the SETTLED state once the move resolves — ymaps' own report of what is
	 * clustered at `key` can change once the camera actually settles, and a still-degenerate
	 * result after moving must not apply focus either.
	 *
	 * @param {string} key
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusGroup = function( key ) {
		var self = this;
		var mySeq = ++this._focusSeq;
		var state = this.objectManager.getObjectState( key );
		var mover = Promise.resolve();

		if ( state && state.isClustered && ! isSingleCoordinateCluster( state.cluster ) ) {
			var target = clusterAnchorCoordinates( state.cluster );

			if ( target ) {
				mover = this.map.setBounds( [ target, target ], { checkZoomRange: true } );
			}
		}

		return mover.then( function() {
			if ( self._destroyed || mySeq !== self._focusSeq ) {
				return;
			}

			var settled = self.objectManager.getObjectState( key );

			if ( settled && settled.isClustered && isSingleCoordinateCluster( settled.cluster ) ) {
				return;
			}

			self._applyFocus( key );
		} );
	};

	/**
	 * Applies the visual "focused" state to `key` and reverts the previously focused group (if
	 * any, and if different) back to its resting icon box.
	 *
	 * @param {string} key
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyFocus = function( key ) {
		var previous = this._focusedKey;

		if ( previous && previous !== key ) {
			this._setIconBox( previous, ICON_BOX );
		}

		this._setIconBox( key, ICON_BOX_ACTIVE );
		this._focusedKey = key;
	};

	/**
	 * Sets one feature's `iconImageSize`/`iconImageOffset` via `objectManager.objects.setObjectOptions()`
	 * — the documented way to update a single already-added feature's options in place, without
	 * a `removeAll()`/`add()` rebuild.
	 *
	 * @param {string}                       key
	 * @param {{size: number[], offset: number[]}} box
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._setIconBox = function( key, box ) {
		var objects = this.objectManager && this.objectManager.objects;

		if ( objects && 'function' === typeof objects.setObjectOptions ) {
			objects.setObjectOptions( key, {
				iconImageSize: box.size,
				iconImageOffset: box.offset,
			} );
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
		this.handlers = { pointClick: [], boundsChange: [], bboxTooWide: [], visibleChange: [], error: [] };
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
