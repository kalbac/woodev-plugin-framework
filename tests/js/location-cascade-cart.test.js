/**
 * Tests for location-cascade.js on the CLASSIC CART's shipping calculator (issue #331).
 *
 * The calculator renders `calc_shipping_*` ids and has no "ship to a different address"
 * toggle; the config is marked `context: 'cart'`. The checkout path is covered by
 * `location-cascade.test.js` and must stay untouched.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js
 */

'use strict';

const CONFIG_GLOBAL = 'woodev_checkout_field_config_cart_test';
const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';

let attachCalls;
let fetchCalls;

const KZ = {
	key: 'test-cdek:44', provider_id: 'test-cdek', level: 'settlement', country: 'RU',
	settlement: { name: 'Казань', type: 'г' }, label: 'Казань, Татарстан', ancestors: [],
};

async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

function field( level ) {
	return {
		id: null, type: 'text', section: 'shipping', source_kind: 'location', location_level: level,
		depends_on: null, required: false, is_pickup_slot: false,
	};
}

function installCalculatorMarkup( country ) {
	document.body.innerHTML = `
		<form class="woocommerce-shipping-calculator">
			<select id="calc_shipping_country" name="calc_shipping_country">
				<option value="default">Select a country…</option>
				<option value="RU">Россия</option>
			</select>
			<input type="text" id="calc_shipping_state" name="calc_shipping_state" value="" />
			<input type="text" id="calc_shipping_city" name="calc_shipping_city" value="" />
			<input type="text" id="calc_shipping_postcode" name="calc_shipping_postcode" value="" />
		</form>
	`;
	document.getElementById( 'calc_shipping_country' ).value = country;
}

function boot( { country = 'RU', location = {}, fields } = {} ) {
	installCalculatorMarkup( country );

	global.jQuery = require( 'jquery' );
	window.jQuery = global.jQuery;
	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );

	attachCalls = [];
	window.WoodevLocationTypeahead = jest.fn( ( el, opts ) => {
		attachCalls.push( { el, fetch: opts.fetch, onSelect: opts.onSelect, opts } );

		return { detach: jest.fn() };
	} );

	fetchCalls = [];
	global.fetch = jest.fn( ( url, init ) => {
		const entry = { url, init };

		entry.promise = new Promise( ( resolve ) => {
			entry.resolve = ( body ) => resolve( { ok: true, json: () => Promise.resolve( body ) } );
		} );
		fetchCalls.push( entry );

		return entry.promise;
	} );

	window[ CONFIG_GLOBAL ] = {
		context: 'cart',
		fields: fields || {
			calc_shipping_state: field( 'region' ),
			calc_shipping_city: field( 'settlement' ),
		},
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'n',
		takeover: {},
		pickup_method_ids: [],
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: 'x', forget: 'y' },
			nonce: 'n',
			countries: [ 'RU' ],
			mode: { region: 'typeahead', settlement: 'typeahead' },
			allowCustomSettlement: false,
			levels: { RU: { region: true, settlement: true, address: false } },
			popular: { RU: [] },
			current: null,
			implicit: false,
			defaultCountry: 'RU',
			i18n: {},
			...location,
		},
	};

	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

function callFor( id ) {
	return attachCalls.find( ( c ) => c.el.id === id );
}

beforeEach( () => {
	jest.resetModules();
	document.body.replaceWith( document.createElement( 'body' ) );
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevCheckoutFieldStore;
	delete window.WoodevLocationTypeahead;
	delete window.WoodevLocationRenderers;
	delete window.jQuery;
	delete global.jQuery;
	delete global.fetch;
} );

describe( 'cart calculator boot path (issue #331)', () => {
	it( 'finds the fields by the calculator ids and attaches region + settlement', () => {
		boot();

		expect( attachCalls.map( ( c ) => c.el.id ).sort() ).toEqual( [ 'calc_shipping_city', 'calc_shipping_state' ] );
	} );

	it( 'attaches with no "ship to a different address" toggle present', () => {
		boot();

		expect( document.querySelector( '[name="ship_to_different_address"]' ) ).toBeNull();
		expect( callFor( 'calc_shipping_city' ) ).toBeDefined();
	} );

	it( 'treats the «Select a country…» placeholder as the default country, not as a country code', () => {
		boot( { country: 'default' } );

		expect( callFor( 'calc_shipping_city' ) ).toBeDefined();
		callFor( 'calc_shipping_city' ).fetch( 'Каз' );
		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( 'country=RU' );
	} );

	it( 'renders no pickup button or modal', () => {
		boot();

		expect( document.querySelector( '[class*="pickup"], [id*="pickup"]' ) ).toBeNull();
		expect( window.WoodevPickup ).toBeUndefined();
	} );

	it( 'persists a pick at pick time through /select (the store the checkout reads)', () => {
		boot();

		const call = callFor( 'calc_shipping_city' );
		call.el.value = 'Казань';
		call.onSelect( { key: KZ.key, label: KZ.label, level: 'settlement', record: KZ } );

		const select = fetchCalls.find( ( c ) => c.url === SELECT_URL );
		expect( select ).toBeDefined();
		expect( JSON.parse( select.init.body ).record.key ).toBe( KZ.key );
	} );

	it( 'does not scope the settlement search by a region record when the region field is removed (#1069)', () => {
		boot( { location: { regionFieldRemoved: true }, fields: { calc_shipping_state: field( 'region' ), calc_shipping_city: field( 'settlement' ) } } );

		callFor( 'calc_shipping_state' ).onSelect( {
			key: 'dadata:region1', label: 'г Москва', level: 'region',
			record: { key: 'dadata:region1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Москва', type: 'г' }, label: 'г Москва' },
		} );
		callFor( 'calc_shipping_city' ).fetch( 'Каз' );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).not.toContain( 'within=' );
	} );

	it( 'scopes the settlement search by a picked region when the region field is shown (#551/#552)', () => {
		boot();

		callFor( 'calc_shipping_state' ).onSelect( {
			key: 'dadata:region1', label: 'г Москва', level: 'region',
			record: { key: 'dadata:region1', provider_id: 'dadata', level: 'region', country: 'RU', region: { name: 'Москва', type: 'г' }, label: 'г Москва' },
		} );
		callFor( 'calc_shipping_city' ).fetch( 'Каз' );

		expect( fetchCalls[ fetchCalls.length - 1 ].url ).toContain( 'within=' + encodeURIComponent( 'dadata:region1' ) );
	} );

	it( 'restores the customer pick after the calculator reloads: popular list is off once a pick exists (#1071)', () => {
		const calls = [];

		window.WoodevLocationRenderers = {
			'custom-mode:settlement': ( el, options ) => {
				calls.push( options );

				return { detach: jest.fn() };
			},
			'custom-mode:region': () => ( { detach: jest.fn() } ),
		};

		boot( {
			location: {
				mode: { region: 'custom-mode', settlement: 'custom-mode' },
				current: { key: KZ.key, level: 'settlement' },
				chain: { settlement: { key: KZ.key, level: 'settlement' } },
			},
		} );

		expect( calls.find( ( o ) => o.popular ).pickedSettlement() ).toEqual( { name: '' } );
	} );

	it( 're-binds to the NEW calculator fields after WooCommerce replaces the cart HTML (updated_wc_div)', () => {
		boot();

		const oldCity = document.getElementById( 'calc_shipping_city' );
		const firstAttach = callFor( 'calc_shipping_city' );

		// cart.js swaps in server markup: a fresh plain input carrying the saved value.
		document.querySelector( 'form' ).outerHTML = `
			<form class="woocommerce-shipping-calculator">
				<select id="calc_shipping_country" name="calc_shipping_country"><option value="RU" selected>Россия</option></select>
				<input type="text" id="calc_shipping_state" name="calc_shipping_state" value="" />
				<input type="text" id="calc_shipping_city" name="calc_shipping_city" value="Внуково" />
				<input type="text" id="calc_shipping_postcode" name="calc_shipping_postcode" value="" />
			</form>`;

		const before = attachCalls.length;
		window.jQuery( document.body ).trigger( 'updated_wc_div' );

		const fresh = document.getElementById( 'calc_shipping_city' );
		expect( fresh ).not.toBe( oldCity );
		expect( firstAttach.el ).toBe( oldCity );
		expect( attachCalls.length ).toBeGreaterThan( before );
		expect( attachCalls.slice( before ).some( ( c ) => c.el === fresh ) ).toBe( true );
		expect( fresh.value ).toBe( 'Внуково' );
	} );

	it( 'restores the picked text into a replaced field that came back empty', () => {
		boot();

		const call = callFor( 'calc_shipping_city' );
		call.el.value = 'Внуково';
		call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		call.onSelect( { key: KZ.key, label: 'Внуково', level: 'settlement', record: KZ } );

		document.querySelector( 'form' ).outerHTML = `
			<form class="woocommerce-shipping-calculator">
				<select id="calc_shipping_country" name="calc_shipping_country"><option value="RU" selected>Россия</option></select>
				<input type="text" id="calc_shipping_state" name="calc_shipping_state" value="" />
				<input type="text" id="calc_shipping_city" name="calc_shipping_city" value="" />
			</form>`;
		window.jQuery( document.body ).trigger( 'updated_cart_totals' );

		expect( document.getElementById( 'calc_shipping_city' ).value ).toBe( 'Внуково' );
	} );
} );
