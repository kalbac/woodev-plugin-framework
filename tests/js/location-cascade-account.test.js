/**
 * Tests for location-cascade.js on the classic My Account address forms (issue #332):
 * `/my-account/edit-address/billing/` and `/shipping/`.
 *
 * The forms render WooCommerce's own `billing_*` / `shipping_*` ids, edit ONE section per page, have
 * no "ship to a different address" toggle and save by a plain POST; the config is marked
 * `context: 'account'` with `accountSection`. The checkout and cart paths are covered by
 * `location-cascade.test.js` / `location-cascade-cart.test.js` and must stay untouched.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js
 */

'use strict';

const CONFIG_GLOBAL = 'woodev_checkout_field_config_account_test';
const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';
const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';

const MOSCOW = {
	key: 'dadata:r77', provider_id: 'dadata', level: 'region', country: 'RU',
	region: { name: 'Москва', type: 'г' }, label: 'Москва', ancestors: [],
};
const VNUKOVO = {
	key: 'dadata:vn', provider_id: 'dadata', level: 'settlement', country: 'RU',
	settlement: { name: 'Внуково', type: 'п' }, region: { name: 'Москва', type: 'г' },
	label: 'Внуково, Москва', ancestors: [ 'dadata:r77' ],
};

let fetchCalls;

async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

function field( section, level ) {
	return {
		id: null, type: 'text', section, source_kind: 'location', location_level: level,
		depends_on: null, required: false, is_pickup_slot: false,
	};
}

/** The markup `woocommerce_form_field()` renders on the edit-address form. */
function installAccountMarkup( section, savedCity ) {
	document.body.innerHTML = `
		<form method="post" novalidate>
			<select id="${ section }_country" name="${ section }_country">
				<option value="RU">Россия</option>
				<option value="US">США</option>
			</select>
			<select id="${ section }_state" name="${ section }_state">
				<option value="">Выберите…</option>
				<option value="MOW">Москва</option>
				<option value="SPE">Санкт-Петербург</option>
			</select>
			<input type="text" id="${ section }_city" name="${ section }_city" value="${ savedCity }" />
			<input type="text" id="${ section }_postcode" name="${ section }_postcode" value="" />
		</form>
	`;
	document.getElementById( section + '_state' ).value = 'MOW';
}

function boot( section, { savedCity = '', location = {}, configs = 1 } = {} ) {
	installAccountMarkup( section, savedCity );

	global.jQuery = require( 'jquery' );
	window.jQuery = global.jQuery;
	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );
	require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

	window.WoodevLocationTypeahead = jest.fn( () => ( { detach: jest.fn() } ) );

	fetchCalls = [];
	global.fetch = jest.fn( ( url, init ) => {
		const entry = { url, init };

		entry.promise = new Promise( ( resolve ) => {
			entry.resolve = ( body ) => resolve( { ok: true, json: () => Promise.resolve( body ) } );
		} );
		fetchCalls.push( entry );

		if ( String( url ).includes( '/location/list' ) ) {
			entry.resolve( { localities: [ { key: MOSCOW.key, label: 'Москва', level: 'region', record: MOSCOW } ] } );
		}

		return entry.promise;
	} );

	const config = {
		context: 'account',
		accountSection: section,
		fields: {
			[ section + '_state' ]: field( section, 'region' ),
			[ section + '_city' ]: field( section, 'settlement' ),
		},
		endpoint: 'x', nonce: 'n', takeover: {}, pickup_method_ids: [],
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL, forget: 'y' },
			nonce: 'n', countries: [ 'RU' ],
			mode: { region: 'related-list', settlement: 'typeahead' },
			allowCustomSettlement: false,
			levels: { RU: { region: false, settlement: true, address: false } },
			popular: { RU: [] },
			current: null, chain: {}, implicit: false, defaultLocality: null, defaultCountry: 'RU', i18n: {},
			...location,
		},
	};

	// A second plugin on the same form: another config global with the same field ids.
	for ( let i = 0; i < configs; i++ ) {
		window[ 0 === i ? CONFIG_GLOBAL : CONFIG_GLOBAL + '_' + i ] = JSON.parse( JSON.stringify( config ) );
	}

	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

const posts = () => fetchCalls.filter( ( c ) => 'POST' === ( c.init && c.init.method ) );
const selectPosts = () => fetchCalls.filter( ( c ) => SELECT_URL === c.url );

function cityCall( section ) {
	const city = document.getElementById( section + '_city' );

	return window.WoodevLocationTypeahead.mock.calls.find( ( c ) => c[ 0 ] === city );
}

/** What WooCommerce's country-select.js does on `change` of the country field. */
function wcRebuildState( section ) {
	const $ = window.jQuery;
	const $state = $( '#' + section + '_state' );
	const value = $state.val();

	$state.empty().append( '<option value="">Выберите…</option>' )
		.append( '<option value="MOW">Москва</option>' )
		.append( '<option value="SPE">Санкт-Петербург</option>' );
	$state.val( value ).trigger( 'change' );
	$( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );
}

beforeEach( () => {
	jest.resetModules();
	document.body.replaceWith( document.createElement( 'body' ) );
	delete window[ CONFIG_GLOBAL ];
	delete window[ CONFIG_GLOBAL + '_1' ];
	delete window.WoodevCheckoutFieldStore;
	delete window.WoodevLocationTypeahead;
	delete window.WoodevLocationRenderers;
	delete window.jQuery;
	delete global.jQuery;
	delete global.fetch;
} );

describe.each( [ 'billing', 'shipping' ] )( 'My Account %s address form (issue #332)', ( section ) => {
	it( 'attaches the settlement suggestions to the form\'s own field with no ship-to-different toggle', () => {
		boot( section );

		expect( document.querySelector( '[name="ship_to_different_address"]' ) ).toBeNull();
		expect( cityCall( section ) ).toBeDefined();
	} );

	it( 'scopes the settlement search by the form\'s own country', () => {
		boot( section );

		cityCall( section )[ 1 ].fetch( 'Вну' );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( 'country=RU' );
	} );

	it( 'renders no pickup button or modal', () => {
		boot( section );

		expect( document.querySelector( '[class*="pickup"], [id*="pickup"]' ) ).toBeNull();
		expect( window.WoodevPickup ).toBeUndefined();
	} );

	it( 'posts no /select on page load, even when WooCommerce re-fires change on the state select', async () => {
		boot( section, {
			savedCity: 'Внуково',
			location: {
				current: { key: VNUKOVO.key, level: 'settlement' },
				chain: { settlement: { key: VNUKOVO.key, level: 'settlement' }, region: { key: MOSCOW.key, level: 'region' } },
			},
		} );

		wcRebuildState( section );
		await flushMicrotasks();

		expect( selectPosts() ).toHaveLength( 0 );
		expect( document.getElementById( section + '_city' ).value ).toBe( 'Внуково' );
	} );

	it( 'writes nothing to the store on a settlement pick — only «Save» persists (operator, s149)', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		expect( selectPosts() ).toHaveLength( 0 );
		expect( city.value ).toBe( 'Внуково' );
	} );

	it( 'carries the picked settlement record in a hidden form field and still posts nothing (#332)', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		const hidden = city.form.querySelector( '[name="woodev_location_record"]' );

		expect( hidden ).not.toBeNull();
		expect( hidden.type ).toBe( 'hidden' );
		expect( JSON.parse( hidden.value ) ).toEqual( VNUKOVO );
		expect( fetchCalls.filter( ( c ) => 'POST' === ( c.init && c.init.method ) ) ).toHaveLength( 0 );
	} );

	it( 'empties the hidden field when the customer edits the city after a pick', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		city.value = 'Внуково-2';
		city.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await flushMicrotasks();

		expect( city.form.querySelector( '[name="woodev_location_record"]' ).value ).toBe( '' );
	} );

	it( 'empties the hidden field when a real region change clears the city', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		const state = document.getElementById( section + '_state' );

		state.value = 'SPE';
		window.jQuery( state ).trigger( 'change' );
		await flushMicrotasks();

		expect( city.form.querySelector( '[name="woodev_location_record"]' ).value ).toBe( '' );
	} );

	it( 'adds no hidden field before a pick and keeps it on a same-value state churn', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		expect( city.form.querySelector( '[name="woodev_location_record"]' ) ).toBeNull();

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		wcRebuildState( section );
		await flushMicrotasks();

		expect( JSON.parse( city.form.querySelector( '[name="woodev_location_record"]' ).value ).key ).toBe( VNUKOVO.key );
	} );

	it( 'posts nothing on a country change — «Forget» waits for Save (#332)', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		const country = document.getElementById( section + '_country' );

		country.value = 'US';
		window.jQuery( country ).trigger( 'change' );
		await flushMicrotasks();

		expect( city.value ).toBe( '' );
		expect( city.form.querySelector( '[name="woodev_location_record"]' ).value ).toBe( '' );
		expect( posts() ).toHaveLength( 0 );
	} );

	it( 'writes nothing to the store on a region pick, and a second pick does not queue a request', async () => {
		boot( section );

		const state = document.getElementById( section + '_state' );

		state.value = 'SPE';
		window.jQuery( state ).trigger( 'change' );
		await flushMicrotasks();

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		expect( selectPosts() ).toHaveLength( 0 );
	} );

	it( 'keeps the picked city and posts no /select on a same-value state churn', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();
		const postsAfterPick = selectPosts().length;

		wcRebuildState( section );
		await flushMicrotasks();

		expect( city.value ).toBe( 'Внуково' );
		expect( selectPosts() ).toHaveLength( postsAfterPick );
	} );

	it( 'clears the city on a real region change', async () => {
		boot( section );

		const city = document.getElementById( section + '_city' );

		city.value = 'Внуково';
		cityCall( section )[ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		const state = document.getElementById( section + '_state' );
		state.value = 'SPE';
		window.jQuery( state ).trigger( 'change' );
		await flushMicrotasks();

		expect( city.value ).toBe( '' );
	} );

	it( 'degrades to the saved plain text when no record is served (provider changed, #333)', async () => {
		boot( section, { savedCity: 'Тверь' } ); // `current: null` — the server dropped the unservable record

		wcRebuildState( section );
		await flushMicrotasks();

		expect( document.getElementById( section + '_city' ).value ).toBe( 'Тверь' );
		expect( document.getElementById( section + '_state' ).value ).toBe( 'MOW' );
		expect( selectPosts() ).toHaveLength( 0 );
	} );
} );

describe( 'My Account address form with two plugin entries on one form (issue #332)', () => {
	const pickThroughFirst = async ( section ) => {
		boot( section, { configs: 2 } );

		const city = document.getElementById( section + '_city' );
		const calls = window.WoodevLocationTypeahead.mock.calls.filter( ( c ) => c[ 0 ] === city );

		expect( calls ).toHaveLength( 2 );

		city.value = 'Внуково';
		calls[ 0 ][ 1 ].onSelect( { key: VNUKOVO.key, label: VNUKOVO.label, level: 'settlement', record: VNUKOVO } );
		await flushMicrotasks();

		return { city, hidden: () => city.form.querySelector( '[name="woodev_location_record"]' ) };
	};

	it( 'keeps the first entry\'s record when an unrelated postcode field changes', async () => {
		const { city, hidden } = await pickThroughFirst( 'billing' );
		const postcode = document.getElementById( 'billing_postcode' );

		postcode.value = '108800';
		postcode.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await flushMicrotasks();

		expect( JSON.parse( hidden().value ).key ).toBe( VNUKOVO.key );
		expect( city.value ).toBe( 'Внуково' );
	} );

	it( 'keeps the first entry\'s record on a same-value state churn', async () => {
		const { hidden } = await pickThroughFirst( 'shipping' );

		wcRebuildState( 'shipping' );
		await flushMicrotasks();

		expect( JSON.parse( hidden().value ).key ).toBe( VNUKOVO.key );
	} );

	it( 'empties the record when the city is edited through the entry that picked it', async () => {
		const { city, hidden } = await pickThroughFirst( 'billing' );

		city.value = 'Внуково-2';
		city.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await flushMicrotasks();

		expect( hidden().value ).toBe( '' );
	} );

	it( 'lets the entry that picked LAST own the record', async () => {
		const { city, hidden } = await pickThroughFirst( 'billing' );
		const second = window.WoodevLocationTypeahead.mock.calls.filter( ( c ) => c[ 0 ] === city )[ 1 ];
		const other = { ...VNUKOVO, key: 'dadata:vn2', label: 'Внуково-2, Москва' };

		second[ 1 ].onSelect( { key: other.key, label: other.label, level: 'settlement', record: other } );
		await flushMicrotasks();

		expect( JSON.parse( hidden().value ).key ).toBe( 'dadata:vn2' );

		const postcode = document.getElementById( 'billing_postcode' );

		postcode.value = '108800';
		postcode.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await flushMicrotasks();

		expect( JSON.parse( hidden().value ).key ).toBe( 'dadata:vn2' );
	} );
} );
