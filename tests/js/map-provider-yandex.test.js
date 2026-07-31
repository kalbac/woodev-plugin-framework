/**
 * @jest-environment jsdom
 *
 * Tests for map-provider-yandex.js (SP-5 Task 13).
 *
 * The real Yandex Maps JS API is not loadable in jest, so this file hand-rolls a minimal
 * `window[ns]` stub covering exactly the surface the provider uses: `Map`, `Clusterer`,
 * `Placemark`, `control.ZoomControl`/`SearchControl`, `templateLayoutFactory.createClass`,
 * `util.defineClass`, `collection.Item`, `geoQuery`, `geocode`. Setting `window[ns]` to a
 * working stub BEFORE calling `init()` makes {@see loadYmapsScript}'s "already loaded"
 * branch fire immediately — the provider never actually injects/awaits a real `<script>`
 * tag, which jsdom would never resolve anyway (it does not fetch external script `src`s by
 * default). Each test uses its OWN `ns` so the module-level script-load cache never leaks
 * state between tests.
 *
 * Two testing strategies are used depending on what is under test:
 *  - the full `init()` flow (script load → map/clusterer/controls → strategy-driven initial
 *    fetch → `boundschange` re-fetch) is exercised end-to-end through the stub, because the
 *    THING being tested is the orchestration itself (which strategy calls what, when);
 *  - the balloon (`_renderBalloon`) is tested by calling it DIRECTLY against a plain
 *    `document.createElement( 'div' )` container, bypassing ymaps' own balloon-open
 *    machinery entirely — see the production file's own docblock for why `_renderBalloon`
 *    is deliberately independent of that machinery. This is real coverage, not a shortcut:
 *    `_renderBalloon` IS what ymaps' balloon layout `build()` hook delegates to.
 *
 * @see woodev/shipping-method/assets/js/frontend/map-provider-yandex.js
 */

'use strict';

const WoodevYandexMapProvider = require( '../../woodev/shipping-method/assets/js/frontend/map-provider-yandex' );

let nsCounter = 0;

/**
 * Waits one macrotask — enough for any chain of already-settled promises this file's
 * production code builds (script load → map build → initial fetch → listener registration,
 * or a `boundschange` re-fetch) to fully flush.
 */
function flushPromises() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * A `properties`-shaped wrapper matching ymaps' real `IDataManager`-ish `get`/`set`/`getAll`
 * surface, backing both `Placemark.properties` and the fake balloon `getData()` result.
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
 * Builds a hand-rolled `window[ns]` ymaps stub. See the file docblock for what it covers
 * and why each piece is shaped the way it is.
 */
function createYmapsStub() {
	function Map( container, state ) {
		this.container = container;
		this.state = state;
		this._bounds = [ [ 10, 20 ], [ 11, 21 ] ];
		this._eventHandlers = {};
		this.destroy = jest.fn();
		this.geoObjects = { add: jest.fn(), remove: jest.fn() };

		const self = this;

		this.controls = {
			add: ( control ) => {
				if ( 'function' === typeof control.onAddToMap ) {
					control._parent = {
						// Deliberately never resolves in this stub — the drawer/filter's own DOM
						// is not part of any REQUIRED test, and letting this hang is harmless (see
						// the "what to build" section this stub does not need full fidelity for).
						getChildElement: () => new Promise( () => {} ),
					};
					control.getParent = () => control._parent;
					control.onAddToMap( self );
				}
			},
		};
		this.margin = { addArea: () => ( { remove: () => {} } ) };
		this.events = {
			add: ( name, cb ) => {
				self._eventHandlers[ name ] = self._eventHandlers[ name ] || [];
				self._eventHandlers[ name ].push( cb );
			},
		};
	}
	Map.prototype.setBounds = function( bounds ) {
		this._bounds = bounds;
	};
	Map.prototype.getBounds = function() {
		return this._bounds;
	};
	Map.prototype.setCenter = function() {};
	Map.prototype.getZoom = function() {
		return 10;
	};
	Map.prototype.fireBoundsChange = function() {
		( this._eventHandlers.boundschange || [] ).forEach( ( cb ) => cb() );
	};

	function Clusterer() {
		this._objects = [];
	}
	Clusterer.prototype.add = function( placemarks ) {
		this._objects = this._objects.concat( placemarks );
	};
	Clusterer.prototype.getGeoObjects = function() {
		return this._objects;
	};
	Clusterer.prototype.getBounds = function() {
		return [ [ 0, 0 ], [ 1, 1 ] ];
	};
	Clusterer.prototype.removeAll = function() {
		this._objects = [];
	};

	function Placemark( coords, properties, options ) {
		this._coords = coords;
		this.properties = makeProperties( properties );
		this._options = options || {};
		this._optionsStore = {};
		this.options = {
			set: ( k, v ) => {
				this._optionsStore[ k ] = v;
			},
			get: ( k ) => this._optionsStore[ k ],
		};
		this.geometry = { getCoordinates: () => coords };

		const self = this;

		this.balloon = {
			open: () => {
				const LayoutCtor = self._options.balloonLayout;

				if ( ! LayoutCtor ) {
					return;
				}

				const instance = new LayoutCtor();

				instance._data = { properties: self.properties };
				instance._element = document.createElement( 'div' );
				instance.build();
				self._layoutInstance = instance;
			},
			isOpen: () => !! self._layoutInstance,
			close: () => {
				self._layoutInstance = null;
			},
		};
	}

	function CollectionItem() {}
	CollectionItem.prototype.onAddToMap = function() {};
	CollectionItem.prototype.onRemoveFromMap = function() {};

	function defineClass( ctor, Base, methods ) {
		ctor.superclass = Object.assign( { constructor: Base }, Base && Base.prototype );
		ctor.prototype = Object.create( Base ? Base.prototype : Object.prototype );
		ctor.prototype.constructor = ctor;
		Object.keys( methods || {} ).forEach( ( key ) => {
			ctor.prototype[ key ] = methods[ key ];
		} );

		return ctor;
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

	return {
		ready: () => Promise.resolve(),
		Map,
		Clusterer,
		Placemark,
		control: {
			ZoomControl: function() {},
			SearchControl: function() {},
		},
		templateLayoutFactory: { createClass },
		util: { defineClass },
		collection: { Item: CollectionItem },
		geoQuery: () => ( { searchInside: () => ( { each: () => {} } ) } ),
		geocode: () => Promise.resolve( { geoObjects: { get: () => null } } ),
	};
}

function makeConfig( overrides ) {
	nsCounter += 1;

	return Object.assign(
		{
			scriptUrl: 'https://example.test/ymaps.js',
			ns: 'WoodevTestYmaps_' + nsCounter,
			hasApiKey: true,
			strategy: 'bulk',
			locality: 'Москва',
			i18n: {
				search: 'Найти адрес или ПВЗ',
				drawerTitle: 'Список пунктов',
				howToGet: 'Как добраться',
				paymentMethods: 'Методы оплаты',
				workTime: 'Время работы',
				phone: 'Телефон',
				maxWeight: 'Ограничение веса',
				allTypes: 'Все типы',
				detailsError: 'Не удалось загрузить детали пункта',
				select: 'Выбрать этот пункт',
				blocked: 'Этот пункт выдачи недоступен для вашего заказа.',
			},
		},
		overrides
	);
}

function fakeDataSource( impl ) {
	return Object.assign(
		{
			fetchPoints: () => Promise.resolve( [] ),
			fetchDetails: () => Promise.resolve( {} ),
		},
		impl
	);
}

function point( overrides ) {
	return Object.assign(
		{
			id: 'PVZ-1',
			name: 'Точка',
			lat: 55.75,
			lng: 37.61,
			address: 'ул. Ленина, 1',
			type: { code: 'PVZ', label: 'ПВЗ' },
			short_address: '',
			locality: 'Москва',
			postal_code: '101000',
			phone: '',
			instruction: '',
			work_time: '',
			payment_methods: [],
			photos: [],
			accepts_cod: null,
			max_weight: null,
			selectable: { allowed: true, reason: null },
		},
		overrides
	);
}

// -------------------------------------------------------------------------
// Strategy-driven initial load + boundschange re-fetch
// -------------------------------------------------------------------------

test( 'bulk strategy fetches points exactly once, by locality, and fits the map to the result', async () => {
	const config = makeConfig( { strategy: 'bulk', locality: 'Казань' } );

	window[ config.ns ] = createYmapsStub();

	const calls = [];
	const ds = fakeDataSource( {
		fetchPoints: ( query ) => {
			calls.push( query );

			return Promise.resolve( [ point() ] );
		},
	} );
	const provider = new WoodevYandexMapProvider();

	await provider.init( document.createElement( 'div' ), config, ds );

	expect( calls ).toEqual( [ { locality: 'Казань' } ] );
	expect( provider.clusterer.getGeoObjects().length ).toBe( 1 );
	// A non-empty result fits the map to the clusterer's own bounds.
	expect( provider.map.getBounds() ).toEqual( [ [ 0, 0 ], [ 1, 1 ] ] );
} );

test( 'viewport strategy fetches by the current bounds, then re-fetches on boundschange', async () => {
	const config = makeConfig( { strategy: 'viewport', locality: '' } );

	window[ config.ns ] = createYmapsStub();

	const calls = [];
	const ds = fakeDataSource( {
		fetchPoints: ( query ) => {
			calls.push( query );

			return Promise.resolve( [] );
		},
	} );
	const provider = new WoodevYandexMapProvider();

	await provider.init( document.createElement( 'div' ), config, ds );

	expect( calls.length ).toBe( 1 );
	expect( Array.isArray( calls[ 0 ].bounds ) ).toBe( true );
	expect( calls[ 0 ].bounds.length ).toBe( 4 );
	expect( calls[ 0 ].locality ).toBeUndefined(); // bulk-only key must never leak into a viewport call

	provider.map.fireBoundsChange();
	await flushPromises();

	expect( calls.length ).toBe( 2 );
} );

test( 'viewport de-duplication: panning back to an already-seen point never adds a duplicate placemark', async () => {
	const config = makeConfig( { strategy: 'viewport', locality: '' } );

	window[ config.ns ] = createYmapsStub();

	const samePoint = point( { id: 'DUP-1' } );
	const ds = fakeDataSource( { fetchPoints: () => Promise.resolve( [ samePoint ] ) } );
	const provider = new WoodevYandexMapProvider();

	await provider.init( document.createElement( 'div' ), config, ds );
	expect( provider.clusterer.getGeoObjects().length ).toBe( 1 );

	provider.map.fireBoundsChange();
	await flushPromises();

	expect( provider.clusterer.getGeoObjects().length ).toBe( 1 );
} );

test( 'a fetchPoints rejection emits error with the reason passed through UNCHANGED', async () => {
	const config = makeConfig( { strategy: 'bulk' } );

	window[ config.ns ] = createYmapsStub();

	const reason = { status: 502, code: 'woodev_pickup_upstream_error', message: 'boom' };
	const ds = fakeDataSource( { fetchPoints: () => Promise.reject( reason ) } );
	const provider = new WoodevYandexMapProvider();
	const errorSpy = jest.fn();

	provider.on( 'error', errorSpy );

	await provider.init( document.createElement( 'div' ), config, ds );

	expect( errorSpy ).toHaveBeenCalledTimes( 1 );
	// Referential equality — not just deep equality — proves the object is never rewrapped.
	expect( errorSpy.mock.calls[ 0 ][ 0 ] ).toBe( reason );
} );

test( 'hasApiKey: false emits a map_script error and never constructs a map', async () => {
	const config = makeConfig( { hasApiKey: false } );

	window[ config.ns ] = createYmapsStub();

	const provider = new WoodevYandexMapProvider();
	const errorSpy = jest.fn();

	provider.on( 'error', errorSpy );

	await provider.init( document.createElement( 'div' ), config, fakeDataSource( {} ) );

	expect( errorSpy ).toHaveBeenCalledWith( { code: 'map_script', message: '' } );
	expect( provider.map ).toBeNull();
} );

// -------------------------------------------------------------------------
// destroy()
// -------------------------------------------------------------------------

test( 'destroy() is idempotent and detaches the window resize listener it added', async () => {
	const config = makeConfig( { strategy: 'bulk' } );

	window[ config.ns ] = createYmapsStub();

	const provider = new WoodevYandexMapProvider();

	await provider.init( document.createElement( 'div' ), config, fakeDataSource( {} ) );

	const removeSpy = jest.spyOn( window, 'removeEventListener' );

	provider.destroy();

	expect( removeSpy ).toHaveBeenCalledWith( 'resize', expect.any( Function ) );
	expect( provider.map ).toBeNull();

	removeSpy.mockClear();
	expect( () => provider.destroy() ).not.toThrow();
	expect( removeSpy ).not.toHaveBeenCalled(); // second call is a true no-op, nothing left to detach

	removeSpy.mockRestore();
} );

test( 'destroy() called before init() settles never builds a map or throws', async () => {
	const config = makeConfig( { strategy: 'bulk' } );

	window[ config.ns ] = createYmapsStub();

	const provider = new WoodevYandexMapProvider();
	const ds = fakeDataSource( { fetchPoints: () => Promise.resolve( [ point() ] ) } );

	// The script-load "already loaded" branch still resolves via a real .then() microtask,
	// so calling destroy() synchronously right after init() races every later continuation
	// (_buildMap(), the initial fetch) against the `_destroyed` guard each one checks.
	const initPromise = provider.init( document.createElement( 'div' ), config, ds );

	expect( () => provider.destroy() ).not.toThrow();

	await initPromise;

	expect( provider.map ).toBeNull();
} );

// -------------------------------------------------------------------------
// Balloon — selectable-driven CTA, click emits select, i18n, escaping
// -------------------------------------------------------------------------

test( 'a blocked point renders a disabled CTA with the reason; clicking it emits nothing', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig();
	provider.dataSource = fakeDataSource( {} );

	const selectSpy = jest.fn();

	provider.on( 'select', selectSpy );

	const container = document.createElement( 'div' );
	const p = point( { selectable: { allowed: false, reason: 'Нельзя выбрать этот пункт' } } );

	provider._renderBalloon( container, p );

	const warning = container.querySelector( '.woodev-pickup-balloon__warning' );
	const button = container.querySelector( '.woodev-pickup-balloon__select' );

	expect( warning.textContent ).toBe( 'Нельзя выбрать этот пункт' );
	expect( button.disabled ).toBe( true );

	button.click();

	expect( selectSpy ).not.toHaveBeenCalled();
} );

test( 'an allowed point renders no warning; clicking the CTA emits select with the point', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig();
	provider.dataSource = fakeDataSource( {} );

	const selectSpy = jest.fn();

	provider.on( 'select', selectSpy );

	const container = document.createElement( 'div' );
	const p = point( { selectable: { allowed: true, reason: null } } );

	provider._renderBalloon( container, p );

	expect( container.querySelector( '.woodev-pickup-balloon__warning' ) ).toBeNull();

	const button = container.querySelector( '.woodev-pickup-balloon__select' );

	expect( button.disabled ).toBe( false );

	button.click();

	expect( selectSpy ).toHaveBeenCalledTimes( 1 );
	expect( selectSpy.mock.calls[ 0 ][ 0 ] ).toBe( p ); // the SAME point object, not a copy
} );

test( 'viewport: opening a balloon calls fetchDetails and re-renders with the returned verdict', async () => {
	const config = makeConfig( { strategy: 'viewport' } );
	const provider = new WoodevYandexMapProvider();

	provider.config = config;

	const listPoint = point( { id: 'LZY-1', selectable: { allowed: true, reason: null } } );
	const detailPoint = point( {
		id: 'LZY-1',
		max_weight: 5000,
		selectable: { allowed: false, reason: 'Превышен вес' },
	} );

	provider.dataSource = fakeDataSource( { fetchDetails: () => Promise.resolve( detailPoint ) } );

	const container = document.createElement( 'div' );

	provider._renderBalloon( container, listPoint );

	// Immediately after the synchronous render: still reflects the LIST point (allowed).
	expect( container.querySelector( '.woodev-pickup-balloon__select' ).disabled ).toBe( false );

	await flushPromises();

	const button = container.querySelector( '.woodev-pickup-balloon__select' );

	expect( button.disabled ).toBe( true ); // upgraded to the DETAIL point's verdict
	expect( container.querySelector( '.woodev-pickup-balloon__warning' ).textContent ).toBe( 'Превышен вес' );
} );

test( 'a fetchDetails rejection shows detailsError, emits no error, keeps the LIST point selectable', async () => {
	const config = makeConfig( { strategy: 'viewport' } );
	const provider = new WoodevYandexMapProvider();

	provider.config = config;
	provider.dataSource = fakeDataSource( {
		fetchDetails: () => Promise.reject( { status: 502, code: 'x', message: 'y' } ),
	} );

	const errorSpy = jest.fn();
	const selectSpy = jest.fn();

	provider.on( 'error', errorSpy );
	provider.on( 'select', selectSpy );

	const container = document.createElement( 'div' );
	const listPoint = point( { selectable: { allowed: true, reason: null } } );

	provider._renderBalloon( container, listPoint );

	await flushPromises();

	expect( container.querySelector( '.woodev-pickup-balloon__error' ).textContent ).toBe(
		'Не удалось загрузить детали пункта'
	);
	expect( errorSpy ).not.toHaveBeenCalled();

	const button = container.querySelector( '.woodev-pickup-balloon__select' );

	expect( button.disabled ).toBe( false ); // still the list point's own (allowed) verdict

	button.click();

	expect( selectSpy ).toHaveBeenCalledWith( listPoint );
} );

test( 'under bulk strategy, opening a balloon never calls fetchDetails', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig( { strategy: 'bulk' } );

	const fetchDetailsSpy = jest.fn( () => Promise.resolve( {} ) );

	provider.dataSource = fakeDataSource( { fetchDetails: fetchDetailsSpy } );

	provider._renderBalloon( document.createElement( 'div' ), point() );

	expect( fetchDetailsSpy ).not.toHaveBeenCalled();
} );

test( 'max_weight is converted from GRAMS to kg with two decimals (unit/divisor mutation guard)', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig();
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );

	provider._renderBalloon( container, point( { max_weight: 15500 } ) );

	const value = container.querySelector( '.woodev-pickup-balloon__weight .woodev-pickup-balloon__value' );

	expect( value.textContent ).toBe( '15.50' );
} );

test( 'each label/value line renders the i18n LABEL before the VALUE, not swapped', () => {
	const config = makeConfig();
	const provider = new WoodevYandexMapProvider();

	provider.config = config;
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );

	provider._renderBalloon( container, point( { phone: '+7 000 000-00-00' } ) );

	const line = container.querySelector( '.woodev-pickup-balloon__phone' ).textContent;

	expect( line.indexOf( config.i18n.phone ) ).toBeLessThan( line.indexOf( '+7 000 000-00-00' ) );
} );

test( 'a missing i18n key renders BLANK, never a JS-side Russian default', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig( { i18n: {} } ); // every key absent
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );
	const p = point( { instruction: 'Инструкция', selectable: { allowed: false, reason: null } } );

	provider._renderBalloon( container, p );

	expect( container.querySelector( '.woodev-pickup-balloon__howto-summary' ).textContent ).toBe( '' );
	expect( container.querySelector( '.woodev-pickup-balloon__select' ).textContent ).toBe( '' );
	// selectable.reason is null here too, so the fallback to i18n.blocked must ALSO render
	// blank rather than the framework's own hardcoded Russian "недоступен" default.
	expect( container.querySelector( '.woodev-pickup-balloon__warning' ).textContent ).toBe( '' );
} );

test( 'pre-escaped point fields are embedded as-is (decoded by the browser), never double-escaped', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig();
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );
	// Exactly the shape Pickup_Point::to_browser_array()'s esc_html() would produce.
	const p = point( { name: 'ООО &quot;Ромашка&quot;' } );

	provider._renderBalloon( container, p );

	expect( container.querySelector( '.woodev-pickup-balloon__title' ).textContent ).toBe( 'ООО "Ромашка"' );
} );

test( 'i18n label strings are escaped before being woven into the balloon markup', () => {
	const config = makeConfig();

	config.i18n = Object.assign( {}, config.i18n, { phone: 'Tel <b>bold</b>' } );

	const provider = new WoodevYandexMapProvider();

	provider.config = config;
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );

	provider._renderBalloon( container, point( { phone: '123' } ) );

	// The <b> must NOT have been parsed as a real element — it must render as literal text.
	expect( container.querySelector( '.woodev-pickup-balloon__phone b' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__phone' ).textContent ).toContain( 'Tel <b>bold</b>' );
} );

test( 'optional fields absent from the point render no line at all', () => {
	const provider = new WoodevYandexMapProvider();

	provider.config = makeConfig();
	provider.dataSource = fakeDataSource( {} );

	const container = document.createElement( 'div' );

	// Override the fixture's default non-empty postal_code — every OTHER optional field
	// is already '' / null / [] in the base fixture.
	provider._renderBalloon( container, point( { postal_code: '' } ) );

	expect( container.querySelector( '.woodev-pickup-balloon__postal' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__howto' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__payments' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__phone' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__worktime' ) ).toBeNull();
	expect( container.querySelector( '.woodev-pickup-balloon__weight' ) ).toBeNull();
} );
