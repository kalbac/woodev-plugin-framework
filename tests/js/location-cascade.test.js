/**
 * Tests for location-cascade.js — Task 11 of the location-provider plan.
 *
 * Covers: chain assembly from present fields only, region/settlement/address scoping,
 * persist-then-trigger ordering (D8), downward-only dependent clearing through the
 * remembered-parent-value gate (mirrors gotcha
 * `a-programmatic-parent-change-must-not-run-a-destructive-cascade`), backwards fill with
 * no second lookup, dual native+jQuery event-world binding (gotcha
 * `jquery-trigger-change-fires-no-native-event`), per-country widget attach/detach, D15
 * unsupported-level degradation, a failed/unpersisted `/select`, `config.location.current`
 * restore without a re-fetch, checkout re-render (`updated_checkout` replacing a field
 * node), and store-sharing with `checkout-field-classic.js` via `getStoreForField()`.
 *
 * Real jQuery is loaded throughout (a devDependency — see `checkout-field-classic.test.js`'s
 * own docblock for why the claim to the contrary elsewhere is stale). Task 10's own
 * `location-typeahead.js` widget is REPLACED with a fake `window.WoodevLocationTypeahead`
 * that records `{ el, fetch, onSelect, detach }` per attach call — Task 10's internal
 * debounce/keyboard/XSS mechanics already have their own test file; this file tests only
 * what location-cascade.js itself owns (chain, scoping, persistence, clearing, backwards
 * fill, attach/detach, degradation), driving the fake's `fetch`/`onSelect` exactly the way
 * the real widget would (see {@see selectViaFake}).
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js
 * @see docs-internal/plans/2026-08-12-location-provider-plan.md — Task 11
 * @see docs-internal/specs/2026-08-12-location-provider-design.md — §4.4, D8, D13, D15
 */

'use strict';

const CONFIG_GLOBAL = 'woodev_checkout_field_config_location_cascade_test';
const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';

let attachCalls;
let fetchCalls;

/**
 * Yields several real microtask ticks — mirrors location-typeahead.test.js's own helper.
 */
async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

/**
 * The WC-convention field id for `level` within `section` (`'billing'` or `'shipping'`) —
 * mirrors `location-cascade.js`'s own `LEVEL_SUFFIX` derivation exactly.
 *
 * @param {string} level
 * @param {string} section
 * @returns {string}
 */
function fieldIdFor( level, section ) {
	const suffix = { region: 'state', settlement: 'city', address: 'address_1' }[ level ];
	return ( 'shipping' === section ? 'shipping_' : 'billing_' ) + suffix;
}

/**
 * Builds the classic-checkout markup for whichever location fields + postcode the test
 * needs. `billing_country` (and its postcode counterpart) is always present (a native WC
 * field, never declared in `config.fields`). `#shipping_country` and the WC
 * `ship_to_different_address` checkbox are rendered whenever `which.section === 'shipping'`
 * (or `which.withShippingCountry` is set for a test that wants both present) — mirrors WC's
 * OWN classic-checkout template, which always renders both country selects and the toggle
 * checkbox together.
 *
 * @param {{region?: boolean, settlement?: boolean, address?: boolean, section?: string,
 *          shippingCountry?: string, shipToDifferentAddress?: boolean,
 *          withShippingCountry?: boolean}} which
 * @param {string} country
 * @returns {void}
 */
function installMarkup( which, country = 'RU' ) {
	const w = which || {};
	const section = w.section || 'billing';
	let inputs = '';

	if ( w.region ) {
		inputs += `<input type="text" id="${ fieldIdFor( 'region', section ) }" name="${ fieldIdFor( 'region', section ) }" value="" />`;
	}
	if ( w.settlement ) {
		inputs += `<input type="text" id="${ fieldIdFor( 'settlement', section ) }" name="${ fieldIdFor( 'settlement', section ) }" value="" />`;
	}
	if ( w.address ) {
		inputs += `<input type="text" id="${ fieldIdFor( 'address', section ) }" name="${ fieldIdFor( 'address', section ) }" value="" />`;
	}

	const postcodeId = 'shipping' === section ? 'shipping_postcode' : 'billing_postcode';
	const needsShippingCountry = 'shipping' === section || w.withShippingCountry;
	const checked = false !== w.shipToDifferentAddress; // defaults to checked (true)

	const shippingMarkup = needsShippingCountry ? `
		<select id="shipping_country" name="shipping_country">
			<option value="RU">Россия</option>
			<option value="US">США</option>
		</select>
		<input type="checkbox" id="ship-to-different-address-checkbox" name="ship_to_different_address" ${ checked ? 'checked' : '' } />
	` : '';

	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<select id="billing_country" name="billing_country">
				<option value="RU">Россия</option>
				<option value="US">США</option>
			</select>
			${ shippingMarkup }
			${ inputs }
			<input type="text" id="${ postcodeId }" name="${ postcodeId }" value="" />
		</form>
	`;

	document.getElementById( 'billing_country' ).value = country;

	const shippingCountryEl = document.getElementById( 'shipping_country' );
	if ( shippingCountryEl ) {
		shippingCountryEl.value = w.shippingCountry !== undefined ? w.shippingCountry : country;
	}
}

/**
 * Builds one location-kind field descriptor, matching `Checkout_Config::build()`'s emitted
 * shape (`class-checkout-config.php`).
 *
 * @param {string} level
 * @param {string} [section]
 * @returns {Object}
 */
function locationField( level, section = 'billing' ) {
	return {
		id: null,
		type: 'text',
		section,
		source_kind: 'location',
		location_level: level,
		depends_on: null,
		required: false,
		is_pickup_slot: false,
	};
}

/**
 * Builds the full `woodev_checkout_field_config_*` global, matching
 * `Checkout_Config::build()`'s shape including the `location` block
 * (`Checkout_Config::build_location_block()`).
 *
 * @param {{region?: boolean, settlement?: boolean, address?: boolean, section?: string,
 *          levels?: Object, countries?: string[], current?: Object|null}} opts
 * @returns {Object}
 */
function buildConfig( opts ) {
	const o = opts || {};
	const section = o.section || 'billing';
	const fields = {};

	if ( o.region ) {
		fields[ fieldIdFor( 'region', section ) ] = locationField( 'region', section );
	}
	if ( o.settlement ) {
		fields[ fieldIdFor( 'settlement', section ) ] = locationField( 'settlement', section );
	}
	if ( o.address ) {
		fields[ fieldIdFor( 'address', section ) ] = locationField( 'address', section );
	}

	return {
		fields,
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'test-nonce',
		takeover: {},
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL },
			nonce: 'test-nonce',
			countries: o.countries || [ 'RU' ],
			mode: 'typeahead',
			levels: o.levels || { region: true, settlement: true, address: true },
			current: o.current !== undefined ? o.current : null,
			implicit: false,
		},
	};
}

/**
 * Installs the fake `window.WoodevLocationTypeahead` — same contract shape as the real
 * Task 10 widget (`attachTypeahead(input, {fetch, onSelect}) → {detach}`), but records every
 * call instead of doing any real DOM/debounce work.
 *
 * @returns {void}
 */
function fakeTypeahead() {
	attachCalls = [];
	window.WoodevLocationTypeahead = jest.fn( ( el, opts ) => {
		const detach = jest.fn();
		const call = { el, fetch: opts.fetch, onSelect: opts.onSelect, detach };

		attachCalls.push( call );

		return { detach };
	} );
}

/**
 * Stubs `global.fetch` — each captured call exposes `resolve()`/`reject()` so a test decides
 * WHEN the server answers (mirrors `pickup-datasource.test.js`'s own convention).
 *
 * @returns {void}
 */
function mockFetch() {
	fetchCalls = [];
	global.fetch = jest.fn( ( url, init ) => {
		const entry = { url, init };

		entry.promise = new Promise( ( resolve, reject ) => {
			entry.resolve = ( body, ok = true ) => resolve( { ok, json: () => Promise.resolve( body ) } );
			entry.reject = reject;
		} );

		fetchCalls.push( entry );

		return entry.promise;
	} );
}

/**
 * Simulates a customer picking suggestion `item` through the attach `call` captured from the
 * fake widget — replicates location-typeahead.js's OWN `selectItem()` ordering exactly: write
 * the label into the field, dispatch input+change (native by default, real jQuery when
 * `viaJquery` is set), THEN invoke `onSelect(item)` — so the cascade's own change-gate sees a
 * DOM already consistent with the pick, exactly as the real widget guarantees.
 *
 * @param {Object}  call
 * @param {Object}  item
 * @param {boolean} [viaJquery]
 * @returns {void}
 */
function selectViaFake( call, item, viaJquery = false ) {
	call.el.value = item.label;

	if ( viaJquery ) {
		window.jQuery( call.el ).trigger( 'change' );
	} else {
		call.el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	call.onSelect( item );
}

function callFor( fieldId ) {
	return attachCalls.find( ( c ) => c.el.id === fieldId );
}

/**
 * Loads the module fresh: markup, real jQuery, the §8 store factory, the location-typeahead
 * fake, the fetch stub, then the config global, then requires the module under test (which
 * boots synchronously — jsdom's `document.readyState` is `'complete'` at require time, so
 * there is no ready-handler/timer dance to flush, unlike `checkout-field-classic.js`).
 *
 * @param {Object} configOpts
 * @returns {void}
 */
function boot( configOpts ) {
	installMarkup( configOpts, configOpts && configOpts.country );

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;

	window.WoodevCheckoutFieldStore = require(
		'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
	);

	fakeTypeahead();
	mockFetch();

	window[ CONFIG_GLOBAL ] = buildConfig( configOpts );

	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

beforeEach( () => {
	jest.resetModules();
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevCheckoutFieldStore;
	delete window.WoodevLocationTypeahead;
	delete window.jQuery;
	delete global.jQuery;
	delete global.$;
	delete global.fetch;
	delete window.wc;
	document.body.innerHTML = '';
} );

// -----------------------------------------------------------------------
// Chain built from present fields only
// -----------------------------------------------------------------------

describe( 'chain assembly from present fields only', () => {
	it( 'attaches region+settlement+address when all three fields are present', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( attachCalls.map( ( c ) => c.el.id ).sort() ).toEqual(
			[ 'billing_address_1', 'billing_city', 'billing_state' ].sort()
		);
	} );

	it( 'attaches settlement+address only when there is no region field', () => {
		boot( { settlement: true, address: true } );

		expect( attachCalls.map( ( c ) => c.el.id ).sort() ).toEqual(
			[ 'billing_address_1', 'billing_city' ].sort()
		);
	} );

	it( 'attaches settlement only when it is the sole location field', () => {
		boot( { settlement: true } );

		expect( attachCalls.map( ( c ) => c.el.id ) ).toEqual( [ 'billing_city' ] );
	} );
} );

// -----------------------------------------------------------------------
// Scoping
// -----------------------------------------------------------------------

describe( 'suggestion scoping', () => {
	it( 'scopes settlement suggestions by the selected region record key', () => {
		boot( { region: true, settlement: true, address: true } );

		const regionCall = callFor( 'billing_state' );
		const regionItem = {
			key: 'dadata:region1', label: 'г Москва', level: 'region',
			record: { key: 'dadata:region1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Москва', type: 'г' }, label: 'г Москва' },
		};

		selectViaFake( regionCall, regionItem );

		const settlementCall = callFor( 'billing_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( SUGGEST_URL );
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:region1' ) );
		expect( req.url ).toContain( 'level=settlement' );
	} );

	it( 'scopes settlement suggestions by country when the region field is present but empty', () => {
		boot( { region: true, settlement: true, address: true } );

		const settlementCall = callFor( 'billing_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).not.toContain( 'within=' );
	} );

	it( 'scopes settlement suggestions by country when there is no region field at all', () => {
		boot( { settlement: true, address: true } );

		const settlementCall = callFor( 'billing_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).not.toContain( 'within=' );
		expect( req.url ).toContain( 'level=settlement' );
	} );

	it( 'scopes address suggestions by the selected settlement record key', () => {
		boot( { region: true, settlement: true, address: true } );

		const settlementCall = callFor( 'billing_city' );
		const settlementItem = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Москва', type: 'г' }, settlement: { name: 'Москва', type: 'г' },
				postcode: '101000', label: 'г Москва',
			},
		};

		selectViaFake( settlementCall, settlementItem );

		const addressCall = callFor( 'billing_address_1' );
		addressCall.fetch( 'Твер' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:city1' ) );
		expect( req.url ).toContain( 'level=address' );
	} );
} );

// -----------------------------------------------------------------------
// D8 — persist THEN trigger, in that order
// -----------------------------------------------------------------------

describe( 'persist then trigger (D8)', () => {
	it( 'POSTs the full record to /select and triggers update_checkout only AFTER it resolves, in that order', async () => {
		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );

		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		expect( selectReq.url ).toBe( SELECT_URL );
		expect( selectReq.init.method ).toBe( 'POST' );
		// The FULL record, round-tripped untouched.
		expect( JSON.parse( selectReq.init.body ) ).toEqual( { record: item.record } );

		// Not yet — the persist call has not resolved.
		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( false );

		selectReq.resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( true );

		triggerSpy.mockRestore();
	} );

	it( 'does NOT trigger update_checkout when /select fails', async () => {
		// A network failure is logged (`logError()`), so expect it explicitly rather than
		// let @wordpress/jest-console flag an "unexpected" console.error.
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.reject( new Error( 'network down' ) );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( false );
		// The customer's visible choice survives — selectViaFake already wrote it to the DOM.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'г Москва' );

		triggerSpy.mockRestore();
		consoleSpy.mockRestore();
	} );

	it( 'does NOT trigger update_checkout when /select resolves with persisted: false (guest without a session)', async () => {
		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( false );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'г Москва' );

		triggerSpy.mockRestore();
	} );
} );

// -----------------------------------------------------------------------
// Single-flight /select queue (Finding 2, PR-C review): a second selection made before the
// first POST /select resolves must not race it — the server persists exactly ONE customer
// record slot (Location_Controller::handle_select_request() → set_customer_record()), so
// concurrent POSTs for the SAME entry can arrive out of order and let an OLDER pick win.
// The guaranteed property: once selections stop arriving, the persisted server record equals
// the customer's MOST RECENT selection, and update_checkout fires exactly once, for that
// final selection only.
// -----------------------------------------------------------------------

describe( 'single-flight /select queue (Finding 2)', () => {
	function selectRequests() {
		return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
	}

	function itemFor( key, label ) {
		return {
			key, label, level: 'settlement',
			record: { key, provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: label, type: 'г' }, label },
		};
	}

	it( 'a second selection made before the first /select resolves does NOT send a second concurrent request — it waits', () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );
		const b = itemFor( 'dadata:b', 'Балашиха' );

		selectViaFake( settlementCall, a );
		expect( selectRequests().length ).toBe( 1 );

		// A second, later selection arrives while A's /select is still in flight.
		selectViaFake( settlementCall, b );
		expect( selectRequests().length ).toBe( 1 ); // still just A's request — B waits, not fired concurrently
	} );

	it( 'once the in-flight request settles, the QUEUED (most recent) selection is sent next, and update_checkout fires only for it', async () => {
		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );
		const b = itemFor( 'dadata:b', 'Балашиха' );

		selectViaFake( settlementCall, a );
		selectViaFake( settlementCall, b );
		expect( selectRequests().length ).toBe( 1 );

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		// A's resolution must NOT have triggered update_checkout — B was already queued when
		// it settled, so A's response is stale by construction.
		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( false );

		// B is now the second request, sent automatically once A freed the single flight slot.
		expect( selectRequests().length ).toBe( 2 );
		expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: b.record } );

		selectRequests()[ 1 ].resolve( { current: { key: b.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		// Exactly ONE trigger overall, firing for the FINAL (B) selection.
		expect( triggerSpy.mock.calls.filter( ( args ) => args[ 0 ] === 'update_checkout' ).length ).toBe( 1 );

		triggerSpy.mockRestore();
	} );

	it( 'three rapid selections while the first is in flight: the superseded MIDDLE one is never sent at all', async () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );
		const b = itemFor( 'dadata:b', 'Балашиха' );
		const c = itemFor( 'dadata:c', 'Верея' );

		selectViaFake( settlementCall, a ); // sent immediately — the queue starts empty
		selectViaFake( settlementCall, b ); // queued
		selectViaFake( settlementCall, c ); // supersedes B in the queue — B is NEVER sent

		expect( selectRequests().length ).toBe( 1 );

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		expect( selectRequests().length ).toBe( 2 );
		expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: c.record } );

		// B's record body must never have been sent in ANY request.
		selectRequests().forEach( ( req ) => {
			expect( JSON.parse( req.init.body ) ).not.toEqual( { record: b.record } );
		} );
	} );

	it( 'a FAILED /select does not jam the queue — the next queued selection is still sent', async () => {
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );
		const b = itemFor( 'dadata:b', 'Балашиха' );

		selectViaFake( settlementCall, a );
		selectViaFake( settlementCall, b );

		selectRequests()[ 0 ].reject( new Error( 'network down' ) );
		await flushMicrotasks();

		expect( selectRequests().length ).toBe( 2 ); // B was dequeued and sent despite A's failure
		expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: b.record } );

		consoleSpy.mockRestore();
	} );

	it( 'a /select resolving persisted: false does not jam the queue either', async () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );
		const b = itemFor( 'dadata:b', 'Балашиха' );

		selectViaFake( settlementCall, a );
		selectViaFake( settlementCall, b );

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		expect( selectRequests().length ).toBe( 2 );
		expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: b.record } );
	} );

	it( 'a solitary selection (no concurrency) behaves exactly as before — sent immediately, triggers once', async () => {
		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const settlementCall = callFor( 'billing_city' );
		const a = itemFor( 'dadata:a', 'Александров' );

		selectViaFake( settlementCall, a );
		expect( selectRequests().length ).toBe( 1 );

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.filter( ( args ) => args[ 0 ] === 'update_checkout' ).length ).toBe( 1 );

		triggerSpy.mockRestore();
	} );
} );

// -----------------------------------------------------------------------
// Dependent clearing DOWNWARD only, through the remembered-parent gate
// -----------------------------------------------------------------------

describe( 'dependent clearing (downward only, remembered-parent gate)', () => {
	function bootFilled() {
		boot( { region: true, settlement: true, address: true } );

		document.getElementById( 'billing_state' ).value = '';
		document.getElementById( 'billing_city' ).value = 'Москва';
		document.getElementById( 'billing_address_1' ).value = 'Тверская 1';
		document.getElementById( 'billing_postcode' ).value = '101000';
	}

	it( 'clears settlement+address+postcode when region genuinely changes', () => {
		bootFilled();

		document.getElementById( 'billing_state' ).value = 'г Санкт-Петербург';
		document.getElementById( 'billing_state' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );

	it( 'clears only postcode when address genuinely changes', () => {
		bootFilled();

		document.getElementById( 'billing_address_1' ).value = 'Новый Арбат 5';
		document.getElementById( 'billing_address_1' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' ); // untouched: not a descendant
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );

	it( 'clears nothing when postcode itself is edited', () => {
		bootFilled();

		document.getElementById( 'billing_postcode' ).value = '199000';
		document.getElementById( 'billing_postcode' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'Тверская 1' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '199000' );
	} );

	it( 'a REAL jQuery .trigger("change") alone (no native event) still drives the cascade', () => {
		// Regression pin for gotcha `jquery-trigger-change-fires-no-native-event`: select2/
		// selectWoo report a pick with EXACTLY this call, dispatching no native event at all —
		// a native-only delegated listener would never see it.
		bootFilled();

		window.jQuery( '#billing_state' ).val( 'г Казань' ).trigger( 'change' );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );

	it( 'a programmatic re-assignment of the SAME parent value (seen by BOTH bound worlds) does NOT clear children — double delivery is harmless', () => {
		// Mirrors gotcha `a-programmatic-parent-change-must-not-run-a-destructive-cascade`:
		// WooCommerce fires a programmatic `change` carrying the value the field ALREADY has.
		// Because both a native `addEventListener('change')` AND a jQuery `.on('change')` are
		// bound, a single native dispatch reaches the handler TWICE when jQuery is loaded — the
		// remembered-value gate must absorb the SAME transition (or its absence) each time.
		bootFilled();
		document.getElementById( 'billing_state' ).value = 'г Москва'; // prefilled AFTER boot, so 'resolved' seeded to '' — set then re-seed via a first real change
		document.getElementById( 'billing_state' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
		// The change above genuinely occurred once (prefill was ''), so children ARE cleared —
		// refill them to prove the NEXT, same-value dispatch does not clear them again.
		document.getElementById( 'billing_city' ).value = 'Москва';
		document.getElementById( 'billing_address_1' ).value = 'Тверская 1';
		document.getElementById( 'billing_postcode' ).value = '101000';

		// Now a genuinely NO-OP programmatic churn: SAME value as already resolved.
		document.getElementById( 'billing_state' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'Тверская 1' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );
	} );
} );

// -----------------------------------------------------------------------
// Backwards fill — no second lookup
// -----------------------------------------------------------------------

describe( 'backwards fill', () => {
	it( 'fills region/settlement/postcode from an address-level record with NO second fetch', () => {
		boot( { region: true, settlement: true, address: true } );

		const fetchCallCountBefore = global.fetch.mock.calls.length;

		const addressCall = callFor( 'billing_address_1' );
		const item = {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				region: { name: 'Москва', type: 'г' },
				settlement: { name: 'Москва', type: 'г' },
				postcode: '101000',
				label: 'ул Тверская, 1',
			},
		};

		selectViaFake( addressCall, item );

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'г Москва' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'г Москва' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );

		// Exactly one new fetch: the /select persist call — no extra GET suggest lookups.
		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore + 1 );
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toBe( SELECT_URL );
	} );
} );

// -----------------------------------------------------------------------
// Country switch
// -----------------------------------------------------------------------

describe( 'country switch', () => {
	it( 'detaches every attached widget on switch to an unsupported country, leaves fields native, keeps store state', () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';

		const detachSpies = attachCalls.map( ( c ) => c.detach );

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		detachSpies.forEach( ( spy ) => expect( spy ).toHaveBeenCalled() );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' ); // state kept
	} );

	it( 're-attaches with state intact when switching back to a supported country', () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const attachCountAfterDetach = window.WoodevLocationTypeahead.mock.calls.length;

		document.getElementById( 'billing_country' ).value = 'RU';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( window.WoodevLocationTypeahead.mock.calls.length ).toBeGreaterThan( attachCountAfterDetach );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	} );
} );

// -----------------------------------------------------------------------
// Per-section country resolution (Finding 1, PR-C review): a field declared in the
// `shipping` §8 section (`field.section === 'shipping'`, the SAME convention
// `class-checkout-fields.php::normalize()` / `Field::set_section()` already establish and
// `checkout-field-classic.js`/`class-checkout-handler.php::inject()` already consume) must be
// scoped by `#shipping_country`, never `#billing_country` — and must additionally respect the
// live "ship to a different address" checkbox, since a shipping-section field is only actually
// in play once the customer has opted into a separate shipping address.
// -----------------------------------------------------------------------

describe( 'per-section country resolution (Finding 1)', () => {
	it( '/suggest for a shipping-section field is scoped by the SHIPPING country, not billing', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ],
			country: 'US', shippingCountry: 'RU', // billing and shipping deliberately DIFFER
		} );

		const settlementCall = callFor( 'shipping_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'country=RU' );
		expect( req.url ).not.toContain( 'country=US' );
	} );

	it( 'a shipping-section widget attaches when the SHIPPING country is supported, even though billing is not', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ],
			country: 'US', shippingCountry: 'RU',
		} );

		expect( attachCalls.map( ( c ) => c.el.id ) ).toEqual( [ 'shipping_city' ] );
	} );

	it( 'a shipping-section widget does NOT attach when the shipping country is unsupported, even though billing is', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ],
			country: 'RU', shippingCountry: 'US',
		} );

		expect( attachCalls.length ).toBe( 0 );
	} );

	it( 'changing #shipping_country (not #billing_country) drives arbitration for a shipping-section entry', () => {
		boot( { settlement: true, section: 'shipping', countries: [ 'RU' ], shippingCountry: 'RU' } );

		const detachSpy = callFor( 'shipping_city' ).detach;

		// Billing country changes — must NOT affect a shipping-section entry at all.
		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( detachSpy ).not.toHaveBeenCalled();

		// Shipping country changes to an unsupported one — THIS must detach the widget.
		document.getElementById( 'shipping_country' ).value = 'US';
		document.getElementById( 'shipping_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( detachSpy ).toHaveBeenCalled();
	} );

	it( 'a billing-section entry is unaffected by #shipping_country changes (the existing default keeps working)', () => {
		boot( { settlement: true, withShippingCountry: true, countries: [ 'RU' ] } ); // section defaults to 'billing'

		const detachSpy = callFor( 'billing_city' ).detach;

		document.getElementById( 'shipping_country' ).value = 'US';
		document.getElementById( 'shipping_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( detachSpy ).not.toHaveBeenCalled();
	} );

	it( 'unchecking "ship to a different address" detaches a shipping-section widget even though its own country stays supported', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ], shippingCountry: 'RU',
			shipToDifferentAddress: true,
		} );

		const detachSpy = callFor( 'shipping_city' ).detach;
		expect( detachSpy ).not.toHaveBeenCalled();

		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = false;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( detachSpy ).toHaveBeenCalled();
	} );

	it( 're-checking "ship to a different address" re-attaches the shipping-section widget with state intact', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ], shippingCountry: 'RU',
			shipToDifferentAddress: true,
		} );

		document.getElementById( 'shipping_city' ).value = 'Москва';

		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = false;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const attachCountAfterUncheck = window.WoodevLocationTypeahead.mock.calls.length;

		checkbox.checked = true;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( window.WoodevLocationTypeahead.mock.calls.length ).toBeGreaterThan( attachCountAfterUncheck );
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Москва' ); // state kept, never cleared
	} );

	it( 'a shipping-section widget never attaches at boot when "ship to a different address" starts unchecked', () => {
		boot( {
			settlement: true, section: 'shipping', countries: [ 'RU' ], shippingCountry: 'RU',
			shipToDifferentAddress: false,
		} );

		expect( attachCalls.length ).toBe( 0 );
	} );
} );

// -----------------------------------------------------------------------
// D15 — unsupported level
// -----------------------------------------------------------------------

describe( 'D15 — a level no configured provider serves stays native', () => {
	it( 'never attaches a widget to the unsupported-level field, but it still participates in clearing', () => {
		boot( {
			region: true, settlement: true, address: true,
			levels: { region: true, settlement: true, address: false },
		} );

		expect( attachCalls.map( ( c ) => c.el.id ).sort() ).toEqual( [ 'billing_city', 'billing_state' ].sort() );

		document.getElementById( 'billing_city' ).value = 'Москва';
		document.getElementById( 'billing_address_1' ).value = 'Тверская 1';
		document.getElementById( 'billing_postcode' ).value = '101000';

		// Parent (settlement) still clears the unsupported (address) field as a plain input.
		document.getElementById( 'billing_city' ).value = 'Казань';
		document.getElementById( 'billing_city' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );

		// The unsupported field's OWN edits still clear ITS descendants (postcode).
		document.getElementById( 'billing_address_1' ).value = 'Новая улица 9';
		document.getElementById( 'billing_postcode' ).value = '199000';
		document.getElementById( 'billing_address_1' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );
} );

// -----------------------------------------------------------------------
// config.location.current present on load — restore without a re-fetch
// -----------------------------------------------------------------------

describe( 'restoring config.location.current on load', () => {
	it( 'scopes a child fetch by the restored key without any prior selection', () => {
		boot( {
			region: true, settlement: true,
			current: { key: 'dadata:region9', level: 'region' },
		} );

		const settlementCall = callFor( 'billing_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:region9' ) );
	} );
} );

// -----------------------------------------------------------------------
// Checkout re-render (`updated_checkout` replaces a field node)
// -----------------------------------------------------------------------

describe( 'checkout re-render (updated_checkout)', () => {
	it( 're-attaches to a REPLACED field node and restores its value from the store as a safety net', () => {
		boot( { settlement: true } );

		document.getElementById( 'billing_city' ).value = 'Москва';
		document.getElementById( 'billing_city' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const oldCall = callFor( 'billing_city' );
		const attachCountBefore = window.WoodevLocationTypeahead.mock.calls.length;

		// WooCommerce replaces the fragment: a FRESH, empty node with the same id.
		const fresh = document.createElement( 'input' );
		fresh.type = 'text';
		fresh.id = 'billing_city';
		fresh.name = 'billing_city';
		document.getElementById( 'billing_city' ).replaceWith( fresh );

		window.jQuery( document.body ).trigger( 'updated_checkout' );

		expect( oldCall.detach ).toHaveBeenCalled();
		expect( window.WoodevLocationTypeahead.mock.calls.length ).toBeGreaterThan( attachCountBefore );
		expect( document.getElementById( 'billing_city' ) ).toBe( fresh );
		// Safety net: the store still held 'Москва', the fresh node rendered empty.
		expect( fresh.value ).toBe( 'Москва' );
	} );
} );

// -----------------------------------------------------------------------
// Store sharing with checkout-field-classic.js (via getStoreForField)
// -----------------------------------------------------------------------

describe( 'store sharing', () => {
	it( 'reuses an existing §8 store instance for the same config rather than creating a second one', () => {
		installMarkup( { settlement: true } );

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		const config = buildConfig( { settlement: true } );
		window[ CONFIG_GLOBAL ] = config;

		// Simulate checkout-field-classic.js having already created the store for this SAME
		// config object, exactly as it does synchronously at its own top-level scan.
		const existingStore = window.WoodevCheckoutFieldStore.createStore( config );
		existingStore.setValue( 'billing_city', 'Preexisting' );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		// The cascade's own prefill() must have read/written through the SAME store instance
		// getStoreForField() resolves — not a second, diverging one.
		expect( window.WoodevCheckoutFieldStore.getStoreForField( 'billing_city' ) ).toBe( existingStore );
	} );
} );

// -----------------------------------------------------------------------
// WC Address Autocomplete suppression — client half (Task 12, spec D2)
// -----------------------------------------------------------------------

/**
 * Installs a fake `window.wc.addressAutocomplete` registry mirroring the real shape measured
 * from WooCommerce's own `address-autocomplete-common.js` (gotcha
 * `wc-address-autocomplete-hosts-only-address1-and-flattens-identity`): `providers` (an object
 * keyed by provider id, each entry FROZEN via `Object.freeze()` at registration time),
 * `serverProviders` (the server-preference-ordered list `[{ id, name, branding_html }, ...]`
 * WC's own arbitration loop walks), and `activeProvider` (per address-type, unrelated to this
 * module). `canSearch` is stubbed to always return `true` so a suppressed vs. delegated result
 * is unambiguous in assertions.
 *
 * @param {string[]} ids
 * @returns {Object.<string, Object>} the ORIGINAL (frozen) provider objects, keyed by id — for
 *          asserting they were never mutated.
 */
function installWcAddressAutocomplete( ids ) {
	const providers = {};

	ids.forEach( ( id ) => {
		providers[ id ] = Object.freeze( {
			id,
			name: id,
			canSearch: jest.fn( () => true ),
			search: jest.fn(),
			select: jest.fn(),
		} );
	} );

	window.wc = {
		addressAutocomplete: {
			providers,
			serverProviders: ids.map( ( id ) => ( { id, name: id } ) ),
			activeProvider: { billing: null, shipping: null },
		},
	};

	return providers;
}

describe( 'WC Address Autocomplete suppression (Task 12, spec D2)', () => {
	it( 'is a no-op when window.wc.addressAutocomplete is absent (feature off / older WC)', () => {
		expect( () => boot( { settlement: true, countries: [ 'RU' ] } ) ).not.toThrow();
		expect( window.wc ).toBeUndefined();
	} );

	it( 'replaces the registry ENTRY with a delegating clone: our country returns false, another delegates', () => {
		const providers = installWcAddressAutocomplete( [ 'google' ] );
		// Snapshot the ORIGINAL provider object BEFORE boot() — `providers` is the very
		// container `window.wc.addressAutocomplete.providers` also points at, so reading
		// `providers.google` AFTER boot() would read whatever is CURRENTLY in that slot
		// (the clone, once wrapped), not the pre-wrap object.
		const originalGoogle = providers.google;

		boot( { settlement: true, countries: [ 'RU' ] } );

		const wrapped = window.wc.addressAutocomplete.providers.google;

		expect( wrapped ).not.toBe( originalGoogle ); // the registry SLOT was replaced
		expect( wrapped.canSearch( 'RU' ) ).toBe( false ); // our own country — suppressed
		expect( wrapped.canSearch( 'US' ) ).toBe( true ); // not ours — delegates to the original
		expect( originalGoogle.canSearch ).toHaveBeenCalledWith( 'US' );
	} );

	it( 'never mutates the original frozen provider object', () => {
		const providers = installWcAddressAutocomplete( [ 'google' ] );
		const originalGoogle = providers.google; // snapshot BEFORE the registry slot is replaced

		boot( { settlement: true, countries: [ 'RU' ] } );

		expect( Object.isFrozen( originalGoogle ) ).toBe( true );
		expect( originalGoogle.canSearch( 'RU' ) ).toBe( true ); // unaffected by our wrap
	} );

	it( 'wraps EVERY registered provider, not just one', () => {
		installWcAddressAutocomplete( [ 'google', 'algolia' ] );

		boot( { settlement: true, countries: [ 'RU' ] } );

		expect( window.wc.addressAutocomplete.providers.google.canSearch( 'RU' ) ).toBe( false );
		expect( window.wc.addressAutocomplete.providers.algolia.canSearch( 'RU' ) ).toBe( false );
	} );

	it( 'suppresses every one of our own countries when the config carries more than one', () => {
		installWcAddressAutocomplete( [ 'google' ] );

		boot( { settlement: true, countries: [ 'RU', 'KZ' ] } );

		const wrapped = window.wc.addressAutocomplete.providers.google;

		expect( wrapped.canSearch( 'RU' ) ).toBe( false );
		expect( wrapped.canSearch( 'KZ' ) ).toBe( false );
		expect( wrapped.canSearch( 'US' ) ).toBe( true );
	} );

	it( 'leaves the registry completely untouched when our own config carries no countries', () => {
		const providers = installWcAddressAutocomplete( [ 'google' ] );
		const originalGoogle = providers.google;

		boot( { settlement: true, countries: [] } );

		expect( window.wc.addressAutocomplete.providers.google ).toBe( originalGoogle );
	} );

	it( 'still applies the wrap on the next country change when WC installs its namespace AFTER boot (script-order tolerance)', () => {
		// WC's arbitration re-reads `providers[id]` live on every country change (the same
		// property that makes replacing the registry slot timing-safe in the first place) — this
		// module leans on the SAME property defensively: our own wrap is retried on country
		// change too, so a page where WC's deferred script happens to execute after ours still
		// gets suppressed the first time the customer actually touches the country field.
		boot( { region: true, settlement: true, countries: [ 'RU' ] } );

		expect( window.wc ).toBeUndefined();

		const providers = installWcAddressAutocomplete( [ 'google' ] );
		const originalGoogle = providers.google; // snapshot BEFORE the country-change wraps it

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const wrapped = window.wc.addressAutocomplete.providers.google;

		expect( wrapped ).not.toBe( originalGoogle );
		expect( wrapped.canSearch( 'RU' ) ).toBe( false );
	} );
} );
