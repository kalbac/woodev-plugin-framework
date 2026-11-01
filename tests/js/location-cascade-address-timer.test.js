/**
 * Tests for location-cascade.js — no checkout reload while the buyer types «Адрес» (issue #1542).
 *
 * WooCommerce's `checkout.js` binds a delegated `keydown` on `.address-field input.input-text` that
 * calls `update_checkout` about a second after a keystroke. The cascade takes `address-field` off
 * the address row while the input has the focus and puts it back on a pick and on blur. These tests
 * drive the REAL `location-typeahead.js`, the real store and the real cascade, and stand in for
 * WooCommerce with the same delegated bindings.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js — createAddressTimerGuard()
 * @see tests/js/location-cascade-shared.test.js — the harness this file follows
 */

'use strict';

const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';
const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';
const FORGET_URL = 'https://example.test/wp-json/woodev/v1/location/forget';
const PREFIX = 'woodev_checkout_field_config_';

const KAZAN = {
	key: 'dadata:kazan',
	label: 'г Казань',
	level: 'settlement',
	record: {
		key: 'dadata:kazan',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		label: 'г Казань',
		region: { type: 'Респ', name: 'Татарстан' },
		settlement: { type: 'г', name: 'Казань' },
	},
};

const BAUMANA = {
	key: 'dadata:baumana-1',
	label: 'ул Баумана, д 1',
	level: 'address',
	record: {
		key: 'dadata:baumana-1',
		provider_id: 'dadata',
		level: 'address',
		country: 'RU',
		label: 'ул Баумана, д 1',
		region: { type: 'Респ', name: 'Татарстан' },
		settlement: { type: 'г', name: 'Казань' },
		street: { type: 'ул', name: 'Баумана' },
		house: '1',
	},
};

let fetchCalls;
let registeredGlobals;
let updates;
let wooKeydowns;
let wooChanges;

async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

function configFor( levels, section ) {
	const ids = { region: '_state', settlement: '_city', address: '_address_1' };
	const fields = {};

	levels.forEach( ( level ) => {
		fields[ section + ids[ level ] ] = {
			id: null,
			type: 'text',
			section,
			source_kind: 'location',
			location_level: level,
			depends_on: null,
			required: false,
			is_pickup_slot: false,
		};
	} );

	return {
		fields,
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'test-nonce',
		takeover: {},
		block_place_order: true,
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL, forget: FORGET_URL },
			nonce: 'test-nonce',
			countries: [ 'RU' ],
			mode: { region: 'typeahead', settlement: 'typeahead' },
			levels: { RU: { region: true, settlement: true, address: true } },
			current: null,
			chain: {},
			implicit: false,
			defaultCountry: 'RU',
			i18n: { noResults: 'Ничего не найдено', noResultsAddress: 'Адрес не найден' },
		},
	};
}

function fieldRows( section ) {
	return `
		<select id="${ section }_country" name="${ section }_country"><option value="RU">Россия</option></select>
		<p class="form-row address-field" id="${ section }_state_field">
			<input type="text" class="input-text" id="${ section }_state" name="${ section }_state" value="" />
		</p>
		<p class="form-row address-field" id="${ section }_city_field">
			<input type="text" class="input-text" id="${ section }_city" name="${ section }_city" value="" />
		</p>
		<p class="form-row address-field validate-required" id="${ section }_address_1_field">
			<input type="text" class="input-text" id="${ section }_address_1" name="${ section }_address_1" value="" />
		</p>
	`;
}

/**
 * @param {string}  formClass  `checkout` for the classic checkout; anything else is "not a checkout form".
 * @param {string}  [section]
 */
function boot( formClass = 'checkout woocommerce-checkout', section = 'billing' ) {
	const toggle = 'shipping' === section
		? '<input type="checkbox" id="ship-to-different-address-checkbox" name="ship_to_different_address" value="1" checked />'
		: '';

	document.body.innerHTML = `<form class="${ formClass }">${ toggle }${ fieldRows( section ) }</form>`;
	document.getElementById( section + '_country' ).value = 'RU';

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;

	// WooCommerce's own delegated bindings (checkout.js 206–212), counted instead of queued.
	wooKeydowns = jest.fn();
	wooChanges = jest.fn();
	updates = jest.fn();
	window.jQuery( 'form' ).on( 'keydown', '.address-field input.input-text', wooKeydowns );
	window.jQuery( 'form' ).on( 'change', '.address-field input.input-text', wooChanges );
	window.jQuery( document.body ).on( 'update_checkout', updates );

	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );
	require( '../../woodev/shipping-method/assets/js/frontend/location-typeahead.js' );

	fetchCalls = [];
	global.fetch = jest.fn( ( url, init ) => {
		const call = { url, init };

		call.promise = new Promise( ( resolve, reject ) => {
			call.resolve = ( body, ok = true ) => resolve( { ok, json: () => Promise.resolve( body ) } );
			call.reject = reject;
		} );
		fetchCalls.push( call );

		return call.promise;
	} );

	registeredGlobals = [ PREFIX + 'carrier' ];
	window[ registeredGlobals[ 0 ] ] = configFor( [ 'region', 'settlement', 'address' ], section );

	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

function el( id ) {
	return document.getElementById( id );
}

function row( id ) {
	return el( id + '_field' );
}

function keydown( input ) {
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'a', bubbles: true, cancelable: true } ) );
}

function type( input, text ) {
	input.value = text;
	keydown( input );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

function blur( input ) {
	// jsdom fires `change` for a programmatic blur only when asked; a real browser does it first.
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	input.blur();
}

async function answerSuggest( items ) {
	jest.advanceTimersByTime( 250 ); // the widget's debounce

	const suggest = fetchCalls.filter( ( c ) => c.url.indexOf( SUGGEST_URL ) === 0 ).pop();

	expect( suggest ).toBeDefined();
	suggest.resolve( { suggestions: items } );
	await flushMicrotasks();
}

async function confirmSelect( key, level ) {
	const select = fetchCalls.filter( ( c ) => c.url === SELECT_URL ).pop();

	expect( select ).toBeDefined();
	// A house keeps the settlement under it (#1534) — a chain without it would re-lock the address.
	const chain = { settlement: { key: KAZAN.key, level: 'settlement' }, [ level ]: { key, level } };

	select.resolve( { current: { key, level }, persisted: true, chain } );
	await flushMicrotasks();
}

async function pickKazan( section = 'billing' ) {
	const input = el( section + '_city' );

	input.focus();
	type( input, 'Каз' );
	await answerSuggest( [ KAZAN ] );
	document.querySelector( '.woodev-location-listbox li' ).dispatchEvent(
		new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } )
	);
	await confirmSelect( KAZAN.key, 'settlement' );
	input.blur();
	updates.mockClear();
	wooChanges.mockClear();
	wooKeydowns.mockClear();
}

/**
 * Types into «Адрес» and clicks the first suggestion (the listbox of the address field).
 */
async function pickBaumana( section = 'billing' ) {
	const input = el( section + '_address_1' );

	type( input, 'Баумана 1' );
	await answerSuggest( [ BAUMANA ] );

	const listbox = input.parentNode.querySelector( '.woodev-location-listbox li' )
		|| document.querySelectorAll( '.woodev-location-listbox' )[ 2 ].querySelector( 'li' );

	listbox.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
}

beforeEach( () => {
	jest.resetModules();
	jest.useFakeTimers();

	const fresh = document.createElement( 'body' );
	document.documentElement.replaceChild( fresh, document.body );
} );

afterEach( () => {
	jest.useRealTimers();
	( registeredGlobals || [] ).forEach( ( name ) => {
		delete window[ name ];
	} );
	delete window.WoodevCheckoutFieldStore;
	delete window.WoodevLocationTypeahead;
	delete global.fetch;
} );

describe( 'issue #1542: the classic checkout «Адрес» row while the buyer types', () => {
	it( 'takes the row out of WooCommerce\'s keydown trigger on focus and puts it back on blur', async () => {
		boot();
		await pickKazan();

		const input = el( 'billing_address_1' );

		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );

		input.focus();

		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( false );
		// Only the address row — the validation class and the other fields' rows are untouched.
		expect( row( 'billing_address_1' ).classList.contains( 'validate-required' ) ).toBe( true );
		expect( row( 'billing_city' ).classList.contains( 'address-field' ) ).toBe( true );

		type( input, 'Баумана' );
		type( input, 'Баумана 1' );

		expect( wooKeydowns ).not.toHaveBeenCalled();

		input.blur();

		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );
	} );

	it( 'recalculates exactly once on blur after manual entry without a suggestion', async () => {
		boot();
		await pickKazan();

		const input = el( 'billing_address_1' );

		input.focus();
		type( input, 'ул Баумана, 1' );
		blur( input );

		expect( updates ).toHaveBeenCalledTimes( 1 );
		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( 1 ); // Kazan only
	} );

	it( 'does not recalculate on blur when the value did not change', async () => {
		boot();
		await pickKazan();

		const input = el( 'billing_address_1' );

		input.focus();
		keydown( input ); // a key that left the text as it was (an arrow, Shift)
		blur( input );

		expect( updates ).not.toHaveBeenCalled();
		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );
	} );

	it( 'a pick posts /select before the recalculation, restores the class, and the blur adds nothing', async () => {
		boot();
		await pickKazan();

		const input = el( 'billing_address_1' );

		input.focus();
		await pickBaumana();

		// The pick wrote the field and dispatched its native `change` while the row was still out of
		// WooCommerce's bindings, so WooCommerce did not recalculate ahead of `/select`.
		expect( wooChanges ).not.toHaveBeenCalled();
		expect( updates ).not.toHaveBeenCalled();
		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );

		const selects = fetchCalls.filter( ( c ) => c.url === SELECT_URL );

		expect( selects ).toHaveLength( 2 );
		expect( JSON.parse( selects[ 1 ].init.body ).record.key ).toBe( BAUMANA.key );

		await confirmSelect( BAUMANA.key, 'address' );

		expect( updates ).toHaveBeenCalledTimes( 1 ); // the framework's own, after /select

		blur( input );

		expect( updates ).toHaveBeenCalledTimes( 1 ); // the value is the picked one — nothing new
	} );

	it( 'typing again after a pick takes the row out again, and the blur recalculates once', async () => {
		boot();
		await pickKazan();

		const input = el( 'billing_address_1' );

		input.focus();
		await pickBaumana();
		await confirmSelect( BAUMANA.key, 'address' );
		updates.mockClear();

		type( input, 'ул Баумана, 12' );

		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( false );
		expect( wooKeydowns ).not.toHaveBeenCalled();

		blur( input );

		expect( updates ).toHaveBeenCalledTimes( 1 );
		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );
	} );

	it( 'guards the shipping address the same way', async () => {
		boot( 'checkout woocommerce-checkout', 'shipping' );
		await pickKazan( 'shipping' );

		const input = el( 'shipping_address_1' );

		input.focus();

		expect( row( 'shipping_address_1' ).classList.contains( 'address-field' ) ).toBe( false );

		type( input, 'ул Баумана, 1' );
		blur( input );

		expect( wooKeydowns ).not.toHaveBeenCalled();
		expect( updates ).toHaveBeenCalledTimes( 1 );
		expect( row( 'shipping_address_1' ).classList.contains( 'address-field' ) ).toBe( true );
	} );

	it( 'leaves the settlement and region rows alone', async () => {
		boot();

		el( 'billing_city' ).focus();

		expect( row( 'billing_city' ).classList.contains( 'address-field' ) ).toBe( true );

		el( 'billing_state' ).focus();

		expect( row( 'billing_state' ).classList.contains( 'address-field' ) ).toBe( true );
	} );

	it( 'does nothing outside the classic checkout form', async () => {
		boot( 'woocommerce-shipping-calculator' );
		await pickKazan();

		const input = el( 'billing_address_1' );

		input.focus();

		expect( row( 'billing_address_1' ).classList.contains( 'address-field' ) ).toBe( true );

		type( input, 'ул Баумана, 1' );
		blur( input );

		expect( updates ).not.toHaveBeenCalled();
	} );
} );
