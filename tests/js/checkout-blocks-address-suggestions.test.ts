import { watchBlockAddressSuggestions } from '../../src/checkout-blocks/address-suggestions';
import { publishSettlementScope, resetSettlementScope } from '../../src/checkout-blocks/address-scope';
import { resetSharedChainSync, sharedChainSync } from '../../src/checkout-blocks/chain-sync';

const address = { country: 'RU', city: 'Москва', state: 'MOW', address_1: 'Тверская, 1', postcode: '101000' };
const mockConfig = {
	endpoints: { suggest: '/wp-json/woodev/v1/location/suggest', select: '/select', list: '/list', forget: '/forget' },
	nonce: 'nonce',
	countries: [ 'RU' ],
	levels: { RU: { region: true, settlement: true, address: true } },
	regionFieldRemoved: false,
	current: null,
	chain: {},
	implicit: false,
	savedCityUnresolved: null,
	selection: { record: { key: 'settlement:moscow', provider_id: 'dadata', level: 'settlement', country: 'RU' } },
	billingOnly: false,
	i18n: {},
};

let mockCurrentAddress = { ...address };
let mockForcedBilling = false;
let attach: jest.Mock;
let attachedOptions: Array< { fetch: ( query: string, signal?: AbortSignal ) => Promise< unknown[] >; onSelect: ( item: any ) => void } >;
let detachments: jest.Mock[];
let dispatch: jest.Mock;
let mockStoreListener: (() => void) | null;

jest.mock( '@wordpress/data', () => ( {
	dispatch: jest.fn(),
	subscribe: jest.fn( ( listener: () => void ) => {
		mockStoreListener = listener;
		return jest.fn();
	} ),
} ) );

jest.mock( '../../src/checkout-blocks/rest', () => ( {
	forgetSelection: jest.fn( () => Promise.resolve( true ) ),
	selectRecord: jest.fn( () => Promise.resolve( { ok: true, persisted: true } ) ),
	SuggestUnavailableError: class extends Error {},
} ) );

jest.mock( '../../src/checkout-blocks/wc-stores', () => ( {
	CART_STORE: 'wc/store/cart',
	gateCheckout: ( work: () => Promise< void > ) => work(),
	readDeliveryAddress: jest.fn( () => mockCurrentAddress ),
	readLocalityData: jest.fn( () => ( { enabled: true, location: mockConfig } ) ),
	refreshRates: jest.fn( () => Promise.resolve() ),
} ) );

jest.mock( '../../src/checkout-blocks/wc-runtime', () => ( {
	wcRuntime: () => ( { wcSettings: { getSetting: ( name: string ) => name === 'forcedBillingAddress' && mockForcedBilling } } ),
} ) );

beforeEach( () => {
	jest.clearAllMocks();
	document.body.innerHTML = '';
	mockCurrentAddress = { ...address };
	mockForcedBilling = false;
	mockStoreListener = null;
	attachedOptions = [];
	detachments = [];
	attach = jest.fn( ( _input, options ) => {
		attachedOptions.push( options );
		const detach = jest.fn();
		detachments.push( detach );
		return { detach };
	} );
	( window as unknown as { WoodevLocationTypeahead: typeof attach } ).WoodevLocationTypeahead = attach;
	dispatch = jest.fn( () => ( {
		setShippingAddress: jest.fn( ( next ) => ( mockCurrentAddress = next ) ),
		setBillingAddress: jest.fn(),
	} ) );
	( require( '@wordpress/data' ) as { dispatch: jest.Mock } ).dispatch.mockImplementation( dispatch );
	resetSettlementScope();
	publishSettlementScope( 'settlement:moscow' );
	resetSharedChainSync();
} );

afterEach( () => {
	delete ( window as unknown as { WoodevLocationTypeahead?: typeof attach } ).WoodevLocationTypeahead;
} );

const flushDom = async (): Promise< void > => {
	await Promise.resolve();
	await Promise.resolve();
};

describe( 'Blocks address suggestions', () => {
	it( 'attaches to the native field, scopes address searches, and survives React remounts', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const stop = watchBlockAddressSuggestions();

		expect( attach ).toHaveBeenCalledTimes( 1 );
		const response = { ok: true, json: async () => ( { suggestions: [] } ) };
		global.fetch = jest.fn( async () => response as Response );
		await attachedOptions[ 0 ].fetch( 'Tverskaya' );
		const request = new URL( ( global.fetch as jest.Mock ).mock.calls[ 0 ][ 0 ] as string );

		expect( request.searchParams.get( 'level' ) ).toBe( 'address' );
		expect( request.searchParams.get( 'within' ) ).toBe( 'settlement:moscow' );
		publishSettlementScope( 'settlement:moscow' );
		expect( attach ).toHaveBeenCalledTimes( 1 );
		publishSettlementScope( 'settlement:new' );
		await attachedOptions[ 1 ].fetch( 'Tverskaya' );
		const refreshedRequest = new URL( ( global.fetch as jest.Mock ).mock.calls[ 1 ][ 0 ] as string );
		expect( refreshedRequest.searchParams.get( 'within' ) ).toBe( 'settlement:new' );
		expect( detachments[ 0 ] ).toHaveBeenCalledTimes( 1 );

		const replacement = document.createElement( 'input' );
		replacement.id = 'shipping-address_1';
		input.replaceWith( replacement );
		await flushDom();
		expect( attach ).toHaveBeenCalledTimes( 3 );
		expect( detachments[ 1 ] ).toHaveBeenCalledTimes( 1 );
		stop();
		expect( detachments[ 2 ] ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not attach until a validated settlement scope is published', () => {
		resetSettlementScope();
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const stop = watchBlockAddressSuggestions();

		expect( attach ).not.toHaveBeenCalled();
		publishSettlementScope( 'settlement:moscow' );
		expect( attach ).toHaveBeenCalledTimes( 1 );
		stop();
		expect( detachments[ 0 ] ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'persists the selected record and merges its street and postcode into the cart address', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const stop = watchBlockAddressSuggestions();
		const suggestion = {
			key: 'address:123',
			label: 'ул Тверская, 7',
			value: 'ул Тверская, 7',
			record: { key: 'address:123', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '7', postcode: '125009' },
		};
		input.value = suggestion.value;
		attachedOptions[ 0 ].onSelect( suggestion );
		await flushDom();

		expect( dispatch ).toHaveBeenCalledWith( 'wc/store/cart' );
		expect( mockCurrentAddress ).toEqual( { ...address, address_1: 'ул Тверская, 7', postcode: '125009' } );
		stop();
	} );

	it( 'leaves the native input alone when the address suggestion setting is off', () => {
		const stores = require( '../../src/checkout-blocks/wc-stores' );
		stores.readLocalityData.mockReturnValueOnce( { enabled: true, location: { ...mockConfig, levels: { RU: { ...mockConfig.levels.RU, address: false } } } } );
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );

		const stop = watchBlockAddressSuggestions();

		expect( attach ).not.toHaveBeenCalled();
		stop();
	} );

	it( 'uses the billing address only when the framework and WooCommerce both choose billing delivery', () => {
		const stores = require( '../../src/checkout-blocks/wc-stores' );
		stores.readLocalityData.mockReturnValueOnce( { enabled: true, location: { ...mockConfig, billingOnly: true } } );
		mockForcedBilling = true;
		const input = document.createElement( 'input' );
		input.id = 'billing-address_1';
		document.body.append( input );

		const stop = watchBlockAddressSuggestions();

		expect( attach ).toHaveBeenCalledWith( input, expect.objectContaining( { fetch: expect.any( Function ) } ) );
		stop();
	} );

	it( 'keeps a newer pickup-point postcode when the suggestion request settles', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const rest = require( '../../src/checkout-blocks/rest' );
		let finish!: ( value: { ok: true; persisted: true } ) => void;
		rest.selectRecord.mockImplementationOnce( () => new Promise( ( resolve ) => ( finish = resolve ) ) );
		const stop = watchBlockAddressSuggestions();
		const suggestion = {
			key: 'address:123', label: 'ул Тверская, 7', value: 'ул Тверская, 7',
			record: { key: 'address:123', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '7', postcode: '125009' },
		};
		input.value = suggestion.value;
		attachedOptions[ 0 ].onSelect( suggestion );
		mockCurrentAddress = { ...mockCurrentAddress, postcode: 'pickup-point-postcode' };
		finish( { ok: true, persisted: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( mockCurrentAddress.address_1 ).toBe( 'ул Тверская, 7' );
		expect( mockCurrentAddress.postcode ).toBe( 'pickup-point-postcode' );
		stop();
	} );

	it( 'reattaches and fetches with the new country when the delivery country changes', async () => {
		const stores = require( '../../src/checkout-blocks/wc-stores' );
		stores.readLocalityData.mockReturnValueOnce( {
			enabled: true,
			location: { ...mockConfig, levels: { ...mockConfig.levels, FR: { region: true, settlement: true, address: true } } },
		} );
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const stop = watchBlockAddressSuggestions();
		mockCurrentAddress = { ...mockCurrentAddress, country: 'FR' };
		( mockStoreListener as () => void )();

		expect( attach ).toHaveBeenCalledTimes( 2 );
		expect( detachments[ 0 ] ).toHaveBeenCalledTimes( 1 );
		global.fetch = jest.fn( async () => ( { ok: true, json: async () => ( { suggestions: [] } ) } ) as Response );
		await attachedOptions[ 1 ].fetch( 'Rue' );
		expect( new URL( ( global.fetch as jest.Mock ).mock.calls[ 0 ][ 0 ] as string ).searchParams.get( 'country' ) ).toBe( 'FR' );
		stop();
	} );

	it.each( [
		[ 'refused', { ok: false, reason: 'refused' } ],
		[ 'failed', { ok: false, reason: 'unreachable' } ],
	] as const )( 'keeps the picked street when /select is %s', async ( _name, result ) => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const rest = require( '../../src/checkout-blocks/rest' );
		let finish!: ( value: typeof result ) => void;
		rest.selectRecord.mockImplementationOnce( () => new Promise( ( resolve ) => ( finish = resolve ) ) );
		const stop = watchBlockAddressSuggestions();
		const suggestion = {
			key: 'address:123', label: 'ул Тверская, 7', value: 'ул Тверская, 7',
			record: { key: 'address:123', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '7', postcode: '125009' },
		};
		input.value = suggestion.value;
		attachedOptions[ 0 ].onSelect( suggestion );
		expect( mockCurrentAddress ).toEqual( { ...address, address_1: 'ул Тверская, 7', postcode: '125009' } );
		finish( result );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( mockCurrentAddress ).toEqual( { ...address, address_1: 'ул Тверская, 7', postcode: '125009' } );
		stop();
	} );

	it( 'keeps the second immediate pick when its /select supersedes the first', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const rest = require( '../../src/checkout-blocks/rest' );
		const finishes: Array< ( value: { ok: true; persisted: true } ) => void > = [];
		rest.selectRecord.mockImplementation( () => new Promise( ( resolve ) => finishes.push( resolve ) ) );
		const stop = watchBlockAddressSuggestions();
		const first = { key: 'address:1', label: 'ул Тверская, 1', value: 'ул Тверская, 1', record: { key: 'address:1', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '1' } };
		const second = { key: 'address:2', label: 'ул Тверская, 2', value: 'ул Тверская, 2', record: { key: 'address:2', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '2' } };
		input.value = first.value;
		attachedOptions[ 0 ].onSelect( first );
		await Promise.resolve();
		input.value = second.value;
		attachedOptions[ 0 ].onSelect( second );
		expect( mockCurrentAddress.address_1 ).toBe( second.value );
		finishes[ 0 ]( { ok: true, persisted: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( finishes ).toHaveLength( 2 );
		finishes[ 1 ]( { ok: true, persisted: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( mockCurrentAddress.address_1 ).toBe( second.value );
		stop();
	} );

	it( 'detaches with a pending select without applying any later store write', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const rest = require( '../../src/checkout-blocks/rest' );
		let finish!: ( value: { ok: true; persisted: true } ) => void;
		rest.selectRecord.mockImplementationOnce( () => new Promise( ( resolve ) => ( finish = resolve ) ) );
		const stop = watchBlockAddressSuggestions();
		const suggestion = {
			key: 'address:123', label: 'ул Тверская, 7', value: 'ул Тверская, 7',
			record: { key: 'address:123', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '7' },
		};
		input.value = suggestion.value;
		attachedOptions[ 0 ].onSelect( suggestion );
		stop();
		expect( detachments[ 0 ] ).toHaveBeenCalledTimes( 1 );
		const writes = ( dispatch.mock.results[ 0 ].value.setShippingAddress as jest.Mock ).mock.calls.length;
		finish( { ok: true, persisted: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( ( dispatch.mock.results[ 0 ].value.setShippingAddress as jest.Mock ).mock.calls ).toHaveLength( writes );
	} );

	it( 'does not erase the selected chain when the chooser abandons during the pending select', async () => {
		const input = document.createElement( 'input' );
		input.id = 'shipping-address_1';
		document.body.append( input );
		const rest = require( '../../src/checkout-blocks/rest' );
		let finish!: ( value: { ok: true; persisted: true } ) => void;
		rest.selectRecord.mockImplementationOnce( () => new Promise( ( resolve ) => ( finish = resolve ) ) );
		const stop = watchBlockAddressSuggestions();
		const suggestion = {
			key: 'address:123', label: 'ул Тверская, 7', value: 'ул Тверская, 7',
			record: { key: 'address:123', provider_id: 'dadata', level: 'address', country: 'RU', street: { name: 'Тверская', type: 'ул' }, house: '7' },
		};
		input.value = suggestion.value;
		attachedOptions[ 0 ].onSelect( suggestion );
		const sync = sharedChainSync( {
			select: () => Promise.resolve( { ok: true, persisted: true } ),
			forget: jest.fn( () => Promise.resolve( true ) ),
			refresh: () => Promise.resolve(),
			gate: ( work ) => work(),
			retryDelayMs: 0,
		} );

		expect( sync.abandon() ).toBe( false );
		expect( require( '../../src/checkout-blocks/rest' ).forgetSelection ).not.toHaveBeenCalled();
		stop();
		finish( { ok: true, persisted: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
} );
