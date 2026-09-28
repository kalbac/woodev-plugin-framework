/**
 * The wizard's picker session against the REAL storefront pieces it is assembled from (#970):
 * `pickup-datasource.js`, `pickup-geo.js` and `pickup-panels.js`, with only the map provider
 * faked (a real one needs a map library and a network). `order-wizard-pickup-session.test.js`
 * fakes all four, so it can only prove the wiring is self-consistent; this proves the wiring
 * matches what those files actually do — the method names it calls exist, the data source puts
 * the admin context on the wire, and a card's own choose button reaches the caller.
 *
 * @see src/shipping-orders-page/order-wizard/pickup-session.ts
 */

import { createPickupSession } from '../../src/shipping-orders-page/order-wizard/pickup-session';

const DataSource = require( '../../woodev/shipping-method/assets/js/frontend/pickup-datasource' );
const Panels = require( '../../woodev/shipping-method/assets/js/frontend/pickup-panels' );
const Geo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

const REST_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders/pickup/carrier/points';

const CONFIG = {
	provider: 'fake',
	strategy: 'bulk',
	restRoot: REST_ROOT,
	i18n: {
		select: 'Выбрать этот пункт',
		error: 'Не удалось загрузить пункты.',
		emptyLocality: 'Пунктов нет.',
		blocked: 'Пункт недоступен для этого заказа.',
		retry: 'Повторить',
		drawerTitle: 'Пункты выдачи',
		distanceMeters: 'м',
		distanceKilometers: 'км',
		distanceMiles: 'mi',
		yourAddress: 'Ваш адрес',
		resetSearch: 'Сбросить',
		noResults: 'Ничего не найдено',
		sectionPoints: 'Пункты',
		sectionAddresses: 'Адреса',
		zoomInLabel: '+',
		zoomOutLabel: '−',
		showMap: 'Показать карту',
		continueCheckout: 'Пункт выбран',
	},
	mapConfig: { lang: 'ru_RU' },
	distanceUnitSystem: 'metric',
	defaultLocation: { center: [ 55.75, 37.61 ], zoom: 10 },
	pointIcons: {},
	accentColor: '#06aedd',
};

const point = ( id, extra = {} ) => ( {
	id,
	name: `Пункт ${ id }`,
	short_address: `ул Тверская ${ id }`,
	address: `Москва, ул Тверская ${ id }`,
	locality: 'Москва',
	lat: 55.75,
	lng: 37.61,
	type: { code: 'pvz', label: 'ПВЗ' },
	selectable: { allowed: true, reason: null },
	...extra,
} );

let provider;

class FakeProvider {
	constructor() {
		this.handlers = {};
		this.init = jest.fn( () => Promise.resolve() );
		this.setPoints = jest.fn();
		this.destroy = jest.fn();
		this.setMargin = jest.fn();
		this.focusGroup = jest.fn();
		this.getCenter = jest.fn( () => [ 55.75, 37.61 ] );
		provider = this;
	}

	on( event, callback ) {
		( this.handlers[ event ] = this.handlers[ event ] || [] ).push( callback );
	}

	emit( event, payload ) {
		( this.handlers[ event ] || [] ).forEach( ( callback ) => callback( payload ) );
	}
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

beforeEach( () => {
	provider = null;
	window.WoodevPickupDataSource = DataSource;
	window.WoodevPickupPanels = Panels;
	window.WoodevPickupGeo = Geo;
	window.WoodevPickupMapProviders = { fake: FakeProvider };

	global.fetch = jest.fn( () =>
		Promise.resolve( { ok: true, status: 200, json: () => Promise.resolve( { points: [ point( 'P-1' ), point( 'P-2', { lat: 55.76, lng: 37.62 } ) ] } ) } )
	);
} );

afterEach( () => {
	delete window.WoodevPickupDataSource;
	delete window.WoodevPickupPanels;
	delete window.WoodevPickupGeo;
	delete window.WoodevPickupMapProviders;
	delete global.fetch;
	document.body.innerHTML = '';
} );

const start = ( overrides = {} ) => {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );

	const onSelect = jest.fn();
	const session = createPickupSession( {
		host,
		config: CONFIG,
		nonce: 'nonce-1',
		context: () => ( { weight: 3250, payment_method: 'cod', location: { key: 'dadata:77', region: { name: 'Москва' } } } ),
		locality: 'Москва',
		localityKey: 'dadata:77',
		selectedId: '',
		onSelect,
		...overrides,
	} );

	return { host, session, onSelect };
};

test( 'the real data source puts the admin context on the wire, with the page nonce', async () => {
	start();
	// The data source debounces `fetchPoints()` 300 ms.
	await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );

	expect( global.fetch ).toHaveBeenCalledTimes( 1 );

	const [ url, init ] = global.fetch.mock.calls[ 0 ];

	expect( init.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );
	expect( decodeURIComponent( url ) ).toBe(
		REST_ROOT + '?locality=dadata:77&weight=3250&payment_method=cod&location[key]=dadata:77&location[region][name]=Москва'
	);
} );

test( 'the real panels draw into the host, and a card\'s own choose button reaches the caller with the point', async () => {
	const { host, onSelect } = start();
	await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );

	expect( host.querySelector( '.woodev-pickup-stage' ) ).not.toBeNull();
	expect( provider.init.mock.calls[ 0 ][ 0 ] ).toBe( host.querySelector( '.woodev-pickup-map' ) );

	// The provider's second argument to `setPoints` is the grouped set the panels' rows are keyed by.
	const groups = provider.setPoints.mock.calls[ 0 ][ 0 ];

	expect( groups.map( ( group ) => group.points[ 0 ].id ).sort() ).toEqual( [ 'P-1', 'P-2' ] );

	// What the map reports as on screen fills the list; a click on a row opens its card.
	provider.emit( 'visibleChange', groups.map( ( group ) => group.key ) );

	const rows = host.querySelectorAll( '.woodev-pickup-list__item' );

	expect( rows.length ).toBeGreaterThan( 0 );

	rows[ 0 ].click();
	await flush();

	const cta = host.querySelector( '.woodev-pickup-card__cta' );

	expect( cta ).not.toBeNull();
	expect( cta.disabled ).toBe( false );

	cta.click();

	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	expect( [ 'P-1', 'P-2' ] ).toContain( onSelect.mock.calls[ 0 ][ 0 ].id );
	// The card's own state follows the choice, as it does at checkout.
	expect( host.querySelector( '.woodev-pickup-card__cta' ).textContent ).toBe( 'Пункт выбран' );
} );

test( 'a point the carrier says cannot be chosen has a disabled button and never reaches the caller', async () => {
	global.fetch = jest.fn( () =>
		Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( { points: [ point( 'P-9', { selectable: { allowed: false, reason: 'Наложенный платёж недоступен.' } } ) ] } ),
		} )
	);
	const { host, onSelect } = start();
	await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );

	const groups = provider.setPoints.mock.calls[ 0 ][ 0 ];

	provider.emit( 'visibleChange', groups.map( ( group ) => group.key ) );
	host.querySelector( '.woodev-pickup-list__item' ).click();
	await flush();

	const cta = host.querySelector( '.woodev-pickup-card__cta' );

	expect( cta.disabled ).toBe( true );
	expect( host.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( 'Наложенный платёж недоступен.' );

	cta.click();

	expect( onSelect ).not.toHaveBeenCalled();
} );

test( 'a carrier failure shows the panels\' own message card, and destroy leaves an empty host', async () => {
	global.fetch = jest.fn( () =>
		Promise.resolve( { ok: false, status: 502, json: () => Promise.resolve( { code: 'woodev_pickup_upstream_error', message: 'x', data: { status: 502 } } ) } )
	);
	const { host, session } = start();
	await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );

	expect( host.querySelector( '.woodev-pickup-message' ) ).not.toBeNull();

	session.destroy();

	expect( host.childNodes ).toHaveLength( 0 );
	expect( provider.destroy ).toHaveBeenCalled();
} );
