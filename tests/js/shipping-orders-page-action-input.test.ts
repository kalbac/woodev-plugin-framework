/**
 * The browser-side half of an order action's input fields (#1180): defaults in, payload out, and the checks that
 * need no server. The server re-checks the same declaration (`Order_Action_Fields::validate()`).
 *
 * @see src/shipping-orders-page/action-input.ts
 */

import { initialValues, reconcileValues, toPayload, validateInput } from '../../src/shipping-orders-page/action-input';
import type { OrderActionField } from '../../src/shipping-orders-page/rest';

const FIELDS: OrderActionField[] = [
	{ id: 'day', type: 'date', label: 'День', required: true, default: '2026-10-13', min: '2026-10-12', max: '2026-10-26' },
	{
		id: 'window',
		type: 'time_range',
		label: 'Время',
		required: true,
		default: { from: '09:00', to: '18:00' },
		min: '09:00',
		max: '21:00',
	},
	{
		id: 'service',
		type: 'select',
		label: 'Забор',
		required: false,
		default: '',
		options: [ { value: 'standard', label: 'Обычный' } ],
	},
	{ id: 'comment', type: 'textarea', label: 'Комментарий', required: false, default: '', maxlength: 10 },
];

describe( 'initialValues', () => {
	it( 'opens with the declared defaults', () => {
		expect( initialValues( FIELDS ) ).toEqual( {
			day: '2026-10-13',
			window: { from: '09:00', to: '18:00' },
			service: '',
			comment: '',
		} );
	} );

	it( 'hands a time range its own copy, so editing it never edits the declaration', () => {
		const values = initialValues( FIELDS );

		( values.window as { from: string } ).from = '10:00';

		expect( ( FIELDS[ 1 ] as { default: { from: string } } ).default.from ).toBe( '09:00' );
	} );
} );

describe( 'toPayload', () => {
	it( 'sends every declared field, an empty one as an empty string', () => {
		expect( toPayload( FIELDS, initialValues( FIELDS ) ) ).toEqual( {
			day: '2026-10-13',
			window: { from: '09:00', to: '18:00' },
			service: '',
			comment: '',
		} );
	} );

	it( 'sends nothing it was not asked for', () => {
		expect( Object.keys( toPayload( FIELDS, { day: 'x', extra: 'y' } ) ) ).toEqual( [ 'day', 'window', 'service', 'comment' ] );
	} );
} );

describe( 'validateInput', () => {
	const valid = () => initialValues( FIELDS );
	const codes = ( values: ReturnType< typeof valid > ) =>
		validateInput( FIELDS, values ).map( ( error ) => `${ error.field }:${ error.code }` );

	it( 'finds nothing wrong with the defaults', () => {
		expect( codes( valid() ) ).toEqual( [] );
	} );

	it( 'requires a required field', () => {
		expect( codes( { ...valid(), day: '  ' } ) ).toEqual( [ 'day:required' ] );
	} );

	it( 'does not require an optional one', () => {
		expect( codes( { ...valid(), service: '', comment: '' } ) ).toEqual( [] );
	} );

	it( 'keeps a date inside its inclusive bounds', () => {
		expect( codes( { ...valid(), day: '2026-10-11' } ) ).toEqual( [ 'day:out_of_range' ] );
		expect( codes( { ...valid(), day: '2026-10-27' } ) ).toEqual( [ 'day:out_of_range' ] );
		expect( codes( { ...valid(), day: '2026-10-12' } ) ).toEqual( [] );
		expect( codes( { ...valid(), day: '2026-10-26' } ) ).toEqual( [] );
	} );

	it( 'wants both ends of a window, in order, inside its bounds', () => {
		expect( codes( { ...valid(), window: { from: '', to: '' } } ) ).toEqual( [ 'window:required' ] );
		expect( codes( { ...valid(), window: { from: '10:00', to: '' } } ) ).toEqual( [ 'window:invalid' ] );
		expect( codes( { ...valid(), window: { from: '14:00', to: '10:00' } } ) ).toEqual( [ 'window:invalid_range' ] );
		expect( codes( { ...valid(), window: { from: '10:00', to: '10:00' } } ) ).toEqual( [ 'window:invalid_range' ] );
		expect( codes( { ...valid(), window: { from: '08:00', to: '12:00' } } ) ).toEqual( [ 'window:out_of_range' ] );
		expect( codes( { ...valid(), window: { from: '12:00', to: '22:00' } } ) ).toEqual( [ 'window:out_of_range' ] );
	} );

	it( 'caps a text at its limit', () => {
		expect( codes( { ...valid(), comment: 'я'.repeat( 11 ) } ) ).toEqual( [ 'comment:too_long' ] );
		expect( codes( { ...valid(), comment: 'я'.repeat( 10 ) } ) ).toEqual( [] );
	} );

	it( 'says it in Russian', () => {
		expect( validateInput( FIELDS, { ...valid(), day: '' } )[ 0 ].message ).toBe( 'Заполните это поле.' );
	} );
} );

// ----- s164: the orders multi-select of a toolbar dialog -----

const ORDERS_FIELD: OrderActionField = {
	id: 'orders',
	type: 'orders',
	label: 'Заказы',
	required: true,
	default: [ '1047', '1050', '1051' ],
	options: [
		{ value: '1047', label: '#1047 · Екатеринбург' },
		{ value: '1050', label: '#1050 · Москва' },
		{ value: '1051', label: '#1051 · Казань' },
	],
};

describe( 'an orders field', () => {
	const fields: OrderActionField[] = [ ORDERS_FIELD, FIELDS[ 0 ] ];

	it( 'opens with every declared order selected, as its own copy', () => {
		const values = initialValues( fields );

		expect( values.orders ).toEqual( [ '1047', '1050', '1051' ] );

		( values.orders as string[] ).pop();

		expect( ( ORDERS_FIELD as { default: string[] } ).default ).toHaveLength( 3 );
	} );

	it( 'travels as a list of ids, whatever the dialog holds', () => {
		expect( toPayload( fields, { orders: [ '1050' ], day: '2026-10-13' } ).orders ).toEqual( [ '1050' ] );
		expect( toPayload( fields, { orders: '1050', day: '2026-10-13' } ).orders ).toEqual( [] );
	} );

	it( 'is invalid with no order chosen — even though the field is not marked required', () => {
		const optional: OrderActionField[] = [ { ...ORDERS_FIELD, required: false } as OrderActionField ];

		expect( validateInput( optional, { orders: [] } ).map( ( e ) => e.code ) ).toEqual( [ 'required' ] );
		expect( validateInput( optional, { orders: [] } )[ 0 ].message ).toBe( 'Выберите хотя бы один заказ.' );
		expect( validateInput( optional, { orders: [ '1047' ] } ) ).toEqual( [] );
	} );
} );

describe( 'reconcileValues — a refreshed declaration keeps what the merchant typed', () => {
	const fields: OrderActionField[] = [ ORDERS_FIELD, FIELDS[ 0 ], FIELDS[ 1 ], FIELDS[ 2 ], FIELDS[ 3 ] ];

	it( 'keeps typed text, day and window; drops chosen orders the server no longer offers; new ones start with the default', () => {
		const refreshed: OrderActionField[] = [
			{
				...ORDERS_FIELD,
				// 1047 went through and is gone; 1099 is new.
				options: [
					{ value: '1050', label: '#1050' },
					{ value: '1099', label: '#1099' },
				],
				default: [ '1050', '1099' ],
			} as OrderActionField,
			...fields.slice( 1 ),
		];

		const values = reconcileValues( refreshed, {
			orders: [ '1047', '1050' ],
			day: '2026-10-20',
			window: { from: '10:00', to: '12:00' },
			service: 'standard',
			comment: 'Позвонить',
		} );

		expect( values.orders ).toEqual( [ '1050' ] );
		expect( values.day ).toBe( '2026-10-20' );
		expect( values.window ).toEqual( { from: '10:00', to: '12:00' } );
		expect( values.comment ).toBe( 'Позвонить' );
	} );

	it( 'a select whose chosen option is gone falls back to its default', () => {
		const values = reconcileValues( fields, { service: 'express' } );

		expect( values.service ).toBe( '' );
	} );

	it( 'nothing typed before means the declared defaults', () => {
		expect( reconcileValues( fields, {} ) ).toEqual( initialValues( fields ) );
	} );

	it( 'a value of the wrong shape is replaced by the default rather than trusted', () => {
		const values = reconcileValues( fields, { orders: 'x', day: [ 'y' ], window: 'z' } as never );

		expect( values.orders ).toEqual( [ '1047', '1050', '1051' ] );
		expect( values.day ).toBe( '2026-10-13' );
		expect( values.window ).toEqual( { from: '09:00', to: '18:00' } );
	} );

	it( 'the result is its own copy of the defaults', () => {
		const values = reconcileValues( fields, {} );

		( values.orders as string[] ).pop();

		expect( ORDERS_FIELD.default ).toHaveLength( 3 );
	} );
} );
