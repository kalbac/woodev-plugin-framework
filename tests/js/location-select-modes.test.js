/**
 * Tests for location-select-modes.js — Task 13 of the 2026-08-12 location-provider plan
 * (spec D7: `related-list` region/settlement renderers, `ajax-select2`).
 *
 * Covers, per renderer: the decline contract (wrong element shape falls back to the baseline
 * typeahead, per `location-cascade.js`'s own `attachOne()`), the DOM-replacement + restore
 * lifecycle for the two select2-backed renderers (select2 can only enhance a real `<select>`),
 * population from the SAME `/location/list`/`options.fetch` primitives the cascade hands over,
 * the SHARED `onSelect` persist path (never a duplicated one), and the dual native+jQuery
 * `change` event-world binding (gotcha `jquery-trigger-change-fires-no-native-event`) —
 * including that a SINGLE real native event delivered to BOTH bound worlds calls `onSelect`
 * only once (double delivery harmless by construction).
 *
 * Real jQuery is loaded throughout (a devDependency). `select2` itself is NOT a dependency of
 * this repo (it ships with WordPress/WooCommerce as the `selectWoo` script handle) — every test
 * either stubs `jQuery.fn.select2` to capture the config passed to it (proving the WIRING is
 * correct without the real plugin) or leaves it absent entirely (proving the graceful
 * degradation the file docblock describes).
 *
 * @see woodev/shipping-method/assets/js/frontend/location-select-modes.js
 * @see docs-internal/plans/2026-08-12-location-provider-plan.md — Task 13
 * @see docs-internal/specs/2026-08-12-location-provider-design.md — D7
 */

'use strict';

const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';

let fetchJsonCalls;

/**
 * Mirrors location-cascade.js's own `buildUrl()` exactly — omits empty/absent params.
 *
 * @param {string} base
 * @param {Object} params
 * @returns {string}
 */
function buildUrl( base, params ) {
	const parts = [];

	Object.keys( params || {} ).forEach( ( key ) => {
		const value = params[ key ];

		if ( undefined !== value && null !== value && '' !== value ) {
			parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
		}
	} );

	return parts.length ? base + '?' + parts.join( '&' ) : base;
}

/**
 * A controllable `options.fetchJson` double — mirrors `location-cascade.test.js`'s own
 * `mockFetch()` convention: each captured call exposes `resolve()`/`reject()`.
 *
 * @returns {Function}
 */
function fakeFetchJson() {
	fetchJsonCalls = [];

	return jest.fn( ( url, init ) => {
		const entry = { url, init };

		entry.promise = new Promise( ( resolve, reject ) => {
			entry.resolve = ( body ) => resolve( body );
			entry.reject = reject;
		} );

		fetchJsonCalls.push( entry );

		return entry.promise;
	} );
}

/**
 * Builds a fresh `options` object matching the exact shape `location-cascade.js`'s
 * `attachOne()` hands to every renderer.
 *
 * @param {Object} overrides
 * @returns {Object}
 */
function buildOptions( overrides ) {
	return Object.assign(
		{
			fetch: jest.fn( () => Promise.resolve( [] ) ),
			onSelect: jest.fn(),
			emptyText: '',
			node: { level: 'settlement', fieldId: 'billing_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list' },
			country: jest.fn( () => 'RU' ),
			parentKey: jest.fn( () => null ),
			buildUrl,
			fetchJson: fakeFetchJson(),
			nonceHeader: jest.fn( () => ( { 'X-WP-Nonce': 'N' } ) ),
		},
		overrides
	);
}

beforeEach( () => {
	jest.resetModules();
	// Fresh <body> per test — jest.resetModules() gives a fresh MODULE, not a fresh DOM; a
	// module that bound a delegated/element-level listener on a surviving node keeps answering
	// with stale closure state (gotcha `jest-resetmodules-leaves-listeners-on-the-surviving-body`).
	document.body.replaceWith( document.createElement( 'body' ) );
	document.body.innerHTML = '';
	delete window.WoodevLocationRenderers;
	delete window.jQuery;
	delete global.jQuery;
	delete global.$;

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;
} );

// -----------------------------------------------------------------------
// Registration — the seam location-cascade.js reads from
// -----------------------------------------------------------------------

describe( 'registers onto window.WoodevLocationRenderers on load', () => {
	it( 'registers related-list:region, related-list:settlement, and the bare ajax-select2 key', () => {
		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		expect( typeof window.WoodevLocationRenderers[ 'related-list:region' ] ).toBe( 'function' );
		expect( typeof window.WoodevLocationRenderers[ 'related-list:settlement' ] ).toBe( 'function' );
		expect( typeof window.WoodevLocationRenderers[ 'ajax-select2' ] ).toBe( 'function' );
	} );
} );

// -----------------------------------------------------------------------
// related-list: region — native <select> watcher
// -----------------------------------------------------------------------

describe( 'related-list region renderer', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	function installSelect() {
		document.body.innerHTML = `
			<select id="billing_state" name="billing_state">
				<option value="">-- выберите --</option>
				<option value="МОСКВА">Москва</option>
				<option value="КАЗАНЬ">Казань</option>
			</select>
		`;

		return document.getElementById( 'billing_state' );
	}

	it( 'declines (returns null) when the field is a plain <input>, not a <select>', () => {
		document.body.innerHTML = '<input id="billing_state" name="billing_state" value="" />';
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		const api = mod.attachRelatedListRegion( document.getElementById( 'billing_state' ), options );

		expect( api ).toBeNull();
	} );

	it( 'matches the selected OPTION TEXT against record.label from /location/list and calls the shared onSelect', async () => {
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		mod.attachRelatedListRegion( el, options );

		el.value = 'МОСКВА';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( fetchJsonCalls ).toHaveLength( 1 );
		expect( fetchJsonCalls[ 0 ].url ).toBe( LIST_URL + '?level=region&country=RU' );

		fetchJsonCalls[ 0 ].resolve( {
			localities: [
				{ key: 'dadata:kazan', label: 'Казань', level: 'region', record: { key: 'dadata:kazan', label: 'Казань' } },
				{ key: 'dadata:msk', label: 'Москва', level: 'region', record: { key: 'dadata:msk', label: 'Москва' } },
			],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:msk', label: 'Москва' } } );
	} );

	it( 'never calls onSelect when nothing in the list matches the selected text', async () => {
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		mod.attachRelatedListRegion( el, options );

		el.value = 'КАЗАНЬ';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		fetchJsonCalls[ 0 ].resolve( { localities: [] } );
		await Promise.resolve().then( () => Promise.resolve() );

		// toHaveLength(0), never toEqual([]) — a mock's own .mock.calls can hold an
		// [undefined] entry that toEqual([]) would let through unnoticed (gotcha
		// `jest-toequal-empty-array-ignores-undefined`).
		expect( options.onSelect.mock.calls ).toHaveLength( 0 );
	} );

	it( 'a REAL jQuery .trigger("change") alone (no native event at all) still reaches onSelect', async () => {
		// Pins gotcha `jquery-trigger-change-fires-no-native-event`: WooCommerce may enhance
		// this very <select> with selectWoo independently of this layer, and a pick through
		// THAT reports via exactly this call — no native DOM event at all.
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		mod.attachRelatedListRegion( el, options );

		window.jQuery( el ).val( 'МОСКВА' ).trigger( 'change' );

		fetchJsonCalls[ 0 ].resolve( {
			localities: [ { key: 'dadata:msk', label: 'Москва', level: 'region', record: { key: 'dadata:msk', label: 'Москва' } } ],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a single native event delivered to BOTH bound worlds calls onSelect exactly once — double delivery is harmless', async () => {
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		mod.attachRelatedListRegion( el, options );

		// jQuery's own `.on()` binds a REAL native listener under the hood — a single genuine
		// native dispatch is therefore seen by BOTH our own addEventListener AND jQuery's.
		el.value = 'МОСКВА';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		fetchJsonCalls[ 0 ].resolve( {
			localities: [ { key: 'dadata:msk', label: 'Москва', level: 'region', record: { key: 'dadata:msk', label: 'Москва' } } ],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		// Pinned against the earlier "one dispatch, one match" test: still exactly 1, not 2 —
		// distinguishes "de-duplicated" from "coincidentally called once".
		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( fetchJsonCalls ).toHaveLength( 1 ); // the list itself is also fetched only once.
	} );

	it( 'caches the region list per country — a second selection in the SAME country issues no second fetch', async () => {
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		mod.attachRelatedListRegion( el, options );

		el.value = 'МОСКВА';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		fetchJsonCalls[ 0 ].resolve( {
			localities: [
				{ key: 'dadata:msk', label: 'Москва', level: 'region', record: { key: 'dadata:msk', label: 'Москва' } },
				{ key: 'dadata:kzn', label: 'Казань', level: 'region', record: { key: 'dadata:kzn', label: 'Казань' } },
			],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		el.value = 'КАЗАНЬ';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( fetchJsonCalls ).toHaveLength( 1 ); // cached — pinned against the 2-country case below.
		expect( options.onSelect ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'a country change re-fetches the list', async () => {
		const el = installSelect();
		let country = 'RU';
		const options = buildOptions( {
			node: { level: 'region', fieldId: 'billing_state' },
			country: jest.fn( () => country ),
		} );

		mod.attachRelatedListRegion( el, options );

		el.value = 'МОСКВА';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		fetchJsonCalls[ 0 ].resolve( { localities: [] } );
		await Promise.resolve().then( () => Promise.resolve() );

		country = 'BY';
		el.value = 'КАЗАНЬ';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		fetchJsonCalls[ 1 ].resolve( { localities: [] } );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( fetchJsonCalls ).toHaveLength( 2 ); // pinned against the same-country 1-call case above.
	} );

	it( 'detach() unbinds both worlds — a change afterwards never reaches onSelect', () => {
		const el = installSelect();
		const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

		const api = mod.attachRelatedListRegion( el, options );

		api.detach();

		el.value = 'МОСКВА';
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( el ).trigger( 'change' );

		expect( fetchJsonCalls ).toHaveLength( 0 );
	} );
} );

// -----------------------------------------------------------------------
// related-list: settlement — select2 fed by the full per-region /location/list
// -----------------------------------------------------------------------

describe( 'related-list settlement renderer', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<form><input type="text" id="billing_city" name="billing_city" value="" /></form>';
	} );

	it( 'declines when the field is not a plain <input>', () => {
		document.body.innerHTML = '<select id="billing_city" name="billing_city"></select>';
		const options = buildOptions();

		const api = mod.attachRelatedListSettlement( document.getElementById( 'billing_city' ), options );

		expect( api ).toBeNull();
	} );

	it( 'replaces the <input> with a <select> carrying the SAME id, fetches the full region-scoped list, and populates <option>s', async () => {
		const input = document.getElementById( 'billing_city' );
		const options = buildOptions( {
			node: { level: 'settlement', fieldId: 'billing_city' },
			country: jest.fn( () => 'RU' ),
			parentKey: jest.fn( () => 'dadata:region1' ),
		} );

		mod.attachRelatedListSettlement( input, options );

		expect( document.getElementById( 'billing_city' ).tagName ).toBe( 'SELECT' );
		expect( fetchJsonCalls[ 0 ].url ).toBe( LIST_URL + '?level=settlement&country=RU&within=' + encodeURIComponent( 'dadata:region1' ) );

		fetchJsonCalls[ 0 ].resolve( {
			localities: [ { key: 'dadata:zh', label: 'Жуковский', level: 'settlement', record: { key: 'dadata:zh', label: 'Жуковский' } } ],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		const select = document.getElementById( 'billing_city' );
		expect( select.options.length ).toBe( 1 ); // pinned: exactly the one entry the fake response carried.
		expect( select.options[ 0 ].value ).toBe( 'dadata:zh' );
		expect( select.options[ 0 ].textContent ).toBe( 'Жуковский' );
	} );

	it( 'omits `within` when parentKey() is empty (no region selected yet — country-wide list)', () => {
		const options = buildOptions( { parentKey: jest.fn( () => null ) } );

		mod.attachRelatedListSettlement( document.getElementById( 'billing_city' ), options );

		expect( fetchJsonCalls[ 0 ].url ).not.toContain( 'within=' );
	} );

	it( 'picking an option calls the shared onSelect with the matching record — the SAME persist path as every other level', async () => {
		const options = buildOptions( { node: { level: 'settlement', fieldId: 'billing_city' } } );

		mod.attachRelatedListSettlement( document.getElementById( 'billing_city' ), options );

		fetchJsonCalls[ 0 ].resolve( {
			localities: [ { key: 'dadata:zh', label: 'Жуковский', level: 'settlement', record: { key: 'dadata:zh', label: 'Жуковский' } } ],
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		const select = document.getElementById( 'billing_city' );
		select.value = 'dadata:zh';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:zh', label: 'Жуковский' } } );
	} );

	it( 'initializes select2 in LOCAL mode (no `ajax` config) when the plugin is present', async () => {
		const select2Calls = [];
		window.jQuery.fn.select2 = jest.fn( function( config ) {
			select2Calls.push( config );
			return this;
		} );

		mod.attachRelatedListSettlement( document.getElementById( 'billing_city' ), buildOptions() );

		fetchJsonCalls[ 0 ].resolve( { localities: [] } );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( select2Calls ).toHaveLength( 1 );
		expect( select2Calls[ 0 ].ajax ).toBeUndefined();

		delete window.jQuery.fn.select2;
	} );

	it( 'detach() restores the ORIGINAL <input> node in place, removing the <select>', async () => {
		const input = document.getElementById( 'billing_city' );
		const options = buildOptions();

		const api = mod.attachRelatedListSettlement( input, options );

		fetchJsonCalls[ 0 ].resolve( { localities: [] } );
		await Promise.resolve().then( () => Promise.resolve() );

		api.detach();

		const restored = document.getElementById( 'billing_city' );
		expect( restored ).toBe( input );
		expect( restored.tagName ).toBe( 'INPUT' );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — select2 remote data through the SAME options.fetch the typeahead uses
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'declines when the field is not a plain <input>', () => {
		document.body.innerHTML = '<select id="billing_address_1"></select>';

		const api = mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), buildOptions() );

		expect( api ).toBeNull();
	} );

	it( 'does NOT call options.fetch upfront — only select2 ajax.transport drives population', () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		expect( fetchSpy.mock.calls ).toHaveLength( 0 );
	} );

	it( 'wires the SAME options.fetch as select2\'s own ajax.transport — shared code path, not a copy', async () => {
		const fetchSpy = jest.fn( ( term ) => Promise.resolve( [
			{ key: 'dadata:tv', label: 'ул Тверская', level: 'address', value: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } },
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		const select2Calls = [];
		window.jQuery.fn.select2 = jest.fn( function( config ) {
			select2Calls.push( config );
			return this;
		} );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		expect( select2Calls ).toHaveLength( 1 );
		expect( typeof select2Calls[ 0 ].ajax.transport ).toBe( 'function' );

		const success = jest.fn();
		const failure = jest.fn();

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, success, failure );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( fetchSpy ).toHaveBeenCalledWith( 'Твер' );
		expect( success ).toHaveBeenCalledTimes( 1 );
		expect( success.mock.calls[ 0 ][ 0 ].results ).toEqual( [ { id: 'dadata:tv', text: 'ул Тверская' } ] );
	} );

	it( 'a selection AFTER an ajax.transport response calls the shared onSelect with the fetched record', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tv', label: 'ул Тверская', level: 'address', value: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } },
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		const select2Calls = [];
		window.jQuery.fn.select2 = jest.fn( function( config ) {
			select2Calls.push( config );
			return this;
		} );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		// select2 itself would insert the picked <option> into the underlying <select> before
		// marking it selected (a bare `.value =` assignment is a no-op without a matching
		// <option> present — both jQuery's and the native `<select>` value setter search the
		// existing option list) — reproduced explicitly here, same as the next test.
		const select = document.getElementById( 'billing_address_1' );
		const option = document.createElement( 'option' );
		option.value = 'dadata:tv';
		select.appendChild( option );
		select.value = 'dadata:tv';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:tv', label: 'ул Тверская' } } );
	} );

	it( 'a jQuery-only .trigger("change") (select2\'s own reporting mechanism, no native event) still reaches onSelect', async () => {
		// The core event-world pin: select2 reports a pick via EXACTLY this call — no native
		// DOM event is ever dispatched (gotcha `jquery-trigger-change-fires-no-native-event`).
		// A NATIVE-only `addEventListener('change')` would never see this at all.
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tv', label: 'ул Тверская', level: 'address', value: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } },
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		const select2Calls = [];
		window.jQuery.fn.select2 = jest.fn( function( config ) {
			select2Calls.push( config );
			return this;
		} );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		// Prime the lookup map exactly the way a real ajax.transport response would.
		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		const select = document.getElementById( 'billing_address_1' );

		// A real select2 pick inserts the chosen result's own <option> into the underlying
		// <select> before marking it selected — reproduced explicitly here since no real
		// select2 runs under jest (see the file docblock's SELECT2 IS OPTIONAL section).
		const option = document.createElement( 'option' );
		option.value = 'dadata:tv';
		select.appendChild( option );

		window.jQuery( select ).val( 'dadata:tv' ).trigger( 'change' );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:tv', label: 'ул Тверская' } } );
	} );
} );
