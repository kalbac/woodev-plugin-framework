/**
 * The checkout's address-field policy in the order wizard's step ② «Адрес» (#985).
 *
 * The rule lives in PHP (`Checkout_Field_Policy::address_rules()`); the wizard fetches it through
 * `GET …/orders/address-policy` and only READS it. Pinned here: the answer is read into a full
 * policy (an empty answer is «no rule», never «everything removed»); `validateAddress()` demands
 * what the policy marks required — in the server's own words — and never asks a hidden field;
 * the step marks required fields, does not draw hidden ones, and blocks «Далее» on an empty
 * required one; a failed request leaves the step asking for a country and a city, as before.
 *
 * @see src/shipping-orders-page/order-wizard/address-policy.ts
 * @see src/shipping-orders-page/order-wizard/step-address.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import OrderWizard from '../../src/shipping-orders-page/order-wizard/order-wizard';
import { fetchAddressPolicy } from '../../src/shipping-orders-page/order-wizard/api';
import { FALLBACK_POLICY, normalizeAddressPolicy, ruleOf } from '../../src/shipping-orders-page/order-wizard/address-policy';
import { validateAddress } from '../../src/shipping-orders-page/order-wizard/validation';
import { emptyWizardData } from '../../src/shipping-orders-page/order-wizard/wizard-data';

jest.mock( '@wordpress/api-fetch' );

const ORDERS_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders';
const COUNTRIES = { RU: 'Россия', KZ: 'Казахстан' };

const rule = ( overrides = {} ) => ( { required: false, hidden: false, removed: false, ...overrides } );

/** What the route answers for a country whose checkout wants a street and a postcode, and a plain region. */
const POLICY_FIELDS = {
	country: rule( { required: true } ),
	state: rule(),
	city: rule( { required: true } ),
	address_1: rule( { required: true } ),
	address_2: rule(),
	postcode: rule( { required: true } ),
};

const filled = ( shipping = {} ) => {
	const data = emptyWizardData( 'RU' );

	data.shipping = { ...data.shipping, country: 'RU', city: 'Москва', address_1: 'ул Тверская 1', postcode: '125009', ...shipping };

	return data;
};

describe( 'normalizeAddressPolicy', () => {
	test( 'an empty answer is «no rule» — an empty PHP array arrives as [], an empty object as {}', () => {
		expect( normalizeAddressPolicy( [] ) ).toBeNull();
		expect( normalizeAddressPolicy( {} ) ).toBeNull();
		expect( normalizeAddressPolicy( undefined ) ).toBeNull();
		expect( normalizeAddressPolicy( null ) ).toBeNull();
		expect( normalizeAddressPolicy( 'nonsense' ) ).toBeNull();
	} );

	test( 'reads every field the server sent and keeps the stand-in for one it left out', () => {
		const policy = normalizeAddressPolicy( { postcode: rule( { hidden: true, removed: true } ), address_1: rule( { required: true } ) } );

		expect( policy.postcode ).toEqual( { required: false, hidden: true, removed: true } );
		expect( policy.address_1 ).toEqual( { required: true, hidden: false, removed: false } );
		expect( policy.city ).toEqual( FALLBACK_POLICY.city );
	} );

	test( 'only a literal true counts — a stray string never makes a field required', () => {
		const policy = normalizeAddressPolicy( { postcode: { required: 'yes', hidden: 1, removed: 'true' } } );

		expect( policy.postcode ).toEqual( { required: false, hidden: false, removed: false } );
	} );

	test( 'ruleOf falls back to a country and a city before the server has answered', () => {
		expect( ruleOf( null, 'city' ).required ).toBe( true );
		expect( ruleOf( undefined, 'country' ).required ).toBe( true );
		expect( ruleOf( null, 'postcode' ) ).toEqual( { required: false, hidden: false, removed: false } );
	} );
} );

describe( 'validateAddress with the checkout policy', () => {
	const policy = normalizeAddressPolicy( POLICY_FIELDS );

	test( 'a required field left empty is reported on its own path in the server\'s words', () => {
		const errors = validateAddress( filled( { address_1: '', postcode: '' } ), COUNTRIES, {}, policy );

		expect( errors[ 'shipping.address_1' ] ).toEqual( [ 'Укажите улицу и дом.' ] );
		expect( errors[ 'shipping.postcode' ] ).toEqual( [ 'Укажите индекс.' ] );
		expect( Object.keys( errors ) ).toHaveLength( 2 );
	} );

	test( 'a whitespace-only value counts as empty', () => {
		expect( validateAddress( filled( { postcode: '   ' } ), COUNTRIES, {}, policy )[ 'shipping.postcode' ] ).toEqual( [ 'Укажите индекс.' ] );
	} );

	test( 'a field the policy hides is never asked, even one that reads required', () => {
		const hidden = normalizeAddressPolicy( { ...POLICY_FIELDS, postcode: rule( { required: true, hidden: true } ), address_1: rule( { hidden: true } ) } );

		expect( validateAddress( filled( { address_1: '', postcode: '' } ), COUNTRIES, {}, hidden ) ).toEqual( {} );
	} );

	test.each( [
		[ 'hidden', rule( { hidden: true } ) ],
		[ 'removed', rule( { hidden: true, removed: true } ) ],
	] )( 'a %s region with a stored invalid code is not checked', ( mode, state ) => {
		const policy = normalizeAddressPolicy( { ...POLICY_FIELDS, state } );

		expect( validateAddress( filled( { state: 'MOW' } ), COUNTRIES, { RU: { MOS: 'Москва' } }, policy ) ).toEqual( {} );
	} );

	test( 'a visible region with a stored invalid code is checked', () => {
		const policy = normalizeAddressPolicy( { ...POLICY_FIELDS, state: rule() } );

		expect( validateAddress( filled( { state: 'MOW' } ), COUNTRIES, { RU: { MOS: 'Москва' } }, policy )[ 'shipping.state' ] ).toEqual( [
			'Такого региона нет в справочнике магазина для выбранной страны.',
		] );
	} );

	test( 'a required region and a required flat are checked too', () => {
		const strict = normalizeAddressPolicy( { ...POLICY_FIELDS, state: rule( { required: true } ), address_2: rule( { required: true } ) } );
		const errors = validateAddress( filled(), COUNTRIES, {}, strict );

		expect( errors[ 'shipping.state' ] ).toEqual( [ 'Укажите регион доставки.' ] );
		expect( errors[ 'shipping.address_2' ] ).toEqual( [ 'Укажите квартиру или офис.' ] );
	} );

	test( 'without a policy only the country and the city are required — the stand-in', () => {
		expect( validateAddress( filled( { address_1: '', postcode: '' } ), COUNTRIES, {}, null ) ).toEqual( {} );
		expect( validateAddress( filled( { city: '' } ), COUNTRIES, {} )[ 'shipping.city' ] ).toEqual( [ 'Укажите город или населённый пункт.' ] );
		expect( validateAddress( filled( { country: '' } ), COUNTRIES, {} )[ 'shipping.country' ] ).toEqual( [ 'Укажите страну доставки.' ] );
	} );
} );

describe( 'fetchAddressPolicy', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		window.woodevShippingOrders = { restRoot: ORDERS_ROOT, nonce: 'nonce-1', providers: [], wizard: {} };
	} );

	afterEach( () => {
		delete window.woodevShippingOrders;
	} );

	test.each( [
		[ false, '0' ],
		[ true, '1' ],
	] )( 'asks the route with the country and the tariff kind (pickup %s)', async ( pickup, flag ) => {
		apiFetch.mockResolvedValue( { country: 'RU', pickup, fields: POLICY_FIELDS } );

		await fetchAddressPolicy( 'RU', pickup );

		const request = apiFetch.mock.calls[ 0 ][ 0 ];

		expect( request.url ).toBe( `${ ORDERS_ROOT }/address-policy?country=RU&pickup=${ flag }` );
		expect( request.method ).toBe( 'GET' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );
	} );
} );

describe( 'step ② Адрес under the checkout policy', () => {
	const policyApi = ( fields ) => {
		apiFetch.mockImplementation( ( request ) => {
			if ( request.url.includes( '/address-policy' ) ) {
				return 'function' === typeof fields ? fields( request ) : Promise.resolve( { country: 'RU', pickup: false, fields } );
			}

			return Promise.reject( { message: `unexpected request ${ request.url }` } );
		} );
	};

	const asked = () => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => r.url.includes( '/address-policy' ) );
	const next = () => fireEvent.click( screen.getByRole( 'button', { name: 'Далее' } ) );

	/** Step ①, «Далее», then step ② on screen. */
	const toAddress = async () => {
		render( createElement( OrderWizard, { onClose: jest.fn(), onSaved: jest.fn() } ) );
		next();
		await screen.findByText( 'Куда доставить' );
	};

	beforeEach( () => {
		apiFetch.mockReset();
		window.woodevShippingOrders = {
			restRoot: ORDERS_ROOT,
			nonce: 'nonce-1',
			providers: [],
			wizard: {
				countries: COUNTRIES,
				states: {},
				defaultCountry: 'RU',
				currency: { code: 'RUB', symbol: '₽' },
			},
		};
	} );

	afterEach( () => {
		delete window.woodevShippingOrders;
	} );

	test( 'the rules are asked once step ② is on screen — never before — for the shop\'s country', async () => {
		policyApi( POLICY_FIELDS );

		render( createElement( OrderWizard, { onClose: jest.fn(), onSaved: jest.fn() } ) );

		expect( asked() ).toHaveLength( 0 );

		next();
		await screen.findByText( 'Куда доставить' );
		await waitFor( () => expect( asked() ).toHaveLength( 1 ) );

		expect( asked()[ 0 ].url ).toBe( `${ ORDERS_ROOT }/address-policy?country=RU&pickup=0` );
	} );

	test( 'a required field is marked, and «Далее» stops on an empty one with the server\'s sentence', async () => {
		policyApi( POLICY_FIELDS );
		await toAddress();

		await waitFor( () => expect( screen.getByLabelText( 'Улица, дом' ) ).toBeRequired() );

		expect( screen.getByLabelText( 'Индекс' ) ).toBeRequired();
		expect( screen.getByLabelText( 'Город или населённый пункт' ) ).toBeRequired();
		expect( screen.getByLabelText( 'Квартира, офис' ) ).not.toBeRequired();
		expect( screen.getByLabelText( 'Индекс' ).closest( '.woodev-order-wizard__field' ) ).toHaveClass( 'is-required' );
		expect( screen.getByLabelText( 'Квартира, офис' ).closest( '.woodev-order-wizard__field' ) ).not.toHaveClass( 'is-required' );

		fireEvent.change( screen.getByLabelText( 'Город или населённый пункт' ), { target: { value: 'Москва' } } );
		next();

		expect( screen.getByText( 'Укажите улицу и дом.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Укажите индекс.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Что в заказе' ) ).toBeNull();

		fireEvent.change( screen.getByLabelText( 'Улица, дом' ), { target: { value: 'ул Тверская 1' } } );
		fireEvent.change( screen.getByLabelText( 'Индекс' ), { target: { value: '125009' } } );
		next();

		expect( await screen.findByText( 'Что в заказе' ) ).toBeInTheDocument();
	} );

	test( 'a field the checkout removes is not drawn, and is not asked', async () => {
		policyApi( { ...POLICY_FIELDS, state: rule( { hidden: true, removed: true } ), postcode: rule( { hidden: true, removed: true } ) } );
		await toAddress();

		await waitFor( () => expect( screen.queryByLabelText( 'Индекс' ) ).toBeNull() );

		expect( screen.queryByLabelText( 'Регион' ) ).toBeNull();
		expect( screen.getByLabelText( 'Улица, дом' ) ).toBeInTheDocument();

		fireEvent.change( screen.getByLabelText( 'Город или населённый пункт' ), { target: { value: 'Москва' } } );
		fireEvent.change( screen.getByLabelText( 'Улица, дом' ), { target: { value: 'ул Тверская 1' } } );
		next();

		expect( await screen.findByText( 'Что в заказе' ) ).toBeInTheDocument();
	} );

	test( 'a hidden street takes its search button with it, the rest of the address stays', async () => {
		policyApi( { ...POLICY_FIELDS, address_1: rule( { hidden: true } ), postcode: rule( { hidden: true } ) } );
		await toAddress();

		await waitFor( () => expect( screen.queryByLabelText( 'Улица, дом' ) ).toBeNull() );

		expect( screen.queryByRole( 'button', { name: 'Найти адрес…' } ) ).toBeNull();
		expect( screen.getByRole( 'button', { name: 'Найти населённый пункт…' } ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Квартира, офис' ) ).toBeInTheDocument();
	} );

	test( 'a failed request leaves the step asking for a country and a city, every field shown', async () => {
		policyApi( () => Promise.reject( { message: 'boom' } ) );
		await toAddress();

		await waitFor( () => expect( asked() ).toHaveLength( 1 ) );

		expect( screen.getByLabelText( 'Индекс' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Улица, дом' ) ).not.toBeRequired();

		fireEvent.change( screen.getByLabelText( 'Город или населённый пункт' ), { target: { value: 'Москва' } } );
		next();

		expect( await screen.findByText( 'Что в заказе' ) ).toBeInTheDocument();
	} );

	test( 'an empty answer (no WooCommerce on the server) is no rule either', async () => {
		policyApi( [] );
		await toAddress();

		await waitFor( () => expect( asked() ).toHaveLength( 1 ) );

		expect( screen.getByLabelText( 'Индекс' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Регион' ) ).toBeInTheDocument();
	} );

	test( 'another country asks the route again; a country already asked is not asked twice', async () => {
		policyApi( POLICY_FIELDS );
		await toAddress();
		await waitFor( () => expect( asked() ).toHaveLength( 1 ) );

		fireEvent.change( screen.getByLabelText( 'Страна' ), { target: { value: 'KZ' } } );
		await waitFor( () => expect( asked() ).toHaveLength( 2 ) );

		expect( asked()[ 1 ].url ).toBe( `${ ORDERS_ROOT }/address-policy?country=KZ&pickup=0` );

		fireEvent.change( screen.getByLabelText( 'Страна' ), { target: { value: 'RU' } } );

		expect( asked() ).toHaveLength( 2 );
	} );
} );
