/**
 * The delivery step's pure state rules (#970, #710 D2 / D3 / O8): what the rates request is
 * built from, how a chosen tariff becomes the payload's `shipping_line`, how the editable price
 * relates to the carrier's own, and what a fresh answer does to a tariff that was already chosen.
 *
 * @see src/shipping-orders-page/order-wizard/delivery-state.ts
 */

import {
	applyRates,
	buildRatesRequest,
	chooseRate,
	chosenRateId,
	decodeEntities,
	findRate,
	isChosenRateGone,
	isCostOverridden,
	pickedPointId,
	pickupContext,
	ratesRequestKey,
	resetDeliveryCost,
	scalarMeta,
	setDeliveryCost,
	setPickupPoint,
} from '../../src/shipping-orders-page/order-wizard/delivery-state';
import { emptyWizardData, isDirty, newItem } from '../../src/shipping-orders-page/order-wizard/wizard-data';
import { buildPayload } from '../../src/shipping-orders-page/order-wizard/wizard-data';

const COURIER = { id: 'cdek_courier:3', method_id: 'cdek_courier', instance_id: 3, label: 'Курьер', cost: 250.5, delivery_time: '2-3 дня', description: '', is_pickup: false, meta: { tariff_code: 137, nested: { a: 1 }, flag: true } };
const PVZ = { id: 'cdek_pvz:5', method_id: 'cdek_pvz', instance_id: 5, label: 'ПВЗ', cost: 120, delivery_time: '', description: '', is_pickup: true, meta: {} };

const answer = ( ...rates ) => ( {
	destination: {},
	needs_shipping: true,
	weight: 3250,
	zone: { id: 1, name: 'Россия' },
	providers: [ { id: 'cdek', label: 'СДЭК', rates } ],
} );

/** Steps ①–③ done: one mug at 1000, delivering to Moscow. */
const filled = () => {
	const data = emptyWizardData( 'RU' );

	data.customer = { id: 5, create_account: false, label: 'Анна' };
	data.shipping = { ...data.shipping, country: 'RU', state: 'МОСКВА', city: 'Москва', postcode: '101000', address_1: 'ул Тверская 1', address_2: 'кв 5' };
	data.items = [ { ...newItem( { product_id: 12, name: 'Кружка', price: '1000' } ), quantity: '2' } ];

	return data;
};

describe( 'buildRatesRequest (D2)', () => {
	test( 'carries the lines at their EDITED price, the destination with WooCommerce codes, and the customer', () => {
		const data = filled();
		data.items[ 0 ].price = '900.50';

		expect( buildRatesRequest( data ) ).toEqual( {
			items: [ { product_id: 12, variation_id: 0, quantity: 2, price: 900.5 } ],
			// The calculator reads `address`, not `address_1` (`Admin_Rate_Calculator::normalize_destination()`).
			destination: { country: 'RU', state: 'МОСКВА', city: 'Москва', postcode: '101000', address: 'ул Тверская 1', address_2: 'кв 5' },
			customer_id: 5,
		} );
	} );

	test( 'hands over the settlement record the manager picked, whole, as `location`', () => {
		const record = { key: 'dadata:77', level: 'settlement', settlement: { name: 'Москва', type: 'г' } };
		const data = { ...filled(), settlementRecord: record };

		expect( buildRatesRequest( data ).location ).toBe( record );
	} );

	test( 'leaves `location` out when the city was typed by hand', () => {
		expect( buildRatesRequest( filled() ) ).not.toHaveProperty( 'location' );
	} );

	test( 'is null while it cannot ask: no country, or nothing in the order', () => {
		const noCountry = filled();
		noCountry.shipping.country = ' ';

		expect( buildRatesRequest( noCountry ) ).toBeNull();
		expect( buildRatesRequest( { ...filled(), items: [] } ) ).toBeNull();
	} );

	test( 'leaves a line with no usable price to the product\'s own, and drops a line that is not a product', () => {
		const data = filled();
		data.items = [
			{ ...data.items[ 0 ], price: '' },
			{ ...newItem( { product_id: 0, name: 'Пусто', price: '5' } ) },
		];

		expect( buildRatesRequest( data ).items ).toEqual( [ { product_id: 12, variation_id: 0, quantity: 2 } ] );
	} );

	test( 'the key changes only when the request does', () => {
		const a = ratesRequestKey( buildRatesRequest( filled() ) );
		const b = ratesRequestKey( buildRatesRequest( filled() ) );
		const moved = filled();
		moved.shipping.city = 'Тверь';

		expect( a ).toBe( b );
		expect( ratesRequestKey( buildRatesRequest( moved ) ) ).not.toBe( a );
		expect( ratesRequestKey( null ) ).toBe( '' );
	} );
} );

describe( 'scalarMeta', () => {
	test( 'keeps only what the payload accepts — scalars', () => {
		expect( scalarMeta( COURIER.meta ) ).toEqual( { tariff_code: 137, flag: true } );
		expect( scalarMeta( null ) ).toEqual( {} );
		expect( scalarMeta( 'x' ) ).toEqual( {} );
	} );
} );

describe( 'chooseRate (O8)', () => {
	test( 'writes the tariff as the payload wants it, at the carrier\'s own price', () => {
		const data = chooseRate( filled(), COURIER );

		expect( data.rest.shipping_line ).toEqual( {
			method_id: 'cdek_courier',
			instance_id: 3,
			rate_id: 'cdek_courier:3',
			label: 'Курьер',
			cost: '250.5',
			meta: { tariff_code: 137, flag: true },
		} );
		expect( data.rest.rate_cost ).toBe( '250.5' );
		expect( data.rest.rate_is_pickup ).toBe( false );
		expect( chosenRateId( data.rest ) ).toBe( 'cdek_courier:3' );
	} );

	test( 'a different tariff drops the point that belonged to the previous one', () => {
		let data = chooseRate( filled(), PVZ );
		data = setPickupPoint( data, { id: 'P-1' } );

		expect( pickedPointId( data.rest ) ).toBe( 'P-1' );
		expect( chooseRate( data, COURIER ).rest.pickup_point ).toBeNull();
	} );

	test( 'choosing the tariff that is already chosen changes nothing — a typed price survives a re-click', () => {
		const chosen = setDeliveryCost( chooseRate( filled(), COURIER ), '199' );

		expect( chooseRate( chosen, COURIER ) ).toBe( chosen );
	} );
} );

describe( 'the editable delivery price (O8)', () => {
	test( 'is «overridden» only while it differs from the carrier\'s own — compared as numbers', () => {
		const data = chooseRate( filled(), COURIER );

		expect( isCostOverridden( data.rest ) ).toBe( false );
		expect( isCostOverridden( setDeliveryCost( data, '250.50' ).rest ) ).toBe( false );
		expect( isCostOverridden( setDeliveryCost( data, '0' ).rest ) ).toBe( true );
		// An empty field is not a price the manager chose; the validator says so.
		expect( isCostOverridden( setDeliveryCost( data, '' ).rest ) ).toBe( false );
	} );

	test( 'the carrier\'s own price comes back with one action', () => {
		const typed = setDeliveryCost( chooseRate( filled(), COURIER ), '0' );

		expect( resetDeliveryCost( typed ).rest.shipping_line.cost ).toBe( '250.5' );
	} );

	test( 'setting a price with no tariff chosen is a no-op', () => {
		const data = filled();

		expect( setDeliveryCost( data, '10' ) ).toBe( data );
		expect( resetDeliveryCost( data ) ).toBe( data );
	} );

	test( 'the FINAL price is what the payload carries', () => {
		const data = setDeliveryCost( chooseRate( filled(), COURIER ), '0' );

		expect( buildPayload( data ).shipping_line.cost ).toBe( '0' );
	} );
} );

describe( 'applyRates — a fresh answer against a tariff already chosen', () => {
	test( 'nothing chosen: nothing to reconcile, the very same state comes back', () => {
		const data = filled();

		expect( applyRates( data, answer( COURIER ) ) ).toBe( data );
	} );

	test( 'the tariff is no longer offered: the line and its point are dropped, and the step can say why', () => {
		const chosen = setPickupPoint( chooseRate( filled(), PVZ ), { id: 'P-1' } );
		const fresh = answer( COURIER );

		expect( isChosenRateGone( chosen, fresh ) ).toBe( true );

		const data = applyRates( chosen, fresh );

		expect( data.rest.shipping_line ).toBeNull();
		expect( data.rest.pickup_point ).toBeNull();
		expect( data.rest.rate_cost ).toBe( '' );
		expect( data.rest.rate_is_pickup ).toBe( false );
	} );

	test( 'the tariff is still offered at the same price: the very same state comes back', () => {
		const chosen = chooseRate( filled(), COURIER );

		expect( isChosenRateGone( chosen, answer( COURIER ) ) ).toBe( false );
		expect( applyRates( chosen, answer( COURIER ) ) ).toBe( chosen );
	} );

	test( 'the carrier re-quoted: the price follows it — while the manager has not typed their own', () => {
		const chosen = chooseRate( filled(), COURIER );
		const data = applyRates( chosen, answer( { ...COURIER, cost: 300 } ) );

		expect( data.rest.shipping_line.cost ).toBe( '300' );
		expect( data.rest.rate_cost ).toBe( '300' );
	} );

	test( 'a price the manager typed is theirs: a re-quote never overwrites it, but the reference moves', () => {
		const typed = setDeliveryCost( chooseRate( filled(), COURIER ), '199' );
		const data = applyRates( typed, answer( { ...COURIER, cost: 300 } ) );

		expect( data.rest.shipping_line.cost ).toBe( '199' );
		expect( data.rest.rate_cost ).toBe( '300' );
		expect( isCostOverridden( data.rest ) ).toBe( true );
	} );

	test( 'an order loaded for edit keeps its saved price and gets the reference the first time the rates land', () => {
		const loaded = filled();
		loaded.rest = {
			...loaded.rest,
			shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '180', meta: {} },
			rate_cost: '',
		};

		const data = applyRates( loaded, answer( COURIER ) );

		expect( data.rest.shipping_line.cost ).toBe( '180' );
		expect( data.rest.rate_cost ).toBe( '250.5' );
		expect( isCostOverridden( data.rest ) ).toBe( true );
	} );

	test( 'the tariff\'s own label and meta follow the fresh answer', () => {
		const chosen = chooseRate( filled(), COURIER );
		const data = applyRates( chosen, answer( { ...COURIER, label: 'Курьер до двери', meta: { tariff_code: 138 } } ) );

		expect( data.rest.shipping_line.label ).toBe( 'Курьер до двери' );
		expect( data.rest.shipping_line.meta ).toEqual( { tariff_code: 138 } );
	} );

	test( 'a tariff that turned into a non-pickup one no longer carries a point', () => {
		const chosen = setPickupPoint( chooseRate( filled(), PVZ ), { id: 'P-1' } );
		const data = applyRates( chosen, answer( { ...PVZ, is_pickup: false } ) );

		expect( data.rest.pickup_point ).toBeNull();
		expect( data.rest.rate_is_pickup ).toBe( false );
	} );
} );

describe( 'findRate', () => {
	test( 'finds a tariff across carriers, and reports its carrier', () => {
		const response = {
			...answer( COURIER ),
			providers: [ { id: 'cdek', label: 'СДЭК', rates: [ COURIER ] }, { id: 'yandex', label: 'Яндекс', rates: [ PVZ ] } ],
		};

		expect( findRate( response, 'cdek_pvz:5' ).group.id ).toBe( 'yandex' );
		expect( findRate( response, 'nope' ) ).toBeNull();
		expect( findRate( null, 'cdek_pvz:5' ) ).toBeNull();
		expect( findRate( response, '' ) ).toBeNull();
	} );
} );

describe( 'the pickup point and what the routes are told (D3)', () => {
	test( 'a chosen point keeps its id and, for display, its name and address', () => {
		const data = setPickupPoint( filled(), { id: 'P-1', name: 'ПВЗ 1', address: 'ул Тверская 1' } );

		expect( data.rest.pickup_point ).toEqual( { id: 'P-1', name: 'ПВЗ 1', address: 'ул Тверская 1' } );
		expect( pickedPointId( data.rest ) ).toBe( 'P-1' );
		expect( setPickupPoint( data, null ).rest.pickup_point ).toBeNull();
		expect( setPickupPoint( data, { id: '' } ).rest.pickup_point ).toBeNull();
	} );

	test( 'the routes get the weight in grams, the payment method once chosen, and the destination record', () => {
		const record = { key: 'dadata:77' };
		const data = { ...filled(), settlementRecord: record };
		data.rest = { ...data.rest, payment_method: 'cod' };

		expect( pickupContext( data, 3250 ) ).toEqual( { weight: 3250, payment_method: 'cod', location: record } );
	} );

	test( 'an unknown payment method and no record are left out — absent reads as absent server-side', () => {
		expect( pickupContext( filled(), 0 ) ).toEqual( { weight: 0 } );
	} );

	test( 'point fields arrive HTML-escaped and are decoded for React text', () => {
		expect( decodeEntities( 'ПВЗ &quot;Ромашка&quot; &amp; Ко' ) ).toBe( 'ПВЗ "Ромашка" & Ко' );
		expect( decodeEntities( 'plain' ) ).toBe( 'plain' );
		expect( decodeEntities( '' ) ).toBe( '' );
	} );
} );

describe( 'isDirty ignores what the wizard derives on its own', () => {
	test( 'opening ④ and letting it look the tariffs up is not «the manager typed something»', () => {
		const initial = filled();
		const looked = {
			...initial,
			settlementRecord: { key: 'dadata:77' },
			rest: { ...initial.rest, rate_cost: '250.5', rate_is_pickup: true, rates_pending: true },
		};

		expect( isDirty( initial, looked ) ).toBe( false );
	} );

	test( 'choosing a tariff is', () => {
		const initial = filled();

		expect( isDirty( initial, chooseRate( initial, COURIER ) ) ).toBe( true );
	} );
} );
