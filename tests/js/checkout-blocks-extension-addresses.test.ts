import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';

const mockMiddlewares: APIFetchMiddleware[] = [];
const mockForm = { shipping: {} as Record< string, string >, billing: {} as Record< string, string > };

jest.mock( '@wordpress/data', () => ( {
	select: () => ( {
		getCustomerData: () => ( { shippingAddress: mockForm.shipping, billingAddress: mockForm.billing } ),
		getUseShippingAsBilling: () => false,
	} ),
	dispatch: () => ( {
		setShippingAddress: ( address: Record< string, string > ) => { mockForm.shipping = address; },
		setBillingAddress: ( address: Record< string, string > ) => { mockForm.billing = address; },
	} ),
} ) );

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: { use: ( middleware: APIFetchMiddleware ) => mockMiddlewares.push( middleware ) },
} ) );

const { keepExtensionAddressesInForm } = require( '../../src/checkout-blocks/extension-cart-addresses' ) as
	typeof import('../../src/checkout-blocks/extension-cart-addresses');
const { adoptDestination, writeDeliveryLocality } = require( '../../src/checkout-blocks/wc-stores' ) as
	typeof import('../../src/checkout-blocks/wc-stores');

const PICKUP = 'woodev-shipping';
const FEES = 'woodev-shipping-fee-payments';
const EXTENSION_PATH = '/wc/store/v1/cart/extensions';
const blank = { city: '', state: '*', country: 'RU', email: '' };
const chosen = { city: 'Москва', state: '', country: 'RU', email: 'buyer@example.com' };
const cart = () => ( {
	shipping_address: { ...blank },
	billing_address: { ...blank },
	extensions: { [ PICKUP ]: { pickup: { cdek: { destination: { address_1: 'Point street', postcode: '101000' } } } } },
	shipping_rates: [ { package_id: 0 } ],
} );

function request( options: APIFetchOptions, response: unknown | Promise< unknown > ): Promise< unknown > {
	const next = mockMiddlewares.reduceRight< ( args: APIFetchOptions ) => Promise< unknown > >(
		( handler, middleware ) => ( args ) => middleware( args, handler ),
		() => Promise.resolve( response )
	);
	return next( options );
}

beforeEach( () => {
	mockMiddlewares.length = 0;
	keepExtensionAddressesInForm( [ PICKUP, FEES ] );
} );

// WC 11.2.1 wc-blocks-data.js, applyExtensionCartUpdate (`gi`): when the localStorage dirty
// flag is false, receiveCart merges the WHOLE reply before resolving. The flag trails setters
// by 300 ms (`It`) and a partial updateCustomerData clears it (`wi`). These are both real windows.
function receiveCart( form: typeof chosen, reply: unknown, dirty: boolean ): typeof chosen {
	const response = reply as { shipping_address?: typeof chosen };
	return dirty ? form : { ...form, ...response.shipping_address };
}

it.each( [ false, true ] )( 'keeps a picked city and typed email over a late fee reply (dirty=%s)', async ( dirty ) => {
	let answer!: ( response: unknown ) => void;
	const pending = request( { path: EXTENSION_PATH, data: { namespace: FEES } }, new Promise( ( resolve ) => { answer = resolve; } ) );
	// The request began while city/email were empty; the shopper edits before it lands.
	mockForm.shipping = { ...blank, email: chosen.email };
	mockForm.billing = { ...mockForm.shipping };
	writeDeliveryLocality( chosen.city, chosen.state, true );
	const form = mockForm.shipping as typeof chosen;
	// Positive control: the unfiltered main-path reply really does erase these fields.
	expect( receiveCart( form, cart(), false ) ).toEqual( blank );
	answer( cart() );
	const reply = await pending;
	expect( receiveCart( form, reply, dirty ) ).toEqual( chosen );
	// Core's focusout pushes the form it NOW holds, so it cannot send the old blank city.
	expect( ( reply as Record< string, unknown > ).billing_address ).toBeUndefined();
	expect( reply ).toMatchObject( { extensions: cart().extensions, shipping_rates: cart().shipping_rates } );
} );

it( 'keeps city through CDEK → Yandex without locality → CDEK, while preserving explicit destination adoption', async () => {
	mockForm.shipping = { ...chosen, address_1: '', postcode: '' };
	mockForm.billing = { ...mockForm.shipping };
	for ( const [ address, postcode ] of [ [ 'CDEK point', '101000' ], [ 'Yandex point', '' ], [ 'CDEK again', '101000' ] ] ) {
		const response = cart();
		response.extensions[ PICKUP ].pickup.cdek.destination = { address_1: address, postcode };
		const reply = await request( { path: EXTENSION_PATH, data: { namespace: PICKUP } }, response );
		mockForm.shipping = receiveCart( mockForm.shipping as typeof chosen, reply, false );
		mockForm.billing = receiveCart( mockForm.billing as typeof chosen, reply, false );
		adoptDestination( response.extensions[ PICKUP ].pickup.cdek.destination, true );
		expect( mockForm.shipping.city ).toBe( 'Москва' );
		expect( mockForm.billing.city ).toBe( 'Москва' );
		expect( mockForm.billing.email ).toBe( 'buyer@example.com' );
		expect( reply ).toMatchObject( { extensions: response.extensions } );
	}
	expect( mockForm.shipping ).toMatchObject( { city: 'Москва', address_1: 'CDEK again', postcode: '101000' } );
	expect( mockForm.billing ).toMatchObject( { city: 'Москва', address_1: 'CDEK again', postcode: '101000' } );
} );

it.each( [
	{ url: 'https://shop.test/wp-json/wc/store/v1/cart/extensions' },
	{ url: 'https://shop.test/?rest_route=/wc/store/v1/cart/extensions' },
	{ path: EXTENSION_PATH },
] )( 'recognizes direct route %j', async ( route ) => {
	const reply = await request( { ...route, data: { namespace: PICKUP } }, cart() );
	expect( reply ).not.toHaveProperty( 'shipping_address' );
	expect( reply ).not.toHaveProperty( 'billing_address' );
} );

it( 'strips only matching successful batch bodies; customer, foreign and error replies stay untouched', async () => {
	const responses = [
		{ status: 200, body: cart() },
		{ status: 200, body: cart() },
		{ status: 200, body: cart() },
		{ status: 400, body: { code: 'refused', data: { cart: cart() } } },
		{ status: 200, body: cart() },
	];
	const reply = await request( {
		url: 'https://shop.test/?rest_route=/wc/store/v1/batch&_locale=site',
		data: { requests: [
			{ path: '/wc/store/v1/cart/update-customer', body: {} },
			{ path: EXTENSION_PATH, body: { namespace: FEES } },
			{ path: EXTENSION_PATH, data: { namespace: 'foreign' } },
			{ path: EXTENSION_PATH, data: { namespace: PICKUP } },
			{ path: EXTENSION_PATH, data: { namespace: PICKUP } },
		] },
	}, { responses } ) as { responses: typeof responses };
	expect( reply.responses[ 0 ] ).toBe( responses[ 0 ] );
	expect( reply.responses[ 1 ].body ).not.toHaveProperty( 'shipping_address' );
	expect( reply.responses[ 2 ] ).toBe( responses[ 2 ] );
	expect( reply.responses[ 3 ] ).toBe( responses[ 3 ] );
	expect( reply.responses[ 4 ].body ).not.toHaveProperty( 'billing_address' );
	expect( responses[ 1 ].body ).toHaveProperty( 'shipping_address' );
} );

it( 'leaves foreign direct replies, malformed requests and malformed responses untouched', async () => {
	const response = cart();
	for ( const options of [
		{ path: EXTENSION_PATH, data: { namespace: 'foreign' } },
		{ path: '/wc/store/v1/cart', data: { namespace: PICKUP } },
		{ path: '/wc/store/v1/batch', data: { requests: [ null, {} ] } },
	] ) {
		expect( await request( options, response ) ).toBe( response );
	}
	expect( await request( { path: EXTENSION_PATH, data: { namespace: PICKUP } }, null ) ).toBeNull();
} );

it( 'propagates transport failures', async () => {
	const error = { code: 'network_error' };
	await expect( request( { path: EXTENSION_PATH, data: { namespace: PICKUP } }, Promise.reject( error ) ) ).rejects.toBe( error );
} );
