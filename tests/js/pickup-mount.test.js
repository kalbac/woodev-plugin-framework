/**
 * Tests for pickup-mount.js
 *
 * Covers SP-5 Task 12: idempotent trigger placement, the click → modal →
 * provider → dataSource wiring, writing a selection THROUGH the field's OWN
 * owning store (never straight to the DOM, and never through a store that
 * does not manage that field), firing `change`/`change.select2` after every
 * write, address replacement target resolution, missing-option handling for
 * the city select, dataSource error/empty mapping, the non-destructive
 * degrade-to-notice once a point set is drawn, retry always rebuilding the
 * provider from scratch, i18n keys read from the SHAPE the PHP side actually
 * emits, and the no-duplicate-session guarantee across a slot recreated
 * mid-session.
 *
 * `jest.useFakeTimers()` is installed BEFORE pickup-mount.js is required, so
 * the module's own top-level `setTimeout()` calls (initial mount +
 * `updated_checkout` defer) are captured under fake-timer control from the
 * very first require — a real timer registered before fake timers are
 * installed would otherwise fire uncontrolled, mid test, with whatever
 * `window` state happened to exist at that moment.
 *
 * No real jQuery is loaded in this environment (none is a project
 * dependency), so `window.jQuery` is undefined and pickup-mount.js's
 * `onCheckoutUpdated()` falls back to a plain native `updated_checkout` event
 * on `document.body` — exactly the fallback its own docblock documents. The
 * `change.select2`-firing branch is likewise unreachable without jQuery, so a
 * dedicated tiny jQuery stub is installed for the ONE test that needs it.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-mount.js
 */

'use strict';

jest.useFakeTimers();

const { createStore } = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );
require( '../../woodev/assets/js/frontend/woodev-modal' ); // side effect: window.WoodevModal
const { mountAll } = require( '../../woodev/shipping-method/assets/js/frontend/pickup-mount' );

const FIELD_ID = 'carrier_pickup_point';

/**
 * A minimal, test-controlled `Map_Provider` double. Records every `init()`
 * call and lets a test `emit()` `select`/`error` as if the (not-yet-built)
 * real provider had. Every constructed instance is pushed onto
 * `StubProvider.instances` so a test can assert how many concurrently-live
 * providers ever existed and which of them were destroyed.
 */
function StubProvider() {
	this.handlers = { select: [], error: [] };
	this.destroyed = false;
	this.initCalls = [];
	StubProvider.instances.push( this );
}

StubProvider.instances = [];

StubProvider.prototype.init = function( container, config, dataSource ) {
	this.initCalls.push( { container: container, config: config, dataSource: dataSource } );
};

StubProvider.prototype.on = function( event, cb ) {
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

/**
 * A provider double that EAGERLY calls `dataSource.fetchPoints()` from
 * `init()`, the way a real bulk-strategy provider's initial load would —
 * lets a test drive pickup-mount.js's own error/empty mapping (which lives in
 * front of whatever the dataSource resolves/rejects with) without needing a
 * real map library. `pending` is the settled-or-not promise a test can
 * `await` to know the microtask chain has flushed. Re-implements `init()`
 * fully on every construction (rather than delegating to StubProvider's),
 * since a retry constructs a brand NEW instance each time — exactly the
 * behaviour under test.
 */
function EagerStubProvider() {
	StubProvider.call( this );
}

EagerStubProvider.prototype = Object.create( StubProvider.prototype );

EagerStubProvider.prototype.init = function( container, config, dataSource ) {
	StubProvider.prototype.init.call( this, container, config, dataSource );
	this.pending = dataSource.fetchPoints( {} ).then(
		function( points ) {
			this.lastPoints = points;
		}.bind( this ),
		function( reason ) {
			this.lastError = reason;
		}.bind( this )
	);
};

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
			retry: 'Повторить',
			upstreamError: 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
			rateLimited: 'Слишком много запросов. Подождите немного и попробуйте снова.',
			notFound: 'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
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
	};

	return Object.assign( {}, base, overrides );
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

function point( overrides ) {
	return Object.assign(
		{
			id: 'PVZ-1',
			name: 'Точка',
			address: 'ул. Ленина, 1',
			locality: 'Москва',
			postal_code: '101000',
		},
		overrides
	);
}

beforeEach( () => {
	StubProvider.instances = [];
	buildCheckoutDom();
	window.WoodevPickupMapProviders = { testProvider: StubProvider };
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.woodev_pickup_config_p;
	delete window.WoodevPickupMapProviders;
	delete window.WoodevPickupDataSource;
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
// Click → modal → provider → dataSource
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
	// The provider config is the MERGE buildProviderConfig() builds — mapConfig's own
	// keys plus strategy/i18n/locality — never config.mapConfig passed through raw.
	expect( calls[ 0 ].config ).toEqual( {
		center: [ 55.75, 37.61 ],
		strategy: 'bulk',
		i18n: config.i18n,
		locality: '',
	} );
	expect( typeof calls[ 0 ].dataSource.fetchPoints ).toBe( 'function' );
	expect( typeof calls[ 0 ].dataSource.fetchDetails ).toBe( 'function' );
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
	} );
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
// select → write THROUGH the field's OWN owning store, fire change, close
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

test( 'select closes the shell', () => {
	makeStore();
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	StubProvider.instances[ 0 ].emit( 'select', point() );

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
// dataSource error/empty mapping (this file's own responsibility — see its
// docblock for why the provider cannot call showError()/showEmpty() itself),
// keyed by the i18n shape the PHP side actually emits
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
	window.WoodevPickupMapProviders = { testProvider: EagerStubProvider };
	mountAll();
	clickTrigger();

	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( config.i18n[ i18nKey ] );
	expect( dialog.textContent ).not.toContain( code );
} );

test( 'an unmapped/unknown code falls back to the generic error message, never the raw code', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 500, code: 'something_else', message: 'raw' } )
	);
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: EagerStubProvider };
	mountAll();
	clickTrigger();

	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Не удалось загрузить пункты выдачи' );
	expect( dialog.textContent ).not.toContain( 'something_else' );
} );

test( 'a genuinely empty result (nothing drawn yet) shows the EMPTY state, not the error state', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: EagerStubProvider };
	mountAll();
	clickTrigger();

	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Пункты выдачи не найдены' );
	expect( dialog.querySelector( '.woodev-pickup-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-modal__message--empty' ) ).not.toBeNull();
} );

test( 'a non-empty result shows neither the error nor the empty state', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [ point() ] ) );
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: EagerStubProvider };
	mountAll();
	clickTrigger();

	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-pickup-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-modal__message--empty' ) ).toBeNull();
} );

// -------------------------------------------------------------------------
// C1 — non-destructive degradation once a point set has been drawn, and
// retry always destroying the live provider and constructing a fresh one
// -------------------------------------------------------------------------

test( 'once a set is drawn, a SUBSEQUENT empty result shows a NOTICE, keeping the drawn content', async () => {
	let resolveWith = [ point() ];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( resolveWith ) );
	setConfig( makeConfig() );

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
		this.dataSource = dataSource;
		// The initial load — this is what actually sets hasDrawnPoints inside pickup-mount.js.
		this.pending = dataSource.fetchPoints( {} );
	};
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	// Now a subsequent fetch (e.g. the customer panning) comes back empty.
	resolveWith = [];
	await StubProvider.instances[ 0 ].dataSource.fetchPoints( {} );

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( dialog.querySelector( '.woodev-pickup-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-modal__notice' ) ).not.toBeNull();
	expect( dialog.textContent ).toContain( 'Пункты выдачи не найдены' );
} );

test( 'once a set is drawn, a SUBSEQUENT error shows a NOTICE with retry, keeping the drawn content', async () => {
	let shouldFail = false;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		shouldFail
			? Promise.reject( { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } )
			: Promise.resolve( [ point() ] )
	);
	setConfig( makeConfig() );

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
		this.dataSource = dataSource;
		// The initial load — this is what actually sets hasDrawnPoints inside pickup-mount.js.
		this.pending = dataSource.fetchPoints( {} ).catch( () => {} );
	};
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await StubProvider.instances[ 0 ].pending;

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	shouldFail = true;
	await StubProvider.instances[ 0 ].dataSource.fetchPoints( {} ).catch( () => {} );

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( dialog.querySelector( '.woodev-pickup-modal__message--error' ) ).toBeNull();
	const notice = dialog.querySelector( '.woodev-pickup-modal__notice' );
	expect( notice ).not.toBeNull();
	expect( notice.textContent ).toContain( 'Сервис пунктов выдачи временно недоступен' );

	// Retry on the notice destroys the OLD provider and builds a fresh one — never
	// re-init()s the live instance.
	const oldProvider = StubProvider.instances[ 0 ];
	const retryButton = notice.querySelector( '.woodev-pickup-modal__notice-retry' );
	expect( retryButton ).not.toBeNull();

	shouldFail = false;
	retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( oldProvider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ] ).not.toBe( oldProvider );
} );

test( 'BEFORE anything is drawn, an error still uses the destructive showError (nothing to lose)', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-pickup-modal__message--error' ) ).not.toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-modal__notice' ) ).toBeNull();
} );

test( 'a provider-emitted error retry destroys the old provider and constructs a fresh one, never re-init()ing', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 429, code: 'woodev_pickup_rate_limited', message: 'raw' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Слишком много запросов' );

	const retryButton = dialog.querySelector( '.woodev-pickup-modal__retry' );
	expect( retryButton ).not.toBeNull();
	retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( provider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( StubProvider.instances[ 1 ].initCalls.length ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// No stacked sessions — including across a slot recreated mid-session (I2)
// -------------------------------------------------------------------------

test( 'clicking the trigger twice in a row never leaves two providers alive at once', () => {
	setConfig( makeConfig() );
	mountAll();

	const trigger = clickTrigger();
	trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );
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
