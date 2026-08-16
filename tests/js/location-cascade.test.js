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
const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';

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
 * needs. `billing_country` (and its postcode counterpart) is present by default (a native WC
 * field, never declared in `config.fields`) — `which.omitBillingCountry` drops it entirely
 * (issue #296: a single-country store commonly removes the field from checkout). `#shipping_country`
 * and the WC `ship_to_different_address` checkbox are rendered whenever `which.section ===
 * 'shipping'` (or `which.withShippingCountry` is set for a test that wants both present) —
 * mirrors WC's OWN classic-checkout template, which always renders both country selects and
 * the toggle checkbox together; `which.omitShippingCountry` drops the shipping one the same way.
 *
 * @param {{region?: boolean, settlement?: boolean, address?: boolean, section?: string,
 *          shippingCountry?: string, shipToDifferentAddress?: boolean,
 *          withShippingCountry?: boolean, omitBillingCountry?: boolean,
 *          omitShippingCountry?: boolean}} which
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
	const needsShippingCountry = ( 'shipping' === section || w.withShippingCountry ) && ! w.omitShippingCountry;
	const checked = false !== w.shipToDifferentAddress; // defaults to checked (true)

	const shippingMarkup = needsShippingCountry ? `
		<select id="shipping_country" name="shipping_country">
			<option value="RU">Россия</option>
			<option value="US">США</option>
		</select>
		<input type="checkbox" id="ship-to-different-address-checkbox" name="ship_to_different_address" ${ checked ? 'checked' : '' } />
	` : '';

	const billingCountryMarkup = w.omitBillingCountry ? '' : `
		<select id="billing_country" name="billing_country">
			<option value="RU">Россия</option>
			<option value="US">США</option>
		</select>
	`;

	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			${ billingCountryMarkup }
			${ shippingMarkup }
			${ inputs }
			<input type="text" id="${ postcodeId }" name="${ postcodeId }" value="" />
		</form>
	`;

	const billingCountryEl = document.getElementById( 'billing_country' );
	if ( billingCountryEl ) {
		billingCountryEl.value = country;
	}

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
 *          levels?: Object, countries?: string[], current?: Object|null, chain?: Object,
 *          implicit?: boolean, defaultCountry?: string}} opts
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
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
			nonce: 'test-nonce',
			countries: o.countries || [ 'RU' ],
			// Task 13 (spec D7): 'typeahead' | 'related-list' | 'ajax-select2'.
			mode: o.mode || 'typeahead',
			// Keyed BY COUNTRY, mirroring Checkout_Config::build_location_block(): DaData's
			// coverage is per country (street data for RU/BY/KZ/UZ, city-only elsewhere), so
			// a flat per-level map cannot describe it without lying.
			levels: o.levels || { RU: { region: true, settlement: true, address: true } },
			current: o.current !== undefined ? o.current : null,
			// Issue #330 (spec §7): `{ level: { key, level } }`, alongside `current` — omitted
			// entirely (not merely `undefined`) unless a test opts in, so "no `chain` key at
			// all" (an older server) is exercised as its own real case, not a stand-in for it.
			...( o.chain !== undefined ? { chain: o.chain } : {} ),
			implicit: o.implicit !== undefined ? o.implicit : false,
			// Issue #296: steps 2+3 of the checkout-field -> WC-store-setting -> RU chain,
			// already merged into ONE value server-side by Location_Service::resolve_default_country().
			defaultCountry: o.defaultCountry !== undefined ? o.defaultCountry : 'RU',
			i18n: o.i18n !== undefined ? o.i18n : {
				noResults: 'Поиск не дал результатов. Попробуйте изменить запрос.',
				noResultsAddress: 'Адрес не найден — введите вручную.',
				notPersisted: 'Не удалось сохранить выбор — попробуйте ещё раз.',
			},
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
		const call = { el, fetch: opts.fetch, onSelect: opts.onSelect, emptyText: opts.emptyText, detach };

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
	// Mirrors location-typeahead.js's own `selectItem()`: the widget writes `item.value`
	// and falls back to `item.label` only when none was supplied. A harness that always
	// wrote the label would model a widget production no longer has.
	call.el.value = 'string' === typeof item.value ? item.value : item.label;

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
	// A FRESH <body>, not just emptied markup. `jest.resetModules()` gives the next test a new
	// module instance, but the PREVIOUS instance's delegated listeners are still bound to the
	// surviving `document.body` — `innerHTML = ''` removes children, never listeners. Those
	// zombie instances keep handling events with their own stale state (a stale remembered
	// country, say) and mutate the CURRENT test's DOM by id. Measured: nine cascade instances
	// answered one country change in a single file run.
	document.body.replaceWith( document.createElement( 'body' ) );
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevCheckoutFieldStore;
	delete window.WoodevLocationTypeahead;
	delete window.WoodevLocationRenderers;
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

		selectReq.resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: item.record.key, level: 'settlement' } } } );
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
// Task 15 (issue #159): `woodev_location_applied` — the event pickup-mount.js's own
// resolveLocalityKey() listens for, fired on the SAME "final and persisted" condition as
// update_checkout above.
// -----------------------------------------------------------------------

describe( 'woodev_location_applied (Task 15; issue #159)', () => {
	/**
	 * Captures every `woodev_location_applied` NATIVE event seen on document.body — a plain
	 * addEventListener is enough (this is our own event, never a jQuery one; see the file
	 * docblock's own note contrasting it with `update_checkout`).
	 *
	 * @returns {Array<Object>} each entry's `detail`.
	 */
	function captureLocationApplied() {
		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );
		return seen;
	}

	it( 'fires with the persisted record\'s key/level once the /select round-trip resolves', async () => {
		boot( { settlement: true } );

		const seen = captureLocationApplied();
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		expect( seen ).toEqual( [] ); // not yet — the persist call has not resolved.

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: item.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		// A persisted /select is ALWAYS an explicit choice (issue #309; spec D11) —
		// `implicit` is `false` regardless of the config's own boot-time value.
		// settlementKey (issue #336) matches key here: a direct settlement pick sets
		// entry.records.settlement to the SAME record.
		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: false } ] );
	} );

	it( 'is a NATIVE event, seen by a plain addEventListener with no jQuery involved', async () => {
		// The whole point of NOT using jQuery.trigger() here (unlike update_checkout, which
		// WooCommerce itself only ever fires through jQuery) — this module IS the producer,
		// so a native dispatchEvent() is enough and a listener needs no jQuery world at all.
		boot( { settlement: true } );

		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'cdek:city-77', label: 'г Москва', level: 'settlement',
			record: { key: 'cdek:city-77', provider_id: 'cdek', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: item.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'cdek:city-77', level: 'settlement', settlementKey: 'cdek:city-77', implicit: false } ] );
	} );

	it( 'does NOT fire when /select fails', async () => {
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		boot( { settlement: true } );

		const seen = captureLocationApplied();
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );
		fetchCalls[ fetchCalls.length - 1 ].reject( new Error( 'network down' ) );
		await flushMicrotasks();

		expect( seen ).toEqual( [] );
		consoleSpy.mockRestore();
	} );

	it( 'fires WITH AN EMPTY KEY (never the stale one) when /select resolves with persisted: false (review finding F2)', async () => {
		// Review finding F2, rig-verified: this branch used to fire NOTHING at all, leaving
		// pickup-mount.js's own resolveLocalityKey() cache pointing at whatever locality was
		// current BEFORE this failed attempt — even though the DOM already shows the
		// customer's new (unsaved) choice. An empty-key event is the honest "unknown" signal
		// (the SAME sentinel Pickup_Handler::location_config_block() uses server-side); F1's
		// own fix makes an empty key fall back to the DOM read on the picker side, which is
		// the right degradation here.
		boot( { settlement: true } );

		const seen = captureLocationApplied();
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		// settlementKey (issue #336) is EMPTY here too, and that is the whole point of this
		// branch: `onSelectFor()` wrote entry.records.settlement OPTIMISTICALLY, before the
		// persist outcome was known, and the server ended up holding nothing. Publishing that
		// optimistic key would hand pickup-mount.js a confident locality exactly where F1/F2
		// decided the honest answer is "unknown" — bypassing the DOM fallback those findings
		// added. The unknown sentinel applies to the WHOLE detail.
		expect( seen ).toEqual( [ { key: '', level: '', settlementKey: '', implicit: false } ] );
	} );

	it( 'fires only ONCE, for the FINAL response, when a superseded selection is queued behind it', async () => {
		boot( { settlement: true } );

		function selectRequests() {
			return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
		}

		const seen = captureLocationApplied();
		const settlementCall = callFor( 'billing_city' );
		const first = {
			key: 'dadata:first', label: 'г Тверь', level: 'settlement',
			record: { key: 'dadata:first', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Тверь' },
		};
		const second = {
			key: 'dadata:second', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:second', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, first );
		selectViaFake( settlementCall, second ); // queued — the first request is still in flight.
		expect( selectRequests().length ).toBe( 1 );

		selectRequests()[ 0 ].resolve( { current: { key: first.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: first.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		// The queued (second) selection is dispatched automatically once the first settles.
		expect( selectRequests().length ).toBe( 2 );
		selectRequests()[ 1 ].resolve( { current: { key: second.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: second.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'dadata:second', level: 'settlement', settlementKey: 'dadata:second', implicit: false } ] );
	} );
} );

// -----------------------------------------------------------------------
// #295 finding 1 — the client now CONSUMES `persisted: false`, not just skips the trigger
// -----------------------------------------------------------------------

describe( '#295 finding 1 — the "not saved" notice consumes persisted: false', () => {
	function notice() {
		return document.querySelector( '.woodev-location-notice' );
	}

	it( 'shows the server-supplied notPersisted string right after the field, anchored past it in the DOM', async () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		expect( notice() ).not.toBeNull();
		expect( notice().textContent ).toBe( 'Не удалось сохранить выбор — попробуйте ещё раз.' );
		expect( notice().getAttribute( 'role' ) ).toBe( 'alert' );
		expect( document.getElementById( 'billing_city' ).nextElementSibling ).toBe( notice() );
	} );

	it( 'does NOT show a notice for a network/transport failure — only an honest persisted: false is consumed', async () => {
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );

		fetchCalls[ fetchCalls.length - 1 ].reject( new Error( 'network down' ) );
		await flushMicrotasks();

		expect( notice() ).toBeNull();

		consoleSpy.mockRestore();
	} );

	it( 'clears a previously-shown notice once a LATER selection persists successfully', async () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const first = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, first );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: first.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		expect( notice() ).not.toBeNull();

		const second = {
			key: 'dadata:city2', label: 'г Казань', level: 'settlement',
			record: { key: 'dadata:city2', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Казань' },
		};

		selectViaFake( settlementCall, second );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: second.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: second.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		expect( notice() ).toBeNull();
	} );

	it( 'degrades to silence when the server config carries no notPersisted string (older config)', async () => {
		boot( { settlement: true, i18n: { noResults: 'x' } } );

		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( settlementCall, item );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: false } );
		await flushMicrotasks();

		expect( notice() ).toBeNull();
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

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: a.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		// A's resolution must NOT have triggered update_checkout — B was already queued when
		// it settled, so A's response is stale by construction.
		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( false );

		// B is now the second request, sent automatically once A freed the single flight slot.
		expect( selectRequests().length ).toBe( 2 );
		expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: b.record } );

		selectRequests()[ 1 ].resolve( { current: { key: b.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: b.record.key, level: 'settlement' } } } );
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

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: a.record.key, level: 'settlement' } } } );
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

		selectRequests()[ 0 ].resolve( { current: { key: a.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: a.record.key, level: 'settlement' } } } );
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
// Field value derivation — what a pick WRITES into the field, as opposed to
// what the list SHOWS (operator, s70 rig pass: the locality field must carry
// 'Жуковский', not DaData's own 'Московская обл., г Жуковский')
// -----------------------------------------------------------------------

describe( 'field value derivation', () => {
	/**
	 * Resolves the pending suggest request with `suggestions` and returns what the
	 * cascade's own `fetch()` callback hands the widget.
	 *
	 * @param {Promise} promise
	 * @param {Array}   suggestions
	 * @returns {Promise<Array>}
	 */
	async function answerSuggest( promise, suggestions ) {
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions } );

		return promise;
	}

	it( 'narrows a settlement suggestion to the bare locality name, leaving the list label intact', async () => {
		boot( { region: true, settlement: true, address: true } );

		const suggestions = await answerSuggest(
			callFor( 'billing_city' ).fetch( 'Жуко' ),
			[ {
				key: 'dadata:zh', label: 'Московская обл., г Жуковский', level: 'settlement',
				record: {
					key: 'dadata:zh', provider_id: 'dadata', level: 'settlement', country: 'RU',
					region: { name: 'Московская', type: 'обл' },
					settlement: { name: 'Жуковский', type: 'г' },
					label: 'Московская обл., г Жуковский',
				},
			} ]
		);

		// The value written into the field: the locality and nothing else — no region prefix
		// (it belongs to the region field) and no type prefix (carriers reject it).
		expect( suggestions[ 0 ].value ).toBe( 'Жуковский' );
		// The label the LIST renders is untouched — it is what tells two Жуковских apart.
		expect( suggestions[ 0 ].label ).toBe( 'Московская обл., г Жуковский' );
	} );

	it( 'composes an address value from street + house, dropping every ancestor the label carries', async () => {
		boot( { region: true, settlement: true, address: true } );

		const suggestions = await answerSuggest(
			callFor( 'billing_address_1' ).fetch( 'Тверская' ),
			[ {
				key: 'dadata:addr', label: 'г Москва, ул Тверская, д 1', level: 'address',
				record: {
					key: 'dadata:addr', provider_id: 'dadata', level: 'address', country: 'RU',
					region: { name: 'Москва', type: 'г' },
					settlement: { name: 'Москва', type: 'г' },
					street: { name: 'Тверская', type: 'ул' },
					house: '1',
					label: 'г Москва, ул Тверская, д 1',
				},
			} ]
		);

		// Street KEEPS its type ("Тверская" alone is not an address); the city does not repeat.
		expect( suggestions[ 0 ].value ).toBe( 'ул Тверская, 1' );
	} );

	it( 'falls back to the label when the provider returned no component at the asked-for level', async () => {
		boot( { region: true, settlement: true, address: true } );

		const suggestions = await answerSuggest(
			callFor( 'billing_city' ).fetch( 'Мос' ),
			[ {
				key: 'dadata:odd', label: 'Некое место', level: 'settlement',
				record: {
					key: 'dadata:odd', provider_id: 'dadata', level: 'settlement', country: 'RU',
					label: 'Некое место',
				},
			} ]
		);

		// Blanking the field right after the customer picked in it would read as a failed pick.
		expect( suggestions[ 0 ].value ).toBe( 'Некое место' );
	} );

	it( 'leaves the record itself untouched — it round-trips to /select verbatim', async () => {
		boot( { region: true, settlement: true, address: true } );

		const record = {
			key: 'dadata:zh', provider_id: 'dadata', level: 'settlement', country: 'RU',
			settlement: { name: 'Жуковский', type: 'г' },
			label: 'Московская обл., г Жуковский',
		};

		const suggestions = await answerSuggest(
			callFor( 'billing_city' ).fetch( 'Жуко' ),
			[ { key: 'dadata:zh', label: record.label, level: 'settlement', record } ]
		);

		expect( suggestions[ 0 ].record.value ).toBeUndefined();
		expect( Object.keys( suggestions[ 0 ].record ).sort() ).toEqual(
			[ 'country', 'key', 'label', 'level', 'provider_id', 'settlement' ]
		);
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

		// Bare component NAMES, not the record's own label and not `type + name`: the field
		// carries what a carrier's locality dictionary can match (operator, s70 rig pass).
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
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
	it( 'detaches every attached widget on switch to an unsupported country AND empties the section', () => {
		// CONTRADICTS spec Task 11's original wording ("store state kept ... switch back →
		// re-attached with state intact"), on the operator's rig verdict (s70): the values left
		// behind name a locality in the country the customer just LEFT, the shipping
		// calculation reads them, and the confirmed records would still scope the next
		// /suggest by a region of the old country. An address that cannot exist is worse than
		// a re-typed one.
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';

		const detachSpies = attachCalls.map( ( c ) => c.detach );

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		detachSpies.forEach( ( spy ) => expect( spy ).toHaveBeenCalled() );
		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );

	it( 'clears the section on a switch to a SUPPORTED country too — the rig case', () => {
		// RU → UZ on the rig: both are served, so nothing detaches and the old code left every
		// field filled with the Moscow values.
		boot( { region: true, settlement: true, address: true, countries: [ 'RU', 'US' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';
		document.getElementById( 'billing_postcode' ).value = '101000';

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '' );
	} );

	it( 'a programmatic country change carrying the SAME value clears nothing', () => {
		// WooCommerce fires exactly this while initialising the checkout — the gate that keeps
		// #272 from happening again, now that the country is a destructive parent.
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		// Through a REAL selection, so store and DOM agree — writing straight to `.value`
		// leaves the store empty, and the re-attach pass then legitimately restores the DOM
		// from it, which would look like this gate failing when it did not.
		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:msk', label: 'г Москва', value: 'Москва', level: 'settlement',
			record: {
				key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU',
				settlement: { name: 'Москва', type: 'г' }, postcode: '101000', label: 'г Москва',
			},
		} );

		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );
	} );

	it( 'a billing-country change never empties the SHIPPING section', () => {
		boot( { region: true, settlement: true, address: true, section: 'shipping', withShippingCountry: true, countries: [ 'RU' ] } );

		document.getElementById( 'shipping_city' ).value = 'Москва';

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Москва' );
	} );

	it( 'detaches a level the NEW country does not serve, even though the country itself is served', () => {
		// The nasty case, and the one no test caught before: RU and AM are BOTH in `countries`,
		// so the country gate stays satisfied across the switch — but DaData has street data
		// only for RU/BY/KZ/UZ, so the address widget must come off. A gate that lives only on
		// the attach path can never detach, which is why isNodeActive() owns the level check.
		boot( {
			region: true, settlement: true, address: true,
			countries: [ 'RU', 'AM' ],
			levels: {
				RU: { region: true, settlement: true, address: true },
				AM: { region: true, settlement: true, address: false },
			},
		} );

		// The shared fixture's country <select> only carries RU and US; assigning an absent
		// value to a <select> silently yields '', which would look exactly like "country not
		// supported" and detach everything — so the option has to exist before we can switch.
		const countrySelect = document.getElementById( 'billing_country' );
		const am = document.createElement( 'option' );
		am.value = 'AM';
		am.textContent = 'Армения';
		countrySelect.appendChild( am );

		const addressAttach = attachCalls.find( ( c ) => c.el.id === 'billing_address_1' );
		const settlementAttach = attachCalls.find( ( c ) => c.el.id === 'billing_city' );

		expect( addressAttach ).toBeDefined();

		// Deltas around the switch, not absolute counts: a widget may legitimately be
		// re-attached during boot (the typeahead auto-detaches a previous instance on
		// re-attach), and an absolute assertion would be measuring that instead.
		const addressDetachesBefore = addressAttach.detach.mock.calls.length;
		const settlementDetachesBefore = settlementAttach.detach.mock.calls.length;

		document.getElementById( 'billing_country' ).value = 'AM';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressAttach.detach.mock.calls.length ).toBeGreaterThan( addressDetachesBefore );
		// …and the levels AM DOES serve stay attached — this must not degrade into "detach all".
		expect( settlementAttach.detach.mock.calls.length ).toBe( settlementDetachesBefore );
	} );

	it( 're-attaches on the way back to a supported country, but the fields stay EMPTY', () => {
		// The other half of the same reversal: the widgets come back, the values do not.
		// Spec Task 11 promised "state intact" here; that promise dies by construction once a
		// real country change is destructive, and the trade is deliberate — a customer who
		// mis-clicks a country retypes an address, whereas keeping it silently ships an
		// address belonging to another country.
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const attachCountAfterDetach = window.WoodevLocationTypeahead.mock.calls.length;

		document.getElementById( 'billing_country' ).value = 'RU';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( window.WoodevLocationTypeahead.mock.calls.length ).toBeGreaterThan( attachCountAfterDetach );
		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
	} );
} );

// -----------------------------------------------------------------------
// Country fallback chain (issue #296): `checkout field -> WooCommerce store setting -> RU`.
// Steps 2+3 are already merged server-side into ONE `entry.location.defaultCountry` value
// (`Location_Service::resolve_default_country()`, fed through `Checkout_Config::build_location_block()`)
// — `countryFor()` only ever has ONE fallback of its own to make: the live field, else that
// value. Before this task, a checkout with no country field at all made `countryFor()` return
// `''`, which `isCountrySupported()` always rejects — no widget ever attached, and the whole
// location layer went silently dead with no signal why (the operator's own bug report).
// -----------------------------------------------------------------------

describe( 'country fallback chain (issue #296)', () => {
	it( 'step 1: uses the live checkout field value when present and non-empty', () => {
		boot( {
			settlement: true, country: 'US', defaultCountry: 'RU',
			countries: [ 'RU', 'US' ],
			levels: { RU: { region: true, settlement: true, address: true }, US: { region: false, settlement: true, address: false } },
		} );

		callFor( 'billing_city' ).fetch( 'Sea' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'country=US' );
	} );

	it( 'steps 2/3: falls back to the server-resolved defaultCountry when the field is present but empty', () => {
		boot( { settlement: true, country: '', defaultCountry: 'RU', countries: [ 'RU' ] } );

		// The bug this closes: isCountrySupported( entry, '' ) was always false, so no widget
		// ever attached for a country <select> a customer had not chosen from yet.
		expect( attachCalls.map( ( c ) => c.el.id ) ).toContain( 'billing_city' );

		callFor( 'billing_city' ).fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'country=RU' );
	} );

	it( 'steps 2/3: falls back to the server-resolved defaultCountry, and still attaches, when the checkout has no country field at all', () => {
		boot( { settlement: true, omitBillingCountry: true, defaultCountry: 'RU', countries: [ 'RU' ] } );

		expect( document.getElementById( 'billing_country' ) ).toBeNull();
		expect( attachCalls.map( ( c ) => c.el.id ) ).toContain( 'billing_city' );

		callFor( 'billing_city' ).fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'country=RU' );
	} );

	it( 'never throws, and never attaches a listener to a non-existent country field, when the checkout has no country field at all', () => {
		// bindChangeWatchers() delegates on document.body (see the file's own docblock on the
		// two event worlds) — there is nothing to bind TO a node that was never rendered, so
		// booting with the field entirely absent must not throw.
		expect( () => boot( { settlement: true, omitBillingCountry: true, defaultCountry: 'RU', countries: [ 'RU' ] } ) ).not.toThrow();
	} );

	it( 'does not detach the widget across a layout-relevant re-arbitration while the country field stays absent', () => {
		boot( {
			settlement: true, omitBillingCountry: true, withShippingCountry: true,
			defaultCountry: 'RU', countries: [ 'RU' ],
		} );

		const settlementAttach = callFor( 'billing_city' );
		const detachesBefore = settlementAttach.detach.mock.calls.length;

		// #shipping_country IS present here — changing it re-runs applyCountryArbitration() for
		// EVERY entry (handleLayoutRelevantChange()), including this billing-section one, whose
		// own country is still unavailable from the DOM and must keep resolving to the SAME
		// server default on every pass — never flap the already-attached widget.
		document.getElementById( 'shipping_country' ).value = 'US';
		document.getElementById( 'shipping_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( settlementAttach.detach.mock.calls.length ).toBe( detachesBefore );
	} );

	it( 'a country the fallback names but the layer does not cover still degrades to no widget, never a forced attach', () => {
		// entry.location.countries never includes the fallback's own answer here — the D15
		// degradation idiom must still win; a server-side misconfiguration must never force a
		// widget onto a country this layer genuinely does not serve.
		boot( { settlement: true, omitBillingCountry: true, defaultCountry: 'RU', countries: [ 'KZ' ] } );

		expect( attachCalls.map( ( c ) => c.el.id ) ).not.toContain( 'billing_city' );
	} );
} );

// -----------------------------------------------------------------------
// PR #320 review, finding 1: the destructive-clear gate must compare the SAME effective
// country countryFor() (and the widget's own attach/scope) already resolves — never the raw
// DOM value. Before this fix, prefill() seeded entry.resolved['billing_country'] from
// `el.value` ('' for an unselected select), so the customer's first EXPLICIT pick of the very
// country the fallback was already using read as a real transition and destructively cleared
// an address that was never stale to begin with.
// -----------------------------------------------------------------------

describe( 'selecting the fallback\'s own country must not destructively clear (finding 1, PR #320 review)', () => {
	it( 'reproduction: attaches on the RU fallback, customer fills the address, then explicitly picks "Россия" — nothing is cleared', () => {
		// "No location by default": #billing_country is present but genuinely unselected
		// (`value === ''`, WooCommerce's own placeholder option) — the widget attaches on the
		// server-resolved defaultCountry fallback, exactly like the "steps 2/3" tests above.
		boot( {
			settlement: true, address: true, country: '', defaultCountry: 'RU', countries: [ 'RU' ],
		} );

		// The widget attached under the fallback at all — precondition for the bug to even be
		// reachable.
		expect( attachCalls.map( ( c ) => c.el.id ) ).toContain( 'billing_city' );

		// Customer picks a settlement through the widget (a real record, so the field's own
		// confirmed record is non-null — see the "country switch" describe block above for why
		// a genuine selection, not a raw `.value` write, is required here).
		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:msk', label: 'г Москва', value: 'Москва', level: 'settlement',
			record: {
				key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU',
				settlement: { name: 'Москва', type: 'г' }, label: 'г Москва',
			},
		} );

		// ...types a street and a postcode by hand, without picking an address suggestion.
		document.getElementById( 'billing_address_1' ).value = 'ул Тверская, 1';
		document.getElementById( 'billing_postcode' ).value = '101000';

		// THEN explicitly selects the SAME country every suggestion was already scoped by.
		document.getElementById( 'billing_country' ).value = 'RU';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул Тверская, 1' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );
	} );

	it( 'the SAME reproduction with no country field at all (prefill has nothing to seed, but there is also no field to fire a change on)', () => {
		// A checkout that dropped the country field entirely never sees a `change` event for it
		// at all, so the bug this finding describes cannot fire — this pins that the fix does
		// not depend on a country field existing.
		boot( { settlement: true, address: true, omitBillingCountry: true, defaultCountry: 'RU', countries: [ 'RU' ] } );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:msk', label: 'г Москва', value: 'Москва', level: 'settlement',
			record: {
				key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU',
				settlement: { name: 'Москва', type: 'г' }, label: 'г Москва',
			},
		} );

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
			levels: { RU: { region: true, settlement: true, address: false } },
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
// Task 13 renderer seam (spec D7) — the cascade must not know WHICH renderer a field uses,
// only how to ask `window.WoodevLocationRenderers` for one, and it must fall back to the
// baseline typeahead whenever nothing claims the node.
// -----------------------------------------------------------------------

describe( 'Task 13 renderer seam (spec D7)', () => {
	it( 'falls back to the baseline typeahead when nothing is registered for the resolved mode', () => {
		// `window.WoodevLocationRenderers` is left entirely undefined (as if
		// location-select-modes.js never loaded) — the D7 floor ("text+typeahead always")
		// must still hold.
		boot( { settlement: true, mode: 'related-list' } );

		expect( callFor( 'billing_city' ) ).not.toBeUndefined();
	} );

	it( 'attaches the {mode}:{level}-specific renderer INSTEAD of the baseline typeahead, and hands it the shared onSelect', async () => {
		const specialCalls = [];
		window.WoodevLocationRenderers = {
			'custom-mode:settlement': ( el, options ) => {
				specialCalls.push( { el, options } );

				return { detach: jest.fn() };
			},
		};

		boot( { settlement: true, mode: 'custom-mode' } );

		expect( callFor( 'billing_city' ) ).toBeUndefined(); // the baseline typeahead was never asked.
		expect( specialCalls ).toHaveLength( 1 );
		expect( specialCalls[ 0 ].el.id ).toBe( 'billing_city' );

		// The SAME persist route every other level uses (D8) — not a duplicated one.
		const record = { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' };
		specialCalls[ 0 ].options.onSelect( { record } );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		expect( selectReq.url ).toBe( SELECT_URL );
		expect( JSON.parse( selectReq.init.body ) ).toEqual( { record } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		selectReq.resolve( { current: { key: record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( true );
		triggerSpy.mockRestore();
	} );

	it( 'prefers the {mode}:{level} key over the bare {mode} key when both are registered', () => {
		const levelSpecific = jest.fn( () => ( { detach: jest.fn() } ) );
		const bareMode = jest.fn( () => ( { detach: jest.fn() } ) );

		window.WoodevLocationRenderers = {
			'custom-mode:settlement': levelSpecific,
			'custom-mode': bareMode,
		};

		boot( { settlement: true, mode: 'custom-mode' } );

		expect( levelSpecific ).toHaveBeenCalledTimes( 1 );
		expect( bareMode ).not.toHaveBeenCalled();
	} );

	it( 'the bare {mode} key serves every level uniformly when no level-specific one is registered', () => {
		const bareMode = jest.fn( () => ( { detach: jest.fn() } ) );

		window.WoodevLocationRenderers = { 'custom-mode': bareMode };

		boot( { region: true, settlement: true, address: true, mode: 'custom-mode' } );

		expect( bareMode ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'a renderer that DECLINES (returns a falsy value) falls back to the baseline typeahead', () => {
		window.WoodevLocationRenderers = { 'custom-mode:settlement': () => null };

		boot( { settlement: true, mode: 'custom-mode' } );

		expect( callFor( 'billing_city' ) ).not.toBeUndefined();
	} );

	it( 'a DOM-replacing renderer\'s reported `api.el` is what gets stored as the node\'s live element', () => {
		// Guards against reconcileAfterCheckoutUpdate() misreading a select2-style DOM swap
		// (input replaced by a <select>) as a checkout re-render every single pass.
		const replacement = document.createElement( 'select' );
		replacement.id = 'billing_city';

		window.WoodevLocationRenderers = {
			'custom-mode:settlement': ( el ) => {
				el.parentNode.replaceChild( replacement, el );

				return { detach: jest.fn(), el: replacement };
			},
		};

		boot( { settlement: true, mode: 'custom-mode' } );

		// Trigger updated_checkout — if the WRONG element were stored, this would misfire a
		// spurious detach/reattach; nothing here asserts on that directly, but a second pass
		// re-deriving country arbitration must not throw or double-attach.
		window.jQuery( document.body ).trigger( 'updated_checkout' );

		expect( document.getElementById( 'billing_city' ) ).toBe( replacement );
	} );

	// -------------------------------------------------------------------
	// isNodeActive()'s ONE necessary D15 exception — related-list region only
	// -------------------------------------------------------------------

	describe( 'the related-list region exception to the D15 level gate (issue #294)', () => {
		it( 'attempts the related-list:region renderer even when levels[country].region is false', () => {
			const regionRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			window.WoodevLocationRenderers = { 'related-list:region': regionRenderer };

			boot( {
				region: true, mode: 'related-list',
				// Mirrors class-checkout-config.php's own #294 arbitration: `region` reads
				// `false` here REGARDLESS of whether this layer's own related-list injector
				// populated the states or a genuine conflict did — see isRelatedListRegionNode()'s
				// own docblock.
				levels: { RU: { region: false, settlement: true, address: true } },
			} );

			expect( regionRenderer ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does NOT bypass the D15 gate for any OTHER mode — a plain typeahead mode still stays native', () => {
			const regionRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			// Registered under a DIFFERENT mode key — must never be consulted while
			// `entry.location.mode` is `'typeahead'`.
			window.WoodevLocationRenderers = { 'typeahead:region': regionRenderer };

			boot( {
				region: true, mode: 'typeahead',
				levels: { RU: { region: false, settlement: true, address: true } },
			} );

			expect( regionRenderer ).not.toHaveBeenCalled();
			expect( callFor( 'billing_state' ) ).toBeUndefined(); // D15: unsupported level stays fully native.
		} );

		it( 'does NOT bypass the D15 gate for a non-region level under related-list mode', () => {
			const settlementRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			window.WoodevLocationRenderers = { 'related-list:settlement': settlementRenderer };

			boot( {
				settlement: true, mode: 'related-list',
				levels: { RU: { region: true, settlement: false, address: true } },
			} );

			// isNodeActive() is false for this node (settlement is D15-unsupported and this is
			// NOT the region exception), so attachOne() — and therefore the registered
			// renderer — is never even reached.
			expect( settlementRenderer ).not.toHaveBeenCalled();
		} );
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
// Location chain (issue #330, spec §7) — the client half of the bug: a stored current record
// only ever seeded ONE level, so once the customer's current record was address-level, a page
// reload left `entry.records['settlement']` empty and the next address suggest went
// country-wide instead of `within=<settlement key>`. The server now also publishes
// `config.location.chain` (`{ level: { key, level } }`) and `/select`'s own response gains a
// `chain` field of the same shape — this restores/adopts it into `entry.records` for EVERY
// level it names, not only `current`'s own.
// -----------------------------------------------------------------------

describe( 'location chain restore (issue #330, spec §7)', () => {
	it( 'restores every level named by config.location.chain, so a restored address-level current STILL scopes the next address suggest by within=<settlement key> (the actual bug)', () => {
		boot( {
			settlement: true, address: true,
			current: { key: 'dadata:addr1', level: 'address' },
			chain: {
				settlement: { key: 'dadata:city1', level: 'settlement' },
				address: { key: 'dadata:addr1', level: 'address' },
			},
		} );

		const addressCall = callFor( 'billing_address_1' );
		addressCall.fetch( 'Твер' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:city1' ) );
	} );

	it( 'degrades to today\'s single-level seed when config.location.chain is absent (older server) — no throw, no within on the address suggest', () => {
		expect( () => boot( {
			settlement: true, address: true,
			current: { key: 'dadata:addr1', level: 'address' },
			// `chain` deliberately omitted — see buildConfig()'s own comment.
		} ) ).not.toThrow();

		const addressCall = callFor( 'billing_address_1' );
		addressCall.fetch( 'Твер' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).not.toContain( 'within=' );
	} );

	it( 'skips a malformed chain entry (a non-string key) rather than storing a broken record for it', () => {
		// A NUMBER key, not a missing one: scopeKeyFor()'s own `record.key ? ... : null` guard
		// would already hide a MISSING key regardless of whether adoptChain() stored `{}` or
		// skipped it outright — that shape can't tell "skipped" and "stored broken" apart. A
		// truthy but non-string key is the one shape only adoptChain()'s own `'string' ===
		// typeof node.key` check catches: skipped here means no `within` at all; a broken
		// `{ key: 12345 }` slipping through would show up as `within=12345`.
		boot( {
			settlement: true, address: true,
			current: { key: 'dadata:addr1', level: 'address' },
			chain: {
				settlement: { key: 12345, level: 'settlement' }, // malformed — must be skipped
				address: { key: 'dadata:addr1', level: 'address' },
			},
		} );

		const addressCall = callFor( 'billing_address_1' );
		addressCall.fetch( 'Твер' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).not.toContain( 'within=' );
	} );

	it( 'still fires woodev_location_applied exactly ONCE, with the current payload only — the chain is scoping plumbing, not a second event source', () => {
		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		boot( {
			settlement: true, address: true,
			current: { key: 'dadata:addr1', level: 'address' },
			chain: {
				settlement: { key: 'dadata:city1', level: 'settlement' },
				address: { key: 'dadata:addr1', level: 'address' },
			},
		} );

		// settlementKey (issue #336) comes from the boot-time chain's own settlement entry,
		// adopted by adoptChain() BEFORE this boot fire — see prefill()'s own docblock.
		expect( seen ).toEqual( [ { key: 'dadata:addr1', level: 'address', settlementKey: 'dadata:city1', implicit: false } ] );
	} );

	it( 'adopts a /select response\'s own rebuilt chain, so the very next suggest in the SAME page load is scoped by the settlement it names', async () => {
		boot( { settlement: true, address: true } );

		// Selecting the address directly (no settlement ever picked on this page load) is
		// exactly the shape that used to leave entry.records['settlement'] empty — backwardsFill()
		// only ever writes FIELD VALUES, never records (see the file's own docblock).
		const addressCall = callFor( 'billing_address_1' );
		const item = {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'ул Тверская, 1',
			},
		};

		selectViaFake( addressCall, item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( {
			current: { key: item.record.key, level: 'address' },
			persisted: true,
			chain: {
				settlement: { key: 'dadata:city1', level: 'settlement' },
				address: { key: item.record.key, level: 'address' },
			},
		} );
		await flushMicrotasks();

		addressCall.fetch( 'Твер' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:city1' ) );
	} );

	/**
	 * Selects a settlement through the widget and settles its /select round trip, so
	 * `entry.records.settlement` holds a real record before the address step.
	 */
	const pickSettlement = async ( key = 'dadata:city1' ) => {
		const settlementCall = callFor( 'billing_city' );

		selectViaFake( settlementCall, {
			key, label: 'Москва', level: 'settlement',
			record: { key, provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'Москва' },
		} );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key, level: 'settlement' } },
		} );

		await flushMicrotasks();
	};

	// -------------------------------------------------------------------
	// detail.settlementKey (issue #336) — always entry.records.settlement's OWN key,
	// regardless of which level the event's own record (`key`/`level`) is for.
	// -------------------------------------------------------------------

	it( 'settlementKey names the settlement actually picked, not the deeper address just picked (issue #336)', async () => {
		boot( { settlement: true, address: true } );

		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		await pickSettlement( 'dadata:settlement-pushkino' );

		const addressCall = callFor( 'billing_address_1' );

		// A DIFFERENT settlement's own address (issue #336's own rig measurement: an
		// address's `city_fias_id` routinely nests a DEEPER settlement than the one the
		// customer picked) — the server does not repair the chain this time (no `chain`
		// field), so entry.records.settlement keeps the customer's OWN earlier pick.
		selectViaFake( addressCall, {
			key: 'dadata:address-cherkizovo', label: 'дер Черкизово, ул Ленина, 1', level: 'address',
			record: {
				key: 'dadata:address-cherkizovo', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'дер Черкизово, ул Ленина, 1',
			},
		} );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:address-cherkizovo', level: 'address' },
			persisted: true,
		} );
		await flushMicrotasks();

		const last = seen[ seen.length - 1 ];

		expect( last.key ).toBe( 'dadata:address-cherkizovo' );
		expect( last.settlementKey ).toBe( 'dadata:settlement-pushkino' );
	} );

	it( 'settlementKey is empty when no settlement has ever been picked on this page load (issue #336)', async () => {
		boot( { settlement: true, address: true } );

		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		const addressCall = callFor( 'billing_address_1' );

		selectViaFake( addressCall, {
			key: 'dadata:address-typed-only', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:address-typed-only', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'ул Тверская, 1',
			},
		} );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:address-typed-only', level: 'address' },
			persisted: true,
		} );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'dadata:address-typed-only', level: 'address', settlementKey: '', implicit: false } ] );
	} );

	it( 'DROPS a stale record the server\'s rebuilt chain no longer names — a non-empty chain is authoritative, not merely additive', async () => {
		// Adversarial review: adopting additively could never REMOVE anything, so a
		// server-side repair (the new record proved not to be within the stored
		// settlement, so the chain dropped it) left the settlement sitting here and the
		// client kept sending a `within` the server now refuses — silently falling back
		// to a country-wide search, the exact seam this change exists to close.
		boot( { settlement: true, address: true } );

		await pickSettlement();

		const addressCall = callFor( 'billing_address_1' );

		selectViaFake( addressCall, {
			key: 'dadata:addr-elsewhere', label: 'ул Другая, 1', level: 'address',
			record: {
				key: 'dadata:addr-elsewhere', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'ул Другая, 1',
			},
		} );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:addr-elsewhere', level: 'address' },
			persisted: true,
			// The server kept ONLY the address: the settlement was not an ancestor of it.
			chain: { address: { key: 'dadata:addr-elsewhere', level: 'address' } },
		} );
		await flushMicrotasks();

		addressCall.fetch( 'Твер' );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).not.toContain( 'within=' );
	} );

	it( 'KEEPS its own records when the server reports no chain at all — a guest whose write did not persist still scopes by what they picked', async () => {
		// The mirror of the rule above, and the reason it is gated on "non-empty": a
		// server with nothing to report (`persisted: false`, `chain: []` — a guest whose
		// WooCommerce session never initialized, gotcha
		// `guest-session-write-needs-the-cart-cookie`) has not repaired anything. Wiping
		// the client's own in-session memory there would break the very flow issue #324
		// was about, for no gain.
		boot( { settlement: true, address: true } );

		await pickSettlement();

		const addressCall = callFor( 'billing_address_1' );

		selectViaFake( addressCall, {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'ул Тверская, 1',
			},
		} );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:addr1', level: 'address' },
			persisted: false,
			chain: [], // PHP's empty array — what the server sends when it has no chain.
		} );
		await flushMicrotasks();

		addressCall.fetch( 'Твер' );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( 'within=' + encodeURIComponent( 'dadata:city1' ) );
	} );
} );

// -----------------------------------------------------------------------
// woodev_location_applied's `implicit` detail (issue #309; spec D11/§4.6) — replaces an
// earlier `data-woodev-location-implicit` DOM-attribute attempt (reverted after an adversarial
// review reproduced five ways it could not work: destroyed at boot by attachAll()'s
// DOM-replacing renderers, lost on a checkout re-render, permanently stale across two entries
// sharing one customer record, never cleared on a country change, and impossible to signal for
// a record whose level has no field in the chain). The event carries the SAME information with
// none of those failure modes, because it depends on no DOM node existing or surviving.
// -----------------------------------------------------------------------

describe( 'woodev_location_applied\'s implicit detail (issue #309)', () => {
	function captureLocationApplied() {
		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );
		return seen;
	}

	it( 'fires implicit:true on boot when config.location.implicit is true (defect: the old DOM marker could never signal at all for the ajax-select2/related-list modes — see the test below)', () => {
		const seen = captureLocationApplied();

		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: true,
		} );

		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true } ] );
	} );

	it( 'fires implicit:false on boot for a real customer choice (config.location.implicit is false)', () => {
		const seen = captureLocationApplied();

		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: false,
		} );

		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: false } ] );
	} );

	it( 'fires nothing at boot when there is no current record at all', () => {
		const seen = captureLocationApplied();

		boot( { settlement: true, current: null, implicit: true } );

		expect( seen ).toEqual( [] );
	} );

	it( 'never writes any DOM attribute for the signal — the mechanism this replaces is gone entirely', () => {
		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: true,
		} );

		const el = document.getElementById( 'billing_city' );

		expect( el.getAttributeNames().some( ( name ) => name.indexOf( 'implicit' ) !== -1 ) ).toBe( false );
	} );

	it( 'still fires implicit:true at boot even when the record\'s level has no field in this entry\'s chain (defect #5 — spec §4.4 explicitly permits this shape)', () => {
		// The chain here only ever carries 'settlement'; the record names 'address', a level
		// this entry has no field for at all. chainNodeForLevel() would have returned null for
		// the OLD DOM mechanism — nothing to mark, no signal, ever. The event needs no field to
		// exist.
		const seen = captureLocationApplied();

		boot( {
			settlement: true,
			current: { key: 'dadata:addr1', level: 'address' },
			implicit: true,
		} );

		// settlementKey (issue #336) is '' — this entry's chain never carried a settlement
		// entry, so entry.records.settlement was never seeded.
		expect( seen ).toEqual( [ { key: 'dadata:addr1', level: 'address', settlementKey: '', implicit: true } ] );
	} );

	it( 'an explicit pick always fires implicit:false, overriding a previously-implicit boot state (spec D11: a real choice drops the flag)', async () => {
		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: true,
		} );

		const seen = captureLocationApplied(); // attached AFTER boot — only the pick's own fire matters here.
		const settlementCall = callFor( 'billing_city' );
		const item = {
			key: 'dadata:city2', label: 'г Тверь', level: 'settlement',
			record: {
				key: 'dadata:city2', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Тверь',
			},
		};

		selectViaFake( settlementCall, item );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: item.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: item.record.key, level: 'settlement' } } } );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'dadata:city2', level: 'settlement', settlementKey: 'dadata:city2', implicit: false } ] );
	} );

	it( 'the initial implicit:true fire is not destroyed by a DOM-replacing renderer running right after it (defect #1 — the fatal one: attachAll() runs AFTER prefill() in boot())', () => {
		// Reproduces the OLD mechanism's exact failure timing: a renderer that swaps the
		// original <input> for a fresh element (mirrors what location-select-modes.js's
		// buildSelectField() does for real under ajax-select2/related-list — Task 13, spec D7)
		// runs during attachAll(), which boot() calls right after prefill() already fired the
		// event. A DOM-attribute mechanism set on the OLD node before this swap would already
		// be gone; the event has no such dependency.
		window.WoodevLocationRenderers = {
			'custom-mode:settlement': ( el ) => {
				const replacement = document.createElement( 'select' );
				replacement.id = el.id;
				el.parentNode.replaceChild( replacement, el );

				return { detach: jest.fn(), el: replacement };
			},
		};

		const seen = captureLocationApplied();

		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: true,
			mode: 'custom-mode',
		} );

		// Sanity: this really does exercise the DOM-destroying renderer path.
		expect( document.getElementById( 'billing_city' ).tagName ).toBe( 'SELECT' );
		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true } ] );
	} );

	it( 'a later checkout re-render (updated_checkout) neither loses nor duplicates the boot-time signal (defect #2)', () => {
		// The old DOM attribute never survived WooCommerce replacing the field fragment,
		// because nothing re-applied it after reconcileAfterCheckoutUpdate() ran (prefill() is
		// only ever called once, from boot()). The event already fired once, independent of
		// any DOM node — a later re-render has nothing to lose and reconcileAfterCheckoutUpdate()
		// does not need to (and does not) re-fire it.
		const seen = captureLocationApplied();

		boot( {
			settlement: true,
			current: { key: 'dadata:city1', level: 'settlement' },
			implicit: true,
		} );

		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true } ] );

		const fresh = document.createElement( 'input' );
		fresh.type = 'text';
		fresh.id = 'billing_city';
		fresh.name = 'billing_city';
		document.getElementById( 'billing_city' ).replaceWith( fresh );

		window.jQuery( document.body ).trigger( 'updated_checkout' );

		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true } ] );
	} );

	it( 'never gates scoping/addressing on the implicit flag — the restored key still scopes the child fetch', () => {
		// Sibling assertion to "restoring config.location.current on load" above: the SAME
		// implicit default fired here must stay fully usable for scoping — spec D11's other
		// half ("implicit records participate in rate calculation").
		boot( {
			region: true, settlement: true,
			current: { key: 'dadata:region9', level: 'region' },
			implicit: true,
		} );

		const settlementCall = callFor( 'billing_city' );
		settlementCall.fetch( 'Мос' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:region9' ) );
	} );

	/**
	 * Defect #3 (second-entry regression, per the review): two entries sharing ONE customer
	 * record — e.g. a billing-section entry and a shipping-section entry both wired to the
	 * SAME Location_Service — used to each get their OWN `data-woodev-location-implicit` DOM
	 * marker, and an explicit pick in ONE entry's active section only ever cleared THAT
	 * entry's own marker, leaving the other permanently stale. The event has no per-entry
	 * state to diverge: each entry fires its own, identically-valued boot event, and a real
	 * pick's `implicit: false` is a single global fact a listener applies regardless of which
	 * entry produced it — mirrors `bootBillingAndShippingEntries()` in the "section-aware
	 * addressing" describe block below (same shape, `buildConfig()`/`installMarkup()` cannot
	 * express two sections at once).
	 */
	function bootSharedLocationEntries( current, implicit ) {
		document.body.innerHTML = `
			<form class="checkout woocommerce-checkout">
				<select id="billing_country" name="billing_country">
					<option value="RU">Россия</option>
				</select>
				<select id="shipping_country" name="shipping_country">
					<option value="RU">Россия</option>
				</select>
				<input type="checkbox" id="ship-to-different-address-checkbox"
					name="ship_to_different_address" checked />
				<input type="text" id="billing_city" name="billing_city" value="" />
				<input type="text" id="shipping_city" name="shipping_city" value="" />
			</form>
		`;
		document.getElementById( 'billing_country' ).value = 'RU';
		document.getElementById( 'shipping_country' ).value = 'RU';

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		const sharedLocation = {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
			nonce: 'test-nonce',
			countries: [ 'RU' ],
			mode: 'typeahead',
			levels: { RU: { region: true, settlement: true, address: true } },
			current,
			implicit,
			i18n: {},
		};

		window[ CONFIG_GLOBAL + '_billing_shared' ] = {
			fields: { billing_city: locationField( 'settlement', 'billing' ) },
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: sharedLocation,
		};
		window[ CONFIG_GLOBAL + '_shipping_shared' ] = {
			fields: { shipping_city: locationField( 'settlement', 'shipping' ) },
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: sharedLocation,
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
	}

	afterEach( () => {
		delete window[ CONFIG_GLOBAL + '_billing_shared' ];
		delete window[ CONFIG_GLOBAL + '_shipping_shared' ];
	} );

	it( 'two entries sharing one implicit customer record each fire their own identical boot event — no per-entry state to diverge', () => {
		const seen = captureLocationApplied();

		bootSharedLocationEntries( { key: 'dadata:city1', level: 'settlement' }, true );

		expect( seen ).toEqual( [
			{ key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true },
			{ key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: true },
		] );
	} );

	it( 'an explicit pick in the ACTIVE section fires implicit:false — a listener applies it globally, no OTHER entry is left permanently stale', async () => {
		bootSharedLocationEntries( { key: 'dadata:city1', level: 'settlement' }, true );

		const seen = captureLocationApplied(); // attached AFTER boot — only the pick's own fire matters here.

		// "ship to a different address" is checked (bootSharedLocationEntries' own markup) —
		// shipping is the active section; only ITS pick reaches /select (review finding F3,
		// same gate the "section-aware addressing" describe block exercises directly).
		selectViaFake( callFor( 'shipping_city' ), {
			key: 'dadata:kazan', label: 'г Казань', level: 'settlement',
			record: { key: 'dadata:kazan', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Казань' },
		} );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { current: { key: 'dadata:kazan', level: 'settlement' }, persisted: true, chain: { settlement: { key: 'dadata:kazan', level: 'settlement' } } } );
		await flushMicrotasks();

		// ONE event, implicit:false — not two, not still true, and not scoped to "only the
		// shipping entry knows this now" the way the old per-entry DOM marker was.
		expect( seen ).toEqual( [ { key: 'dadata:kazan', level: 'settlement', settlementKey: 'dadata:kazan', implicit: false } ] );
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

// -----------------------------------------------------------------------
// Empty-result message — server-supplied, never a literal in the client
// -----------------------------------------------------------------------

describe( 'empty-result message', () => {
	it( 'hands the widget the translated noResults string from the config', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( callFor( 'billing_city' ).emptyText ).toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
		expect( callFor( 'billing_state' ).emptyText ).toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	} );

	it( 'gives the ADDRESS level its own wording, and the others the generic one', () => {
		// "Nothing found" under a street field reads as "we do not deliver here"; a street the
		// provider does not carry is ordinary at that level (operator, s70).
		boot( { region: true, settlement: true, address: true } );

		expect( callFor( 'billing_address_1' ).emptyText ).toBe( 'Адрес не найден — введите вручную.' );
		expect( callFor( 'billing_city' ).emptyText ).toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
		expect( callFor( 'billing_state' ).emptyText ).toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	} );

	it( 'falls the address level back to the generic string when the server sent no address one', () => {
		boot( { region: true, settlement: true, address: true, i18n: { noResults: 'Общий текст' } } );

		expect( callFor( 'billing_address_1' ).emptyText ).toBe( 'Общий текст' );
	} );

	it( 'passes an empty string when the config carries no i18n block at all', () => {
		// An older server (or a filter that emptied the map) must degrade to the widget's
		// silent default, never to a hardcoded Russian literal baked into the script.
		boot( { region: true, settlement: true, address: true, i18n: null } );

		expect( callFor( 'billing_city' ).emptyText ).toBe( '' );
	} );
} );

// -----------------------------------------------------------------------
// Review finding F2 (issue #159 PR #312, rig-verified): a client-side clear/edit that
// abandons the current locality must invalidate the Location Provider key
// (`woodev_location_applied` with an empty key/level), not leave it stale for
// pickup-mount.js's own resolveLocalityKey() to keep addressing points by.
// -----------------------------------------------------------------------

describe( 'Location Provider key invalidation on a local-only clear (review finding F2)', () => {
	function captureLocationApplied() {
		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );
		return seen;
	}

	it( 'a real country transition fires an empty-key event (clearCountryScope posts nothing to /select)', () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU', 'US' ] } );

		document.getElementById( 'billing_city' ).value = 'Москва';

		const seen = captureLocationApplied();

		document.getElementById( 'billing_country' ).value = 'US';
		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( seen ).toEqual( [ { key: '', level: '', settlementKey: '', implicit: false } ] );
		// Sanity: this really is a pure client-side clear, never a network call.
		expect( fetchCalls.length ).toBe( 0 );
	} );

	it( 'a programmatic country change carrying the SAME value (no real transition) fires nothing', () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		const seen = captureLocationApplied();

		document.getElementById( 'billing_country' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( seen ).toEqual( [] );
	} );

	it( 'a chain-level field edited WITHOUT picking a suggestion fires an empty-key event', async () => {
		// The "typed but not picked" path: handleFieldChanged()'s general branch, never
		// onSelectFor()'s pick path. The invalidation itself is deferred one microtask (see
		// handleFieldChanged()'s own docblock), so this assertion needs a flush — a bare
		// synchronous check right after dispatch would see nothing yet, not a real failure.
		boot( { settlement: true, countries: [ 'RU' ] } );

		const seen = captureLocationApplied();
		const field = document.getElementById( 'billing_city' );

		field.value = 'Моск';
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: '', level: '', settlementKey: '', implicit: false } ] );
	} );

	it( 'a real pick through the widget does NOT fire the empty-key event before /select resolves', () => {
		// onSelectFor()'s own pick path writes call.el.value itself before invoking onSelect —
		// this must not be misread as a "typed but not picked" edit by handleFieldChanged()'s
		// own change listener (both are bound to the SAME native `change` event).
		boot( { settlement: true, countries: [ 'RU' ] } );

		const seen = captureLocationApplied();
		const settlementCall = callFor( 'billing_city' );

		selectViaFake( settlementCall, {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		} );

		// No empty-key invalidation fired yet — only the real, persisted key, once /select
		// resolves (see the "woodev_location_applied" describe block above for that half).
		expect( seen ).toEqual( [] );
	} );

	it( 'editing the postcode-only field never fires the invalidation event (postcode is not a locality)', () => {
		boot( { settlement: true, countries: [ 'RU' ] } );

		const seen = captureLocationApplied();
		const field = document.getElementById( 'billing_postcode' );

		field.value = '101000';
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( seen ).toEqual( [] );
	} );
} );

// -----------------------------------------------------------------------
// Review finding F3 (issue #159 PR #312): the Location Provider layer stores exactly ONE
// customer record — a pick made in the section that is NOT currently the customer's
// delivery address must never overwrite it, or the bulk points query (addressed by that
// record) and the map's own live DOM-read centering (pickup-mount.js's resolveLocality())
// end up describing two different cities.
// -----------------------------------------------------------------------

describe( 'section-aware addressing (review finding F3)', () => {
	/**
	 * Boots TWO separate cascade entries sharing one DOM: a billing-section settlement
	 * field and a shipping-section settlement field — `buildConfig()`/`installMarkup()`
	 * cannot express this directly (both apply ONE section to the whole config/markup), so
	 * this test builds the markup and the two `window.woodev_checkout_field_config_*`
	 * globals by hand, matching `Checkout_Config::build()`'s own shape.
	 *
	 * @param {boolean} shipToDifferentAddress Initial checkbox state.
	 * @returns {void}
	 */
	function bootBillingAndShippingEntries( shipToDifferentAddress ) {
		document.body.innerHTML = `
			<form class="checkout woocommerce-checkout">
				<select id="billing_country" name="billing_country">
					<option value="RU">Россия</option>
				</select>
				<select id="shipping_country" name="shipping_country">
					<option value="RU">Россия</option>
				</select>
				<input type="checkbox" id="ship-to-different-address-checkbox"
					name="ship_to_different_address" ${ shipToDifferentAddress ? 'checked' : '' } />
				<input type="text" id="billing_city" name="billing_city" value="" />
				<input type="text" id="shipping_city" name="shipping_city" value="" />
			</form>
		`;
		document.getElementById( 'billing_country' ).value = 'RU';
		document.getElementById( 'shipping_country' ).value = 'RU';

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		const sharedLocation = {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
			nonce: 'test-nonce',
			countries: [ 'RU' ],
			mode: 'typeahead',
			levels: { RU: { region: true, settlement: true, address: true } },
			current: null,
			implicit: false,
			i18n: {},
		};

		window[ CONFIG_GLOBAL + '_billing' ] = {
			fields: { billing_city: locationField( 'settlement', 'billing' ) },
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: sharedLocation,
		};
		window[ CONFIG_GLOBAL + '_shipping' ] = {
			fields: { shipping_city: locationField( 'settlement', 'shipping' ) },
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: sharedLocation,
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
	}

	afterEach( () => {
		delete window[ CONFIG_GLOBAL + '_billing' ];
		delete window[ CONFIG_GLOBAL + '_shipping' ];
	} );

	it( 'a pick in the ACTIVE section (shipping, checkbox checked) POSTs /select', () => {
		bootBillingAndShippingEntries( true );

		selectViaFake( callFor( 'shipping_city' ), {
			key: 'dadata:kazan', label: 'г Казань', level: 'settlement',
			record: { key: 'dadata:kazan', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Казань' },
		} );

		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( 1 );
	} );

	it( 'a pick in the INACTIVE section (billing, while shipping is the checked ship-to target) does NOT POST /select', () => {
		bootBillingAndShippingEntries( true );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:msk', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		} );

		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( 0 );
		// The LOCAL field value is still written — only the server round trip is gated. Written
		// by selectViaFake() itself (mirroring the real typeahead widget), not by this module.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'г Москва' );
	} );

	it( 'when "ship to a different address" is UNCHECKED, billing is active and shipping is not', () => {
		bootBillingAndShippingEntries( false );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:msk', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		} );

		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( 1 );
	} );

	it( 'a shipping pick is never POSTed while "ship to a different address" is unchecked (the widget is not even attached)', () => {
		bootBillingAndShippingEntries( false );

		// isNodeActive() already detaches a shipping-section widget when the checkbox is
		// unchecked (pre-existing behaviour) — sanity-checked here so this describe block's
		// OWN two-entry harness is proven equivalent to the rest of the file's boot() path.
		expect( callFor( 'shipping_city' ) ).toBeUndefined();
	} );
} );

describe( 'a pickup point address replacement must not read as a manual edit (#339)', () => {
	function captureLocationApplied() {
		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );
		return seen;
	}

	/**
	 * What `pickup-mount.js`'s `applyAddressReplacement()` does to the shared WooCommerce
	 * address fields: writes the POINT's own address/locality/postcode and fires a real
	 * `change` on each. Modelled here rather than imported so this file keeps testing only
	 * what location-cascade.js owns — the seam, not the other module's internals.
	 *
	 * @param {Object}  fields    `{ fieldId: value }` — the write pickup-mount is about to make.
	 * @param {boolean} announced Whether pickup-mount announces the write first (the seam).
	 */
	function replaceAddressLikePickupMount( fields, announced ) {
		if ( announced ) {
			document.body.dispatchEvent(
				new CustomEvent( 'woodev_pickup_address_replacing', {
					detail: { fields: fields },
					bubbles: true,
				} )
			);
		}

		Object.keys( fields ).forEach( ( fieldId ) => {
			const el = document.getElementById( fieldId );

			el.value = fields[ fieldId ];
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	function bootWithPickedSettlement() {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU' ] } );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:0c5b2444', label: 'Moscow', level: 'settlement',
			record: {
				key: 'dadata:0c5b2444', provider_id: 'dadata', level: 'settlement',
				country: 'RU', label: 'Moscow',
			},
		} );
	}

	it( 'reproduction: an UNANNOUNCED write of a different settlement spelling invalidates the record', async () => {
		// #339 as found in the browser: the customer picked «Moscow» (the account locale
		// transliterates), the carrier hands back «Москва» in Cyrillic, and the cascade —
		// correctly, by its own rule — reads the differing text as the customer having edited
		// the field by hand, so the settlement record goes away and the next address search
		// leaves without `within`.
		bootWithPickedSettlement();

		const seen = captureLocationApplied();

		replaceAddressLikePickupMount( {
			billing_city: 'Москва',
			billing_address_1: 'ул Новокосинская, д 17 к 6',
		}, false );

		await flushMicrotasks();

		expect( seen ).toContainEqual( { key: '', level: '', settlementKey: '', implicit: false } );
	} );

	it( 'the seam: an ANNOUNCED write keeps the record — the cascade re-seeds instead of clearing', async () => {
		bootWithPickedSettlement();

		const seen = captureLocationApplied();

		replaceAddressLikePickupMount( {
			billing_city: 'Москва',
			billing_address_1: 'ул Новокосинская, д 17 к 6',
		}, true );

		await flushMicrotasks();

		expect( seen ).toEqual( [] );
	} );

	it( 'the announced write still lands in the fields and the store — silent, not skipped', () => {
		bootWithPickedSettlement();

		replaceAddressLikePickupMount( {
			billing_city: 'Москва',
			billing_address_1: 'ул Новокосинская, д 17 к 6',
			billing_postcode: '111672',
		}, true );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул Новокосинская, д 17 к 6' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '111672' );

		const store = window.WoodevCheckoutFieldStore.getStoreForField( 'billing_city' );

		expect( store.getValue( 'billing_city' ) ).toBe( 'Москва' );
	} );

	it( 'announcing does NOT disarm the next genuine manual edit', async () => {
		// The seam must cover exactly the announced write and nothing after it: a customer who
		// edits the city by hand a moment later still invalidates the record. Without this the
		// fix would trade #339 for a permanently deaf cascade.
		bootWithPickedSettlement();

		replaceAddressLikePickupMount( { billing_city: 'Москва' }, true );

		const seen = captureLocationApplied();
		const field = document.getElementById( 'billing_city' );

		field.value = 'Тверь';
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// The invalidation is deferred one microtask (see handleFieldChanged()'s docblock).
		await flushMicrotasks();

		expect( seen ).toContainEqual( { key: '', level: '', settlementKey: '', implicit: false } );
	} );

	it( 'an announcement naming a field this entry does not own is ignored', () => {
		bootWithPickedSettlement();

		expect( () => {
			document.body.dispatchEvent(
				new CustomEvent( 'woodev_pickup_address_replacing', {
					detail: { fields: { shipping_city: 'Москва', not_a_field_at_all: 'x' } },
					bubbles: true,
				} )
			);
		} ).not.toThrow();

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Moscow' );
	} );

	it( 'a malformed announcement is survivable', () => {
		bootWithPickedSettlement();

		expect( () => {
			document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_address_replacing', { bubbles: true } ) );
			document.body.dispatchEvent(
				new CustomEvent( 'woodev_pickup_address_replacing', { detail: {}, bubbles: true } )
			);
		} ).not.toThrow();
	} );
} );

// -----------------------------------------------------------------------
// Address lock (#337)
// -----------------------------------------------------------------------

describe( 'the address field is locked until a settlement is picked (#337)', () => {
	const SETTLEMENT_ITEM = {
		key: 'dadata:0c5b2444', label: 'Москва', level: 'settlement',
		record: {
			key: 'dadata:0c5b2444', provider_id: 'dadata', level: 'settlement',
			country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва',
		},
	};

	function addressField() {
		return document.getElementById( 'billing_address_1' );
	}

	it( 'locks it on boot when settlement and address are linked and the provider serves address', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'leaves it an ORDINARY input when the chain carries no settlement field', () => {
		// Nothing to wait for: with no settlement field, the address level is scoped
		// country-wide by construction (scopeKeyFor()), so a lock would never lift.
		boot( { region: true, address: true } );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'leaves it an ORDINARY input when the provider does not serve the address level', () => {
		// The operator's second condition, read PER LEVEL: no address suggestions means the
		// customer free-types a street, and needs no settlement to do it.
		boot( {
			region: true, settlement: true, address: true,
			levels: { RU: { region: true, settlement: true, address: false } },
		} );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'unlocks on the settlement pick ITSELF, before /select has answered', () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		// The /select round trip is still in flight — the customer must be able to type now.
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( SELECT_URL );
		expect( addressField().disabled ).toBe( false );
	} );

	it( 're-locks when the settlement text is edited without picking a suggestion', () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		expect( addressField().disabled ).toBe( false );

		const city = document.getElementById( 'billing_city' );

		city.value = 'Тверь';
		city.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// The record no longer matches the text, so the address is unscoped again.
		expect( addressField().disabled ).toBe( true );
	} );

	it( 'is ALREADY unlocked on boot when the server restored a settlement record', () => {
		// "Active immediately after a reload, not after some first event nudges it."
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:0c5b2444', level: 'settlement' },
		} );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'stays locked when the restored record is an ADDRESS with no settlement behind it', () => {
		// The pre-#337 state itself: an address picked while no settlement ever was. The chain
		// the server restores names no settlement, so there is still nothing keying the pickup
		// selection — the lock is exactly what stops this state being created again.
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:c0e3b087', level: 'address' },
			chain: { address: { key: 'dadata:c0e3b087', level: 'address' } },
		} );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'becomes an ORDINARY input when the country changes to one with no address coverage', () => {
		boot( {
			region: true, settlement: true, address: true,
			countries: [ 'RU', 'AM' ],
			levels: {
				RU: { region: true, settlement: true, address: true },
				AM: { region: true, settlement: true, address: false },
			},
		} );

		expect( addressField().disabled ).toBe( true );

		const country = document.getElementById( 'billing_country' );

		country.innerHTML += '<option value="AM">Армения</option>';
		country.value = 'AM';
		country.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 're-locks after a country change wipes a settlement that HAD been picked', () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU', 'US' ], levels: {
			RU: { region: true, settlement: true, address: true },
			US: { region: true, settlement: true, address: true },
		} } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		expect( addressField().disabled ).toBe( false );

		const country = document.getElementById( 'billing_country' );

		country.value = 'US';
		country.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 're-applies the lock to the FRESH node after a checkout re-render', () => {
		boot( { region: true, settlement: true, address: true } );

		// WooCommerce replaces the address fragment: a fresh node carrying the server's markup
		// and none of the lock this module put on the one it replaced.
		const fresh = document.createElement( 'input' );
		fresh.type = 'text';
		fresh.id = 'billing_address_1';
		fresh.name = 'billing_address_1';
		addressField().replaceWith( fresh );

		expect( fresh.disabled ).toBe( false );

		window.jQuery( document.body ).trigger( 'updated_checkout' );

		expect( fresh.disabled ).toBe( true );
	} );

	it( 'does not lock a shipping-section field while "ship to a different address" is unchecked', () => {
		// A field hidden behind the toggle is not in play at all — isNodeActive()'s own rule.
		boot( {
			region: true, settlement: true, address: true,
			section: 'shipping', shipToDifferentAddress: false,
		} );

		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( false );
	} );
} );
