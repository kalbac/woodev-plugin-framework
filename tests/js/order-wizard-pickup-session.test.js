/**
 * The pickup picker inside the order wizard's delivery step (#970, #710 D3 / O10): the wiring of
 * the storefront's data source, panels and map provider into one host element, against fakes of
 * those three globals.
 *
 * What is asserted is what only this file decides: the data source is told the admin context
 * (explicit weight / payment / destination record), a chosen point is reported straight to the
 * caller (nothing is confirmed against a session), `bulk` and `viewport` fetch when their
 * strategy says so, and the provider that owns its whole chrome gets the host instead of the panels'
 * map element.
 *
 * @see src/shipping-orders-page/order-wizard/pickup-session.ts
 */

import { createPickupSession, isPickupRuntimeAvailable } from '../../src/shipping-orders-page/order-wizard/pickup-session';

/** A tiny event emitter, the shape both the provider and the panels expose (`on( event, cb )`). */
class Emitter {
	constructor() {
		this.handlers = {};
	}

	on( event, callback ) {
		( this.handlers[ event ] = this.handlers[ event ] || [] ).push( callback );
	}

	emit( event, payload ) {
		( this.handlers[ event ] || [] ).forEach( ( callback ) => callback( payload ) );
	}
}

let providers;
let panelsInstances;
let dataSourceOptions;
let dataSource;

class FakeProvider extends Emitter {
	constructor() {
		super();
		this.init = jest.fn( () => Promise.resolve() );
		this.setPoints = jest.fn();
		this.destroy = jest.fn();
		this.setMargin = jest.fn();
		this.setTypeFilter = jest.fn();
		this.focusGroup = jest.fn();
		this.getCenter = jest.fn( () => [ 55.7, 37.6 ] );
		providers.push( this );
	}
}

class FakePanels extends Emitter {
	constructor( container, config ) {
		super();
		this.container = container;
		this.config = config;
		this.mapElement = document.createElement( 'div' );
		[ 'render', 'setBusy', 'setLoading', 'setTypes', 'setSelectedId', 'hideMessage', 'showMessage', 'setVisible', 'setAnchor', 'openCard', 'destroy', 'setVerdictPending', 'updatePoint', 'buildSearchLayout' ].forEach( ( name ) => {
			this[ name ] = jest.fn();
		} );
		this.buildSearchLayout.mockReturnValue( document.createElement( 'div' ) );
		this.getMapElement = jest.fn( () => this.mapElement );
		panelsInstances.push( this );
	}
}

const CONFIG = {
	provider: 'yandex',
	strategy: 'bulk',
	restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders/pickup/carrier/points',
	i18n: { error: 'Не удалось загрузить пункты.', emptyLocality: 'В этом населённом пункте пунктов нет.', emptyInView: 'В этой области пунктов нет.', upstreamError: 'Сервис пунктов недоступен.' },
	mapConfig: { lang: 'ru_RU' },
	defaultLocation: { center: [ 55.75, 37.61 ], zoom: 10 },
	pointIcons: {},
	accentColor: '#06aedd',
};

const POINTS = [
	{ id: 'P-1', name: 'ПВЗ 1', address: 'ул Тверская 1', type: { code: 'pvz', label: 'ПВЗ' } },
	{ id: 'P-2', name: 'Постамат', address: 'ул Арбат 2', type: { code: 'post', label: 'Постамат' } },
];

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const start = ( overrides = {} ) => {
	const host = document.createElement( 'div' );
	const onSelect = jest.fn();
	const onError = jest.fn();
	const session = createPickupSession( {
		host,
		config: CONFIG,
		nonce: 'nonce-1',
		context: () => ( { weight: 3250, payment_method: 'cod' } ),
		locality: 'Москва',
		localityKey: 'dadata:77',
		selectedId: '',
		onSelect,
		onError,
		...overrides,
	} );

	return { host, session, onSelect, onError };
};

beforeEach( () => {
	providers = [];
	panelsInstances = [];
	dataSourceOptions = null;
	dataSource = {
		fetchPoints: jest.fn( () => Promise.resolve( POINTS ) ),
		fetchDetails: jest.fn( ( id ) => Promise.resolve( { id, selectable: { allowed: true } } ) ),
	};

	window.WoodevPickupDataSource = jest.fn( ( options ) => {
		dataSourceOptions = options;

		return dataSource;
	} );
	window.WoodevPickupPanels = FakePanels;
	window.WoodevPickupGeo = {
		groupByPosition: jest.fn( ( points ) => points.map( ( point ) => ( { key: `k-${ point.id }`, points: [ point ] } ) ) ),
	};
	window.WoodevPickupMapProviders = { yandex: FakeProvider, embedded: FakeProvider };
} );

afterEach( () => {
	delete window.WoodevPickupDataSource;
	delete window.WoodevPickupPanels;
	delete window.WoodevPickupGeo;
	delete window.WoodevPickupMapProviders;
} );

describe( 'isPickupRuntimeAvailable', () => {
	test( 'true when the data source, panels, geometry helper and the carrier\'s provider are all on the page', () => {
		expect( isPickupRuntimeAvailable( CONFIG ) ).toBe( true );
	} );

	test.each( [
		[ 'no config for the carrier', undefined ],
		[ 'a config with no points route', { ...CONFIG, restRoot: '' } ],
	] )( 'false for %s', ( label, config ) => {
		expect( isPickupRuntimeAvailable( config ) ).toBe( false );
	} );

	test.each( [ 'WoodevPickupDataSource', 'WoodevPickupPanels', 'WoodevPickupGeo' ] )( 'false when %s did not load', ( name ) => {
		delete window[ name ];

		expect( isPickupRuntimeAvailable( CONFIG ) ).toBe( false );
	} );

	test( 'false when the carrier\'s map provider did not load', () => {
		window.WoodevPickupMapProviders = {};

		expect( isPickupRuntimeAvailable( CONFIG ) ).toBe( false );
	} );

	test( 'a provider that owns its whole chrome needs neither the panels nor the geometry helper', () => {
		delete window.WoodevPickupPanels;
		delete window.WoodevPickupGeo;

		expect( isPickupRuntimeAvailable( { ...CONFIG, provider: 'embedded', mapConfig: { ownsChrome: true } } ) ).toBe( true );
	} );
} );

describe( 'the data source', () => {
	test( 'is built on the ADMIN points route with the page nonce and the wizard\'s context, read per request', () => {
		start();

		expect( dataSourceOptions.restRoot ).toBe( CONFIG.restRoot );
		expect( dataSourceOptions.nonce() ).toBe( 'nonce-1' );
		expect( dataSourceOptions.context() ).toEqual( { weight: 3250, payment_method: 'cod' } );
	} );
} );

describe( 'strategy bulk', () => {
	test( 'draws the panels around the provider\'s map element and fetches the settlement once the map is ready', async () => {
		const { host } = start();

		expect( panelsInstances ).toHaveLength( 1 );
		expect( panelsInstances[ 0 ].container ).toBe( host );
		expect( panelsInstances[ 0 ].render ).toHaveBeenCalled();
		// The panels' config is the carrier's own with the map language the distance labels follow.
		expect( panelsInstances[ 0 ].config ).toMatchObject( { strategy: 'bulk', lang: 'ru_RU', accentColor: '#06aedd' } );

		const provider = providers[ 0 ];
		expect( provider.init ).toHaveBeenCalledTimes( 1 );

		const [ mapHost, providerConfig, ds ] = provider.init.mock.calls[ 0 ];
		expect( mapHost ).toBe( panelsInstances[ 0 ].mapElement );
		expect( ds ).toBe( dataSource );
		expect( providerConfig ).toMatchObject( { lang: 'ru_RU', strategy: 'bulk', locality: 'Москва', accentColor: '#06aedd' } );

		await flush();

		// `bulk` addresses points by the settlement RECORD's key, not by the typed city.
		expect( dataSource.fetchPoints ).toHaveBeenCalledWith( { locality: 'dadata:77', types: [] } );
		expect( provider.setPoints ).toHaveBeenCalledTimes( 1 );
		expect( provider.setPoints.mock.calls[ 0 ][ 0 ].map( ( group ) => group.key ) ).toEqual( [ 'k-P-1', 'k-P-2' ] );
		expect( panelsInstances[ 0 ].setTypes ).toHaveBeenCalledWith( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'post', label: 'Постамат' } ] );
		expect( panelsInstances[ 0 ].hideMessage ).toHaveBeenCalled();
	} );

	test( 'falls back to the typed city when no settlement record was picked', async () => {
		start( { localityKey: '' } );
		await flush();

		expect( dataSource.fetchPoints ).toHaveBeenCalledWith( { locality: 'Москва', types: [] } );
	} );

	test( 'an empty answer is not an error: the panels say the settlement has no points', async () => {
		dataSource.fetchPoints.mockResolvedValue( [] );
		start();
		await flush();

		expect( panelsInstances[ 0 ].showMessage ).toHaveBeenCalledWith( 'emptyLocality' );
	} );

	test( 'a carrier failure is worded from the server\'s code, and the busy cover comes down', async () => {
		dataSource.fetchPoints.mockRejectedValue( { status: 502, code: 'woodev_pickup_upstream_error', message: '' } );
		start();
		await flush();

		expect( panelsInstances[ 0 ].showMessage ).toHaveBeenCalledWith( 'upstreamError' );
		expect( panelsInstances[ 0 ].setBusy ).toHaveBeenLastCalledWith( false );
	} );

	test( 'the type filter is applied by the provider, locally', async () => {
		start();
		await flush();

		panelsInstances[ 0 ].emit( 'typeFilterChange', [ 'pvz' ] );

		expect( providers[ 0 ].setTypeFilter ).toHaveBeenCalledWith( [ 'pvz' ] );
		expect( dataSource.fetchPoints ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'strategy viewport', () => {
	const viewport = { ...CONFIG, strategy: 'viewport' };

	test( 'waits for the provider\'s bounds and fetches per bbox — nothing on init', async () => {
		start( { config: viewport } );
		await flush();

		expect( dataSource.fetchPoints ).not.toHaveBeenCalled();

		providers[ 0 ].emit( 'boundsChange', [ 55.1, 37.2, 55.9, 37.9 ] );
		await flush();

		expect( dataSource.fetchPoints ).toHaveBeenCalledWith( { bounds: [ 55.1, 37.2, 55.9, 37.9 ], types: [] } );
		expect( providers[ 0 ].setPoints ).toHaveBeenCalled();
	} );

	test( 'a type filter change asks the SERVER again for the same viewport with the new types', async () => {
		start( { config: viewport } );
		await flush();
		providers[ 0 ].emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flush();

		panelsInstances[ 0 ].emit( 'typeFilterChange', [ 'post' ] );
		await flush();

		expect( dataSource.fetchPoints ).toHaveBeenLastCalledWith( { bounds: [ 1, 2, 3, 4 ], types: [ 'post' ] } );
		expect( providers[ 0 ].setTypeFilter ).not.toHaveBeenCalled();
	} );

	test( 'opening a card fetches that point\'s full record once, for its real availability verdict', async () => {
		start( { config: viewport } );
		await flush();

		panelsInstances[ 0 ].emit( 'cardOpened', { pointId: 'P-1', origin: 'list', group: { key: 'k-P-1' } } );
		panelsInstances[ 0 ].emit( 'cardOpened', { pointId: 'P-1', origin: 'list', group: { key: 'k-P-1' } } );
		await flush();

		expect( dataSource.fetchDetails ).toHaveBeenCalledTimes( 1 );
		expect( dataSource.fetchDetails ).toHaveBeenCalledWith( 'P-1' );
		expect( panelsInstances[ 0 ].updatePoint ).toHaveBeenCalledWith( 'P-1', { id: 'P-1', selectable: { allowed: true } } );
		expect( providers[ 0 ].focusGroup ).toHaveBeenCalledWith( 'k-P-1', { zoom: true } );
	} );

	test( 'a marker click only pans the camera, it does not zoom', async () => {
		start( { config: viewport } );
		await flush();

		panelsInstances[ 0 ].emit( 'cardOpened', { pointId: 'P-1', origin: 'marker', group: { key: 'k-P-1' } } );

		expect( providers[ 0 ].focusGroup ).toHaveBeenCalledWith( 'k-P-1', { zoom: false } );
	} );
} );

describe( 'choosing a point', () => {
	test( 'the panels\' choose button reports the point straight to the caller and marks it chosen', async () => {
		const { onSelect } = start();
		await flush();

		panelsInstances[ 0 ].emit( 'select', POINTS[ 1 ] );

		expect( onSelect ).toHaveBeenCalledWith( POINTS[ 1 ] );
		expect( panelsInstances[ 0 ].setSelectedId ).toHaveBeenLastCalledWith( 'P-2' );
		// Nothing is confirmed against a session: the data source has no select in this flow.
		expect( dataSource.selectPoint ).toBeUndefined();
	} );

	test( 'so does the provider\'s own select (an embedded widget reports it)', async () => {
		const { onSelect } = start();
		await flush();

		providers[ 0 ].emit( 'select', POINTS[ 0 ] );

		expect( onSelect ).toHaveBeenCalledWith( POINTS[ 0 ] );
	} );

	test( 'the point already chosen is marked when the card opens', () => {
		start( { selectedId: 'P-9' } );

		expect( panelsInstances[ 0 ].setSelectedId ).toHaveBeenCalledWith( 'P-9' );
	} );

	test( 'a point with no id is ignored', async () => {
		const { onSelect } = start();
		await flush();

		providers[ 0 ].emit( 'select', {} );

		expect( onSelect ).not.toHaveBeenCalled();
	} );
} );

describe( 'a provider that owns its whole chrome (an embedded carrier widget)', () => {
	const embedded = { ...CONFIG, provider: 'embedded', mapConfig: { ownsChrome: true } };

	test( 'gets the host itself, no panels are built, and nothing is fetched for it', async () => {
		const { host, onSelect } = start( { config: embedded } );
		await flush();

		expect( panelsInstances ).toHaveLength( 0 );
		expect( providers[ 0 ].init.mock.calls[ 0 ][ 0 ] ).toBe( host );
		expect( dataSource.fetchPoints ).not.toHaveBeenCalled();

		providers[ 0 ].emit( 'select', POINTS[ 0 ] );

		expect( onSelect ).toHaveBeenCalledWith( POINTS[ 0 ] );
	} );

	test( 'a failure it reports has no panel to show it in, so it goes to the caller', async () => {
		const { onError } = start( { config: embedded } );
		await flush();

		providers[ 0 ].emit( 'error', { message: 'widget failed to load' } );

		expect( onError ).toHaveBeenCalledWith( 'widget failed to load' );
	} );
} );

describe( 'the lifetime', () => {
	test( 'destroy tears the provider and the panels down, empties the host and is safe twice', async () => {
		const { host, session } = start();
		await flush();
		host.appendChild( document.createElement( 'span' ) );

		session.destroy();
		session.destroy();

		expect( providers[ 0 ].destroy ).toHaveBeenCalledTimes( 1 );
		expect( panelsInstances[ 0 ].destroy ).toHaveBeenCalledTimes( 1 );
		expect( host.childNodes ).toHaveLength( 0 );
	} );

	test( 'an answer that lands after destroy draws nothing', async () => {
		let resolveFetch;
		dataSource.fetchPoints.mockReturnValue( new Promise( ( resolve ) => ( resolveFetch = resolve ) ) );
		const { session } = start();
		await flush();

		session.destroy();
		resolveFetch( POINTS );
		await flush();

		expect( providers[ 0 ].setPoints ).not.toHaveBeenCalled();
	} );

	test( 'a retry from the panels builds a FRESH provider — a live one is never re-inited', async () => {
		start();
		await flush();

		panelsInstances[ 0 ].emit( 'retryRequested' );

		expect( providers ).toHaveLength( 2 );
		expect( providers[ 0 ].destroy ).toHaveBeenCalledTimes( 1 );
		expect( providers[ 1 ].init ).toHaveBeenCalledTimes( 1 );
		expect( panelsInstances ).toHaveLength( 1 );
	} );

	test( 'without the scripts it says so and does nothing, instead of throwing', () => {
		delete window.WoodevPickupMapProviders;
		const { session, onError } = start();

		expect( onError ).toHaveBeenCalledWith( CONFIG.i18n.error );
		expect( () => session.destroy() ).not.toThrow();
	} );
} );
