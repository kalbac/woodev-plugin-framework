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

const { installFakeSelect2 } = require( './support/fake-select2.js' );

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
 * `opts.mode` (issue #380 — two independent axes, `location.mode = { region,
 * settlement }`, replacing the single shared mode string): a plain STRING
 * applies uniformly to BOTH axes (the pre-#380 shape every existing caller in
 * this file still passes — least-diff backward compatibility), an OBJECT
 * `{ region, settlement }` sets each axis independently (only a test
 * exercising the two axes' independence needs this form).
 *
 * @param {{region?: boolean, settlement?: boolean, address?: boolean, section?: string,
 *          levels?: Object, owners?: Object, countries?: string[], current?: Object|null,
 *          chain?: Object, implicit?: boolean, defaultCountry?: string,
 *          defaultLocality?: Object|null,
 *          mode?: string|{region?: string, settlement?: string}}} opts
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

	const modeOpt = o.mode !== undefined ? o.mode : 'typeahead';
	const modeAxes = ( modeOpt && 'object' === typeof modeOpt )
		? { region: modeOpt.region || 'typeahead', settlement: modeOpt.settlement || 'typeahead' }
		: { region: modeOpt, settlement: modeOpt };

	return {
		fields,
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'test-nonce',
		takeover: {},
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
			nonce: 'test-nonce',
			countries: o.countries || [ 'RU' ],
			// Issue #380: two independent axes — each 'typeahead' | 'related-list' |
			// 'ajax-select2' — instead of one shared mode string (spec D7).
			mode: modeAxes,
			// Issue #528: the merchant opt-in for `ajax-select2` tags — default `false`,
			// matching the store setting's own default (opt-in only).
			allowCustomSettlement: o.allowCustomSettlement !== undefined ? o.allowCustomSettlement : false,
			// Keyed BY COUNTRY, mirroring Checkout_Config::build_location_block(): DaData's
			// coverage is per country (street data for RU/BY/KZ/UZ, city-only elsewhere), so
			// a flat per-level map cannot describe it without lying.
			levels: o.levels || { RU: { region: true, settlement: true, address: true } },
			// Issue #352: keyed BY COUNTRY, same shape as `levels`, but each leaf is a
			// provider id (or `''`) rather than a bool — omitted entirely (not merely
			// `undefined`) unless a test opts in, so "no `owners` key at all" (an older
			// server, or a plugin that builds the location block itself) is exercised as
			// its own real case by every OTHER test in this file, mirroring the `chain`
			// convention just below.
			...( o.owners !== undefined ? { owners: o.owners } : {} ),
			// Issue #530: `{ [country]: Array<{key, label, level, record}> }` — omitted entirely
			// (not merely `undefined`) unless a test opts in, mirroring `owners`/`chain`'s own
			// convention, so "no `popular` key at all" (an older server) is exercised as its own
			// real case by every other test in this file.
			...( o.popular !== undefined ? { popular: o.popular } : {} ),
			current: o.current !== undefined ? o.current : null,
			// Issue #330 (spec §7): `{ level: { key, level } }`, alongside `current` — omitted
			// entirely (not merely `undefined`) unless a test opts in, so "no `chain` key at
			// all" (an older server) is exercised as its own real case, not a stand-in for it.
			...( o.chain !== undefined ? { chain: o.chain } : {} ),
			implicit: o.implicit !== undefined ? o.implicit : false,
			// Issue #536 (spec §4.6/D11 amendment): omitted entirely (not merely
			// `undefined`) unless a test opts in, mirroring `owners`/`chain`/`popular`'s own
			// convention — "no `defaultLocality` key at all" (an older server) is exercised
			// as its own real case by every other test in this file.
			...( o.defaultLocality !== undefined ? { defaultLocality: o.defaultLocality } : {} ),
			// Issue #296: steps 2+3 of the checkout-field -> WC-store-setting -> RU chain,
			// already merged into ONE value server-side by Location_Service::resolve_default_country().
			defaultCountry: o.defaultCountry !== undefined ? o.defaultCountry : 'RU',
			i18n: o.i18n !== undefined ? o.i18n : {
				noResults: 'Поиск не дал результатов. Попробуйте изменить запрос.',
				noResultsAddress: 'Адрес не найден — введите вручную.',
				notPersisted: 'Не удалось сохранить выбор — попробуйте ещё раз.',
				// Issue #405: DISTINCT from noResults/noResultsAddress above — see
				// attachOne()'s own errorText wiring.
				unavailable: 'Источник подсказок недоступен. Попробуйте ещё раз позже или введите вручную.',
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
		const call = {
			el, fetch: opts.fetch, onSelect: opts.onSelect, onAbandon: opts.onAbandon,
			// Issue #541: the seam a renderer uses to announce a pick whose record it does not
			// know yet — see onResolvingFor() in the module under test.
			onResolving: opts.onResolving,
			emptyText: opts.emptyText, errorText: opts.errorText, detach,
		};

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

/**
 * Resolves the most recent `/select` as an ordinary persisted success, and flushes.
 *
 * Since s90 the address level unlocks on the SERVER's confirmation rather than on the click
 * (operator decision reversing #337 — the reasoning is in `isAddressLocked()`'s own docblock),
 * so any flow that goes on to touch a DEEPER level has to get past the round trip first.
 *
 * @param {Object} record The record just picked — its key/level are echoed back as `current`.
 * @returns {Promise<void>}
 */
function settleLastSelect( record ) {
	fetchCalls[ fetchCalls.length - 1 ].resolve( {
		current: { key: record.key, level: record.level },
		persisted: true,
		chain: { [ record.level ]: { key: record.key, level: record.level } },
	} );

	return flushMicrotasks();
}


/**
 * Simulates a customer typing `query` into the field behind `call` and abandoning it — leaving
 * WITHOUT ever picking a suggestion, with a COMPLETED search that resolved to zero results
 * (issue #350). Reproduces the real timing this module's own `handleFieldChanged()` docblock and
 * `location-typeahead.js`'s own ABANDON section both rely on: a real browser fires the native
 * `change` a text edit produces BEFORE the widget's own `blur`-driven decision runs, so the
 * "typed but not picked" clearing this module already does for ANY unpicked edit always happens
 * FIRST, and the widget's `onAbandon()` call (never a value write — unlike
 * {@see selectViaFake}) always lands strictly after it.
 *
 * @param {Object} call
 * @param {string} query
 * @returns {void}
 */
function abandonViaFake( call, query ) {
	call.el.value = query;
	call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

	call.onAbandon( { query, resolved: true } );
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

	it( 'issue #449 (second half): a signal passed as the second fetch() argument reaches the underlying fetch() call\'s own init.signal', () => {
		// This is the seam location-select-modes.js's ajax-select2 transport now uses for real
		// cancellation (see that file's own selectConfigFor() test suite for the transport-side
		// half of this contract) — this test pins the OTHER half: fetchFor()'s returned function
		// actually forwards whatever signal it is given onto the real fetch() call, rather than
		// swallowing it.
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );
		const controller = new AbortController();

		settlementCall.fetch( 'Мос', { signal: controller.signal } );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.init.signal ).toBe( controller.signal );

		controller.abort();

		expect( req.init.signal.aborted ).toBe( true );
	} );

	it( 'issue #449 (second half): omitting opts (the baseline typeahead\'s own call shape) leaves init.signal unset — never a TypeError', () => {
		boot( { settlement: true } );

		const settlementCall = callFor( 'billing_city' );

		expect( () => settlementCall.fetch( 'Мос' ) ).not.toThrow();

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.init.signal ).toBeUndefined();
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

	it( 'shows the server-supplied notPersisted string BELOW the field — last child of the field\'s own parent', async () => {
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

		// Operator rig pass, s90: anchored immediately AFTER the field, the notice renders ABOVE
		// the visible control for `ajax-select2` — select2 leaves the real <select> hidden in
		// place and draws its own container as the next sibling. Last-child of the field's own
		// parent is after every node any renderer drew.
		const host = document.getElementById( 'billing_city' ).parentNode;

		expect( host.lastElementChild ).toBe( notice() );
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
// D7 (spec + plan Seam D, issue #488 slice 3) — the client half of a stale popular-settlement
// pick: `/select` answers HTTP 200 with `cancelled: true` when the posted record named a
// popular-settlement entry whose provider key has died and the server's own adopt search found
// no unambiguous rename to fall back to silently.
// -----------------------------------------------------------------------

// Operator rig pass, s90. A `/select` round trip is 2.4-4.5 SECONDS on the rig (measured: region
// 4527 ms, an ordinary settlement 2391 ms, one needing the D7 provider check 3493 ms) — so the
// field sat showing the customer's pick and then, on the D7 path, emptied itself with an error
// several seconds later. Nothing in between said the form was working: "я уже даже подумал, что
// перестало работать". The busy state is therefore immediate and unconditional; there is no fast
// path here worth protecting from a flicker.
describe( 'the /select busy state (operator rig pass, s90)', () => {
	const ITEM = {
		key: 'dadata:city1', label: 'г Москва', level: 'settlement',
		record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
	};

	function selectRequests() {
		return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
	}

	function spinner() {
		return document.querySelector( '.woodev-location-select-spinner' );
	}

	function host() {
		return document.getElementById( 'billing_city' ).parentNode;
	}

	it( 'marks the field busy the moment the request goes out — spinner, aria-busy and the host class', () => {
		boot( { settlement: true } );

		expect( spinner() ).toBeNull();

		selectViaFake( callFor( 'billing_city' ), ITEM );

		expect( selectRequests().length ).toBe( 1 );

		// The rendered, customer-visible state: a spinner is actually in the document, inside the
		// same host the field lives in.
		expect( spinner() ).not.toBeNull();
		expect( host().contains( spinner() ) ).toBe( true );
		expect( spinner().getAttribute( 'aria-hidden' ) ).toBe( 'true' );

		const field = document.getElementById( 'billing_city' );

		expect( field.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( field.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( host().classList.contains( 'woodev-location-field-busy' ) ).toBe( true );
	} );

	// The wrapper WooCommerce puts around a checkout field is `display: inline`, so the spinner's
	// `top: 0; bottom: 0` resolve against a LINE box sized by line-height, not against the field.
	// Measured on the rig, s90: wrapper 388-418 (30px), the control 382-432 (50px) — centres 403
	// vs 407, and the ring sat 4px high. jsdom has no layout, so the boxes are mocked here; what
	// is pinned is the arithmetic and the guard, not the browser's own numbers.
	it( 'sizes the spinner to the CONTROL it sits on, not to the inline wrapper around it', () => {
		boot( { settlement: true } );

		const field = document.getElementById( 'billing_city' );

		field.parentNode.getBoundingClientRect = () => ( { top: 388, bottom: 418, height: 30 } );
		field.getBoundingClientRect = () => ( { top: 382, bottom: 432, height: 50 } );

		selectViaFake( callFor( 'billing_city' ), ITEM );

		expect( spinner().style.top ).toBe( '-6px' );
		expect( spinner().style.height ).toBe( '50px' );
		expect( spinner().style.bottom ).toBe( 'auto' );
	} );

	it( 'leaves the CSS fallback alone when there is no box to measure — a ring pinned to a zero-height nothing is worse', () => {
		boot( { settlement: true } );

		// jsdom's own default: every rect is zero. The guard must read that as "not laid out".
		selectViaFake( callFor( 'billing_city' ), ITEM );

		expect( spinner().style.top ).toBe( '' );
		expect( spinner().style.height ).toBe( '' );
	} );

	// Operator, s90. Picking a region clears the settlement field (it depends on the region), and
	// the client writes the region optimistically — so until this, the settlement field was free
	// to be searched, scoped by a region key the SERVER had not accepted yet. If that /select then
	// answers `cancelled` (D7) or `persisted: false`, the search was scoped by a key that never
	// existed as far as the server is concerned.
	it( 'locks a level whose PARENT is still in flight, and releases it when the parent answers', async () => {
		boot( { region: true, settlement: true } );

		const regionItem = {
			key: 'dadata:r1', label: 'Московская область', level: 'region',
			record: { key: 'dadata:r1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Московская область', type: 'обл' }, label: 'Московская область' },
		};

		selectViaFake( callFor( 'billing_state' ), regionItem );

		const settlement = document.getElementById( 'billing_city' );

		expect( settlement.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( settlement.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( true );

		// The child is blocked, not emptied of whatever it posts — a disabled control leaves the
		// serialized checkout form, and this one may still hold text the customer typed.
		expect( settlement.disabled ).toBe( false );

		selectRequests()[ 0 ].resolve( {
			current: { key: regionItem.record.key, level: 'region' }, persisted: true,
			chain: { region: { key: regionItem.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		expect( settlement.hasAttribute( 'aria-disabled' ) ).toBe( false );
		expect( settlement.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
	} );

	// Issue #541, the REAL cause. Everything above this point assumes the renderer knows the
	// record at the moment of the pick — true for `ajax-select2`, false for `related-list:region`,
	// which holds only WooCommerce's label text and must match it against `GET /location/list`
	// first. That lookup was MEASURED at 10.5 s on the rig for a cold region, and for all of it
	// `onSelectFor()` had not run, so none of the s90 machinery above had been reached: no
	// spinner, and a settlement field still offering the region the customer had just left.
	describe( 'onResolving — a pick announced before its record is known (#541)', () => {
		it( 'raises the spinner on the picking field and locks the level below it, with no record and no /select', () => {
			boot( { region: true, settlement: true } );

			const region = document.getElementById( 'billing_state' );
			const settlement = document.getElementById( 'billing_city' );

			callFor( 'billing_state' ).onResolving();

			expect( region.parentNode.querySelector( '.woodev-location-select-spinner' ) ).not.toBeNull();
			expect( region.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( true );
			expect( region.getAttribute( 'aria-busy' ) ).toBe( 'true' );

			// The operator's point 3: the settlement list on screen still belongs to the OLD
			// region, so it must stop taking picks — this is the same lock the s90 test above
			// asserts, reached without any record having been named.
			expect( settlement.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
			expect( settlement.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( true );

			// Still blocked rather than emptied — a disabled control leaves the serialized
			// checkout form (measured, s90), and this one may hold text the customer typed.
			expect( settlement.disabled ).toBe( false );

			// Nothing has been ASKED of the server: the record is exactly what is still unknown.
			expect( selectRequests().length ).toBe( 0 );
		} );

		it( 'the marker it raises is NOT the queued kind — a queued one would leave the level below unlocked', () => {
			boot( { region: true, settlement: true } );

			callFor( 'billing_state' ).onResolving();

			// The distinction 85292d1 introduced, asserted from the outside: `queued` means "the
			// request has not left, so it confirms nothing", and hasUnconfirmedParent() ignores
			// it. This marker asserts the thing that IS true — this level's identity is unknown
			// — so it must lock. Passing `true` for queuedOnly here would show the spinner and
			// silently drop the lock, which is the half of #541 the operator actually saw.
			expect( document.getElementById( 'billing_city' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		} );

		it( 'release() clears the marker it raised — the identity search that found nothing leaves no spinner behind', () => {
			boot( { region: true, settlement: true } );

			const region = document.getElementById( 'billing_state' );
			const settlement = document.getElementById( 'billing_city' );
			const release = callFor( 'billing_state' ).onResolving();

			release();

			expect( region.parentNode.querySelector( '.woodev-location-select-spinner' ) ).toBeNull();
			expect( region.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
			expect( region.hasAttribute( 'aria-busy' ) ).toBe( false );
			expect( settlement.hasAttribute( 'aria-disabled' ) ).toBe( false );
		} );

		it( 'release() stands down when a REAL pick has since taken the field over — never strands the newer owner', async () => {
			boot( { region: true, settlement: true } );

			const release = callFor( 'billing_state' ).onResolving();

			const regionItem = {
				key: 'dadata:r1', label: 'Московская область', level: 'region',
				record: { key: 'dadata:r1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Московская область', type: 'обл' }, label: 'Московская область' },
			};

			selectViaFake( callFor( 'billing_state' ), regionItem );

			// This is the ordinary sequence on the match path: the renderer calls release()
			// unconditionally right after onSelect, and the marker standing now belongs to the
			// in-flight /select. Clearing it here would take the spinner away for the whole
			// round trip and unlock the settlement under an unconfirmed region.
			release();

			const region = document.getElementById( 'billing_state' );

			expect( selectRequests().length ).toBe( 1 );
			expect( region.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( true );
			expect( document.getElementById( 'billing_city' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );

			// And the real owner still clears normally when its own answer lands.
			selectRequests()[ 0 ].resolve( {
				current: { key: regionItem.record.key, level: 'region' }, persisted: true,
				chain: { region: { key: regionItem.record.key, level: 'region' } },
			} );
			await flushMicrotasks();

			expect( region.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
			expect( document.getElementById( 'billing_city' ).hasAttribute( 'aria-disabled' ) ).toBe( false );
		} );

		it( 'a second release() is inert — the token is spent, not re-usable against a later marker', () => {
			boot( { region: true, settlement: true } );

			const release = callFor( 'billing_state' ).onResolving();

			release();

			// A fresh announcement, e.g. the customer picking a second region while the first
			// lookup was still running.
			callFor( 'billing_state' ).onResolving();

			release();

			expect( document.getElementById( 'billing_state' ).parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( true );
			expect( document.getElementById( 'billing_city' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		} );
	} );

	// Operator's own constraint, s90, and the reason the lock is keyed on an IN-FLIGHT request
	// rather than on "the parent has no record yet": with no region picked, the customer is free
	// to fill the settlement directly and let backwardsFill() write the region for them. Locking
	// on absence would break that ordinary path outright. `enqueueSelect()` has exactly one call
	// site — a real pick in `onSelectFor()` — so nothing else can ever raise this lock.
	it( 'does NOT lock the settlement when no region was picked — the customer may fill it directly and let the region backfill', () => {
		boot( { region: true, settlement: true } );

		const settlement = document.getElementById( 'billing_city' );

		expect( settlement.hasAttribute( 'aria-disabled' ) ).toBe( false );
		expect( settlement.parentNode.classList.contains( 'woodev-location-field-busy' ) ).toBe( false );

		// Typing in it is what the customer does next, and nothing here may stand in the way:
		// the region field is still empty and stays that way until backwardsFill() writes it.
		expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
		expect( settlement.disabled ).toBe( false );
	} );

	it( 'releases a child lock even when the parent\'s request FAILS — a lock outliving its cause is worse than no lock', async () => {
		boot( { region: true, settlement: true } );

		const regionItem = {
			key: 'dadata:r1', label: 'Московская область', level: 'region',
			record: { key: 'dadata:r1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Московская область', type: 'обл' }, label: 'Московская область' },
		};

		const logged = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		selectViaFake( callFor( 'billing_state' ), regionItem );
		expect( document.getElementById( 'billing_city' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );

		selectRequests()[ 0 ].reject( new Error( 'network down' ) );
		await flushMicrotasks();
		logged.mockRestore();

		expect( document.getElementById( 'billing_city' ).hasAttribute( 'aria-disabled' ) ).toBe( false );
	} );

	it( 'never uses the disabled ATTRIBUTE — measured: a disabled control leaves the serialized checkout form entirely', () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), ITEM );

		expect( document.getElementById( 'billing_city' ).disabled ).toBe( false );
	} );

	it( 'applies readonly where the element actually supports it, and takes it back off', () => {
		boot( { settlement: true } );

		const field = document.getElementById( 'billing_city' );
		const supportsReadOnly = 'readOnly' in field;

		selectViaFake( callFor( 'billing_city' ), ITEM );

		if ( supportsReadOnly ) {
			expect( field.readOnly ).toBe( true );
		}

		selectRequests()[ 0 ].resolve( { current: { key: ITEM.record.key, level: 'settlement' }, persisted: true } );

		return flushMicrotasks().then( () => {
			expect( field.readOnly ).toBe( false );
		} );
	} );

	it( 'clears the busy state on a PERSISTED response', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), ITEM );
		selectRequests()[ 0 ].resolve( { current: { key: ITEM.record.key, level: 'settlement' }, persisted: true } );
		await flushMicrotasks();

		expect( spinner() ).toBeNull();
		expect( document.getElementById( 'billing_city' ).hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( host().classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
	} );

	it( 'clears the busy state on a CANCELLED response — the field must not be left spinning under the error', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), ITEM );
		selectRequests()[ 0 ].resolve( {
			cancelled: true, reason: 'stale_record', message: 'Данные не актуальны, выберите заново',
			current: null, persisted: false, chain: {},
		} );
		await flushMicrotasks();

		expect( spinner() ).toBeNull();
		expect( host().classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
	} );

	it( 'clears the busy state when the request FAILS outright — a spinner surviving a transport error would spin forever', async () => {
		boot( { settlement: true } );

		// The module logs the transport failure by design (logError); silenced so a genuinely
		// unexpected console.error elsewhere in this suite still stands out.
		const logged = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		selectViaFake( callFor( 'billing_city' ), ITEM );
		selectRequests()[ 0 ].reject( new Error( 'network down' ) );
		await flushMicrotasks();

		expect( logged ).toHaveBeenCalled();
		logged.mockRestore();

		expect( spinner() ).toBeNull();
		expect( document.getElementById( 'billing_city' ).hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( host().classList.contains( 'woodev-location-field-busy' ) ).toBe( false );
	} );
} );

describe( 'D7 — a cancelled /select response (stale popular-settlement pick, issue #488 slice 3)', () => {
	const MESSAGE = 'Данные не актуальны, выберите заново';

	const SETTLEMENT_ITEM = {
		key: 'dadata:dead', label: 'Старое Место', level: 'settlement',
		record: {
			key: 'dadata:dead', provider_id: 'dadata', level: 'settlement', country: 'RU',
			settlement: { name: 'Старое Место', type: 'дер' }, label: 'Старое Место',
		},
	};

	function notice() {
		return document.querySelector( '.woodev-location-notice' );
	}

	function selectRequests() {
		return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
	}

	function cancelledBody( chain ) {
		return {
			cancelled: true, reason: 'stale_record', message: MESSAGE,
			current: null, persisted: false, chain: chain || {},
		};
	}

	it( 'clears the field\'s value, leaving it genuinely empty (not a stale label)', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Старое Место' );

		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
	} );

	it( 'shows response.message in the SAME reusable notice surface #295\'s not-persisted case uses — the rendered DOM text, not a captured variable', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		expect( notice() ).not.toBeNull();
		expect( notice().textContent ).toBe( MESSAGE );
		expect( notice().getAttribute( 'role' ) ).toBe( 'alert' );

		const host = document.getElementById( 'billing_city' ).parentNode;

		expect( host.lastElementChild ).toBe( notice() );
	} );

	// Operator rig pass, s90. The message alone was not enough: it rendered above the settlement
	// field (so it read as belonging to the region), at the theme's own body size, with nothing
	// marking it as an error and nothing marking the field. These three assertions pin the shape
	// the fix gives it — placement is pinned by the test above.
	it( 'presents the cancel as an ERROR: a warning mark, an error class on the field\'s host, and the message in its own text node', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		const host = document.getElementById( 'billing_city' ).parentNode;

		expect( host.classList.contains( 'woodev-location-field-error' ) ).toBe( true );
		expect( notice().querySelector( '.woodev-location-notice__icon' ) ).not.toBeNull();
		expect( notice().querySelector( '.woodev-location-notice__icon' ).getAttribute( 'aria-hidden' ) ).toBe( 'true' );

		// The server-supplied string goes in as text, never as markup — the icon is the only
		// thing this module authors as HTML.
		const label = notice().querySelector( '.woodev-location-notice__text' );

		expect( label ).not.toBeNull();
		expect( label.textContent ).toBe( MESSAGE );
	} );

	it( 'takes the error outline off the field when the notice goes, not just the text', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		const host = document.getElementById( 'billing_city' ).parentNode;

		expect( host.classList.contains( 'woodev-location-field-error' ) ).toBe( true );

		// The recovery the message asks for: pick again, and this time the server persists it.
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ selectRequests().length - 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		expect( notice() ).toBeNull();
		expect( host.classList.contains( 'woodev-location-field-error' ) ).toBe( false );
	} );

	it( 're-locks the address field on top of the cleared settlement, exactly like an ordinary drop (#337)', async () => {
		boot( { settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		// s90: the address stays locked for the length of the round trip — and this is exactly
		// the case that reversed #337. Under the old rule the customer could have been typing a
		// street here for 2.4-4.5 seconds, into a field about to be re-locked underneath them by
		// the very answer this test then delivers.
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( true );

		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		expect( document.getElementById( 'billing_address_1' ).classList.contains( 'woodev-location-locked' ) ).toBe( true );
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( true );
	} );

	it( 'is NOT a transport error — no retry is ever sent for a cancelled response', async () => {
		boot( { settlement: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		expect( selectRequests().length ).toBe( 1 );
	} );

	it( 'does not silently swallow the outcome — update_checkout still fires, and woodev_location_applied fires with the empty/unknown sentinel', async () => {
		boot( { settlement: true } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const seen = [];

		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( true );
		expect( seen ).toEqual( [ { key: '', level: '', settlementKey: '', implicit: false } ] );

		triggerSpy.mockRestore();
	} );

	it( 'still adopts the response\'s own chain for a level the cancel never touched — deeper/other levels follow the chain exactly as an ordinary response would', async () => {
		boot( { region: true, settlement: true } );

		selectViaFake( callFor( 'billing_state' ), {
			key: 'dadata:r1', label: 'Московская область', level: 'region',
			record: { key: 'dadata:r1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Московская область', type: 'обл' }, label: 'Московская область' },
		} );
		selectRequests()[ 0 ].resolve( { current: { key: 'dadata:r1', level: 'region' }, persisted: true, chain: { region: { key: 'dadata:r1', level: 'region' } } } );
		await flushMicrotasks();

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		// "the server's chain as it stands — unchanged by this request" (D7): region survives,
		// named exactly as it stood before this failed settlement pick.
		selectRequests()[ 1 ].resolve( cancelledBody( { region: { key: 'dadata:r1', level: 'region' } } ) );
		await flushMicrotasks();

		// scopeKeyFor() reads entry.records.region for the NEXT settlement suggest call — this
		// only stays 'dadata:r1' if adoptChain() ran for the cancelled response exactly as it
		// would for any other one.
		callFor( 'billing_city' ).fetch( 'Балаш' );
		const req = fetchCalls[ fetchCalls.length - 1 ];

		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:r1' ) );
	} );

	// -------------------------------------------------------------------
	// Issue #529: `related-list:settlement` is gone (the settlement axis never offers
	// `related-list` — operator decision 24.08.2026, issue #486). The two tests below were
	// never really ABOUT that renderer — Codex round 2 already said so: they exist to pin
	// `applyValueToElement()`'s synthetic-option branch and `resolveAndSelect()`'s
	// `lastHandledKey` guard (location-select-modes.js's shared `buildSelectField()`) against a
	// REAL DOM-replacing widget, not `boot()`'s `fakeTypeahead()` stand-in. Both mechanisms are
	// shared with `ajax-select2`, so this round ports them to boot that renderer for real
	// instead, driving the pick through `resolveAndSelect()`'s OTHER caller —
	// `handleSelect2Select()`, which resolves by `.key` off the select2 event payload, never the
	// DOM `<option>`'s own dataset (see that function's own docblock) — via
	// `support/fake-select2.js`'s `pick()`.
	// -------------------------------------------------------------------

	/**
	 * Boots `location-cascade.js` against the REAL `location-select-modes.js` `ajax-select2`
	 * settlement renderer, with the REAL fake-select2 instrumentation installed — mirrors
	 * `bootWithRealSelectModes()` in the `issue #517` describe block further down this file
	 * (duplicated locally per this file's own convention: each describe owns its boot helper).
	 *
	 * @returns {Array<Object>} the fake-select2 `instances` array (see `support/fake-select2.js`).
	 */
	function bootRealAjaxSelect2Settlement() {
		installMarkup( { settlement: true }, 'RU' );

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		const instances = installFakeSelect2( window.jQuery );

		window[ CONFIG_GLOBAL ] = buildConfig( { settlement: true, mode: { settlement: 'ajax-select2' } } );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		return instances;
	}

	/**
	 * Boots, searches for `SETTLEMENT_ITEM`, resolves the `/suggest` request with it as the ONLY
	 * result, and picks it through the fake's `pick()` — the `ajax-select2` analog of the removed
	 * `attachAndPopulateRelatedList()`/`pickByKey()` pair. `query.success` may be called TWICE
	 * (once synchronously with the empty/narrowed local state — settlement always carries a
	 * `popular()` primitive, issue #530 — and once for real once the `/suggest` fetch resolves),
	 * so this reads the LAST call, never the first, for the actually-fetched result.
	 *
	 * @returns {Promise<{select: Element, resultItem: {id: string, text: string, key: string}, instance: Object}>}
	 */
	async function attachAndPickAjaxSelect2() {
		const instances = bootRealAjaxSelect2Settlement();
		const select = document.getElementById( 'billing_city' );

		expect( select.tagName ).toBe( 'SELECT' );

		const query = instances[ 0 ].query( SETTLEMENT_ITEM.label );

		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [ SETTLEMENT_ITEM ] } );
		await flushMicrotasks();

		const calls = query.success.mock.calls;
		const resultItem = calls[ calls.length - 1 ][ 0 ].results[ 0 ];

		instances[ 0 ].pick( resultItem );
		// Exactly ONE /select request for ONE dispatched pick — the native+jQuery double-fire
		// `resolveAndSelect()`'s guard exists for was already exercised here (the fake's own
		// `pick()` dispatches jQuery `change` — see its own docblock — which reaches
		// `handleChange()`'s native/no-select2 path too, but that path no-ops on an
		// `<option>` carrying no `dataset.woodevKey`, exactly what a real select2-rendered
		// option never carries either).
		expect( selectRequests().length ).toBe( 1 );

		return { select, resultItem, instance: instances[ 0 ] };
	}

	it( 'clears a REAL ajax-select2 <select> to no selection on a cancel, leaving the real option behind unmarked as synthetic', async () => {
		const { select, resultItem } = await attachAndPickAjaxSelect2();

		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		// The rendered, customer-visible state: nothing selected, and the ONE real entry the
		// pick produced is still the only option — no phantom empty row a customer opening this
		// dropdown would ever have to explain, and never re-marked as the synthetic placeholder
		// applyValueToElement()'s own third branch would otherwise leave behind.
		expect( select.selectedIndex ).toBe( -1 );
		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( String( resultItem.id ) );
		expect( select.options[ 0 ].hasAttribute( 'data-woodev-location-synthetic' ) ).toBe( false );
	} );

	it( 'lets the customer re-pick the SAME still-rendered entry after a cancel — /select fires again, not a silent no-op', async () => {
		const { resultItem, instance } = await attachAndPickAjaxSelect2();

		selectRequests()[ 0 ].resolve( cancelledBody() );
		await flushMicrotasks();

		// The exact recovery the cancel notice instructs the customer to take: click the SAME
		// still-rendered entry again. Before this fix, resolveAndSelect()'s lastHandledKey guard
		// silently ate this — no second /select request ever went out, and the field stayed
		// empty forever no matter how many times the customer repeated the instruction.
		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 2 );
	} );

	describe( 'single-flight queue interaction (gotcha a-shared-select-queue-narrows-a-level-its-response-never-named)', () => {
		it( 'a cancelled response for one level still clears/notices THAT level even while a DIFFERENT level is already queued behind it', async () => {
			boot( { region: true, settlement: true } );

			const regionItem = {
				key: 'dadata:r1', label: 'Московская область', level: 'region',
				record: { key: 'dadata:r1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Московская область', type: 'обл' }, label: 'Московская область' },
			};

			selectViaFake( callFor( 'billing_state' ), regionItem );
			expect( selectRequests().length ).toBe( 1 );

			// Settlement is picked right behind it — region's own /select has not resolved yet,
			// so this queues behind the single-flight slot instead of racing it.
			selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
			expect( selectRequests().length ).toBe( 1 ); // still just region's request

			// Region's own pick turns out stale.
			selectRequests()[ 0 ].resolve( cancelledBody() );
			await flushMicrotasks();

			// Region — the level THIS response actually answered for — is cleared and noticed,
			// even though something else (settlement) was already queued.
			expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
			expect( notice() ).not.toBeNull();
			expect( notice().textContent ).toBe( MESSAGE );

			// Settlement's own optimistic write survives untouched — it is a DIFFERENT level,
			// never in this response's scope.
			expect( document.getElementById( 'billing_city' ).value ).toBe( 'Старое Место' );

			// settleSelect() dequeues the pending settlement record and sends it automatically.
			expect( selectRequests().length ).toBe( 2 );
			expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: SETTLEMENT_ITEM.record } );
		} );

		it( 'a cancelled response is skipped entirely for its OWN level when a NEWER pick for that SAME level is already queued', async () => {
			boot( { settlement: true } );

			const first = SETTLEMENT_ITEM;
			const second = {
				key: 'dadata:alive', label: 'Новое Место', level: 'settlement',
				record: { key: 'dadata:alive', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Новое Место', type: 'дер' }, label: 'Новое Место' },
			};

			selectViaFake( callFor( 'billing_city' ), first );
			selectViaFake( callFor( 'billing_city' ), second ); // queued — first's /select is still in flight
			expect( selectRequests().length ).toBe( 1 );

			selectRequests()[ 0 ].resolve( cancelledBody() );
			await flushMicrotasks();

			// second's own optimistic write must survive — first's cancellation must not touch
			// the SAME level a newer, not-yet-sent pick already owns.
			expect( document.getElementById( 'billing_city' ).value ).toBe( 'Новое Место' );
			expect( notice() ).toBeNull(); // nothing shown for a response superseded before it landed

			// second is dequeued and sent automatically.
			expect( selectRequests().length ).toBe( 2 );
			expect( JSON.parse( selectRequests()[ 1 ].init.body ) ).toEqual( { record: second.record } );

			// second settles ordinarily — nothing about the cancelled first pick lingers.
			selectRequests()[ 1 ].resolve( { current: { key: second.record.key, level: 'settlement' }, persisted: true, chain: { settlement: { key: second.record.key, level: 'settlement' } } } );
			await flushMicrotasks();

			expect( document.getElementById( 'billing_city' ).value ).toBe( 'Новое Место' );
		} );
	} );
} );

// -----------------------------------------------------------------------
// Issue #488 slice 3 round 3 (Codex re-review): resetWidgetGuard() must run on EVERY route that
// overwrites a select2 widget's DOM value out from under it — not only the D7 cancel path
// clearChainField() already covers above. Enumerated by grepping this file for every literal
// `applyValueToElement( el, '' )` call: clearChainField() (already covered by the D7 suite
// above), clearDescendants() (an ordinary ANCESTOR text edit — no country involved), and
// clearCountryScope() (a country change). Both of the latter two are exercised here, each
// through a REAL Task 13 widget, never a hand-rolled stand-in — same standard as the D7 suite's
// own round-2 rewrite. Issue #529 ported this whole describe from the removed
// `related-list:settlement` renderer to `ajax-select2` — see the D7 suite's own port comment
// above for why that renderer was never really what these tests are about.
//
// Falsified first, per the operator's own instruction, rather than assumed: `applyCountryArbitration()`
// (:2689-2700) only calls `detachOne()` when a node's `isNodeActive()` flips to false — a country
// change that leaves a level served under the NEW country too leaves `attached && active` both
// true, so NEITHER branch fires and the SAME widget instance (and its stale `lastHandledKey`
// closure) stays attached. And `clearDescendants()`'s only caller, `handleFieldChanged()`'s
// ordinary (non-country) branch (:3293-3332), never calls `attachOne()`/`detachOne()`/
// `applyCountryArbitration()` at all. Neither route gets a fresh widget for free.
// -----------------------------------------------------------------------

describe( 'resetWidgetGuard() on the OTHER two clearing routes, against the REAL Task 13 widget (issue #488 slice 3 round 3)', () => {
	const SETTLEMENT_ITEM = {
		key: 'dadata:dead', label: 'Старое Место', level: 'settlement',
		record: {
			key: 'dadata:dead', provider_id: 'dadata', level: 'settlement', country: 'RU',
			settlement: { name: 'Старое Место', type: 'дер' }, label: 'Старое Место',
		},
	};

	function selectRequests() {
		return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
	}

	/**
	 * Boots `location-cascade.js` against the REAL `location-select-modes.js` `ajax-select2`
	 * settlement renderer, with the REAL fake-select2 instrumentation installed — see the D7
	 * suite's own `bootRealAjaxSelect2Settlement()` above for the identical shape (duplicated
	 * locally per this file's own convention: each describe owns its boot helper).
	 *
	 * @param {Object} configOpts merged into `buildConfig()`/`installMarkup()` — a test opts
	 *   into `region: true` or a multi-country `levels` map as its own scenario needs.
	 * @returns {Array<Object>} the fake-select2 `instances` array (see `support/fake-select2.js`).
	 */
	function bootRealAjaxSelect2Settlement( configOpts ) {
		installMarkup( Object.assign( { settlement: true }, configOpts ), 'RU' );

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		const instances = installFakeSelect2( window.jQuery );

		window[ CONFIG_GLOBAL ] = buildConfig( Object.assign(
			{ settlement: true, mode: { settlement: 'ajax-select2' } },
			configOpts
		) );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		return instances;
	}

	/**
	 * Boots, searches for and picks `SETTLEMENT_ITEM` through the fake's `pick()`, and lets that
	 * first `/select` succeed ordinarily — the baseline state every test below starts its OWN
	 * clearing route from. `query.success` may be called TWICE (see the D7 suite's own
	 * `attachAndPickAjaxSelect2()` docblock above) — this reads the LAST call.
	 *
	 * @param {Object} configOpts
	 * @returns {Promise<{select: Element, resultItem: {id: string, text: string, key: string}, instance: Object}>}
	 */
	async function attachPopulateAndPick( configOpts ) {
		const instances = bootRealAjaxSelect2Settlement( configOpts );
		const select = document.getElementById( 'billing_city' );

		expect( select.tagName ).toBe( 'SELECT' );

		const query = instances[ 0 ].query( SETTLEMENT_ITEM.label );

		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [ SETTLEMENT_ITEM ] } );
		await flushMicrotasks();

		const calls = query.success.mock.calls;
		const resultItem = calls[ calls.length - 1 ][ 0 ].results[ 0 ];

		instances[ 0 ].pick( resultItem );
		expect( selectRequests().length ).toBe( 1 );

		selectRequests()[ 0 ].resolve( {
			current: { key: SETTLEMENT_ITEM.key, level: 'settlement' }, persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		return { select, resultItem, instance: instances[ 0 ] };
	}

	it( 'an ancestor (region) text edit — clearDescendants() — lets the SAME still-rendered settlement entry be re-picked', async () => {
		const { select, resultItem, instance } = await attachPopulateAndPick( { region: true } );

		// An ORDINARY customer edit on the ANCESTOR level — never a pick — which
		// handleFieldChanged() routes straight to clearDescendants(), never through
		// applyCountryArbitration()/attachOne()/detachOne() at all.
		const region = document.getElementById( 'billing_state' );

		region.value = 'Татарстан';
		region.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// The rendered, customer-visible state: nothing selected any more.
		expect( select.selectedIndex ).toBe( -1 );

		// The exact recovery: re-pick the SAME still-rendered settlement entry. Before this fix,
		// resolveAndSelect()'s lastHandledKey guard — never reset by clearDescendants() — silently
		// ate this: no second /select request, the field stayed empty forever.
		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 2 );
	} );

	it( 'a country change to another country that ALSO serves this level — clearCountryScope() — lets the SAME still-rendered entry be re-picked', async () => {
		// levels for BOTH countries — isNodeActive() stays true across the switch, so
		// applyCountryArbitration() neither detaches nor re-attaches: the SAME widget instance
		// (and its own lastHandledKey closure) survives, exactly the falsification this test
		// pins per the operator's own instruction.
		const { select, resultItem, instance } = await attachPopulateAndPick( {
			countries: [ 'RU', 'US' ],
			levels: { RU: { settlement: true }, US: { settlement: true } },
		} );

		const country = document.getElementById( 'billing_country' );

		country.value = 'US';
		country.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( select.selectedIndex ).toBe( -1 );

		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 2 );
	} );

	// The fourth clearing route, and the one the round-3 enumeration missed: it does not go
	// through `applyValueToElement( el, '' )` at all, so grepping for that literal could never
	// find it. `pickup-mount.js`'s `applyAddressReplacement()` coerces an absent
	// `point.locality` to `''` and announces it as `{target}_city` one synchronous event before
	// writing (issue #339), and `handlePickupAddressReplacing()` puts that blank through
	// `writeSilently()`. Hence the fix sits in `writeSilently()` itself rather than at a fourth
	// call site.
	it( 'a pickup point with NO locality — an EMPTY silent write — lets the SAME still-rendered entry be re-picked', async () => {
		const { select, resultItem, instance } = await attachPopulateAndPick();

		// Exactly what applyAddressReplacement() sends for a point whose `locality` is absent:
		// `'' === point.locality ? ... : ''` reaches the announcement as a real empty string.
		document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_address_replacing', {
			detail: { fields: { billing_city: '' } },
			bubbles: true,
		} ) );

		// The rendered, customer-visible state: the field shows nothing selected.
		expect( select.selectedIndex ).toBe( -1 );

		// The recovery a customer would actually attempt. Before the writeSilently() fix,
		// resolveAndSelect()'s lastHandledKey still held this exact key and ate the re-pick:
		// one /select total, the settlement record gone, the address field locked.
		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 2 );
	} );

	// Round 4 (Codex): an empty write is NOT the only way a silent write can strand the guard,
	// and this is the case that disproved the first attempt at this fix. The SAME pickup path
	// deliberately writes a DIFFERENT non-empty spelling when it has one — the carrier answers
	// «Москва» where the provider said «Moscow» (gotcha
	// `a-locality-display-name-is-not-an-identifier`), and a point may legitimately stand in a
	// neighbouring settlement, which is exactly why `applyAddressReplacement()` re-seeds rather
	// than suppresses. `resolveAndSelect()` compares only the provider KEY, so a changed
	// spelling leaves it just as stale as a blank does.
	it( 'a pickup point with a DIFFERENT locality spelling — a non-empty silent write — also lets the SAME entry be re-picked', async () => {
		const { select, resultItem, instance } = await attachPopulateAndPick();

		document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_address_replacing', {
			detail: { fields: { billing_city: 'Москва' } },
			bubbles: true,
		} ) );

		// The field now carries the point's own locality, not the picked entry's.
		expect( select.value ).toBe( 'Москва' );

		// The customer puts their own settlement back. Under an empty-only release this was
		// swallowed: no second /select, and handleFieldChanged() then read the text change as a
		// manual edit and dropped the confirmed record, re-locking the address.
		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 2 );
	} );

	// The other half of the rule, so a later change cannot "simplify" it into resetting on EVERY
	// silent write: a write that does not CHANGE the field must leave the guard alone. It still
	// tells the truth about what is on screen, and re-picking that entry really is the duplicate
	// delivery the guard exists to eat (issue #461 BLOCKING 2 — one pick must not fire across
	// both the select2 and the native path).
	it( 'a silent write of the SAME text leaves the guard alone — the entry is still treated as already handled', async () => {
		const { resultItem, instance } = await attachPopulateAndPick();

		document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_address_replacing', {
			detail: { fields: { billing_city: 'Старое Место' } },
			bubbles: true,
		} ) );

		instance.pick( resultItem );

		expect( selectRequests().length ).toBe( 1 );
	} );
} );

// -----------------------------------------------------------------------
// D7 Seam D, last paragraph — the client adopts the SERVER's key when an ordinary (non-
// cancelled) response's `current.key` differs from what it posted (D6 "updated" / D7's own
// silent adopt both persist a DIFFERENT record than the one the customer picked).
// -----------------------------------------------------------------------

describe( 'an ordinary /select response whose current.key differs from the posted key (D6/D7 adopt)', () => {
	it( 'publishes the SERVER\'s key on woodev_location_applied, not the client\'s own posted one', async () => {
		boot( { settlement: true } );

		const seen = [];

		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		const item = {
			key: 'dadata:old', label: 'Старое Название', level: 'settlement',
			record: { key: 'dadata:old', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Старое Название', type: 'дер' }, label: 'Старое Название' },
		};

		selectViaFake( callFor( 'billing_city' ), item );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];

		// The server persisted a DIFFERENT record than the one posted — a renamed popular
		// settlement (D6 "updated") or a D7 step 2 silent adopt.
		selectReq.resolve( {
			current: { key: 'dadata:new', level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: 'dadata:new', level: 'settlement' } },
		} );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'dadata:new', level: 'settlement', settlementKey: 'dadata:new', implicit: false } ] );
	} );

	it( 'keeps the client\'s own posted key when current.key matches it (the ordinary, overwhelmingly common case)', async () => {
		boot( { settlement: true } );

		const seen = [];

		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		const item = {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
		};

		selectViaFake( callFor( 'billing_city' ), item );
		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:city1', level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: 'dadata:city1', level: 'settlement' } },
		} );
		await flushMicrotasks();

		expect( seen ).toEqual( [ { key: 'dadata:city1', level: 'settlement', settlementKey: 'dadata:city1', implicit: false } ] );
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

	/**
	 * Issue #465, symptom B (rig, s86): picking a region manually does not visually clear the
	 * settlement. `city.value` DID clear (the assertion above already pins that) — the WIDGET
	 * kept showing the old text because `clearDescendants()` used to write `el.value = ''`
	 * DIRECTLY, bypassing {@see applyValueToElement} entirely — the same silent-write path
	 * {@see writeSilently} already goes through for every OTHER silent write in this module. A
	 * select2-enhanced descendant therefore never got the `change.select2` nudge
	 * {@see refreshSelectWooWidget} sends, exactly the display-vs-value gap issue #462 round 2
	 * closed for backwards-fill.
	 */
	it( 'refreshes a select2-enhanced descendant\'s WIDGET (not just .value) when it is silently cleared (issue #465, symptom B)', () => {
		bootFilled();

		// Simulates the mode renderer having already swapped the settlement <input> for a
		// select2-enhanced <select> carrying the customer's current pick — same shape
		// applyValueToElement() itself would have produced via its synthetic-option path.
		const cityInput = document.getElementById( 'billing_city' );
		const citySelect = document.createElement( 'select' );
		const cityOption = document.createElement( 'option' );

		cityOption.value = 'Москва';
		cityOption.textContent = 'Москва';
		cityOption.selected = true;
		citySelect.appendChild( cityOption );
		citySelect.id = cityInput.id;
		citySelect.name = cityInput.name;
		cityInput.parentNode.replaceChild( citySelect, cityInput );

		const cityEl = document.getElementById( 'billing_city' );
		const widgetRefreshSpy = jest.fn();
		const gateSpyNative = jest.fn();
		const gateSpyJquery = jest.fn();

		window.jQuery( cityEl ).on( 'change.select2', widgetRefreshSpy );
		document.body.addEventListener( 'change', gateSpyNative );
		window.jQuery( document.body ).on( 'change', gateSpyJquery );

		// A genuine region transition — clearDescendants() clears settlement/address/postcode.
		document.getElementById( 'billing_state' ).value = 'г Санкт-Петербург';
		document.getElementById( 'billing_state' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( cityEl.value ).toBe( '' ); // unchanged behaviour: the clear itself still works.
		// THE FIX: the widget must have been told to re-render...
		expect( widgetRefreshSpy ).toHaveBeenCalledTimes( 1 );
		// ...WITHOUT the clear reading as a customer-driven edit to this module's own change-gate
		// (a plain, unnamespaced `change`) — exactly the silence writeSilently() already
		// guarantees for backwards-fill; a clear that tripped the gate would run a SECOND,
		// destructive cascade off of clearing the settlement itself.
		expect( gateSpyNative.mock.calls.map( ( call ) => call[ 0 ].target ) ).not.toContain( cityEl );
		expect( gateSpyJquery.mock.calls.map( ( call ) => call[ 0 ].target ) ).not.toContain( cityEl );
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

	/**
	 * Issue #352 (Variant A) — the measured bug's field-text half: a mixed provider chain
	 * (the active provider owning `region`/`settlement`, the bundled DaData fallback owning
	 * `address` alone) used to let picking an address overwrite the settlement/region text a
	 * DIFFERENT provider had already written, with that OTHER provider's own — possibly
	 * differently-spelled — component. A level with NO known owner still fills exactly as
	 * before this fix: nobody owns it, so nothing is being clobbered.
	 */
	it( 'skips a foreign-owned ancestor while still filling an unowned one', () => {
		boot( {
			region: true, settlement: true, address: true,
			// region is owned by the (different) active provider; settlement has no known
			// owner in this fixture at all.
			owners: { RU: { region: 'test-cdek', settlement: '', address: 'dadata' } },
		} );

		// The customer's own settlement pick, in the active provider's own Cyrillic spelling —
		// this must survive untouched.
		document.getElementById( 'billing_state' ).value = 'Московская область';

		const addressCall = callFor( 'billing_address_1' );
		const item = {
			key: 'dadata:addr1', label: 'Moscow, Tverskaya st, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				// DaData's OWN region component, under an English-locale account — exactly the
				// spelling that must never overwrite the field above.
				region: { name: 'Moscow Oblast', type: '' },
				settlement: { name: 'Moscow', type: '' },
				postcode: '101000',
				label: 'Moscow, Tverskaya st, 1',
			},
		};

		selectViaFake( addressCall, item );

		// region is owned by 'test-cdek' ≠ the record's own 'dadata' — left untouched.
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская область' );
		// settlement has NO known owner — still filled from the record, unchanged behaviour.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Moscow' );
		// postcode is never gated by ownership — it is not a chain level.
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );
	} );

	/**
	 * Issue #352's LITERAL reported defect, under the REAL rig owner map (the active
	 * `test-cdek` provider owning `region`+`settlement`, the bundled DaData fallback owning
	 * `address` alone). The operator measured `city = "Москва"` becoming `city = "Moscow"` and
	 * `state = "Москва"` becoming `state = "Moscow"` the moment an address suggestion was
	 * picked: the rig account's locale is English, so DaData answers transliterated while the
	 * carrier answers Cyrillic (gotcha `a-locality-display-name-is-not-an-identifier`).
	 *
	 * The test above pins the UNOWNED half (a level nobody owns still fills). This one pins the
	 * OWNED half, which is the half that was actually broken — without it the suite passes with
	 * `settlement` left writable by any provider.
	 */
	it( 'leaves a foreign-owned settlement AND region untouched — the measured «Москва» → «Moscow» defect', () => {
		boot( {
			region: true, settlement: true, address: true,
			owners: { RU: { region: 'test-cdek', settlement: 'test-cdek', address: 'dadata' } },
		} );

		// What the customer picked from the CDEK-backed provider, in its own Cyrillic spelling.
		document.getElementById( 'billing_state' ).value = 'Москва';
		document.getElementById( 'billing_city' ).value = 'Москва';

		selectViaFake( callFor( 'billing_address_1' ), {
			key: 'dadata:addr1', label: 'Moscow, Tverskaya st, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				region: { name: 'Moscow', type: '' },
				settlement: { name: 'Moscow', type: '' },
				postcode: '101000',
				label: 'Moscow, Tverskaya st, 1',
			},
		} );

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		// Still filled: postcode is not a chain level and is never gated by ownership.
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );
	} );

	/**
	 * Issue #460 (measured on the rig, s86: `region mode: ajax-select2`). A mode-specific
	 * renderer (`location-select-modes.js`, not this module) replaces the region `<input>`
	 * with a `<select>` — under `ajax-select2` it starts with NO `<option>` elements at all
	 * (see `buildSelectField()`'s own docblock: "nothing is pre-populated"). Before this fix,
	 * `backwardsFill()`'s plain `el.value = ...` selected NOTHING on such a field
	 * (`selectedIndex` stays `-1`), so the region silently never reached WooCommerce and the
	 * store kept its `RU:*` "no state" default across a reload — exactly the reported symptom.
	 */
	it( 'creates and selects a synthetic option when backward-filling a select2-enhanced region field with no matching option', () => {
		boot( { region: true, settlement: true } );

		// Simulates the mode renderer's own swap (see this test's own docblock) — this
		// module never performs it itself, it only ever finds whatever is live by id.
		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );

		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		const regionEl = document.getElementById( 'billing_state' );

		expect( regionEl.tagName ).toBe( 'SELECT' );
		expect( regionEl.value ).toBe( 'Московская область' );
		expect( regionEl.selectedOptions[ 0 ].textContent ).toBe( 'Московская область' );
	} );

	/**
	 * Issue #460's `related-list` half: that mode pre-populates the WHOLE country's real,
	 * WC-registered `<option>` elements up front (`class-checkout-config.php::build_location_block()`'s
	 * own "related-list region seam" docblock) — option VALUE is `wc_strtoupper(trim(label))`,
	 * option TEXT is the human label. Backward-fill must select the EXISTING option (by
	 * matching its TEXT against the bare component name {@see fieldValueFor} derives) and
	 * inherit ITS real value — never fabricate one WooCommerce's own state-list validation
	 * would then reject.
	 */
	it( 'selects the EXISTING option by matching its text when backward-filling a related-list-enhanced region field', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );
		const option = document.createElement( 'option' );

		option.value = 'МОСКОВСКАЯ ОБЛАСТЬ';
		option.textContent = 'Московская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		const regionEl = document.getElementById( 'billing_state' );

		// The REGISTERED WC value, not a synthetic one carrying the bare name — a value
		// posted for a related-list field must match what `woocommerce_states` registered.
		expect( regionEl.value ).toBe( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		expect( regionEl.options.length ).toBe( 1 );
	} );

	/**
	 * Issue #462 round 2 (Codex critic, s86) — the BLOCKING finding. `applyValueToElement()`
	 * used to select a PRE-EXISTING matching option via a bare `selectedIndex` assignment and
	 * nothing else. select2/selectWoo only re-pulls its rendered snapshot on a `change` event it
	 * hears itself (`Select2.prototype._registerDomEvents`'s `this.$element.on('change.select2',
	 * ...)`, `selectWoo.full.js:5345-5354`) — a bare assignment fires NONE, so the widget keeps
	 * showing whatever it last rendered (stale or empty) while the underlying `<select>` silently
	 * carries the newly restored value. This is display-critical, not cosmetic: PR #461 makes
	 * `ajax-select2`'s own option values `entry.value` — the SAME space `writeSilently()` writes —
	 * so a VALUE match, not the synthetic-option path, becomes the common case for this field the
	 * moment it lands.
	 *
	 * Pinned via a fake `change.select2` listener standing in for the real widget (this file's
	 * jsdom environment has no select2 package at all — see file docblock). Also pins the other
	 * required half: the refresh must NOT trip this module's OWN change-gate, which binds a plain
	 * (no-namespace) `change` on `document.body` in both event worlds ({@see bindChangeWorlds}) —
	 * a jQuery-namespaced trigger reaches only same-namespace handlers
	 * (`jQuery.event.dispatch`'s `event.rnamespace.test( handleObj.namespace )`, verified against
	 * `node_modules/jquery/dist/jquery.js`), so a plain listener on `document.body` must see
	 * nothing from it.
	 */
	it( 'fires a namespaced change.select2 refresh — never the module\'s own change-gate — when the restored value matches an EXISTING option by VALUE', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );
		const option = document.createElement( 'option' );

		// Simulates PR #461: the select2-backed region field already carries a REAL option
		// whose value is the exact same space writeSilently() writes into.
		option.value = 'Московская область';
		option.textContent = 'Московская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		const regionEl = document.getElementById( 'billing_state' );
		const widgetRefreshSpy = jest.fn();
		const gateSpyNative = jest.fn();
		const gateSpyJquery = jest.fn();

		// Stands in for selectWoo's own internal binding — namespaced, exactly as the real
		// widget binds it.
		window.jQuery( regionEl ).on( 'change.select2', widgetRefreshSpy );
		// Stands in for this module's OWN change-gate — bound with NO namespace, exactly as
		// bindChangeWorlds() binds it, on the SAME document.body it uses.
		document.body.addEventListener( 'change', gateSpyNative );
		window.jQuery( document.body ).on( 'change', gateSpyJquery );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		expect( regionEl.value ).toBe( 'Московская область' );
		expect( regionEl.options.length ).toBe( 1 ); // no synthetic option grew alongside the real one.

		// THE fix: the widget was told to re-render...
		expect( widgetRefreshSpy ).toHaveBeenCalledTimes( 1 );
		// ...but the module's own change-gate never saw a change event TARGETING the region
		// field — in EITHER event world. `gateSpyNative`/`gateSpyJquery` also legitimately fire
		// once each for `selectViaFake`'s OWN native `change` dispatch on the settlement field
		// (the ordinary, unrelated pick path this module is designed to react to) — asserting on
		// the event target, not on "never called at all", isolates the region refresh from that.
		expect( gateSpyNative.mock.calls.map( ( call ) => call[ 0 ].target ) ).not.toContain( regionEl );
		expect( gateSpyJquery.mock.calls.map( ( call ) => call[ 0 ].target ) ).not.toContain( regionEl );
	} );

	/**
	 * Same widget-refresh requirement, TEXT-match branch (`related-list`'s own path — see the
	 * test above this one in this file for the VALUE-match half). Reusing an existing option node
	 * via `selectedIndex` alone is exactly as invisible to select2 regardless of WHICH loop found
	 * it.
	 */
	it( 'fires a namespaced change.select2 refresh when the restored value matches an EXISTING option by TEXT', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );
		const option = document.createElement( 'option' );

		option.value = 'МОСКОВСКАЯ ОБЛАСТЬ';
		option.textContent = 'Московская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		const regionEl = document.getElementById( 'billing_state' );
		const widgetRefreshSpy = jest.fn();

		window.jQuery( regionEl ).on( 'change.select2', widgetRefreshSpy );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		expect( regionEl.value ).toBe( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		expect( widgetRefreshSpy ).toHaveBeenCalledTimes( 1 );
	} );

	/**
	 * Issue #462 round 2 (NOTED finding 1) — a region changed twice while `ajax-select2` (no
	 * matching option either time) used to append TWO synthetic `<option>` elements, the first
	 * left behind, deselected. `applyValueToElement()` now marks and REUSES its one synthetic
	 * option instead of appending a fresh one every time.
	 */
	it( 'reuses the single synthetic option across repeated backward-fills of DISTINCT unmatched values, never accumulating stale ones', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );

		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		const regionEl = document.getElementById( 'billing_state' );

		expect( regionEl.options.length ).toBe( 1 );
		expect( regionEl.value ).toBe( 'Московская область' );

		// A SECOND settlement pick, in a DIFFERENT region — nothing in `regionEl.options` matches
		// this new value either (still the ajax-select2 no-pre-population case).
		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city2', label: 'г Санкт-Петербург', level: 'settlement',
			record: {
				key: 'dadata:city2', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Ленинградская область', type: '' },
				label: 'г Санкт-Петербург',
			},
		} );

		// Still exactly ONE option — the stale «Московская область» one was reused, not left
		// behind alongside a second.
		expect( regionEl.options.length ).toBe( 1 );
		expect( regionEl.value ).toBe( 'Ленинградская область' );
		expect( regionEl.selectedOptions[ 0 ].textContent ).toBe( 'Ленинградская область' );
	} );

	/**
	 * Issue #465 (measured on the rig, s86): the region updates only once per full page reload.
	 * `refreshSelectWooWidget()`'s `change.select2` trigger (issue #462 round 2's own fix) makes
	 * selectWoo RE-RUN its own rendering pass, but that pass is `SelectAdapter.prototype.current()`
	 * -> `item($option)` (`selectWoo.full.js:3167-3180,3352-3396`, verified against the vendored
	 * copy at `D:/Projects/wordpress/woocommerce/assets/js/selectWoo/selectWoo.full.js`), which
	 * returns `$.data($option[0], 'data')` WITHOUT rebuilding it if that key is already set — and
	 * `item()` ITSELF sets that key the first time it ever reads a node (line 3393). The synthetic
	 * option this fix reuses (never a fresh append — issue #462 round 2) is exactly such a node:
	 * its FIRST fill gets read (by selectWoo's own separate MutationObserver re-sync) and cached;
	 * every fill after that mutates the node's `value`/`textContent` in place but the STALE cached
	 * object survives untouched, so a namespaced `change.select2` trigger re-renders the widget
	 * from data that no longer matches the DOM at all. `regionEl.value` was already correct before
	 * this fix — this is the gap none of the tests above this one closed, because none of them
	 * modelled the cache at all (only the DOM's own `value`/`textContent`, which was never wrong).
	 *
	 * @see docs-internal/research/2026-08-21-select2-location-fields.md
	 */
	it( 'invalidates the reused synthetic option\'s select2 data cache so the WIDGET (not just .value) shows each new fill (issue #465)', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );

		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		const regionEl = document.getElementById( 'billing_state' );

		/**
		 * Mirrors `SelectAdapter.prototype.item()` exactly (selectWoo.full.js:3352-3396): return
		 * the cached `data` key if already set, otherwise build `{id, text}` fresh off the LIVE
		 * DOM and cache it. Real selectWoo calls this from `current()` — bound both to its own
		 * `change.select2` listener (`_registerDomEvents`) and to its separate MutationObserver
		 * re-sync on a freshly appended child (`_syncSubtree`) — this fake only stands in for the
		 * FORMER; the latter is invoked directly below to seed the FIRST fill's cache, exactly as
		 * `applyValueToElement()`'s own docblock describes the two paths differing.
		 */
		function selectWooItem( option ) {
			var cached = window.jQuery.data( option, 'data' );

			if ( null != cached ) {
				return cached;
			}

			var data = { id: option.value, text: option.textContent };

			window.jQuery.data( option, 'data', data );

			return data;
		}

		var widgetRenderedText = null;

		// Stands in for selectWoo's OWN internal `change.select2` binding.
		window.jQuery( regionEl ).on( 'change.select2', function() {
			widgetRenderedText = selectWooItem( regionEl.options[ regionEl.selectedIndex ] ).text;
		} );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				label: 'г Москва',
			},
		} );

		// The FIRST fill: stands in for selectWoo's own MutationObserver re-sync on the freshly
		// APPENDED node — never `refreshSelectWooWidget()`'s trigger, which the append path does
		// not call at all (see `applyValueToElement()`'s own docblock).
		widgetRenderedText = selectWooItem( regionEl.options[ regionEl.selectedIndex ] ).text;
		expect( widgetRenderedText ).toBe( 'Московская область' );

		// A SECOND, DIFFERENT pick — reuses the SAME synthetic option node (issue #462 round 2).
		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city2', label: 'г Санкт-Петербург', level: 'settlement',
			record: {
				key: 'dadata:city2', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Ленинградская область', type: '' },
				label: 'г Санкт-Петербург',
			},
		} );

		expect( regionEl.value ).toBe( 'Ленинградская область' ); // the field's real value: always correct.
		// THE FIX (issue #465): the WIDGET must show the fill it was just told about — not the
		// FIRST fill's stale cached {id,text}, which survives on the reused node unless something
		// invalidates it before the `change.select2` trigger runs.
		expect( widgetRenderedText ).toBe( 'Ленинградская область' );

		// A THIRD pick, back to the FIRST region's own name — proves this isn't "the cache just
		// happens to equal the new value again" (both fills before this one used names the cache
		// never held), and matches the brief's "at least three consecutive fills" requirement.
		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city3', label: 'г Тверь', level: 'settlement',
			record: {
				key: 'dadata:city3', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Тверская область', type: '' },
				label: 'г Тверь',
			},
		} );

		expect( regionEl.value ).toBe( 'Тверская область' );
		expect( widgetRenderedText ).toBe( 'Тверская область' );
	} );

	/**
	 * Both required degradations at once (task round-2 brief): no jQuery loaded at all (a plain
	 * `<select>`, and — separately — this file's own jQuery-less capability, exercised directly
	 * here rather than assumed) must neither throw nor block the underlying value write; the
	 * synthetic/matched-option selection logic itself has nothing to do with jQuery.
	 */
	it( 'degrades cleanly with no jQuery loaded — sets the value, attempts no widget refresh, never throws', () => {
		boot( { region: true, settlement: true } );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );
		const option = document.createElement( 'option' );

		option.value = 'Московская область';
		option.textContent = 'Московская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );

		delete window.jQuery;
		delete global.jQuery;
		delete global.$;

		expect( () => {
			selectViaFake( callFor( 'billing_city' ), {
				key: 'dadata:city1', label: 'г Москва', level: 'settlement',
				record: {
					key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
					region: { name: 'Московская область', type: '' },
					label: 'г Москва',
				},
			} );
		} ).not.toThrow();

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская область' );
	} );
} );

// -----------------------------------------------------------------------
// Issue #352 — mixed-provider chain: a foreign-provider record never enters the
// SERVER-SIDE chain via /select (Variant A). See `class-checkout-config.php
// ::build_location_block()`'s own `owners` docblock and `location-cascade.js`'s own
// `mayEnterChain()` docblock for the full reasoning.
// -----------------------------------------------------------------------

describe( 'issue #352 — refusing to post a foreign-provider record into the server chain', () => {
	const OWNERS_MIXED_CHAIN = { RU: { region: 'test-cdek', settlement: 'test-cdek', address: 'dadata' } };

	function foreignAddressItem() {
		return {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				label: 'ул Тверская, 1',
			},
		};
	}

	it( 'does not POST /select for a record DEEPER than settlement whose provider does not own the settlement level', () => {
		boot( { region: true, settlement: true, address: true, owners: OWNERS_MIXED_CHAIN } );

		const fetchCallCountBefore = global.fetch.mock.calls.length;

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		// No new fetch AT ALL — the /select POST never happened.
		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore );
	} );

	it( 'still POSTs /select when the SAME provider owns the settlement level (single-provider chain)', () => {
		boot( {
			region: true, settlement: true, address: true,
			owners: { RU: { region: 'dadata', settlement: 'dadata', address: 'dadata' } },
		} );

		const fetchCallCountBefore = global.fetch.mock.calls.length;

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore + 1 );
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toBe( SELECT_URL );
	} );

	/**
	 * THE RULE MUST NOT BE RE-BROADENED — this is the adversarial critic's counter-example
	 * (Codex/GPT-5.5, s78), and it is a REGRESSION GUARD, not a nicety.
	 *
	 * A first draft of `mayEnterChain()` refused a record unless its provider owned every served
	 * level from the shallowest down to its own. Under a store whose active provider serves ONLY
	 * `region`, with the bundled DaData fallback serving `settlement` and `address`, that draft
	 * refused the SETTLEMENT pick because `region` had a foreign owner. The settlement record
	 * then never reached the server at all, `Provider_Selection_Scope::current_locality()` kept
	 * answering `''`, and the customer's pickup point could never be filed or restored — strictly
	 * WORSE than the unfixed code, which posts the settlement, lets `rebuild_chain()` drop the
	 * unprovable region, and ends with a usable `{ settlement: … }` chain.
	 *
	 * The settlement level is the anchor. Nothing at or above it is ever refused.
	 */
	it( 'still POSTs a settlement pick even when a SHALLOWER level is foreign-owned (the anchor is never refused)', () => {
		boot( {
			region: true, settlement: true, address: true,
			owners: { RU: { region: 'city-dict', settlement: 'dadata', address: 'dadata' } },
		} );

		const fetchCallCountBefore = global.fetch.mock.calls.length;

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city1', label: 'г Москва', level: 'settlement',
			record: {
				key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
				label: 'г Москва',
			},
		} );

		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore + 1 );
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toBe( SELECT_URL );
	} );

	it( 'still POSTs /select exactly as before this fix when the config carries no `owners` map at all', () => {
		// No `owners` key in the config at all — an older cached config, or a plugin/test
		// harness building the location block itself. Must degrade to EXACTLY the pre-#352
		// behaviour: every pick reaches /select, regardless of provider.
		boot( { region: true, settlement: true, address: true } );

		const fetchCallCountBefore = global.fetch.mock.calls.length;

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore + 1 );
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toBe( SELECT_URL );
	} );

	/**
	 * The coordinator's own measurement: WooCommerce's classic checkout does not reliably fire
	 * `update_checkout` off an address change on its own (gotcha
	 * `wc-does-not-save-the-address-until-every-required-text-field-is-filled`), and
	 * `backwardsFill()`'s silent writes dispatch no event of their own — so a foreign record
	 * that skips /select must still trigger `update_checkout` itself, or the address/postcode
	 * it just wrote into the DOM never reaches WooCommerce's own pricing at all.
	 */
	it( 'foreign record: no /select POST, but update_checkout still fires', () => {
		boot( { region: true, settlement: true, address: true, owners: OWNERS_MIXED_CHAIN } );

		const triggerSpy = jest.spyOn( window.jQuery.fn, 'trigger' );
		const fetchCallCountBefore = global.fetch.mock.calls.length;

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		expect( global.fetch.mock.calls.length ).toBe( fetchCallCountBefore );
		expect( triggerSpy.mock.calls.some( ( args ) => args[ 0 ] === 'update_checkout' ) ).toBe( true );

		triggerSpy.mockRestore();
	} );

	it( 'does not fire woodev_location_applied for a foreign-provider record', () => {
		boot( { region: true, settlement: true, address: true, owners: OWNERS_MIXED_CHAIN } );

		const seen = [];
		document.body.addEventListener( 'woodev_location_applied', ( event ) => seen.push( event.detail ) );

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		expect( seen ).toHaveLength( 0 );
	} );

	it( 'the LOCAL record and the address lock still update for a refused, foreign-provider pick', async () => {
		boot( { region: true, settlement: true, address: true, owners: OWNERS_MIXED_CHAIN } );

		// The address field starts locked (issue #337: a settlement/address chain with the
		// address level served and no settlement confirmed yet).
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( true );

		// Confirm a settlement first — through the OWNING provider, so /select DOES fire and
		// unlocks the address field, exactly like the real mixed-chain flow.
		selectViaFake( callFor( 'billing_city' ), {
			key: 'test-cdek:msk', label: 'г Москва', level: 'settlement',
			record: { key: 'test-cdek:msk', provider_id: 'test-cdek', level: 'settlement', country: 'RU', label: 'г Москва' },
		} );

		// s90: the address unlocks when the SERVER confirms the settlement, not on the click —
		// so this flow has to get past the round trip before the lock lifts.
		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'test-cdek:msk', level: 'settlement' }, persisted: true,
			chain: { settlement: { key: 'test-cdek:msk', level: 'settlement' } },
		} );
		await flushMicrotasks();

		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );

		selectViaFake( callFor( 'billing_address_1' ), foreignAddressItem() );

		// Still unlocked — refusing the /select POST does not re-lock a field the customer can
		// already see is confirmed and editable.
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );
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

	it( 'the bare {mode} key serves the region and settlement levels uniformly when no level-specific one is registered — issue #448: NOT the address level, which never resolves an axis mode at all', () => {
		// PRE-#448 this asserted `toHaveBeenCalledTimes( 3 )` — the address node used to
		// inherit whichever axis mode a bare registry key answered to, exactly like region and
		// settlement, because resolveModeRenderer() had no third branch of its own. That pinned
		// the defect this issue fixes, not a real contract: the settings UI has never offered a
		// mode for the address level, so there is no mode for it to inherit. Updated, not
		// silently — see the #448 PR body for the failing-on-main verification.
		const bareMode = jest.fn( () => ( { detach: jest.fn() } ) );

		window.WoodevLocationRenderers = { 'custom-mode': bareMode };

		boot( { region: true, settlement: true, address: true, mode: 'custom-mode' } );

		expect( bareMode ).toHaveBeenCalledTimes( 2 );
		// The address node falls straight through to the baseline typeahead — never a
		// registry lookup, bare or level-specific.
		expect( callFor( 'billing_address_1' ) ).toBeDefined();
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
	// options.list() — issue #463: the /location/list analog of options.fetch, same
	// fieldValueFor() value stamping — a related-list renderer must never fall back to the raw
	// provider key the way `attachRelatedListSettlement()` did before this fix (the #455 disease
	// on the other branch).
	// -------------------------------------------------------------------

	describe( 'options.list() — issue #463', () => {
		it( 'builds the /location/list URL scoped by level/country/within, and stamps entry.value via fieldValueFor() exactly like options.fetch does for /location/suggest', async () => {
			const specialCalls = [];

			window.WoodevLocationRenderers = {
				'custom-mode:settlement': ( el, options ) => {
					specialCalls.push( { el, options } );

					return { detach: jest.fn() };
				},
			};

			boot( { region: true, settlement: true, mode: 'custom-mode' } );

			const listPromise = specialCalls[ 0 ].options.list();

			const req = fetchCalls[ fetchCalls.length - 1 ];

			expect( req.url ).toBe( LIST_URL + '?level=settlement&country=RU' );

			req.resolve( {
				localities: [ {
					key: 'dadata:zh', label: 'Московская обл., г Жуковский', level: 'settlement',
					record: {
						key: 'dadata:zh', provider_id: 'dadata', level: 'settlement', country: 'RU',
						settlement: { name: 'Жуковский', type: 'г' },
						label: 'Московская обл., г Жуковский',
					},
				} ],
			} );

			const localities = await listPromise;

			// The value written into the field — never the raw provider key, never the
			// ancestor-carrying label. Same derivation `fetchFor()` already gives `options.fetch`.
			expect( localities[ 0 ].value ).toBe( 'Жуковский' );
			expect( localities[ 0 ].label ).toBe( 'Московская обл., г Жуковский' );
		} );

		it( 'scopes `within` by the LIVE parent selection at call time, never captured at attach time', () => {
			const specialCalls = [];

			window.WoodevLocationRenderers = {
				'custom-mode:settlement': ( el, options ) => {
					specialCalls.push( { el, options } );

					return { detach: jest.fn() };
				},
			};

			boot( { region: true, settlement: true, mode: 'custom-mode' } );

			specialCalls[ 0 ].options.list();

			expect( fetchCalls[ fetchCalls.length - 1 ].url ).not.toContain( 'within=' );

			// A region gets picked (native <select>, related-list's own watcher persists it via
			// the shared onSelect/backwards-fill route) — the NEXT list() call must scope to it.
			const record = { key: 'dadata:region1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Москва', type: 'г' }, label: 'г Москва' };

			specialCalls[ 0 ].options.onSelect( { record } );

			const selectReq = fetchCalls[ fetchCalls.length - 1 ];

			selectReq.resolve( { current: { key: record.key, level: 'region' }, persisted: true, chain: { region: { key: record.key, level: 'region' } } } );

			return flushMicrotasks().then( () => {
				specialCalls[ 0 ].options.list();

				expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( 'within=' + encodeURIComponent( 'dadata:region1' ) );
			} );
		} );
	} );

	// -------------------------------------------------------------------
	// options.popular() — issue #530 (#488's customer-facing half): the shop's popular-
	// settlements list, scoped to the live country + region, `.value`-stamped exactly like
	// options.fetch/options.list already are.
	// -------------------------------------------------------------------

	describe( 'options.popular() — issue #530', () => {
		it( 'scopes to the live country, stamps entry.value via fieldValueFor(), and is null for a level the popular list can never carry (only settlement is ever enrolled)', () => {
			const specialCalls = [];

			window.WoodevLocationRenderers = {
				'custom-mode:settlement': ( el, options ) => {
					specialCalls.push( { level: 'settlement', options } );

					return { detach: jest.fn() };
				},
				'custom-mode:region': ( el, options ) => {
					specialCalls.push( { level: 'region', options } );

					return { detach: jest.fn() };
				},
			};

			boot( {
				region: true, settlement: true, mode: 'custom-mode',
				popular: {
					RU: [ {
						key: 'dadata:tv', label: 'Тверь', level: 'settlement',
						record: {
							key: 'dadata:tv', provider_id: 'dadata', level: 'settlement', country: 'RU',
							settlement: { name: 'Тверь', type: 'г' }, label: 'Тверь', ancestors: [],
						},
					} ],
					// A DIFFERENT country's entries must never leak into the RU-scoped answer.
					BY: [ {
						key: 'dadata:mi', label: 'Минск', level: 'settlement',
						record: {
							key: 'dadata:mi', provider_id: 'dadata', level: 'settlement', country: 'BY',
							settlement: { name: 'Минск', type: 'г' }, label: 'Минск', ancestors: [],
						},
					} ],
				},
			} );

			const settlementCall = specialCalls.find( ( c ) => 'settlement' === c.level );
			const regionCall = specialCalls.find( ( c ) => 'region' === c.level );

			expect( typeof settlementCall.options.popular ).toBe( 'function' );
			// Absent, not present-and-empty (spec D4's own "no capability, no list" discipline,
			// reused client-side): a region field must never even be handed a callback that
			// could only ever answer [].
			expect( regionCall.options.popular ).toBeNull();

			const popular = settlementCall.options.popular();

			expect( popular ).toHaveLength( 1 );
			expect( popular[ 0 ].key ).toBe( 'dadata:tv' );
			// The value a pick of this entry would WRITE into the field — never the raw
			// provider key, the same derivation options.fetch/options.list already give.
			expect( popular[ 0 ].value ).toBe( 'Тверь' );
		} );

		it( 'excludes an entry whose own level does not match the node it was asked about — defensive, since every stored entry is settlement-level today', () => {
			const specialCalls = [];

			window.WoodevLocationRenderers = {
				'custom-mode:settlement': ( el, options ) => {
					specialCalls.push( options );

					return { detach: jest.fn() };
				},
			};

			boot( {
				settlement: true, mode: 'custom-mode',
				popular: {
					RU: [ {
						key: 'dadata:reg1', label: 'Москва', level: 'region',
						record: {
							key: 'dadata:reg1', provider_id: 'dadata', level: 'region', country: 'RU',
							region: { name: 'Москва', type: 'г' }, label: 'Москва', ancestors: [],
						},
					} ],
				},
			} );

			expect( specialCalls[ 0 ].popular() ).toEqual( [] );
		} );

		it( 'unscoped (no region confirmed yet) returns every country-scoped entry; once a region is picked, only entries carrying that region key among record.ancestors survive', async () => {
			const specialCalls = [];

			window.WoodevLocationRenderers = {
				'custom-mode:settlement': ( el, options ) => {
					specialCalls.push( options );

					return { detach: jest.fn() };
				},
			};

			const within = {
				key: 'dadata:tver-region', level: 'region', provider_id: 'dadata', country: 'RU',
				region: { name: 'Тверская', type: 'обл' }, label: 'Тверская обл.',
			};

			boot( {
				region: true, settlement: true, mode: 'custom-mode',
				popular: {
					RU: [
						{
							key: 'dadata:in', label: 'Тверь', level: 'settlement',
							record: {
								key: 'dadata:in', provider_id: 'dadata', level: 'settlement', country: 'RU',
								settlement: { name: 'Тверь', type: 'г' }, label: 'Тверь', ancestors: [ within.key ],
							},
						},
						{
							key: 'dadata:out', label: 'Казань', level: 'settlement',
							record: {
								key: 'dadata:out', provider_id: 'dadata', level: 'settlement', country: 'RU',
								settlement: { name: 'Казань', type: 'г' }, label: 'Казань', ancestors: [ 'dadata:other-region' ],
							},
						},
					],
				},
			} );

			// Nothing selected yet — unscoped, both entries survive.
			expect( specialCalls[ 0 ].popular().map( ( p ) => p.key ) ).toEqual( [ 'dadata:in', 'dadata:out' ] );

			specialCalls[ 0 ].onSelect( { record: within } );
			await settleLastSelect( within );

			expect( specialCalls[ 0 ].popular().map( ( p ) => p.key ) ).toEqual( [ 'dadata:in' ] );
		} );
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

	// -------------------------------------------------------------------
	// Issue #380 — the two axes resolve INDEPENDENTLY (resolveModeRenderer()
	// keys the region node off the region axis, every other node off the
	// settlement axis) — the exact combination the legacy single `field_mode`
	// could never express.
	// -------------------------------------------------------------------

	describe( 'issue #380 — region and settlement axes resolve independently', () => {
		it( 'attaches the related-list region renderer while settlement stays the baseline typeahead', () => {
			const regionRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			window.WoodevLocationRenderers = {
				'related-list:region': regionRenderer,
				// A settlement renderer registered under 'related-list' must NEVER be
				// consulted here — the settlement axis is 'typeahead', not 'related-list'.
				'related-list:settlement': jest.fn( () => ( { detach: jest.fn() } ) ),
			};

			boot( {
				region: true,
				settlement: true,
				mode: { region: 'related-list', settlement: 'typeahead' },
				levels: { RU: { region: false, settlement: true, address: true } },
			} );

			expect( regionRenderer ).toHaveBeenCalledTimes( 1 );
			expect( window.WoodevLocationRenderers[ 'related-list:settlement' ] ).not.toHaveBeenCalled();
			// The settlement node falls through to the baseline typeahead — no
			// renderer registered for 'typeahead:settlement' or bare 'typeahead'.
			expect( callFor( 'billing_city' ) ).toBeDefined();
		} );

		it( 'attaches a settlement-axis renderer while region stays native/typeahead', () => {
			const settlementRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			window.WoodevLocationRenderers = {
				'ajax-select2': settlementRenderer,
			};

			boot( {
				region: true,
				settlement: true,
				mode: { region: 'typeahead', settlement: 'ajax-select2' },
				levels: { RU: { region: true, settlement: true, address: true } },
			} );

			// The settlement node resolves the bare 'ajax-select2' registry entry —
			// the SAME lookup key the old shared-mode 'ajax-select2' used.
			expect( settlementRenderer ).toHaveBeenCalledTimes( 1 );
			// The region node's own axis is 'typeahead' — the region-only related-list
			// exception (isRelatedListRegionNode) never engages, and no 'ajax-select2'
			// call is made FOR the region node (only ever settlement, above).
			expect( callFor( 'billing_state' ) ).toBeDefined();
		} );
	} );

	// -------------------------------------------------------------------
	// Issue #448 — the address level has NO axis of its own and must never
	// resolve one: resolveModeRenderer()'s old binary ternary (`region` vs.
	// "everything else") fed address the SETTLEMENT axis, so a bare registry
	// key like `ajax-select2` — registered with no level suffix — attached to
	// address too, turning a text field into a select nobody configured. The
	// settings UI only ever offers a mode for the region and settlement axes;
	// address must fall straight through to the baseline typeahead regardless
	// of what either axis is set to.
	// -------------------------------------------------------------------

	describe( 'issue #448 — the address level never resolves an axis mode', () => {
		it( 'does NOT attach the settlement axis\'s ajax-select2 renderer to the address field — falls through to the baseline typeahead instead', () => {
			const settlementRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			// The REAL registry shape (location-select-modes.js): 'ajax-select2' is registered
			// under a bare, level-less key — the exact key that let it attach to any level.
			window.WoodevLocationRenderers = { 'ajax-select2': settlementRenderer };

			boot( {
				settlement: true, address: true,
				mode: { region: 'typeahead', settlement: 'ajax-select2' },
			} );

			// Attached exactly once — for the settlement node the axis actually governs.
			expect( settlementRenderer ).toHaveBeenCalledTimes( 1 );
			expect( settlementRenderer.mock.calls[ 0 ][ 0 ].id ).toBe( 'billing_city' );

			// The address node was never handed to the renderer at all, and falls through to
			// the baseline typeahead — the D7 floor for every level nothing claims.
			expect( callFor( 'billing_address_1' ) ).toBeDefined();
		} );

		it( 'still resolves region and settlement against their OWN axes while address stays on the baseline, all three attached together', () => {
			const regionRenderer = jest.fn( () => ( { detach: jest.fn() } ) );
			const settlementRenderer = jest.fn( () => ( { detach: jest.fn() } ) );

			window.WoodevLocationRenderers = {
				'related-list:region': regionRenderer,
				'ajax-select2': settlementRenderer,
			};

			boot( {
				region: true, settlement: true, address: true,
				mode: { region: 'related-list', settlement: 'ajax-select2' },
				levels: { RU: { region: false, settlement: true, address: true } },
			} );

			expect( regionRenderer ).toHaveBeenCalledTimes( 1 );
			expect( regionRenderer.mock.calls[ 0 ][ 0 ].id ).toBe( 'billing_state' );

			expect( settlementRenderer ).toHaveBeenCalledTimes( 1 );
			expect( settlementRenderer.mock.calls[ 0 ][ 0 ].id ).toBe( 'billing_city' );

			// Neither renderer was ever asked about the address node — it went straight to
			// the baseline typeahead, the operator's rig-observed contract for that level.
			expect( callFor( 'billing_address_1' ) ).toBeDefined();
		} );

		it( 'falls through to the baseline typeahead for the address level even when NOTHING is registered for either axis', () => {
			// window.WoodevLocationRenderers left entirely undefined — as if
			// location-select-modes.js never loaded (the D7 floor must still hold).
			boot( {
				settlement: true, address: true,
				mode: { region: 'typeahead', settlement: 'typeahead' },
			} );

			expect( callFor( 'billing_city' ) ).toBeDefined();
			expect( callFor( 'billing_address_1' ) ).toBeDefined();
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
// WooCommerce's OWN state-field rebuild (`country_to_state_changed`, issue #460)
// -----------------------------------------------------------------------

describe( 'WooCommerce\'s country_to_state_changed rebuild (issue #460)', () => {
	/**
	 * Mimics `location-select-modes.js`'s own `attachAjaxSelect2()`/`buildSelectField()` just
	 * closely enough to reproduce the defect: swaps a plain `<input>` for a fresh `<select>`
	 * carrying the SAME id, seeded from the input's OWN value at attach time (issue #447),
	 * exactly the shape the real `ajax-select2` mode renderer produces for the region level.
	 * Registered under the SAME registry key `resolveModeRenderer()` looks up, so
	 * `location-cascade.js` exercises its REAL attach/detach/reconcile path against it — only
	 * the widget's own guts are stubbed here, never the cascade code under test.
	 */
	function stubAjaxSelect2( el ) {
		if ( ! el || 'INPUT' !== el.tagName ) {
			return null;
		}

		const select = document.createElement( 'select' );
		select.id = el.id;
		select.name = el.name || '';

		if ( el.value ) {
			const option = document.createElement( 'option' );
			option.value = el.value;
			option.textContent = el.value;
			option.selected = true;
			select.appendChild( option );
		}

		el.parentNode.replaceChild( select, el );

		return { el: select, detach: jest.fn() };
	}

	/**
	 * Replicates WooCommerce's `country-select.js` own rebuild of `#shipping_state` for a
	 * country it has no state list for (RU) — the exact defect measured on the live rig: the
	 * "no WC states" branch (`country-select.js:156-167`) unconditionally replaces whatever
	 * `<select>`/`input[type="hidden"]` currently occupies the field with a FRESH, EMPTY
	 * `<input type="text">` — it reads `$statebox.val()` earlier in the same handler but never
	 * uses it in this branch — then fires `country_to_state_changed`. Never fires
	 * `updated_checkout` — this is synchronous client-side DOM churn, not a server round-trip.
	 *
	 * @returns {HTMLInputElement} the fresh, empty node WooCommerce left behind.
	 */
	function simulateWcStateRebuild( fieldId ) {
		const current = document.getElementById( fieldId );
		const fresh = document.createElement( 'input' );

		fresh.type = 'text';
		fresh.id = fieldId;
		fresh.name = fieldId;

		current.replaceWith( fresh );
		window.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );

		return fresh;
	}

	it( 'restores the region value after WooCommerce tears down and rebuilds the field', () => {
		window.WoodevLocationRenderers = { 'ajax-select2': stubAjaxSelect2 };

		installMarkup( { region: true, section: 'shipping', mode: 'ajax-select2' }, 'RU' );
		document.getElementById( 'shipping_state' ).value = 'Moskovskaya';

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		window[ CONFIG_GLOBAL ] = buildConfig( { region: true, section: 'shipping', mode: 'ajax-select2' } );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		// Our own renderer already swapped input -> select, seeded from the server value —
		// the boot-time half of the measured trace ("t=86ms our renderer swaps input -> select").
		expect( document.getElementById( 'shipping_state' ).tagName ).toBe( 'SELECT' );
		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Moskovskaya' );

		simulateWcStateRebuild( 'shipping_state' );

		// WC's own rebuild always arrives empty (measured) — this module must have restored it.
		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Moskovskaya' );
	} );

	/**
	 * Issue #466 — the settlement field measured on the rig as a plain `<input>` for ~3-4s
	 * after every page load. Two leads were proposed and both are tested here against the
	 * REAL production `location-cascade.js`/`location-select-modes.js` boot + reconcile path
	 * (never a stub of the cascade logic itself — only the renderer's own select2 guts are
	 * faked, exactly like `stubAjaxSelect2` above):
	 *
	 * Lead A ("the country_to_state_changed subscriber only recovers the region, leaving
	 * settlement to wait for the next updated_checkout") does not hold as read from the code:
	 * `bindCountryToStateChangedWatcher()` calls the SAME `handleCheckoutUpdated()` an
	 * `updated_checkout` re-render already runs, which reconciles `entry.chain` IN FULL —
	 * region, settlement, and address together — not a region-only patch. This test proves
	 * that empirically: both nodes attach in the SAME synchronous `boot()` pass, and a WC-style
	 * rebuild that (per the vendored `country-select.js:90-171`) touches ONLY the state field
	 * leaves the settlement node's element identity untouched — attached exactly once, never
	 * torn down by an event that never touched it.
	 *
	 * This does NOT reproduce the multi-second delay itself — jsdom has no real select2/
	 * selectWoo runtime, and a prior investigation already established (two separate control
	 * scenarios, both against these same real modules) that the delay cannot be reproduced
	 * here. What this test DOES nail down is that our OWN attach code carries no asymmetry
	 * between the two levels — the delay's actual cause is runtime-specific to a real browser
	 * and requires rig-level evidence this test cannot provide.
	 */
	it( 'a WC-style state-only rebuild leaves every chain node attached exactly once — region and settlement attach together at boot, and settlement is untouched by an event that never targets it', () => {
		const renderer = jest.fn( stubAjaxSelect2 );

		window.WoodevLocationRenderers = { 'ajax-select2': renderer };

		installMarkup( { region: true, settlement: true, section: 'shipping', mode: 'ajax-select2' }, 'RU' );
		document.getElementById( 'shipping_state' ).value = 'Moskovskaya';
		document.getElementById( 'shipping_city' ).value = 'Zhukovsky';

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		window[ CONFIG_GLOBAL ] = buildConfig( { region: true, settlement: true, section: 'shipping', mode: 'ajax-select2' } );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		// Both nodes attach in the SAME synchronous boot() pass — never staggered.
		expect( renderer ).toHaveBeenCalledTimes( 2 );
		expect( document.getElementById( 'shipping_state' ).tagName ).toBe( 'SELECT' );
		expect( document.getElementById( 'shipping_city' ).tagName ).toBe( 'SELECT' );

		const settlementNodeBeforeRebuild = document.getElementById( 'shipping_city' );

		simulateWcStateRebuild( 'shipping_state' );

		// The state field is torn down and rebuilt exactly once more — never a second call.
		expect( renderer ).toHaveBeenCalledTimes( 3 );
		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Moskovskaya' );

		// The settlement node's element identity is UNCHANGED — WC's rebuild never touched it,
		// and reconcileAfterCheckoutUpdate() correctly treats "current.el === live" as a no-op
		// rather than tearing down and re-attaching a node nothing replaced.
		expect( document.getElementById( 'shipping_city' ) ).toBe( settlementNodeBeforeRebuild );
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Zhukovsky' );
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
// Source-unavailable message (issue #405) — server-supplied, DISTINCT from
// the empty-result message above: "the source could not answer" must never
// read the same as "searched, found nothing" at checkout.
// -----------------------------------------------------------------------

describe( 'source-unavailable message (issue #405)', () => {
	it( 'hands the widget the translated unavailable string from the config, distinct from emptyText', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( callFor( 'billing_city' ).errorText ).toBe( 'Источник подсказок недоступен. Попробуйте ещё раз позже или введите вручную.' );
		expect( callFor( 'billing_city' ).errorText ).not.toBe( callFor( 'billing_city' ).emptyText );
	} );

	it( 'passes an empty string when the config carries no i18n block at all', () => {
		boot( { region: true, settlement: true, address: true, i18n: null } );

		expect( callFor( 'billing_city' ).errorText ).toBe( '' );
	} );

	it( 'passes an empty string when the server sent no unavailable string of its own', () => {
		boot( { region: true, settlement: true, address: true, i18n: { noResults: 'x' } } );

		expect( callFor( 'billing_city' ).errorText ).toBe( '' );
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

	/**
	 * Picks the settlement AND lets its `/select` answer.
	 *
	 * Since s90 the address unlocks on the SERVER's confirmation, not on the click (operator
	 * decision reversing #337's original rule — the reasoning, and what overturned it, is in
	 * `isAddressLocked()`'s own docblock). A test about the lock RULE therefore has to get past
	 * the round trip before it can see the lock lift; a test about the TIMING asserts the
	 * in-flight state directly instead.
	 *
	 * @returns {Promise<void>}
	 */
	function pickSettlementAndSettle() {
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );

		return flushMicrotasks();
	}

	it( 'locks it on boot when settlement and address are linked and the provider serves address', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'marks the locked field for the stylesheet, and unmarks it again', async () => {
		// `disabled` alone is invisible — the theme's own `input` rule overrides the browser's
		// greying (measured on the rig), so the class is what location.css can actually see.
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );

		await pickSettlementAndSettle();

		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( false );
	} );

	it( 'says nothing about the lock — no title, no aria description', () => {
		// Standing operator rule (#274): a blocked control is blocked, and that is all it says.
		boot( { region: true, settlement: true, address: true } );

		const el = addressField();

		expect( el.getAttribute( 'title' ) ).toBeNull();
		expect( el.getAttribute( 'aria-describedby' ) ).toBeNull();
		expect( el.getAttribute( 'aria-disabled' ) ).toBeNull();
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

	// REVERSED BY THE OPERATOR, s90. This test used to assert the opposite — "the /select round
	// trip is still in flight, the customer must be able to type now" — and that was #337's own
	// rule. What changed is a measurement: the round trip is 2.4-4.5 seconds on the rig, not the
	// instant #337 assumed, and the optimistic record it unlocked off can still be REFUSED (a D7
	// `cancelled` wipes the settlement and re-locks the address underneath whatever was typed in
	// the meantime), while the address's own `/suggest` would carry a `within` the server never
	// accepted. The cost — no typing for the length of the round trip — is why the busy state
	// exists: the field says it is working rather than sitting inert.
	it( 'stays locked while the settlement pick is still unconfirmed, and lifts when /select answers', async () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( SELECT_URL );
		expect( addressField().disabled ).toBe( true );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( false );
	} );

	it( 're-locks when the settlement text is edited without picking a suggestion', async () => {
		boot( { region: true, settlement: true, address: true } );

		await pickSettlementAndSettle();
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

	// ISSUE #502 — measured on the rig, s91. With
	// `woodev_location_default_locality_policy = fixed` the server seeds a settlement record
	// the customer never picked and flags the chain `implicit`; NEITHER settlement renderer
	// writes that record's text into the field, so the customer saw an empty НП field beside a
	// live address field. Spec §4.6/D11: «Implicit records participate in rate calculation but
	// never suppress "please choose your locality" prompts» — and this lock is such a prompt.
	it( 'stays LOCKED when the restored settlement record is the store\'s implicit default (#502)', () => {
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:0c5b2444', level: 'settlement' },
			chain: { settlement: { key: 'dadata:0c5b2444', level: 'settlement' } },
			implicit: true,
		} );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'unlocks on the SAME restored record once it is explicit rather than implicit (#502 control)', () => {
		// The control the measurement needs: the ONLY difference from the test above is the
		// flag, so a failure here would mean the fix locked something it should not have.
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:0c5b2444', level: 'settlement' },
			chain: { settlement: { key: 'dadata:0c5b2444', level: 'settlement' } },
			implicit: false,
		} );

		expect( addressField().disabled ).toBe( false );
	} );

	// ISSUE #518 — the intersection #502/#509 created and a stale docblock in
	// `refreshAddressLock()` claimed could not exist ("a pickup selection only ever happens
	// while unlocked"). It can: an implicit record has a perfectly good settlement key, so the
	// pickup layer persists against it happily, while THIS lock still refuses to open off it.
	// The point's address then lands in a disabled input, which WooCommerce does not serialize
	// — the order is refused for an empty required field with nothing pointing at the locality.
	//
	// Operator decision (s92): choosing a point inside a locality is evidence that locality is
	// right, so the prompt this lock represents has been answered.
	function bootLockedBehindAnImplicitDefault() {
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:implicit-seed', level: 'settlement' },
			chain: { settlement: { key: 'dadata:implicit-seed', level: 'settlement' } },
			implicit: true,
			// #536: a `geoip` guess still blocks the lock; only `fixed` was exempted.
			defaultLocality: { policy: 'geoip' },
		} );
	}

	// These dispatch the announcement DIRECTLY, so on their own they say nothing about
	// pickup-mount.js announcing BEFORE it writes — and if it announced after, the field
	// would still be disabled at the moment of the write. That half of the contract is
	// pinned where it belongs, in the module that owns it: pickup-mount.test.js's
	// «announces BEFORE it writes» test asserts `[ 'announced', 'city-changed' ]`.
	// Verified by mutation during the #518 critic pass — moving the announcement after
	// the writes fails that test and only that test.
	function announcePickupAddressWrite( fields ) {
		document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_address_replacing', {
			detail: { fields: fields },
			bubbles: true,
		} ) );
	}

	it( 'a confirmed pickup point unlocks an address field held behind an implicit default (#518)', () => {
		bootLockedBehindAnImplicitDefault();

		expect( addressField().disabled ).toBe( true );

		announcePickupAddressWrite( { billing_address_1: 'ул. Пункта Выдачи, 1' } );

		// The field the point's address is about to be written into must be serializable by
		// the time that write lands — this is the whole defect.
		expect( addressField().disabled ).toBe( false );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( false );
	} );

	it( 'leaves the lock alone when the announcement names no field of THIS entry (#518 control)', () => {
		// The other address column's entry gained no evidence about its own locality, so a
		// promotion there would be unlocking off someone else's pickup selection.
		bootLockedBehindAnImplicitDefault();

		announcePickupAddressWrite( { shipping_address_1: 'ул. Пункта Выдачи, 1' } );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'is a no-op where #536 already left the field unlocked — a `fixed` default (#518 control)', () => {
		// Without this control the test above would also pass for a change that simply
		// unlocked on every announcement regardless of the record's state.
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:fixed-seed', level: 'settlement' },
			chain: { settlement: { key: 'dadata:fixed-seed', level: 'settlement' } },
			implicit: true,
			defaultLocality: { policy: 'fixed' },
		} );

		expect( addressField().disabled ).toBe( false );

		announcePickupAddressWrite( { billing_address_1: 'ул. Пункта Выдачи, 1' } );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'unlocks once the customer picks a settlement over an implicit default (#502)', async () => {
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:implicit-seed', level: 'settlement' },
			chain: { settlement: { key: 'dadata:implicit-seed', level: 'settlement' } },
			implicit: true,
		} );

		expect( addressField().disabled ).toBe( true );

		await pickSettlementAndSettle();

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'still SCOPES address suggestions by the implicit default while the field is locked (#502)', () => {
		// The default locality keeps doing its own job — the fix touches the lock only, never
		// `scopeKeyFor()`. Read off the address node's own fetch callback, which resolves its
		// scope live at call time.
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:0c5b2444', level: 'settlement' },
			chain: { settlement: { key: 'dadata:0c5b2444', level: 'settlement' } },
			implicit: true,
		} );

		callFor( 'billing_address_1' ).fetch( 'Тверская' );

		const req = fetchCalls[ fetchCalls.length - 1 ];

		expect( req.url ).toContain( 'level=address' );
		expect( req.url ).toContain( 'within=' + encodeURIComponent( 'dadata:0c5b2444' ) );
	} );

	// ISSUE #502, s91 critic finding MAJOR-1. The first version of this fix passed the implicit
	// flag ONLY on the boot-time seed, on the reasoning that a `/select` response is by definition
	// a customer's own pick. The route does persist explicitly — but the `chain` it ANSWERS with is
	// read from the server's own store through the accessor that is itself the lazy trigger for the
	// default-locality policy, so a response that wrote nothing (D7 `cancelled`) or failed to write
	// (`persisted: false`) hands the client the merchant's default guess. Adopting that as explicit
	// re-opened #502 one click after the fix had produced the correct state.
	//
	// A CORRECTION TO THE CRITIC'S OWN WORKED SCENARIO, so a later reader does not chase it: it
	// numbered the reachable path as a cancel of the SETTLEMENT pick. That exact step does not
	// reach the defect — `handleCancelledSelect()` calls `clearChainField( entry, level )`, which
	// sets `entry.records[ level ] = null`, so a cancelled settlement re-locks the address whatever
	// the flag says (verified: the control below, written for that shape first, could not be made
	// to unlock). The finding is real one level down: a cancel at a level BELOW settlement leaves
	// `records.settlement` holding whatever the chain just supplied — hence `ADDRESS_ITEM`,
	// the fixture this describe block already declares further down, rather than a settlement pick.
	it( 'does NOT unlock off a cancelled response whose chain is the implicit default (#502)', async () => {
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );

		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			cancelled: true,
			reason: 'stale_record',
			message: 'Данные не актуальны, выберите заново',
			current: null,
			persisted: false,
			chain: { settlement: { key: 'dadata:store-default', level: 'settlement' } },
			implicit: true,
		} );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'DOES unlock off a cancelled response whose chain is explicit — the control for the test above (#502)', async () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			cancelled: true,
			reason: 'stale_record',
			message: 'Данные не актуальны, выберите заново',
			current: null,
			persisted: false,
			chain: { settlement: { key: 'dadata:a-real-earlier-pick', level: 'settlement' } },
			implicit: false,
		} );
		await flushMicrotasks();

		// Only the flag differs from the test above, so a failure here would mean the fix locks
		// something it must not.
		expect( addressField().disabled ).toBe( false );
	} );

	it( 'does NOT unlock off a persisted:false response whose chain is the implicit default (#502)', async () => {
		// A guest whose session/cart cookie has not initialized: the explicit write fails, the
		// server then resolves and serves the implicit default, and the client used to adopt that
		// default's key as a confirmed customer pick.
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:store-default', level: 'settlement' },
			persisted: false,
			chain: { settlement: { key: 'dadata:store-default', level: 'settlement' } },
			implicit: true,
		} );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'DOES unlock off a persisted:false response whose chain is explicit — the second control (#502)', async () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: false,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
			implicit: false,
		} );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( false );
	} );

	// ISSUE #536 — spec §4.6/D11 amendment, operator decision 25.08.2026: a FIXED default
	// locality is shown to the customer exactly as if they had picked it — including the
	// address unlocking. The DEFAULT record here deliberately carries a DIFFERENT settlement
	// (Тверь) than SETTLEMENT_ITEM (Москва) so a test asserting on it cannot pass by accident
	// off some OTHER fixture's text.
	const DEFAULT_LOCALITY_RECORD = {
		key: 'dadata:tver-1', provider_id: 'dadata', level: 'settlement', country: 'RU',
		region: { name: 'Тверская область', type: 'обл' },
		settlement: { name: 'Тверь', type: 'г' }, label: 'Тверская обл., г Тверь',
	};

	it( 'unlocks a FIXED implicit default on boot — the #536 control for the #502 tests above', () => {
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:tver-1', level: 'settlement' },
			chain: { settlement: { key: 'dadata:tver-1', level: 'settlement' } },
			implicit: true,
			defaultLocality: { policy: 'fixed', record: DEFAULT_LOCALITY_RECORD },
		} );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'stays LOCKED for a GEOIP implicit default even when defaultLocality is present but not fixed (#536)', () => {
		// The operator's decision, verbatim: geoip is a guess and stays invisible — this is the
		// control proving #536 narrowed the #502 rule rather than removing it.
		boot( {
			region: true, settlement: true, address: true,
			current: { key: 'dadata:tver-1', level: 'settlement' },
			chain: { settlement: { key: 'dadata:tver-1', level: 'settlement' } },
			implicit: true,
			defaultLocality: { policy: 'geoip', record: DEFAULT_LOCALITY_RECORD },
		} );

		expect( addressField().disabled ).toBe( true );
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

	it( 're-locks after a country change wipes a settlement that HAD been picked', async () => {
		boot( { region: true, settlement: true, address: true, countries: [ 'RU', 'US' ], levels: {
			RU: { region: true, settlement: true, address: true },
			US: { region: true, settlement: true, address: true },
		} } );

		await pickSettlementAndSettle();
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

	// -----------------------------------------------------------------------
	// #350 amendment: a settlement the provider will never suggest stands the lock down
	// -----------------------------------------------------------------------

	it( 'is NOT locked after an abandon-with-zero-results at settlement level (#350)', () => {
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );

		abandonViaFake( callFor( 'billing_city' ), 'Тьмутаракань' );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'is NOT locked after a below-minChars abandon at settlement level — #350 follow-up (17.08.2026)', () => {
		// A settlement name genuinely shorter than the widget's own minChars used to be a dead
		// end: no fetch ever ran, so onAbandon() never fired and the lock had no exit at all.
		// location-typeahead.js's handleBlur() now reports `resolved: false` for this case —
		// this cascade must unlock on it exactly like the zero-results `resolved: true` case,
		// since onAbandonFor() treats both identically (see that function's own docblock for why).
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );

		const call = callFor( 'billing_city' );

		call.el.value = 'Ку';
		call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		call.onAbandon( { query: 'Ку', resolved: false } );

		expect( addressField().disabled ).toBe( false );
	} );

	it( 're-locks once the abandoned settlement text is edited to something else (#350)', () => {
		boot( { region: true, settlement: true, address: true } );

		abandonViaFake( callFor( 'billing_city' ), 'Тьмутаракань' );
		expect( addressField().disabled ).toBe( false );

		const city = document.getElementById( 'billing_city' );

		// Still no pick — but the text itself changed, so the #350 marker no longer describes it.
		city.value = 'Тьмутаракань-2';
		city.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'a plain "typed but not picked" edit that never went through onAbandon still locks — #337 itself is unweakened (#350)', () => {
		boot( { region: true, settlement: true, address: true } );

		const city = document.getElementById( 'billing_city' );

		// Simulates a customer still mid-search (or one whose search simply never completed) —
		// NOT the #350 zero-results report, which only ever arrives via onAbandon().
		city.value = 'Москва';
		city.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'never clears a field VALUE and never touches the region field on an abandon (#350, operator point 3)', () => {
		boot( { region: true, settlement: true, address: true } );

		const region = document.getElementById( 'billing_state' );

		region.value = 'Московская область';
		region.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		abandonViaFake( callFor( 'billing_city' ), 'Кокошкино' );

		expect( region.value ).toBe( 'Московская область' ); // a parent — never in scope for this.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Кокошкино' ); // the typed text survives.
		expect( addressField().value ).toBe( '' ); // had nothing to begin with — still nothing, not "wiped".
		expect( addressField().disabled ).toBe( false );
	} );

	// -----------------------------------------------------------------------
	// #350 follow-up (operator decision, 17.08.2026): the customer keeps their downstream
	// TEXT on an abandon — only the IDENTITY (the record) is dropped, because it belonged to
	// the settlement they just abandoned. clearDescendants() itself is UNCHANGED and still
	// unconditionally wipes on every text edit (the ORDINARY path — adopting a different
	// settlement still has to clear the old address); a snapshot-and-restore step downstream
	// of it (restoreClearedDescendants(), reached only via onAbandonFor()) puts the TEXT back
	// when the edit turns out to be an abandon rather than a pick.
	// -----------------------------------------------------------------------

	const ADDRESS_ITEM = {
		key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
		record: {
			key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
			settlement: { name: 'Москва', type: 'г' },
			street: { name: 'Тверская', type: 'ул' }, house: '1', label: 'ул Тверская, 1',
		},
	};

	it( 'restores the address TEXT (never the record) after a zero-results settlement abandon (#350 follow-up)', async () => {
		boot( { region: true, settlement: true, address: true } );

		const region = document.getElementById( 'billing_state' );

		region.value = 'Московская область';
		region.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		await pickSettlementAndSettle();
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		expect( addressField().value ).toBe( 'ул Тверская, 1' );

		abandonViaFake( callFor( 'billing_city' ), 'Тьмутаракань' );

		// region: untouched (a parent, never in scope). settlement: the customer's own typed
		// text. address: the TEXT is back, even though clearDescendants() already wiped it the
		// instant the settlement field's `change` fired — but the RECORD is genuinely gone and
		// the field is unlocked, exactly like the plain #350 zero-results case.
		expect( region.value ).toBe( 'Московская область' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тьмутаракань' );
		expect( addressField().value ).toBe( 'ул Тверская, 1' );
		expect( addressField().disabled ).toBe( false );
	} );

	it( 'restores the address TEXT after a below-minChars settlement abandon too', async () => {
		boot( { region: true, settlement: true, address: true } );

		await pickSettlementAndSettle();
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		const call = callFor( 'billing_city' );

		call.el.value = 'Ку';
		call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		call.onAbandon( { query: 'Ку', resolved: false } );

		expect( addressField().value ).toBe( 'ул Тверская, 1' );
		expect( addressField().disabled ).toBe( false );
	} );

	it( 'never overwrites address text the customer typed themselves while the abandon was resolving', async () => {
		boot( { region: true, settlement: true, address: true } );

		await pickSettlementAndSettle();
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		const settlementCall = callFor( 'billing_city' );

		// The settlement `change` fires first (clearDescendants() wipes+snapshots the address),
		// mirroring the real timing onAbandon's own docblock relies on — but this time the
		// customer types their OWN new address into the now-empty field BEFORE the abandon
		// report itself arrives.
		settlementCall.el.value = 'Тьмутаракань';
		settlementCall.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().value ).toBe( '' ); // clearDescendants() already ran.

		addressField().value = 'ул Новая, 5';

		settlementCall.onAbandon( { query: 'Тьмутаракань', resolved: true } );

		// Their own text wins — never clobbered by a restore of the OLD address.
		expect( addressField().value ).toBe( 'ул Новая, 5' );
		expect( addressField().disabled ).toBe( false );
	} );

	it( 'does NOT restore the old address after the settlement is re-typed and then a REAL suggestion is adopted', async () => {
		// The regression guard: the snapshot must never leak into the ordinary cascade path.
		boot( { region: true, settlement: true, address: true } );

		await pickSettlementAndSettle();
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		expect( addressField().value ).toBe( 'ул Тверская, 1' );

		const OTHER_SETTLEMENT_ITEM = {
			key: 'dadata:other', label: 'Тверь', level: 'settlement',
			record: {
				key: 'dadata:other', provider_id: 'dadata', level: 'settlement',
				country: 'RU', settlement: { name: 'Тверь', type: 'г' }, label: 'г Тверь',
			},
		};

		// A genuine pick at settlement — never onAbandon.
		selectViaFake( callFor( 'billing_city' ), OTHER_SETTLEMENT_ITEM );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );
		// The address stays cleared — clearDescendants() wiped it, and nothing restores it for
		// an ordinary pick; adopting a different settlement really must not keep the old street.
		expect( addressField().value ).toBe( '' );
		expect( addressField().disabled ).toBe( false ); // unlocked by the pick itself.
	} );
} );

// -----------------------------------------------------------------------
// issue #536 — spec §4.6/D11 amendment, operator decision 25.08.2026: a FIXED default
// locality is shown to the customer exactly as if they had picked it. `class-checkout-
// config.php::build_location_block()` sends `defaultLocality.record` (the FULL
// Location_Record::to_array() shape) only for the `fixed` policy; `prefill()` writes its
// text into the settlement field and backwards-fills the region, through the same
// writeSilently()/backwardsFill() primitives a real pick uses.
// -----------------------------------------------------------------------

describe( 'issue #536: a FIXED default locality writes its text into the field on boot', () => {
	const DEFAULT_RECORD = {
		key: 'dadata:tver-1', provider_id: 'dadata', level: 'settlement', country: 'RU',
		region: { name: 'Тверская область', type: 'обл' },
		settlement: { name: 'Тверь', type: 'г' }, label: 'Тверская обл., г Тверь',
	};

	function bootWithDefaultLocality( extra ) {
		return boot( Object.assign(
			{
				region: true, settlement: true, address: true,
				current: { key: 'dadata:tver-1', level: 'settlement' },
				chain: { settlement: { key: 'dadata:tver-1', level: 'settlement' } },
				implicit: true,
				defaultLocality: { policy: 'fixed', record: DEFAULT_RECORD },
			},
			extra || {}
		) );
	}

	it( 'writes the settlement field\'s text from the default record\'s own component (typeahead mode)', () => {
		bootWithDefaultLocality();

		// fieldValueFor() derives from the component's bare `name`, never the ancestor-carrying
		// `label` — "Тверь", not "Тверская обл., г Тверь" (see that function's own docblock).
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );
	} );

	it( 'backwards-fills the region field from the SAME default record, no second lookup', () => {
		bootWithDefaultLocality();

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Тверская область' );
	} );

	it( 'does NOT write any text when the policy is geoip, even though implicit is true (control)', () => {
		bootWithDefaultLocality( { defaultLocality: { policy: 'geoip', record: DEFAULT_RECORD } } );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
	} );

	it( 'does NOT write any text when defaultLocality is absent (older server) — the #502 tests\' own baseline', () => {
		bootWithDefaultLocality( { defaultLocality: undefined } );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
	} );

	it( 'does NOT write any text for an EXPLICIT customer record, even when defaultLocality is present (nothing to seed)', () => {
		bootWithDefaultLocality( { implicit: false } );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
	} );

	it( 'still fires woodev_location_applied with implicit:true — the flag itself stays truthful regardless of source (issue #536, #309)', () => {
		var received = null;
		document.body.addEventListener( 'woodev_location_applied', function( e ) {
			received = e.detail;
		} );

		bootWithDefaultLocality();

		expect( received ).not.toBeNull();
		expect( received.implicit ).toBe( true );
	} );

	/**
	 * The blocker the operator's own brief called out: `ajax-select2` replaces the settlement
	 * `<input>` with a real `<select>` (`location-select-modes.js::buildSelectField()`), and an
	 * unmatched `.value` write there submits NOTHING (gotcha
	 * `a-select-value-write-with-no-matching-option-submits-nothing`). Boots the REAL
	 * `location-select-modes.js` registry (never the fake typeahead the rest of this describe
	 * block uses) to prove the integration: `prefill()` writes the plain `<input>`'s `.value`
	 * BEFORE `attachAll()` converts it, so `buildSelectField()`'s own issue #447 seeding
	 * (`initialValue = input.value`) picks it up as a REAL, selected `<option>` — not a blank
	 * select2 with the write silently lost.
	 */
	it( 'ajax-select2: the seeded initialValue mechanism (issue #447) picks up the default text and SUBMITS it', () => {
		installMarkup(
			{ region: true, settlement: true, address: true },
			'RU'
		);

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		installFakeSelect2( window.jQuery );

		window[ CONFIG_GLOBAL ] = buildConfig( {
			region: true, settlement: true, address: true,
			mode: { settlement: 'ajax-select2' },
			current: { key: 'dadata:tver-1', level: 'settlement' },
			chain: { settlement: { key: 'dadata:tver-1', level: 'settlement' } },
			implicit: true,
			defaultLocality: { policy: 'fixed', record: DEFAULT_RECORD },
		} );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		const select = document.getElementById( 'billing_city' );

		expect( select.tagName ).toBe( 'SELECT' );
		// Half 1 of the gotcha: a value with NO matching <option> reads back as ''. This proves
		// a real, matching option exists — the write SUBMITS, not just "looks right" in a variable.
		expect( select.value ).toBe( 'Тверь' );
		expect( select.options[ select.selectedIndex ].textContent ).toBe( 'Тверь' );
	} );

	/**
	 * Issue #536 ROUND 2 — rig-measured (fresh guest, incognito, `related-list` region axis,
	 * `fixed` policy): the region ancestor is very often STILL a plain WooCommerce `<input>` at
	 * `prefill()` time — a fresh guest has no session country/state yet, so PHP has nothing to
	 * render states FOR — and WooCommerce's OWN `assets/js/frontend/country-select.js` promotes
	 * it to a real, state-populated `<select>` client-side, ASYNCHRONOUSLY relative to this
	 * module's own boot (`wc_address_i18n_ready`, no ordering guarantee). See
	 * `applyPendingDefaultLocality()`'s own docblock in the source for the full measured trace.
	 *
	 * Reproduces WooCommerce's OWN failed value-carry exactly as `country-select.js` does it:
	 * captures `$statebox.val()` BEFORE rebuilding, rebuilds with the `related-list` WC-canonical
	 * uppercase VALUE convention (`wc_strtoupper(trim(label))`), then restores by that captured
	 * value via `.val(value).trigger('change')`. `capturedValue` is read from the LIVE field
	 * right before the simulated rebuild — exactly what WooCommerce's own handler would see —
	 * which is what makes this test discriminate the fix: the OLD code wrote the bare display
	 * text into the `<input>` immediately, so the capture is a non-empty string that can never
	 * match the rebuilt option's uppercase value, WC's restore fails, and the resulting genuine
	 * empty `change` reads (in the OLD code) as a real parent edit — this module's own
	 * `clearDescendants()` then wipes the settlement default it had JUST correctly written.
	 */
	it( 'survives WooCommerce\'s own async input->select promotion of a related-list region field, without wiping the settlement default (issue #536 round 2)', () => {
		bootWithDefaultLocality( { mode: { region: 'related-list' } } );

		// Settlement's own text is unaffected by the region promotion hazard — written
		// immediately, exactly like the baseline test above.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );

		const input = document.getElementById( 'billing_state' );

		expect( input.tagName ).toBe( 'INPUT' );

		const select = document.createElement( 'select' );
		const blank = document.createElement( 'option' );

		blank.value = '';
		select.appendChild( blank );

		const option = document.createElement( 'option' );

		option.value = 'ТВЕРСКАЯ ОБЛАСТЬ';
		option.textContent = 'Тверская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;

		const capturedValue = input.value; // WooCommerce's OWN `value = $statebox.val()` capture.

		input.parentNode.replaceChild( select, input );
		select.value = capturedValue; // WooCommerce's OWN `$statebox.val(value)` restore attempt.
		window.jQuery( select ).trigger( 'change' );
		window.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );

		const regionEl = document.getElementById( 'billing_state' );

		expect( regionEl.tagName ).toBe( 'SELECT' );
		// The default landed AFTER promotion, matched by TEXT against the real WC-canonical
		// option — never a fabricated value the state list would reject.
		expect( regionEl.value ).toBe( 'ТВЕРСКАЯ ОБЛАСТЬ' );
		expect( regionEl.selectedOptions[ 0 ].textContent ).toBe( 'Тверская область' );

		// The measured symptom itself: the settlement default must survive the region's own
		// (would-be) false transition — it must never have been read as a real parent edit.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );
	} );

	/**
	 * The safety net's OTHER half: a customer who picks their OWN settlement BEFORE
	 * WooCommerce's promotion ever completes must never have that pick overwritten by the
	 * merchant's stale default once the deferred retry finally fires. `entry.records.settlement`
	 * no longer carries the implicit default's own key once `onSelectFor()` runs — see
	 * `applyPendingDefaultLocality()`'s own docblock for exactly what disarms it.
	 */
	it( 'does NOT resurrect the default over a customer\'s own pick made before the region field is promoted (issue #536 round 2, control)', () => {
		bootWithDefaultLocality( { mode: { region: 'related-list' } } );

		selectViaFake( callFor( 'billing_city' ), {
			key: 'dadata:city2', label: 'Жуковский', level: 'settlement',
			record: {
				key: 'dadata:city2', provider_id: 'dadata', level: 'settlement', country: 'RU',
				region: { name: 'Московская область', type: '' },
				settlement: { name: 'Жуковский', type: '' }, label: 'Жуковский',
			},
		} );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Жуковский' );

		const input = document.getElementById( 'billing_state' );
		const select = document.createElement( 'select' );
		const blank = document.createElement( 'option' );

		blank.value = '';
		select.appendChild( blank );

		const option = document.createElement( 'option' );

		option.value = 'ТВЕРСКАЯ ОБЛАСТЬ';
		option.textContent = 'Тверская область';
		select.appendChild( option );
		select.id = input.id;
		select.name = input.name;
		input.parentNode.replaceChild( select, input );
		window.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );

		// The merchant's default ('Тверская область') never gets forced in over the customer's
		// own pick — the settlement default's own retry disarmed the moment the real pick landed.
		expect( document.getElementById( 'billing_state' ).value ).not.toBe( 'ТВЕРСКАЯ ОБЛАСТЬ' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Жуковский' );
	} );
} );

// -----------------------------------------------------------------------
// issue #517 — the #350 escape hatch (onAbandon -> entry.unresolved.settlement -> the address
// lock stands down) never fired at all in the two select2-backed modes, and even if it had,
// `settlementTextIsKnownUnresolved()` compared the marker against `document.getElementById(
// fieldId).value` — a `<select>`'s own `.value` (what `buildSelectField()` replaces the plain
// `<input>` with) never carries the customer's typed-but-unresolved search text, only a real
// picked option's value or ''. This suite boots the REAL `location-select-modes.js` alongside
// the REAL `location-cascade.js` (never the fake typeahead stand-in the rest of this file uses
// for the settlement field) to prove the FULL, end-to-end DOM effect — the address field's own
// `disabled` attribute and locked class — not just the internal marker.
// -----------------------------------------------------------------------

describe( 'issue #517: onAbandon unlocks the address field through the select2-backed modes too', () => {
	/**
	 * Boots the REAL `location-select-modes.js` registry ahead of the REAL
	 * `location-cascade.js` — mirrors the real enqueue order
	 * (`class-checkout-handler.php::enqueue_assets()` declares select-modes.js a hard
	 * dependency of the cascade file) — instead of this file's own `boot()`, which never loads
	 * select-modes.js at all and drives everything through the fake `WoodevLocationTypeahead`.
	 * The baseline (address) field still gets the fake typeahead; only the settlement field's
	 * mode-specific renderer is real.
	 *
	 * @param {Object} configOpts Same shape as `boot()`'s own.
	 * @returns {Array<Object>} the fake-select2 `instances` array (see `support/fake-select2.js`) —
	 *   populated synchronously by the time this returns, since `boot()` runs at require time.
	 */
	function bootWithRealSelectModes( configOpts ) {
		// Issue #528: this whole describe block predates the merchant opt-in and exercises
		// the #517 onAbandon/unlock mechanism on the assumption it just works — default it ON
		// here so every existing test keeps pinning that mechanism unchanged. The dedicated
		// #528 sub-block below overrides this to `false` to prove the gate itself.
		const opts = Object.assign( { allowCustomSettlement: true }, configOpts );

		installMarkup( opts, opts && opts.country );

		global.jQuery = require( 'jquery' );
		global.$ = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);

		fakeTypeahead();
		mockFetch();

		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		const instances = installFakeSelect2( window.jQuery );

		window[ CONFIG_GLOBAL ] = buildConfig( opts );

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		return instances;
	}

	function addressField() {
		return document.getElementById( 'billing_address_1' );
	}

	/**
	 * Issue #517: the ajax-select2/related-list `onAbandon` report is RECORDED at
	 * search-completion time and only actually FIRED on `select2:close` (the select2 analogue
	 * of the baseline typeahead's blur-only decision) — see `location-select-modes.js`'s own
	 * `recordAbandonCandidate`/`handleSelect2Close` docblocks. `fieldId` defaults to the
	 * settlement field this describe block uses throughout (`billing_city`).
	 *
	 * Round 3 (critic MJ-3): the flush this dispatches is now DEFERRED via `setTimeout( fn, 0 )`
	 * — a macrotask, measured on the rig to survive `select2:select` arriving right after
	 * `close` (see that function's own docblock) — so this helper is `async` and awaits the
	 * SAME macrotask via {@see tick} before returning; every caller must `await` it.
	 *
	 * @param {string} [fieldId]
	 * @returns {Promise<void>}
	 */
	async function closeSettlementSelect2( fieldId ) {
		window.jQuery( '#' + ( fieldId || 'billing_city' ) ).trigger( 'select2:close' );
		await tick();
	}

	/**
	 * See `location-select-modes.test.js`'s own identical helper — a microtask chain resolves
	 * BEFORE any `setTimeout`, so it alone is never enough to observe a deferred flush.
	 *
	 * @returns {Promise<void>}
	 */
	function tick() {
		return new Promise( function( resolve ) {
			setTimeout( resolve, 0 );
		} );
	}

	it( 'ajax-select2: a completed, non-empty, zero-result settlement search unlocks the address field once the dropdown closes', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		// #337 baseline: locked with no settlement confirmed, unaffected by the renderer change.
		expect( addressField().disabled ).toBe( true );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );
		expect( document.getElementById( 'billing_city' ).tagName ).toBe( 'SELECT' );

		expect( instances ).toHaveLength( 1 );

		instances[ 0 ].query( 'Тьмутаракань' );

		const req = fetchCalls[ fetchCalls.length - 1 ];
		expect( req.url ).toContain( SUGGEST_URL );

		req.resolve( { suggestions: [] } );
		await flushMicrotasks();

		// The search completed — but MJ-1: nothing fires until the dropdown actually closes.
		expect( addressField().disabled ).toBe( true );

		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( false );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( false );
	} );

	it( 'ajax-select2 control: a completed settlement search WITH suggestions does NOT unlock the address field, even after select2:close', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		expect( addressField().disabled ).toBe( true );

		instances[ 0 ].query( 'Моск' );

		const req = fetchCalls[ fetchCalls.length - 1 ];

		req.resolve( { suggestions: [
			{ key: 'dadata:msk', label: 'г Москва', level: 'settlement', record: { key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва' } },
		] } );
		await flushMicrotasks();

		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'ajax-select2 control: a query below minimumInputLength never reaches the network and never unlocks the address field', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		const fetchCallsBefore = fetchCalls.length;

		// settlement's own floor is 2. Issue #530 ROUND 2 (BLOCKER 2): a settlement widget
		// always carries a `popular()` fn, so `minimumInputLengthFor()`'s real floor is now
		// scoped to 0 at the select2 CONFIG level — the fake's own gate (which mirrors
		// select2's `MinimumInputLength` decorator) no longer blocks this call before
		// `transport()` runs. `transport()` itself still short-circuits a below-floor term
		// locally, though: no network call, empty results, same end state as before this round.
		const result = instances[ 0 ].query( 'Т' );

		expect( result ).not.toBeNull();
		expect( result.success ).toHaveBeenCalledWith( { results: [] } );
		expect( fetchCalls.length ).toBe( fetchCallsBefore );

		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( true );
	} );

	it( 'ajax-select2 control: a superseded (aborted) request never unlocks the address field, even once it settles with zero entries and the dropdown closes', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьм' ); // left pending
		const staleReq = fetchCalls[ fetchCalls.length - 1 ];

		instances[ 0 ].query( 'Тьмутаракань' ); // supersedes it
		const liveReq = fetchCalls[ fetchCalls.length - 1 ];

		expect( staleReq ).not.toBe( liveReq );

		staleReq.resolve( { suggestions: [] } ); // the STALE request settles too, also empty
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( true );

		liveReq.resolve( { suggestions: [] } );
		await flushMicrotasks();

		// Still locked — the live query's own candidate is only RECORDED so far.
		expect( addressField().disabled ).toBe( true );

		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( false );
	} );

	it( 'ajax-select2 control: a transport error never unlocks the address field, even after select2:close', async () => {
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьмутаракань' );

		const req = fetchCalls[ fetchCalls.length - 1 ];

		req.reject( new Error( 'network down' ) );
		await flushMicrotasks();

		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( true );

		consoleSpy.mockRestore();
	} );

	it( 're-locks once the unlocked settlement <select> genuinely changes value — the two events that disprove the marker still apply to a <select>', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьмутаракань' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();
		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( false );

		// A real pick — dispatches change on the now-<select> settlement field, exactly like a
		// customer choosing an option. handleFieldChanged() nulls entry.unresolved.settlement on
		// ANY real transition, `<select>` included.
		const select = document.getElementById( 'billing_city' );
		const option = document.createElement( 'option' );

		option.value = 'Тверь';
		select.appendChild( option );
		select.value = 'Тверь';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( true );
	} );

	// -----------------------------------------------------------------------
	// issue #528 — the merchant opt-in: gated abandon (address stays locked when off) and a
	// tag pick unlocking through the SAME onAbandon route, at the full cascade+select-modes
	// integration level (never reachable through location-select-modes.js's own tests alone,
	// which know nothing of entry.records/refreshAddressLocks()).
	// -----------------------------------------------------------------------

	it( 'issue #528 option OFF: a completed, non-empty, zero-result settlement search does NOT unlock the address field, even after select2:close', async () => {
		const instances = bootWithRealSelectModes( {
			settlement: true, address: true, mode: { settlement: 'ajax-select2' }, allowCustomSettlement: false,
		} );

		instances[ 0 ].query( 'Тьмутаракань' );

		const req = fetchCalls[ fetchCalls.length - 1 ];

		req.resolve( { suggestions: [] } );
		await flushMicrotasks();
		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( true );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );
	} );

	it( 'issue #528 option OFF: select2 receives no tags config at all', () => {
		const instances = bootWithRealSelectModes( {
			settlement: true, address: true, mode: { settlement: 'ajax-select2' }, allowCustomSettlement: false,
		} );

		expect( instances[ 0 ].config.tags ).toBeFalsy();
	} );

	it( 'issue #528 option ON (control): select2 receives tags: true', () => {
		const instances = bootWithRealSelectModes( {
			settlement: true, address: true, mode: { settlement: 'ajax-select2' }, allowCustomSettlement: true,
		} );

		expect( instances[ 0 ].config.tags ).toBe( true );
	} );

	// -----------------------------------------------------------------------
	// Critic MJ-A (round 2, #528): the opt-in must be scoped to the SETTLEMENT level. Both
	// axes on ajax-select2 at once, the critic's own identifying control
	// (minimumInputLengthFor('region') === 1, ('settlement') === 2).
	// -----------------------------------------------------------------------

	it( 'critic MJ-A: with BOTH region and settlement on ajax-select2 and the opt-in ON, only the SETTLEMENT widget gets tags — the REGION widget never does', () => {
		const instances = bootWithRealSelectModes( {
			region: true, settlement: true, address: true,
			mode: { region: 'ajax-select2', settlement: 'ajax-select2' },
			allowCustomSettlement: true,
		} );

		expect( instances ).toHaveLength( 2 );

		const byFieldId = {};

		instances.forEach( ( instance ) => {
			byFieldId[ instance.el.attr( 'id' ) ] = instance;
		} );

		// Issue #530 ROUND 2 (BLOCKER 2): `minimumInputLength` is no longer a reliable way to
		// tell these two widgets apart — the settlement widget's floor is now scoped to 0 (it
		// always carries a `popular()` fn; see `location-select-modes.js`'s own
		// `popularAvailable`), so keying by field id instead of by
		// `config.minimumInputLength` is what actually identifies which widget is which here.
		expect( byFieldId.billing_state ).toBeDefined(); // the region widget attached
		expect( byFieldId.billing_city ).toBeDefined(); // the settlement widget attached
		expect( byFieldId.billing_state.config.minimumInputLength ).toBe( 1 ); // region: unaffected, no popular list at that level
		expect( byFieldId.billing_city.config.minimumInputLength ).toBe( 0 ); // settlement: scoped down by BLOCKER 2's own fix
		expect( byFieldId.billing_state.config.tags ).toBeFalsy(); // REGION: must NEVER get tags
		expect( byFieldId.billing_city.config.tags ).toBe( true ); // SETTLEMENT: the merchant's actual opt-in
	} );

	it( 'issue #528: a tag pick unlocks the address field, issues NO /select request, and writes no settlement record', async () => {
		const instances = bootWithRealSelectModes( {
			settlement: true, address: true, mode: { settlement: 'ajax-select2' }, allowCustomSettlement: true,
		} );

		instances[ 0 ].query( 'Тьмутаракань' );

		const req = fetchCalls[ fetchCalls.length - 1 ];

		req.resolve( { suggestions: [] } );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( true ); // still open, nothing fired yet

		const selectCallsBefore = fetchCalls.filter( ( c ) => c.url === SELECT_URL ).length;

		// The customer picks the "add tag" row — select2's own createTag() shape, no `key`.
		// Asserted SYNCHRONOUSLY, with NO `await tick()` first: the tag branch fires
		// immediately (dropdownOpen is already false by the time select2:select runs, per the
		// measured close-before-select order), never through the deferred close-flush macrotask
		// — a version that fell through to the ordinary scheduled flush instead would still
		// unlock eventually (the flush carries this same term), but not yet at this instant.
		instances[ 0 ].pick( { id: 'Тьмутаракань', text: 'Тьмутаракань', newTag: true } );

		expect( addressField().disabled ).toBe( false );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( false );
		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( selectCallsBefore );

		// #528 round 2 (critic MN-B): the card's ENTIRE deliverable — a picked tag's free text
		// actually ending up in the submitted field, which is the only reason #528 exists (an
		// empty <select> made WooCommerce reject the order as a required field). The fake's
		// own `pick()` now models exactly what a real select2/selectWoo pick does (measured
		// live on the rig: a matching <option> exists and the field carries the text) — see
		// that method's own docblock for why a bare `.val()` write would otherwise silently
		// no-op here (gotcha `a-select-value-write-with-no-matching-option-submits-nothing`).
		const settlementSelect = document.getElementById( 'billing_city' );

		expect( settlementSelect.value ).toBe( 'Тьмутаракань' );
		expect( settlementSelect.tagName ).toBe( 'SELECT' );
		expect( settlementSelect.disabled ).toBe( false );

		// Composes with the already-scheduled close-flush — must not double-fire on its tick.
		await tick();
		expect( addressField().disabled ).toBe( false );
	} );

	// -----------------------------------------------------------------------
	// issue #517 round 2 (critic BL-1 — BLOCKER): the marker outlived the state it was proven
	// in. clearDescendants()/clearCountryScope() blank the settlement <select> on an ANCESTOR
	// edit (a region or country change) but, before this round, never cleared
	// entry.unresolved.settlement — so the #350 marker survived the field going empty and
	// permanently unlocked the address, in a region/country the customer never searched, with
	// no settlement record at all. PROBE A1/A2 are the critic's own reproduction, promoted to
	// permanent regression tests; A3 is the <input> control proving the divergence was new (the
	// baseline typeahead already re-locks here, by construction of the live-DOM text match).
	// -----------------------------------------------------------------------

	it( 'PROBE A1 (<select>): a REGION change after an abandoned settlement search re-locks the address field', async () => {
		const instances = bootWithRealSelectModes( { region: true, settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьмутаракань' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();
		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( false ); // the #350/#517 fix working, same as the tests above.

		// The customer realises the region was wrong and changes it — an ORDINARY ancestor
		// edit, never a pick and never an abandon. clearDescendants() blanks the settlement
		// <select> and nulls its record as a side effect.
		const region = document.getElementById( 'billing_state' );

		region.value = 'Другой регион';
		region.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( addressField().disabled ).toBe( true );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );
	} );

	it( 'PROBE A2 (<select>): a COUNTRY change after an abandoned settlement search re-locks the address field', async () => {
		// Both RU and AM stay served (region+settlement+address) so the switch re-scopes the
		// SAME entry rather than detaching it outright — isolating the marker-staleness claim
		// from D15's own country-support gate.
		const instances = bootWithRealSelectModes( {
			settlement: true, address: true, mode: { settlement: 'ajax-select2' },
			countries: [ 'RU', 'AM' ],
			levels: { RU: { region: true, settlement: true, address: true }, AM: { region: true, settlement: true, address: true } },
		} );

		instances[ 0 ].query( 'Тьмутаракань' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();
		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( false );

		// The fixture's own country <select> only ships RU/US — append the option the config
		// above actually declared support for, mirroring this file's own precedent for the
		// same reason (a value assigned to a <select> with no matching option silently yields
		// '', reading as "country not supported" and detaching everything).
		const countrySelect = document.getElementById( 'billing_country' );
		const am = document.createElement( 'option' );

		am.value = 'AM';
		am.textContent = 'Армения';
		countrySelect.appendChild( am );

		countrySelect.value = 'AM';
		countrySelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( addressField().disabled ).toBe( true );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );
	} );

	it( 'PROBE A3 (<input> CONTROL): the identical region-change sequence already re-locks the baseline typeahead — the divergence is the <select> branch alone', async () => {
		// No select-modes.js, no mode override — the plain fake-typeahead boot() this file uses
		// everywhere else, so the settlement field stays a plain <input>.
		boot( { region: true, settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );

		abandonViaFake( callFor( 'billing_city' ), 'Тьмутаракань' );

		expect( addressField().disabled ).toBe( false ); // same #350 fix, pre-existing on main.

		const region = document.getElementById( 'billing_state' );

		region.value = 'Другой регион';
		region.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// The pre-existing <input> rule already gets this right BY CONSTRUCTION: the blanked
		// field's live text no longer matches the marker, with no change needed to reach this —
		// proof that BL-1 is a regression this PR's <select> branch introduced, not a
		// pre-existing gap in #350/#337 itself.
		expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
		expect( addressField().disabled ).toBe( true );
	} );

	// -----------------------------------------------------------------------
	// issue #517 round 2 (critic MJ-1): a customer typing straight through a non-matching
	// prefix to a real pick must never have their already-confirmed address text disturbed —
	// restoreClearedDescendants() consumes its snapshot unconditionally, and firing onAbandon
	// per keystroke (the pre-fix behaviour) risked exactly that. This is the cascade-level
	// proof: with the fix, onAbandon (hence restoreClearedDescendants) is provably never
	// invoked at all before a subsequent select2:close, so the address ends the sequence
	// exactly where an ORDINARY settlement pick leaves it — cleared by clearDescendants()'s own
	// unconditional wipe, never restored to the OLD settlement's street mid-typing.
	// -----------------------------------------------------------------------

	it( 'typing a non-matching prefix on the way to a real pick never restores the previous settlement\'s address text', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		// Confirm settlement = Москва, then address = ул. Тверская, 1 — the starting state the
		// critic's own scenario names.
		instances[ 0 ].query( 'Моск' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [
			{ key: 'dadata:msk', value: 'Москва', label: 'г Москва', level: 'settlement', record: { key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва' } },
		] } );
		await flushMicrotasks();

		const settlementSelect = document.getElementById( 'billing_city' );
		const moscowOption = document.createElement( 'option' );

		moscowOption.value = 'Москва';
		settlementSelect.appendChild( moscowOption );
		settlementSelect.value = 'Москва';
		settlementSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( settlementSelect ).trigger( window.jQuery.Event( 'select2:select', {
			params: { data: { id: 'Москва', text: 'г Москва', key: 'dadata:msk' } },
		} ) );

		selectViaFake( callFor( 'billing_address_1' ), {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				settlement: { name: 'Москва', type: 'г' },
				street: { name: 'Тверская', type: 'ул' }, house: '1', label: 'ул Тверская, 1',
			},
		} );

		expect( addressField().value ).toBe( 'ул Тверская, 1' );

		// Now the customer changes their mind: types a non-matching prefix ("Тве" — zero
		// results, RECORDED per MJ-1, never fired), then keeps typing to a real match
		// ("Тверь") and picks it — all WITHOUT ever closing the dropdown on the failed prefix.
		instances[ 0 ].query( 'Тве' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();

		// Still holding the OLD confirmed value and address — nothing fired, nothing restored,
		// nothing wiped yet (typing alone never dispatches change on a <select>).
		expect( addressField().value ).toBe( 'ул Тверская, 1' );

		const tverResult = instances[ 0 ].query( 'Тверь' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [
			{ key: 'dadata:tver', value: 'Тверь', label: 'г Тверь', level: 'settlement', record: { key: 'dadata:tver', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Тверь', type: 'г' }, label: 'г Тверь' } },
		] } );
		await flushMicrotasks();

		const tverOption = document.createElement( 'option' );

		tverOption.value = 'Тверь';
		settlementSelect.appendChild( tverOption );
		settlementSelect.value = 'Тверь';
		settlementSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( settlementSelect ).trigger( window.jQuery.Event( 'select2:select', {
			params: { data: { id: 'Тверь', text: 'г Тверь', key: 'dadata:tver' } },
		} ) );

		// The pick is what runs clearDescendants() for settlement's own edit — wiping the OLD
		// Moscow street. The claim under test: it must NEVER have been "restored" to the old
		// street first by the earlier "Тве" abandon report (which never fired at all — it was
		// only recorded, and the pick above cleared that recording before any close could ever
		// flush it). If it HAD fired mid-typing, the address would read the OLD Moscow street
		// UNDER Тверь here — the exact corruption MJ-1 describes.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );
		expect( addressField().value ).toBe( '' );

		// Closing the dropdown now must not resurrect anything either — the pick already
		// cleared the pending candidate.
		await closeSettlementSelect2();

		expect( addressField().value ).toBe( '' );
	} );

	// -----------------------------------------------------------------------
	// issue #517 round 3 — three critic probes, promoted to permanent cascade-level tests
	// (the same "assert the observable DOM state" shape as PROBE A1/A2/A3 above).
	// -----------------------------------------------------------------------

	it( 'PROBE P1: a zero-result term SUPERSEDED by a later matching search does NOT unlock the address on close (critic BL-2)', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тве' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } ); // zero — recorded
		await flushMicrotasks();

		instances[ 0 ].query( 'Тверь' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [       // found — must clear the stale candidate
			{ key: 'dadata:tver', label: 'г Тверь', level: 'settlement', record: { key: 'dadata:tver', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Тверь', type: 'г' }, label: 'г Тверь' } },
		] } );
		await flushMicrotasks();

		// The customer clicks away WITHOUT picking.
		await closeSettlementSelect2();

		expect( addressField().disabled ).toBe( true );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( true );
	} );

	it( 'PROBE P1c (<input> CONTROL): the baseline typeahead never reaches this state at all — a completed search WITH results adopts the first one rather than reporting an abandon', async () => {
		boot( { settlement: true, address: true } );

		expect( addressField().disabled ).toBe( true );

		const call = callFor( 'billing_city' );
		const record = {
			key: 'dadata:tver', label: 'г Тверь', level: 'settlement',
			record: { key: 'dadata:tver', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Тверь', type: 'г' }, label: 'г Тверь' },
		};

		// The typeahead's own handleBlur() (location-typeahead.js) adopts the first result on
		// a completed search that found something — it never reports onAbandon at all for
		// that case, so there is no stale marker for a later blur to have left behind.
		selectViaFake( call, record );

		// s90: the address unlocks on the SERVER's confirmation of the pick, not the optimistic
		// click itself — settle the /select round trip this pick just queued (same convention
		// as `settleLastSelect()`'s own callers elsewhere in this file).
		await settleLastSelect( record );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'г Тверь' );
		expect( addressField().disabled ).toBe( false ); // unlocked by the REAL, CONFIRMED pick, not a stale marker.
	} );

	it( 'PROBE P2: a genuine pick clears the recorded candidate even though select2:close fires BEFORE select2:select — the MEASURED rig order (critic MJ-3)', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		// Confirm settlement = Москва, then address = ул. Тверская, 1 — the critic's own
		// starting state, needed to make the corruption OBSERVABLE (nothing to wrongly
		// restore without a confirmed address already sitting there).
		instances[ 0 ].query( 'Моск' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [
			{ key: 'dadata:msk', value: 'Москва', label: 'г Москва', level: 'settlement', record: { key: 'dadata:msk', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Москва', type: 'г' }, label: 'г Москва' } },
		] } );
		await flushMicrotasks();

		const settlementSelect = document.getElementById( 'billing_city' );
		const moscowOption = document.createElement( 'option' );

		moscowOption.value = 'Москва';
		settlementSelect.appendChild( moscowOption );
		settlementSelect.value = 'Москва';
		settlementSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( settlementSelect ).trigger( window.jQuery.Event( 'select2:select', {
			params: { data: { id: 'Москва', text: 'г Москва', key: 'dadata:msk' } },
		} ) );

		selectViaFake( callFor( 'billing_address_1' ), {
			key: 'dadata:addr1', label: 'ул Тверская, 1', level: 'address',
			record: {
				key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
				settlement: { name: 'Москва', type: 'г' },
				street: { name: 'Тверская', type: 'ул' }, house: '1', label: 'ул Тверская, 1',
			},
		} );

		expect( addressField().value ).toBe( 'ул Тверская, 1' );

		// An EARLIER search finds "Тверь" (populates dataByKey) — BEFORE the failed one, so
		// BL-2's own "clear on found results" never gets a chance to clear anything here.
		instances[ 0 ].query( 'Тверь' );
		const firstReq = fetchCalls[ fetchCalls.length - 1 ];

		firstReq.resolve( { suggestions: [
			{ key: 'dadata:tver', value: 'Тверь', label: 'г Тверь', level: 'settlement', record: { key: 'dadata:tver', provider_id: 'dadata', level: 'settlement', country: 'RU', settlement: { name: 'Тверь', type: 'г' }, label: 'г Тверь' } },
		] } );
		await flushMicrotasks();

		// A LATER search for "Тве" completes with zero results — recorded, and STILL pending
		// right up to the pick below (no interim "found" search AFTER it to clear it via BL-2).
		instances[ 0 ].query( 'Тве' );
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();

		// The pick of the EARLIER "Тверь" result — MEASURED order: change, closing, close,
		// THEN select. `handleFieldChanged()`'s own `clearDescendants()` (bound to the `change`
		// dispatched below) wipes the address AND snapshots it into `entry.clearedByEdit`
		// FIRST — a live snapshot exists in the window between this `change` and the
		// `select2:select` below, exactly the window MJ-3 is about.
		const select = document.getElementById( 'billing_city' );
		const tverOption = document.createElement( 'option' );

		tverOption.value = 'Тверь';
		select.appendChild( tverOption );
		select.value = 'Тверь';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		window.jQuery( '#billing_city' ).trigger( 'select2:closing' );
		window.jQuery( '#billing_city' ).trigger( 'select2:close' );

		window.jQuery( '#billing_city' ).trigger( window.jQuery.Event( 'select2:select', {
			params: { data: { id: 'Тверь', text: 'г Тверь', key: 'dadata:tver' } },
		} ) );

		await tick();

		// The claim under test: the address must show whatever the pick's own clearDescendants()
		// left it as (wiped, per the ordinary "adopting a different settlement clears the old
		// address" rule) — NEVER the OLD Moscow street restored under the newly-picked Тверь,
		// which is exactly what an inline (non-deferred) close-flush would have done.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Тверь' );
		expect( addressField().value ).toBe( '' );
	} );

	it( 'PROBE P3: a zero-result response ARRIVING AFTER the dropdown already closed unlocks the address immediately (critic MJ-4)', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьмутаракань' ); // request goes out, dropdown open
		window.jQuery( '#billing_city' ).trigger( 'select2:closing' );
		window.jQuery( '#billing_city' ).trigger( 'select2:close' ); // customer clicks away BEFORE the response lands

		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } ); // the response finally arrives
		await flushMicrotasks();

		// No further close, no tick needed — MJ-4's own immediate-fire branch is what unlocks
		// this, the moment the response is recorded, not the (already-passed) close event.
		expect( addressField().disabled ).toBe( false );
		expect( addressField().classList.contains( 'woodev-location-locked' ) ).toBe( false );
	} );

	it( 'PROBE P3 control: a zero-result response arriving WHILE the dropdown is still open leaves the address locked until close', async () => {
		const instances = bootWithRealSelectModes( { settlement: true, address: true, mode: { settlement: 'ajax-select2' } } );

		instances[ 0 ].query( 'Тьмутаракань' ); // dropdown still open, never closed
		fetchCalls[ fetchCalls.length - 1 ].resolve( { suggestions: [] } );
		await flushMicrotasks();

		expect( addressField().disabled ).toBe( true );
	} );
} );

describe( 'buildChain() tie-break when Rule 7b fans a field into both sections (issue #458)', () => {
	/**
	 * Boots ONE cascade entry whose `config.fields` carries BOTH a `billing_city` and a
	 * `shipping_city`, both claiming the SAME `location_level: 'settlement'` — the shape
	 * `Checkout_Handler::effective_fields()` now produces for a `source_location()` field
	 * under any `woocommerce_ship_to_destination` value except `billing_only` (issue #458).
	 * `boot()`/`buildConfig()`/`installMarkup()` cannot express two fields in ONE config
	 * directly (both apply a single section to the whole config/markup) — the same
	 * limitation the "section-aware addressing" describe block above already documents for
	 * its own harness; that block works around it with TWO separate config globals (two
	 * plugins sharing a page), which is not what this fans out into — Rule 7b's fan-out is
	 * ONE plugin, ONE config, two ids in the SAME `fields` map — so this needs its own,
	 * single-entry harness instead.
	 *
	 * `firstFieldId` controls which key is inserted into `config.fields` FIRST. Before this
	 * fix, `buildChain()` picked whichever field it found first via `Object.keys()`
	 * (insertion order) with no notion of section at all — so each test below deliberately
	 * inserts the field that the OLD code would have picked WRONGLY first, which is what
	 * makes its assertion the one a revert of the fix actually flips (see each test's own
	 * comment).
	 *
	 * @param {boolean} shipToDifferentAddress
	 * @param {string}  firstFieldId `'billing_city'` or `'shipping_city'`
	 * @returns {void}
	 */
	function bootWithBothSectionsInOneEntry( shipToDifferentAddress, firstFieldId ) {
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

		const secondFieldId = 'billing_city' === firstFieldId ? 'shipping_city' : 'billing_city';
		const fields = {};

		[ firstFieldId, secondFieldId ].forEach( ( id ) => {
			fields[ id ] = locationField( 'settlement', 0 === id.indexOf( 'shipping_' ) ? 'shipping' : 'billing' );
		} );

		window[ CONFIG_GLOBAL ] = {
			fields,
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: {
				endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
				nonce: 'test-nonce',
				countries: [ 'RU' ],
				mode: { region: 'typeahead', settlement: 'typeahead' },
				levels: { RU: { region: true, settlement: true, address: true } },
				current: null,
				implicit: false,
				defaultCountry: 'RU',
				i18n: {},
			},
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
	}

	it( 'checkbox CHECKED (shipping is the active address section): shipping_city wins the settlement level, not billing_city', () => {
		// billing_city inserted FIRST — the pre-fix "first key wins" rule would pick
		// billing_city here, which is the WRONG answer once "ship to a different address" is
		// checked. This ordering is what makes the assertion below fail without the
		// section-aware tie-break (verified by reverting buildChain() and re-running).
		bootWithBothSectionsInOneEntry( true, 'billing_city' );

		expect( callFor( 'shipping_city' ) ).toBeDefined();
		expect( callFor( 'billing_city' ) ).toBeUndefined();
	} );

	it( 'checkbox UNCHECKED (billing is the active address section): billing_city wins the settlement level, not shipping_city', () => {
		// shipping_city inserted FIRST — the pre-fix "first key wins" rule would pick
		// shipping_city here, which is the WRONG answer once "ship to a different address" is
		// unchecked. This ordering is what makes the assertion below fail without the
		// section-aware tie-break (verified by reverting buildChain() and re-running).
		bootWithBothSectionsInOneEntry( false, 'shipping_city' );

		expect( callFor( 'billing_city' ) ).toBeDefined();
		expect( callFor( 'shipping_city' ) ).toBeUndefined();
	} );

	/**
	 * Round 3 (Codex critic, HIGH blocker): the two tests above only ever check the winner
	 * picked ONCE, at boot. `buildChain()`'s own tie-break is re-evaluated live only if
	 * something re-derives `entry.chain` after the "ship to a different address" checkbox
	 * changes ({@see rebuildChainForActiveSection}) — before that existed, `entry.chain` stayed
	 * frozen at whatever `buildEntry()` picked at boot, so `applyCountryArbitration()` (which
	 * only ever walks `entry.chain`) detached the now-inactive column's widget (still in the
	 * frozen chain) while the newly-active column's field was never in the chain to begin with,
	 * and so was never attached either — after ONE toggle, NEITHER address column had a live
	 * cascade. These two tests pin BOTH halves in one assertion set each (the new column's
	 * attach AND the old column's detach, plus a call-count check for no-double-attach) so a
	 * partial fix — attach without detach, or vice versa — fails them too.
	 */
	it( 'unchecking the toggle after boot MOVES the live widget from shipping_city to billing_city, not just detaches it (issue #458 round 3)', () => {
		bootWithBothSectionsInOneEntry( true, 'billing_city' );

		var shippingCall = callFor( 'shipping_city' );
		expect( shippingCall ).toBeDefined();
		expect( callFor( 'billing_city' ) ).toBeUndefined();

		var checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = false;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// billing_city — now the active column — must have gained a widget…
		var billingCalls = attachCalls.filter( function( c ) {
			return 'billing_city' === c.el.id;
		} );
		expect( billingCalls.length ).toBe( 1 );

		// …and shipping_city's widget — no longer the active column — must have been detached,
		// not left dangling: the "neither column" bug this pins.
		expect( shippingCall.detach ).toHaveBeenCalled();

		// shipping_city must not have been re-attached (no double-attach).
		var shippingCalls = attachCalls.filter( function( c ) {
			return 'shipping_city' === c.el.id;
		} );
		expect( shippingCalls.length ).toBe( 1 );
	} );

	it( 'checking the toggle after boot MOVES the live widget from billing_city to shipping_city, not just detaches it (issue #458 round 3)', () => {
		bootWithBothSectionsInOneEntry( false, 'shipping_city' );

		var billingCall = callFor( 'billing_city' );
		expect( billingCall ).toBeDefined();
		expect( callFor( 'shipping_city' ) ).toBeUndefined();

		var checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = true;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		var shippingCalls = attachCalls.filter( function( c ) {
			return 'shipping_city' === c.el.id;
		} );
		expect( shippingCalls.length ).toBe( 1 );

		expect( billingCall.detach ).toHaveBeenCalled();

		var billingCalls = attachCalls.filter( function( c ) {
			return 'billing_city' === c.el.id;
		} );
		expect( billingCalls.length ).toBe( 1 );
	} );
} );

/**
 * Round 4 (issue #458, AGENT-RULES.md Rule 7c): "the chain's RECORDS must move with it, not just
 * the widget. Move the widget without moving the records and the customer gets filled fields plus
 * a re-locked address field: exactly the failure #337 and #459 were about."
 *
 * Round 3 moved the widget only. `entry.records` keys by LEVEL, so it survived the swap on its own
 * and it LOOKED sufficient — but `entry.resolved` keys by FIELD id, and the incoming column's field
 * was never seeded. The next `change` on it (WooCommerce fires plenty of programmatic churn; this
 * module's own `prefill()` exists to defuse exactly that at boot) compared its text against
 * `undefined`, read as a real customer edit, and dropped the level's record — re-locking the
 * address field.
 *
 * Verified against WooCommerce's own source rather than assumed: `checkout.js` binds the toggle to
 * `trigger_update_checkout` and to `ship_to_different_address`, which only slides the shipping
 * fieldset; `update_order_review` returns the order-review and payment fragments and never the
 * address fieldsets. WooCommerce does NOT copy one column's address text into the other in the DOM
 * — the copy Rule 7c refers to is `WC_Checkout::get_posted_address_data()`, server-side at submit.
 * So carrying is ours to do, and the incoming column is usually empty.
 */
describe( 'a column swap carries the chain RECORDS, not just the widget (issue #458 round 4)', () => {
	const SETTLEMENT_ITEM = {
		key: 'dadata:city1', label: 'г Москва', level: 'settlement',
		value: 'Москва',
		record: {
			key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU',
			settlement: { name: 'Москва', type: 'г' }, label: 'г Москва', postcode: '101000',
		},
	};

	const ADDRESS_ITEM = {
		key: 'dadata:addr1', label: 'г Москва, ул Тверская, д 1', level: 'address',
		value: 'ул Тверская, 1',
		record: {
			key: 'dadata:addr1', provider_id: 'dadata', level: 'address', country: 'RU',
			settlement: { name: 'Москва', type: 'г' },
			street: { name: 'Тверская', type: 'ул' }, house: '1',
			label: 'г Москва, ул Тверская, д 1', postcode: '101000',
		},
	};

	/**
	 * One entry whose `config.fields` carries the settlement AND address levels fanned across
	 * BOTH columns — the shape `Checkout_Handler::effective_fields()` produces under every
	 * `woocommerce_ship_to_destination` value except `billing_only`. The address level is what
	 * makes the failure observable: its lock is driven by `entry.records.settlement`, so "the
	 * record was dropped by the swap" and "the address re-locked" are the same fact.
	 *
	 * @param {boolean} shipToDifferentAddress
	 * @returns {void}
	 */
	function bootBothColumns( shipToDifferentAddress ) {
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
				<input type="text" id="billing_address_1" name="billing_address_1" value="" />
				<input type="text" id="shipping_address_1" name="shipping_address_1" value="" />
				<input type="text" id="billing_postcode" name="billing_postcode" value="" />
				<input type="text" id="shipping_postcode" name="shipping_postcode" value="" />
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

		window[ CONFIG_GLOBAL ] = {
			fields: {
				billing_city: locationField( 'settlement', 'billing' ),
				shipping_city: locationField( 'settlement', 'shipping' ),
				billing_address_1: locationField( 'address', 'billing' ),
				shipping_address_1: locationField( 'address', 'shipping' ),
			},
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: {
				endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
				nonce: 'test-nonce',
				countries: [ 'RU' ],
				mode: { region: 'typeahead', settlement: 'typeahead' },
				levels: { RU: { region: true, settlement: true, address: true } },
				current: null,
				implicit: false,
				defaultCountry: 'RU',
				i18n: {},
			},
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
	}

	/**
	 * @param {boolean} checked
	 * @returns {void}
	 */
	function toggleShipToDifferentAddress( checked ) {
		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = checked;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * WooCommerce's own programmatic churn on a field it just revealed — the same event
	 * `prefill()` exists to defuse at boot. Carries whatever is currently in the field, so it is
	 * a no-op for a correctly seeded change-gate and a "real transition" for an unseeded one.
	 *
	 * @param {string} fieldId
	 * @returns {void}
	 */
	function fireProgrammaticChange( fieldId ) {
		document.getElementById( fieldId ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	it( 'checking the toggle carries the picked settlement TEXT onto the shipping column', () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'shipping_city' ).value ).toBe( '' );

		toggleShipToDifferentAddress( true );

		// Without the carry, the customer's newly-live column is blank while the chain still
		// claims a picked locality — an identity that lies about the text.
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Москва' );
	} );

	it( 'the carried field survives WooCommerce\'s own change churn — the address does NOT re-lock', async () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		await settleLastSelect( SETTLEMENT_ITEM.record );

		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );

		toggleShipToDifferentAddress( true );
		fireProgrammaticChange( 'shipping_city' );

		// THE regression this round is about: an unseeded `entry.resolved['shipping_city']` makes
		// this change read as a real edit, drops `records.settlement`, and re-locks the address —
		// "filled fields plus a re-locked address field" (Rule 7c, #337, #459).
		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( false );
	} );

	it( 'unchecking the toggle carries the record back the other way too (Rule 7c: BOTH directions)', async () => {
		bootBothColumns( true );
		selectViaFake( callFor( 'shipping_city' ), SETTLEMENT_ITEM );
		await settleLastSelect( SETTLEMENT_ITEM.record );

		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( false );

		toggleShipToDifferentAddress( false );
		fireProgrammaticChange( 'billing_city' );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );
	} );

	it( 'never overwrites an address the customer typed into the incoming column themselves', () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		// The customer checks "ship to a different address" BECAUSE it is a different address,
		// and has already typed one. Carrying billing's locality over it would destroy that.
		document.getElementById( 'shipping_city' ).value = 'Жуковский';

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Жуковский' );

		// …and because the text no longer matches the carried identity, the record is dropped
		// rather than left lying about it — the module's own standing invariant
		// (`handleFieldChanged`: "the field's own record no longer matches its text"), applied at
		// swap time instead of left to whichever change happens to fire first.
		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( true );
	} );

	/**
	 * Round 4 critic, HIGH blocker. Dropping only the CONTRADICTED level's own record leaves its
	 * descendants describing a locality the customer has just disowned — and branch 1 then WRITES
	 * one in, because the address node still sees `records.address` and finds its incoming field
	 * empty. The customer ends up with the city they chose and a street from a different city,
	 * and since `resolved` is now seeded, no later change event runs clearDescendants() to repair
	 * it.
	 *
	 * Against the pre-fix implementation this fails on the FIRST assertion (shipping_address_1
	 * carries 'ул Тверская, 1' instead of staying empty) — the right reason, not an incidental one.
	 */
	it( 'a contradicted parent invalidates the levels BELOW it, and carries none of them', async () => {
		bootBothColumns( false );

		// A full billing chain: settlement Москва, then an address inside it.
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		await settleLastSelect( SETTLEMENT_ITEM.record );
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул Тверская, 1' );

		// The customer types a DIFFERENT settlement into the shipping column and leaves the
		// shipping address empty, then switches the delivery column to it.
		document.getElementById( 'shipping_city' ).value = 'Жуковский';

		toggleShipToDifferentAddress( true );

		// The address of Москва must NOT be written into a column whose city is Жуковский.
		expect( document.getElementById( 'shipping_address_1' ).value ).toBe( '' );

		// …and the orphaned address identity must be gone, not merely unused: with it retained
		// the address field would read as "a locality is picked" and stay unlocked over an
		// address that no longer exists.
		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( true );
	} );

	it( 'a blocked carry withholds the postcode too — it belongs to the disowned locality', () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );

		document.getElementById( 'shipping_city' ).value = 'Жуковский';
		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_postcode' ).value ).toBe( '' );
	} );

	it( 'blocking drops IDENTITY, never text the customer can already see', async () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );
		await settleLastSelect( SETTLEMENT_ITEM.record );
		selectViaFake( callFor( 'billing_address_1' ), ADDRESS_ITEM );

		// This time the customer has typed BOTH halves of their own shipping address.
		document.getElementById( 'shipping_city' ).value = 'Жуковский';
		document.getElementById( 'shipping_address_1' ).value = 'ул Гагарина, 5';

		toggleShipToDifferentAddress( true );

		// Their own text survives untouched — the same rule clearDescendants() follows for an
		// unresolvable parent (#350 follow-up, operator decision 17.08.2026).
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Жуковский' );
		expect( document.getElementById( 'shipping_address_1' ).value ).toBe( 'ул Гагарина, 5' );
	} );

	it( 'releases the OUTGOING address lock — a required field must not stay disabled', () => {
		// Measured on the rig 24.08.2026: refreshAddressLock() only ever walks the chain's CURRENT
		// address node, so the field a column swap leaves behind kept `disabled` and the locked
		// class forever. billing_address_1 is a REQUIRED billing field, and a disabled input is not
		// submitted at all — the customer could no longer complete checkout.
		bootBothColumns( false );

		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( true );

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );
		expect(
			document.getElementById( 'billing_address_1' ).classList.contains( 'woodev-location-locked' )
		).toBe( false );

		// …and the lock moved to the column that is now active, rather than simply vanishing.
		expect( document.getElementById( 'shipping_address_1' ).disabled ).toBe( true );
	} );

	it( 'carries the postcode onto the incoming column as well', () => {
		bootBothColumns( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_postcode' ).value ).toBe( '101000' );
	} );
} );

/**
 * Issue #490: the round-4 tests above all carry an entry.records[level] that is still the FULL
 * optimistic record selectViaFake() just wrote — none of them let the pick's own `/select` round
 * trip resolve before toggling. A real customer always takes longer than that: {@see adoptChain}
 * — called from EVERY successful `/select` response, not only at boot — deliberately narrows
 * `entry.records[level]` down to `{ key, confirmed: true }` once the server confirms it
 * ("confirmed marks PROVENANCE, not validity", adoptChain()'s own docblock). The narrowed shape
 * has no `record[level].name`/`record.label` left for `fieldValueFor()` to read, so a carry
 * attempted AFTER that round trip silently writes nothing — measured on the rig: the region level
 * is always picked and persisted first, so it is reliably narrowed well before any later toggle
 * and never carries; a level picked closer to the toggle (settlement, address) merely RACES the
 * narrowing and carries or not depending on timing, which is why #490's own rig measurement saw it
 * carry in one run and not in an otherwise identical one.
 */
describe( 'a column swap carries the record even after its /select round trip has already resolved (issue #490)', () => {
	const REGION_ITEM = {
		key: 'test-cdek:r15', label: 'Омская область', level: 'region',
		value: 'Омская область',
		record: {
			key: 'test-cdek:r15', provider_id: 'test-cdek', level: 'region', country: 'RU',
			region: { name: 'Омская область', type: 'обл' }, label: 'Омская область',
		},
	};

	const SETTLEMENT_ITEM = {
		key: 'test-cdek:s1', label: 'г Омск', level: 'settlement',
		value: 'Омск',
		record: {
			key: 'test-cdek:s1', provider_id: 'test-cdek', level: 'settlement', country: 'RU',
			settlement: { name: 'Омск', type: 'г' }, label: 'г Омск',
		},
	};

	/**
	 * Mirrors the round-4 `bootBothColumns()` above, with a region field fanned across both
	 * columns as well — issue #490 is specifically about the region level, which that helper
	 * never declared.
	 *
	 * @param {boolean} shipToDifferentAddress
	 * @returns {void}
	 */
	function bootBothColumnsWithRegion( shipToDifferentAddress ) {
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
				<input type="text" id="billing_state" name="billing_state" value="" />
				<input type="text" id="shipping_state" name="shipping_state" value="" />
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

		window[ CONFIG_GLOBAL ] = {
			fields: {
				billing_state: locationField( 'region', 'billing' ),
				shipping_state: locationField( 'region', 'shipping' ),
				billing_city: locationField( 'settlement', 'billing' ),
				shipping_city: locationField( 'settlement', 'shipping' ),
			},
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: {
				endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
				nonce: 'test-nonce',
				countries: [ 'RU' ],
				mode: { region: 'typeahead', settlement: 'typeahead' },
				levels: { RU: { region: true, settlement: true, address: true } },
				current: null,
				implicit: false,
				defaultCountry: 'RU',
				i18n: {},
			},
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
	}

	/**
	 * @param {boolean} checked
	 * @returns {void}
	 */
	function toggleShipToDifferentAddress( checked ) {
		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = checked;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	it( 'carries the region onto the incoming column after its own /select round trip already resolved', async () => {
		bootBothColumnsWithRegion( false );
		selectViaFake( callFor( 'billing_state' ), REGION_ITEM );

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Омская область' );

		// The pick's own persist round trip completes, narrowing entry.records.region to
		// { key, confirmed: true } — see adoptChain()'s own docblock — BEFORE the toggle.
		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		expect( selectReq.url ).toBe( SELECT_URL );
		selectReq.resolve( {
			current: { key: REGION_ITEM.record.key, level: 'region' },
			persisted: true,
			chain: { region: { key: REGION_ITEM.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( true );

		// Against the pre-fix implementation this stays '' — fieldValueFor() has nothing left
		// to read off the narrowed record.
		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Омская область' );
	} );

	it( 'carries the region back the OTHER way too, after its /select round trip already resolved', async () => {
		bootBothColumnsWithRegion( true );
		selectViaFake( callFor( 'shipping_state' ), REGION_ITEM );

		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Омская область' );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( {
			current: { key: REGION_ITEM.record.key, level: 'region' },
			persisted: true,
			chain: { region: { key: REGION_ITEM.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( false );

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Омская область' );
	} );

	it( 'carries the settlement too once its /select round trip has already resolved — not just an optimistic pick', async () => {
		bootBothColumnsWithRegion( false );
		selectViaFake( callFor( 'billing_city' ), SETTLEMENT_ITEM );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		expect( selectReq.url ).toBe( SELECT_URL );
		selectReq.resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Омск' );
	} );
} );

/**
 * Issue #490 round 2: the round-1 tests above all run under `mode: 'typeahead'`, whose widget
 * ({@see window.WoodevLocationTypeahead}, faked by {@see fakeTypeahead}) attaches directly to the
 * field's own `<input>` and never replaces it — so `document.getElementById( fieldId )`, read
 * AFTER `detachOne()` has already run, still finds the same element the pick was written onto.
 * Production settlement/address fields are never `typeahead` when an `ajax-select2` axis is
 * configured (`location-select-modes.js`'s `buildSelectField()`): that widget swaps the
 * `<input>` for a fresh `<select>` on attach and, on `detach()`, restores the ORIGINAL `<input>`
 * VERBATIM — never synced with whatever the customer picked in the `<select>` (its own docblock:
 * "`detach()` restores it verbatim ... never left in place under the SAME id" — restores the
 * ORIGINAL node, not a copy carrying the pick). Measured on the rig (issue #490 round 2): this is
 * exactly why settlement — always `ajax-select2` in production —
 * still did not carry in either direction even after round 1's fix, while region (always
 * {@see attachRelatedListRegion}'s native-`<select>` watcher, never swapped) carried in both.
 *
 * This stub reproduces the swap-then-stale-restore shape closely enough to exercise
 * `location-cascade.js`'s REAL attach/detach/reconcile path against it, registered under the SAME
 * `'ajax-select2'` registry key `resolveModeRenderer()` looks up — only the widget's own select2
 * guts are faked, never the cascade code under test.
 */
describe( 'a column swap carries the settlement even when its widget SWAPS the DOM element on attach (issue #490 round 2)', () => {
	const SETTLEMENT_ITEM = {
		label: 'г Омск',
		value: 'Омск',
		record: {
			key: 'test-cdek:s1', provider_id: 'test-cdek', level: 'settlement', country: 'RU',
			settlement: { name: 'Омск', type: 'г' }, label: 'г Омск',
		},
	};

	/**
	 * Mimics `location-select-modes.js`'s own `buildSelectField()` just closely enough to
	 * reproduce the defect: swaps the plain `<input>` for a fresh `<select>` on attach — seeded
	 * from `el.value` (issue #447's own "Preselect option in AJAX Select2" pattern, captured
	 * BEFORE the `<input>` is replaced, exactly the real `buildSelectField()`) when carrying
	 * ALREADY wrote something there, else a bare placeholder `<option>` (`ajax-select2`'s real
	 * empty-field shape) — and on `detach()` swaps the SAME original `<input>` back in, never
	 * writing the picked text onto it first, mirroring the real widget's own stale restore
	 * exactly.
	 *
	 * @returns {{renderer: Function, pick: function(string, Object, string): void}}
	 */
	function stubAjaxSelect2() {
		var picks = {};

		function renderer( el, options ) {
			if ( ! el || 'INPUT' !== el.tagName ) {
				return null;
			}

			var originalInput = el;
			var initialValue = el.value || '';
			var select = document.createElement( 'select' );
			select.id = el.id;
			select.name = el.name || '';

			if ( initialValue ) {
				var seeded = document.createElement( 'option' );
				seeded.value = initialValue;
				seeded.textContent = initialValue;
				seeded.selected = true;
				select.appendChild( seeded );
			} else {
				select.appendChild( document.createElement( 'option' ) ); // placeholder only, matching #490's rig measurement.
			}

			el.parentNode.replaceChild( select, el );

			picks[ select.id ] = function( record, value ) {
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = value;
				select.appendChild( option );
				select.value = value;
				options.onSelect( { record: record } );
			};

			return {
				el: select,
				detach: function() {
					if ( select.parentNode ) {
						select.parentNode.insertBefore( originalInput, select );
						select.parentNode.removeChild( select );
					}
				},
			};
		}

		return {
			renderer: renderer,
			pick: function( fieldId, record, value ) {
				picks[ fieldId ]( record, value );
			},
		};
	}

	/**
	 * @param {boolean} shipToDifferentAddress
	 * @returns {{renderer: Function, pick: function(string, Object, string): void}}
	 */
	function bootBothColumnsSettlementAjaxSelect2( shipToDifferentAddress ) {
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

		mockFetch();

		var stub = stubAjaxSelect2();
		window.WoodevLocationRenderers = { 'ajax-select2': stub.renderer };

		window[ CONFIG_GLOBAL ] = {
			fields: {
				billing_city: locationField( 'settlement', 'billing' ),
				shipping_city: locationField( 'settlement', 'shipping' ),
			},
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: {
				endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
				nonce: 'test-nonce',
				countries: [ 'RU' ],
				mode: { region: 'typeahead', settlement: 'ajax-select2' },
				levels: { RU: { region: true, settlement: true, address: true } },
				current: null,
				implicit: false,
				defaultCountry: 'RU',
				i18n: {},
			},
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		return stub;
	}

	/**
	 * @param {boolean} checked
	 * @returns {void}
	 */
	function toggleShipToDifferentAddress( checked ) {
		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = checked;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	it( 'carries the settlement onto the incoming column even though the outgoing widget restores a stale <input> on detach', async () => {
		var stub = bootBothColumnsSettlementAjaxSelect2( false );

		// Sanity: this really does exercise the DOM-swapping renderer path.
		expect( document.getElementById( 'billing_city' ).tagName ).toBe( 'SELECT' );

		stub.pick( 'billing_city', SETTLEMENT_ITEM.record, SETTLEMENT_ITEM.value );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		expect( selectReq.url ).toBe( SELECT_URL );
		selectReq.resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( true );

		// Against the pre-fix implementation this reads '' — carryChainStateToIncomingNodes()
		// read document.getElementById('billing_city') AFTER detachOne() had already restored
		// the ORIGINAL, placeholder-only <input> the widget swapped out at attach time, which
		// never received the picked text.
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Омск' );
	} );

	it( 'carries the settlement back the OTHER way too', async () => {
		var stub = bootBothColumnsSettlementAjaxSelect2( true );

		stub.pick( 'shipping_city', SETTLEMENT_ITEM.record, SETTLEMENT_ITEM.value );

		const selectReq = fetchCalls[ fetchCalls.length - 1 ];
		selectReq.resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' } },
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( false );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Омск' );
	} );
} );

/**
 * Round 3 (issue #490): every direction-B test above (round 4, round 2) boots ALREADY
 * unchecked and drives exactly ONE toggle — but this rig's checkbox starts CHECKED by
 * default (`installMarkup()`'s own `checked = false !== w.shipToDifferentAddress`, and
 * WooCommerce's own classic-checkout markup agrees: "ship to a different address" defaults
 * to on). So filling billing at all, on the real rig, is a TWO-toggle sequence: uncheck
 * (shipping -> billing), pick region + settlement in billing, check again (billing ->
 * shipping) — never a single toggle from a clean boot. This describe block drives that full
 * sequence, plus the interleaving a synchronous single-tick jest test cannot otherwise
 * reproduce: a REGION `/select` still in flight when SETTLEMENT is picked right behind it.
 *
 * THE RACE THIS FOUND: `sendNextSelect()`'s single-flight queue is per ENTRY, not per level
 * (see that function's own docblock) — a region pick and a settlement pick made close
 * together queue behind ONE `/select` slot. `adoptChain()` (called from every `/select`
 * response) treats its `chain` argument as authoritative for EVERY level in `LEVELS`,
 * unconditionally: `entry.records[level] = adopted[level] || null` — including a level the
 * server was never ASKED about yet, not just one it genuinely dropped. So the moment the
 * REGION response lands — before SETTLEMENT's own (already-queued) `/select` has even been
 * sent — `adoptChain()` nulls `entry.records.settlement` right out from under the
 * optimistic write `onSelectFor()` already made for it. `carryChainStateToIncomingNodes()`
 * gates its ENTIRE carry (including the round-2 fix's own `outgoingLevelText` read) behind
 * `record ? ... : ''` — so a toggle landing in this exact window carries nothing for
 * settlement, even though the live DOM (and `outgoingLevelText`) still has the picked text
 * sitting right there. This is a genuinely NEW defect, in `adoptChain()`/its interaction with
 * the single-flight queue — round 1 and round 2's own fixes are both still necessary and
 * both still correct; this is a third, independent gap in the same carry path.
 */
describe( 'the real rig sequence: starts CHECKED, uncheck, fill billing, check again (issue #490 round 3)', () => {
	const REGION_ITEM = {
		value: 'Омская область',
		record: {
			key: 'test-cdek:r15', provider_id: 'test-cdek', level: 'region', country: 'RU',
			region: { name: 'Омская область', type: 'обл' }, label: 'Омская область',
		},
	};

	const SETTLEMENT_ITEM = {
		value: 'Омск',
		record: {
			key: 'test-cdek:s1', provider_id: 'test-cdek', level: 'settlement', country: 'RU',
			settlement: { name: 'Омск', type: 'г' }, label: 'г Омск',
		},
	};

	/**
	 * Same shape as the round-2 `stubAjaxSelect2()` above (this describe block cannot reach
	 * that one — scoped to its own `describe`) — reproduces `buildSelectField()`'s swap-on-
	 * attach/stale-restore-on-detach contract closely enough to exercise the real cascade
	 * attach/detach/carry path against it. Generic per field id, so ONE stub instance serves
	 * both the region and the settlement node.
	 *
	 * @returns {{renderer: Function, pick: function(string, Object, string): void}}
	 */
	function stubAjaxSelect2() {
		var picks = {};

		function renderer( el, options ) {
			if ( ! el || 'INPUT' !== el.tagName ) {
				return null;
			}

			var originalInput = el;
			var initialValue = el.value || '';
			var select = document.createElement( 'select' );
			select.id = el.id;
			select.name = el.name || '';

			if ( initialValue ) {
				var seeded = document.createElement( 'option' );
				seeded.value = initialValue;
				seeded.textContent = initialValue;
				seeded.selected = true;
				select.appendChild( seeded );
			} else {
				select.appendChild( document.createElement( 'option' ) );
			}

			el.parentNode.replaceChild( select, el );

			picks[ select.id ] = function( record, value ) {
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = value;
				select.appendChild( option );
				select.value = value;
				options.onSelect( { record: record } );
			};

			return {
				el: select,
				detach: function() {
					if ( select.parentNode ) {
						select.parentNode.insertBefore( originalInput, select );
						select.parentNode.removeChild( select );
					}
				},
			};
		}

		return {
			renderer: renderer,
			pick: function( fieldId, record, value ) {
				picks[ fieldId ]( record, value );
			},
		};
	}

	/**
	 * Boots with BOTH region and settlement fanned across both sections, both axes
	 * `ajax-select2` (production's real shape for these levels — never `typeahead`), and the
	 * checkbox in whichever state the rig's own first screen shows it in.
	 *
	 * @param {boolean} shipToDifferentAddress
	 * @returns {{renderer: Function, pick: function(string, Object, string): void}}
	 */
	function bootRealSequence( shipToDifferentAddress ) {
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
				<input type="text" id="billing_state" name="billing_state" value="" />
				<input type="text" id="shipping_state" name="shipping_state" value="" />
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

		mockFetch();

		var stub = stubAjaxSelect2();
		window.WoodevLocationRenderers = { 'ajax-select2': stub.renderer };

		window[ CONFIG_GLOBAL ] = {
			fields: {
				billing_state: locationField( 'region', 'billing' ),
				shipping_state: locationField( 'region', 'shipping' ),
				billing_city: locationField( 'settlement', 'billing' ),
				shipping_city: locationField( 'settlement', 'shipping' ),
			},
			endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
			nonce: 'test-nonce',
			takeover: {},
			location: {
				endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL },
				nonce: 'test-nonce',
				countries: [ 'RU' ],
				mode: { region: 'ajax-select2', settlement: 'ajax-select2' },
				levels: { RU: { region: true, settlement: true, address: true } },
				current: null,
				implicit: false,
				defaultCountry: 'RU',
				i18n: {},
			},
		};

		require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

		return stub;
	}

	/**
	 * @param {boolean} checked
	 * @returns {void}
	 */
	function toggleShipToDifferentAddress( checked ) {
		const checkbox = document.querySelector( '[name="ship_to_different_address"]' );
		checkbox.checked = checked;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function selectRequests() {
		return fetchCalls.filter( ( c ) => c.url === SELECT_URL );
	}

	it( 'carries both region and settlement through the FULL two-toggle sequence when each /select is awaited before the next step (baseline — must stay green)', async () => {
		var stub = bootRealSequence( true ); // starts CHECKED, like the real rig's first screen

		toggleShipToDifferentAddress( false ); // uncheck — billing becomes the active column

		stub.pick( 'billing_state', REGION_ITEM.record, REGION_ITEM.value );
		selectRequests()[ 0 ].resolve( {
			current: { key: REGION_ITEM.record.key, level: 'region' },
			persisted: true,
			chain: { region: { key: REGION_ITEM.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		stub.pick( 'billing_city', SETTLEMENT_ITEM.record, SETTLEMENT_ITEM.value );
		selectRequests()[ 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: {
				region: { key: REGION_ITEM.record.key, level: 'region' },
				settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			},
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( true ); // check again — shipping becomes active

		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Омская область' );
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Омск' );
	} );

	it( 'DROPS the settlement (never the region) when it is picked while the region /select is still in flight, and the toggle lands before settlement\'s own /select resolves', async () => {
		var stub = bootRealSequence( true );

		toggleShipToDifferentAddress( false );

		// Region is picked and its /select is sent immediately (nothing else in flight).
		stub.pick( 'billing_state', REGION_ITEM.record, REGION_ITEM.value );
		expect( selectRequests().length ).toBe( 1 );

		// Settlement is picked right behind it — REGION's own /select has not resolved yet, so
		// this one queues behind the single-flight slot rather than sending concurrently (same
		// per-entry queue documented on enqueueSelect()'s own docblock).
		stub.pick( 'billing_city', SETTLEMENT_ITEM.record, SETTLEMENT_ITEM.value );
		expect( selectRequests().length ).toBe( 1 ); // still just region's request

		// Region's /select now resolves. The server was never TOLD about settlement yet at the
		// point it built this response, so its own chain can only ever report region — this is
		// not a malformed response, it is the ordinary, honest shape of "settlement hasn't
		// reached the server yet".
		selectRequests()[ 0 ].resolve( {
			current: { key: REGION_ITEM.record.key, level: 'region' },
			persisted: true,
			chain: { region: { key: REGION_ITEM.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		// settleSelect() dequeues the pending settlement record and sends it automatically.
		expect( selectRequests().length ).toBe( 2 );

		// The toggle lands NOW — settlement's own /select is in flight but has not resolved.
		// The customer has already picked both; the DOM already shows both values.
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Омская область' );
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Омск' );

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Омская область' );
		// This is the #490 rig's own 1-in-5 failure, reproduced deterministically: region's
		// /select response narrowed entry.records EVERY level via adoptChain(), including
		// settlement — which the server was never asked about yet — nulling out the
		// optimistic record onSelectFor() had already written for it. carryChainStateTo
		// IncomingNodes() gates its ENTIRE carry behind `entry.records[level]` being truthy
		// BEFORE it ever consults outgoingLevelText, so the live DOM text captured by round
		// 2's own fix is discarded unread.
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Омск' );
	} );

	it( 'still drops the settlement even when BOTH /selects resolve before the toggle, as long as region resolved BEFORE settlement was picked... no — region resolving AFTER settlement was queued is the trigger, not the resolve order relative to the toggle', async () => {
		// This test intentionally documents the boundary: awaiting settlement's OWN /select
		// too (unlike the test above) lets its response re-adopt settlement (adoptChain()
		// resolves it back to a narrowed-but-non-null record), so the carry recovers. The
		// defect is specifically the WINDOW between region's response landing and settlement's
		// own response landing — not the picks' order, not whether the toggle waits.
		var stub = bootRealSequence( true );

		toggleShipToDifferentAddress( false );

		stub.pick( 'billing_state', REGION_ITEM.record, REGION_ITEM.value );
		stub.pick( 'billing_city', SETTLEMENT_ITEM.record, SETTLEMENT_ITEM.value );

		selectRequests()[ 0 ].resolve( {
			current: { key: REGION_ITEM.record.key, level: 'region' },
			persisted: true,
			chain: { region: { key: REGION_ITEM.record.key, level: 'region' } },
		} );
		await flushMicrotasks();

		selectRequests()[ 1 ].resolve( {
			current: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			persisted: true,
			chain: {
				region: { key: REGION_ITEM.record.key, level: 'region' },
				settlement: { key: SETTLEMENT_ITEM.record.key, level: 'settlement' },
			},
		} );
		await flushMicrotasks();

		toggleShipToDifferentAddress( true );

		expect( document.getElementById( 'shipping_state' ).value ).toBe( 'Омская область' );
		expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Омск' );
	} );
} );

// -------------------------------------------------------------------
// Issue #538 — a region the customer did NOT pick still scopes the popular list.
//
// The #536 fixed default fills the region field's TEXT but records no region KEY, and the key is
// not recoverable from the default record either: Location_Record keeps ancestors as a flat SET
// and refuses a `level => key` map deliberately. So popularFor() narrows by ancestor INTERSECTION
// when nothing recorded a parent key.
//
// Measured on the rig before the fix (fixed default «Москва», fresh incognito): the region field
// read «МОСКВА» while the settlement list still offered all six popular entries, three of them in
// Saint Petersburg.
// -------------------------------------------------------------------

describe( 'options.popular() scoped by an AUTO-FILLED region — issue #538', () => {
	const MSK = 'test-cdek:r81';
	const SPB = 'test-cdek:r82';

	const entryFor = ( key, name, region ) => ( {
		key, label: name, level: 'settlement',
		record: {
			key, provider_id: 'test-cdek', level: 'settlement', country: 'RU',
			settlement: { name, type: 'г' }, label: name, ancestors: [ region ],
		},
	} );

	const POPULAR = {
		RU: [
			entryFor( 'test-cdek:137', 'Санкт-Петербург', SPB ),
			entryFor( 'test-cdek:28', 'Внуково', MSK ),
			entryFor( 'test-cdek:394', 'Пушкин', SPB ),
			entryFor( 'test-cdek:44', 'Москва', MSK ),
		],
	};

	const DEFAULT_MSK = {
		key: 'test-cdek:44', provider_id: 'test-cdek', level: 'settlement', country: 'RU',
		region: { name: 'Москва', type: '' }, settlement: { name: 'Москва', type: '' },
		label: 'Москва, Россия', ancestors: [ MSK ],
	};

	const popularCallbackAfterBoot = ( extra ) => {
		const calls = [];

		window.WoodevLocationRenderers = {
			'custom-mode:settlement': ( el, options ) => {
				calls.push( options );

				return { detach: jest.fn() };
			},
			'custom-mode:region': () => ( { detach: jest.fn() } ),
		};

		boot( { region: true, settlement: true, mode: 'custom-mode', popular: POPULAR, ...extra } );

		return calls[ 0 ].popular;
	};

	it( 'narrows to the auto-filled region — the defect the operator saw: three foreign-region entries were offered under «Москва»', () => {
		const popular = popularCallbackAfterBoot( {
			implicit: true,
			current: { key: 'test-cdek:44', level: 'settlement' },
			chain: { settlement: { key: 'test-cdek:44', level: 'settlement' } },
			defaultLocality: { policy: 'fixed', record: DEFAULT_MSK },
		} );

		expect( popular().map( ( e ) => e.label ).sort() ).toEqual( [ 'Внуково', 'Москва' ] );
	} );

	it( 'still shows everything when nothing at all is standing at the level — the empty state #530 exists for', () => {
		const popular = popularCallbackAfterBoot( {} );

		expect( popular() ).toHaveLength( 4 );
	} );

	it( 'shows everything when the record standing there publishes NO ancestors — an absent answer must never hide entries', () => {
		const popular = popularCallbackAfterBoot( {
			implicit: true,
			current: { key: 'test-cdek:44', level: 'settlement' },
			chain: { settlement: { key: 'test-cdek:44', level: 'settlement' } },
			defaultLocality: {
				policy: 'fixed',
				record: { ...DEFAULT_MSK, ancestors: [] },
			},
		} );

		expect( popular() ).toHaveLength( 4 );
	} );

	it( 'a GEOIP default never narrows either, because it never fills the field in the first place (#536)', () => {
		const popular = popularCallbackAfterBoot( {
			implicit: true,
			current: { key: 'test-cdek:44', level: 'settlement' },
			chain: { settlement: { key: 'test-cdek:44', level: 'settlement' } },
			defaultLocality: { policy: 'geoip', record: DEFAULT_MSK },
		} );

		expect( popular() ).toHaveLength( 4 );
	} );
} );

// -------------------------------------------------------------------
// Issue #541 — the busy state belongs to the customer's ACTION, not to the request.
//
// `/select` is single-flight per section. The marker used to be raised inside sendNextSelect(),
// i.e. when the request left — the same instant for an idle queue, and arbitrarily late behind a
// busy one. Measured on the rig with a `fixed` default (whose own boot-time /select runs ~14 s
// against the real CDEK test API), picking a region 3.7 s after load:
//
//     >>> click on the region     +0 ms
//     /select left               +11 045 ms
//     SPINNER on shipping_state  +11 048 ms
//
// The spinner was 3 ms behind its request and ELEVEN SECONDS behind the human.
// -------------------------------------------------------------------

describe( 'the /select busy state is raised on ENQUEUE, not on send — issue #541', () => {
	const REGION = {
		key: 'dadata:reg1', label: 'Московская область', level: 'region',
		record: { key: 'dadata:reg1', provider_id: 'dadata', level: 'region', country: 'RU', label: 'Московская область' },
	};
	const CITY = {
		key: 'dadata:city1', label: 'г Москва', level: 'settlement',
		record: { key: 'dadata:city1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'г Москва' },
	};

	const spinner = () => document.querySelector( '.woodev-location-select-spinner' );
	const selectCount = () => fetchCalls.filter( ( c ) => c.url === SELECT_URL ).length;

	it( 'shows the spinner for a pick still WAITING in the queue, before its request has left', () => {
		boot( { region: true, settlement: true } );

		// First pick occupies the single-flight slot and is left UNANSWERED.
		selectViaFake( callFor( 'billing_state' ), REGION );

		expect( selectCount() ).toBe( 1 );

		// Second pick can only queue — nothing new goes to the server...
		selectViaFake( callFor( 'billing_city' ), CITY );

		expect( selectCount() ).toBe( 1 );

		// ...and yet the customer must see their own field working. Before #541 the spinner sat
		// on the REGION field until the first request settled.
		const city = document.getElementById( 'billing_city' );

		expect( spinner() ).not.toBeNull();
		expect( city.parentNode.contains( spinner() ) ).toBe( true );
		expect( city.getAttribute( 'aria-busy' ) ).toBe( 'true' );
	} );

	it( 'a merely QUEUED pick does not re-lock the address it just unlocked — the s90 rule survives the change', () => {
		boot( { region: true, settlement: true, address: true } );

		selectViaFake( callFor( 'billing_state' ), REGION );
		selectViaFake( callFor( 'billing_city' ), CITY );

		// A settlement pick unlocks the address on the spot. The queued marker shows a spinner but
		// asserts nothing about confirmation, so hasUnconfirmedParent() must ignore it — marking
		// unconditionally here re-locked the address and broke s90's own regression test.
		expect( document.getElementById( 'billing_address_1' ).disabled ).toBe( false );
	} );
} );
