/**
 * Tests for `src/components/validate.js`'s required-field check on SENSITIVE fields.
 *
 * Found on the rig while verifying #362 Task 8 (unrelated to that task's own code): saving
 * ANY section of the (now-merged) «Доставка» tab was blocked by "Обязательное поле." on
 * `cdek_client_id`/`cdek_client_secret` — a provider's credentials that ARE configured
 * server-side (`is_set: true`), simply never touched THIS session. `Field_Schema` masks a
 * sensitive field's value to `''` regardless of server state (by design — the value must
 * never reach the browser), so the client-side required check saw an "empty" field and
 * blocked Save even though `onSave()` never actually sends an untouched field at all
 * (`saveTab( providerId, providerEdits )` — `providerEdits` omits anything not in `edits`).
 * The existing gotcha `settings-sensitive-secret-empty-skip-is-client-side.md` already
 * documents that the SEND path correctly omits an untouched secret; this fixes the
 * VALIDATION gate, which did not know that and blocked the request before it was ever built.
 *
 * This file is the FIRST test coverage `validate.js` has ever had.
 */

'use strict';

import { validateField, validateFields, evaluateConditions } from '../../src/components/validate';

describe( 'validateField — sensitive + required interaction', () => {
	const sensitiveRequired = { required: true, controlType: 'text', sensitive: true, is_set: true };

	test( 'an untouched, already-configured secret does not block (masked "" is not really empty)', () => {
		expect( validateField( sensitiveRequired, '', false ) ).toBeNull();
	} );

	test( 'a secret with nothing stored yet is still required when untouched', () => {
		const notSet = { ...sensitiveRequired, is_set: false };
		expect( validateField( notSet, '', false ) ).toBe( 'Обязательное поле.' );
	} );

	test( 'an explicit clear of a configured secret IS an error — touched wins', () => {
		expect( validateField( sensitiveRequired, '', true ) ).toBe( 'Обязательное поле.' );
	} );

	test( 'typing a real value while touched is valid, same as any other required field', () => {
		expect( validateField( sensitiveRequired, 'a-real-secret', true ) ).toBeNull();
	} );

	test( 'a non-sensitive required field is unaffected by the third argument', () => {
		const plainRequired = { required: true, controlType: 'text' };
		expect( validateField( plainRequired, '', false ) ).toBe( 'Обязательное поле.' );
		expect( validateField( plainRequired, 'x', false ) ).toBeNull();
	} );

	test( 'omitting the third argument defaults to "not touched" (safe for every existing caller)', () => {
		expect( validateField( sensitiveRequired, '' ) ).toBeNull();
	} );
} );

describe( 'validateFields — touched-id map threading', () => {
	const fields = {
		cdek_client_id: { required: true, controlType: 'text', sensitive: true, is_set: true },
		field_order_preset: { required: false, controlType: 'checkbox' },
	};

	test( 'a field present in the touched map is validated as touched', () => {
		const errors = validateFields( fields, { cdek_client_id: '' }, { cdek_client_id: '' } );
		expect( errors ).toEqual( { cdek_client_id: 'Обязательное поле.' } );
	} );

	test( 'a field absent from the touched map is validated as untouched — Save is not blocked', () => {
		const errors = validateFields( fields, { cdek_client_id: '' }, {} );
		expect( errors ).toEqual( {} );
	} );

	test( 'omitting the touched map entirely still works (back-compat for any other caller)', () => {
		const errors = validateFields( fields, { cdek_client_id: '' } );
		expect( errors ).toEqual( {} );
	} );
} );


describe( 'per-country records-table counts (#1642)', () => {
	const rows = JSON.stringify( [ 'RU', 'RU', 'RU', 'KZ' ].map( ( country ) => ( { city: JSON.stringify( { country } ) } ) ) );
	const condition = { setting: 'places', operator: 'count', per: 'country', aggregate: 'min', comparison: '>', value: 1 };
	const schemas = ( in_scope ) => ( { places: { controlType: 'records-table', columns: [ { id: 'country', type: 'location-country', of: 'city', in_scope } ] } } );

	test.each( [
		[ [ 'RU', 'KZ' ], 'min', false ],
		[ [ 'RU' ], 'min', true ],
		[ [], 'min', false ],
		[ [ 'RU', 'KZ' ], 'max', true ],
		[ [ 'RU' ], 'max', true ],
		[ [], 'max', false ],
	] )( '%j with %s agrees with both PHP save paths', ( scope, aggregate, expected ) => {
		expect( evaluateConditions( { ...condition, aggregate }, { places: rows }, schemas( scope ) ) ).toBe( expected );
	} );

	test( 'without per the same rows count in full, whatever their countries', () => {
		const { per, aggregate, ...total } = condition;
		expect( evaluateConditions( total, { places: rows }, schemas( [] ) ) ).toBe( true );
		expect( evaluateConditions( total, { places: rows }, { places: { controlType: 'records-table' } } ) ).toBe( true );
	} );

	test( 'no groups, bad records, unknown aggregates and wrong columns do not match', () => {
		for ( const value of [ '', 'broken', '[{"city":"broken"}]', '[{"city":"null"}]' ] ) {
			expect( evaluateConditions( { ...condition, comparison: '==', value: 0 }, { places: value }, schemas( [ 'RU' ] ) ) ).toBe( false );
		}
		expect( evaluateConditions( { ...condition, aggregate: 'sum' }, { places: rows }, schemas( [ 'RU' ] ) ) ).toBe( false );
		expect( evaluateConditions( { ...condition, per: 'city' }, { places: rows }, schemas( [ 'RU' ] ) ) ).toBe( false );
	} );

	test.each( [ [ 'min', 1 ], [ 'max', 3 ] ] )( '%s supports all count comparisons', ( aggregate, count ) => {
		for ( const [ comparison, value ] of [ [ '>', count - 1 ], [ '>=', count ], [ '<', count + 1 ], [ '<=', count ], [ '==', count ] ] ) {
			expect( evaluateConditions( { ...condition, aggregate, comparison, value }, { places: rows }, schemas( [ 'RU', 'KZ' ] ) ) ).toBe( true );
		}
	} );
} );
