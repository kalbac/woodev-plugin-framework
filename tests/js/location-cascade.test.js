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
 * Builds the classic-checkout markup for whichever location fields + postcode the test
 * needs. `billing_country`/`billing_postcode` are always present (native WC fields, never
 * declared in `config.fields`).
 *
 * @param {{region?: boolean, settlement?: boolean, address?: boolean}} which
 * @param {string} country
 * @returns {void}
 */
function installMarkup( which, country = 'RU' ) {
	const w = which || {};
	let inputs = '';

	if ( w.region ) {
		inputs += '<input type="text" id="billing_state" name="billing_state" value="" />';
	}
	if ( w.settlement ) {
		inputs += '<input type="text" id="billing_city" name="billing_city" value="" />';
	}
	if ( w.address ) {
		inputs += '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';
	}

	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<select id="billing_country" name="billing_country">
				<option value="RU">Россия</option>
				<option value="US">США</option>
			</select>
			${ inputs }
			<input type="text" id="billing_postcode" name="billing_postcode" value="" />
		</form>
	`;

	document.getElementById( 'billing_country' ).value = country;
}

/**
 * Builds one location-kind field descriptor, matching `Checkout_Config::build()`'s emitted
 * shape (`class-checkout-config.php`).
 *
 * @param {string} level
 * @returns {Object}
 */
function locationField( level ) {
	return {
		id: null,
		type: 'text',
		section: 'billing',
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
 * @param {{region?: boolean, settlement?: boolean, address?: boolean, levels?: Object, countries?: string[], current?: Object|null}} opts
 * @returns {Object}
 */
function buildConfig( opts ) {
	const o = opts || {};
	const fields = {};

	if ( o.region ) {
		fields.billing_state = locationField( 'region' );
	}
	if ( o.settlement ) {
		fields.billing_city = locationField( 'settlement' );
	}
	if ( o.address ) {
		fields.billing_address_1 = locationField( 'address' );
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
