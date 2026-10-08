/**
 * The browser-side half of an order action's input fields (#1180): defaults in, payload out, and the checks that
 * need no server. The server re-checks the same declaration (`Order_Action_Fields::validate()`).
 *
 * @see src/shipping-orders-page/action-input.ts
 */

import { initialValues, toPayload, validateInput } from '../../src/shipping-orders-page/action-input';
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
