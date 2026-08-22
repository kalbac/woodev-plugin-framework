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

const { installFakeSelect2 } = require( './support/fake-select2.js' );

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

// -----------------------------------------------------------------------
// selectConfigFor() — the PURE half of the #450 harness: no DOM, no jQuery, no select2 call.
// Testable in an environment with no select2 present at all (issue #450, harness option 2).
// -----------------------------------------------------------------------

describe( 'selectConfigFor() — pure config builder, no select2 required', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	it( 'a non-ajax strategy gets width only — no ajax block, no placeholder', () => {
		const config = mod.selectConfigFor(
			{ ajax: false },
			{ initialValue: '', placeholder: 'Регион', applyEntries: jest.fn() }
		);

		expect( config ).toEqual( { width: '100%' } );
	} );

	it( 'an ajax strategy with an initial value gets no placeholder — the seeded <option> already carries it', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: 'Татарстан', placeholder: 'Регион', applyEntries: jest.fn() }
		);

		expect( config.placeholder ).toBeUndefined();
		expect( typeof config.ajax.transport ).toBe( 'function' );
	} );

	it( 'an ajax strategy with NO initial value and a placeholder attribute gets config.placeholder', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: 'Улица, дом', applyEntries: jest.fn() }
		);

		expect( config.placeholder ).toBe( 'Улица, дом' );
	} );

	it( 'the transport calls strategy.fetchEntries(term), merges results via seed.applyEntries(entries, false), and reports the ACCEPTED entries as {id, text, key} shaped results', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tv', label: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } },
		] ) );
		// A pass-through stand-in for the REAL applyEntries() (buildSelectField.test coverage below
		// exercises the real one) — issue #461 BLOCKING 1/2: the transport must build `results` from
		// applyEntries()'s OWN return value, never re-derive it from the raw `entries` array, so the
		// two can never disagree.
		const applyEntries = jest.fn( ( entries ) => entries );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries }
		);

		const success = jest.fn();
		const failure = jest.fn();

		config.ajax.transport( { data: { term: 'Твер' } }, success, failure );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( fetchEntries ).toHaveBeenCalledWith( 'Твер' );
		expect( applyEntries ).toHaveBeenCalledWith(
			[ { key: 'dadata:tv', label: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } } ],
			false
		);
		expect( success ).toHaveBeenCalledWith( { results: [ { id: 'dadata:tv', text: 'ул Тверская', key: 'dadata:tv' } ] } );
		expect( failure ).not.toHaveBeenCalled();
	} );
} );

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
		// issue #455: the reported id is entry.value (the location VALUE space every other
		// renderer in this layer submits), never entry.key (the raw provider key). issue #461
		// BLOCKING 2: `key` rides along too — the STABLE identity a real select2 pick hands back
		// via `select2:select`'s `e.params.data.key`, never used as the submitted `id`/value.
		expect( success.mock.calls[ 0 ][ 0 ].results ).toEqual( [ { id: 'ул Тверская', text: 'ул Тверская', key: 'dadata:tv' } ] );
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

		const success = jest.fn();

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		const pickedData = success.mock.calls[ 0 ][ 0 ].results[ 0 ];

		// select2 itself would insert the picked <option> into the underlying <select>, set its
		// value, and fire native 'change' (SelectAdapter.prototype.select,
		// selectWoo.full.js:3182-3220) — reproduced explicitly here, same as the next test.
		const select = document.getElementById( 'billing_address_1' );
		const option = document.createElement( 'option' );
		option.value = pickedData.id;
		select.appendChild( option );
		select.value = pickedData.id;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// issue #461 BLOCKING 2: identity resolution for a REAL select2 pick goes through
		// `select2:select`'s `e.params.data` — the exact object select2/selectWoo hands back
		// verbatim (EventRelay, selectWoo.full.js:2174-2218) — never the <option>'s own DOM
		// value/dataset, which select2's own `SelectAdapter.prototype.option()` never carries a
		// custom `key` field onto (selectWoo.full.js:3309-3327).
		window.jQuery( select ).trigger( window.jQuery.Event( 'select2:select', { params: { data: pickedData } } ) );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:tv', label: 'ул Тверская' } } );
	} );

	it( 'a jQuery-only select2:select trigger (select2\'s own reporting mechanism, no native event) still reaches onSelect', async () => {
		// The core event-world pin: select2 reports a pick via EXACTLY this jQuery custom event —
		// no native DOM event carries the picked record's identity at all (gotcha
		// `jquery-trigger-change-fires-no-native-event`, extended by issue #461 BLOCKING 2 to
		// select2:select specifically). A NATIVE-only `addEventListener` would never see this.
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
		const success = jest.fn();

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		const pickedData = success.mock.calls[ 0 ][ 0 ].results[ 0 ];
		const select = document.getElementById( 'billing_address_1' );

		// A real select2 pick inserts the chosen result's own <option> into the underlying
		// <select> before marking it selected — reproduced explicitly here since no real
		// select2 runs under jest (see the file docblock's SELECT2 IS OPTIONAL section).
		const option = document.createElement( 'option' );
		option.value = pickedData.id;
		select.appendChild( option );

		window.jQuery( select ).val( pickedData.id ).trigger( 'change' );
		window.jQuery( select ).trigger( window.jQuery.Event( 'select2:select', { params: { data: pickedData } } ) );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:tv', label: 'ул Тверская' } } );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — seeding the field's OWN current value (issue #447), proven through the
// #450 harness (a fake jQuery.fn.select2 that records config and can drive ajax.transport,
// PLUS the plain-DOM assertions the file docblock already promised were possible without it).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — current value is seeded before select2 init (issue #447)', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'a field with a non-empty value produces a select containing that value, selected, with NO select2 present at all', () => {
		// No jQuery.fn.select2 installed — ensureSelect2() no-ops (see the file docblock's
		// "SELECT2 IS OPTIONAL AT RUNTIME" section), so whatever the DOM looks like here is
		// exactly what buildSelectField() itself produced, unmodified by any plugin. FAILS on
		// main: buildSelectField() never reads input.value at all today.
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="Татарстан" />';
		const input = document.getElementById( 'shipping_state' );

		mod.attachAjaxSelect2( input, buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		const select = document.getElementById( 'shipping_state' );

		expect( select.tagName ).toBe( 'SELECT' );
		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( 'Татарстан' );
		expect( select.options[ 0 ].selected ).toBe( true );
		expect( select.value ).toBe( 'Татарстан' );
	} );

	it( 'a field with an empty value produces a select with NO options (unchanged behaviour, no placeholder attribute present)', () => {
		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), buildOptions() );

		const select = document.getElementById( 'billing_address_1' );

		expect( select.options.length ).toBe( 0 );
	} );

	it( 'DATA LOSS CLAIM, ajax order-review path: jQuery(\'#shipping_state\').val() returns the seeded value — the exact call checkout.js:532 makes before writing s_state', () => {
		// Mirrors checkout.js's OWN mechanism for the shipping_state field exactly:
		// `s_state = $( '#shipping_state' ).val();` (assets/js/frontend/checkout.js:532) — this
		// is jQuery's `.val()`, not the native `.value` getter; jQuery's own valHook for a
		// select-one with selectedIndex === -1 returns `null` (main's select has zero options,
		// so `.val()` is `null` there too). This test pins the FIXED behaviour: a real value now
		// comes back from `.val()` as itself, so the ajax order-review update never wipes it.
		document.body.innerHTML = '<form id="checkout"><input type="text" id="shipping_state" name="shipping_state" value="Татарстан" /></form>';
		const input = document.getElementById( 'shipping_state' );

		mod.attachAjaxSelect2( input, buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		expect( window.jQuery( '#shipping_state' ).val() ).toBe( 'Татарстан' );
	} );

	it( 'DATA LOSS CLAIM, form-submit path: $(\'#checkout\').serialize() carries the seeded value, never an absent/empty shipping_state key', () => {
		// jQuery.param() serializes a `null` .val() as an EMPTY STRING in the actual POST body
		// (verified empirically against this repo's own jQuery devDependency) — an empty select
		// never simply drops the field from the wire, it arrives as `shipping_state=`, and
		// WooCommerce's own get_posted_data() treats an ABSENT posted key exactly like an empty
		// one, writing '' over the stored address (includes/class-wc-checkout.php:784-789). This
		// test pins the FIXED behaviour: the seeded value now survives the whole serialize() call.
		document.body.innerHTML = '<form id="checkout"><input type="text" id="shipping_state" name="shipping_state" value="Татарстан" /></form>';
		const input = document.getElementById( 'shipping_state' );

		mod.attachAjaxSelect2( input, buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		expect( window.jQuery( '#checkout' ).serialize() ).toBe( 'shipping_state=' + encodeURIComponent( 'Татарстан' ) );
	} );

	it( 'placeholder: an empty field WITH a placeholder attribute gets an empty leading <option> and select2 receives config.placeholder (select2 docs: required even for AJAX single selects)', () => {
		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" placeholder="Улица, дом" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), buildOptions() );

		expect( instances ).toHaveLength( 1 );
		expect( instances[ 0 ].config.placeholder ).toBe( 'Улица, дом' );

		const select = document.getElementById( 'billing_address_1' );

		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( '' );
	} );

	it( 'a non-empty value takes priority over a placeholder attribute — the select carries the VALUE, not a blank leading option, and select2 gets no config.placeholder', () => {
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="Казань" placeholder="Населённый пункт" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), buildOptions( { node: { level: 'settlement', fieldId: 'shipping_city' } } ) );

		expect( instances[ 0 ].config.placeholder ).toBeUndefined();

		const select = document.getElementById( 'shipping_city' );

		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( 'Казань' );
	} );

	/**
	 * Issue #460: a WooCommerce-rebuilt state field (`country-select.js`'s own
	 * `country_to_state_changed` handler) carries neither a `value` nor a `placeholder`/
	 * `data-placeholder` attribute — the "thin strip" report (zero content height until the
	 * customer picks something). `entry.location.i18n.placeholder` — the SAME server-supplied,
	 * translatable string `Checkout_Config::build_location_block()` already emits for the main
	 * config — is the fallback, never a hardcoded JS literal.
	 */
	it( 'an empty field with NO placeholder attribute falls back to location.i18n.placeholder', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2(
			document.getElementById( 'shipping_state' ),
			buildOptions( {
				node: { level: 'region', fieldId: 'shipping_state' },
				location: { endpoints: { list: LIST_URL }, mode: 'related-list', i18n: { placeholder: 'Выберите регион' } },
			} )
		);

		expect( instances[ 0 ].config.placeholder ).toBe( 'Выберите регион' );

		const select = document.getElementById( 'shipping_state' );

		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( '' );
	} );

	it( 'a placeholder/data-placeholder attribute still wins over location.i18n.placeholder', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" placeholder="Своя подсказка" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2(
			document.getElementById( 'shipping_state' ),
			buildOptions( {
				node: { level: 'region', fieldId: 'shipping_state' },
				location: { endpoints: { list: LIST_URL }, mode: 'related-list', i18n: { placeholder: 'Выберите регион' } },
			} )
		);

		expect( instances[ 0 ].config.placeholder ).toBe( 'Своя подсказка' );
	} );

	it( 'the seeded option is already IN THE DOM at the moment select2() is called — proven via the #450 fake, not inferred', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="Татарстан" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_state' ), buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		expect( instances ).toHaveLength( 1 );

		const liveSelect = instances[ 0 ].el[ 0 ];

		expect( liveSelect.options.length ).toBe( 1 );
		expect( liveSelect.value ).toBe( 'Татарстан' );
		expect( typeof instances[ 0 ].config.ajax.transport ).toBe( 'function' );
	} );

	it( 'the #450 fake reproduces the minimumInputLength gate — FIXED (issue #449): our own config now sets a real floor', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		// issue #449: a search on a genuinely empty term (select2 queries on focus with
		// `term: ''`) must never reach the transport — the whole point of the floor.
		expect( instances[ 0 ].config.minimumInputLength ).toBe( 2 );
		expect( instances[ 0 ].config.ajax.delay ).toBe( 250 );

		const belowFloor = instances[ 0 ].query( '' );

		expect( belowFloor ).toBeNull();
		expect( fetchSpy ).not.toHaveBeenCalled();

		const atFloor = instances[ 0 ].query( 'Тв' );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( atFloor ).not.toBeNull();
		expect( fetchSpy ).toHaveBeenCalledWith( 'Тв' );
	} );

	it( 'FIXED (issue #449): ajax.transport returns an abortable handle, and abort() suppresses that call\'s own eventual success() — no more last-arrived-wins flicker', async () => {
		// Two overlapping queries, slower-first: "Мо" resolves AFTER "Моск" despite being
		// issued first — exactly the flicker the operator observed (issue #449's "results
		// blink" symptom, a slower earlier response landing after a faster later one).
		let resolveFirst;
		let resolveSecond;
		const fetchSpy = jest.fn()
			.mockImplementationOnce( () => new Promise( ( resolve ) => {
				resolveFirst = resolve;
			} ) )
			.mockImplementationOnce( () => new Promise( ( resolve ) => {
				resolveSecond = resolve;
			} ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		const first = instances[ 0 ].query( 'Мо' );

		// The fake's own store-then-abort sequence (proven directly below) calls `.abort()`
		// on the FIRST call's returned handle before issuing the second.
		const second = instances[ 0 ].query( 'Моск' );

		resolveSecond( [ { key: 'dadata:msk', value: 'Москва', level: 'settlement', record: { key: 'dadata:msk', label: 'Москва' } } ] );
		await Promise.resolve().then( () => Promise.resolve() );

		resolveFirst( [ { key: 'dadata:mo', value: 'Мозырь', level: 'settlement', record: { key: 'dadata:mo', label: 'Мозырь' } } ] );
		await Promise.resolve().then( () => Promise.resolve() );

		// The stale first response never repaints the list — its success() never fires.
		expect( first.success ).not.toHaveBeenCalled();
		expect( second.success ).toHaveBeenCalledTimes( 1 );
		expect( second.success.mock.calls[ 0 ][ 0 ].results ).toEqual( [ { id: 'Москва', text: 'Москва', key: 'dadata:msk' } ] );
	} );

	it( 'ajax.transport returns an abortable handle so selectWoo can cancel an in-flight request on the next keystroke (issue #449)', () => {
		// selectConfigFor()'s own `config.ajax.transport` now `return`s an object with a real
		// `abort()` — the real selectWoo AjaxAdapter stores it as `this._request` and calls
		// `.abort()` on the NEXT query (selectWoo.full.js:3564-3571, mirrored by the #450 fake).
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		const request = instances[ 0 ].config.ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );

		expect( Boolean( request && 'function' === typeof request.abort ) ).toBe( true );
	} );
} );

// -----------------------------------------------------------------------
// The #450 fake's OWN contract — driven directly, independently of any renderer, to prove the
// fake itself gates and aborts exactly the way selectWoo does (issue #456 follow-up: the
// original #450 harness modeled these two points but no test ever exercised either — see the
// fake's own docblock, `tests/js/support/fake-select2.js`).
// -----------------------------------------------------------------------

describe( 'the #450 fake — minimumInputLength gate and store-then-abort, driven directly', () => {
	let instances;
	let el;

	beforeEach( () => {
		document.body.innerHTML = '<input type="text" id="fake-select2-driver" name="fake-select2-driver" value="" />';
		el = document.getElementById( 'fake-select2-driver' );
		instances = installFakeSelect2( window.jQuery );
	} );

	it( 'minimumInputLength gate: a query SHORTER than the minimum never reaches ajax.transport', () => {
		const transport = jest.fn();

		window.jQuery( el ).select2( { minimumInputLength: 3, ajax: { transport: transport } } );

		const result = instances[ 0 ].query( 'ab' );

		expect( result ).toBeNull();
		expect( transport ).not.toHaveBeenCalled();
	} );

	it( 'minimumInputLength gate: a query AT OR ABOVE the minimum reaches ajax.transport', () => {
		const transport = jest.fn();

		window.jQuery( el ).select2( { minimumInputLength: 3, ajax: { transport: transport } } );

		const result = instances[ 0 ].query( 'abc' );

		expect( result ).not.toBeNull();
		expect( transport ).toHaveBeenCalledTimes( 1 );
		expect( transport ).toHaveBeenCalledWith( { data: { term: 'abc' } }, result.success, result.failure );
	} );

	it( 'store-then-abort: a second query aborts the first one\'s returned request when the transport returns something abortable', () => {
		const firstAbort = jest.fn();
		const secondAbort = jest.fn();
		const transport = jest.fn()
			.mockReturnValueOnce( { abort: firstAbort } )
			.mockReturnValueOnce( { abort: secondAbort } );

		window.jQuery( el ).select2( { ajax: { transport: transport } } );

		instances[ 0 ].query( 'a' );
		instances[ 0 ].query( 'b' );

		expect( firstAbort ).toHaveBeenCalledTimes( 1 );
		expect( secondAbort ).not.toHaveBeenCalled();
		expect( transport ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'store-then-abort guard: a second query does not throw when the previous transport call returned nothing abortable', () => {
		const transport = jest.fn().mockReturnValue( undefined );

		window.jQuery( el ).select2( { ajax: { transport: transport } } );

		instances[ 0 ].query( 'a' );

		expect( () => instances[ 0 ].query( 'b' ) ).not.toThrow();
		expect( transport ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'store-then-abort guard: a second query does not throw when the previous transport call returned an object with no abort() method', () => {
		const transport = jest.fn().mockReturnValue( { notAbort: true } );

		window.jQuery( el ).select2( { ajax: { transport: transport } } );

		instances[ 0 ].query( 'a' );

		expect( () => instances[ 0 ].query( 'b' ) ).not.toThrow();
		expect( transport ).toHaveBeenCalledTimes( 2 );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — issue #455, the white-spot claim proven/disproven BY EXECUTION: a real pick
// through the wired ajax.transport must submit the SAME value space every other renderer in
// this layer uses (`location-cascade.js`'s own `fetchFor()` already stamps `entry.value` via
// `fieldValueFor()` before an entry ever reaches this file) — never the raw provider key.
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #455: the submitted value is the location VALUE, never the raw provider key', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'a real pick through ajax.transport ends with the <select> — and the serialized form — carrying entry.value, not entry.key', async () => {
		// Mirrors location-cascade.js's own fetchFor(): every entry reaching options.fetch
		// already carries `.value = fieldValueFor(entry.record, node.level)`, assigned BEFORE
		// this renderer ever sees it (location-cascade.js:915).
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{
				key: 'dadata:0c5b2444-city-zhukovsky',
				value: 'Жуковский',
				level: 'settlement',
				record: { key: 'dadata:0c5b2444-city-zhukovsky', label: 'Жуковский' },
			},
		] ) );
		const options = buildOptions( { fetch: fetchSpy, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		document.body.innerHTML = '<form id="checkout"><input type="text" id="shipping_city" name="shipping_city" value="" /></form>';
		const input = document.getElementById( 'shipping_city' );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( input, options );

		const result = instances[ 0 ].query( 'Жук' );
		await Promise.resolve().then( () => Promise.resolve() );

		const results = result.success.mock.calls[ 0 ][ 0 ].results;

		// The actual claim under test: what select2's own {id, text} result — and therefore the
		// <option> value it would insert on a pick — carries for this field. `key` (issue #461
		// BLOCKING 2) rides along as the resolution identity, never as the submitted id/value.
		expect( results ).toEqual( [ { id: 'Жуковский', text: 'Жуковский', key: 'dadata:0c5b2444-city-zhukovsky' } ] );

		// select2 itself inserts the picked result's own <option>, sets its value, and fires
		// native 'change' (same convention as the existing "a selection AFTER an ajax.transport
		// response" test) — and separately relays the pick as `select2:select` with the exact
		// result object, which is what identity resolution now goes through (BLOCKING 2).
		const select = document.getElementById( 'shipping_city' );
		const option = document.createElement( 'option' );

		option.value = results[ 0 ].id;
		select.appendChild( option );
		select.value = results[ 0 ].id;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( select ).trigger( window.jQuery.Event( 'select2:select', { params: { data: results[ 0 ] } } ) );

		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:0c5b2444-city-zhukovsky', label: 'Жуковский' } } );

		// The white-spot claim itself: the field's own submitted value, all the way through
		// jQuery's .val() and .serialize() — the exact calls checkout.js and jQuery.param() make.
		expect( window.jQuery( '#shipping_city' ).val() ).toBe( 'Жуковский' );
		expect( window.jQuery( '#checkout' ).serialize() ).toBe( 'shipping_city=' + encodeURIComponent( 'Жуковский' ) );
	} );

	it( 'falls back to entry.key when an entry carries no .value (defensive — related-list:settlement entries never carry one, and fixing that renderer is out of scope here)', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:no-value-entry', level: 'settlement', record: { key: 'dadata:no-value-entry', label: 'Витебск' } },
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_city' ), options );

		const result = instances[ 0 ].query( 'Вит' );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( result.success.mock.calls[ 0 ][ 0 ].results ).toEqual( [ { id: 'dadata:no-value-entry', text: 'Витебск', key: 'dadata:no-value-entry' } ] );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — round-2 critic REJECT, BLOCKING 1: `entry.value || entry.key` was a truthiness
// fallback, not a presence check — a record with no derivable component AND no usable label
// (`fieldValueFor()` legitimately returns '') silently became selectable under its own raw
// provider key, reintroducing #455 in the one case hardest to notice.
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #461 BLOCKING 1: an entry with an explicitly EMPTY derived value is never selectable', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'excludes the empty-value entry from select2 results entirely — FAILS on `entry.value || entry.key`, which would report it under its own raw provider key', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{
				key: 'dadata:has-value',
				value: 'Жуковский',
				level: 'settlement',
				record: { key: 'dadata:has-value', label: 'Жуковский' },
			},
			{
				// fieldValueFor() found no component AND no usable label at this level — a real,
				// legitimate '', never absent (see the next test for the absent case, which is
				// unaffected and must keep falling back to entry.key).
				key: 'dadata:no-derivable-value',
				value: '',
				level: 'settlement',
				record: { key: 'dadata:no-derivable-value', label: '' },
			},
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_city' ), options );

		const result = instances[ 0 ].query( 'Жук' );
		await Promise.resolve().then( () => Promise.resolve() );

		const results = result.success.mock.calls[ 0 ][ 0 ].results;

		expect( results ).toEqual( [ { id: 'Жуковский', text: 'Жуковский', key: 'dadata:has-value' } ] );
		expect( results.some( ( r ) => r.id === 'dadata:no-derivable-value' ) ).toBe( false );
	} );

	it( 'an entry with NO .value at all (undefined, not empty) is UNAFFECTED — still falls back to entry.key, out of scope here', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:no-value-entry', level: 'settlement', record: { key: 'dadata:no-value-entry', label: 'Витебск' } },
		] ) );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_city' ), options );

		const result = instances[ 0 ].query( 'Вит' );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( result.success.mock.calls[ 0 ][ 0 ].results ).toEqual( [ { id: 'dadata:no-value-entry', text: 'Витебск', key: 'dadata:no-value-entry' } ] );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — round-2 critic REJECT, BLOCKING 2: keying `dataByKey` by the SUBMITTED value
// (the original PR's own guidance, now WITHDRAWN) resolves the WRONG record when two entries
// legitimately share one submitted name — the merge in `applyEntries()` always leaves whichever
// entry was processed LAST in `dataByKey`, regardless of which one the customer actually picked.
// Fixed by keying `dataByKey` on `entry.key` (the provider's own stable identity) and resolving a
// real select2 pick through `select2:select`'s `e.params.data.key` — select2/selectWoo hands the
// full, un-stripped result object back on that event (verified against the real selectWoo source,
// selectWoo.full.js:2174-2218, 3305-3350, 3454-3466 — see the worker's report for the trace).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #461 BLOCKING 2: two same-named localities resolve to their OWN records', () => {
	let mod;

	function fetchTwoSameNamedEntries() {
		return jest.fn( () => Promise.resolve( [
			{
				key: 'dadata:first-alexandrovka',
				value: 'Александровка',
				level: 'settlement',
				record: { key: 'dadata:first-alexandrovka', label: 'Александровка (Воронежская обл.)' },
			},
			{
				key: 'dadata:second-alexandrovka',
				value: 'Александровка',
				level: 'settlement',
				record: { key: 'dadata:second-alexandrovka', label: 'Александровка (Оренбургская обл.)' },
			},
		] ) );
	}

	/**
	 * Simulates a REAL select2 pick of `resultIndex` from `results` — a real select2 inserts the
	 * picked <option>, sets the <select>'s value, and fires native 'change'
	 * (SelectAdapter.prototype.select, selectWoo.full.js:3182-3220) AND separately relays the
	 * SAME pick as `select2:select` carrying the exact result object as `e.params.data`
	 * (EventRelay, selectWoo.full.js:2174-2218) — both happen for one pick, from the same
	 * underlying `container.trigger('select', {data})` call.
	 *
	 * @param {Element} select
	 * @param {Array}   results
	 * @param {number}  resultIndex
	 * @returns {void}
	 */
	function simulateSelect2Pick( select, results, resultIndex ) {
		var picked = results[ resultIndex ];
		var option = document.createElement( 'option' );

		option.value = picked.id;
		select.appendChild( option );
		select.value = picked.id;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		window.jQuery( select ).trigger( window.jQuery.Event( 'select2:select', { params: { data: picked } } ) );
	}

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'select2 reports BOTH same-named results, each carrying its OWN key', async () => {
		const options = buildOptions( { fetch: fetchTwoSameNamedEntries(), node: { level: 'settlement', fieldId: 'shipping_city' } } );

		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const result = instances[ 0 ].query( 'Александровка' );
		await Promise.resolve().then( () => Promise.resolve() );

		const results = result.success.mock.calls[ 0 ][ 0 ].results;

		expect( results ).toEqual( [
			{ id: 'Александровка', text: 'Александровка (Воронежская обл.)', key: 'dadata:first-alexandrovka' },
			{ id: 'Александровка', text: 'Александровка (Оренбургская обл.)', key: 'dadata:second-alexandrovka' },
		] );
	} );

	it( 'picking the FIRST of two same-named results resolves to the FIRST record — FAILS on a value-keyed dataByKey, which always keeps whichever entry merged in last', async () => {
		const options = buildOptions( { fetch: fetchTwoSameNamedEntries(), node: { level: 'settlement', fieldId: 'shipping_city' } } );

		document.body.innerHTML = '<form id="checkout"><input type="text" id="shipping_city" name="shipping_city" value="" /></form>';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const result = instances[ 0 ].query( 'Александровка' );
		await Promise.resolve().then( () => Promise.resolve() );

		const results = result.success.mock.calls[ 0 ][ 0 ].results;
		const select = document.getElementById( 'shipping_city' );

		simulateSelect2Pick( select, results, 0 );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( {
			record: { key: 'dadata:first-alexandrovka', label: 'Александровка (Воронежская обл.)' },
		} );
	} );

	it( 'picking the SECOND of two same-named results resolves to the SECOND record', async () => {
		const options = buildOptions( { fetch: fetchTwoSameNamedEntries(), node: { level: 'settlement', fieldId: 'shipping_city' } } );

		document.body.innerHTML = '<form id="checkout"><input type="text" id="shipping_city" name="shipping_city" value="" /></form>';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const result = instances[ 0 ].query( 'Александровка' );
		await Promise.resolve().then( () => Promise.resolve() );

		const results = result.success.mock.calls[ 0 ][ 0 ].results;
		const select = document.getElementById( 'shipping_city' );

		simulateSelect2Pick( select, results, 1 );

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( {
			record: { key: 'dadata:second-alexandrovka', label: 'Александровка (Оренбургская обл.)' },
		} );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — NOTED finding: `minimumInputLength` is now picked per level, not a universal 2
// with no provider invariant behind it.
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — minimumInputLength is picked per level, not a universal constant', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'a region-level field gets a floor of 1 — a small, already server-cached list', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_state' ), buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		expect( instances[ 0 ].config.minimumInputLength ).toBe( 1 );
	} );

	it( 'a settlement-level field gets a floor of 2 — matches woocommerce-edostavka\'s own city adapter default', () => {
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), buildOptions( { node: { level: 'settlement', fieldId: 'shipping_city' } } ) );

		expect( instances[ 0 ].config.minimumInputLength ).toBe( 2 );
	} );

	it( 'an address-level field also gets a floor of 2 — the same locality-name-search precedent as settlement', () => {
		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), buildOptions( { node: { level: 'address', fieldId: 'billing_address_1' } } ) );

		expect( instances[ 0 ].config.minimumInputLength ).toBe( 2 );
	} );
} );

// -----------------------------------------------------------------------
// ajax-select2 — issue #457: detach() must not throw when the node's select2 data has already
// been purged out from under it (WooCommerce's own `update_checkout` replacing the address
// fragment via jQuery `.html()`/`.empty()`, which runs `cleanData()` on this exact node).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #457: detach() gates on the node\'s actual select2 data, not a closure flag', () => {
	let mod;

	/**
	 * A select2 stub that mirrors selectWoo.full.js's own `$element.data('select2', this)` /
	 * `removeData('select2')` bookkeeping (selectWoo.full.js:5258,5782) AND its own
	 * `$.fn.selectWoo('destroy')` dispatch, which dereferences `$(this).data('select2')` with
	 * NO null-guard even after logging (selectWoo.full.js:6562-6571) — reproducing the exact
	 * TypeError this issue is about, not an approximation of it.
	 *
	 * @param {Object} $ jQuery.
	 * @returns {void}
	 */
	function installSelectWooLikeStub( $ ) {
		$.fn.select2 = jest.fn( function( methodOrConfig ) {
			var $el = this;

			if ( 'string' === typeof methodOrConfig ) {
				var instance = $el.data( 'select2' );

				if ( instance == null && window.console && console.error ) {
					console.error( 'select2(\'' + methodOrConfig + '\') called on an element that is not using Select2.' );
				}

				return instance[ methodOrConfig ].apply( instance, [] );
			}

			$el.data( 'select2', {
				destroy: function() {
					$el.removeData( 'select2' );
				},
			} );

			return $el;
		} );
	}

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'does not call select2(\'destroy\') — and never throws or logs — when the select2 data has already been purged out from under the node', () => {
		const input = document.getElementById( 'shipping_city' );

		installSelectWooLikeStub( window.jQuery );

		const api = mod.attachAjaxSelect2( input, buildOptions( { node: { level: 'settlement', fieldId: 'shipping_city' } } ) );

		expect( window.jQuery( api.el ).data( 'select2' ) ).toBeTruthy();

		// Mirrors WooCommerce's own update_checkout: jQuery's cleanData() purges this exact
		// node's select2 instance data WITHOUT ever calling OUR detach() — the node itself
		// survives (this closure still holds it), only the data is gone.
		window.jQuery( api.el ).removeData( 'select2' );

		const errorSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		expect( () => api.detach() ).not.toThrow();
		expect( errorSpy ).not.toHaveBeenCalled();

		errorSpy.mockRestore();
	} );

	it( 'regression: still calls select2(\'destroy\') and clears the select2 data when it is genuinely present', () => {
		const input = document.getElementById( 'shipping_city' );

		installSelectWooLikeStub( window.jQuery );

		const api = mod.attachAjaxSelect2( input, buildOptions( { node: { level: 'settlement', fieldId: 'shipping_city' } } ) );
		const select = api.el;

		expect( window.jQuery( select ).data( 'select2' ) ).toBeTruthy();

		api.detach();

		expect( window.jQuery( select ).data( 'select2' ) ).toBeUndefined();
	} );
} );
