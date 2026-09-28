/**
 * The carrier's own order fields (#973, #710 D7 / O13) — the pure state rules: what a chosen
 * tariff brings with it, what a fresh rates answer does to values already held, which fields are
 * shown, how «Далее» judges them, and that only what the manager set is held or sent.
 *
 * @see src/shipping-orders-page/order-wizard/delivery-state.ts
 * @see src/shipping-orders-page/order-wizard/validation.ts
 */

import {
	applyRates,
	carrierFieldValue,
	carrierFieldsOf,
	chooseRate,
	effectiveCarrierValues,
	setCarrierField,
	visibleCarrierFields,
} from '../../src/shipping-orders-page/order-wizard/delivery-state';
import { validateDelivery, stepOfField } from '../../src/shipping-orders-page/order-wizard/validation';
import { buildPayload, emptyWizardData, isDirty, newItem, prefillToData } from '../../src/shipping-orders-page/order-wizard/wizard-data';

/** What `Carrier_Field_Set::to_schema()` sends: `Field_Schema` entries plus the `id`. */
const DECLARED_VALUE = { id: 'declared_value', type: 'float', name: 'Объявленная ценность', controlType: 'number', min: 0, required: false, value: null };
const PACKAGE_TYPE = { id: 'package_type', type: 'string', name: 'Упаковка', controlType: 'select', options: { box: 'Коробка', envelope: 'Конверт' }, required: true, value: 'box' };
const CALL_BEFORE = { id: 'call_before', type: 'boolean', name: 'Позвонить перед доставкой', controlType: 'toggle', value: true };
const BOX_NOTE = {
	id: 'box_note',
	type: 'string',
	name: 'Что в коробке',
	controlType: 'text',
	required: true,
	value: null,
	show_if: { setting: 'package_type', operator: '=', value: 'box' },
};

const COURIER = {
	id: 'cdek_courier:3',
	method_id: 'cdek_courier',
	instance_id: 3,
	label: 'Курьер',
	cost: 250,
	delivery_time: '',
	description: '',
	is_pickup: false,
	meta: {},
	order_fields: [ DECLARED_VALUE, PACKAGE_TYPE, CALL_BEFORE ],
};
const PVZ = { ...COURIER, id: 'cdek_pvz:5', method_id: 'cdek_pvz', instance_id: 5, label: 'ПВЗ', is_pickup: true, order_fields: [ DECLARED_VALUE ] };
const PLAIN = { ...COURIER, id: 'plain:1', method_id: 'plain', instance_id: 1, label: 'Без полей', order_fields: undefined };

const answer = ( ...rates ) => ( {
	destination: {},
	needs_shipping: true,
	weight: 1000,
	zone: { id: 1, name: 'Россия' },
	providers: [ { id: 'cdek', label: 'СДЭК', rates } ],
} );

const filled = () => {
	const data = emptyWizardData( 'RU' );

	data.shipping = { ...data.shipping, country: 'RU', city: 'Москва', address_1: 'ул Тверская 1' };
	data.items = [ { ...newItem( { product_id: 12, name: 'Кружка', price: '1000' } ), quantity: '1' } ];

	return data;
};

describe( 'choosing a tariff brings its fields', () => {
	test( 'the tariff\'s definitions become the schema the step draws, and no value is written for an untouched field', () => {
		const data = chooseRate( filled(), COURIER );

		expect( data.rest.carrier_schema ).toEqual( [ DECLARED_VALUE, PACKAGE_TYPE, CALL_BEFORE ] );
		expect( data.rest.carrier_fields ).toEqual( {} );
	} );

	test( 'a tariff whose carrier declares nothing asks for nothing', () => {
		expect( carrierFieldsOf( PLAIN ) ).toEqual( [] );
		expect( chooseRate( filled(), PLAIN ).rest.carrier_schema ).toEqual( [] );
	} );

	test( 'another tariff drops what was typed for the previous one — its fields are its own', () => {
		const typed = setCarrierField( chooseRate( filled(), COURIER ), 'declared_value', '1500' );
		const other = chooseRate( typed, PVZ );

		expect( other.rest.carrier_fields ).toEqual( {} );
		expect( other.rest.carrier_schema ).toEqual( [ DECLARED_VALUE ] );
	} );

	test( 'a re-click on the chosen tariff keeps what was typed', () => {
		const typed = setCarrierField( chooseRate( filled(), COURIER ), 'declared_value', '1500' );

		expect( chooseRate( typed, COURIER ) ).toBe( typed );
	} );
} );

describe( 'an untouched field is its declared default', () => {
	test( 'the value shown and judged is what the manager set, else the default the carrier declared', () => {
		expect( carrierFieldValue( PACKAGE_TYPE, {} ) ).toBe( 'box' );
		expect( carrierFieldValue( PACKAGE_TYPE, { package_type: 'envelope' } ) ).toBe( 'envelope' );
		expect( carrierFieldValue( CALL_BEFORE, { call_before: false } ) ).toBe( false );
		expect( carrierFieldValue( DECLARED_VALUE, { declared_value: '' } ) ).toBe( '' );
	} );

	test( 'only what the manager set travels in the payload — the server applies the defaults to the rest', () => {
		let data = chooseRate( filled(), COURIER );

		expect( buildPayload( data ).carrier_fields ).toEqual( {} );

		data = setCarrierField( setCarrierField( data, 'declared_value', '1500' ), 'call_before', false );

		expect( buildPayload( data ).carrier_fields ).toEqual( { declared_value: '1500', call_before: false } );
	} );

	test( 'choosing a tariff and looking at its fields is not an edit', () => {
		const initial = filled();

		expect( isDirty( initial, { ...initial, rest: { ...initial.rest, carrier_schema: [ DECLARED_VALUE ] } } ) ).toBe( false );
		expect( isDirty( initial, setCarrierField( initial, 'declared_value', '1500' ) ) ).toBe( true );
	} );
} );

describe( 'a fresh rates answer', () => {
	test( 'an order loaded for edit keeps its saved values and gets the tariff\'s definitions', () => {
		const loaded = prefillToData( {
			order: { id: 9, number: '9', status: 'processing', status_name: 'В обработке', is_paid: false, total: '1250', currency: 'RUB' },
			carrier: 'cdek',
			customer: { id: 5, create_account: false },
			billing: { country: 'RU', city: 'Москва' },
			shipping: { country: 'RU', city: 'Москва', address_1: 'ул Тверская 1' },
			items: [ { item_id: 1, product_id: 12, variation_id: 0, name: 'Кружка', quantity: 1, price: '1000' } ],
			shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '250', meta: {} },
			pickup_point: null,
			fields: {},
			carrier_fields: { declared_value: 1500.5, call_before: false },
			payment_method: '',
			status: 'processing',
		} );

		expect( loaded.rest.carrier_schema ).toEqual( [] );

		const requoted = applyRates( loaded, answer( COURIER ) );

		expect( requoted.rest.carrier_fields ).toEqual( { declared_value: 1500.5, call_before: false } );
		expect( requoted.rest.carrier_schema ).toEqual( [ DECLARED_VALUE, PACKAGE_TYPE, CALL_BEFORE ] );
		expect( isDirty( loaded, requoted ) ).toBe( false );
	} );

	test( 'a value of a field the tariff no longer asks for is dropped', () => {
		const data = setCarrierField( setCarrierField( chooseRate( filled(), COURIER ), 'declared_value', '10' ), 'call_before', false );
		const requoted = applyRates( data, answer( { ...COURIER, order_fields: [ DECLARED_VALUE ] } ) );

		expect( requoted.rest.carrier_fields ).toEqual( { declared_value: '10' } );
		expect( requoted.rest.carrier_schema ).toEqual( [ DECLARED_VALUE ] );
	} );

	test( 'the very same answer changes nothing', () => {
		const data = chooseRate( filled(), COURIER );

		expect( applyRates( data, answer( COURIER ) ) ).toBe( data );
	} );

	test( 'a tariff that is gone takes its fields with it', () => {
		const data = setCarrierField( chooseRate( filled(), COURIER ), 'declared_value', '10' );
		const gone = applyRates( data, answer( PVZ ) );

		expect( gone.rest.shipping_line ).toBeNull();
		expect( gone.rest.carrier_fields ).toEqual( {} );
		expect( gone.rest.carrier_schema ).toEqual( [] );
	} );
} );

describe( 'which fields are shown', () => {
	test( 'a field whose show_if does not hold is not shown, judged against the effective values', () => {
		const schema = [ PACKAGE_TYPE, BOX_NOTE ];

		expect( visibleCarrierFields( schema, {} ).map( ( field ) => field.id ) ).toEqual( [ 'package_type', 'box_note' ] );
		expect( visibleCarrierFields( schema, { package_type: 'envelope' } ).map( ( field ) => field.id ) ).toEqual( [ 'package_type' ] );
		expect( effectiveCarrierValues( schema, { package_type: 'envelope' } ) ).toEqual( { package_type: 'envelope', box_note: null } );
	} );
} );

describe( '«Далее» judges the carrier\'s fields by the settings page\'s rules', () => {
	const onTariff = ( fields = {}, rate = COURIER, extra = [] ) => {
		const data = chooseRate( filled(), rate );

		return {
			...data,
			rest: { ...data.rest, carrier_fields: fields, carrier_schema: [ ...carrierFieldsOf( rate ), ...extra ] },
		};
	};

	test( 'a field with its default and an optional one left empty pass', () => {
		expect( validateDelivery( onTariff() ) ).toEqual( {} );
	} );

	test( 'a required field emptied is refused, on its own path, in the settings page\'s words', () => {
		expect( validateDelivery( onTariff( { package_type: '' } ) ) ).toEqual( { 'carrier_fields.package_type': [ 'Обязательное поле.' ] } );
	} );

	test( 'a number under the declared floor is refused', () => {
		expect( validateDelivery( onTariff( { declared_value: '-5' } ) ) ).toEqual( { 'carrier_fields.declared_value': [ 'Значение не меньше 0.' ] } );
	} );

	test( 'a required field hidden by its show_if does not block the order', () => {
		expect( validateDelivery( onTariff( { package_type: 'envelope' }, COURIER, [ BOX_NOTE ] ) ) ).toEqual( {} );
		expect( validateDelivery( onTariff( { package_type: 'box' }, COURIER, [ BOX_NOTE ] ) ) ).toEqual( { 'carrier_fields.box_note': [ 'Обязательное поле.' ] } );
	} );

	test( 'the problems belong to step ④, wherever they come from', () => {
		expect( stepOfField( 'carrier_fields.declared_value' ) ).toBe( 'delivery' );
	} );
} );
