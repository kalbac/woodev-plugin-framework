/**
 * Tests for location-cascade.js — range-shipping city mode (spec 2026-11-05-range-shipping-city-mode §4.2, card F3).
 *
 * While `config.location.allowed` lists places for the country, the classic cascade:
 * - ONE place: shows the settlement as plain text (the input stays in the form as a hidden field
 *   that posts the place); SEVERAL: a plain `<select>` of exactly those places, no select2, and no
 *   settlement-level `/suggest` either way;
 * - hides the REGION row (its value comes from the place), and the settlement row too when one place
 *   is listed and `hideSettlement` is on;
 * - leaves the city and region alone when a street suggestion is picked.
 *
 * The settlement renderers are the REAL ones (`location-select-modes.js`); only the address
 * typeahead is a fake (its own suite covers it), recording the options the cascade hands it.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js — City mode section
 */

'use strict';

const CONFIG_GLOBAL = 'woodev_checkout_field_config_city_mode_test';
const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';
const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';
const FORGET_URL = 'https://example.test/wp-json/woodev/v1/location/forget';

let typeaheadCalls;
let fetchCalls;

async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

const MOSCOW = {
	key: 'dadata:moscow',
	label: 'г Москва',
	level: 'settlement',
	value: 'Москва',
	record: {
		key: 'dadata:moscow',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { type: 'г', name: 'Москва' },
		settlement: { type: 'г', name: 'Москва' },
	},
};

const KHIMKI = {
	key: 'dadata:khimki',
	label: 'г Химки, Московская обл',
	level: 'settlement',
	value: 'Химки',
	record: {
		key: 'dadata:khimki',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { type: 'обл', name: 'Московская' },
		settlement: { type: 'г', name: 'Химки' },
	},
};

// A nested place: «рп Черкизово» sits under «г Пушкино» — the record's `settlement` is the parent,
// the item's `value` is the place's own name (the server stamps it, `own_locality_name()`).
const CHERKIZOVO = {
	key: 'dadata:cherkizovo',
	label: 'рп Черкизово, г Пушкино',
	level: 'settlement',
	value: 'Черкизово',
	record: {
		key: 'dadata:cherkizovo',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { type: 'обл', name: 'Московская' },
		settlement: { type: 'г', name: 'Пушкино' },
	},
};

const STREET = {
	key: 'dadata:street',
	label: 'г Пушкино, ул Главная',
	level: 'address',
	value: 'ул Главная, 1',
	record: {
		key: 'dadata:street',
		provider_id: 'dadata',
		level: 'address',
		country: 'RU',
		region: { type: 'обл', name: 'Московская' },
		settlement: { type: 'г', name: 'Пушкино' },
		street: { type: 'ул', name: 'Главная' },
		house: '1',
		postcode: '141200',
	},
};

function installMarkup( { shipToDifferent = null, cityValue = '' } = {} ) {
	const row = ( id ) => `<p class="form-row" id="${ id }_field"><input type="text" id="${ id }" name="${ id }" value="${ 'billing_city' === id ? cityValue : '' }" /></p>`;
	const shippingBlock = null === shipToDifferent ? '' : `
		<input type="checkbox" name="ship_to_different_address" ${ shipToDifferent ? 'checked' : '' } />
		<select id="shipping_country" name="shipping_country"><option value="RU">Россия</option></select>
		${ row( 'shipping_state' ) }${ row( 'shipping_city' ) }${ row( 'shipping_address_1' ) }${ row( 'shipping_postcode' ) }
	`;

	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<select id="billing_country" name="billing_country"><option value="RU">Россия</option></select>
			${ row( 'billing_state' ) }${ row( 'billing_city' ) }${ row( 'billing_address_1' ) }${ row( 'billing_postcode' ) }
			${ shippingBlock }
		</form>
	`;
	document.getElementById( 'billing_country' ).value = 'RU';
}

function locationField( level, section ) {
	return {
		id: null, type: 'text', section, source_kind: 'location', location_level: level,
		depends_on: null, required: false, is_pickup_slot: false,
	};
}

function buildConfig( { places = [ MOSCOW, KHIMKI ], hideSettlement = false, sections = [ 'billing' ], extra = {} } = {} ) {
	const fields = {};

	sections.forEach( ( section ) => {
		fields[ `${ section }_state` ] = locationField( 'region', section );
		fields[ `${ section }_city` ] = locationField( 'settlement', section );
		fields[ `${ section }_address_1` ] = locationField( 'address', section );
	} );

	return {
		fields,
		endpoint: 'https://example.test/wp-json/woodev/v1/carrier/field-source',
		nonce: 'test-nonce',
		takeover: {},
		location: {
			endpoints: { suggest: SUGGEST_URL, select: SELECT_URL, list: LIST_URL, forget: FORGET_URL },
			nonce: 'test-nonce',
			countries: [ 'RU' ],
			// The shop's own field mode stays `typeahead`: city mode must not depend on it.
			mode: { region: 'typeahead', settlement: 'typeahead' },
			allowCustomSettlement: false,
			// City mode clamps the region level off (F2).
			levels: { RU: { region: false, settlement: true, address: true } },
			current: null,
			chain: {},
			implicit: false,
			defaultCountry: 'RU',
			...( places ? { allowed: { RU: places }, hideSettlement } : {} ),
			i18n: { noResults: 'Нет результатов', placeholder: 'Выбрать…' },
			...extra,
		},
	};
}

function boot( config, markup = {} ) {
	installMarkup( markup );

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;
	window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );

	// select2 must never be bound in city mode: a spy on the jQuery plugin proves it.
	global.jQuery.fn.select2 = jest.fn();
	typeaheadCalls = [];
	window.WoodevLocationRenderers = {};
	require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );
	window.WoodevLocationTypeahead = jest.fn( ( el, opts ) => {
		const detach = jest.fn();
		typeaheadCalls.push( { el, opts, detach } );

		return { detach };
	} );

	fetchCalls = [];
	global.fetch = jest.fn( ( url, init ) => {
		const call = { url, init };

		call.promise = new Promise( ( resolve ) => {
			call.resolve = ( body ) => resolve( { ok: true, json: () => Promise.resolve( body ) } );
		} );
		fetchCalls.push( call );

		return call.promise;
	} );

	window[ CONFIG_GLOBAL ] = config;
	require( '../../woodev/shipping-method/assets/js/frontend/location-cascade.js' );
}

/** The address typeahead's pick (a fake widget): write the value, fire the native change, then `onSelect`. */
function pick( call, item ) {
	call.el.value = 'string' === typeof item.value ? item.value : item.label;
	call.el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	call.opts.onSelect( item );
}

/** The shopper chooses a place in the settlement `<select>`. */
function choosePlace( place, id = 'billing_city' ) {
	const select = document.getElementById( id );

	select.selectedIndex = Array.from( select.options ).findIndex( ( option ) => option.dataset.woodevKey === place.key );
	select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

const cityField = ( id = 'billing_city' ) => document.getElementById( id );
const rowHidden = ( id ) => document.getElementById( `${ id }_field` ).classList.contains( 'woodev-field--hidden' );
const addressWidget = () => typeaheadCalls.find( ( call ) => 'billing_address_1' === call.el.id );
const suggestCalls = () => fetchCalls.filter( ( call ) => call.url.indexOf( '/location/suggest' ) !== -1 );
const selectCalls = () => fetchCalls.filter( ( call ) => call.url.indexOf( '/location/select' ) !== -1 );

beforeEach( () => {
	jest.resetModules();
	document.body.innerHTML = '';
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevLocationRenderers;
	delete window.WoodevLocationTypeahead;
} );

const ONE_PLACE_DEFAULT = {
	current: { key: 'dadata:moscow', level: 'settlement' },
	chain: { settlement: { key: 'dadata:moscow', level: 'settlement' } },
	implicit: true,
	defaultLocality: { policy: 'fixed', record: MOSCOW.record },
};

describe( 'city mode — several places (classic)', () => {
	it( 'renders a plain select of exactly the listed places, whatever the shop field mode is', () => {
		boot( buildConfig() );

		const select = cityField();

		expect( select.tagName ).toBe( 'SELECT' );
		// A blank leading option, then the places — nothing else, and no typeahead on the field.
		expect( Array.from( select.options ).map( ( option ) => option.value ) ).toEqual( [ '', 'Москва', 'Химки' ] );
		expect( typeaheadCalls.map( ( call ) => call.el.id ) ).not.toContain( 'billing_city' );
	} );

	it( 'is never enhanced with select2 (no search box)', () => {
		boot( buildConfig() );

		expect( window.jQuery.fn.select2 ).not.toHaveBeenCalled();
		expect( document.querySelector( '.select2-container' ) ).toBeNull();
	} );

	it( 'posts a nested place under its OWN name, not its parent\'s', () => {
		boot( buildConfig( { places: [ CHERKIZOVO, KHIMKI ] } ) );

		expect( Array.from( cityField().options ).map( ( option ) => option.value ) ).toContain( 'Черкизово' );
		expect( Array.from( cityField().options ).map( ( option ) => option.value ) ).not.toContain( 'Пушкино' );
	} );

	it( 'asks the settlement service for nothing — no /suggest at any level of the settlement field', async () => {
		boot( buildConfig() );

		choosePlace( KHIMKI );
		await flushMicrotasks();

		expect( suggestCalls().filter( ( call ) => 'settlement' === new URL( call.url ).searchParams.get( 'level' ) ) ).toHaveLength( 0 );
	} );

	it( 'writes the region of a picked place into the region field and sends the record to /select', () => {
		boot( buildConfig() );

		choosePlace( KHIMKI );

		expect( cityField().value ).toBe( 'Химки' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская' );
		expect( selectCalls() ).toHaveLength( 1 );
		expect( JSON.parse( selectCalls()[ 0 ].init.body ).record.key ).toBe( 'dadata:khimki' );
	} );

	it( 'picking the blank option sends nothing', () => {
		boot( buildConfig() );

		choosePlace( KHIMKI );
		cityField().selectedIndex = 0;
		cityField().dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( selectCalls() ).toHaveLength( 1 );
	} );

	it( 'hides the region row from the start; the settlement row stays, flag or not', () => {
		boot( buildConfig( { hideSettlement: true } ) );

		expect( rowHidden( 'billing_state' ) ).toBe( true );
		expect( rowHidden( 'billing_city' ) ).toBe( false );
	} );

	it( 'keeps the region row when a pick leaves the region empty, so the shopper can choose it', () => {
		const noRegion = { ...KHIMKI, record: { ...KHIMKI.record, region: undefined } };

		boot( buildConfig( { places: [ MOSCOW, noRegion ] } ) );
		expect( rowHidden( 'billing_state' ) ).toBe( true );

		choosePlace( noRegion );

		expect( cityField().value ).toBe( 'Химки' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( '' );
		expect( rowHidden( 'billing_state' ) ).toBe( false );
	} );

	it( 'preselects the place the field already holds, without announcing it as a pick', () => {
		boot( buildConfig(), { cityValue: 'Химки' } );

		expect( cityField().value ).toBe( 'Химки' );
		expect( cityField().options[ cityField().selectedIndex ].dataset.woodevKey ).toBe( 'dadata:khimki' );
		expect( selectCalls() ).toHaveLength( 0 );
	} );

	it( 'shows the blank option for a saved city nobody listed', () => {
		boot( buildConfig(), { cityValue: 'Тула' } );

		expect( cityField().value ).toBe( '' );
	} );

	it( 'leaves a shop without city mode on its own field mode (typeahead, real /suggest)', async () => {
		boot( buildConfig( { places: null } ) );

		expect( cityField().tagName ).toBe( 'INPUT' );
		expect( window.WoodevLocationTypeahead.mock.calls.map( ( call ) => call[ 0 ].id ) ).toContain( 'billing_city' );

		const cityCall = typeaheadCalls.find( ( call ) => 'billing_city' === call.el.id );
		cityCall.opts.fetch( 'хим' );
		await flushMicrotasks();

		expect( suggestCalls() ).toHaveLength( 1 );
		expect( rowHidden( 'billing_state' ) ).toBe( false );
	} );

	it( 'does not close the list for a country the merchant listed no places for', () => {
		boot( buildConfig( { places: [ MOSCOW, KHIMKI ] } ) );
		const country = document.getElementById( 'billing_country' );
		country.innerHTML = '<option value="RU">Россия</option><option value="KZ">Казахстан</option>';
		country.value = 'KZ';
		country.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// `allowed` carries RU only: a KZ buyer gets the shop's own field back.
		expect( cityField().tagName ).toBe( 'INPUT' );
		expect( rowHidden( 'billing_state' ) ).toBe( false );
	} );
} );

describe( 'city mode — one place, field shown (classic)', () => {
	it( 'shows the place as plain text, and the city still posts through a hidden input', () => {
		boot( buildConfig( { places: [ MOSCOW ], extra: ONE_PLACE_DEFAULT } ) );

		const input = cityField();
		const text = document.querySelector( '.woodev-city-mode__place' );

		expect( input.tagName ).toBe( 'INPUT' );
		expect( input.type ).toBe( 'hidden' );
		expect( input.value ).toBe( 'Москва' );
		expect( input.disabled ).toBe( false );
		expect( text.textContent ).toBe( 'Москва' );
		expect( input.parentNode ).toBe( text.parentNode );
		// A field mode of its own — no typeahead, no select — and the row stays visible.
		expect( typeaheadCalls.map( ( call ) => call.el.id ) ).not.toContain( 'billing_city' );
		expect( rowHidden( 'billing_city' ) ).toBe( false );
	} );

	it( 'hides the region row once the place has written it', () => {
		boot( buildConfig( { places: [ MOSCOW ], extra: ONE_PLACE_DEFAULT } ) );

		expect( document.getElementById( 'billing_state' ).value ).not.toBe( '' );
		expect( rowHidden( 'billing_state' ) ).toBe( true );
	} );

	it( 'sends nothing to the settlement service', () => {
		boot( buildConfig( { places: [ MOSCOW ], extra: ONE_PLACE_DEFAULT } ) );

		expect( suggestCalls() ).toHaveLength( 0 );
	} );
} );

describe( 'city mode — one NESTED place as the default (classic)', () => {
	const nestedDefault = ( places ) => ( {
		places,
		extra: {
			current: { key: 'dadata:cherkizovo', level: 'settlement' },
			chain: { settlement: { key: 'dadata:cherkizovo', level: 'settlement' } },
			implicit: true,
			defaultLocality: {
				policy: 'fixed',
				record: { ...CHERKIZOVO.record, nested_settlement: { type: 'рп', name: 'Черкизово' } },
			},
		},
	} );

	it( 'shown: the text and the posted value are the place\'s own name, not the parent\'s', () => {
		boot( buildConfig( nestedDefault( [ CHERKIZOVO ] ) ) );

		expect( cityField().value ).toBe( 'Черкизово' );
		expect( document.querySelector( '.woodev-city-mode__place' ).textContent ).toBe( 'Черкизово' );
		expect( rowHidden( 'billing_city' ) ).toBe( false );
	} );

	it( 'hidden: the concealed field holds the place\'s own name, which is what posts', () => {
		boot( buildConfig( { ...nestedDefault( [ CHERKIZOVO ] ), hideSettlement: true } ) );

		expect( rowHidden( 'billing_city' ) ).toBe( true );
		expect( cityField().value ).toBe( 'Черкизово' );
	} );

	it( 'without city mode the old derivation stands (the record\'s settlement)', () => {
		boot( buildConfig( nestedDefault( null ) ) );

		expect( cityField().value ).toBe( 'Пушкино' );
		expect( document.querySelector( '.woodev-city-mode__place' ) ).toBeNull();
	} );
} );

describe( 'city mode — one place, hidden (classic)', () => {
	it( 'hides the settlement and region rows by class, keeps the values in the form, leaves the address row', () => {
		boot( buildConfig( { places: [ MOSCOW ], hideSettlement: true, extra: ONE_PLACE_DEFAULT } ) );

		expect( rowHidden( 'billing_city' ) ).toBe( true );
		expect( rowHidden( 'billing_state' ) ).toBe( true );
		expect( rowHidden( 'billing_address_1' ) ).toBe( false );
		expect( rowHidden( 'billing_postcode' ) ).toBe( false );
		// A row hidden by class still posts: the input is neither removed nor disabled.
		expect( cityField().value ).toBe( 'Москва' );
		expect( cityField().disabled ).toBe( false );
	} );

	it( 'never hides the settlement while several places are listed, even with the flag on', () => {
		boot( buildConfig( { places: [ MOSCOW, KHIMKI ], hideSettlement: true } ) );

		expect( rowHidden( 'billing_city' ) ).toBe( false );
	} );

	it( 'hides only the delivery section: the payer\'s billing city stays when delivery moves to shipping', () => {
		const config = buildConfig( { places: [ MOSCOW ], hideSettlement: true, sections: [ 'billing', 'shipping' ], extra: ONE_PLACE_DEFAULT } );

		boot( config, { shipToDifferent: true } );

		expect( rowHidden( 'shipping_city' ) ).toBe( true );
		expect( rowHidden( 'shipping_state' ) ).toBe( true );
		expect( rowHidden( 'billing_city' ) ).toBe( false );

		// «Доставить по другому адресу» switched off: billing is the delivery address again.
		const toggle = document.querySelector( '[name="ship_to_different_address"]' );
		toggle.checked = false;
		toggle.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( rowHidden( 'billing_city' ) ).toBe( true );
		expect( rowHidden( 'shipping_city' ) ).toBe( false );
	} );
} );

describe( 'city mode — picking a street (classic)', () => {
	it( 'fills the street and postcode, and does not rewrite the city or region from the address record', () => {
		boot( buildConfig( { places: [ CHERKIZOVO, KHIMKI ] } ) );

		choosePlace( CHERKIZOVO );

		expect( cityField().value ).toBe( 'Черкизово' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская' );

		pick( addressWidget(), STREET );

		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул Главная, 1' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '141200' );
		// The street record names «Пушкино»; the listed place stays «Черкизово».
		expect( cityField().value ).toBe( 'Черкизово' );
	} );

	it( 'still fills the city from an address pick when the shop runs without city mode', () => {
		boot( buildConfig( { places: null } ) );

		pick( addressWidget(), STREET );

		expect( cityField().value ).toBe( 'Пушкино' );
	} );

	it( 'scopes the address suggestions to the chosen place', async () => {
		boot( buildConfig() );

		choosePlace( KHIMKI );
		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:khimki', level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: 'dadata:khimki', level: 'settlement' } },
		} );
		await flushMicrotasks();

		addressWidget().opts.fetch( 'Ленина' );
		await flushMicrotasks();

		const addressCalls = suggestCalls();

		expect( addressCalls.map( ( call ) => new URL( call.url ).searchParams.get( 'level' ) ) ).toEqual( [ 'address' ] );
		expect( new URL( addressCalls[ 0 ].url ).searchParams.get( 'within' ) ).toBe( 'dadata:khimki' );
	} );
} );
