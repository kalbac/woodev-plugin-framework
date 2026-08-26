/**
 * Tests for location-select-modes.js — Task 13 of the 2026-08-12 location-provider plan
 * (spec D7: `related-list` region renderer, `ajax-select2`). The settlement axis's own
 * `related-list` renderer (`attachRelatedListSettlement()`) and its tests were removed by
 * issue #529: the settlement axis never offers `related-list` (operator decision 24.08.2026,
 * issue #486), so no store configuration could ever reach it.
 *
 * Covers, per renderer: the decline contract (wrong element shape falls back to the baseline
 * typeahead, per `location-cascade.js`'s own `attachOne()`), the DOM-replacement + restore
 * lifecycle for the select2-backed `ajax-select2` renderer (select2 can only enhance a real
 * `<select>`), population from the SAME `/location/list`/`options.fetch` primitives the cascade
 * hands over, the SHARED `onSelect` persist path (never a duplicated one), and the dual
 * native+jQuery `change` event-world binding (gotcha
 * `jquery-trigger-change-fires-no-native-event`) — including that a SINGLE real native event
 * delivered to BOTH bound worlds calls `onSelect` only once (double delivery harmless by
 * construction).
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

/**
 * Issue #517 round 3 (critic MJ-3): `handleSelect2Close()` defers its flush via
 * `setTimeout( fn, 0 )` rather than firing inline (see that function's own docblock for the
 * measured rig event order this survives). A test that expects the deferred fire has to
 * actually wait for that macrotask — a microtask chain (`Promise.resolve().then(...)`) resolves
 * BEFORE any `setTimeout`, so it is never enough on its own.
 *
 * @returns {Promise<void>}
 */
function tick() {
	return new Promise( function( resolve ) {
		setTimeout( resolve, 0 );
	} );
}

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
			// Issue #528: defaults ON so every EXISTING #517 onAbandon test (predating the
			// merchant opt-in) keeps exercising the recording/flush mechanism unchanged — the
			// dedicated `allowCustomSettlement` describe block below overrides this to `false`
			// to prove the gate itself.
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
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

	afterEach( () => {
		delete window.wc_country_select_params;
	} );

	// -------------------------------------------------------------------------
	// Issue #526 — select2's UI messages come from WooCommerce's own
	// `wc_country_select_params`, never from select2's built-in English defaults and never
	// from a literal invented in this repo.
	//
	// The keys and the shape below are NOT guessed: they are copied from WooCommerce's own
	// `assets/js/frontend/country-select.js` + `WC_Frontend_Scripts::get_script_data()`
	// `case 'wc-country-select'`, both read in the rig container (`woocommerce.latest-stable`)
	// on 25.08.2026. `WC_PARAMS` below is that param set verbatim, in ENGLISH, because that
	// is what the rig actually serves — the site locale there is English, so English strings
	// coming out of these tests is the CORRECT result and not a reproduction of the bug. What
	// the fix changes is the SOURCE, which is what these tests measure.
	// -------------------------------------------------------------------------

	const WC_PARAMS = {
		i18n_no_matches: 'No matches found',
		i18n_ajax_error: 'Loading failed',
		i18n_input_too_short_1: 'Please enter 1 or more characters',
		i18n_input_too_short_n: 'Please enter %qty% or more characters',
		i18n_input_too_long_1: 'Please delete 1 character',
		i18n_input_too_long_n: 'Please delete %qty% characters',
		i18n_selection_too_long_1: 'You can only select 1 item',
		i18n_selection_too_long_n: 'You can only select %qty% items',
		i18n_load_more: 'Loading more results…',
		i18n_searching: 'Searching…',
	};

	it( 'an ajax strategy wires language from wc_country_select_params — the mode the card was filed against, which used to get no language block at all', () => {
		window.wc_country_select_params = { ...WC_PARAMS };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', emptyText: '' }
		);

		expect( config.language.noResults() ).toBe( 'No matches found' );
		expect( config.language.searching() ).toBe( 'Searching…' );
		expect( config.language.loadingMore() ).toBe( 'Loading more results…' );
	} );

	it( 'inputTooShort — the message the settlement field shows BEFORE the customer types, given minimumInputLength 2', () => {
		window.wc_country_select_params = { ...WC_PARAMS };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', emptyText: '' }
		);

		expect( config.minimumInputLength ).toBe( 2 );

		// Two characters still to go — the plural msgid, with %qty% substituted.
		expect( config.language.inputTooShort( { minimum: 2, input: '' } ) )
			.toBe( 'Please enter 2 or more characters' );

		// Exactly one to go — WooCommerce ships this as its OWN msgid, not as a %qty% of 1.
		expect( config.language.inputTooShort( { minimum: 2, input: 'М' } ) )
			.toBe( 'Please enter 1 or more characters' );
	} );

	it( 'errorLoading returns i18n_searching, matching WooCommerce\'s own select2#4355 workaround — NOT i18n_ajax_error', () => {
		window.wc_country_select_params = { ...WC_PARAMS };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', emptyText: '' }
		);

		// Pinned deliberately: the "obvious" key here is `i18n_ajax_error` ("Loading failed"),
		// and WooCommerce does not use it. Reading this as a bug in the reference and
		// "fixing" it on the way past is the mistake this test exists to catch.
		expect( config.language.errorLoading() ).toBe( 'Searching…' );
		expect( config.language.errorLoading() ).not.toBe( WC_PARAMS.i18n_ajax_error );
	} );

	it( 'this layer\'s own emptyText outranks WooCommerce\'s generic i18n_no_matches — the two settlement modes must agree', () => {
		window.wc_country_select_params = { ...WC_PARAMS };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{
				initialValue: '',
				placeholder: '',
				applyEntries: jest.fn(),
				level: 'settlement',
				emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.',
			}
		);

		expect( config.language.noResults() )
			.toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	} );

	it( 'a missing wc_country_select_params OMITS every key, so select2 keeps its own English — a key defined-but-returning-undefined would render a BLANK message', () => {
		// No `window.wc_country_select_params` at all. The dependency declared in
		// `Checkout_Handler` makes a missing HANDLE unlikely; the demonstrated route to
		// missing PARAMS is WooCommerce's own `woocommerce_get_script_data` filter, which any
		// plugin may use to strip msgids from the localized object. (An earlier version of
		// this comment blamed "a third party can dequeue anything" — unverified, and not the
		// route a re-critic could actually demonstrate.)
		//
		// The distinction this test pins is measured in the shipped selectWoo, not reasoned:
		// `customTranslation.extend( baseTranslation )` is `$.extend( {}, base, ours )`
		// (`selectWoo.full.js:2236,4934-4940`), so ANY key we define shadows the English one
		// permanently. A callback returning `undefined` therefore does NOT fall back — it
		// renders an empty message box. Only ABSENCE of the key lets English through.
		//
		// The first version of this fix asserted the opposite and said so in a docblock.
		// Codex refuted it against the selectWoo source; re-verified before this rewrite.
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', emptyText: '' }
		);

		expect( config.language ).toEqual( {} );
		expect( 'noResults' in config.language ).toBe( false );
		expect( 'inputTooShort' in config.language ).toBe( false );
		expect( 'searching' in config.language ).toBe( false );
	} );

	it( 'a HALF-localized plural pair omits that key rather than wiring a callback that can return undefined on one branch', () => {
		// Only the singular msgid present. Both branches of `inputTooShort` are reachable
		// (minimum 2 renders the plural, minimum 1 the singular), so a key wired off the
		// singular alone would render blank exactly when the customer has typed nothing.
		window.wc_country_select_params = { i18n_input_too_short_1: 'Please enter 1 or more characters' };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', emptyText: '' }
		);

		expect( 'inputTooShort' in config.language ).toBe( false );
	} );

	it( 'emptyText alone still wires noResults even with no wc_country_select_params at all', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{
				initialValue: '',
				placeholder: '',
				applyEntries: jest.fn(),
				level: 'settlement',
				emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.',
			}
		);

		expect( config.language.noResults() )
			.toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
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

		// issue #449 (second half): the transport now hands fetchEntries() a second argument
		// carrying a real AbortSignal (feature-detected via window.AbortController, present in
		// jsdom) — see the dedicated abort-propagation test below for the cancellation contract
		// itself; this assertion only pins the call SHAPE.
		expect( fetchEntries ).toHaveBeenCalledWith( 'Твер', { signal: expect.any( AbortSignal ) } );
		expect( applyEntries ).toHaveBeenCalledWith(
			[ { key: 'dadata:tv', label: 'ул Тверская', record: { key: 'dadata:tv', label: 'ул Тверская' } } ],
			false
		);
		expect( success ).toHaveBeenCalledWith( { results: [ { id: 'dadata:tv', text: 'ул Тверская', key: 'dadata:tv' } ] } );
		expect( failure ).not.toHaveBeenCalled();
	} );

	// -----------------------------------------------------------------------
	// Issue #530 — a completed search's results are re-ranked so an entry ALSO in the shop's
	// popular list sorts above the rest of that same search (a stable partition, never a
	// re-sort of provider relevance within either group).
	// -----------------------------------------------------------------------

	it( 'issue #530: entries that are also in seed.popular() rank above the rest, preserving the provider\'s own relative order within each group', async () => {
		// Provider relevance order: Тверь, Тверская область, Тверь (Калужская обл.) — only
		// the SECOND one is popular. The expected output moves it to the front, WITHOUT
		// reordering the other two relative to each other.
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:a', label: 'Тверь', record: { key: 'dadata:a', label: 'Тверь' } },
			{ key: 'dadata:b', label: 'Тверская область', record: { key: 'dadata:b', label: 'Тверская область' } },
			{ key: 'dadata:c', label: 'Тверь (Калужская обл.)', record: { key: 'dadata:c', label: 'Тверь (Калужская обл.)' } },
		] ) );
		const applyEntries = jest.fn( ( entries ) => entries );
		const popular = jest.fn( () => [ { key: 'dadata:b' } ] );

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries, popular: popular }
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Твер' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledWith( {
			results: [
				{ id: 'dadata:b', text: 'Тверская область', key: 'dadata:b' },
				{ id: 'dadata:a', text: 'Тверь', key: 'dadata:a' },
				{ id: 'dadata:c', text: 'Тверь (Калужская обл.)', key: 'dadata:c' },
			],
		} );
		// Read LIVE, not captured once — a region picked between two searches must scope the
		// very next ranking, same discipline `options.parentKey()`'s own callers already follow.
		expect( popular ).toHaveBeenCalled();
	} );

	it( 'control: with no seed.popular at all (a related-list-only page, or a level the popular list never carries), the transport reports results in the provider\'s own order, unchanged', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:a', label: 'Тверь', record: { key: 'dadata:a', label: 'Тверь' } },
			{ key: 'dadata:b', label: 'Тверская область', record: { key: 'dadata:b', label: 'Тверская область' } },
		] ) );
		const applyEntries = jest.fn( ( entries ) => entries );

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries } // no `popular` key at all.
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Твер' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledWith( {
			results: [
				{ id: 'dadata:a', text: 'Тверь', key: 'dadata:a' },
				{ id: 'dadata:b', text: 'Тверская область', key: 'dadata:b' },
			],
		} );
	} );

	// -----------------------------------------------------------------------
	// Issue #530 ROUND 2 (BLOCKER 2, s93 rig measurement): seeding real <option> elements is
	// NOT sufficient in ajax mode — select2's AjaxData adapter never reads a <select>'s own DOM
	// options for what it renders, and `minimumInputLength` blocks the transport from running
	// at all below the floor. The fix scopes the floor to 0 for a level that carries a popular
	// list, and answers an empty (or below-floor) term LOCALLY, never over the network.
	// -----------------------------------------------------------------------

	it( 'BLOCKER 2: minimumInputLength is scoped to 0 for a level that carries a popular list', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', popular: jest.fn( () => [] ) }
		);

		expect( config.minimumInputLength ).toBe( 0 );
	} );

	it( 'control: minimumInputLength keeps its ORIGINAL floor when no popular list exists for this level — the scoping never loosens a gate it cannot itself answer', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement' } // no `popular` key.
		);

		expect( config.minimumInputLength ).toBe( 2 );
	} );

	it( 'BLOCKER 2: an EMPTY term is answered from seed.popular() directly, SYNCHRONOUSLY — strategy.fetchEntries (the network call) is never invoked', () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [] ) );
		const popularEntries = [
			{ key: 'dadata:tv', label: 'Тверь', value: 'Тверь', record: { key: 'dadata:tv', label: 'Тверь' } },
			{ key: 'dadata:kz', label: 'Казань', value: 'Казань', record: { key: 'dadata:kz', label: 'Казань' } },
		];
		const applyEntries = jest.fn( ( entries ) => entries );
		const popular = jest.fn( () => popularEntries );

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries, level: 'settlement', popular: popular }
		);

		const success = jest.fn();
		const failure = jest.fn();

		config.ajax.transport( { data: { term: '' } }, success, failure );

		// SYNCHRONOUS — no `await`, no microtask flush. MUTATION PROOF (per the brief's own
		// rule): reverting the empty-term short-circuit in the transport routes this through
		// `strategy.fetchEntries('').then(...)` instead — a real Promise, whose `.then()`
		// callback cannot have run yet at this point — so `success` would still be
		// `not.toHaveBeenCalled()` here and this assertion fails. Verified by hand (see the
		// worker_done report for the exact mutation and its failing output).
		expect( fetchEntries ).not.toHaveBeenCalled();
		expect( applyEntries ).toHaveBeenCalledWith( popularEntries, false );
		expect( success ).toHaveBeenCalledWith( {
			results: [
				{ id: 'Тверь', text: 'Тверь', key: 'dadata:tv' },
				{ id: 'Казань', text: 'Казань', key: 'dadata:kz' },
			],
		} );
		expect( failure ).not.toHaveBeenCalled();
	} );

	it( 'BLOCKER 2: a NON-EMPTY term shorter than the real floor also never reaches strategy.fetchEntries — answered locally as zero results, not a search', () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [ { key: 'dadata:x', label: 'x', record: { key: 'dadata:x', label: 'x' } } ] ) );
		const applyEntries = jest.fn( ( entries ) => entries );
		const popular = jest.fn( () => [] );

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries, level: 'settlement', popular: popular }
		);

		const success = jest.fn();

		// One character — settlement's own floor (minimumInputLengthFor()) is 2, only REACHABLE
		// here at all because BLOCKER 2's own scoping lowered select2's built-in gate to 0.
		config.ajax.transport( { data: { term: 'М' } }, success, jest.fn() );

		expect( fetchEntries ).not.toHaveBeenCalled();
		expect( success ).toHaveBeenCalledWith( { results: [] } );
	} );

	it( 'control: a term AT the real floor still reaches strategy.fetchEntries normally — the scoping only ever widens what is answered LOCALLY, never narrows what is searched', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tv', label: 'Тверь', record: { key: 'dadata:tv', label: 'Тверь' } },
		] ) );
		const applyEntries = jest.fn( ( entries ) => entries );
		const popular = jest.fn( () => [] );

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: applyEntries, level: 'settlement', popular: popular }
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Тв' } }, success, jest.fn() ); // exactly 2 chars

		await Promise.resolve().then( () => Promise.resolve() );

		expect( fetchEntries ).toHaveBeenCalledWith( 'Тв', expect.anything() );
		expect( success ).toHaveBeenCalledWith( { results: [ { id: 'dadata:tv', text: 'Тверь', key: 'dadata:tv' } ] } );
	} );

	it( 'BLOCKER 2: language.noResults shows the "type N more characters" wording for a below-floor term once popular scoping lowered the built-in gate — and the ordinary message otherwise', () => {
		window.wc_country_select_params = { ...WC_PARAMS };

		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement', popular: jest.fn( () => [] ), emptyText: '' }
		);

		expect( config.language.noResults( { term: 'М' } ) ).toBe( 'Please enter 1 or more characters' );
		expect( config.language.noResults( { term: 'Москва не найдена' } ) ).toBe( WC_PARAMS.i18n_no_matches );
	} );

	// -----------------------------------------------------------------------
	// Issue #528 — `tags`/`createTag`/`insertTag`, gated on `seed.allowCustomSettlement` AND
	// (round 2, critic MJ-A) `'settlement' === seed.level`.
	// -----------------------------------------------------------------------

	it( 'control: seed.allowCustomSettlement omitted (falsy) wires NO tags/createTag/insertTag into the ajax config', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), level: 'settlement' }
		);

		expect( config.tags ).toBeUndefined();
		expect( config.createTag ).toBeUndefined();
		expect( config.insertTag ).toBeUndefined();
	} );

	it( 'seed.allowCustomSettlement === true AND seed.level === "settlement" wires tags: true plus createTag/insertTag into the ajax config', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), allowCustomSettlement: true, level: 'settlement' }
		);

		expect( config.tags ).toBe( true );
		expect( typeof config.createTag ).toBe( 'function' );
		expect( typeof config.insertTag ).toBe( 'function' );
	} );

	// -----------------------------------------------------------------------
	// Critic MJ-A (round 2): the opt-in must NOT also enable tags for the REGION level, whose
	// own `ajax-select2` widget shares this exact function — a region value posts as
	// `billing_state`/`shipping_state`, permanent order data the option's own label/tooltip
	// never promised to touch.
	// -----------------------------------------------------------------------

	it( 'critic MJ-A: seed.allowCustomSettlement === true wires NO tags/createTag/insertTag for a REGION-level seed', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), allowCustomSettlement: true, level: 'region' }
		);

		expect( config.tags ).toBeFalsy();
		expect( config.createTag ).toBeUndefined();
		expect( config.insertTag ).toBeUndefined();
	} );

	it( 'critic MJ-A: an ADDRESS-level seed (the third ajax-select2-capable level) also gets no tags', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), allowCustomSettlement: true, level: 'address' }
		);

		expect( config.tags ).toBeFalsy();
	} );

	it( 'createTag() returns null for an empty or whitespace-only term — select2 offers no tag row at all', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), allowCustomSettlement: true, level: 'settlement' }
		);

		expect( config.createTag( { term: '' } ) ).toBeNull();
		expect( config.createTag( { term: '   ' } ) ).toBeNull();
		expect( config.createTag( {} ) ).toBeNull();
	} );

	it( 'createTag() trims the term and stamps newTag: true on a real term', () => {
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: jest.fn() },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn(), allowCustomSettlement: true, level: 'settlement' }
		);

		expect( config.createTag( { term: '  Тьмутаракань  ' } ) ).toEqual( {
			id: 'Тьмутаракань',
			text: 'Тьмутаракань',
			newTag: true,
		} );
	} );

	// -----------------------------------------------------------------------
	// Critic MJ-B (round 2): `insertTag` must answer from the completed search's own
	// `entries.length` — the SAME provider-truth signal the abandon gate uses a few lines
	// away in the same config — never from the rendered/filtered `data` array select2 hands
	// the hook, which can be empty for reasons that say nothing about what the provider
	// carries. Driven through the REAL `config.ajax.transport`, never a hand-built array —
	// the critic's own finding was that the shipped test could not have caught this because
	// it never drove `success()` at all.
	// -----------------------------------------------------------------------

	it( 'critic MJ-B control: a genuinely completed ZERO-result search offers the tag row', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [] ) );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn( () => [] ), allowCustomSettlement: true, level: 'settlement' }
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Тьмутаракань' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledWith( { results: [] } );

		const tag = { id: 'Тьмутаракань', text: 'Тьмутаракань', newTag: true };
		const data = [];

		config.insertTag( data, tag );
		expect( data ).toEqual( [ tag ] );
	} );

	it( 'critic MJ-B (Z4): a TRANSPORT ERROR still reports success([]) but offers NO tag row — an outage must not read as "the provider carries nothing"', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( null ) ); // attachAjaxSelect2()'s own fetchEntries() swallow contract
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn( () => [] ), allowCustomSettlement: true, level: 'settlement' }
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Тверь' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledWith( { results: [] } ); // the existing, unchanged contract

		const tag = { id: 'Тверь', text: 'Тверь', newTag: true };
		const data = [];

		config.insertTag( data, tag );
		expect( data ).toEqual( [] ); // MJ-B: must NOT offer a free-text row for an outage

		consoleSpy.mockRestore();
	} );

	it( 'critic MJ-B (Z3): a response the provider genuinely answered, but whose rows all fail applyEntries()\'s own value-derivation filter, offers NO tag row', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tver', label: 'г Тверь', record: { key: 'dadata:tver', label: 'г Тверь' } },
		] ) );
		// applyEntries() filtered EVERY row (e.g. no derivable submit value) — accepted/results
		// end up empty even though the provider plainly answered with something.
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{ initialValue: '', placeholder: '', applyEntries: jest.fn( () => [] ), allowCustomSettlement: true, level: 'settlement' }
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Тверь' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledWith( { results: [] } );

		const tag = { id: 'Тверь', text: 'Тверь', newTag: true };
		const data = [];

		config.insertTag( data, tag );
		expect( data ).toEqual( [] ); // MJ-B: the provider answered — no free-text row
	} );

	it( 'insertTag() control: a NON-empty result set — a town the provider actually carries — gets no tag row', async () => {
		const fetchEntries = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tver', value: 'Тверь', label: 'г Тверь', record: { key: 'dadata:tver', label: 'г Тверь' } },
		] ) );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries: fetchEntries },
			{
				initialValue: '', placeholder: '',
				applyEntries: jest.fn( ( entries ) => entries ),
				allowCustomSettlement: true, level: 'settlement',
			}
		);

		const success = jest.fn();

		config.ajax.transport( { data: { term: 'Тверь' } }, success, jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		const tag = { id: 'Тве', text: 'Тве', newTag: true };
		const withResults = success.mock.calls[ 0 ][ 0 ].results.slice();

		config.insertTag( withResults, tag );
		expect( withResults.indexOf( tag ) ).toBe( -1 );
	} );
} );

// -----------------------------------------------------------------------
// Issue #539 — the popular list narrows LOCALLY while the real search runs.
//
// MEASURED on the rig before the fix (region «Санкт-Петербург», popular list
// «Санкт-Петербург»/«Пушкин»/«Репино», term «Пушк»): select2 PREPENDS its «Searching…» row and
// leaves the previous results below it, so for 9.6 s the field went on offering «Санкт-Петербург»
// and «Репино» to a customer who had already typed «Пушк».
// -----------------------------------------------------------------------

describe( 'matchingPopular() — the local narrowing filter (#539)', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	const ENTRIES = [
		{ key: 'p:1', label: 'СПб', record: { label: 'Санкт-Петербург' } },
		{ key: 'p:2', label: 'ignored', record: { label: 'Пушкин' } },
		{ key: 'p:3', label: 'Репино', record: {} },
	];

	it( 'keeps only the entries whose label contains the term, case-insensitively', () => {
		expect( mod.matchingPopular( ENTRIES, 'Пушк' ).map( ( e ) => e.key ) ).toEqual( [ 'p:2' ] );
		expect( mod.matchingPopular( ENTRIES, 'пушк' ).map( ( e ) => e.key ) ).toEqual( [ 'p:2' ] );
		expect( mod.matchingPopular( ENTRIES, 'ПУШК' ).map( ( e ) => e.key ) ).toEqual( [ 'p:2' ] );
	} );

	it( 'matches SUBSTRING, not prefix — the same rule select2\'s own stock matcher applies to these very rows', () => {
		// «петер» is not a prefix of «Санкт-Петербург». select2's default matcher would still
		// match it, and a prefix-only rule here would make the popular list answer a keystroke
		// differently from every other list rendered in the same dropdown.
		expect( mod.matchingPopular( ENTRIES, 'петер' ).map( ( e ) => e.key ) ).toEqual( [ 'p:1' ] );
	} );

	it( 'reads record.label first and falls back to the entry label — the same text toSelect2Result renders', () => {
		// p:2's own `label` is 'ignored'; matching it would prove the wrong field was read.
		expect( mod.matchingPopular( ENTRIES, 'ignored' ) ).toEqual( [] );
		// p:3 has no record.label at all, so its own label is what must answer.
		expect( mod.matchingPopular( ENTRIES, 'Репино' ).map( ( e ) => e.key ) ).toEqual( [ 'p:3' ] );
	} );

	it( 'an empty term keeps everything — that is the idle popular list, not a search', () => {
		expect( mod.matchingPopular( ENTRIES, '' ) ).toHaveLength( 3 );
		expect( mod.matchingPopular( ENTRIES, undefined ) ).toHaveLength( 3 );
	} );

	it( 'tolerates a non-array and a hole rather than throwing', () => {
		expect( mod.matchingPopular( null, 'Пушк' ) ).toEqual( [] );
		expect( mod.matchingPopular( undefined, 'Пушк' ) ).toEqual( [] );
		expect( mod.matchingPopular( [ null, undefined ], 'Пушк' ) ).toEqual( [] );
	} );
} );

describe( 'ajax transport — local narrowing while the real search runs (#539)', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		window.wc_country_select_params = {
			i18n_no_matches: 'No matches found',
			i18n_input_too_short_1: 'Please enter 1 or more characters',
			i18n_input_too_short_n: 'Please enter %qty% or more characters',
			i18n_searching: 'Searching…',
		};
	} );

	afterEach( () => {
		delete window.wc_country_select_params;
	} );

	const POPULAR = [
		{ key: 'p:1', value: 'Санкт-Петербург', label: 'Санкт-Петербург', record: { label: 'Санкт-Петербург' } },
		{ key: 'p:2', value: 'Пушкин', label: 'Пушкин', record: { label: 'Пушкин' } },
		{ key: 'p:3', value: 'Репино', label: 'Репино', record: { label: 'Репино' } },
	];

	function buildAjax( overrides ) {
		let settle;
		const pending = new Promise( ( resolve ) => { settle = resolve; } );
		const fetchEntries = jest.fn( () => pending );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries },
			Object.assign( {
				initialValue: '', placeholder: '', level: 'settlement',
				emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.',
				popular: () => POPULAR,
				applyEntries: ( entries ) => ( Array.isArray( entries ) ? entries : [] ),
			}, overrides )
		);

		return { config, fetchEntries, settle };
	}

	// `success` asks `language.noResults` AT CALL TIME, because that is when select2 asks it:
	// the callback select2 hands this transport runs `processResults()` -> `results:all`
	// synchronously (`selectWoo.full.js:3586-3600`), and an empty list is rendered — and its
	// message resolved — inside that call, not after the transport returns. Sampling the hook
	// afterwards instead cannot tell the two orderings apart: a probe that moved
	// `searchInFlight = true` to AFTER the narrowing left every test green, while the rig showed
	// «Поиск не дал результатов» instantly. The flag is only ever wrong DURING this window.
	function run( config, term ) {
		const seenNoResults = [];
		const success = jest.fn( () => {
			seenNoResults.push( config.language.noResults( { term } ) );
		} );
		const failure = jest.fn();
		config.ajax.transport( { data: { term } }, success, failure );

		return { success, failure, seenNoResults };
	}

	it( 'paints the locally-matching popular entries SYNCHRONOUSLY, before the provider has answered anything', () => {
		const { config, fetchEntries } = buildAjax();
		const { success } = run( config, 'Пушк' );

		// Asserted while `fetchEntries`'s promise is deliberately still pending: this is the
		// 9.6-second window the customer used to spend looking at «Санкт-Петербург» and «Репино».
		expect( fetchEntries ).toHaveBeenCalledTimes( 1 );
		expect( success ).toHaveBeenCalledTimes( 1 );
		expect( success.mock.calls[ 0 ][ 0 ].results.map( ( r ) => r.text ) )
			.toEqual( [ 'Пушкин', 'Searching…' ] );
	} );

	// Round 2, found by the operator on the rig: with a local match the field showed «Пушкин» and
	// nothing else, so the customer could not tell a search was still running. `append()` opens
	// with `hideLoading()` (selectWoo.full.js:856), so ANY success() strips select2's own loading
	// row — the early paint has to carry its own.
	it( 'carries a loading row alongside the local matches, so the customer still sees a search running', () => {
		const { config } = buildAjax();
		const { success } = run( config, 'Пушк' );

		const rows = success.mock.calls[ 0 ][ 0 ].results;
		const loading = rows[ rows.length - 1 ];

		expect( loading.text ).toBe( 'Searching…' );
		// Shaped exactly as select2's own `showLoading()` shapes its row. `disabled` is what
		// makes `option()` drop `data-selected`, and BOTH the click binding and
		// `highlightFirstItem()` filter on that attribute — so this row cannot be clicked, cannot
		// be keyboard-selected, and never steals the first-item highlight.
		expect( loading.disabled ).toBe( true );
		expect( loading.loading ).toBe( true );
		// It must never look like a pickable entry: no id for select2 to resolve.
		expect( loading.id ).toBeUndefined();
	} );

	it( 'the loading row goes LAST — the real matches stay at the top where the customer looks', () => {
		const { config } = buildAjax();
		const { success } = run( config, 'Пушк' );

		expect( success.mock.calls[ 0 ][ 0 ].results[ 0 ].text ).toBe( 'Пушкин' );
	} );

	it( 'omits the loading row when WooCommerce localized no searching string — never a literal invented here', () => {
		delete window.wc_country_select_params.i18n_searching;

		const { config } = buildAjax();
		const { success } = run( config, 'Пушк' );

		expect( success.mock.calls[ 0 ][ 0 ].results.map( ( r ) => r.text ) ).toEqual( [ 'Пушкин' ] );
	} );

	it( 'STILL sends the request on a local hit — the popular list is ranking and an empty state, never coverage', () => {
		const { config, fetchEntries } = buildAjax();

		run( config, 'Пушк' );

		// The card's own rejected first form was "do not go to ajax when we found it locally".
		// «Мос» matching «Москва» locally must never hide Московский, Мосрентген and the rest.
		expect( fetchEntries ).toHaveBeenCalledTimes( 1 );
		expect( fetchEntries.mock.calls[ 0 ][ 0 ] ).toBe( 'Пушк' );
	} );

	it( 'the provider\'s own answer replaces the narrowed list when it lands', async () => {
		const { config, settle } = buildAjax();
		const { success } = run( config, 'Пушк' );

		settle( [ { key: 's:9', value: 'Пушкин, Санкт-Петербург, Россия', label: 'Пушкин, Санкт-Петербург, Россия', record: { label: 'Пушкин, Санкт-Петербург, Россия' } } ] );
		await Promise.resolve().then( () => Promise.resolve() ).then( () => Promise.resolve() );

		expect( success ).toHaveBeenCalledTimes( 2 );
		expect( success.mock.calls[ 1 ][ 0 ].results.map( ( r ) => r.text ) ).toEqual( [ 'Пушкин, Санкт-Петербург, Россия' ] );
	} );

	it( 'a term NO popular entry matches shows the loading row ALONE — never an empty list, never "not found"', () => {
		const { config } = buildAjax();
		const { success } = run( config, 'Выборг' );

		// Round 2 unified the two branches: a zero-match narrowing is a ONE-row list rather than
		// an empty one, so the customer sees the same "still searching" statement whether or not
		// a popular entry happened to match, and `noResults` is not reached on this path at all.
		expect( success.mock.calls[ 0 ][ 0 ].results.map( ( r ) => r.text ) ).toEqual( [ 'Searching…' ] );
	} );

	it( 'and the noResults guard STILL answers "searching" while in flight — the invariant does not rest on that row', () => {
		const { config } = buildAjax();
		const { seenNoResults } = run( config, 'Выборг' );

		// THE HALF THAT WAS WRONG ON THE FIRST RIG PASS, and the reason the flag is raised before
		// the narrowing rather than before the request: an empty list otherwise rendered «Поиск не
		// дал результатов» INSTANTLY, over a search that had not been sent. Kept as
		// belt-and-braces now that the loading row keeps the list non-empty — a future edit that
		// drops the row must not silently bring the false frame back. Sampled AT RENDER TIME; see
		// `run()`'s own comment for why sampling it afterwards proves nothing.
		expect( seenNoResults ).toEqual( [ 'Searching…' ] );
	} );

	it( 'once the provider answers empty, the SAME hook goes back to the real "nothing found" message', async () => {
		const { config, settle } = buildAjax();

		run( config, 'Выборг' );
		settle( [] );
		await Promise.resolve().then( () => Promise.resolve() ).then( () => Promise.resolve() );

		expect( config.language.noResults( { term: 'Выборг' } ) )
			.toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	} );

	it( 'a failed request also stops reading as searching — the flag never sticks', async () => {
		let reject;
		const fetchEntries = jest.fn( () => new Promise( ( resolve, r ) => { reject = r; } ) );
		const config = mod.selectConfigFor(
			{ ajax: true, fetchEntries },
			{
				initialValue: '', placeholder: '', level: 'settlement',
				emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.',
				popular: () => POPULAR,
				applyEntries: ( entries ) => ( Array.isArray( entries ) ? entries : [] ),
			}
		);

		config.ajax.transport( { data: { term: 'Выборг' } }, jest.fn(), jest.fn() );
		reject( new Error( 'boom' ) );
		await Promise.resolve().then( () => Promise.resolve() ).then( () => Promise.resolve() );

		expect( config.language.noResults( { term: 'Выборг' } ) )
			.toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	} );

	it( 'a below-floor term is untouched by the narrowing — it still short-circuits to the "type more" message', () => {
		const { config, fetchEntries } = buildAjax();
		const { success } = run( config, 'П' );

		expect( fetchEntries ).not.toHaveBeenCalled();
		expect( success.mock.calls[ 0 ][ 0 ].results ).toEqual( [] );
		expect( config.language.noResults( { term: 'П' } ) ).toBe( 'Please enter 1 or more characters' );
	} );

	it( 'an EMPTY term still answers with the whole popular list — the idle empty state is unchanged', () => {
		const { config, fetchEntries } = buildAjax();
		const { success } = run( config, '' );

		expect( fetchEntries ).not.toHaveBeenCalled();
		expect( success.mock.calls[ 0 ][ 0 ].results.map( ( r ) => r.text ) )
			.toEqual( [ 'Санкт-Петербург', 'Пушкин', 'Репино' ] );
	} );

	it( 'a level with NO popular list narrows nothing and keeps its own floor', () => {
		const { config, fetchEntries } = buildAjax( { popular: undefined, level: 'settlement' } );
		const { success } = run( config, 'Пушк' );

		expect( config.minimumInputLength ).toBe( 2 );
		expect( fetchEntries ).toHaveBeenCalledTimes( 1 );
		// Nothing painted before the answer — there is no local list to paint from.
		expect( success ).not.toHaveBeenCalled();
	} );
} );

// -----------------------------------------------------------------------
// Issue #540 — the SEARCH BOX's own placeholder.
//
// With #530's popular list showing ready-made towns, a customer can read that list as the whole
// offer and never realise the box above it accepts typing. select2 4.x has no config option for
// this placeholder, so it is set on `select2:open`.
// -----------------------------------------------------------------------

describe( 'search-box placeholder (#540)', () => {
	let mod;

	function installStub( $ ) {
		const calls = [];

		$.fn.select2 = jest.fn( function( config ) {
			calls.push( config );

			return this;
		} );

		return calls;
	}

	/**
	 * The markup select2 actually builds when a dropdown opens: a container carrying the PUBLIC
	 * `--open` class, with the search input inside it. The renderer finds the box through that
	 * class rather than through the select2 instance's private `$dropdown`, so the test has to
	 * present the same public surface.
	 *
	 * @param {boolean} open
	 * @returns {HTMLInputElement}
	 */
	function installDropdown( open ) {
		const container = document.createElement( 'span' );

		container.className = 'select2-container' + ( open ? ' select2-container--open' : '' );
		container.innerHTML = '<input class="select2-search__field" />';
		document.body.appendChild( container );

		return container.querySelector( '.select2-search__field' );
	}

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<form><input type="text" id="billing_city" name="billing_city" value="" /></form>';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	// Issue #529: this feature (`handleSelect2Open()`'s search-box placeholder) lives in
	// `buildSelectField()`, shared by every select2-backed renderer — proven here through
	// `attachAjaxSelect2()` now that `attachRelatedListSettlement()` (the settlement axis's
	// `related-list` mode, unreachable per operator decision 24.08.2026, issue #486) is gone.
	function attach( overrides ) {
		installStub( window.jQuery );
		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( overrides )
		);
	}

	it( 'stamps the server-supplied string onto the search box when the dropdown opens', async () => {
		attach( { searchPlaceholder: 'Начните вводить название' } );
		await Promise.resolve().then( () => Promise.resolve() );

		const box = installDropdown( true );

		expect( box.getAttribute( 'placeholder' ) ).toBeNull();

		window.jQuery( '#billing_city' ).trigger( 'select2:open' );

		expect( box.getAttribute( 'placeholder' ) ).toBe( 'Начните вводить название' );
	} );

	it( 'falls back to location.i18n.searchPlaceholder when the caller passed none directly', async () => {
		attach( {
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true, i18n: { searchPlaceholder: 'Введите город' } },
		} );
		await Promise.resolve().then( () => Promise.resolve() );

		const box = installDropdown( true );

		window.jQuery( '#billing_city' ).trigger( 'select2:open' );

		expect( box.getAttribute( 'placeholder' ) ).toBe( 'Введите город' );
	} );

	it( 'sets NOTHING when the server supplied no string — silence, never a literal invented here (#526)', async () => {
		attach( {} );
		await Promise.resolve().then( () => Promise.resolve() );

		const box = installDropdown( true );

		window.jQuery( '#billing_city' ).trigger( 'select2:open' );

		expect( box.hasAttribute( 'placeholder' ) ).toBe( false );
	} );

	it( 'never reaches a container that is not the OPEN one — at most one dropdown is open at a time', async () => {
		attach( { searchPlaceholder: 'Начните вводить название' } );
		await Promise.resolve().then( () => Promise.resolve() );

		const closedBox = installDropdown( false );

		window.jQuery( '#billing_city' ).trigger( 'select2:open' );

		// Another field's closed container must be left alone — a selector without the
		// `--open` half would have stamped this one too.
		expect( closedBox.hasAttribute( 'placeholder' ) ).toBe( false );
	} );
} );

describe( 'registers onto window.WoodevLocationRenderers on load', () => {
	it( 'registers related-list:region and the bare ajax-select2 key', () => {
		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		expect( typeof window.WoodevLocationRenderers[ 'related-list:region' ] ).toBe( 'function' );
		expect( typeof window.WoodevLocationRenderers[ 'ajax-select2' ] ).toBe( 'function' );
	} );

	// Issue #529: the settlement axis has exactly two modes, `typeahead` and `ajax-select2` —
	// `related-list` is clamped away unconditionally server-side (operator decision 24.08.2026,
	// issue #486), so this key must never come back.
	it( 'does NOT register related-list:settlement — the settlement axis never offers related-list (issue #486/#529)', () => {
		require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

		expect( window.WoodevLocationRenderers[ 'related-list:settlement' ] ).toBeUndefined();
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

	// Issue #541. The defect was a TIMING one, so every assertion here is about WHEN, not what:
	// the announcement has to be on the wire in the same tick as the pick, because the lookup it
	// precedes took 10.5 s on the rig and the customer spent all of it looking at an inert field
	// and a settlement list belonging to the region they had just left.
	describe( 'onResolving — announcing the pick before the record is known (#541)', () => {
		function buildResolvingOptions( overrides ) {
			// One shared log rather than three independent mocks: the defect was an ORDERING
			// one, and separate call counts cannot express "this happened before that".
			const order = [];
			const release = jest.fn( () => order.push( 'release' ) );
			const onResolving = jest.fn( () => {
				order.push( 'resolving' );

				return release;
			} );
			const onSelect = jest.fn( () => order.push( 'select' ) );

			return {
				order,
				release,
				onResolving,
				options: buildOptions( Object.assign(
					{ node: { level: 'region', fieldId: 'billing_state' }, onResolving, onSelect },
					overrides
				) ),
			};
		}

		it( 'announces the pick SYNCHRONOUSLY with the change, before /location/list is even answered', () => {
			const el = installSelect();
			const { options, order, onResolving, release } = buildResolvingOptions();

			mod.attachRelatedListRegion( el, options );

			el.value = 'МОСКВА';
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			// THE POINT, and the reason it is asserted here rather than after an await: the
			// `/location/list` call below is outstanding — deliberately never resolved in this
			// test — and the announcement has ALREADY happened. Move the call into the `.then`
			// (the pre-#541 shape, where nothing spoke until the record was known) and this
			// fails; assert it after awaiting the list instead and it would pass either way.
			expect( fetchJsonCalls ).toHaveLength( 1 );
			expect( onResolving ).toHaveBeenCalledTimes( 1 );
			expect( order ).toEqual( [ 'resolving' ] );
			expect( options.onSelect ).not.toHaveBeenCalled();
			expect( release ).not.toHaveBeenCalled();
		} );

		it( 'releases the announcement when the list carries nothing matching the selected text', async () => {
			const el = installSelect();
			const { options, release } = buildResolvingOptions();

			mod.attachRelatedListRegion( el, options );

			el.value = 'МОСКВА';
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			fetchJsonCalls[ 0 ].resolve( { localities: [ { record: { level: 'region', label: 'Казань' } } ] } );
			await Promise.resolve().then( () => Promise.resolve() );

			expect( options.onSelect ).not.toHaveBeenCalled();
			// Nothing else is coming for this pick, so the marker would otherwise spin forever.
			expect( release ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'releases on the MATCH path too, after onSelect — the one path, never two', async () => {
			const el = installSelect();
			const { options, order, release } = buildResolvingOptions();

			mod.attachRelatedListRegion( el, options );

			el.value = 'МОСКВА';
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			fetchJsonCalls[ 0 ].resolve( { localities: [ { record: { level: 'region', label: 'Москва' } } ] } );
			await Promise.resolve().then( () => Promise.resolve() );

			expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
			// Harmless where onSelect raised a marker of its own (the cascade's token check
			// stands this down — proven in location-cascade.test.js), and REQUIRED where it did
			// not: a mayEnterChain() refusal returns from onSelect with the marker still up.
			expect( release ).toHaveBeenCalledTimes( 1 );
			// Never BEFORE the select — releasing first would clear the marker that onSelect is
			// about to hand over to, and the field would blink instead of staying busy.
			expect( order ).toEqual( [ 'resolving', 'select', 'release' ] );
		} );

		it( 'a renderer handed NO onResolving still selects normally — the primitive is optional', async () => {
			const el = installSelect();
			const options = buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } );

			expect( options.onResolving ).toBeUndefined();

			mod.attachRelatedListRegion( el, options );

			el.value = 'МОСКВА';
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			fetchJsonCalls[ 0 ].resolve( { localities: [ { record: { level: 'region', label: 'Москва' } } ] } );
			await Promise.resolve().then( () => Promise.resolve() );

			expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		} );
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

		// issue #449 (second half): options.fetch now also receives an AbortSignal — see the
		// dedicated abort-propagation test for the cancellation contract itself.
		expect( fetchSpy ).toHaveBeenCalledWith( 'Твер', { signal: expect.any( AbortSignal ) } );
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

	/**
	 * Issue #466: WooCommerce's `country-select.js` reads `data-input-classes` and
	 * `placeholder`/`data-placeholder` straight off whatever CURRENTLY occupies the state
	 * field before replacing it (`country-select.js:103,105`; verified against the vendored
	 * copy at `D:/Projects/wordpress/woocommerce/assets/js/frontend/country-select.js`) —
	 * never `class`. A `<select>` this file built without either attribute makes WC's next
	 * rebuild carry forward `undefined`/empty, measured on the rig as the field WC left behind
	 * reading `class="input-text undefined"`. The CDEK reference
	 * (`plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:79-80`)
	 * already carries both for exactly this reason.
	 */
	it( 'carries data-input-classes from the input onto the generated <select> (issue #466)', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" class="input-text" data-input-classes="input-text validate-required" />';

		mod.attachAjaxSelect2( document.getElementById( 'shipping_state' ), buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		expect( document.getElementById( 'shipping_state' ).getAttribute( 'data-input-classes' ) ).toBe( 'input-text validate-required' );
	} );

	/**
	 * Issue #469, reversing the #466 decision to leave the attribute unset when the input
	 * carried none. Leaving it unset IS the defect: `$statebox.attr( 'data-input-classes' )`
	 * yields `undefined` for a missing attribute and `country-select.js:120` concatenates that
	 * straight into a class list, which is the rig's `class="input-text  undefined"`
	 * fingerprint. The empty string is not fabricated — it is exactly what WooCommerce's own
	 * `state` branch emits for a field with no `input_class`, and a stock install always lands
	 * there because WC core sets none on address fields. The server cannot supply it:
	 * `woocommerce_form_field()` drops empty-string entries from `custom_attributes` via
	 * `array_filter( …, 'strlen' )` (`wc-template-functions.php:3367`, WooCommerce 11.0.1).
	 */
	it( 'sets data-input-classes to the empty string when the input carried none (issue #469)', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" />';

		mod.attachAjaxSelect2( document.getElementById( 'shipping_state' ), buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		const select = document.getElementById( 'shipping_state' );

		expect( select.hasAttribute( 'data-input-classes' ) ).toBe( true );
		expect( select.getAttribute( 'data-input-classes' ) ).toBe( '' );
	} );

	/**
	 * The value WooCommerce's rebuild actually reads back must be a string, not `undefined` —
	 * assert through the same accessor `country-select.js` uses rather than through our own
	 * attribute write, so the test fails if the element ever stops exposing it (issue #469).
	 */
	it( 'the generated <select> reads back a string, never undefined, for data-input-classes (issue #469)', () => {
		document.body.innerHTML = '<input type="text" id="billing_state" name="billing_state" value="" />';

		mod.attachAjaxSelect2( document.getElementById( 'billing_state' ), buildOptions( { node: { level: 'region', fieldId: 'billing_state' } } ) );

		const readBack = document.getElementById( 'billing_state' ).getAttribute( 'data-input-classes' );

		expect( typeof readBack ).toBe( 'string' );
		expect( 'state_select ' + readBack ).toBe( 'state_select ' );
	} );

	it( 'writes the resolved placeholder onto BOTH placeholder and data-placeholder on the generated <select> — WC\'s rebuild reads either (issue #466)', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" placeholder="Своя подсказка" />';

		mod.attachAjaxSelect2( document.getElementById( 'shipping_state' ), buildOptions( { node: { level: 'region', fieldId: 'shipping_state' } } ) );

		const select = document.getElementById( 'shipping_state' );

		expect( select.getAttribute( 'placeholder' ) ).toBe( 'Своя подсказка' );
		expect( select.getAttribute( 'data-placeholder' ) ).toBe( 'Своя подсказка' );
	} );

	it( 'the i18n-fallback placeholder is ALSO written onto the generated <select>, not just handed to select2 (issue #466)', () => {
		document.body.innerHTML = '<input type="text" id="shipping_state" name="shipping_state" value="" />';

		mod.attachAjaxSelect2(
			document.getElementById( 'shipping_state' ),
			buildOptions( {
				node: { level: 'region', fieldId: 'shipping_state' },
				location: { endpoints: { list: LIST_URL }, mode: 'related-list', i18n: { placeholder: 'Выберите регион' } },
			} )
		);

		expect( document.getElementById( 'shipping_state' ).getAttribute( 'data-placeholder' ) ).toBe( 'Выберите регион' );
	} );

	it( 'sets neither placeholder attribute when the field carries no placeholder anywhere (issue #466)', () => {
		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), buildOptions() );

		const select = document.getElementById( 'billing_address_1' );

		expect( select.hasAttribute( 'placeholder' ) ).toBe( false );
		expect( select.hasAttribute( 'data-placeholder' ) ).toBe( false );
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
		expect( fetchSpy ).toHaveBeenCalledWith( 'Тв', { signal: expect.any( AbortSignal ) } );
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

	it( 'FIXED (issue #449, second half): abort() actually reaches fetch — the AbortSignal handed to options.fetch on the superseded call is aborted once a newer query starts, the still-current call\'s is not', () => {
		// This is the cancellation half #461 deliberately left undone (see that PR's own
		// comment, now removed): a `stale` flag alone stops a superseded response from
		// REPAINTING the list, but the underlying `fetch()` kept running to completion —
		// costing DaData/CDEK a paid call per keystroke regardless. Real cancellation means the
		// SIGNAL options.fetch receives is the one selectWoo's own store-then-abort sequence
		// (mirrored by the #450 fake) actually aborts, not merely a closure flag this file
		// alone can see.
		const seenSignals = [];
		const fetchSpy = jest.fn( ( term, opts ) => {
			seenSignals.push( opts && opts.signal );

			// Never settles — only the signal's own `aborted` state matters to this test, never
			// what the promise resolves/rejects with.
			return new Promise( () => {} );
		} );
		const options = buildOptions( { fetch: fetchSpy } );

		document.body.innerHTML = '<input type="text" id="billing_address_1" name="billing_address_1" value="" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'billing_address_1' ), options );

		instances[ 0 ].query( 'Мо' );
		// The fake's own store-then-abort sequence calls the FIRST call's returned `abort()`
		// before issuing this second query — the exact selectWoo AjaxAdapter behaviour this
		// transport is built to cooperate with.
		instances[ 0 ].query( 'Моск' );

		expect( seenSignals ).toHaveLength( 2 );
		expect( seenSignals[ 0 ] ).toBeInstanceOf( AbortSignal );
		expect( seenSignals[ 1 ] ).toBeInstanceOf( AbortSignal );
		expect( seenSignals[ 0 ].aborted ).toBe( true );
		expect( seenSignals[ 1 ].aborted ).toBe( false );
	} );
} );

// -----------------------------------------------------------------------
// Issue #530 (#488's customer-facing half): the settlement field's EMPTY state is seeded from
// the shop's popular-settlements list — real <option> elements, since minimumInputLengthFor()
// floors this field at 2 characters and select2 renders NOTHING below that, so a fetched empty
// state is structurally impossible here (see location-select-modes.js's own comment at the
// seeding call site).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #530: the empty state is seeded from options.popular()', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	const POPULAR = [
		{
			key: 'dadata:tv', label: 'Тверь', level: 'settlement', value: 'Тверь',
			record: { key: 'dadata:tv', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'Тверь' },
		},
		{
			key: 'dadata:kz', label: 'Казань', level: 'settlement', value: 'Казань',
			record: { key: 'dadata:kz', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'Казань' },
		},
	];

	it( 'an empty field with no placeholder seeds the popular entries, in the order options.popular() returned them, with NO select2 present at all', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" />';

		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( { popular: jest.fn( () => POPULAR ) } )
		);

		const select = document.getElementById( 'billing_city' );

		expect( select.options.length ).toBe( 2 );
		expect( select.options[ 0 ].value ).toBe( 'Тверь' );
		expect( select.options[ 0 ].textContent ).toBe( 'Тверь' );
		expect( select.options[ 1 ].value ).toBe( 'Казань' );
	} );

	// MUTATION PROOF (per the brief's own rule — a test that cannot fail survives review):
	// reverting the seeding call in buildSelectField() (the `if ( 'function' === typeof
	// options.popular ) { applyEntries( options.popular(), false ); }` block) back out makes
	// this test fail with `select.options.length` = 0, not 2. Verified by hand — see the
	// worker_done report for the exact mutation and its failing output.

	it( 'the blank leading <option> select2\'s placeholder requires stays FIRST — popular entries are appended after it, never before', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" placeholder="Населённый пункт" />';

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( { popular: jest.fn( () => POPULAR ) } )
		);

		expect( instances[ 0 ].config.placeholder ).toBe( 'Населённый пункт' );

		const select = document.getElementById( 'billing_city' );

		expect( select.options.length ).toBe( 3 );
		expect( select.options[ 0 ].value ).toBe( '' );
		expect( select.options[ 1 ].value ).toBe( 'Тверь' );
		expect( select.options[ 2 ].value ).toBe( 'Казань' );
	} );

	// -----------------------------------------------------------------------
	// Issue #530 ROUND 2 (BLOCKER 1, s93 rig measurement): a fresh customer, isolated browser
	// context, `test-cdek`, «Список с поиском» — `selectedIndex` came back `1`, not `0`: the
	// blank option existed but was NOT selected, and the field arrived showing «Санкт-Петербург»
	// pre-filled. ROOT CAUSE, measured against the real reset-selectedness algorithm a
	// non-`multiple` <select> runs: `applyEntries()` appends each popular <option> WITHOUT
	// `.selected` set, so the browser auto-selects the FIRST one the instant it lands in the
	// DOM; the blank leading <option> used to be inserted only AFTER that already happened —
	// too late to become the selected one. NOT `buildSelectField()`'s `initialValue` seam
	// (issue #447): that branch is `initialValue`-gated and never runs at all for a fresh
	// customer, whose input starts genuinely empty.
	// -----------------------------------------------------------------------

	it( 'BLOCKER 1: the blank leading <option> is the SELECTED one — a popular entry landing in the DOM first must never win the browser\'s own auto-select', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" placeholder="Населённый пункт" />';

		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( { popular: jest.fn( () => POPULAR ) } )
		);

		const select = document.getElementById( 'billing_city' );

		// The exact rig assertion shape (measured, s93): index, value, AND the rendered text —
		// never `.value` alone (this project's own standing rule: `.value` has passed while the
		// widget showed something else, three times before).
		expect( select.selectedIndex ).toBe( 0 );
		expect( select.value ).toBe( '' );
		expect( select.options[ select.selectedIndex ].textContent ).toBe( '' );
		expect( select.options[ 0 ].selected ).toBe( true );
		expect( select.options[ 1 ].selected ).toBe( false );
		expect( select.options[ 2 ].selected ).toBe( false );

		// MUTATION PROOF (per the brief's own rule): dropping the `blankOption.selected = true`
		// line this fix adds (leaving the rest of the insertion — position, `if ( placeholder )`
		// — unchanged) reproduces the measured defect exactly: `select.selectedIndex` comes back
		// `1`, `select.value` comes back `'Тверь'` (the first popular entry, matching the rig's
		// own «Санкт-Петербург» — whichever popular entry the shop ranks first), and this
		// assertion block fails on its very first line. Verified by hand — see the worker_done
		// report for the exact mutation and its failing output.
	} );

	it( 'a non-empty initialValue (issue #447) still wins — no popular seeding at all when the field already carries a value', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="Уже выбрано" />';

		const popular = jest.fn( () => POPULAR );

		mod.attachAjaxSelect2( document.getElementById( 'billing_city' ), buildOptions( { popular } ) );

		expect( popular ).not.toHaveBeenCalled();

		const select = document.getElementById( 'billing_city' );

		expect( select.options.length ).toBe( 1 );
		expect( select.options[ 0 ].value ).toBe( 'Уже выбрано' );
	} );

	it( 'picking a seeded popular option calls options.onSelect with its record — the SAME resolution path a search pick uses, never a separate mechanism (spec D1)', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" />';

		const onSelect = jest.fn();

		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( { popular: jest.fn( () => POPULAR ), onSelect } )
		);

		const select = document.getElementById( 'billing_city' );

		// The NATIVE resolution path (no select2 installed) — handleChange() reads the STABLE
		// identity applyEntries() stamped onto the option's own dataset, exactly like a
		// related-list pick. A real select2 pick would instead go through handleSelect2Select()
		// (issue #461 BLOCKING 2's own coverage), but both converge on the same resolveAndSelect().
		select.value = 'Казань';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( { record: POPULAR[ 1 ].record } );
	} );

	// -----------------------------------------------------------------------
	// Issue #530 ROUND 2 (BLOCKER 2, s93 rig measurement): end-to-end, through the REAL select2
	// config (via the #450 fake) — a fresh customer who opens the field, having typed nothing,
	// must see the popular cities listed, never the dead «Please enter 2 or more characters»
	// state the rig measured (`dropdownRowCount: 1`).
	// -----------------------------------------------------------------------

	it( 'BLOCKER 2, end-to-end: a fresh customer who opens the field having typed nothing sees the popular cities — answered directly, never over the network', () => {
		document.body.innerHTML = '<input type="text" id="billing_city" name="billing_city" value="" placeholder="Населённый пункт" />';

		// `options.fetch` — the REST `/suggest` round trip (the rig measures 6-10s for it).
		// This must never be called for the empty-term/open case.
		const fetch = jest.fn( () => Promise.resolve( [] ) );
		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2(
			document.getElementById( 'billing_city' ),
			buildOptions( { popular: jest.fn( () => POPULAR ), fetch } )
		);

		expect( instances[ 0 ].config.minimumInputLength ).toBe( 0 );

		// No term — exactly select2's own `open()` -> `trigger('query', {})` (verified against
		// the rig's own vendored selectWoo.full.js:5662-5668), the moment the field opens,
		// before any keystroke.
		const result = instances[ 0 ].query();

		expect( result ).not.toBeNull(); // never blocked by the (now-scoped) minimumInputLength gate.
		expect( fetch ).not.toHaveBeenCalled();
		expect( result.success ).toHaveBeenCalledWith( {
			results: [
				{ id: 'Тверь', text: 'Тверь', key: 'dadata:tv' },
				{ id: 'Казань', text: 'Казань', key: 'dadata:kz' },
			],
		} );
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

	it( 'falls back to entry.key when an entry carries no .value at all (defensive — every current caller of applyEntries() now stamps .value upstream, issues #455/#463)', async () => {
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

// -----------------------------------------------------------------------
// issue #449 (teardown gap, round 2) — a Codex critic finding: detach() never cancelled a
// request still in flight when the widget is torn down (WooCommerce's own `updated_checkout`
// does this on every re-render, not a rare path) — the SAME per-keystroke cost #449 already
// fixed, on a different trigger. See `location-select-modes.js`'s own `activeAbort` docblock.
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #449 (teardown gap, round 2): detach() cancels an in-flight request', () => {
	let mod;

	/**
	 * A select2 stub that captures the config passed to `.select2(config)` — so a test can drive
	 * `config.ajax.transport` directly, exactly as the real adapter would on a keystroke — AND
	 * reproduces the `$element.data('select2', ...)`/`removeData('select2')` bookkeeping
	 * `detach()`'s own issue #457 guard reads, needed here because that guard decides whether
	 * `.select2('destroy')` runs at all, and this suite needs both: select2 data genuinely
	 * present, and already purged out from under the node (the exact #457 trap).
	 *
	 * @param {Object} $ jQuery.
	 * @returns {Array<Object>} captured `.select2(config)` calls, in call order.
	 */
	function installCapturingSelect2Stub( $ ) {
		var calls = [];

		$.fn.select2 = jest.fn( function( methodOrConfig ) {
			var $el = this;

			if ( 'string' === typeof methodOrConfig ) {
				var instance = $el.data( 'select2' );

				return instance ? instance[ methodOrConfig ].apply( instance, [] ) : undefined;
			}

			calls.push( methodOrConfig );
			$el.data( 'select2', {
				destroy: function() {
					$el.removeData( 'select2' );
				},
			} );

			return $el;
		} );

		return calls;
	}

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'FAILS without the fix: detach() aborts the AbortSignal handed to options.fetch for a request still in flight', () => {
		// Never settles — proves the cancellation is real (the signal itself flips to aborted),
		// not merely that the pending promise happens to resolve before detach() runs.
		let seenSignal;
		const fetchSpy = jest.fn( ( term, opts ) => {
			seenSignal = opts && opts.signal;

			return new Promise( () => {} );
		} );
		const options = buildOptions( { fetch: fetchSpy, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const select2Calls = installCapturingSelect2Stub( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );

		expect( seenSignal ).toBeInstanceOf( AbortSignal );
		expect( seenSignal.aborted ).toBe( false );

		api.detach();

		expect( seenSignal.aborted ).toBe( true );
	} );

	it( 'still cancels the in-flight request when select2\'s own data was already purged out from under the node (issue #457 interaction)', () => {
		let seenSignal;
		const fetchSpy = jest.fn( ( term, opts ) => {
			seenSignal = opts && opts.signal;

			return new Promise( () => {} );
		} );
		const options = buildOptions( { fetch: fetchSpy, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const select2Calls = installCapturingSelect2Stub( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );

		// Mirrors WooCommerce's own update_checkout: jQuery's cleanData() purges this exact
		// node's select2 instance data WITHOUT ever calling OUR detach() — the node itself
		// survives (this closure still holds it), only the data is gone.
		window.jQuery( api.el ).removeData( 'select2' );

		expect( () => api.detach() ).not.toThrow();
		expect( seenSignal.aborted ).toBe( true );
	} );

	it( 'a second, later request started AFTER an earlier one already settled is unaffected by detach() aborting the (already gone) earlier reference', async () => {
		// Pins that `activeAbort` tracks the CURRENT request, not a stale one — the earlier
		// request's own settling naturally leaves `activeAbort` pointed at whatever request
		// select2 issues next, exactly mirroring its own store-then-abort sequencing.
		const signals = [];
		const fetchSpy = jest.fn()
			.mockImplementationOnce( ( term, opts ) => {
				signals.push( opts.signal );

				return Promise.resolve( [] );
			} )
			.mockImplementationOnce( ( term, opts ) => {
				signals.push( opts.signal );

				return new Promise( () => {} );
			} );
		const options = buildOptions( { fetch: fetchSpy, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const select2Calls = installCapturingSelect2Stub( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Тв' } }, jest.fn(), jest.fn() );
		await Promise.resolve().then( () => Promise.resolve() );

		select2Calls[ 0 ].ajax.transport( { data: { term: 'Твер' } }, jest.fn(), jest.fn() );

		api.detach();

		expect( signals[ 0 ].aborted ).toBe( false );
		expect( signals[ 1 ].aborted ).toBe( true );
	} );
} );

// -----------------------------------------------------------------------
// issue #517 — `onAbandon` (#350's escape hatch for #337's address lock) never fired from
// either select2-backed renderer at all: `location-cascade.js`'s `attachOne()` hands every
// renderer the SAME optional `options.onAbandon` `location-typeahead.js` already calls, but
// this file never called it — a settlement the provider genuinely does not carry had no exit
// from the lock in `ajax-select2`/`related-list` mode. Every positive assertion below is
// paired with the control the operator's own exact condition requires: a completed search
// WITH results, an empty query, a cancelled/superseded request, and a transport error must
// all leave `onAbandon` uncalled.
//
// Round 3 rewrite: the "pick before close" tests now drive `instances[ 0 ].pick(...)`
// (`support/fake-select2.js`), which dispatches the MEASURED rig event order — `change`,
// `select2:closing`, `select2:close`, `select2:select`, in that order — never the
// hand-dispatched "select then close" this suite used before the rig measurement (critic
// MJ-3). Every test that expects the deferred close-flush now `await tick()`s the macrotask
// {@see handleSelect2Close} schedules it on (see that function's own docblock).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #517: onAbandon fires on a completed, non-empty, zero-result search', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	it( 'records a completed, non-empty, zero-result search but does NOT fire onAbandon until select2:close', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Мухосранск' );
		await Promise.resolve().then( () => Promise.resolve() );

		// The completed search happened — but nothing has closed yet, so nothing may fire.
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'fires onAbandon({query, resolved:true}) once select2:close follows a recorded zero-result search', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Мухосранск' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Мухосранск', resolved: true } );
	} );

	// -----------------------------------------------------------------------
	// CRITIC PROBE P2 (round 3, MJ-3) — the corruption round 2's fix actually introduced,
	// promoted to a permanent test. Drives the FAKE's own `pick()`, which dispatches the
	// MEASURED order (`change`, `closing`, `close`, THEN `select` — close arrives BEFORE
	// select, not after). Before the round-3 fix, `handleSelect2Close` fired inline on
	// `select2:close`, i.e. BEFORE `resolveAndSelect()` ever got a chance to clear the
	// candidate — this test is what catches that if it regresses.
	// -----------------------------------------------------------------------

	it( 'PROBE P2: a genuine pick clears the recorded candidate even though select2:close fires BEFORE select2:select (the MEASURED order)', async () => {
		// Isolates MJ-3 from BL-2 (round 3's OWN "clear on found results" fix would otherwise
		// clear the candidate itself, before the pick ever runs, masking the ordering claim
		// under test here). Sequence: an EARLIER search finds "Тверь" (populating dataByKey,
		// never touching the candidate — nothing was pending yet), THEN a LATER search for
		// "Тве" completes with zero results (recording the candidate) — mirroring a customer
		// who saw a result, backspaced to reconsider, found nothing, then clicked the
		// STILL-VISIBLE earlier "Тверь" row rather than a freshly re-searched one. The
		// candidate must survive right up to the pick for this test to actually exercise the
		// ordering guarantee, not BL-2's separate one.
		const fetchSpy = jest.fn()
			.mockImplementationOnce( () => Promise.resolve( [       // "Тверь" — a real match, first
				{ key: 'dadata:tver', value: 'Тверь', level: 'settlement', record: { key: 'dadata:tver', label: 'Тверь' } },
			] ) )
			.mockImplementationOnce( () => Promise.resolve( [] ) ); // "Тве" — zero results, recorded LAST
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const firstQuery = instances[ 0 ].query( 'Тверь' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].query( 'Тве' );
		await Promise.resolve().then( () => Promise.resolve() );

		// The MEASURED pick sequence: change, closing, close, THEN select — never the other
		// way around. `pick()`'s own docblock is explicit that this is not a choice. Picking
		// the EARLIER "Тверь" result (still resolvable via `dataByKey`, per issue #488 slice 3's
		// own re-pick contract) while "Тве" is the currently-pending candidate.
		instances[ 0 ].pick( firstQuery.success.mock.calls[ 0 ][ 0 ].results[ 0 ] );

		// The scheduled flush from `close` (inside `pick()`) would run on the next tick if
		// nothing cancelled it — the claim under test is that the SYNCHRONOUS `select2:select`
		// that `pick()` fires immediately after already cancelled it before this tick runs.
		await tick();

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	// -----------------------------------------------------------------------
	// CRITIC BL-2 (round 3, BLOCKER) — a candidate recorded for an earlier FAILED term must
	// not survive a LATER completed search on the same field that DID find something.
	// -----------------------------------------------------------------------

	it( 'PROBE P1: a zero-result term SUPERSEDED by a later matching search does NOT unlock on close', async () => {
		const fetchSpy = jest.fn()
			.mockImplementationOnce( () => Promise.resolve( [] ) ) // "Тве" — zero, recorded
			.mockImplementationOnce( () => Promise.resolve( [       // "Тверь" — found, on screen
				{ key: 'dadata:tver', value: 'Тверь', level: 'settlement', record: { key: 'dadata:tver', label: 'Тверь' } },
			] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тве' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].query( 'Тверь' );
		await Promise.resolve().then( () => Promise.resolve() );

		// The customer clicks away WITHOUT picking — second thoughts, a phone call.
		instances[ 0 ].close();
		await tick();

		// The stale "Тве" candidate must have been cleared by the "Тверь" search finding
		// something — never fired.
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'PROBE P1 control: a zero-result term with NO later matching search still unlocks on close (the ordinary #350/#517 case, unaffected by the BL-2 fix)', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );
	} );

	// -----------------------------------------------------------------------
	// CRITIC MJ-4 (round 3, MAJOR) — a response landing AFTER the dropdown already closed
	// must fire immediately: no future close is coming to flush it.
	// -----------------------------------------------------------------------

	it( 'PROBE P3: a zero-result response ARRIVING AFTER the dropdown already closed fires immediately', async () => {
		let resolveFetch;
		const fetchSpy = jest.fn( () => new Promise( ( resolve ) => {
			resolveFetch = resolve;
		} ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' ); // the request goes out, dropdown open
		instances[ 0 ].close(); // the customer clicks away BEFORE the response lands

		resolveFetch( [] ); // the response finally arrives — dropdown is already closed
		await Promise.resolve().then( () => Promise.resolve() );

		// No `tick()` — the claim is that this fires the MOMENT the response is recorded,
		// through `recordAbandonCandidate()`'s own immediate-fire branch, not through the
		// scheduled `select2:close` flush (which already ran, before the response existed).
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );
	} );

	it( 'PROBE P3 control: a zero-result response arriving WHILE the dropdown is still open does NOT fire immediately — it waits for close', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' ); // dropdown open, never closed
		await Promise.resolve().then( () => Promise.resolve() );

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'detach() flushes a pending candidate immediately — never silently dropped on teardown (critic MJ-4, second half)', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' ); // dropdown open, response about to land
		await Promise.resolve().then( () => Promise.resolve() );

		expect( onAbandon ).not.toHaveBeenCalled(); // still open, still just recorded

		api.detach(); // WooCommerce tears the widget down before any close ever fires

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );
	} );

	it( 'control: detach() with nothing pending does nothing', () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		installFakeSelect2( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		expect( () => api.detach() ).not.toThrow();
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'a scheduled close-flush does not double-fire when detach() runs before its tick — detach fires it once, immediately, and the pending timer finds nothing left', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		const api = mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].close(); // schedules a flush for the NEXT tick
		api.detach(); // runs synchronously, before that tick — flushes immediately and cancels the timer

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );

		await tick(); // let the (cancelled) timer's slot pass

		expect( onAbandon ).toHaveBeenCalledTimes( 1 ); // still just once
	} );

	it( 'control (critic MN-2): a response whose rows ALL fail applyEntries()\'s filter does NOT fire onAbandon — the provider answered, this layer just could not derive a submittable value', async () => {
		// Every row carries a record and a key but an explicitly EMPTY derived value
		// (fieldValueFor() found no usable component/label) — applyEntries() drops all of them,
		// so accepted.length === 0 while entries.length > 0. That is "we rejected what the
		// provider gave us", never "the provider has nothing for this town".
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:no-value-1', value: '', level: 'settlement', record: { key: 'dadata:no-value-1', label: '' } },
			{ key: 'dadata:no-value-2', value: '', level: 'settlement', record: { key: 'dadata:no-value-2', label: '' } },
		] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const result = instances[ 0 ].query( 'Мухосранск' );
		await Promise.resolve().then( () => Promise.resolve() );

		expect( result.success ).toHaveBeenCalledWith( { results: [] } );

		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'control: select2:close with no recorded candidate at all does nothing', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].open();
		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'control: does NOT fire onAbandon when the completed search accepts at least one entry, even after select2:close', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:zh', value: 'Жуковский', level: 'settlement', record: { key: 'dadata:zh', label: 'Жуковский' } },
		] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Жук' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'control: does NOT fire onAbandon for a query below minimumInputLength — the fake gate returns null and the transport never runs at all', () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		// settlement's own floor is 2 (minimumInputLengthFor()) — a single character never
		// reaches the transport at all, mirroring selectWoo's own MinimumInputLength decorator.
		const result = instances[ 0 ].query( 'М' );

		expect( result ).toBeNull();
		expect( fetchSpy ).not.toHaveBeenCalled();
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'control: does NOT fire onAbandon for a request superseded (aborted) by a newer one, even once the stale request finally settles with zero entries', async () => {
		let resolveFirst;
		const fetchSpy = jest.fn()
			.mockImplementationOnce( () => new Promise( ( resolve ) => {
				resolveFirst = resolve;
			} ) )
			.mockImplementationOnce( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Мух' ); // left pending
		instances[ 0 ].query( 'Мухосранск' ); // supersedes it — the fake's own store-then-abort sequence

		resolveFirst( [] ); // the STALE request settles too, also with zero entries
		await Promise.resolve().then( () => Promise.resolve() ).then( () => Promise.resolve() );

		instances[ 0 ].close();
		await tick();

		// Only the live (second) query's own zero-result completion may report — never a
		// second call from the cancelled first one settling behind it (the stale one, being
		// `stale`-guarded in `selectConfigFor()`'s transport, never even reaches the recorder).
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Мухосранск', resolved: true } );
	} );

	it( 'control: does NOT fire onAbandon when the search rejects with a genuine transport error', async () => {
		// A network failure is logged (`logError()`), so expect it explicitly rather than let
		// @wordpress/jest-console flag an "unexpected" console.error (mirrors
		// location-cascade.test.js's own convention for the same situation).
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		const fetchSpy = jest.fn( () => Promise.reject( new Error( 'network down' ) ) );
		const onAbandon = jest.fn();
		const options = buildOptions( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const result = instances[ 0 ].query( 'Мухосранск' );
		await Promise.resolve().then( () => Promise.resolve() ).then( () => Promise.resolve() );

		// `attachAjaxSelect2()`'s own `fetchEntries()` still renders a genuine (non-abort)
		// fetch failure as an ordinary empty result for the CUSTOMER (success({results: []}),
		// unchanged from before this card — see that function's own docblock) — it resolves
		// with `null` rather than rejecting, specifically so `selectConfigFor()`'s transport can
		// tell "genuinely searched, found nothing" (an array, however empty) apart from "never
		// completed a real search at all" (`null`) for the ONE thing that distinction is for:
		// never treating a swallowed error as the #350/#517 zero-result condition.
		expect( result.success ).toHaveBeenCalledWith( { results: [] } );
		expect( result.failure ).not.toHaveBeenCalled();
		expect( onAbandon ).not.toHaveBeenCalled();

		instances[ 0 ].close();
		await tick();

		// Still nothing to flush — the `null`-entries guard means a transport error is never
		// even recorded as a candidate, so a close afterward cannot resurrect it either.
		expect( onAbandon ).not.toHaveBeenCalled();

		consoleSpy.mockRestore();
	} );

	it( 'never throws when onAbandon is omitted — OPTIONAL, same as every other primitive', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const options = buildOptions( { fetch: fetchSpy, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		delete options.onAbandon;

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		await expect( ( async () => {
			instances[ 0 ].query( 'Мухосранск' );
			await Promise.resolve().then( () => Promise.resolve() );
			instances[ 0 ].close();
			await tick();
		} )() ).resolves.not.toThrow();
	} );
} );

// -----------------------------------------------------------------------
// issue #528 — the merchant opt-in that makes #517 actually deliver in ajax-select2: `tags`
// (gated to the zero-result case via `insertTag`), and a tag pick that unlocks the address
// WITHOUT going through the ordinary record-pick route (`/select`, `entry.records.settlement`).
// -----------------------------------------------------------------------

describe( 'ajax-select2 renderer — issue #528: allow_custom_settlement opt-in', () => {
	let mod;

	beforeEach( () => {
		mod = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
		document.body.innerHTML = '<input type="text" id="shipping_city" name="shipping_city" value="" />';
	} );

	afterEach( () => {
		delete window.jQuery.fn.select2;
	} );

	function buildOptionsOff( overrides ) {
		return buildOptions( Object.assign(
			{ location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: false } },
			overrides
		) );
	}

	// -----------------------------------------------------------------------
	// The gate itself — when the option is OFF, nothing in the #517 mechanism may fire.
	// -----------------------------------------------------------------------

	it( 'option OFF: a completed, non-empty, zero-result search does NOT fire onAbandon, even after select2:close — no candidate recorded, no flush', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptionsOff( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'option OFF: a completed search that DOES find the town still does not fire onAbandon (the clear branch is gated too)', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tver', value: 'Тверь', level: 'settlement', record: { key: 'dadata:tver', label: 'Тверь' } },
		] ) );
		const onAbandon = jest.fn();
		const options = buildOptionsOff( { fetch: fetchSpy, onAbandon, node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тверь' );
		await Promise.resolve().then( () => Promise.resolve() );
		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	it( 'control: option ON behaves exactly like the pre-#528 baseline — a zero-result search fires onAbandon on close', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( {
			fetch: fetchSpy,
			onAbandon,
			node: { level: 'settlement', fieldId: 'shipping_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
		} );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' );
		await Promise.resolve().then( () => Promise.resolve() );
		instances[ 0 ].close();
		await tick();

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );
	} );

	// -----------------------------------------------------------------------
	// The select2 config itself — `tags` wired only when the option is on.
	// -----------------------------------------------------------------------

	it( 'option OFF: select2 receives NO tags/createTag/insertTag config at all', () => {
		const options = buildOptionsOff( { node: { level: 'settlement', fieldId: 'shipping_city' } } );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		expect( instances[ 0 ].config.tags ).toBeFalsy();
		expect( instances[ 0 ].config.createTag ).toBeUndefined();
		expect( instances[ 0 ].config.insertTag ).toBeUndefined();
	} );

	it( 'option ON: select2 receives tags: true plus createTag/insertTag', () => {
		const options = buildOptions( {
			node: { level: 'settlement', fieldId: 'shipping_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
		} );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		expect( instances[ 0 ].config.tags ).toBe( true );
		expect( typeof instances[ 0 ].config.createTag ).toBe( 'function' );
		expect( typeof instances[ 0 ].config.insertTag ).toBe( 'function' );
	} );

	// -----------------------------------------------------------------------
	// A tag pick — NOT a record pick: no /select (onSelect), no key to resolve, but the
	// #350/#517 unresolved marker still gets written via onAbandon so the address unlocks.
	// -----------------------------------------------------------------------

	it( 'a tag pick fires onAbandon (the unresolved marker) but NEVER onSelect — no /select, no record write', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( {
			fetch: fetchSpy,
			onAbandon,
			node: { level: 'settlement', fieldId: 'shipping_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
		} );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тьмутаракань' ); // the zero-result search that made the tag row appear
		await Promise.resolve().then( () => Promise.resolve() );

		expect( onAbandon ).not.toHaveBeenCalled(); // still open — nothing may fire yet

		// The customer picks the tag row itself — select2's own `createTag()` shape, no `key`.
		instances[ 0 ].pick( { id: 'Тьмутаракань', text: 'Тьмутаракань', newTag: true } );

		expect( options.onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );

		// Composes with the deferred flush `select2:close` already scheduled (measured order:
		// close arrives BEFORE select) — it must not double-fire on the next tick.
		await tick();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a tag pick fires with the PICKED term, not a stale earlier-recorded one — proves the tag branch overwrites/fires the candidate itself, not merely riding the ordinary deferred flush', async () => {
		// Deliberately picks a DIFFERENT term than the last completed search recorded — a
		// version that fell through to `resolveAndSelect()`'s no-op (mutant: the `newTag`
		// branch removed) would still eventually fire via the ordinary `select2:close` flush,
		// but with the STALE "Тве" term, not "Тверь" — asserting the query value, synchronously
		// before that flush's own macrotask could run, is what makes this catch that mutant.
		const fetchSpy = jest.fn( () => Promise.resolve( [] ) ); // "Тве" — zero, recorded
		const onAbandon = jest.fn();
		const options = buildOptions( {
			fetch: fetchSpy,
			onAbandon,
			node: { level: 'settlement', fieldId: 'shipping_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
		} );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		instances[ 0 ].query( 'Тве' );
		await Promise.resolve().then( () => Promise.resolve() );

		// The customer keeps typing past "Тве" to "Тверь" and picks the (still zero-result)
		// tag row for the CURRENT text — without a second completed search ever recording it.
		instances[ 0 ].pick( { id: 'Тверь', text: 'Тверь', newTag: true } );

		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тверь', resolved: true } );

		// Composes with the already-scheduled close-flush — must not double-fire on its tick.
		await tick();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a REAL record pick (not a tag) is unaffected — resolveAndSelect() still runs and onAbandon is never invoked for it', async () => {
		const fetchSpy = jest.fn( () => Promise.resolve( [
			{ key: 'dadata:tver', value: 'Тверь', level: 'settlement', record: { key: 'dadata:tver', label: 'Тверь' } },
		] ) );
		const onAbandon = jest.fn();
		const options = buildOptions( {
			fetch: fetchSpy,
			onAbandon,
			node: { level: 'settlement', fieldId: 'shipping_city' },
			location: { endpoints: { list: LIST_URL }, mode: 'related-list', allowCustomSettlement: true },
		} );

		const instances = installFakeSelect2( window.jQuery );

		mod.attachAjaxSelect2( document.getElementById( 'shipping_city' ), options );

		const query = instances[ 0 ].query( 'Тверь' );
		await Promise.resolve().then( () => Promise.resolve() );

		instances[ 0 ].pick( query.success.mock.calls[ 0 ][ 0 ].results[ 0 ] );
		await tick();

		expect( options.onSelect ).toHaveBeenCalledTimes( 1 );
		expect( options.onSelect ).toHaveBeenCalledWith( { record: { key: 'dadata:tver', label: 'Тверь' } } );
		expect( onAbandon ).not.toHaveBeenCalled();
	} );
} );

