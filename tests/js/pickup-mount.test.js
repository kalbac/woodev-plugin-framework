/**
 * Tests for pickup-mount.js
 *
 * Covers SP-5 Task 12's original wiring (idempotent trigger placement, the click → modal →
 * provider → dataSource plumbing, writing a selection THROUGH the field's OWN owning store,
 * firing `change`/`change.select2` after every write, address replacement target resolution,
 * missing-option handling for the city select, the non-destructive degrade-to-notice once a
 * point set is drawn, retry always rebuilding the provider from scratch, i18n keys read from
 * the SHAPE the PHP side actually emits, and the no-duplicate-session guarantee) PLUS Task 20's
 * own wiring: this file, not the provider, now owns fetching (bulk fetches once on `init()`
 * resolve, viewport fetches per `boundsChange`); the `ownsChrome` branch (no panels at all for
 * an embedded-style provider); provider↔panels event bridging both ways; the four
 * `woodev_pickup_*` `document.body` events; `refresh()`; the trigger's `i18n.trigger`/
 * `i18n.triggerChange` label toggle; and the "your address" pin cannot outlive the search that
 * created it (the panels' own `anchorCleared` event → `provider.clearAddress()`).
 *
 * `jest.useFakeTimers()` is installed BEFORE pickup-mount.js is required, so
 * the module's own top-level `setTimeout()` calls (initial mount +
 * `updated_checkout` defer) are captured under fake-timer control from the
 * very first require — a real timer registered before fake timers are
 * installed would otherwise fire uncontrolled, mid test, with whatever
 * `window` state happened to exist at that moment. Fake timers never affect
 * native Promise microtasks, so `await`-ing a chain of `.then()`s (see
 * {@see flushAsync}) works identically regardless.
 *
 * No real jQuery is loaded in this environment (none is a project
 * dependency), so `window.jQuery` is undefined and pickup-mount.js's
 * `onCheckoutUpdated()` falls back to a plain native `updated_checkout` event
 * on `document.body` — exactly the fallback its own docblock documents. The
 * `change.select2`-firing branch is likewise unreachable without jQuery, so a
 * dedicated tiny jQuery stub is installed for the ONE test that needs it.
 *
 * PANELS ARE A STUB (`StubPanels`, not the real `pickup-panels.js`), installed as
 * `window.WoodevPickupPanels` in `beforeEach` — this file tests the WIRING contract mount.js
 * establishes with whatever panels object it is handed, not the panels' own rendering (that is
 * `pickup-panels.test.js`'s job). `StubPanels.render()` still builds the minimal REAL DOM markup
 * (`.woodev-pickup-list`/`.woodev-pickup-card`) the `ownsChrome` branch tests check for, and
 * exposes `emit()` so a test can drive it exactly like `StubProvider`. One dedicated test near
 * the end uses the REAL `Panels` class instead, to prove `buildPanelsConfig()` actually produces
 * a shape the real class accepts.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-mount.js
 */

'use strict';

jest.useFakeTimers();

/**
 * Records `focusGroup()`/`openCard()` calls in the order the STUBS actually saw them — the only
 * way to prove `pickup-mount.js` calls them in the sequence spec V-10 requires (focus, THEN open)
 * rather than merely calling both somewhere. Reset in `beforeEach()`.
 *
 * @type {Array<string>}
 */
let callOrder = [];

const { createStore } = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );
require( '../../woodev/assets/js/frontend/woodev-modal' ); // side effect: window.WoodevModal
const { mountAll, getSession } = require( '../../woodev/shipping-method/assets/js/frontend/pickup-mount' );
const RealPanels = require( '../../woodev/shipping-method/assets/js/frontend/pickup-panels' );

const FIELD_ID = 'carrier_pickup_point';

/**
 * A test-controlled `Map_Provider` double covering the FULL Task 20 contract (`init/on/destroy`
 * plus `setPoints`/`setTypeFilter`/`focusGroup`/`getFocusedKey`/`resolveAddress`/`focusAddress`/
 * `clearAddress`/`setMargin`) — every call is recorded so a test can assert exactly what mount.js
 * sent it. `on()` accepts ANY event name (not a fixed list) so this one double serves every
 * provider event this file wires. Every constructed instance is pushed onto
 * `StubProvider.instances` so a test can assert how many concurrently-live providers ever
 * existed and which were destroyed.
 */
function StubProvider() {
	this.handlers = {};
	this.destroyed = false;
	this.initCalls = [];
	this.setPointsCalls = [];
	this.setTypeFilterCalls = [];
	this.focusGroupCalls = [];
	this.resolveAddressCalls = [];
	this.clearAddressCalls = 0;
	this.setMarginCalls = [];
	StubProvider.instances.push( this );
}

StubProvider.instances = [];

StubProvider.prototype.init = function( container, config, dataSource ) {
	this.initCalls.push( { container: container, config: config, dataSource: dataSource } );
};

StubProvider.prototype.on = function( event, cb ) {
	if ( ! this.handlers[ event ] ) {
		this.handlers[ event ] = [];
	}

	this.handlers[ event ].push( cb );
};

StubProvider.prototype.emit = function( event, payload ) {
	( this.handlers[ event ] || [] ).forEach( function( cb ) {
		cb( payload );
	} );
};

StubProvider.prototype.destroy = function() {
	this.destroyed = true;
};

StubProvider.prototype.setPoints = function( groups ) {
	this.setPointsCalls.push( groups );
};

StubProvider.prototype.setTypeFilter = function( codes ) {
	this.setTypeFilterCalls.push( codes );
};

/**
 * Returns a promise that NEVER resolves — deliberately. Spec V-10 requires the camera move NOT
 * be awaited before the card opens (the card is our own DOM, unrelated to the viewport); a
 * never-resolving stub is what makes a test that awaits nothing after `emit()` prove that, since
 * an (incorrect) `.then( openCard )` chain here would leave `openCard()` forever uncalled.
 */
StubProvider.prototype.focusGroup = function( key ) {
	this.focusGroupCalls.push( key );
	callOrder.push( 'focusGroup:' + key );

	return new Promise( function() {} );
};

StubProvider.prototype.getFocusedKey = function() {
	return null;
};

StubProvider.prototype.resolveAddress = function( displayName ) {
	this.resolveAddressCalls.push( displayName );

	return Promise.resolve();
};

StubProvider.prototype.focusAddress = function() {
	return Promise.resolve();
};

StubProvider.prototype.clearAddress = function() {
	this.clearAddressCalls += 1;
};

StubProvider.prototype.setMargin = function( open, width ) {
	this.setMarginCalls.push( { open: open, width: width } );
};

/**
 * A minimal `window.WoodevPickupPanels` double — see the file docblock's "PANELS ARE A STUB"
 * note. `render()` builds just enough REAL DOM (`.woodev-pickup-list`/`.woodev-pickup-card`) for
 * the `ownsChrome` branch tests to query for; every other method just records its last call.
 */
function StubPanels( container, config ) {
	this.container = container;
	this.config = config;
	this._listeners = {};
	this.root = null;
	StubPanels.instances.push( this );
}

StubPanels.instances = [];

StubPanels.prototype.render = function() {
	this.root = document.createElement( 'div' );
	this.root.className = 'woodev-pickup-panels';

	const list = document.createElement( 'div' );
	list.className = 'woodev-pickup-list';
	const card = document.createElement( 'div' );
	card.className = 'woodev-pickup-card';

	// The panels own the map element now (spec V-3) — the provider mounts INTO it instead of
	// creating a sibling canvas in the dialog body. The stub has to carry it too, or the mount
	// hands `provider.init()` an undefined host.
	this.mapEl = document.createElement( 'div' );
	this.mapEl.className = 'woodev-pickup-map';

	this.root.appendChild( this.mapEl );
	this.root.appendChild( list );
	this.root.appendChild( card );
	this.container.appendChild( this.root );
};

StubPanels.prototype.getMapElement = function() {
	return this.mapEl;
};

StubPanels.prototype.on = function( event, cb ) {
	( this._listeners[ event ] = this._listeners[ event ] || [] ).push( cb );
};

StubPanels.prototype.emit = function( event, payload ) {
	( this._listeners[ event ] || [] ).forEach( function( cb ) {
		cb( payload );
	} );
};

StubPanels.prototype.setVisible = function( groups ) {
	this.lastVisible = groups;
};

StubPanels.prototype.setTypes = function( types ) {
	this.lastTypes = types;
};

StubPanels.prototype.showNothingNearby = function( info ) {
	this.lastNothingNearby = info;
};

StubPanels.prototype.renderSearchResults = function( results ) {
	this.lastSearchResults = results;
};

StubPanels.prototype.openCard = function( group, pointId ) {
	this.lastOpenCard = { group: group, pointId: pointId };

	// The real class emits this from `openCard()`, BEFORE it renders — the single funnel every
	// route to a card passes through, and what the mount listens to in order to move the camera
	// (spec V-10). The stub has to model both the event and its position, or every camera
	// assertion here silently tests nothing and the documented focus-then-card order goes unchecked.
	this.emit( 'cardOpened', {
		group: group,
		pointId: undefined === pointId ? group.points[ 0 ].id : pointId,
	} );

	callOrder.push( 'openCard:' + ( group && group.key ) );
};

StubPanels.prototype.closeCard = function() {
	this.closeCardCalls = ( this.closeCardCalls || 0 ) + 1;
};

StubPanels.prototype.setSelectedId = function( id ) {
	this.lastSelectedId = id;
};

StubPanels.prototype.setAnchor = function( latLng, label ) {
	this.setAnchorCalls = this.setAnchorCalls || [];
	this.setAnchorCalls.push( { latLng: latLng, label: label } );
};

StubPanels.prototype.toggleList = function() {};

/**
 * Builds a fake `WoodevPickupDataSource` factory whose `fetchPoints()`
 * resolves/rejects with whatever `impl` returns — no real `fetch`, no
 * debounce, fully synchronous-microtask-controlled so tests stay fast and
 * deterministic. `fetchDetails()` is unused by this file and stubbed trivially.
 *
 * @param {Function} impl `function( query ) { return Promise }`
 */
function fakeDataSourceFactory( impl ) {
	return function() {
		return {
			fetchPoints: impl,
			fetchDetails: function() {
				return Promise.resolve( {} );
			},
		};
	};
}

/**
 * Awaits several microtask hops — enough for pickup-mount.js's own
 * `provider.init().then()` → `fetchAndSetPoints()`'s `dataSource.fetchPoints().then()` chain
 * (and anything a test drives on top of it) to fully settle. Native Promise microtasks are
 * NEVER affected by `jest.useFakeTimers()` — only macrotask APIs (`setTimeout`, …) are faked.
 */
async function flushAsync() {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
	}
}

/**
 * The i18n shape `Pickup_Handler::get_js_config()` ACTUALLY emits (see
 * class-pickup-handler.php) — used as the default so a test proves the mount
 * reads the real key names, not a hypothetical/convenient one.
 */
function phpI18n( overrides ) {
	return Object.assign(
		{
			modalTitle: 'Выберите пункт выдачи',
			close: 'Закрыть',
			select: 'Выбрать этот пункт',
			loading: 'Загрузка пунктов выдачи…',
			error: 'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
			noResults: 'Пункты выдачи не найдены.',
			blocked: 'Этот пункт выдачи недоступен для вашего заказа.',
			trigger: 'Выбрать пункт выдачи',
			triggerChange: 'Выбрать другой пункт выдачи',
			retry: 'Повторить',
			upstreamError: 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
			rateLimited: 'Слишком много запросов. Подождите немного и попробуйте снова.',
			notFound: 'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
			zoomIn: 'Приблизьте карту, чтобы увидеть пункты выдачи',
		},
		overrides
	);
}

function makeConfig( overrides ) {
	const base = {
		fieldId: FIELD_ID,
		provider: 'testProvider',
		strategy: 'bulk',
		restRoot: 'https://example.test/wp-json/woodev/v1/shipping/pickup/p/points',
		nonce: 'nonce-1',
		i18n: phpI18n(),
		mapConfig: { center: [ 55.75, 37.61 ] },
		replaceAddress: { enabled: true, billingOnly: false },
		// TOP-LEVEL keys `Pickup_Handler::get_js_config()` really emits and the map provider
		// really reads. They were missing from this fixture, which is exactly why nothing here
		// noticed that buildProviderConfig() never forwarded them: the map opened at its
		// technical [0,0]/zoom-2 fallback instead of the buyer's city, ObjectManager creates
		// overlays only for VISIBLE objects, so there were no markers and — through the same
		// bounds test — no sidebar entries either. Keep this fixture shaped like the real
		// config; a fixture poorer than production hides production's bugs.
		defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 },
		pointIcons: { PVZ: { default: 'https://example.test/pvz.svg', active: 'https://example.test/pvz-a.svg' } },
		accentColor: '#06aedd',
		searchNearestCount: 3,
	};

	return Object.assign( {}, base, overrides );
}

/**
 * Spec-style config helper (matches the T20 spec's own `configWith( overrides )` calls):
 * `{ ownsChrome }` is a convenience TOP-LEVEL key that maps onto `mapConfig.ownsChrome` — the
 * actual field `pickup-mount.js` reads — never a real top-level config key of its own.
 *
 * @param {Object} [overrides]
 */
function configWith( overrides ) {
	const opts = Object.assign( {}, overrides );
	const mapConfig = Object.assign( { center: [ 55.75, 37.61 ] }, opts.mapConfig );

	if ( undefined !== opts.ownsChrome ) {
		mapConfig.ownsChrome = opts.ownsChrome;
	}

	delete opts.ownsChrome;
	delete opts.mapConfig;

	return makeConfig( Object.assign( { mapConfig: mapConfig }, opts ) );
}

function buildCheckoutDom() {
	document.body.innerHTML =
		'<div data-woodev-pickup-slot="' + FIELD_ID + '" style="display:none;"></div>' +
		'<input id="' + FIELD_ID + '" type="hidden" value="" />' +
		'<input id="billing_address_1" value="" />' +
		'<select id="billing_city"><option value="">--</option></select>' +
		'<input id="billing_postcode" value="" />' +
		'<input id="shipping_address_1" value="" />' +
		'<select id="shipping_city"><option value="">--</option></select>' +
		'<input id="shipping_postcode" value="" />' +
		'<input type="checkbox" name="ship_to_different_address" />';
}

/**
 * Registers a §8 store that manages ONLY the pickup field and `billing_city` —
 * a realistic shape (a plugin's Checkout_Fields commonly declares a takeover
 * target like `billing_city`), deliberately NOT `billing_address_1`/
 * `billing_postcode`/`shipping_*` — those are plain WooCommerce core fields no
 * real §8 config registers, proving C2: the mount must degrade to DOM-only for
 * them rather than silently "succeeding" against a fabricated store no §8
 * consumer would ever read.
 */
function makeStore() {
	return createStore( {
		fields: {
			carrier_pickup_point: { id: FIELD_ID },
			billing_city: { id: 'billing_city' },
		},
	} );
}

function setConfig( config ) {
	window.woodev_pickup_config_p = config;
}

function clickTrigger() {
	const trigger = document.querySelector( '.woodev-pickup-trigger' );
	trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	return trigger;
}

/**
 * Sets a `<select>` city field's value — `billing_city`/`shipping_city` are real
 * `<select>` elements in {@see buildCheckoutDom}, so a bare `.value = x` assignment with
 * no matching `<option>` silently no-ops (jsdom faithfully replicates the real DOM here —
 * the exact bounded-option behaviour `ensureOption()` in pickup-mount.js itself works
 * around). Adds the missing option first, mirroring that production helper.
 */
function setCitySelectValue( fieldId, value ) {
	const select = document.getElementById( fieldId );

	if ( ! Array.prototype.slice.call( select.options ).some( ( o ) => o.value === value ) ) {
		const option = document.createElement( 'option' );

		option.value = value;
		option.text = value;
		select.appendChild( option );
	}

	select.value = value;
}

/**
 * A normalized point, with valid `lat`/`lng`/`type` by default (Task 20's `groupByPosition()`
 * wiring needs a real position; earlier tasks' fixtures never carried one).
 */
function point( overrides ) {
	return Object.assign(
		{
			id: 'PVZ-1',
			name: 'Точка',
			address: 'ул. Ленина, 1',
			short_address: 'Ленина, 1',
			locality: 'Москва',
			postal_code: '101000',
			lat: 55.75,
			lng: 37.61,
			type: { code: 'pvz', label: 'ПВЗ' },
		},
		overrides
	);
}

/**
 * Spec-style session helper: sets the config, mounts, clicks the trigger, and flushes the
 * `init()` → initial-fetch microtask chain — matching the T20 spec's own `openSession( config )`
 * calls. Returns the most recently constructed provider/panels doubles plus the session's own
 * `refresh()`, exactly the shape the spec's literal test bodies use
 * (`session.panels.emit(...)`, `session.provider.emit(...)`, `session.refresh`).
 *
 * @param {Object} config
 */
async function openSession( config ) {
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	const session = getSession( config.fieldId );

	return {
		provider: StubProvider.instances[ StubProvider.instances.length - 1 ],
		panels: StubPanels.instances.length ? StubPanels.instances[ StubPanels.instances.length - 1 ] : null,
		refresh: session ? session.refresh : null,
	};
}

beforeEach( () => {
	StubProvider.instances = [];
	StubPanels.instances = [];
	callOrder = [];
	buildCheckoutDom();
	window.WoodevPickupMapProviders = { testProvider: StubProvider };
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	window.WoodevPickupPanels = StubPanels;
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.woodev_pickup_config_p;
	delete window.WoodevPickupMapProviders;
	delete window.WoodevPickupDataSource;
	delete window.WoodevPickupPanels;
	delete window.jQuery;
} );

// -------------------------------------------------------------------------
// Idempotent mounting
// -------------------------------------------------------------------------

test( 'mounts exactly one trigger into the anchor, labelled from the PHP-emitted i18n.trigger key', () => {
	setConfig( makeConfig() );
	mountAll();

	const slot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	const triggers = slot.querySelectorAll( '.woodev-pickup-trigger' );
	expect( triggers.length ).toBe( 1 );
	expect( triggers[ 0 ].textContent ).toBe( 'Выбрать пункт выдачи' );
} );

test( 'mounting again on the SAME slot never duplicates the trigger', () => {
	setConfig( makeConfig() );
	mountAll();
	mountAll();
	mountAll();

	const slot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	expect( slot.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

test( 'a slot RE-CREATED by §8 (WooCommerce replaced the fragment) gets re-mounted, still only once', () => {
	setConfig( makeConfig() );
	mountAll();

	const oldSlot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	oldSlot.parentNode.removeChild( oldSlot );
	const freshSlot = document.createElement( 'div' );
	freshSlot.setAttribute( 'data-woodev-pickup-slot', FIELD_ID );
	document.body.appendChild( freshSlot );

	mountAll();

	expect( freshSlot.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

test( 'hooks `updated_checkout`, deferred by EXACTLY 60ms, and re-mounts through it', () => {
	setConfig( makeConfig() );

	document.body.dispatchEvent( new Event( 'updated_checkout' ) );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 0 );

	jest.advanceTimersByTime( 59 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 0 );

	jest.advanceTimersByTime( 1 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// Trigger label toggle: i18n.trigger vs i18n.triggerChange
// -------------------------------------------------------------------------

test( 'the trigger reads i18n.trigger when the field has no value yet', () => {
	setConfig( makeConfig() );
	mountAll();

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent ).toBe( 'Выбрать пункт выдачи' );
} );

test( 'a re-mount with an already-selected field value shows i18n.triggerChange immediately', () => {
	document.getElementById( FIELD_ID ).value = 'PVZ-EXISTING';
	setConfig( makeConfig() );
	mountAll();

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent )
		.toBe( 'Выбрать другой пункт выдачи' );
} );

test( 'the trigger switches to i18n.triggerChange right after a NEW selection is applied', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point( { id: 'PVZ-9' } ) );

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent )
		.toBe( 'Выбрать другой пункт выдачи' );
} );

// -------------------------------------------------------------------------
// Click → modal → provider
// -------------------------------------------------------------------------

test( 'clicking the trigger opens the shell and calls provider.init with the container, config, dataSource', () => {
	const config = makeConfig();
	setConfig( config );
	mountAll();

	clickTrigger();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog ).not.toBeNull();

	expect( StubProvider.instances.length ).toBe( 1 );
	const calls = StubProvider.instances[ 0 ].initCalls;
	expect( calls.length ).toBe( 1 );
	expect( calls[ 0 ].container ).toBeInstanceOf( HTMLElement );
	expect( dialog.contains( calls[ 0 ].container ) ).toBe( true );
	// Specifically the PANELS' map element, not the dialog body: a canvas built as a sibling of
	// the stage sits outside the stage's positioning context, and the page then carries two
	// `.woodev-pickup-map` nodes (spec V-3).
	expect( calls[ 0 ].container ).toBe( StubPanels.instances[ 0 ].getMapElement() );
	// The provider config is the MERGE buildProviderConfig() builds — mapConfig's own
	// keys plus strategy/i18n/locality — never config.mapConfig passed through raw.
	expect( calls[ 0 ].config ).toEqual( {
		center: [ 55.75, 37.61 ],
		strategy: 'bulk',
		i18n: config.i18n,
		locality: '',
		defaultLocation: config.defaultLocation,
		pointIcons: config.pointIcons,
		accentColor: config.accentColor,
		searchNearestCount: config.searchNearestCount,
	} );
	// Task 20: the provider contract dropped fetching, but the raw dataSource is still
	// passed as the 3rd arg for a provider that (like Embedded_Map_Provider) still declares
	// it, unused, in its own signature.
	expect( typeof calls[ 0 ].dataSource.fetchPoints ).toBe( 'function' );
	expect( typeof calls[ 0 ].dataSource.fetchDetails ).toBe( 'function' );
} );

test( 'the session tags its modal with the documented pickup modalId on every modal event', () => {
	const opened = [];
	const closed = [];
	document.body.addEventListener( 'woodev_modal_opened', ( e ) => opened.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	// D-14 fixes this literal value: a consumer filters the pickup dialog out of the
	// framework's generic modal stream by it, exactly as the reference integrations filter
	// WooCommerce's backbone modal by its `target`. An empty modalId reaches every listener
	// and matches none of them — green code, dead feature.
	expect( opened ).toHaveLength( 1 );
	expect( opened[ 0 ].modalId ).toBe( 'woodev-pickup-map' );

	document.querySelector( '.woodev-modal__close' ).click();

	expect( closed ).toHaveLength( 1 );
	expect( closed[ 0 ].modalId ).toBe( 'woodev-pickup-map' );
	expect( closed[ 0 ].reason ).toBe( 'button' );
} );

// -------------------------------------------------------------------------
// buildProviderConfig() — the mapConfig/strategy/i18n/locality merge handed
// to the map provider's init(), and locality's LIVE resolution
// -------------------------------------------------------------------------

test( 'the provider config merges mapConfig with strategy, i18n, and the resolved locality', () => {
	const config = makeConfig( {
		strategy: 'viewport',
		mapConfig: { scriptUrl: 'https://example.test/ymaps.js', ns: 'WoodevPickupMap', hasApiKey: true },
	} );
	setConfig( config );
	mountAll();

	setCitySelectValue( 'billing_city', 'Казань' );

	clickTrigger();

	const receivedConfig = StubProvider.instances[ 0 ].initCalls[ 0 ].config;
	expect( receivedConfig ).toEqual( {
		scriptUrl: 'https://example.test/ymaps.js',
		ns: 'WoodevPickupMap',
		hasApiKey: true,
		strategy: 'viewport',
		i18n: config.i18n,
		locality: 'Казань',
		// Everything below is a TOP-LEVEL key of the mount config that the provider reads off
		// the config it is handed. `toEqual` is deliberate: it fails on a MISSING key as loudly
		// as on a wrong one, which a per-key `toMatchObject` would not.
		defaultLocation: config.defaultLocation,
		pointIcons: config.pointIcons,
		accentColor: config.accentColor,
		searchNearestCount: config.searchNearestCount,
	} );
} );

test( 'every top-level key the provider reads survives the provider-config merge', () => {
	// The regression this pins was silent and total: with `defaultLocation` missing the map
	// opened on the Atlantic instead of the buyer's city, and with `pointIcons` missing every
	// marker rendered as an empty box. Neither threw, neither logged.
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const received = StubProvider.instances[ 0 ].initCalls[ 0 ].config;

	[ 'defaultLocation', 'pointIcons', 'accentColor', 'searchNearestCount', 'strategy', 'i18n' ]
		.forEach( ( key ) => {
			expect( received[ key ] ).toBeDefined();
		} );

	expect( received.defaultLocation ).toEqual( { center: [ 55.76, 37.64 ], zoom: 12 } );
	expect( received.pointIcons.PVZ.default ).toBe( 'https://example.test/pvz.svg' );
	expect( received.accentColor ).toBe( '#06aedd' );
} );

test( 'the BULK points query carries the live locality, not just the type filter', async () => {
	// The bug this pins shipped green: the bulk fetch sent only `{ types }`, so the server
	// got a query naming neither a locality nor a bbox, correctly refused it, and the
	// customer saw an empty map in a city full of points — with no error anywhere. Found on
	// the rig, invisible to every test in this file at the time.
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );

		return Promise.resolve( [] );
	} );

	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	setCitySelectValue( 'billing_city', 'Москва' );

	clickTrigger();
	await flushAsync();

	expect( queries ).toHaveLength( 1 );
	expect( queries[ 0 ].locality ).toBe( 'Москва' );
} );

test( 'refresh() re-reads the city, so a locality changed while the map is open is used', async () => {
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );

		return Promise.resolve( [] );
	} );

	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	setCitySelectValue( 'billing_city', 'Москва' );

	clickTrigger();
	await flushAsync();

	setCitySelectValue( 'billing_city', 'Казань' );
	await getSession( FIELD_ID ).refresh();

	expect( queries.map( ( q ) => q.locality ) ).toEqual( [ 'Москва', 'Казань' ] );
} );

test( 'locality is resolved against the LIVE ship-to-different-address target, not billing unconditionally', () => {
	const config = makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } );
	setConfig( config );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true;
	setCitySelectValue( 'shipping_city', 'Новосибирск' );
	setCitySelectValue( 'billing_city', 'Москва' ); // must be ignored — shipping is the live target
	mountAll();

	clickTrigger();

	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( 'Новосибирск' );
} );

test( 'locality is an empty string, never undefined, when the resolved city field is absent or blank', () => {
	setConfig( makeConfig() );
	mountAll();

	clickTrigger();

	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( '' );
} );

test( 'locality is resolved fresh on EACH open, not cached from the first', () => {
	const config = makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } );
	setConfig( config );
	mountAll();

	setCitySelectValue( 'billing_city', 'Первый Город' );
	clickTrigger();
	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( 'Первый Город' );

	// Close the session, change the field, and open a fresh one.
	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	setCitySelectValue( 'billing_city', 'Второй Город' );
	clickTrigger();

	expect( StubProvider.instances[ 1 ].initCalls[ 0 ].config.locality ).toBe( 'Второй Город' );
} );

test( 'the modal title comes from the PHP-emitted i18n.modalTitle key', () => {
	const config = makeConfig( { i18n: phpI18n( { modalTitle: 'Заголовок из PHP' } ) } );
	setConfig( config );
	mountAll();
	clickTrigger();

	const dialog = document.querySelector( '[role="dialog"]' );
	const titleId = dialog.getAttribute( 'aria-labelledby' );
	expect( document.getElementById( titleId ).textContent ).toBe( 'Заголовок из PHP' );
} );

test( 'an unresolvable provider id shows the generic error without throwing', () => {
	setConfig( makeConfig( { provider: 'does_not_exist' } ) );
	mountAll();

	expect( () => clickTrigger() ).not.toThrow();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Не удалось загрузить пункты выдачи' );
	expect( StubProvider.instances.length ).toBe( 0 );
} );

// -------------------------------------------------------------------------
// select → write THROUGH the field's OWN owning store, fire change, close with reason 'select'
// -------------------------------------------------------------------------

test( 'select writes the point id through the store (not the DOM directly) and fires change on the field', () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	const field = document.getElementById( FIELD_ID );
	const changeSpy = jest.fn();
	field.addEventListener( 'change', changeSpy );

	StubProvider.instances[ 0 ].emit( 'select', point( { id: 'PVZ-42' } ) );

	expect( store.getValue( FIELD_ID ) ).toBe( 'PVZ-42' );
	expect( changeSpy ).toHaveBeenCalledTimes( 1 );
	// A real native Event, not a synthetic no-op — checkout-field-classic.js's own gate
	// treats only a truthy `originalEvent` (jQuery's name for this) as meaningful.
	expect( changeSpy.mock.calls[ 0 ][ 0 ].bubbles ).toBe( true );
} );

test( 'select fires woodev_pickup_point_selected and closes the shell with reason "select"', () => {
	makeStore();
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const selected = [];
	const closed = [];
	document.body.addEventListener( 'woodev_pickup_point_selected', ( e ) => selected.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	StubProvider.instances[ 0 ].emit( 'select', point( { id: 'PVZ-1' } ) );

	expect( selected ).toHaveLength( 1 );
	expect( selected[ 0 ].fieldId ).toBe( FIELD_ID );
	expect( selected[ 0 ].point.id ).toBe( 'PVZ-1' );
	expect( closed[ 0 ].reason ).toBe( 'select' );

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	// The default config's address replacement writes billing_address_1/postcode too,
	// which no store in this test manages — an expected, acknowledged warn (see C2).
	expect( console ).toHaveWarned();
} );

// -------------------------------------------------------------------------
// C2 — per-field store resolution: a field with no owning store still gets
// written to the DOM, but NOT through a fabricated store no §8 consumer reads
// -------------------------------------------------------------------------

test( 'a field with no owning §8 store (billing_address_1) is written to the DOM but not through any store', () => {
	const store = makeStore(); // manages carrier_pickup_point + billing_city only
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	// The DOM is authoritative for the unmanaged field...
	expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	// ...but no store anywhere claims to hold it (store.getValue on the ONE store that
	// exists must not have been used as a dumping ground for a field it does not manage).
	expect( store.getValue( 'billing_address_1' ) ).toBeUndefined();
	// The write logs precisely BECAUSE no store owns the field — acknowledge it.
	expect( console ).toHaveWarned();
} );

test( 'a field WITH an owning §8 store (billing_city) is written through that store', () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( store.getValue( 'billing_city' ) ).toBe( 'Москва' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	// address_1/postcode are written too (unmanaged, DOM-only) — acknowledge the warn.
	expect( console ).toHaveWarned();
} );

// -------------------------------------------------------------------------
// Address replacement — target resolution + missing option
// -------------------------------------------------------------------------

test( 'address replacement writes to billing_* when billingOnly is true, regardless of the checkbox', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true; // must be ignored
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );

	expect( document.getElementById( 'shipping_address_1' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'address replacement writes to billing_* when the "ship to a different address" checkbox is UNCHECKED', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = false;
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'shipping_city' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'address replacement writes to shipping_* when the "ship to a different address" checkbox IS checked', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true;
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( document.getElementById( 'shipping_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'shipping_postcode' ).value ).toBe( '101000' );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // shipping_* fields are unmanaged — acknowledge.
} );

test( 'a city with no matching <option> gets one added before the value is set', () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	const citySelect = document.getElementById( 'billing_city' );
	expect( Array.prototype.slice.call( citySelect.options ).some( ( o ) => o.value === 'Казань' ) ).toBe( false );

	StubProvider.instances[ 0 ].emit( 'select', point( { locality: 'Казань' } ) );

	expect( Array.prototype.slice.call( citySelect.options ).some( ( o ) => o.value === 'Казань' ) ).toBe( true );
	expect( citySelect.value ).toBe( 'Казань' );
	expect( store.getValue( 'billing_city' ) ).toBe( 'Казань' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'replaceAddress.enabled: false writes no address field at all', () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
	expect( store.getValue( 'billing_city' ) ).toBeUndefined();
	// The pickup field id itself is unaffected by the enabled flag — only address replacement is gated.
	expect( store.getValue( FIELD_ID ) ).toBe( 'PVZ-1' );
} );

// -------------------------------------------------------------------------
// C3 — every written address field fires a real change, and change.select2
// when it is select2-enhanced (a tiny jQuery stub is needed for this one)
// -------------------------------------------------------------------------

test( 'a select2-enhanced address field gets change.select2 fired through jQuery, mirroring §8', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );

	const citySelect = document.getElementById( 'billing_city' );
	citySelect.classList.add( 'select2-hidden-accessible' );

	const namespacedCalls = [];
	function FakeJQuery( el ) {
		return {
			trigger: function( eventName ) {
				if ( el === citySelect ) {
					namespacedCalls.push( eventName );
				}
			},
		};
	}
	window.jQuery = FakeJQuery;

	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( namespacedCalls ).toContain( 'change.select2' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'a plain (non-select2) address field does NOT get change.select2', () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );

	const calls = [];
	window.jQuery = function( el ) {
		return { trigger: function( eventName ) { calls.push( { el: el, eventName: eventName } ); } };
	};

	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

	expect( calls.length ).toBe( 0 );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

// -------------------------------------------------------------------------
// dataSource error/empty mapping (Task 20: THIS FILE now calls fetchPoints()
// itself, right after provider.init() resolves under strategy: 'bulk')
// -------------------------------------------------------------------------

test.each( [
	[ 'woodev_pickup_upstream_error', 'upstreamError' ],
	[ 'woodev_pickup_rate_limited', 'rateLimited' ],
	[ 'woodev_pickup_point_not_found', 'notFound' ],
] )( 'dataSource code %s maps to the PHP-emitted i18n.%s message', async ( code, i18nKey ) => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 502, code: code, message: 'raw ' + code } )
	);
	const config = makeConfig();
	setConfig( config );
	mountAll();
	clickTrigger();

	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( config.i18n[ i18nKey ] );
	expect( dialog.textContent ).not.toContain( code );
} );

test( 'an unmapped/unknown code falls back to the generic error message, never the raw code', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 500, code: 'something_else', message: 'raw' } )
	);
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Не удалось загрузить пункты выдачи' );
	expect( dialog.textContent ).not.toContain( 'something_else' );
} );

test( 'a genuinely empty result shows the message as a NON-destructive notice, keeping the panels chrome '
	+ '(Task 20: panels share modal.getContainer() with the map — a destructive showEmpty() would wipe '
	+ 'them out for no reason a dataSource hiccup justifies)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Пункты выдачи не найдены' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).not.toBeNull();
	// The panels chrome survived — it lives in the SAME container the empty state would
	// otherwise have wiped.
	expect( dialog.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
} );

test( 'a non-empty result shows neither the error nor the empty state', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [ point() ] ) );
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
} );

// -------------------------------------------------------------------------
// C1 — non-destructive degradation once a point set has been drawn (Task 20:
// the SECOND fetch within a bulk session now comes from refresh(), the only
// way to re-fetch without a real viewport/type-filter change), and retry
// always destroying the live provider and constructing a fresh one
// -------------------------------------------------------------------------

/**
 * A provider double that marks its container as "drawn" on init() — shared by the two C1 tests
 * below to prove drawn content survives a subsequent empty/failed fetch.
 */
function DrawingProvider() {
	StubProvider.call( this );
}
DrawingProvider.prototype = Object.create( StubProvider.prototype );
DrawingProvider.prototype.init = function( container, config, dataSource ) {
	StubProvider.prototype.init.call( this, container, config, dataSource );

	if ( ! container.querySelector( '.drawn-map-marker' ) ) {
		const marker = document.createElement( 'div' );
		marker.className = 'drawn-map-marker';
		container.appendChild( marker );
	}
};

test( 'once a set is drawn, a SUBSEQUENT empty refresh() shows a NOTICE, keeping the drawn content', async () => {
	let resolveWith = [ point() ];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( resolveWith ) );
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	// A changed viewport/payment method, via refresh() — the only re-fetch trigger under
	// `strategy: 'bulk'` with no real provider driving boundsChange.
	resolveWith = [];
	await getSession( FIELD_ID ).refresh();

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).not.toBeNull();
	expect( dialog.textContent ).toContain( 'Пункты выдачи не найдены' );
} );

test( 'once drawn, a failed refresh() shows a NOTICE with retry, keeping the drawn content', async () => {
	let shouldFail = false;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		shouldFail
			? Promise.reject( { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } )
			: Promise.resolve( [ point() ] )
	);
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	shouldFail = true;
	await getSession( FIELD_ID ).refresh();

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	const notice = dialog.querySelector( '.woodev-modal__notice' );
	expect( notice ).not.toBeNull();
	expect( notice.textContent ).toContain( 'Сервис пунктов выдачи временно недоступен' );

	// Retry on the notice destroys the OLD provider and builds a fresh one — never
	// re-init()s the live instance.
	const oldProvider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const retryButton = notice.querySelector( '.woodev-modal__notice-retry' );
	expect( retryButton ).not.toBeNull();

	shouldFail = false;
	retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	await flushAsync();

	expect( oldProvider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ] ).not.toBe( oldProvider );
} );

test( 'BEFORE anything is drawn, a provider-level error still uses the destructive showError (nothing to lose)', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).not.toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).toBeNull();
} );

test( 'a provider-emitted error retry destroys the old provider and constructs a fresh one, never re-init()ing', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 429, code: 'woodev_pickup_rate_limited', message: 'raw' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Слишком много запросов' );

	const retryButton = dialog.querySelector( '.woodev-modal__retry' );
	expect( retryButton ).not.toBeNull();
	retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( provider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( StubProvider.instances[ 1 ].initCalls.length ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// No stacked sessions — including across a slot recreated mid-session (I2) —
// and NO panels/providers left alive either (Task 20)
// -------------------------------------------------------------------------

test( 'clicking the trigger twice in a row never leaves two providers or two panels alive', () => {
	setConfig( makeConfig() );
	mountAll();

	const trigger = clickTrigger();
	trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	// Task 20: one panels instance is constructed per SESSION (not per retry), and a second
	// click opens a second, independent one — the old session's panels DOM went with its
	// (destroyed) modal.
	expect( StubPanels.instances.length ).toBe( 2 );
	expect( document.querySelectorAll( '.woodev-pickup-list' ).length ).toBe( 1 );
} );

test( 'closing via Escape, then clicking again, opens a clean new session', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();

	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );
} );

test( 're-mounting an already-mounted slot never attaches a second click listener', () => {
	setConfig( makeConfig() );
	mountAll();
	mountAll();
	mountAll();

	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 1 );
} );

test( 'I2: a session opened before §8 recreates the anchor is still torn down when the NEW trigger is clicked', () => {
	setConfig( makeConfig() );
	mountAll();

	const oldTrigger = clickTrigger(); // opens session #1, mounted on the OLD button
	expect( StubProvider.instances.length ).toBe( 1 );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	// §8 recreates the whole anchor — the old button (and any state closed over only by
	// ITS click handler) is discarded, exactly like a real `updated_checkout` AJAX swap.
	const oldSlot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	oldSlot.parentNode.removeChild( oldSlot );
	const freshSlot = document.createElement( 'div' );
	freshSlot.setAttribute( 'data-woodev-pickup-slot', FIELD_ID );
	document.body.appendChild( freshSlot );
	mountAll();

	expect( document.body.contains( oldTrigger ) ).toBe( false );

	// Clicking the NEW trigger must tear down session #1 (still tracked in module scope,
	// not lost with the old button) before opening a second one — never two live at once.
	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );
} );

// =========================================================================
// Task 20 — the wiring that makes the feature actually work
// =========================================================================

// -------------------------------------------------------------------------
// The ownsChrome branch (D-3): no panels at all for a provider that owns
// the whole container — not merely hidden
// -------------------------------------------------------------------------

test( 'renders panels for a provider that does not own the chrome', async () => {
	await openSession( configWith( { ownsChrome: false } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
	expect( StubPanels.instances.length ).toBe( 1 );
} );

test( 'renders no panels for a provider that owns the chrome', async () => {
	await openSession( configWith( { ownsChrome: true } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).toBeNull();
	// Never constructed — not just hidden/unrendered.
	expect( StubPanels.instances.length ).toBe( 0 );
} );

// -------------------------------------------------------------------------
// The four woodev_pickup_* document.body events
// -------------------------------------------------------------------------

test( 'fires woodev_pickup_map_ready once the provider init resolves, naming fieldId AND provider (D-14)', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_map_ready', ( e ) => seen.push( e.detail ) );
	await openSession( configWith() );

	// Exact equality — pins the full D-14 payload shape, not just one field of it. `provider`
	// is the whole point of this event for an integrator hooking a SPECIFIC map: without it
	// there is no way to tell which provider just initialised.
	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, provider: 'testProvider' } );
} );

test( 'fires woodev_pickup_map_ready for an ownsChrome provider too, still naming the provider', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_map_ready', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { ownsChrome: true } ) );

	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, provider: 'testProvider' } );
} );

test( 'every woodev_pickup_* event bubbles (jQuery delegation relies on it, see the file docblock)', async () => {
	const seenOnDocument = [];
	// Listening on `document` — the PARENT of `document.body`, where these events are
	// actually dispatched — only sees them if `bubbles: true` was set; a non-bubbling event
	// dispatched on `document.body` would never reach here.
	[ 'woodev_pickup_map_ready', 'woodev_pickup_points_loaded', 'woodev_pickup_point_selected', 'woodev_pickup_error' ]
		.forEach( ( type ) => document.addEventListener( type, ( e ) => seenOnDocument.push( e.type ) ) );

	const session = await openSession(
		configWith( { strategy: 'bulk', replaceAddress: { enabled: false, billingOnly: true } } )
	);
	session.provider.emit( 'error', { code: 'x', message: 'y' } );
	session.panels.emit( 'select', point( { id: 'p1' } ) );

	expect( seenOnDocument ).toEqual( expect.arrayContaining( [
		'woodev_pickup_map_ready', 'woodev_pickup_points_loaded', 'woodev_pickup_error', 'woodev_pickup_point_selected',
	] ) );
} );

test( 'fires woodev_pickup_points_loaded with the count and strategy', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ), point( { id: 'p2', lat: 3, lng: 4 } ) ] )
	);
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { strategy: 'bulk' } ) );

	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, count: 2, strategy: 'bulk' } );
} );

test( 'never fires woodev_pickup_points_loaded for an ownsChrome provider (it never fetches)', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { ownsChrome: true } ) );

	expect( seen ).toHaveLength( 0 );
} );

test( 'fires woodev_pickup_point_selected (fieldId + point) and closes with reason select', async () => {
	const selected = [];
	const closed = [];
	document.body.addEventListener( 'woodev_pickup_point_selected', ( e ) => selected.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	const selectedPoint = point( { id: 'p1' } );
	const session = await openSession( configWith() );
	session.panels.emit( 'select', selectedPoint );

	// Exact equality — pins fieldId AND the point object, not just one of the two.
	expect( selected[ 0 ] ).toEqual( { fieldId: FIELD_ID, point: selectedPoint } );
	expect( closed[ 0 ].reason ).toBe( 'select' );
	// The default config's address replacement writes billing_address_1/postcode too, which
	// no §8 store in this test manages — an expected, acknowledged warn (see C2 above).
	expect( console ).toHaveWarned();
} );

test( 'fires woodev_pickup_error when the provider reports a fatal error', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_error', ( e ) => seen.push( e.detail ) );

	const session = await openSession( configWith() );
	session.provider.emit( 'error', { code: 'map_script', message: 'ymaps script failed to load' } );

	// Exact equality — pins fieldId AND message, not just code.
	expect( seen[ 0 ] ).toEqual( {
		fieldId: FIELD_ID,
		code: 'map_script',
		message: 'ymaps script failed to load',
	} );
} );

test( 'does NOT fire woodev_pickup_error for a transient (non-fatal) dataSource fetch failure', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } )
	);
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_error', ( e ) => seen.push( e.detail ) );

	await openSession( configWith() );

	expect( seen ).toHaveLength( 0 );
} );

// -------------------------------------------------------------------------
// refresh()
// -------------------------------------------------------------------------

test( 'exposes refresh() on the open session', async () => {
	const session = await openSession( configWith() );

	expect( typeof session.refresh ).toBe( 'function' );
} );

test( 'refresh() re-runs the bulk fetch and fires a fresh points_loaded', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );

	const session = await openSession( configWith( { strategy: 'bulk' } ) );
	const callsBeforeRefresh = fetchCalls;
	const seenBeforeRefresh = seen.length;

	await session.refresh();

	expect( fetchCalls ).toBe( callsBeforeRefresh + 1 );
	expect( seen.length ).toBe( seenBeforeRefresh + 1 );
} );

test( 'refresh() is safe to call twice in a row', async () => {
	const session = await openSession( configWith() );

	await expect( Promise.all( [ session.refresh(), session.refresh() ] ) ).resolves.toBeDefined();
} );

test( 'refresh() is safe to call after the session has been fully torn down', async () => {
	const config = configWith();
	const session = await openSession( config );

	session.provider.emit( 'select', point() ); // tears the session down via handleSelection
	// The default config's address replacement writes billing_address_1/postcode too, which
	// no §8 store in this test manages — an expected, acknowledged warn (see C2 above).
	expect( console ).toHaveWarned();

	await expect( session.refresh() ).resolves.toBeUndefined();
} );

test( 'refresh() is a no-op for an ownsChrome provider (nothing here ever fetches for it)', async () => {
	const session = await openSession( configWith( { ownsChrome: true } ) );

	await expect( session.refresh() ).resolves.toBeUndefined();
} );

// -------------------------------------------------------------------------
// Provider → panels wiring
// -------------------------------------------------------------------------

test( 'provider pointClick opens the card for the matching group', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	expect( session.panels.lastOpenCard.group.key ).toBe( '1.0000,2.0000' );
} );

test( 'a marker click focuses the group BEFORE opening its card, in that order (spec V-10)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	expect( session.provider.focusGroupCalls ).toEqual( [ '1.0000,2.0000' ] );
	expect( callOrder ).toEqual( [ 'focusGroup:1.0000,2.0000', 'openCard:1.0000,2.0000' ] );
} );

test( 'a marker click opens the card WITHOUT waiting for focusGroup()\'s camera move to settle '
	+ '(spec V-10 — the card is our own DOM, not the viewport)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	// `StubProvider.focusGroup()` returns a promise that NEVER resolves. No `await`/flush
	// happens between the `emit()` above and this assertion — if `pickup-mount.js` chained the
	// card open off that promise, `lastOpenCard` would still be unset here, forever.
	expect( session.panels.lastOpenCard ).toBeDefined();
	expect( session.panels.lastOpenCard.group.key ).toBe( '1.0000,2.0000' );
} );

test( 'provider visibleChange resolves keys to groups and calls panels.setVisible', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [
		point( { id: 'a', lat: 1, lng: 2 } ),
		point( { id: 'b', lat: 3, lng: 4 } ),
	] ) );
	const session = await openSession( configWith() );

	session.provider.emit( 'visibleChange', [ '1.0000,2.0000' ] );

	expect( session.panels.lastVisible ).toHaveLength( 1 );
	expect( session.panels.lastVisible[ 0 ].key ).toBe( '1.0000,2.0000' );
} );

test( 'provider nothingNearby calls panels.showNothingNearby with the same payload', async () => {
	const session = await openSession( configWith() );
	const info = { key: 'x', distanceMeters: 999, name: 'Y' };

	session.provider.emit( 'nothingNearby', info );

	expect( session.panels.lastNothingNearby ).toBe( info );
} );

test( 'provider bboxTooWide shows the i18n.zoomIn message WITHOUT destroying the map/panels the '
	+ 'customer is being asked to zoom', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'bboxTooWide', null );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Приблизьте карту, чтобы увидеть пункты выдачи' );
	// NON-destructive: a notice, never the whole-body showError()/showEmpty() replacement —
	// wiping the map/panels here would make the "zoom in" instruction impossible to follow.
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).not.toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
} );

test( 'provider searchResults forwards to panels.renderSearchResults verbatim', async () => {
	const session = await openSession( configWith() );
	const results = { points: [ point() ], addresses: [ { displayName: 'Тверская 1' } ] };

	session.provider.emit( 'searchResults', results );

	expect( session.panels.lastSearchResults ).toBe( results );
} );

// -------------------------------------------------------------------------
// Panels → provider wiring
// -------------------------------------------------------------------------

test( 'panels listToggle calls provider.setMargin with the open state and width', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'listToggle', { open: true, width: 320 } );

	expect( session.provider.setMarginCalls ).toEqual( [ { open: true, width: 320 } ] );
} );

test( 'panels searchAddressPicked resolves the address AT THAT INDEX against the provider', async () => {
	const session = await openSession( configWith() );
	session.provider.emit( 'searchResults', { points: [], addresses: [ { displayName: 'A' }, { displayName: 'B' } ] } );

	session.panels.emit( 'searchAddressPicked', 1 );

	expect( session.provider.resolveAddressCalls ).toEqual( [ 'B' ] );
} );

test( 'provider addressFocused moves the panels\' distance anchor to the SAME latLng/label (D-6)', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'addressFocused', { latLng: [ 55.75, 37.61 ], label: 'Москва, Тверская 1' } );

	expect( session.panels.setAnchorCalls ).toEqual( [
		{ latLng: [ 55.75, 37.61 ], label: 'Москва, Тверская 1' },
	] );
} );

test( 'provider addressFocused moves the anchor even when nothing turns out to be nearby '
	+ '(the pin still dropped)', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'addressFocused', { latLng: [ 1, 2 ], label: 'Далеко' } );
	session.provider.emit( 'nothingNearby', { key: 'g', distanceMeters: 99999, name: 'X' } );

	expect( session.panels.setAnchorCalls ).toEqual( [ { latLng: [ 1, 2 ], label: 'Далеко' } ] );
	expect( session.panels.lastNothingNearby ).toEqual( { key: 'g', distanceMeters: 99999, name: 'X' } );
} );

test( 'panels searchPointPicked focuses the owning group and opens its card on the exact point', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p9', lat: 10, lng: 20 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'searchPointPicked', 'p9' );

	expect( session.provider.focusGroupCalls ).toEqual( [ '10.0000,20.0000' ] );
	expect( session.panels.lastOpenCard.pointId ).toBe( 'p9' );
} );

test( 'panels showNearestRequested focuses the group named by info.key and opens its card', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 55.8, lng: 37.7 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'showNearestRequested', { key: '55.8000,37.7000', distanceMeters: 100, name: 'X' } );

	expect( session.provider.focusGroupCalls ).toEqual( [ '55.8000,37.7000' ] );
	expect( session.panels.lastOpenCard.group.key ).toBe( '55.8000,37.7000' );
} );

test( 'panels showNearestRequested is a no-op when info.key names a group that is no longer loaded', async () => {
	const session = await openSession( configWith() );

	expect( () => session.panels.emit( 'showNearestRequested', { key: 'ghost', distanceMeters: 1, name: 'X' } ) )
		.not.toThrow();
	expect( session.provider.focusGroupCalls ).toEqual( [] );
} );

test( 'panels anchorCleared clears the address — the "your address" pin cannot outlive its search', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'anchorCleared', null );

	expect( session.provider.clearAddressCalls ).toBe( 1 );
} );

test( 'panels.setSelectedId is seeded from the field\'s current value at session-open time', async () => {
	document.getElementById( FIELD_ID ).value = 'PVZ-EXISTING';

	const session = await openSession( configWith() );

	expect( session.panels.lastSelectedId ).toBe( 'PVZ-EXISTING' );
} );

test( 'panels.setSelectedId is never called when the field has no value yet', async () => {
	const session = await openSession( configWith() );

	expect( session.panels.lastSelectedId ).toBeUndefined();
} );

// -------------------------------------------------------------------------
// The strategy-dependent type-filter destination (D-10) — getting this
// backwards is invisible under a loosely-stubbed dataSource, so both sides
// are pinned by VALUE, not just "some branch was taken"
// -------------------------------------------------------------------------

test( 'typeFilterChange under bulk calls provider.setTypeFilter and does NOT refetch', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'bulk' } ) );
	const callsBefore = fetchCalls;

	session.panels.emit( 'typeFilterChange', [ 'pvz' ] );
	await flushAsync();

	expect( session.provider.setTypeFilterCalls ).toEqual( [ [ 'pvz' ] ] );
	expect( fetchCalls ).toBe( callsBefore ); // client-side filter only — a refetch would be waste
} );

test( 'typeFilterChange under viewport refetches the SAME bbox + new types, never setTypeFilter', async () => {
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );

	session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
	await flushAsync();

	session.panels.emit( 'typeFilterChange', [ 'pvz' ] );
	await flushAsync();

	expect( session.provider.setTypeFilterCalls ).toEqual( [] ); // never a client-side filter under viewport
	expect( queries[ queries.length - 1 ] ).toEqual( { bounds: [ 1, 2, 3, 4 ], types: [ 'pvz' ] } );
} );

test( 'typeFilterChange under viewport, before any boundsChange, does not throw and does not fetch', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );
	const callsBefore = fetchCalls;

	expect( () => session.panels.emit( 'typeFilterChange', [ 'pvz' ] ) ).not.toThrow();
	await flushAsync();

	expect( fetchCalls ).toBe( callsBefore );
} );

// -------------------------------------------------------------------------
// boundsChange (viewport) drives the fetch → setPoints() → panels.setTypes()
// chain end to end
// -------------------------------------------------------------------------

test( 'a viewport boundsChange fetches, groups, and hands the groups to provider.setPoints()', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [
		point( { id: 'a', lat: 1, lng: 2 } ),
		point( { id: 'b', lat: 1, lng: 2 } ), // co-located with 'a' — folds into the SAME group
	] ) );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );

	session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
	await flushAsync();

	const lastGroups = session.provider.setPointsCalls[ session.provider.setPointsCalls.length - 1 ];
	expect( lastGroups ).toHaveLength( 1 );
	expect( lastGroups[ 0 ].points ).toHaveLength( 2 );
	expect( session.panels.lastTypes ).toEqual( [ { code: 'pvz', label: 'ПВЗ' } ] );
} );

// -------------------------------------------------------------------------
// A real Panels integration smoke test — proves buildPanelsConfig() actually
// produces a shape the REAL pickup-panels.js class accepts and renders from
// -------------------------------------------------------------------------

test( 'INTEGRATION: the real Panels class renders correctly from buildPanelsConfig()\'s output', async () => {
	window.WoodevPickupPanels = RealPanels;

	const config = configWith( {
		mapConfig: { center: [ 55.75, 37.61 ], lang: 'ru_RU' },
		i18n: phpI18n( { drawerTitle: 'Пункты выдачи в этой области' } ),
	} );
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );

	// The real class builds its whole structure from that config: the stage, the map element the
	// provider mounts into, and both panels.
	const stage = dialog.querySelector( '.woodev-pickup-stage' );
	expect( stage ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-map' ) ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-card' ) ).not.toBeNull();

	// And the i18n map reached it. This used to be asserted through the list header's text; Task 7
	// (spec V-11) deleted that header — neither reference has one and it stated something the
	// customer can see — so `drawerTitle` now names the control that opens the drawer instead.
	expect( stage.querySelector( '.woodev-pickup-list__toggle' ).getAttribute( 'aria-label' ) )
		.toBe( 'Пункты выдачи в этой области' );
} );

test( 'INTEGRATION: a REAL click on a sidebar list row reaches focusGroup() exactly like a marker '
	+ 'click does (spec V-10) — pickup-panels.js itself is never touched by this file', async () => {
	window.WoodevPickupPanels = RealPanels;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);

	const config = configWith();
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	// The real Panels' list only ever shows what the provider last reported visible — the stub
	// provider never emits that on its own, so this drives it exactly like a real one would once
	// its viewport settles.
	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'visibleChange', [ '1.0000,2.0000' ] );

	const dialog = document.querySelector( '[role="dialog"]' );
	dialog.querySelector( '.woodev-pickup-list__item' ).click();

	expect( provider.focusGroupCalls ).toEqual( [ '1.0000,2.0000' ] );
} );
