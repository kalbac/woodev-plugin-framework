/**
 * @jest-environment jsdom
 *
 * Tests for map-provider-yandex.js (SP-5 Task 17 — the ObjectManager/groups rewrite).
 *
 * The real Yandex Maps JS API is not loadable in jest, so this file hand-rolls a minimal
 * `window[ns]` stub covering exactly the surface the provider uses at this stage: `Map`,
 * `ObjectManager`, `control.ZoomControl`, `Layer`, `projection`, `templateLayoutFactory.createClass`.
 * Setting `window[ns]` to a working stub BEFORE calling `init()` makes {@see loadYmapsScript}'s
 * "already loaded" branch fire immediately.
 *
 * Camera control (initial viewport, focusing/un-clustering a group, the bbox cap) is Task 18 —
 * this file grows a `geocode`/`setBounds`/`setCenter`-aware stub and the matching tests in that
 * follow-up commit.
 *
 * Every balloon/drawer/type-filter-CONTROL/cluster-balloon test that used to live here is GONE:
 * that presentation moved to `pickup-panels.js` (the list panel, the point card, the tab bar,
 * the search view, the type filter MENU) and is covered by `pickup-panels.test.js`. This file
 * only tests what the provider itself still owns: the map canvas, one ObjectManager feature per
 * group, and the click event out.
 *
 * @see woodev/shipping-method/assets/js/frontend/map-provider-yandex.js
 */

'use strict';

const WoodevYandexMapProvider = require( '../../woodev/shipping-method/assets/js/frontend/map-provider-yandex' );

let nsCounter = 0;
let ymapsStub;

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
 * Builds a hand-rolled `window[ns]` ymaps stub. See the file docblock for what it covers.
 */
function createYmapsStub() {
	const stub = {};

	function Map( container, state, mapOptions ) {
		this.container = container;
		this.state = state;
		this.options = mapOptions;
		this.layers = [];
		// `add` is defined non-enumerable so `toEqual( [ '© Test' ] )` against a plain array
		// literal still passes — an ENUMERABLE own `add` property would make the received
		// array structurally unequal to a plain array even with identical indexed contents.
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

		stub.lastMap = this;
	}

	function ObjectManager( omOptions ) {
		this.options = omOptions;
		this.added = [];
		this.removeAllCalls = 0;
		this.filter = null;

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

	function Layer( url, layerOptions ) {
		this.url = url;
		this.options = layerOptions;
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
	stub.control = { ZoomControl: function() {} };
	stub.Layer = Layer;
	stub.projection = { sphericalMercator: 'stub-projection' };
	stub.templateLayoutFactory = { createClass };

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
		},
		overrides
	);
}

/** Builds a fresh provider, `window[ns] = ymapsStub`, and awaits `init()`. */
function init( overrides ) {
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
// Map construction — layers/copyrights, object manager options
// -------------------------------------------------------------------------

test( 'adds the plugin tile layers and copyrights when supplied', async () => {
	await init( {
		layers: [ { url: 'https://tiles.test/%c.png', projection: 'sphericalMercator' } ],
		copyrights: [ '© Test' ],
	} );

	expect( ymapsStub.lastMap.layers ).toHaveLength( 1 );
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

test( 'adds every group to the object manager as one feature each', async () => {
	const provider = await init();

	provider.setPoints( [
		{ key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [ { id: '1' } ] },
		{ key: 'b', lat: 55.76, lng: 37.62, typeCode: 'pvz', size: 2, points: [ { id: '2' }, { id: '3' } ] },
	] );

	expect( ymapsStub.lastObjectManager.added ).toHaveLength( 2 );
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

test( 'every feature is registered with the marker icon hit-box dimensions', async () => {
	const provider = await init();

	provider.setPoints( [ group( 'a', 1, 1 ) ] );

	const feature = ymapsStub.lastObjectManager.added[ 0 ];

	expect( feature.options.iconImageSize ).toEqual( [ 45, 45 ] );
	expect( feature.options.iconImageOffset ).toEqual( [ -22, -23 ] );
} );

test( 'emits pointClick with the group key', async () => {
	const seen = [];
	const provider = await init();

	provider.on( 'pointClick', ( key ) => seen.push( key ) );
	provider.setPoints( [ { key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [] } ] );
	ymapsStub.lastObjectManager.fireObjectClick( 'a' );

	expect( seen ).toEqual( [ 'a' ] );
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

test( 'the stored filter function matches only the requested codes, and matches everything once cleared', async () => {
	const provider = await init();

	provider.setTypeFilter( [ 'pvz', 'postamat' ] );

	const filterFn = ymapsStub.lastObjectManager.filter;

	expect( filterFn( 'a', { properties: { typeCode: 'pvz' } } ) ).toBe( true );
	expect( filterFn( 'b', { properties: { typeCode: 'other' } } ) ).toBe( false );

	provider.setTypeFilter( null );

	const clearedFilter = ymapsStub.lastObjectManager.filter;

	expect( clearedFilter( 'c', { properties: { typeCode: 'anything' } } ) ).toBe( true );
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

test( 'the marker renders no <img> and adds the unknown modifier class when the type has no icon', () => {
	const provider = new WoodevYandexMapProvider();
	const container = document.createElement( 'div' );

	container.innerHTML = '<div class="woodev-pickup-marker"></div>';

	provider._renderMarker( container, { properties: makeProperties( { groupSize: 1, iconHref: '' } ) } );

	expect( container.querySelector( 'img' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-marker--unknown' ) ).not.toBeNull();
	expect( container.querySelector( '.woodev-pickup-marker__badge' ) ).toBeNull();
} );
