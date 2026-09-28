/**
 * Tests for the order wizard's pure state helpers (#969): prefill → state, state → payload,
 * a picked customer → state, the region-name → state-code match and the item arithmetic.
 * The payload shape is the one `Order_Payload_Validator` documents — these pin it from the
 * client side, so a rename on either side fails a test instead of a customer's order.
 *
 * @see src/shipping-orders-page/order-wizard/wizard-data.ts
 */

import {
	applyCustomer,
	buildPayload,
	clearCustomer,
	emptyWizardData,
	formatMoney,
	isDirty,
	itemsSubtotal,
	matchState,
	newItem,
	prefillToData,
} from '../../src/shipping-orders-page/order-wizard/wizard-data';

const STATES = { МОСКВА: 'Москва', 'МОСКОВСКАЯ ОБЛАСТЬ': 'Московская область', 'САНКТ-ПЕТЕРБУРГ': 'Санкт-Петербург' };

describe( 'emptyWizardData', () => {
	test( 'starts as a guest, on the shop default country, billing following delivery', () => {
		const data = emptyWizardData( 'RU' );

		expect( data.customer ).toEqual( { id: 0, create_account: false, label: '' } );
		expect( data.shipping.country ).toBe( 'RU' );
		expect( data.billing.country ).toBe( 'RU' );
		expect( data.billingFollowsShipping ).toBe( true );
		expect( data.items ).toEqual( [] );
	} );
} );

describe( 'buildPayload', () => {
	const base = () => {
		const data = emptyWizardData( 'RU' );
		data.billing = { ...data.billing, first_name: 'Иван', last_name: 'Петров', phone: '+79990000000', email: 'i@example.test' };
		data.shipping = { ...data.shipping, city: 'Москва', state: 'МОСКВА', address_1: 'ул Тверская 1', postcode: '125009' };
		data.items = [ { ...newItem( { product_id: 12, name: 'Кружка', price: '1000' } ), quantity: '2' } ];

		return data;
	};

	test( 'sends the validator contract keys, quantity as a number and price as typed', () => {
		const payload = buildPayload( base() );

		expect( Object.keys( payload ).sort() ).toEqual(
			[ 'billing', 'carrier_fields', 'customer', 'fields', 'items', 'payment_method', 'pickup_point', 'shipping', 'shipping_line' ].sort()
		);
		expect( payload.items ).toEqual( [ { product_id: 12, variation_id: 0, quantity: 2, price: '1000' } ] );
		expect( payload.customer ).toEqual( { id: 0, create_account: false } );
	} );

	test( 'export_now (D6, #974) rides beside the order only when ticked — never as an empty flag', () => {
		expect( buildPayload( base() ) ).not.toHaveProperty( 'export_now' );

		const data = base();
		data.rest = { ...data.rest, export_now: true };

		expect( buildPayload( data ).export_now ).toBe( true );
	} );

	test( 'billing address follows the delivery address; names and email stay step ①\'s', () => {
		const payload = buildPayload( base() );

		expect( payload.billing ).toMatchObject( { first_name: 'Иван', email: 'i@example.test', city: 'Москва', state: 'МОСКВА', postcode: '125009' } );
	} );

	test( 'the recipient falls back to the customer\'s name and phone', () => {
		const payload = buildPayload( base() );

		expect( payload.shipping ).toMatchObject( { first_name: 'Иван', last_name: 'Петров', phone: '+79990000000', city: 'Москва' } );
	} );

	test( 'a separate billing address is kept as loaded', () => {
		const data = base();
		data.billingFollowsShipping = false;
		data.billing = { ...data.billing, city: 'Тверь' };

		expect( buildPayload( data ).billing ).toMatchObject( { city: 'Тверь' } );
	} );

	test( 'an existing line keeps its item_id, a new one sends none; status is omitted until chosen', () => {
		const data = base();
		data.items = [ { ...data.items[ 0 ], item_id: 77 }, { ...newItem( { product_id: 5, variation_id: 9, name: 'Футболка', price: '10' } ) } ];

		const payload = buildPayload( data );

		expect( payload.items[ 0 ].item_id ).toBe( 77 );
		expect( payload.items[ 1 ] ).not.toHaveProperty( 'item_id' );
		expect( payload.items[ 1 ].variation_id ).toBe( 9 );
		expect( payload ).not.toHaveProperty( 'status' );

		data.rest.status = 'processing';
		expect( buildPayload( data ).status ).toBe( 'processing' );
	} );
} );

describe( 'prefillToData', () => {
	const prefill = ( overrides = {} ) => ( {
		order: { id: 7, number: '7', status: 'processing', status_name: 'В обработке', is_paid: true, total: '2100.00', currency: 'RUB' },
		carrier: 'cdek',
		customer: { id: 3, create_account: false },
		billing: { first_name: 'Анна', last_name: 'Ким', phone: '+7', email: 'a@example.test', city: 'Москва', country: 'RU', state: 'МОСКВА' },
		shipping: { city: 'Москва', country: 'RU', state: 'МОСКВА' },
		items: [ { item_id: 11, product_id: 12, variation_id: 0, name: 'Кружка', quantity: 2, price: '1000.00' } ],
		shipping_line: { method_id: 'cdek', instance_id: 1, cost: '100' },
		pickup_point: null,
		fields: { a: 'b' },
		carrier_fields: {},
		payment_method: 'cod',
		status: 'processing',
		...overrides,
	} );

	test( 'maps the load route\'s shape onto the wizard state, steps ④/⑤ values carried through', () => {
		const data = prefillToData( prefill() );

		expect( data.customer.id ).toBe( 3 );
		expect( data.billing.email ).toBe( 'a@example.test' );
		expect( data.items[ 0 ] ).toMatchObject( { item_id: 11, product_id: 12, quantity: '2', price: '1000.00', name: 'Кружка' } );
		expect( data.rest.shipping_line ).toEqual( { method_id: 'cdek', instance_id: 1, cost: '100' } );
		expect( data.rest.payment_method ).toBe( 'cod' );
		expect( data.rest.status ).toBe( 'processing' );
		expect( data.rest.fields ).toEqual( { a: 'b' } );
	} );

	test( 'an order with no shipping address of its own delivers to its billing one', () => {
		const data = prefillToData( prefill( { shipping: {} } ) );

		expect( data.shipping.city ).toBe( 'Москва' );
		expect( data.shipping.country ).toBe( 'RU' );
		expect( data.billingFollowsShipping ).toBe( true );
	} );

	test( 'billing stays separate only when it really differed', () => {
		const data = prefillToData( prefill( { shipping: { city: 'Тверь', country: 'RU', state: '' } } ) );

		expect( data.billingFollowsShipping ).toBe( false );
		expect( buildPayload( data ).billing.city ).toBe( 'Москва' );
	} );

	test( 'an untouched prefill is not dirty; an edit is', () => {
		const data = prefillToData( prefill() );

		expect( isDirty( data, { ...data } ) ).toBe( false );
		expect( isDirty( data, { ...data, billing: { ...data.billing, phone: '1' } } ) ).toBe( true );
	} );
} );

describe( 'applyCustomer / clearCustomer', () => {
	const record = {
		id: 5,
		email: 'a@example.test',
		first_name: 'Анна',
		last_name: 'Ким',
		billing: { first_name: 'Анна', last_name: 'Ким', phone: '+7900', email: 'a@example.test', city: 'Казань', country: 'RU', state: '' },
		shipping: { first_name: 'Анна', last_name: 'Ким', city: 'Тверь', country: 'RU', state: '', address_1: 'ул Ленина 2' },
	};

	test( 'identity goes to billing and the delivery address is the customer\'s shipping address', () => {
		const data = applyCustomer( emptyWizardData( 'RU' ), record );

		expect( data.customer ).toEqual( { id: 5, create_account: false, label: 'Анна Ким (a@example.test)' } );
		expect( data.billing ).toMatchObject( { first_name: 'Анна', phone: '+7900', email: 'a@example.test' } );
		expect( data.shipping ).toMatchObject( { city: 'Тверь', address_1: 'ул Ленина 2' } );
	} );

	test( 'a customer with no shipping address falls back to the billing one', () => {
		const data = applyCustomer( emptyWizardData( 'RU' ), { ...record, shipping: {} } );

		expect( data.shipping.city ).toBe( 'Казань' );
	} );

	test( 'a customer with no saved address keeps the wizard\'s country', () => {
		const data = applyCustomer( emptyWizardData( 'KZ' ), { id: 9, email: 'x@y.z', billing: {}, shipping: {} } );

		expect( data.shipping.country ).toBe( 'KZ' );
		expect( data.shipping.city ).toBe( '' );
	} );

	test( 'clearCustomer goes back to a guest and keeps what was typed', () => {
		const picked = applyCustomer( emptyWizardData( 'RU' ), record );
		const cleared = clearCustomer( picked );

		expect( cleared.customer.id ).toBe( 0 );
		expect( cleared.billing.first_name ).toBe( 'Анна' );
	} );
} );

describe( 'matchState', () => {
	test( 'matches a region name to exactly one WooCommerce state code', () => {
		expect( matchState( STATES, 'Москва' ) ).toBe( 'МОСКВА' );
		expect( matchState( STATES, 'Московская' ) ).toBe( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		expect( matchState( STATES, 'санкт-петербург' ) ).toBe( 'САНКТ-ПЕТЕРБУРГ' );
	} );

	test( 'an unknown name, an empty name, or a country without regions maps to nothing', () => {
		expect( matchState( STATES, 'Тверская' ) ).toBe( '' );
		expect( matchState( STATES, '' ) ).toBe( '' );
		expect( matchState( undefined, 'Москва' ) ).toBe( '' );
	} );

	test( 'an ambiguous prefix maps to nothing rather than a guess', () => {
		expect( matchState( { A1: 'Северная область', A2: 'Северный край' }, 'Северн' ) ).toBe( '' );
	} );
} );

describe( 'items arithmetic', () => {
	test( 'subtotal is quantity × price over the lines; an unparseable line counts as zero', () => {
		const lines = [
			{ ...newItem( { product_id: 1, name: 'a', price: '10.50' } ), quantity: '2' },
			{ ...newItem( { product_id: 2, name: 'b', price: '5' } ), quantity: '3' },
			{ ...newItem( { product_id: 3, name: 'c', price: 'abc' } ), quantity: '1' },
		];

		expect( itemsSubtotal( lines ) ).toBeCloseTo( 36 );
	} );

	test( 'formatMoney appends the shop symbol when known', () => {
		expect( formatMoney( 1234.5, '₽' ) ).toMatch( /1\s?234,50\s₽/ );
		expect( formatMoney( 5 ) ).toMatch( /^5,00$/ );
	} );
} );
