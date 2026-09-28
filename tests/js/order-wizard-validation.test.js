/**
 * Tests for the order wizard's step validation and server-error routing (#969).
 *
 * The messages must equal `Order_Payload_Validator`'s own sentences: a problem caught before
 * the request has to read exactly as the server would have worded it.
 *
 * @see src/shipping-orders-page/order-wizard/validation.ts
 */

import {
	errorsOfStep,
	firstStepWithErrors,
	groupServerErrors,
	stepOfField,
	validateAddress,
	validateCustomer,
	validateItems,
	validateStep,
} from '../../src/shipping-orders-page/order-wizard/validation';
import { emptyWizardData, newItem } from '../../src/shipping-orders-page/order-wizard/wizard-data';

const COUNTRIES = { RU: 'Россия', KZ: 'Казахстан' };
const STATES = { RU: { МОСКВА: 'Москва' } };

describe( 'stepOfField', () => {
	test.each( [
		[ 'customer.id', 'customer' ],
		[ 'billing.email', 'customer' ],
		[ 'billing.first_name', 'customer' ],
		[ 'billing.phone', 'customer' ],
		[ 'billing.country', 'address' ],
		[ 'billing.city', 'address' ],
		[ 'shipping.state', 'address' ],
		[ 'shipping.postcode', 'address' ],
		[ 'items', 'items' ],
		[ 'items.2.quantity', 'items' ],
		[ 'shipping_line', 'delivery' ],
		[ 'shipping_line.method_id', 'delivery' ],
		[ 'pickup_point.id', 'delivery' ],
		[ 'payment_method', 'payment' ],
		[ 'status', 'payment' ],
	] )( '%s belongs to step %s', ( field, step ) => {
		expect( stepOfField( field ) ).toBe( step );
	} );
} );

describe( 'server error routing', () => {
	test( 'groups the 422 list by field path, several messages per field kept', () => {
		const grouped = groupServerErrors( [
			{ field: 'billing.email', code: 'invalid_email', message: 'Проверьте email покупателя.' },
			{ field: 'billing.email', code: 'email_exists', message: 'Покупатель с таким email уже есть — выберите его в списке.' },
			{ field: 'items.0.quantity', code: 'invalid_quantity', message: 'Количество должно быть не меньше единицы.' },
			{ nonsense: true },
		] );

		expect( Object.keys( grouped ) ).toEqual( [ 'billing.email', 'items.0.quantity' ] );
		expect( grouped[ 'billing.email' ] ).toHaveLength( 2 );
	} );

	test( 'firstStepWithErrors is the EARLIEST step that has one, -1 for none', () => {
		expect( firstStepWithErrors( {} ) ).toBe( -1 );
		expect( firstStepWithErrors( { 'items.0.price': [ 'x' ], 'shipping.city': [ 'y' ] } ) ).toBe( 1 );
		expect( firstStepWithErrors( { 'payment_method': [ 'x' ], 'billing.email': [ 'y' ] } ) ).toBe( 0 );
		expect( firstStepWithErrors( { 'billing.email': [] } ) ).toBe( -1 );
	} );

	test( 'errorsOfStep keeps only the fields of that step', () => {
		const errors = { 'billing.email': [ 'a' ], 'items.0.price': [ 'b' ] };

		expect( errorsOfStep( errors, 'items' ) ).toEqual( { 'items.0.price': [ 'b' ] } );
	} );
} );

describe( 'validateCustomer', () => {
	test( 'passes for a guest with nothing typed — the server asks for nothing more', () => {
		expect( validateCustomer( emptyWizardData( 'RU' ) ) ).toEqual( {} );
	} );

	test( 'create-account needs an email, in the server\'s words', () => {
		const data = emptyWizardData( 'RU' );
		data.customer.create_account = true;

		expect( validateCustomer( data ) ).toEqual( {
			'billing.email': [ 'Чтобы создать аккаунт, укажите email покупателя.' ],
		} );
	} );

	test( 'a malformed email is refused', () => {
		const data = emptyWizardData( 'RU' );
		data.billing.email = 'not-an-email';

		expect( validateCustomer( data ) ).toEqual( { 'billing.email': [ 'Проверьте email покупателя.' ] } );
	} );
} );

describe( 'validateAddress', () => {
	const address = ( patch ) => {
		const data = emptyWizardData( 'RU' );
		data.shipping = { ...data.shipping, city: 'Москва', ...patch };

		return data;
	};

	test( 'a country and a city are enough', () => {
		expect( validateAddress( address( {} ), COUNTRIES, STATES ) ).toEqual( {} );
	} );

	test( 'a missing country and a missing city are reported on their own fields', () => {
		const errors = validateAddress( address( { country: '', city: ' ' } ), COUNTRIES, STATES );

		expect( errors[ 'shipping.country' ] ).toEqual( [ 'Укажите страну доставки.' ] );
		expect( errors[ 'shipping.city' ] ).toEqual( [ 'Укажите город или населённый пункт.' ] );
	} );

	test( 'a region not in the country\'s list is refused; free text is fine where there is no list', () => {
		expect( validateAddress( address( { state: 'MOW' } ), COUNTRIES, STATES )[ 'shipping.state' ] ).toEqual( [
			'Такого региона нет в справочнике магазина для выбранной страны.',
		] );
		expect( validateAddress( address( { country: 'KZ', state: 'Алматы' } ), COUNTRIES, STATES ) ).toEqual( {} );
	} );

	test( 'a country outside the shop list is refused', () => {
		expect( validateAddress( address( { country: 'ZZ' } ), COUNTRIES, STATES )[ 'shipping.country' ] ).toEqual( [
			'Такой страны нет в справочнике магазина.',
		] );
	} );
} );

describe( 'validateItems', () => {
	test( 'an empty order is refused with the server\'s sentence', () => {
		expect( validateItems( emptyWizardData( 'RU' ) ) ).toEqual( { items: [ 'Добавьте в заказ хотя бы один товар.' ] } );
	} );

	test( 'quantity and price are checked per line, under the payload\'s own indexes', () => {
		const data = emptyWizardData( 'RU' );
		data.items = [
			{ ...newItem( { product_id: 1, name: 'ok', price: '10' } ), quantity: '1' },
			{ ...newItem( { product_id: 2, name: 'bad', price: '-5' } ), quantity: '0' },
			{ ...newItem( { product_id: 3, name: 'half', price: '' } ), quantity: '1.5' },
		];

		const errors = validateItems( data );

		expect( Object.keys( errors ).sort() ).toEqual( [ 'items.1.price', 'items.1.quantity', 'items.2.price', 'items.2.quantity' ] );
		expect( errors[ 'items.1.quantity' ] ).toEqual( [ 'Количество должно быть не меньше единицы.' ] );
		expect( errors[ 'items.1.price' ] ).toEqual( [ 'Цена должна быть числом не меньше нуля.' ] );
	} );

	test( 'a free item (price 0) is allowed — reshipments and gifts are a stated scenario', () => {
		const data = emptyWizardData( 'RU' );
		data.items = [ { ...newItem( { product_id: 1, name: 'gift', price: '0' } ), quantity: '1' } ];

		expect( validateItems( data ) ).toEqual( {} );
	} );
} );

describe( 'validateStep', () => {
	test( 'steps ④ and ⑤ have nothing to check yet', () => {
		expect( validateStep( 3, emptyWizardData( 'RU' ), COUNTRIES, STATES ) ).toEqual( {} );
		expect( validateStep( 4, emptyWizardData( 'RU' ), COUNTRIES, STATES ) ).toEqual( {} );
	} );
} );
