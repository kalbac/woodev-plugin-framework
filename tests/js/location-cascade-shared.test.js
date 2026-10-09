/**
 * Tests for location-cascade.js — ONE cascade for overlapping carrier declarations (issue #1187).
 *
 * The v2 release runs two carrier plugins on one site (#1179). Carrier A declares region +
 * settlement + address, carrier B declares region + settlement only. Both configs name the SAME
 * native WooCommerce ids (`billing_state`, `billing_city`), and before #1187 each config built its
 * own cascade entry with its own records and its own widget on `#billing_city`. The real
 * typeahead keeps ONE instance per input (a second attach detaches the first), so the pick
 * landed in whichever entry attached last — B's. A's records never learned the settlement, and
 * {@see isAddressLocked} kept `#billing_address_1` disabled: the buyer could not type a street,
 * and a disabled input is not even serialized into the order (gotcha
 * `disabled-drops-a-checkout-field-from-the-form-readonly-is-inert-on-select`).
 *
 * Unlike `location-cascade.test.js`, this file drives the REAL `location-typeahead.js` (plus the
 * real store and the real cascade) — the defect is precisely about which widget instance owns the
 * input, so a fake that records every attach call would hide it.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js — SHARED CASCADE section
 * @see tests/js/location-cascade.test.js — the per-feature cascade suite (fake widget)
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

let fetchCalls;
let registeredGlobals;

async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

function locationField( level, required = false ) {
	return {
		id: null,
		type: 'text',
		section: 'billing',
		source_kind: 'location',
		location_level: level,
		depends_on: null,
		required,
		is_pickup_slot: false,
	};
}

/**
 * The store-level `location` block — identical for every plugin on the site by construction
 * (`Checkout_Config::build_location_block()`: fleet-wide endpoints, one nonce, one customer chain).
 */
function locationBlock() {
	return {
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
	};
}

/**
 * One plugin's `woodev_checkout_field_config_*` global, declaring the given levels on the billing ids.
 *
 * @param {string[]} levels
 * @param {Object}   [options]
 * @param {boolean}  [options.required=false] Every declared field is required (round 2, the gate case).
 * @returns {Object}
 */
function carrierConfig( levels, options = {} ) {
	const ids = { region: 'billing_state', settlement: 'billing_city', address: 'billing_address_1' };
	const fields = {};

	levels.forEach( ( level ) => {
		fields[ ids[ level ] ] = locationField( level, !! options.required );
	} );

	return {
		fields,
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'test-nonce',
		takeover: {},
		// The store setting's default — `checkout-field-classic.js` treats an absent key as ON too.
		block_place_order: true,
		location: locationBlock(),
	};
}

function installMarkup() {
	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<select id="billing_country" name="billing_country">
				<option value="RU">Россия</option>
			</select>
			<input type="text" id="billing_state" name="billing_state" value="" />
			<input type="text" id="billing_city" name="billing_city" value="" />
			<input type="text" id="billing_address_1" name="billing_address_1" value="" />
			<input type="text" id="billing_postcode" name="billing_postcode" value="" />
			<div id="shipping_method"></div>
			<button type="submit" id="place_order"></button>
		</form>
	`;
	document.getElementById( 'billing_country' ).value = 'RU';
}

function mockFetch() {
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
}

/**
 * Boots the real modules with the given plugin configs registered in the given order — the order
 * `wp_localize_script()` printed them is the order `Object.keys( window )` returns them.
 *
 * @param {Array<{ id: string, levels: string[] }>} plugins
 * @returns {void}
 */
function boot( plugins ) {
	installMarkup();

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;

	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );
	// The REAL widget — it installs itself as `window.WoodevLocationTypeahead`.
	require( '../../woodev/shipping-method/assets/js/frontend/location-typeahead.js' );

	mockFetch();

	registeredGlobals = [];
	plugins.forEach( ( plugin ) => {
		const name = PREFIX + plugin.id;

		window[ name ] = carrierConfig( plugin.levels );
		registeredGlobals.push( name );
	} );

	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

/**
 * Round 2 (critic P2): boots the REAL classic adapter too — `checkout-field-classic.js` creates one
 * store PER PLUGIN config at its top-level scan and its `refreshGate()` evaluates every one of them,
 * so a shared-field value that reaches only one store disables «Оформить заказ» for good. Production
 * enqueue order (`Checkout_Handler::enqueue_assets()`): store → classic adapter → typeahead → cascade.
 *
 * `required: true` on every declared field and `block_place_order: true` (the store setting's
 * default) — the configuration the round-1 fixture avoided and the critic's probe measured.
 *
 * @param {Array<{ id: string, levels: string[] }>} plugins
 * @returns {Object[]} every store the adapter created, in registration order.
 */
function bootWithClassicAdapter( plugins ) {
	installMarkup();

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;
	// The adapter's suggest/options sources never fire for a location field; stubbed so a
	// surprise request fails loudly instead of reaching the network.
	global.jQuery.ajax = jest.fn( () => {
		throw new Error( 'unexpected $.ajax' );
	} );

	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );

	const stores = [];
	const createStore = window.WoodevCheckoutFieldStore.createStore;

	window.WoodevCheckoutFieldStore.createStore = ( config ) => {
		const store = createStore( config );

		stores.push( store );

		return store;
	};

	mockFetch();

	registeredGlobals = [];
	plugins.forEach( ( plugin ) => {
		const name = PREFIX + plugin.id;

		window[ name ] = carrierConfig( plugin.levels, { required: true } );
		registeredGlobals.push( name );
	} );

	require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );
	require( '../../woodev/shipping-method/assets/js/frontend/location-typeahead.js' );
	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );

	// jQuery schedules `ready` on a timer when the document is already complete, fires the
	// callback on a second one, and the adapter's takeover tick is a third — see
	// `checkout-field-classic.test.js`'s own boot for why this is `runAllTimers`.
	jest.runAllTimers();

	return stores;
}

function placeOrder() {
	return document.getElementById( 'place_order' );
}

function address() {
	return document.getElementById( 'billing_address_1' );
}

function city() {
	return document.getElementById( 'billing_city' );
}

/**
 * The buyer types into the city field, the provider answers with Kazan, the buyer clicks it, and
 * the server confirms the `/select` — the complete classic-checkout pick through the real widget.
 */
async function pickKazan() {
	city().focus();
	city().value = 'Каз';
	city().dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 ); // the widget's debounce

	const suggest = fetchCalls.find( ( c ) => c.url.indexOf( SUGGEST_URL ) === 0 );
	expect( suggest ).toBeDefined();
	suggest.resolve( { suggestions: [ KAZAN ] } );
	await flushMicrotasks();

	const option = document.querySelector( '.woodev-location-listbox li' );
	expect( option ).not.toBeNull();
	option.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );

	const select = fetchCalls.find( ( c ) => c.url === SELECT_URL );
	expect( select ).toBeDefined();
	select.resolve( {
		current: { key: KAZAN.key, level: 'settlement' },
		persisted: true,
		chain: { settlement: { key: KAZAN.key, level: 'settlement' } },
	} );
	await flushMicrotasks();
}

function serializedAddress() {
	const pairs = window.jQuery( 'form.checkout' ).serialize().split( '&' ).map( ( pair ) => {
		const [ name, value ] = pair.split( '=' );

		return [ name, decodeURIComponent( ( value || '' ).replace( /\+/g, ' ' ) ) ];
	} );

	return Object.fromEntries( pairs );
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

describe.each( [
	[ 'the full carrier registered first', [ { id: 'carrier_full', levels: [ 'region', 'settlement', 'address' ] }, { id: 'carrier_partial', levels: [ 'region', 'settlement' ] } ] ],
	[ 'the partial carrier registered first', [ { id: 'carrier_partial', levels: [ 'region', 'settlement' ] }, { id: 'carrier_full', levels: [ 'region', 'settlement', 'address' ] } ] ],
] )( 'issue #1187: A (region + settlement + address) with B (region + settlement), %s', ( _label, plugins ) => {
	it( 'the address is locked until a settlement is picked, and ONE pick unlocks it for the declaration that owns it', async () => {
		boot( plugins );

		expect( address().disabled ).toBe( true ); // #337: no settlement yet

		await pickKazan();

		expect( city().value ).toBe( 'Казань' );
		expect( address().disabled ).toBe( false );
		expect( address().classList.contains( 'woodev-location-locked' ) ).toBe( false );
		// One chain, one persisted pick — not one per declaring plugin.
		expect( fetchCalls.filter( ( c ) => c.url === SELECT_URL ) ).toHaveLength( 1 );
	} );

	it( 'the street typed after the pick survives `updated_checkout` and goes out in the form', async () => {
		boot( plugins );
		await pickKazan();

		address().value = 'ул Баумана, 1';
		address().dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// WooCommerce's own refresh after the pick — the cascade re-applies the lock here.
		window.jQuery( document.body ).trigger( 'updated_checkout' );

		expect( address().disabled ).toBe( false );
		expect( serializedAddress().billing_address_1 ).toBe( 'ул Баумана, 1' );
		expect( serializedAddress().billing_city ).toBe( 'Казань' );
	} );

	it( 'exactly one widget owns each shared field', () => {
		boot( plugins );

		expect( document.querySelectorAll( '.woodev-location-listbox' ) ).toHaveLength( 3 ); // state, city, address
		expect( city().getAttribute( 'role' ) ).toBe( 'combobox' );
	} );
} );

describe( 'issue #1187: declarations that overlap without one containing the other', () => {
	it( 'A (region + settlement) with B (settlement + address) form ONE chain of three levels', async () => {
		boot( [
			{ id: 'carrier_upper', levels: [ 'region', 'settlement' ] },
			{ id: 'carrier_lower', levels: [ 'settlement', 'address' ] },
		] );

		expect( address().disabled ).toBe( true );

		await pickKazan();

		expect( address().disabled ).toBe( false );
		// Backwards fill reached the region through the SAME chain the pick landed in.
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Татарстан' );
	} );
} );

describe.each( [
	[ 'the full carrier registered first', [ { id: 'carrier_full', levels: [ 'region', 'settlement', 'address' ] }, { id: 'carrier_partial', levels: [ 'region', 'settlement' ] } ] ],
	[ 'the partial carrier registered first', [ { id: 'carrier_partial', levels: [ 'region', 'settlement' ] }, { id: 'carrier_full', levels: [ 'region', 'settlement', 'address' ] } ] ],
] )( 'issue #1187 round 2: required shared fields with the REAL classic adapter, %s', ( _label, plugins ) => {
	it( 'a city pick back-fills the region into EVERY declaring store, so «Оформить заказ» unlocks once the street is typed', async () => {
		const stores = bootWithClassicAdapter( plugins );

		expect( stores ).toHaveLength( 2 ); // one per plugin — the adapter's own shape, not merged
		expect( placeOrder().disabled ).toBe( true ); // nothing filled yet

		await pickKazan();

		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Татарстан' ); // silent backwards fill
		expect( city().value ).toBe( 'Казань' );
		expect( address().disabled ).toBe( false );

		address().value = 'ул Баумана, 1';
		address().dispatchEvent( new Event( 'change', { bubbles: true } ) );

		window.jQuery( document.body ).trigger( 'updated_checkout' );

		// The silent fill never dispatches a native `change`, so the adapter's own handler cannot
		// carry it — the cascade has to write the region into each store itself.
		stores.forEach( ( store ) => {
			expect( store.getValue( 'billing_state' ) ).toBe( 'Татарстан' );
			expect( store.getValue( 'billing_city' ) ).toBe( 'Казань' );
		} );

		expect( placeOrder().disabled ).toBe( false );

		const serialized = serializedAddress();

		expect( serialized.billing_state ).toBe( 'Татарстан' );
		expect( serialized.billing_city ).toBe( 'Казань' );
		expect( serialized.billing_address_1 ).toBe( 'ул Баумана, 1' );
	} );

	it( 'clearing the city after the pick empties the dependants in EVERY store and the gate closes again', async () => {
		const stores = bootWithClassicAdapter( plugins );

		await pickKazan();
		address().value = 'ул Баумана, 1';
		address().dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( placeOrder().disabled ).toBe( false );

		city().value = '';
		city().dispatchEvent( new Event( 'input', { bubbles: true } ) );
		city().dispatchEvent( new Event( 'change', { bubbles: true } ) );
		jest.runOnlyPendingTimers();

		stores.forEach( ( store ) => {
			expect( store.getValue( 'billing_city' ) ).toBe( '' );
		} );
		expect( placeOrder().disabled ).toBe( true );
	} );
} );
