/**
 * Tests for checkout-field-store.js
 *
 * Verifies that the JS condition mirror matches PHP Checkout_Condition semantics
 * and that the store API works as specified.
 *
 * @see woodev/shipping-method/checkout/class-checkout-condition.php
 * @see woodev/shipping-method/assets/js/frontend/checkout-field-store.js
 */

'use strict';

const { createStore } = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );

const config = {
	fields: {
		pvz: { id: 'pvz', required: { state: 'chosen_shipping_method', operator: 'in', value: [ 'carrier_pickup' ] } },
		billing_city: { id: 'billing_city', depends_on: 'billing_state', source_kind: 'suggest' },
	},
	takeover: { billing_state: { RU: true, FR: false } },
};

test( 'required mirror matches PHP semantics', () => {
	const s = createStore( config );
	s.setChosenMethod( 'carrier_pickup' );
	expect( s.evaluateRequired( 'pvz' ) ).toBe( true );
	s.setChosenMethod( 'flat_rate' );
	expect( s.evaluateRequired( 'pvz' ) ).toBe( false );
} );

test( 'empty conditions is false (AND and OR)', () => {
	expect( createStore( { fields: { x: { id: 'x', required: { relation: 'AND', conditions: [] } } } } ).evaluateRequired( 'x' ) ).toBe( false );
	expect( createStore( { fields: { x: { id: 'x', required: { relation: 'OR',  conditions: [] } } } } ).evaluateRequired( 'x' ) ).toBe( false );
} );

test( 'in with non-array value is false', () => {
	expect( createStore( { fields: { x: { id: 'x', required: { state: 'a', operator: 'in', value: 'carrier_pickup' } } } } ).evaluateRequired( 'x' ) ).toBe( false );
} );

test( 'unknown operator fails open to false', () => {
	expect( createStore( { fields: { x: { id: 'x', required: { state: 'a', operator: 'regex', value: '.*' } } } } ).evaluateRequired( 'x' ) ).toBe( false );
} );

test( 'bool required passthrough', () => {
	expect( createStore( { fields: { x: { id: 'x', required: true  } } } ).evaluateRequired( 'x' ) ).toBe( true );
	expect( createStore( { fields: { y: { id: 'y', required: false } } } ).evaluateRequired( 'y' ) ).toBe( false );
} );

test( 'childrenOf finds dependents', () => {
	expect( createStore( config ).childrenOf( 'billing_state' ) ).toEqual( [ 'billing_city' ] );
} );

test( 'takeoverFor reads the per-country map', () => {
	const s = createStore( config );
	expect( s.takeoverFor( 'billing_state', 'RU' ) ).toBe( true );
	expect( s.takeoverFor( 'billing_state', 'FR' ) ).toBe( false );
	expect( s.takeoverFor( 'billing_state', 'DE' ) ).toBe( false );
} );
