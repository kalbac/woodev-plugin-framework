/**
 * Tests for location-cascade.js — range-shipping city mode (spec 2026-11-05-range-shipping-city-mode §4.2, card F3).
 *
 * While `config.location.allowed` lists places for the country, the classic cascade:
 * - serves the settlement field from that closed list only, searched locally, never `/suggest`;
 * - hides the settlement and region ROWS when exactly one place is listed and `hideSettlement` is on;
 * - leaves the city and region alone when a street suggestion is picked.
 *
 * The widgets are fakes (the real typeahead and select2 have their own suites): a fake
 * `ajax-select2` renderer records the options the cascade hands it, the fake typeahead does the same
 * for the address level. The test drives `options.popular`, `options.fetch` and `options.onSelect`
 * exactly the way the real renderers do.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-cascade.js — City mode section
 */

'use strict';

const CONFIG_GLOBAL = 'woodev_checkout_field_config_city_mode_test';
const SUGGEST_URL = 'https://example.test/wp-json/woodev/v1/location/suggest';
const SELECT_URL = 'https://example.test/wp-json/woodev/v1/location/select';
const LIST_URL = 'https://example.test/wp-json/woodev/v1/location/list';
const FORGET_URL = 'https://example.test/wp-json/woodev/v1/location/forget';

let rendererCalls;
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

function installMarkup( { shipToDifferent = null } = {} ) {
	const row = ( id ) => `<p class="form-row" id="${ id }_field"><input type="text" id="${ id }" name="${ id }" value="" /></p>`;
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

	rendererCalls = [];
	typeaheadCalls = [];
	window.WoodevLocationRenderers = {
		'ajax-select2': jest.fn( ( el, opts ) => {
			const detach = jest.fn();
			rendererCalls.push( { el, opts, detach } );

			return { detach };
		} ),
	};
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

/** Replays the real widgets' pick: write the value, fire the native change, then `onSelect`. */
function pick( call, item ) {
	call.el.value = 'string' === typeof item.value ? item.value : item.label;
	call.el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	call.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	call.opts.onSelect( item );
}

const settlementWidget = () => rendererCalls.find( ( call ) => 'billing_city' === call.el.id );
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

describe( 'city mode — several places (classic)', () => {
	it( 'renders only the listed places in the settlement field, whatever the shop field mode is', () => {
		boot( buildConfig() );

		expect( window.WoodevLocationRenderers[ 'ajax-select2' ] ).toHaveBeenCalledTimes( 1 );
		expect( settlementWidget() ).toBeDefined();
		expect( settlementWidget().opts.popular().map( ( item ) => item.key ) ).toEqual( [ 'dadata:moscow', 'dadata:khimki' ] );
	} );

	it( 'keeps the server-stamped value of a nested place (its own name, not its parent\'s)', () => {
		boot( buildConfig( { places: [ CHERKIZOVO, KHIMKI ] } ) );

		const popular = settlementWidget().opts.popular();

		expect( popular.find( ( item ) => 'dadata:cherkizovo' === item.key ).value ).toBe( 'Черкизово' );
	} );

	it( 'filters the list locally as the buyer types, and sends no settlement-level /suggest', async () => {
		boot( buildConfig() );

		const found = await settlementWidget().opts.fetch( 'хим' );
		const none = await settlementWidget().opts.fetch( 'Тула' );
		const all = await settlementWidget().opts.fetch( '' );

		expect( found.map( ( item ) => item.key ) ).toEqual( [ 'dadata:khimki' ] );
		expect( none ).toEqual( [] );
		expect( all ).toHaveLength( 2 );
		expect( suggestCalls() ).toHaveLength( 0 );
	} );

	it( 'does not narrow the dropdown to the previous pick (the dropdown keeps offering every place)', () => {
		boot( buildConfig() );

		expect( settlementWidget().opts.pickedSettlement() ).toBeNull();
	} );

	it( 'writes the region of a picked place into the region field and sends the record to /select', () => {
		boot( buildConfig() );

		pick( settlementWidget(), KHIMKI );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Химки' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская' );
		expect( selectCalls() ).toHaveLength( 1 );
		expect( JSON.parse( selectCalls()[ 0 ].init.body ).record.key ).toBe( 'dadata:khimki' );
	} );

	it( 'leaves a shop without city mode on its own field mode (typeahead, real /suggest)', async () => {
		boot( buildConfig( { places: null } ) );

		expect( window.WoodevLocationRenderers[ 'ajax-select2' ] ).not.toHaveBeenCalled();
		expect( window.WoodevLocationTypeahead.mock.calls.map( ( call ) => call[ 0 ].id ) ).toContain( 'billing_city' );

		const cityCall = typeaheadCalls.find( ( call ) => 'billing_city' === call.el.id );
		cityCall.opts.fetch( 'хим' );
		await flushMicrotasks();

		expect( suggestCalls() ).toHaveLength( 1 );
	} );

	it( 'does not close the list for a country the merchant listed no places for', () => {
		boot( buildConfig( { places: [ MOSCOW, KHIMKI ] } ) );
		const select = document.getElementById( 'billing_country' );
		select.innerHTML = '<option value="RU">Россия</option><option value="KZ">Казахстан</option>';
		select.value = 'KZ';

		// `allowed` carries RU only: a KZ buyer gets no closed list.
		expect( settlementWidget().opts.popular().map( ( item ) => item.key ) ).not.toContain( 'dadata:moscow' );
	} );
} );

describe( 'city mode — one place, field shown (classic)', () => {
	it( 'prefills the city with the place through the fixed default locality and keeps the row visible', () => {
		boot( buildConfig( {
			places: [ MOSCOW ],
			extra: {
				current: { key: 'dadata:moscow', level: 'settlement' },
				chain: { settlement: { key: 'dadata:moscow', level: 'settlement' } },
				implicit: true,
				defaultLocality: { policy: 'fixed', record: MOSCOW.record },
			},
		} ) );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_city_field' ).classList.contains( 'woodev-field--hidden' ) ).toBe( false );
	} );
} );

describe( 'city mode — one place, hidden (classic)', () => {
	it( 'hides the settlement and region rows by class, keeps the values in the form, leaves the address row', () => {
		boot( buildConfig( {
			places: [ MOSCOW ],
			hideSettlement: true,
			extra: {
				current: { key: 'dadata:moscow', level: 'settlement' },
				chain: { settlement: { key: 'dadata:moscow', level: 'settlement' } },
				implicit: true,
				defaultLocality: { policy: 'fixed', record: MOSCOW.record },
			},
		} ) );

		const hidden = ( id ) => document.getElementById( `${ id }_field` ).classList.contains( 'woodev-field--hidden' );

		expect( hidden( 'billing_city' ) ).toBe( true );
		expect( hidden( 'billing_state' ) ).toBe( true );
		expect( hidden( 'billing_address_1' ) ).toBe( false );
		expect( hidden( 'billing_postcode' ) ).toBe( false );
		// A row hidden by class still posts: the input is neither removed nor disabled.
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
		expect( document.getElementById( 'billing_city' ).disabled ).toBe( false );
	} );

	it( 'never hides the field while several places are listed, even with the flag on', () => {
		boot( buildConfig( { places: [ MOSCOW, KHIMKI ], hideSettlement: true } ) );

		expect( document.getElementById( 'billing_city_field' ).classList.contains( 'woodev-field--hidden' ) ).toBe( false );
	} );

	it( 'hides only the delivery section: the payer\'s billing city stays when delivery moves to shipping', () => {
		const config = buildConfig( { places: [ MOSCOW ], hideSettlement: true, sections: [ 'billing', 'shipping' ] } );

		boot( config, { shipToDifferent: true } );

		const hidden = ( id ) => document.getElementById( `${ id }_field` ).classList.contains( 'woodev-field--hidden' );

		expect( hidden( 'shipping_city' ) ).toBe( true );
		expect( hidden( 'shipping_state' ) ).toBe( true );
		expect( hidden( 'billing_city' ) ).toBe( false );

		// «Доставить по другому адресу» switched off: billing is the delivery address again.
		const toggle = document.querySelector( '[name="ship_to_different_address"]' );
		toggle.checked = false;
		toggle.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( hidden( 'billing_city' ) ).toBe( true );
		expect( hidden( 'shipping_city' ) ).toBe( false );
	} );
} );

describe( 'city mode — picking a street (classic)', () => {
	it( 'fills the street and postcode, and does not rewrite the city or region from the address record', () => {
		boot( buildConfig( { places: [ CHERKIZOVO, KHIMKI ] } ) );

		pick( settlementWidget(), CHERKIZOVO );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Черкизово' );
		expect( document.getElementById( 'billing_state' ).value ).toBe( 'Московская' );

		pick( addressWidget(), STREET );

		expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул Главная, 1' );
		expect( document.getElementById( 'billing_postcode' ).value ).toBe( '141200' );
		// The street record names «Пушкино»; the listed place stays «Черкизово».
		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Черкизово' );
	} );

	it( 'still fills the city from an address pick when the shop runs without city mode', () => {
		boot( buildConfig( { places: null } ) );

		pick( addressWidget(), STREET );

		expect( document.getElementById( 'billing_city' ).value ).toBe( 'Пушкино' );
	} );
} );

describe( 'city mode — network', () => {
	it( 'sends no settlement-level /suggest while `allowed` is present, but the address level still searches', async () => {
		boot( buildConfig() );

		await settlementWidget().opts.fetch( 'мос' );
		await settlementWidget().opts.fetch( 'хим' );

		pick( settlementWidget(), KHIMKI );
		fetchCalls[ fetchCalls.length - 1 ].resolve( {
			current: { key: 'dadata:khimki', level: 'settlement' },
			persisted: true,
			chain: { settlement: { key: 'dadata:khimki', level: 'settlement' } },
		} );
		await flushMicrotasks();

		addressWidget().opts.fetch( 'Ленина' );
		await flushMicrotasks();

		const levels = suggestCalls().map( ( call ) => new URL( call.url ).searchParams.get( 'level' ) );

		expect( levels ).toEqual( [ 'address' ] );
	} );
} );
