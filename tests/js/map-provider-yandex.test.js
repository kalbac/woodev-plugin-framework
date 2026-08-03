/**
 * @jest-environment jsdom
 *
 * Tests for map-provider-yandex.js (SP-5 Tasks 17/18 — the ObjectManager/groups rewrite).
 *
 * The real Yandex Maps JS API is not loadable in jest, so this file hand-rolls a minimal
 * `window[ns]` stub covering exactly the surface the provider uses: `Map`, `ObjectManager`,
 * `control.ZoomControl`, `Layer`, `projection`, `templateLayoutFactory.createClass`, `geocode`.
 * Setting `window[ns]` to a working stub BEFORE calling `init()` makes {@see loadYmapsScript}'s
 * "already loaded" branch fire immediately.
 *
 * `ymapsStub` is a module-level variable, reset by a fresh `createYmapsStub()` in `beforeEach()`
 * — the given-in-spec test bodies read/mutate it directly (`ymapsStub.lastMap`,
 * `ymapsStub.lastObjectManager`, `ymapsStub.geocodeCalls`, `ymapsStub.geocodeResult`, …) the way
 * the SP-5 Task 17/18 plan text itself does. The {@see init} helper below reuses that SAME
 * object unless a test needs different stub behaviour (e.g. `deferSetBounds`), in which case it
 * passes a second `stubOptions` argument and gets a fresh one.
 *
 * Every balloon/drawer/type-filter-CONTROL/cluster-balloon test that used to live here is GONE:
 * that presentation moved to `pickup-panels.js` (the list panel, the point card, the tab bar,
 * the search view, the type filter MENU) and is covered by `pickup-panels.test.js`. This file
 * only tests what the provider itself still owns: the map canvas, one ObjectManager feature per
 * group, the camera, and the events out.
 *
 * @see woodev/shipping-method/assets/js/frontend/map-provider-yandex.js
 */

'use strict';

const WoodevYandexMapProvider = require( '../../woodev/shipping-method/assets/js/frontend/map-provider-yandex' );
// Used directly (not just by the production file under test) so the Task 19 address-search
// tests below can pin EXACT expected bounds/distances via the same arithmetic focusAddress()
// itself uses, rather than a weaker branch-only assertion (e.g. "the far group is excluded")
// that a mutant returning just the single closest group would still pass.
const geo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

let nsCounter = 0;
let ymapsStub;

/**
 * Waits one macrotask — enough for any chain of already-settled promises the production code
 * builds (script load → map/object-manager build → initial viewport resolution) to fully flush.
 */
function flushPromises() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * A `properties`-shaped wrapper matching ymaps' real `IDataManager`-ish `get`/`set`/`getAll`
 * surface — backs the marker layout's `getData().properties` in the direct `_renderMarker()`
 * tests below.
 */
function makeProperties( initial ) {
	const data = Object.assign( {}, initial );

	return {
		get: ( key ) => data[ key ],
		set: ( key, value ) => {
			data[ key ] = value;
		},
		getAll: () => Object.assign( {}, data ),
	};
}

/**
 * Builds a `ymaps.geocode()` resolution shaped exactly as {@see extractGeocodeBounds}/
 * {@see extractGeocodeCoordinates} (the production file) read it:
 * `result.geoObjects.get(0).properties.get('boundedBy')` for the bounds,
 * `result.geoObjects.get(0).geometry.getCoordinates()` for the point coordinates
 * `resolveAddress()` reads. `coordinates` defaults to `bounds`' own first corner when omitted —
 * plenty for tests that only care ONE of the two shapes was read correctly.
 */
function makeGeocodeResult( bounds, coordinates ) {
	return {
		geoObjects: {
			get: () => ( {
				geometry: {
					getCoordinates: () => coordinates || ( bounds ? bounds[ 0 ] : null ),
				},
				properties: {
					get: ( key ) => ( 'boundedBy' === key ? bounds : undefined ),
				},
			} ),
		},
	};
}

/**
 * Builds a `ymaps.geocode()` resolution shaped for the Task 12 search-provider path
 * (`_searchGeocodeProvider()`'s own `.toArray()`/`.properties.set()`/`.properties.get('text')`
 * reads) — as opposed to {@see makeGeocodeResult}'s single-`.get(0)` shape, which
 * `_resolveInitialViewport()`/`resolveAddress()` read instead. Each entry's `properties` is a
 * REAL get/set data manager ({@see makeProperties}) so a test can assert `_searchGeocodeProvider()`
 * actually tagged it (`properties.get( 'woodevKind' )`) after the fact.
 *
 * @param {string[]} texts one `properties.get( 'text' )` value per hit, in order.
 */
function makeSearchGeocodeResult( texts ) {
	const hits = ( texts || [] ).map( ( text ) => ( { properties: makeProperties( { text } ) } ) );

	return {
		geoObjects: {
			toArray: () => hits,
			getLength: () => hits.length,
		},
		metaData: {},
	};
}

/**
 * Builds a hand-rolled `window[ns]` ymaps stub. See the file docblock for what it covers.
 *
 * @param {Object}  [options]
 * @param {boolean} [options.deferSetBounds] By default `Map#setBounds()` resolves (and applies
 *   the new bounds) SYNCHRONOUSLY-ish — fine for tests that don't care about camera-move
 *   ordering. `deferSetBounds: true` leaves the returned promise pending until a test explicitly
 *   calls `resolveNextSetBounds()`/`resolveSetBoundsFor()` — needed to prove the s46
 *   "setBounds() is asynchronous" lesson and the `_focusSeq` out-of-order guard.
 * @param {number}  [options.zoom] `Map#getZoom()`'s fixed return value. Defaults to 10.
 */
function createYmapsStub( options ) {
	const opts = options || {};
	const deferSetBounds = !! opts.deferSetBounds;
	const zoom = 'number' === typeof opts.zoom ? opts.zoom : 10;
	const stub = {};

	stub.geocodeCalls = 0;
	stub.geocodeImpl = null;
	// A valid, non-empty default result — tests that don't care about the geocode OUTCOME
	// (only that it was called) never need to touch this. Shaped for the single-hit
	// `.get(0)`-style consumers (`_resolveInitialViewport()`/`resolveAddress()`) by default; the
	// Task 12 search-provider tests below override this per-test with `makeSearchGeocodeResult()`,
	// shaped for the multi-hit `.toArray()`-style consumer instead.
	stub.geocodeResult = makeGeocodeResult( [ [ 10, 20 ], [ 11, 21 ] ] );

	function Map( container, state, mapOptions ) {
		this.container = container;
		this.state = state;
		this.options = mapOptions;
		this.bounds = [ [ 10, 20 ], [ 11, 21 ] ];
		this.setBoundsCalls = [];
		this.setCenterCalls = [];
		// Task 14 (spec V-13) — `getZoom` a jest.fn (not a plain prototype method) so a test
		// proving `zoomBy()`'s clamp can override its return value per call via
		// `mockReturnValue()`; every OTHER test gets the same fixed `options.zoom` default it
		// always did. `setZoom` is the camera-move call `zoomBy()` makes; no production code
		// calls it anywhere else, so a bare spy is enough — nothing here needs to track state.
		this.getZoom = jest.fn( () => zoom );
		this.setZoom = jest.fn();
		// `add` is defined non-enumerable so `toEqual( [ '© Test' ] )` against a plain array
		// literal still passes — an ENUMERABLE own `add` property would make the received
		// array structurally unequal to a plain array even with identical indexed contents.
		this.layers = [];
		Object.defineProperty( this.layers, 'add', {
			value: ( layer ) => {
				this.layers.push( layer );
			},
		} );
		this.copyrights = [];
		Object.defineProperty( this.copyrights, 'add', {
			value: ( text ) => {
				this.copyrights.push( text );
			},
		} );
		this.geoObjects = { add: jest.fn(), remove: jest.fn() };
		this.controls = { add: jest.fn() };
		this.destroy = jest.fn();
		this._eventHandlers = {};
		this._pendingSetBounds = [];

		const self = this;

		this.events = {
			add: ( name, cb ) => {
				self._eventHandlers[ name ] = self._eventHandlers[ name ] || [];
				self._eventHandlers[ name ].push( cb );
			},
		};

		stub.lastMap = this;
	}
	Map.prototype.getBounds = function() {
		return this.bounds;
	};
	Map.prototype.setCenter = function( center, mapZoom ) {
		this.setCenterCalls.push( [ center, mapZoom ] );
	};
	Map.prototype.setBounds = function( bounds, boundsOptions ) {
		const self = this;

		this.setBoundsCalls.push( { bounds, options: boundsOptions } );

		if ( deferSetBounds ) {
			return new Promise( ( resolve ) => {
				self._pendingSetBounds.push( {
					bounds,
					resolve: () => {
						self.bounds = bounds;
						resolve();
					},
				} );
			} );
		}

		this.bounds = bounds;

		return Promise.resolve();
	};
	// Resolves the OLDEST still-pending deferred setBounds() call.
	Map.prototype.resolveNextSetBounds = function() {
		const entry = this._pendingSetBounds.shift();

		if ( entry ) {
			entry.resolve();
		}
	};
	// Resolves a SPECIFIC pending setBounds() call by its (unique) bounds argument — needed to
	// resolve two concurrent calls OUT of the order they were made.
	Map.prototype.resolveSetBoundsFor = function( bounds ) {
		const index = this._pendingSetBounds.findIndex(
			( entry ) => JSON.stringify( entry.bounds ) === JSON.stringify( bounds )
		);

		if ( -1 !== index ) {
			this._pendingSetBounds.splice( index, 1 )[ 0 ].resolve();
		}
	};
	Map.prototype.fireBoundsChange = function() {
		( this._eventHandlers.boundschange || [] ).forEach( ( cb ) => cb() );
	};

	function ObjectManager( omOptions ) {
		this.options = omOptions;
		this.added = [];
		this.removeAllCalls = 0;
		this.filter = null;
		// Settable directly by a test — see getObjectState() below.
		this.state = undefined;

		const clickHandlers = [];

		this.objects = {
			events: {
				add: ( type, cb ) => {
					if ( 'click' === type ) {
						clickHandlers.push( cb );
					}
				},
			},
			setObjectOptions: jest.fn(),
			setObjectProperties: jest.fn(),
		};
		this.fireObjectClick = ( id ) => {
			clickHandlers.forEach( ( cb ) => cb( { get: ( k ) => ( 'objectId' === k ? id : undefined ) } ) );
		};

		stub.lastObjectManager = this;
	}
	ObjectManager.prototype.add = function( features ) {
		this.added = this.added.concat( features );
	};
	ObjectManager.prototype.removeAll = function() {
		this.added = [];
		this.removeAllCalls += 1;
	};
	ObjectManager.prototype.setFilter = function( fn ) {
		this.filter = fn;
	};
	ObjectManager.prototype.getObjectState = function( id ) {
		// Most tests only need ONE shared state regardless of which key is queried — set
		// `.state` directly. A test that needs two DIFFERENT keys to resolve to different
		// (e.g. differently-anchored) states sets `.stateFor( id )` instead.
		if ( 'function' === typeof this.stateFor ) {
			return this.stateFor( id );
		}

		return this.state;
	};

	function Layer( url, layerOptions ) {
		this.url = url;
		this.options = layerOptions;
	}

	// Task 12 (spec V-6/V-7, gotcha `ymaps-control-options-must-be-nested.md`) — `this.options`
	// deliberately reads `ctorArgs.options`, the REAL nested sub-object ymaps itself would read,
	// NOT the whole constructor argument — a stub that stored the whole argument under `.options`
	// would make a test asserting `.options.provider.geocode` pass even on the OLD buggy
	// production code (which put `provider` at the ROOT), since the whole root object happens to
	// have a `provider` key too. `rootArgs` keeps the raw constructor argument available
	// separately, for the tests that assert the ROOT carries nothing but `options` (proving a
	// stray `layout`/`provider` at the root — the exact bug this file's gotcha documents — would
	// be caught). `search()`/`getResultsCount()`/`showResult()` are a no-op "engine" surface — the
	// real methods this file's own docblock says are KEPT, never reimplemented by production
	// code, so nothing more elaborate is needed here for a jest run.
	function SearchControl( ctorArgs ) {
		this.rootArgs = ctorArgs;
		this.options = ( ctorArgs && ctorArgs.options ) || {};
		this.state = ( ctorArgs && ctorArgs.state ) || {};
		this.search = jest.fn();
		this.getResultsCount = jest.fn( () => 0 );
		this.showResult = jest.fn();

		stub.lastSearchControl = this;
	}

	// Task 19 (D-6) — the "your address" pin. Only its constructor call shape matters to the
	// tests in this file (that it is never a group inside the ObjectManager); no rendering
	// surface of its own is exercised.
	function Placemark( geometry, properties, placemarkOptions ) {
		this.geometry = geometry;
		this.properties = properties;
		this.options = placemarkOptions;
	}

	function createClass( html, methods ) {
		function Layout() {}
		Layout.superclass = { build: () => {}, clear: () => {} };
		Layout.prototype.getData = function() {
			return this._data;
		};
		Layout.prototype.getElement = function() {
			return this._element;
		};
		Object.keys( methods || {} ).forEach( ( key ) => {
			Layout.prototype[ key ] = methods[ key ];
		} );

		return Layout;
	}

	stub.ready = () => Promise.resolve();
	stub.Map = Map;
	stub.ObjectManager = ObjectManager;
	// Task 14 (spec V-13) — `ZoomControl` is a jest.fn, not a plain constructor stand-in: this
	// file now proves the PRODUCTION code never calls it any more (the control was deleted from
	// `_buildMap()` in favour of the panels' own DOM), which needs `.not.toHaveBeenCalled()`.
	stub.control = { ZoomControl: jest.fn(), SearchControl };
	stub.Layer = Layer;
	stub.Placemark = Placemark;
	stub.projection = { sphericalMercator: 'stub-projection' };
	stub.templateLayoutFactory = { createClass };
	stub.geocode = ( request, options ) => {
		stub.geocodeCalls += 1;
		stub.lastGeocodeRequest = request;
		stub.lastGeocodeOptions = options;

		// `geocodeImpl` lets one test own the timing or the outcome — a rejection (quota, network)
		// or a promise it releases by hand to model "resolves after the customer closed the
		// dialog". Unset for every other test, which just wants `geocodeResult` back.
		if ( stub.geocodeImpl ) {
			return stub.geocodeImpl( request, options );
		}

		return Promise.resolve( stub.geocodeResult );
	};

	return stub;
}

function makeConfig( overrides ) {
	nsCounter += 1;

	return Object.assign(
		{
			scriptUrl: 'https://example.test/ymaps.js',
			ns: 'WoodevTestYmaps_' + nsCounter,
			hasApiKey: true,
			lang: 'ru_RU',
			layers: [],
			copyrights: [],
			strategy: 'bulk',
			locality: 'Москва',
			i18n: {},
			defaultLocation: { center: [ 55.75, 37.61 ], zoom: 10 },
			pointIcons: {},
			accentColor: '#06aedd',
			// A truthy default so `_buildSearchControl()` builds a control for every test that
			// doesn't care about search — a test proving the OPPOSITE (`searchLayoutEl: null` →
			// no control at all, spec V-6) overrides this explicitly.
			searchLayoutEl: 'undefined' !== typeof document ? document.createElement( 'div' ) : null,
		},
		overrides
	);
}

/**
 * Builds a fresh provider, `window[ns] = ymapsStub`, and awaits `init()`. Reuses the
 * `beforeEach()`-created `ymapsStub` unless `stubOptions` is given, in which case it replaces
 * `ymapsStub` with a freshly configured one first (needed for `deferSetBounds`/`zoom`).
 */
function init( overrides, stubOptions ) {
	if ( stubOptions ) {
		ymapsStub = createYmapsStub( stubOptions );
	}

	const config = makeConfig( overrides );

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();

	return provider.init( document.createElement( 'div' ), config ).then( () => provider );
}

/** A minimal group fixture — `{ key, lat, lng, typeCode, size, points }`. */
function group( key, lat, lng, typeCode ) {
	return {
		key,
		lat: 'number' === typeof lat ? lat : 55.75,
		lng: 'number' === typeof lng ? lng : 37.61,
		typeCode: typeCode || 'pvz',
		size: 1,
		points: [ {} ],
	};
}

/**
 * A single-point group fixture built AROUND one point object — unlike {@see group}, which
 * fabricates its own empty point, this wraps the exact `point` a test hands it (its `id`,
 * `name`, `address`, …), for the `_searchGeocodeProvider()`/`matchPoints()` tests that need
 * real, matchable fields.
 */
function groupWith( point ) {
	return {
		key: 'string' === typeof point.id ? point.id : 'g',
		lat: 'number' === typeof point.lat ? point.lat : 55.75,
		lng: 'number' === typeof point.lng ? point.lng : 37.61,
		typeCode: point.typeCode || 'pvz',
		size: 1,
		points: [ point ],
	};
}

beforeEach( () => {
	ymapsStub = createYmapsStub();
} );

// -------------------------------------------------------------------------
// Script loading / hasApiKey / destroy()
// -------------------------------------------------------------------------

test( 'hasApiKey: false emits a map_script error and never constructs a map', async () => {
	const config = makeConfig( { hasApiKey: false } );

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();
	const seen = [];

	provider.on( 'error', ( e ) => seen.push( e ) );

	await provider.init( document.createElement( 'div' ), config );

	expect( seen[ 0 ] ).toEqual( { code: 'map_script', message: '' } );
	expect( ymapsStub.lastMap ).toBeUndefined();
	expect( provider.map ).toBeNull();
} );

test( 'builds the map into its OWN .woodev-pickup-map element, not the shared container', async () => {
	const container = document.createElement( 'div' );
	const config = makeConfig();

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();
	await provider.init( container, config );

	// The container is the modal body, which the framework's panels also populate — ymaps
	// must not be handed that node. And `pickup.css` sizes the map through this exact class:
	// with nothing carrying it the rule matched nothing and the map had no height at all.
	const canvas = container.querySelector( '.woodev-pickup-map' );

	expect( canvas ).not.toBeNull();
	expect( ymapsStub.lastMap.container ).toBe( canvas );
	expect( ymapsStub.lastMap.container ).not.toBe( container );

	provider.destroy();

	expect( container.querySelector( '.woodev-pickup-map' ) ).toBeNull();
} );

test( 'destroy() is idempotent', async () => {
	const provider = await init();

	expect( () => provider.destroy() ).not.toThrow();
	expect( provider.map ).toBeNull();
	expect( ymapsStub.lastMap.destroy ).toHaveBeenCalledTimes( 1 );

	expect( () => provider.destroy() ).not.toThrow();
	expect( ymapsStub.lastMap.destroy ).toHaveBeenCalledTimes( 1 ); // second call is a true no-op
} );

test( 'destroy() called before init() settles never builds a map or throws', async () => {
	const config = makeConfig();

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();
	const initPromise = provider.init( document.createElement( 'div' ), config );

	expect( () => provider.destroy() ).not.toThrow();

	await initPromise;

	expect( provider.map ).toBeNull();
} );

// -------------------------------------------------------------------------
// Map construction — zoom range, layers/copyrights, object manager options
// -------------------------------------------------------------------------

test( 'builds the map with minZoom 8 and maxZoom 18', async () => {
	await init();

	expect( ymapsStub.lastMap.options.minZoom ).toBe( 8 );
	expect( ymapsStub.lastMap.options.maxZoom ).toBe( 18 );
} );

// -------------------------------------------------------------------------
// Task 14 (spec V-13) — our own zoom control replaces ymaps' `ZoomControl`; `zoomBy()` is the
// provider-side half the panels' two buttons drive (see pickup-panels.test.js for the DOM half).
// -------------------------------------------------------------------------

test( 'adds no ymaps ZoomControl (spec V-13)', async () => {
	await init();

	expect( ymapsStub.control.ZoomControl ).not.toHaveBeenCalled();
} );

test( 'clamps zoomBy to the configured range', async () => {
	const provider = await init();

	provider.map.getZoom.mockReturnValue( 18 );
	provider.zoomBy( 1 );

	expect( provider.map.setZoom ).toHaveBeenCalledWith( 18, { duration: 200 } );
} );

test( 'clamps zoomBy at the bottom of the range too', async () => {
	const provider = await init();

	provider.map.getZoom.mockReturnValue( 8 );
	provider.zoomBy( -1 );

	expect( provider.map.setZoom ).toHaveBeenCalledWith( 8, { duration: 200 } );
} );

test( 'zoomBy steps by exactly the signed amount inside the range', async () => {
	const provider = await init();

	provider.map.getZoom.mockReturnValue( 12 );
	provider.zoomBy( 1 );

	expect( provider.map.setZoom ).toHaveBeenCalledWith( 13, { duration: 200 } );
} );

test( 'zoomBy is a safe no-op once destroy() has torn the map down', async () => {
	const provider = await init();

	provider.destroy();

	expect( () => provider.zoomBy( 1 ) ).not.toThrow();
} );

test( 'adds the plugin tile layers, pinning the tile URL and the RESOLVED projection object '
	+ '(not the raw string), and the copyrights', async () => {
	await init( {
		layers: [ { url: 'https://tiles.test/%c.png', projection: 'sphericalMercator' } ],
		copyrights: [ '© Test' ],
	} );

	expect( ymapsStub.lastMap.layers ).toHaveLength( 1 );
	expect( ymapsStub.lastMap.layers[ 0 ].url ).toBe( 'https://tiles.test/%c.png' );
	// 'stub-projection' is what the stub's `ymaps.projection.sphericalMercator` resolves to —
	// pinning THAT proves the raw string was looked up, not passed through verbatim.
	expect( ymapsStub.lastMap.layers[ 0 ].options.projection ).toBe( 'stub-projection' );
	expect( ymapsStub.lastMap.copyrights ).toEqual( [ '© Test' ] );
} );

test( 'the object manager disables its own balloon machinery — the panels own the point card', async () => {
	await init();

	expect( ymapsStub.lastObjectManager.options.geoObjectOpenBalloonOnClick ).toBe( false );
	expect( ymapsStub.lastObjectManager.options.clusterHasBalloon ).toBe( false );
	expect( ymapsStub.lastObjectManager.options.clusterize ).toBe( true );
} );

test( 'the cluster icon colour follows the resolved (valid) accent colour', async () => {
	await init( { accentColor: '#123456' } );

	expect( ymapsStub.lastObjectManager.options.clusterIconColor ).toBe( '#123456' );
} );

test( 'an invalid accentColor falls back to the framework default cluster colour, never crashes', async () => {
	await init( { accentColor: 'not-a-color' } );

	expect( ymapsStub.lastObjectManager.options.clusterIconColor ).toBe( '#FCE000' );
} );

// -------------------------------------------------------------------------
// setPoints() — one ObjectManager feature per group
// -------------------------------------------------------------------------

test( 'adds every group to the object manager as one feature each, pinning its id and coordinates', async () => {
	const provider = await init();

	provider.setPoints( [
		{ key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [ { id: '1' } ] },
		{ key: 'b', lat: 55.76, lng: 37.62, typeCode: 'pvz', size: 2, points: [ { id: '2' }, { id: '3' } ] },
	] );

	const [ a, b ] = ymapsStub.lastObjectManager.added;

	expect( ymapsStub.lastObjectManager.added ).toHaveLength( 2 );
	expect( a.id ).toBe( 'a' );
	expect( a.geometry ).toEqual( { type: 'Point', coordinates: [ 55.75, 37.61 ] } );
	expect( b.id ).toBe( 'b' );
	expect( b.geometry ).toEqual( { type: 'Point', coordinates: [ 55.76, 37.62 ] } );
} );

test( 'uses the plugin icon for the group type and falls back when the type is unknown', async () => {
	const provider = await init( { pointIcons: { pvz: { default: '/pvz.svg', active: '/pvz-a.svg' } } } );

	provider.setPoints( [ { key: 'a', lat: 55.75, lng: 37.61, typeCode: 'unknown', size: 1, points: [ {} ] } ] );

	// options.iconImageHref is the classic image-marker option (iconLayout: 'default#image') —
	// this file never sets it, for ANY type: markers are an HTML layout (D-5), never that.
	expect( ymapsStub.lastObjectManager.added[ 0 ].options.iconImageHref ).toBeUndefined();
} );

test( 'a known type resolves its icon URLs onto the feature properties; an unknown type resolves to none', async () => {
	const provider = await init( { pointIcons: { pvz: { default: '/pvz.svg', active: '/pvz-a.svg' } } } );

	provider.setPoints( [ group( 'known', 1, 1, 'pvz' ), group( 'unknown', 2, 2, 'other' ) ] );

	const [ known, unknown ] = ymapsStub.lastObjectManager.added;

	expect( known.properties.iconHref ).toBe( '/pvz.svg' );
	expect( known.properties.iconHrefActive ).toBe( '/pvz-a.svg' );
	expect( unknown.properties.iconHref ).toBe( '' );
	expect( unknown.properties.iconHrefActive ).toBe( '' );
} );

test( 'marks a group of more than one point so the badge renders', async () => {
	const provider = await init();

	provider.setPoints( [ { key: 'b', lat: 55.76, lng: 37.62, typeCode: 'pvz', size: 3, points: [] } ] );

	expect( ymapsStub.lastObjectManager.added[ 0 ].properties.groupSize ).toBe( 3 );
} );

test( 'every feature is registered with the RESTING (non-active) icon hit-box dimensions', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ) ] );

	const feature = ymapsStub.lastObjectManager.added[ 0 ];

	expect( feature.options.iconImageSize ).toEqual( [ 45, 45 ] );
	expect( feature.options.iconImageOffset ).toEqual( [ -22, -23 ] );
} );

// Spec V-9 / gotcha `ymaps-html-icon-layout-needs-iconshape.md`: `iconImageSize`/
// `iconImageOffset` are options of the `default#image` layout, which this file never uses — the
// custom HTML layout's hit area comes from `iconShape` alone. Without it, clicks fell through to
// the map's own POI layer and Yandex's organisation card opened instead of ours.
test( 'declares an iconShape matching the RESTING icon hit-box, so clicks actually hit the '
	+ 'marker instead of falling through to the map\'s own POI layer (spec V-9)', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ) ] );

	const feature = ymapsStub.lastObjectManager.added[ 0 ];

	expect( feature.options.iconShape ).toEqual( {
		type: 'Rectangle',
		coordinates: [ [ -22, -23 ], [ 23, 22 ] ],
	} );
} );

test( 'emits pointClick with the group key', async () => {
	const seen = [];
	const provider = await init();

	provider.on( 'pointClick', ( key ) => seen.push( key ) );
	provider.setPoints( [ { key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [] } ] );
	ymapsStub.lastObjectManager.fireObjectClick( 'a' );

	expect( seen ).toEqual( [ 'a' ] );
} );

test( 'a refetch that drops the focused group clears getFocusedKey()', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ), group( 'b', 2, 2 ) ] );
	await provider.focusGroup( 'a' );

	expect( provider.getFocusedKey() ).toBe( 'a' );

	provider.setPoints( [ group( 'b', 2, 2 ) ] ); // 'a' is gone from the new set

	expect( provider.getFocusedKey() ).toBeNull();
} );

// -------------------------------------------------------------------------
// setTypeFilter() — setFilter(), never a rebuild (D-10)
// -------------------------------------------------------------------------

test( 'filters by type through setFilter, not by rebuilding the manager', async () => {
	const provider = await init();

	provider.setTypeFilter( [ 'pvz' ] );

	expect( typeof ymapsStub.lastObjectManager.filter ).toBe( 'function' );
	expect( ymapsStub.lastObjectManager.removeAllCalls ).toBe( 0 );
} );

test( 'the stored filter function matches only the requested codes, and matches everything once '
	+ 'cleared — called with ONE argument, the feature itself, matching real ymaps (not the '
	+ '(objectId, object) shape a real bug on this branch assumed)', async () => {
	const provider = await init();

	provider.setTypeFilter( [ 'pvz', 'postamat' ] );

	const filterFn = ymapsStub.lastObjectManager.filter;

	expect( filterFn( { properties: { typeCode: 'pvz' } } ) ).toBe( true );
	expect( filterFn( { properties: { typeCode: 'other' } } ) ).toBe( false );

	provider.setTypeFilter( null );

	const clearedFilter = ymapsStub.lastObjectManager.filter;

	expect( clearedFilter( { properties: { typeCode: 'anything' } } ) ).toBe( true );
} );

// -------------------------------------------------------------------------
// _renderMarker() — direct unit tests, bypassing ymaps' own layout machinery
// -------------------------------------------------------------------------

test( 'the marker shows the group-size badge only when the group has more than one point', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( { groupSize: 3, iconHref: '/x.svg' } ) } );

	expect( container.querySelector( '.woodev-pickup-marker__badge' ).textContent ).toBe( '3' );
	expect( container.querySelector( '.woodev-pickup-marker--group' ) ).not.toBeNull();
} );

test( 'the marker renders from PLAIN feature properties, the shape ObjectManager actually passes', () => {
	// `makeProperties()` models a Placemark's data-manager, which has `.get()`. An
	// ObjectManager overlay's layout receives `properties` as the PLAIN JSON the feature was
	// added with — no `.get` at all. Reading it with `.get()` threw
	// `properties.get is not a function` inside ymaps' cross-origin script, where the browser
	// reports only a bare "Script error." with no stack: every marker rendered as an empty box
	// and dragging the map then span forever on `map.action.Continuous: ticking while inactive`.
	// Every test in this block used the data-manager shape, so none of them could see it.
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	expect( () => provider._renderMarker( container, {
		properties: { groupSize: 2, state: 'active', iconHref: '/x.svg', iconHrefActive: '/x-a.svg' },
	} ) ).not.toThrow();

	const marker = container.querySelector( '.woodev-pickup-marker' );

	expect( marker.getAttribute( 'data-state' ) ).toBe( 'active' );
	expect( marker.querySelector( 'img' ).getAttribute( 'src' ) ).toBe( '/x-a.svg' );
	expect( marker.querySelector( '.woodev-pickup-marker__badge' ).textContent ).toBe( '2' );
} );

test( 'the marker renders no <img> and draws the framework default pin (spec V-9), not the '
	+ 'deleted unknown modifier, when the type has no icon', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( { groupSize: 1, iconHref: '' } ) } );

	expect( container.querySelector( 'img' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-marker--unknown' ) ).toBeNull();
	expect( container.querySelector( 'svg.woodev-pickup-marker__pin' ) ).not.toBeNull();
	expect( container.querySelector( '.woodev-pickup-marker__badge' ) ).toBeNull();
} );

// -------------------------------------------------------------------------
// Default marker icon (spec V-9) — the framework's own inline SVG pin, drawn only when the
// plugin supplies no icon for the group's type.
// -------------------------------------------------------------------------

test( 'renders the framework default pin (map-pin) for the resting state when the plugin '
	+ 'supplies no icon', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'resting', iconHref: '', iconHrefActive: '',
	} ) } );

	const svg = container.querySelector( 'svg.woodev-pickup-marker__pin' );

	expect( container.querySelector( 'img' ) ).toBeNull();
	expect( svg ).not.toBeNull();
	expect( svg.getAttribute( 'data-pin' ) ).toBe( 'resting' );
} );

test( 'renders the check pin (map-pin-check) for the active state when the plugin supplies no '
	+ 'icon', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'active', iconHref: '', iconHrefActive: '',
	} ) } );

	expect( container.querySelector( 'svg' ).getAttribute( 'data-pin' ) ).toBe( 'active' );
} );

test( 'still prefers the plugin image over the framework default pin when one is configured', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'resting', iconHref: '/pvz.svg', iconHrefActive: '/a.svg',
	} ) } );

	expect( container.querySelector( 'img' ).getAttribute( 'src' ) ).toBe( '/pvz.svg' );
	expect( container.querySelector( 'svg.woodev-pickup-marker__pin' ) ).toBeNull();
} );

// Review follow-up (MEDIUM, D-5): the active state must actually be RENDERED — a distinct
// active image when the plugin supplies one, `data-state="active"` always, for Task 21's CSS to
// key off — not just a bigger hit-box with the same resting image forever.
test( 'restingly-drawn marker: data-state is "resting" and the DEFAULT image is used', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'resting', iconHref: '/rest.svg', iconHrefActive: '/active.svg',
	} ) } );

	const root = container.querySelector( '.woodev-pickup-marker' );

	expect( root.getAttribute( 'data-state' ) ).toBe( 'resting' );
	expect( container.querySelector( 'img' ).src ).toContain( '/rest.svg' );
} );

test( 'active marker with two distinct images (Yandex-reference style): data-state is "active" '
	+ 'and the ACTIVE image is used, not the resting one', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'active', iconHref: '/rest.svg', iconHrefActive: '/active.svg',
	} ) } );

	const root = container.querySelector( '.woodev-pickup-marker' );

	expect( root.getAttribute( 'data-state' ) ).toBe( 'active' );
	expect( container.querySelector( 'img' ).src ).toContain( '/active.svg' );
} );

test( 'active marker with only ONE image supplied (CDEK-style): data-state is "active" and the '
	+ 'SAME (mirrored) image renders in the larger box, never a broken one', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	// Mirrors Pickup_Handler::normalized_point_icons(): `active` falls back to `default` when
	// the plugin supplied only one image, so iconHrefActive is never actually empty for a known
	// type — this test still exercises _renderMarker's OWN fallback independently of that
	// server-side guarantee.
	provider._renderMarker( container, { properties: makeProperties( {
		groupSize: 1, state: 'active', iconHref: '/only.svg', iconHrefActive: '/only.svg',
	} ) } );

	const root = container.querySelector( '.woodev-pickup-marker' );

	expect( root.getAttribute( 'data-state' ) ).toBe( 'active' );
	expect( container.querySelector( 'img' ).src ).toContain( '/only.svg' );
	expect( container.querySelector( '.woodev-pickup-marker--unknown' ) ).toBeNull();
} );

// -------------------------------------------------------------------------
// Camera — initial viewport per strategy (Task 18, D-7)
// -------------------------------------------------------------------------

test( 'fits to the loaded points under bulk without geocoding, and the bounds actually contain them', async () => {
	const provider = await init( { strategy: 'bulk', locality: 'Москва' } );

	provider.setPoints( [ group( 'a', 55.70, 37.60 ), group( 'b', 55.80, 37.70 ) ] );

	expect( ymapsStub.geocodeCalls ).toBe( 0 );
	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 1 );

	const bounds = ymapsStub.lastMap.setBoundsCalls[ 0 ].bounds;

	expect( bounds[ 0 ][ 0 ] ).toBeLessThanOrEqual( 55.70 );
	expect( bounds[ 0 ][ 1 ] ).toBeLessThanOrEqual( 37.60 );
	expect( bounds[ 1 ][ 0 ] ).toBeGreaterThanOrEqual( 55.80 );
	expect( bounds[ 1 ][ 1 ] ).toBeGreaterThanOrEqual( 37.70 );
} );

// Review follow-up (HIGH): the bulk fit's setBounds() was fire-and-forget and visibleChange was
// emitted from the map's PRE-fit bounds — the s46 failure verbatim, reproduced INSIDE this
// rewrite. Points sitting outside the technical placeholder viewport used to report an empty (or
// stale) visible-key list that nothing ever corrected, since bulk has no fetch-driven refresh
// cycle. A test that only checks setBounds() was CALLED cannot see this — it has to assert the
// emitted keys, before and after the move settles.
test( 'bulk: visibleChange reflects the POST-fit viewport — points sitting outside the pre-fit '
	+ 'viewport are correctly reported once the fit completes, not before', async () => {
	const provider = await init( { strategy: 'bulk' }, { deferSetBounds: true } );
	const seen = [];

	provider.on( 'visibleChange', ( keys ) => seen.push( keys ) );

	// Far outside the stub Map's default pre-fit bounds ([[10,20],[11,21]]).
	provider.setPoints( [ group( 'a', 55.70, 37.60 ), group( 'b', 55.80, 37.70 ) ] );

	// The fit's setBounds() was called but has not resolved yet — nothing emitted so far.
	expect( seen ).toHaveLength( 0 );

	ymapsStub.lastMap.resolveNextSetBounds();
	await flushPromises();

	expect( seen ).toHaveLength( 1 );
	expect( seen[ 0 ].sort() ).toEqual( [ 'a', 'b' ] );
} );

test( 'bulk: a destroy() racing the in-flight fit never emits visibleChange afterwards', async () => {
	const provider = await init( { strategy: 'bulk' }, { deferSetBounds: true } );
	const seen = [];

	provider.on( 'visibleChange', ( keys ) => seen.push( keys ) );

	provider.setPoints( [ group( 'a', 55.70, 37.60 ) ] );
	provider.destroy();

	ymapsStub.lastMap.resolveNextSetBounds();
	await flushPromises();

	expect( seen ).toHaveLength( 0 );
} );

test( 'geocodes the locality under viewport', async () => {
	await init( { strategy: 'viewport', locality: 'Москва' } );

	expect( ymapsStub.geocodeCalls ).toBe( 1 );
} );

test( 'falls back to the plugin default when the geocode is empty', async () => {
	ymapsStub.geocodeResult = null;

	await init( {
		strategy: 'viewport',
		locality: 'Нетакогогорода',
		defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 },
	} );

	expect( ymapsStub.lastMap.setCenterCalls[ 0 ] ).toEqual( [ [ 55.76, 37.64 ], 12 ] );
} );

test( 'uses the plugin default without geocoding when there is no locality', async () => {
	await init( {
		strategy: 'viewport',
		locality: '',
		defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 },
	} );

	expect( ymapsStub.geocodeCalls ).toBe( 0 );
	expect( ymapsStub.lastMap.setCenterCalls ).toHaveLength( 1 );
} );

// Review follow-up (HIGH): bulk DOES need a boundschange listener — the customer can pan/zoom
// under bulk too, and the panel's "points currently in the viewport" list must track that, even
// though nothing needs re-fetching (the whole locality is already loaded). It must never emit
// boundsChange/bboxTooWide, though — bulk never re-fetches by viewport.
test( 'bulk: a boundschange listener recomputes visibleChange only — never emits '
	+ 'boundsChange/bboxTooWide (bulk never re-fetches by viewport)', async () => {
	const provider = await init( { strategy: 'bulk' } );
	const seenVisible = [];
	const seenBoundsChange = [];
	const seenTooWide = [];

	provider.setPoints( [ group( 'a', 5, 5 ), group( 'b', 50, 50 ) ] );
	provider.on( 'visibleChange', ( keys ) => seenVisible.push( keys ) );
	provider.on( 'boundsChange', () => seenBoundsChange.push( 1 ) );
	provider.on( 'bboxTooWide', () => seenTooWide.push( 1 ) );

	ymapsStub.lastMap.bounds = [ [ 0, 0 ], [ 10, 10 ] ];
	ymapsStub.lastMap.fireBoundsChange();

	expect( seenVisible[ 0 ] ).toEqual( [ 'a' ] );
	expect( seenBoundsChange ).toHaveLength( 0 );
	expect( seenTooWide ).toHaveLength( 0 );
} );

test( 'viewport: emits boundsChange once for the initial (already-resolved) viewport, before any pan', async () => {
	const seen = [];
	const config = makeConfig( { strategy: 'viewport', locality: '' } );

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();

	provider.on( 'boundsChange', ( bbox ) => seen.push( bbox ) );

	await provider.init( document.createElement( 'div' ), config );

	expect( seen ).toHaveLength( 1 );
} );

// Fix A regression guard, adapted from the previous version of this file. ymaps' setBounds() is
// ASYNCHRONOUS — it resolves once the camera move completes, not when it starts.
// _resolveInitialViewport() must RETURN that promise so the boundsChange this file emits right
// after reflects the POST-move viewport, not the pre-move one. Dropping the `return` there would
// let the emit fire with whatever `map.getBounds()` reports BEFORE the move settles.
test( 'viewport: the boundsChange emitted for the initial viewport reflects the POST-move bounds — '
	+ 'proves the setBounds() promise in _resolveInitialViewport() is awaited, not dropped', async () => {
	ymapsStub = createYmapsStub( { deferSetBounds: true } );

	const postMoveBounds = [ [ 55, 37 ], [ 56, 38 ] ];

	ymapsStub.geocodeResult = makeGeocodeResult( postMoveBounds );

	const config = makeConfig( { strategy: 'viewport', locality: 'Казань' } );

	window[ config.ns ] = ymapsStub;

	const provider = new WoodevYandexMapProvider();
	const seen = [];

	provider.on( 'boundsChange', ( bbox ) => seen.push( bbox ) );

	const initPromise = provider.init( document.createElement( 'div' ), config );

	await flushPromises();

	// The geocode resolved and setBounds() was CALLED with the post-move bounds, but its own
	// promise has not resolved yet — nothing has been emitted yet.
	expect( seen ).toHaveLength( 0 );
	expect( ymapsStub.lastMap.setBoundsCalls[ 0 ].bounds ).toEqual( postMoveBounds );

	ymapsStub.lastMap.resolveNextSetBounds(); // simulate the camera move completing
	await initPromise;

	expect( seen[ 0 ] ).toEqual( [ 55, 37, 56, 38 ] ); // the POST-move bbox, flattened
} );

// -------------------------------------------------------------------------
// bbox cap (D-4) — emit bboxTooWide instead of fetching when the viewport is too wide
// -------------------------------------------------------------------------

test( 'emits bboxTooWide instead of boundsChange when the bbox exceeds the server cap', async () => {
	const seen = [];
	const provider = await init( { strategy: 'viewport' } );

	provider.on( 'bboxTooWide', () => seen.push( 1 ) );
	ymapsStub.lastMap.bounds = [ [ 40, 20 ], [ 60, 60 ] ]; // 40° wide, cap is 10°
	ymapsStub.lastMap.fireBoundsChange();

	expect( seen ).toHaveLength( 1 );
} );

test( 'emits boundsChange with the flattened bbox when the viewport is within the cap', async () => {
	const seen = [];
	const provider = await init( { strategy: 'viewport', locality: '' } );

	provider.on( 'boundsChange', ( bbox ) => seen.push( bbox ) );
	ymapsStub.lastMap.bounds = [ [ 10, 20 ], [ 11, 22 ] ];
	ymapsStub.lastMap.fireBoundsChange();

	expect( seen[ 0 ] ).toEqual( [ 10, 20, 11, 22 ] );
} );

// -------------------------------------------------------------------------
// visibleChange — the keys of the currently-loaded groups inside the current bounds
// -------------------------------------------------------------------------

test( 'visibleChange carries only the keys of groups inside the current bounds', async () => {
	const seen = [];
	const provider = await init( { strategy: 'viewport', locality: '' } );

	ymapsStub.lastMap.bounds = [ [ 0, 0 ], [ 10, 10 ] ];
	provider.on( 'visibleChange', ( keys ) => seen.push( keys ) );

	provider.setPoints( [ group( 'inside', 5, 5 ), group( 'outside', 50, 50 ) ] );

	expect( seen[ seen.length - 1 ] ).toEqual( [ 'inside' ] );
} );

// -------------------------------------------------------------------------
// focusGroup() — the co-located ("Russian Post") guard, camera un-clustering, sequencing
// -------------------------------------------------------------------------

test( 'does not try to zoom a group whose points all share one coordinate', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: [ 55.75, 37.61 ] } },
				{ geometry: { coordinates: [ 55.75, 37.61 ] } },
			],
		},
	};

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
	// The card must still open even though the marker itself stays visually clustered forever —
	// "do not try to zoom" is not "refuse to focus".
	expect( provider.getFocusedKey() ).toBe( 'a' );
} );

test( 'zooms a genuine cluster and awaits the move before reporting', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: [ 55.75, 37.61 ] } },
				{ geometry: { coordinates: [ 55.76, 37.62 ] } },
			],
		},
	};

	await provider.focusGroup( 'a' );

	// Spec §7.5's call verbatim — zoomMargin/useMapMargin are not decoration: without
	// useMapMargin the focused point can end up centred underneath the open sidebar's own
	// map.margin area, where the customer cannot see it.
	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 1 );
	expect( ymapsStub.lastMap.setBoundsCalls[ 0 ] ).toEqual( {
		bounds: [ [ 55.75, 37.61 ], [ 55.75, 37.61 ] ],
		options: { checkZoomRange: true, zoomMargin: 0, useMapMargin: true },
	} );
	expect( provider.getFocusedKey() ).toBe( 'a' );
} );

// Review follow-up (MEDIUM): zooming further is impossible once the map is already at MAX_ZOOM,
// so the guard is the same shape as the co-located one — do not attempt a pointless camera move,
// but still apply focus directly (the card must still open).
test( 'at MAX_ZOOM, focusGroup applies focus directly without attempting a pointless camera move', async () => {
	const provider = await init( {}, { zoom: 18 } );

	ymapsStub.lastObjectManager.state = {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: [ 1, 1 ] } },
				{ geometry: { coordinates: [ 2, 2 ] } },
			],
		},
	};

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
	expect( provider.getFocusedKey() ).toBe( 'a' );
} );

test( 'a group that is not currently clustered STILL recentres the camera onto its own point '
	+ '(spec V-10 — a plain marker click must behave like a sidebar row click)', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.75, 37.61 ) ] );
	// setPoints() itself fits the camera to the loaded set under `bulk` (a separate, already
	// covered behaviour) — clear that call so this test asserts only what focusGroup() does.
	ymapsStub.lastMap.setBoundsCalls.length = 0;
	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 1 );
	expect( ymapsStub.lastMap.setBoundsCalls[ 0 ] ).toEqual( {
		bounds: [ [ 55.75, 37.61 ], [ 55.75, 37.61 ] ],
		options: { checkZoomRange: true, zoomMargin: 0, useMapMargin: true },
	} );
	expect( provider.getFocusedKey() ).toBe( 'a' );
} );

test( 'a not-clustered group already at MAX_ZOOM applies focus without a pointless camera move', async () => {
	const provider = await init( {}, { zoom: 18 } );

	provider.setPoints( [ group( 'a', 55.75, 37.61 ) ] );
	ymapsStub.lastMap.setBoundsCalls.length = 0;
	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
	expect( provider.getFocusedKey() ).toBe( 'a' );
} );

test( 'an unknown group (no coordinates on record) applies focus without attempting a move — '
	+ 'defensive only, since a real click always names a group this provider drew itself', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'ghost' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
	expect( provider.getFocusedKey() ).toBe( 'ghost' );
} );

test( 're-checks getObjectState AFTER the move and does not apply focus if it is still a '
	+ 'degenerate cluster post-move', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: [ 1, 1 ] } },
				{ geometry: { coordinates: [ 2, 2 ] } },
			],
		},
	};

	const focusPromise = provider.focusGroup( 'a' );

	// Simulate ymaps reporting, once the camera settles, that 'a' is STILL folded into a
	// single-coordinate cluster — the move failed to separate it.
	ymapsStub.lastObjectManager.state = {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: [ 5, 5 ] } },
				{ geometry: { coordinates: [ 5, 5 ] } },
			],
		},
	};

	await focusPromise;

	expect( provider.getFocusedKey() ).toBeNull();
} );

test( 'ignores a stale focus continuation when a second focus started first', async () => {
	const provider = await init();
	const slow = provider.focusGroup( 'a' );
	const fast = provider.focusGroup( 'b' );

	await Promise.all( [ slow, fast ] );

	expect( provider.getFocusedKey() ).toBe( 'b' );
} );

// The adversarial version of the test above: TRUE out-of-order promise resolution (the earlier
// call's camera move settles AFTER the later call's), which is the only way to actually exercise
// `_focusSeq` — with both moves resolving synchronously in call order (as the test above does),
// a naive implementation with no sequencing guard at all would coincidentally produce the same
// result. See the file docblock's second lesson and `focusGroup()`'s own docblock.
test( 'focusGroup: an earlier focus\'s camera move resolving AFTER a later focus\'s does not leave the '
	+ 'stale group focused (out-of-order resolution guard for _focusSeq)', async () => {
	const provider = await init( {}, { deferSetBounds: true } );

	// Each key resolves to a DIFFERENT cluster anchor, so the two setBounds() calls below are
	// distinguishable by their bounds argument — required to resolve them out of order.
	ymapsStub.lastObjectManager.stateFor = ( key ) => ( {
		isClustered: true,
		cluster: {
			features: [
				{ geometry: { coordinates: 'a' === key ? [ 1, 1 ] : [ 2, 2 ] } },
				{ geometry: { coordinates: [ 9, 9 ] } },
			],
		},
	} );

	provider.focusGroup( 'a' ); // clicked first
	provider.focusGroup( 'b' ); // clicked second (most recent)

	const boundsA = ymapsStub.lastMap.setBoundsCalls[ 0 ].bounds;
	const boundsB = ymapsStub.lastMap.setBoundsCalls[ 1 ].bounds;

	// B's move resolves FIRST (e.g. a shorter distance to travel) — out of click order.
	ymapsStub.lastMap.resolveSetBoundsFor( boundsB );
	await flushPromises();

	expect( provider.getFocusedKey() ).toBe( 'b' );

	// A's move resolves LAST — it must NOT stomp B's now-focused group.
	ymapsStub.lastMap.resolveSetBoundsFor( boundsA );
	await flushPromises();

	expect( provider.getFocusedKey() ).toBe( 'b' );
} );

test( 'focusGroup switches the icon box to ACTIVE for the newly focused group and back to '
	+ 'RESTING for the previously focused one', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastObjectManager.objects.setObjectOptions ).toHaveBeenLastCalledWith( 'a', {
		iconImageSize: [ 50, 70 ],
		iconImageOffset: [ -25, -40 ],
		iconShape: { type: 'Rectangle', coordinates: [ [ -25, -40 ], [ 25, 30 ] ] },
	} );

	await provider.focusGroup( 'b' );

	expect( ymapsStub.lastObjectManager.objects.setObjectOptions ).toHaveBeenCalledWith( 'a', {
		iconImageSize: [ 45, 45 ],
		iconImageOffset: [ -22, -23 ],
		iconShape: { type: 'Rectangle', coordinates: [ [ -22, -23 ], [ 23, 22 ] ] },
	} );
	expect( ymapsStub.lastObjectManager.objects.setObjectOptions ).toHaveBeenLastCalledWith( 'b', {
		iconImageSize: [ 50, 70 ],
		iconImageOffset: [ -25, -40 ],
		iconShape: { type: 'Rectangle', coordinates: [ [ -25, -40 ], [ 25, 30 ] ] },
	} );
} );

// Spec V-9: the hit area must follow the CURRENT state's box, not just its own default — a
// focused marker whose `iconShape` still described the small resting box would be clickable
// only across part of its own (now larger) artwork. See gotcha
// `ymaps-html-icon-layout-needs-iconshape.md`.
test( 'declares the larger iconShape for a focused group (spec V-9)', async () => {
	const provider = await init();

	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'a' );

	const call = ymapsStub.lastObjectManager.objects.setObjectOptions.mock.calls.find(
		( args ) => 'a' === args[ 0 ]
	);

	expect( call[ 1 ].iconShape ).toEqual( {
		type: 'Rectangle',
		coordinates: [ [ -25, -40 ], [ 25, 30 ] ],
	} );
} );

// Review follow-up (MEDIUM, D-5): focusing must also push the ACTIVE state (and therefore the
// active image) onto the feature's properties, via setObjectProperties() — not just resize its
// hit-box. This is the wiring half; _renderMarker's own state-driven rendering is pinned
// directly above.
test( 'focusGroup pushes state="active"/iconHrefActive via setObjectProperties, and reverts the '
	+ 'previous group to state="resting"', async () => {
	const provider = await init( { pointIcons: { pvz: { default: '/d.svg', active: '/a.svg' } } } );

	provider.setPoints( [ group( 'x', 1, 1, 'pvz' ), group( 'y', 2, 2, 'pvz' ) ] );
	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };

	await provider.focusGroup( 'x' );

	expect( ymapsStub.lastObjectManager.objects.setObjectProperties ).toHaveBeenLastCalledWith(
		'x',
		expect.objectContaining( { state: 'active', iconHref: '/d.svg', iconHrefActive: '/a.svg' } )
	);

	await provider.focusGroup( 'y' );

	expect( ymapsStub.lastObjectManager.objects.setObjectProperties ).toHaveBeenCalledWith(
		'x',
		expect.objectContaining( { state: 'resting' } )
	);
	expect( ymapsStub.lastObjectManager.objects.setObjectProperties ).toHaveBeenLastCalledWith(
		'y',
		expect.objectContaining( { state: 'active' } )
	);
} );

// -------------------------------------------------------------------------
// setPoints() rebuild vs. an already-focused group (review follow-up, MEDIUM)
// -------------------------------------------------------------------------

test( 'setPoints() re-applies the ACTIVE state to the focused group when it survives the rebuild '
	+ '(a rebuild resets every feature to resting)', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ), group( 'b', 2, 2 ) ] );
	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };
	await provider.focusGroup( 'a' );

	ymapsStub.lastObjectManager.objects.setObjectOptions.mockClear();
	ymapsStub.lastObjectManager.objects.setObjectProperties.mockClear();

	provider.setPoints( [ group( 'a', 1, 1 ), group( 'b', 2, 2 ) ] ); // refetch, 'a' still present

	expect( provider.getFocusedKey() ).toBe( 'a' );
	expect( ymapsStub.lastObjectManager.objects.setObjectOptions ).toHaveBeenCalledWith( 'a', {
		iconImageSize: [ 50, 70 ],
		iconImageOffset: [ -25, -40 ],
		iconShape: { type: 'Rectangle', coordinates: [ [ -25, -40 ], [ 25, 30 ] ] },
	} );
	expect( ymapsStub.lastObjectManager.objects.setObjectProperties ).toHaveBeenCalledWith(
		'a',
		expect.objectContaining( { state: 'active' } )
	);
} );

// -------------------------------------------------------------------------
// Address search (Task 12/19, D-6, spec V-6/V-7) — the SearchControl's custom geocode
// provider, resolveAddress()/focusAddress(), and the "your address" pin lifecycle
// -------------------------------------------------------------------------

// Gotcha `ymaps-control-options-must-be-nested.md`: ymaps controls read exactly
// `{ data, options, state }` at the root and silently drop anything else. The whole of the
// operator's original search complaint was `provider`/`layout`/`resultsLayout`/`noPlacemark`
// sitting at the ROOT of the constructor argument — asserting the root is CLEAN (not merely
// that `options.x` exists) is the only way to catch that regression: the OLD buggy code also
// happened to have `.provider` reachable, just via a different (wrong) path.
test( 'passes layout, noPlacemark, float and the bounded provider under `options` — the ROOT of '
	+ 'the constructor argument carries nothing else', async () => {
	await init();

	expect( ymapsStub.lastSearchControl.rootArgs.layout ).toBeUndefined();
	expect( ymapsStub.lastSearchControl.rootArgs.provider ).toBeUndefined();
	expect( ymapsStub.lastSearchControl.rootArgs.noPlacemark ).toBeUndefined();
	expect( ymapsStub.lastSearchControl.rootArgs.float ).toBeUndefined();
	expect( ymapsStub.lastSearchControl.rootArgs.position ).toBeUndefined();

	expect( ymapsStub.lastSearchControl.options.layout ).toBeDefined();
	expect( ymapsStub.lastSearchControl.options.noPlacemark ).toBe( true );
	expect( typeof ymapsStub.lastSearchControl.options.provider.geocode ).toBe( 'function' );
} );

test( 'positions the control top-left, floating free (Russian Post\'s chrome, spec V-6)', async () => {
	await init();

	expect( ymapsStub.lastSearchControl.options.float ).toBe( 'none' );
	expect( ymapsStub.lastSearchControl.options.position ).toEqual( { left: '16px', right: 'auto', top: '16px' } );
} );

test( 'builds no SearchControl at all when the plugin disabled search (searchLayoutEl is null, '
	+ 'spec V-6 visibility)', async () => {
	const provider = await init( { searchLayoutEl: null } );

	expect( ymapsStub.lastSearchControl ).toBeUndefined();
	expect( provider.searchControl ).toBeNull();
} );

test( 'returns point matches from the loaded pool, excluding a non-matching point from the same '
	+ 'pool, alongside the geocoded addresses', async () => {
	const provider = await init();

	provider.setPoints( [
		groupWith( { id: '1', name: 'ПВЗ «Магнит»', address: 'Ленина 5' } ),
		groupWith( { id: '2', name: 'ПВЗ «Пятёрочка»', address: 'Гагарина 10' } ), // must NOT match
	] );
	ymapsStub.geocodeResult = makeSearchGeocodeResult( [ 'Москва, Ленина 5' ] );

	const seen = [];
	provider.on( 'searchResults', ( results ) => seen.push( results ) );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'ленина' );

	expect( seen[ 0 ].points ).toHaveLength( 1 );
	expect( seen[ 0 ].points[ 0 ].id ).toBe( '1' ); // NOT '2' — a mutant returning the whole pool fails here
	expect( seen[ 0 ].addresses ).toEqual( [ { displayName: 'Москва, Ленина 5' } ] );
} );

test( 'geocodes (never suggests) on every call — this path is only ever reached via '
	+ 'control.search(), which the mount wires to a DELIBERATE submit, never a keystroke', async () => {
	await init();

	await ymapsStub.lastSearchControl.options.provider.geocode( 'ленина' );

	expect( ymapsStub.geocodeCalls ).toBe( 1 );
} );

test( 'bounds the geocode strictly to the loaded point set once something has loaded (spec V-7)', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.70, 37.60 ), group( 'b', 55.80, 37.70 ) ] );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'Тверская' );

	const expectedBounds = geo.boundsFor( [ 55.70, 37.60 ], [ group( 'a', 55.70, 37.60 ), group( 'b', 55.80, 37.70 ) ] );

	expect( ymapsStub.lastGeocodeOptions ).toEqual( {
		results: 5,
		boundedBy: expectedBounds,
		strictBounds: true,
	} );
} );

test( 'omits the bounds before anything has ever loaded (spec V-7 — no degenerate box)', async () => {
	await init();

	await ymapsStub.lastSearchControl.options.provider.geocode( 'Тверская' );

	expect( ymapsStub.lastGeocodeOptions ).toEqual( { results: 5 } );
	expect( ymapsStub.lastGeocodeOptions.boundedBy ).toBeUndefined();
	expect( ymapsStub.lastGeocodeOptions.strictBounds ).toBeUndefined();
} );

test( 'pads a ZERO-AREA box so a one-point city can still be searched (Codex critic)', async () => {
	const provider = await init();

	// One loaded group — or several sharing a building — collapses the box to a point, and
	// `strictBounds: true` against zero area matches NOTHING: the customer could type their own
	// address forever and never see a result. This is issue #150's city.
	provider.setPoints( [ group( 'only', 55.75, 37.61 ) ] );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'Тверская' );

	const bounds = ymapsStub.lastGeocodeOptions.boundedBy;

	expect( bounds[ 1 ][ 0 ] - bounds[ 0 ][ 0 ] ).toBeCloseTo( 0.1, 10 );
	expect( bounds[ 1 ][ 1 ] - bounds[ 0 ][ 1 ] ).toBeCloseTo( 0.1, 10 );

	// Still centred on the point it was grown from.
	expect( ( bounds[ 0 ][ 0 ] + bounds[ 1 ][ 0 ] ) / 2 ).toBeCloseTo( 55.75, 10 );
	expect( ( bounds[ 0 ][ 1 ] + bounds[ 1 ][ 1 ] ) / 2 ).toBeCloseTo( 37.61, 10 );
} );

test( 'leaves a box that is already wide enough alone (Codex critic)', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.50, 37.30 ), group( 'b', 56.10, 37.95 ) ] );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'Тверская' );

	expect( ymapsStub.lastGeocodeOptions.boundedBy ).toEqual( [ [ 55.50, 37.30 ], [ 56.10, 37.95 ] ] );
} );

test( 'a refused geocode still publishes the locally matched points (Codex critic)', async () => {
	const provider = await init();
	const seen = [];

	provider.setPoints( [ groupWith( { id: '1', name: 'ПВЗ «Ленина»', address: 'Ленина 5', lat: 55.70, lng: 37.60 } ) ] );
	provider.on( 'searchResults', ( payload ) => seen.push( payload ) );

	ymapsStub.geocodeImpl = () => Promise.reject( new Error( 'quota exceeded' ) );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'ленина' );

	// A failed geocode must not leave the customer staring at whatever was on screen before they
	// searched — the local matches cost no network and still stand.
	expect( seen ).toHaveLength( 1 );
	expect( seen[ 0 ].points ).toHaveLength( 1 );
	expect( seen[ 0 ].addresses ).toEqual( [] );
} );

test( 'a geocode that resolves after destroy() emits nothing (Codex critic)', async () => {
	const provider = await init();
	const seen = [];

	provider.setPoints( [ groupWith( { id: '1', name: 'ПВЗ «Ленина»', address: 'Ленина 5', lat: 55.70, lng: 37.60 } ) ] );
	provider.on( 'searchResults', ( payload ) => seen.push( payload ) );

	let release;
	ymapsStub.geocodeImpl = () => new Promise( ( resolve ) => {
		release = resolve;
	} );

	const pending = ymapsStub.lastSearchControl.options.provider.geocode( 'ленина' );

	// The customer closes the dialog while the request is in flight.
	provider.destroy();
	release( makeSearchGeocodeResult( [ 'Москва, Ленина 5' ] ) );
	await pending;

	// Emitting now would push results at a torn-down panels instance — or at the fresh one a
	// reopen already built, showing results for a query the customer abandoned.
	expect( seen ).toHaveLength( 0 );
} );

test( 'tags each geocoded address\'s OWN properties with woodevKind, and returns the real '
	+ 'geocode geoObjects untouched for the control\'s engine — no synthetic Placemark is ever '
	+ 'mixed in (see the file docblock\'s "WHY A MATCHED POINT IS NEVER MIXED IN" note)', async () => {
	await init();

	ymapsStub.geocodeResult = makeSearchGeocodeResult( [ 'Москва, Тверская 1' ] );

	const resolved = await ymapsStub.lastSearchControl.options.provider.geocode( 'Тверская' );
	const hit = resolved.geoObjects.toArray()[ 0 ];

	expect( hit.properties.get( 'woodevKind' ) ).toBe( 'address' );
	expect( resolved.geoObjects ).toBe( ymapsStub.geocodeResult.geoObjects ); // passed through, not rebuilt
} );

test( 'emits searchResults with EMPTY arrays for a query that matches nothing — a narrowed-down '
	+ 'search must not leave the previous (stale) results on screen', async () => {
	const seen = [];
	const provider = await init();

	provider.on( 'searchResults', ( results ) => seen.push( results ) );
	ymapsStub.geocodeResult = makeSearchGeocodeResult( [] );

	await ymapsStub.lastSearchControl.options.provider.geocode( 'несуществующийзапрос' );

	expect( seen ).toHaveLength( 1 );
	expect( seen[ 0 ] ).toEqual( { points: [], addresses: [] } );
} );

test( 'matchLoadedPoints() matches the loaded pool for free, without ever touching the geocoder '
	+ '— the public half of the same matching the debounced searchType event uses', async () => {
	const provider = await init();

	provider.setPoints( [
		groupWith( { id: '1', name: 'ПВЗ «Магнит»', address: 'Ленина 5' } ),
		groupWith( { id: '2', name: 'ПВЗ «Пятёрочка»', address: 'Гагарина 10' } ),
	] );

	const matches = provider.matchLoadedPoints( 'ленина' );

	expect( matches.map( ( p ) => p.id ) ).toEqual( [ '1' ] );
	expect( ymapsStub.geocodeCalls ).toBe( 0 );
} );

test( 'geocodes exactly once when an address result is chosen', async () => {
	const provider = await init();

	await provider.resolveAddress( 'Москва, Ленина 5' );

	expect( ymapsStub.geocodeCalls ).toBe( 1 );
} );

// -------------------------------------------------------------------------
test( 'clearAddress() emits an empty searchResults alongside dropping the pin — the reset '
	+ 'control must not leave stale search rows behind', async () => {
	const seen = [];
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.751 ) ] );
	await provider.focusAddress( [ 55.75, 37.61 ], 'X' ); // picking a result must NOT itself clear

	provider.on( 'searchResults', ( results ) => seen.push( results ) );
	provider.clearAddress();

	expect( seen ).toHaveLength( 1 );
	expect( seen[ 0 ] ).toEqual( { points: [], addresses: [] } );
} );

test( 'picking an address result (focusAddress) never emits searchResults as a side effect of '
	+ 'dropping the pin — only clearAddress() clears the search results state', async () => {
	const seen = [];
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.751 ) ] );
	provider.on( 'searchResults', ( results ) => seen.push( results ) );

	await provider.focusAddress( [ 55.75, 37.61 ], 'First' );
	await provider.focusAddress( [ 55.8, 37.65 ], 'Second' ); // replaces the pin

	expect( seen ).toHaveLength( 0 );
} );

test( 'resolveAddress() end-to-end: the geocoded coordinates drive the same fit focusAddress() '
	+ 'would, not just a call count', async () => {
	const provider = await init();

	// Under the default `bulk` strategy setPoints() itself triggers its OWN camera fit — the
	// count below is taken as a DELTA from here, so that fit is never mistaken for
	// resolveAddress()'s own.
	provider.setPoints( [ group( 'a', 55.751 ), group( 'b', 55.999 ) ] );
	ymapsStub.geocodeResult = makeGeocodeResult( [ [ 10, 20 ], [ 11, 21 ] ], [ 55.75, 37.61 ] );

	const callsBefore = ymapsStub.lastMap.setBoundsCalls.length;

	await provider.resolveAddress( 'Москва, Ленина 5' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( callsBefore + 1 );
	// group 'b' (55.999) is far outside the default nearest-3 fit's own test below — here there
	// are only two groups, both within the default count of 3, so the fit must include 'b' too.
	const fitted = ymapsStub.lastMap.setBoundsCalls[ ymapsStub.lastMap.setBoundsCalls.length - 1 ].bounds;

	expect( fitted[ 1 ][ 0 ] ).toBeGreaterThanOrEqual( 55.999 );
} );

test( 'resolveAddress() is a silent no-op when the geocode result has no usable coordinates', async () => {
	const provider = await init();

	ymapsStub.geocodeResult = { geoObjects: { get: () => null } };

	await expect( provider.resolveAddress( 'X' ) ).resolves.toBeUndefined();

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
} );

test( 'fits the address plus the three nearest groups — the fitted bounds are the EXACT box '
	+ 'containing all three, not just the single closest one, and the fourth is excluded', async () => {
	const provider = await init();
	const anchor = [ 55.75, 37.61 ];
	const groups = [ group( 'a', 55.751 ), group( 'b', 55.752 ), group( 'c', 55.753 ),
		group( 'd', 55.999 ) ];

	provider.setPoints( groups );
	await provider.focusAddress( anchor, 'Москва, Ленина 5' );

	const fitted = ymapsStub.lastMap.setBoundsCalls.pop().bounds;
	// The exact box built from the anchor + a, b, c (NOT d) — a mutant that fit to only the
	// single closest group ('a') would produce a smaller, DIFFERENT box and fail this equality.
	const expected = geo.boundsFor( anchor, [ groups[ 0 ], groups[ 1 ], groups[ 2 ] ] );

	expect( fitted ).toEqual( expected );
	expect( fitted[ 1 ][ 0 ] ).toBeLessThan( 55.999 ); // the far group is not in frame
} );

test( 'honours the nearest-count filter value handed in by config', async () => {
	const provider = await init( { searchNearestCount: 1 } );
	const anchor = [ 55.75, 37.61 ];
	const groups = [ group( 'a', 55.751 ), group( 'b', 55.9 ) ];

	provider.setPoints( groups );
	await provider.focusAddress( anchor, 'X' );

	const fitted = ymapsStub.lastMap.setBoundsCalls.pop().bounds;
	// count: 1 — the box must be built from 'a' ALONE, never both groups.
	const expected = geo.boundsFor( anchor, [ groups[ 0 ] ] );

	expect( fitted ).toEqual( expected );
} );

// The threshold's two sides, pinned together (Codex review follow-up, MEDIUM): a mutant that
// e.g. shrinks NEARBY_THRESHOLD_M to 1 would still make the FAR case below assert
// `> 50000` correctly, so that assertion alone cannot catch it — only a NEAR case that must
// still FIT proves the threshold itself, not just "some threshold exists".
// addressFocused (Task 20 follow-up, D-6): the panels' distance anchor moves through this seam —
// it must fire UNCONDITIONALLY, right when the pin drops, regardless of what the nearest-N
// computation decides afterwards.
test( 'focusAddress() emits addressFocused with the exact latLng/label, before any nearest-N fit', async () => {
	const seen = [];
	const provider = await init();
	const anchor = [ 55.75, 37.61 ];

	provider.on( 'addressFocused', ( info ) => seen.push( info ) );
	provider.setPoints( [ group( 'near', 55.8 ) ] );

	await provider.focusAddress( anchor, 'Тверская 1' );

	expect( seen ).toEqual( [ { latLng: anchor, label: 'Тверская 1' } ] );
} );

test( 'focusAddress() emits addressFocused even when nothingNearby fires (the pin still dropped)', async () => {
	const seen = [];
	const provider = await init();
	const anchor = [ 55.75, 37.61 ];
	const farGroup = groupWith( { id: 'far', name: 'ПВЗ «Далеко»', lat: 56.6 } ); // beyond threshold

	provider.on( 'addressFocused', ( info ) => seen.push( info ) );
	provider.setPoints( [ farGroup ] );

	await provider.focusAddress( anchor, 'Y' );

	expect( seen ).toEqual( [ { latLng: anchor, label: 'Y' } ] );
} );

test( 'focusAddress() emits addressFocused even with no groups loaded at all', async () => {
	const seen = [];
	const provider = await init();

	provider.on( 'addressFocused', ( info ) => seen.push( info ) );

	await provider.focusAddress( [ 1, 2 ], 'Z' );

	expect( seen ).toEqual( [ { latLng: [ 1, 2 ], label: 'Z' } ] );
} );

test( 'resolveAddress() (the real search-pick flow) triggers addressFocused via focusAddress()', async () => {
	const seen = [];
	const provider = await init();

	provider.on( 'addressFocused', ( info ) => seen.push( info ) );
	ymapsStub.geocodeResult = makeGeocodeResult( null, [ 3, 4 ] );

	await provider.resolveAddress( 'Some Address' );

	expect( seen ).toEqual( [ { latLng: [ 3, 4 ], label: 'Some Address' } ] );
} );

test( 'a nearest point comfortably inside the threshold FITS and reports no nothingNearby', async () => {
	const seen = [];
	const provider = await init();
	const anchor = [ 55.75, 37.61 ];
	const groups = [ group( 'near', 55.8 ) ]; // ~5.6 km away — well inside NEARBY_THRESHOLD_M

	provider.on( 'nothingNearby', ( info ) => seen.push( info ) );
	provider.setPoints( groups );

	await provider.focusAddress( anchor, 'X' );

	expect( seen ).toHaveLength( 0 );
	expect( ymapsStub.lastMap.setBoundsCalls.pop().bounds ).toEqual( geo.boundsFor( anchor, groups ) );
} );

test( 'a nearest point beyond the threshold reports nothingNearby with its EXACT distance and '
	+ 'name, and never fits', async () => {
	const seen = [];
	const provider = await init();
	const anchor = [ 55.75, 37.61 ];
	const farGroup = groupWith( { id: 'far', name: 'ПВЗ «Далеко»', lat: 56.6 } ); // ~94.5 km away
	const expectedDistance = geo.distanceMeters( anchor, [ farGroup.lat, farGroup.lng ] );

	provider.on( 'nothingNearby', ( info ) => seen.push( info ) );
	provider.setPoints( [ farGroup ] );

	// setPoints() itself already triggered its own bulk-strategy fit above — checked as a DELTA
	// from here, so it is never mistaken for a fit focusAddress() itself performed.
	const callsBeforeFocus = ymapsStub.lastMap.setBoundsCalls.length;

	await provider.focusAddress( anchor, 'X' );

	expect( expectedDistance ).toBeGreaterThan( 50000 ); // sanity: the fixture IS beyond threshold
	expect( seen ).toHaveLength( 1 );
	expect( seen[ 0 ].distanceMeters ).toBeCloseTo( expectedDistance, 6 );
	expect( seen[ 0 ].name ).toBe( 'ПВЗ «Далеко»' );
	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( callsBeforeFocus ); // never fits this far
} );

test( 'focusAddress() with no groups loaded drops the pin but neither fits nor reports '
	+ 'nothingNearby — there is nothing to fit to or report as nearest', async () => {
	const seen = [];
	const provider = await init();

	// `map.geoObjects.add` was already called once during init() (for the ObjectManager
	// itself, see _buildObjectManager()) — the pin's own call is checked as a DELTA from here,
	// never an absolute count.
	const geoObjectsAddCallsBefore = ymapsStub.lastMap.geoObjects.add.mock.calls.length;

	provider.on( 'nothingNearby', ( info ) => seen.push( info ) );

	await provider.focusAddress( [ 55.75, 37.61 ], 'X' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
	expect( seen ).toHaveLength( 0 );
	// the pin is still dropped
	expect( ymapsStub.lastMap.geoObjects.add ).toHaveBeenCalledTimes( geoObjectsAddCallsBefore + 1 );
} );

// Proves the fit is genuinely AWAITED, not fire-and-forgotten — the same s46 lesson this file's
// docblock already documents for setPoints()'s bulk fit and _resolveInitialViewport(). A version
// of focusAddress() that dropped the `return` in front of `this.map.setBounds(...)` would resolve
// this promise immediately, before the camera move settles, and this test would fail.
test( 'focusAddress(): the returned promise resolves only once the camera fit settles', async () => {
	const provider = await init( {}, { deferSetBounds: true } );

	provider.setPoints( [ group( 'a', 55.751 ), group( 'b', 55.752 ) ] );

	// setPoints() itself queued its OWN deferred bulk-strategy fit — settle THAT one first so
	// resolveNextSetBounds() below (FIFO) cannot be mistaken for focusAddress()'s own fit.
	ymapsStub.lastMap.resolveNextSetBounds();
	await flushPromises();

	let resolved = false;
	const focusPromise = provider.focusAddress( [ 55.75, 37.61 ], 'X' ).then( () => {
		resolved = true;
	} );

	await flushPromises();
	expect( resolved ).toBe( false );

	ymapsStub.lastMap.resolveNextSetBounds();
	await focusPromise;

	expect( resolved ).toBe( true );
} );

test( 'the "your address" pin is never added to the ObjectManager — it cannot leak into the '
	+ 'list, the type filter, or the nearest-N pool', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.751 ) ] );

	const addedBefore = ymapsStub.lastObjectManager.added.length;
	// See the previous test's comment: init() itself already added the ObjectManager once.
	const geoObjectsAddCallsBefore = ymapsStub.lastMap.geoObjects.add.mock.calls.length;

	await provider.focusAddress( [ 55.75, 37.61 ], 'X' );

	expect( ymapsStub.lastObjectManager.added ).toHaveLength( addedBefore );
	expect( ymapsStub.lastMap.geoObjects.add ).toHaveBeenCalledTimes( geoObjectsAddCallsBefore + 1 );
	expect( ymapsStub.lastMap.geoObjects.add ).not.toHaveBeenLastCalledWith( ymapsStub.lastObjectManager );
} );

test( 'a second focusAddress() replaces the previous pin rather than accumulating pins', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.751 ) ] );
	await provider.focusAddress( [ 55.75, 37.61 ], 'First' );

	const addCallsAfterFirst = ymapsStub.lastMap.geoObjects.add.mock.calls.slice();
	const firstPin = addCallsAfterFirst[ addCallsAfterFirst.length - 1 ][ 0 ];

	await provider.focusAddress( [ 55.8, 37.65 ], 'Second' );

	expect( ymapsStub.lastMap.geoObjects.remove ).toHaveBeenCalledWith( firstPin );
	expect( ymapsStub.lastMap.geoObjects.add ).toHaveBeenCalledTimes( addCallsAfterFirst.length + 1 );
} );

test( 'clearAddress() removes the pin and is idempotent', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 55.751 ) ] );
	await provider.focusAddress( [ 55.75, 37.61 ], 'X' );

	const addCalls = ymapsStub.lastMap.geoObjects.add.mock.calls;
	const pin = addCalls[ addCalls.length - 1 ][ 0 ];

	provider.clearAddress();

	expect( ymapsStub.lastMap.geoObjects.remove ).toHaveBeenCalledWith( pin );

	ymapsStub.lastMap.geoObjects.remove.mockClear();
	provider.clearAddress(); // second call: no pin left to remove — a true no-op

	expect( ymapsStub.lastMap.geoObjects.remove ).not.toHaveBeenCalled();
} );

test( 'setPoints() never re-applies focus to a group that no longer exists in the new set', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ), group( 'b', 2, 2 ) ] );
	ymapsStub.lastObjectManager.state = { isClustered: false, cluster: null };
	await provider.focusGroup( 'a' );

	ymapsStub.lastObjectManager.objects.setObjectProperties.mockClear();

	provider.setPoints( [ group( 'b', 2, 2 ) ] ); // 'a' is gone

	expect( provider.getFocusedKey() ).toBeNull();
	expect( ymapsStub.lastObjectManager.objects.setObjectProperties ).not.toHaveBeenCalledWith(
		'a',
		expect.anything()
	);
} );
