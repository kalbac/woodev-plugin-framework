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

const {
	createStore,
	getStoreForField,
} = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );

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

test( 'setChosenMethod strips the :instance suffix for condition matching', () => {
	// WooCommerce posts `method_id:instance_id`; a condition-spec targets the bare method id.
	const s = createStore( {
		fields: { pvz: { id: 'pvz', required: { state: 'chosen_shipping_method', operator: 'in', value: [ 'carrier_pickup' ] } } },
	} );
	s.setChosenMethod( 'carrier_pickup:3' );
	expect( s.evaluateRequired( 'pvz' ) ).toBe( true );
} );

// -------------------------------------------------------------------------
// getStoreForField() — the instance registry SP-5 Task 12 adds so the pickup
// mount can reach the SAME store instance the §8 gate reads, instead of
// building a second one from the same config global (see the module docblock
// on _registry for why that would silently diverge).
// -------------------------------------------------------------------------

test( 'getStoreForField() finds the store that declares the field (hit)', () => {
	const s = createStore( { fields: { registry_hit_field: { id: 'registry_hit_field' } } } );

	expect( getStoreForField( 'registry_hit_field' ) ).toBe( s );
} );

test( 'getStoreForField() returns null when no registered store declares the field (miss)', () => {
	createStore( { fields: { registry_miss_owned_field: { id: 'registry_miss_owned_field' } } } );

	expect( getStoreForField( 'registry_miss_totally_unknown_field' ) ).toBeNull();
} );

test( 'getStoreForField() picks the store that actually owns the field, not just any registered store', () => {
	const other = createStore( { fields: { registry_other_field: { id: 'registry_other_field' } } } );
	const owner = createStore( { fields: { registry_owned_field: { id: 'registry_owned_field' } } } );

	expect( getStoreForField( 'registry_owned_field' ) ).toBe( owner );
	expect( getStoreForField( 'registry_owned_field' ) ).not.toBe( other );
	expect( getStoreForField( 'registry_other_field' ) ).toBe( other );
} );

test( 'getStoreForField() pins the newest-first tie-break when two stores collide on the SAME field id', () => {
	// A genuine collision (two DIFFERENT configs declaring the same field id) is a
	// misconfiguration, not a supported scenario — but the tie-break must still be
	// DETERMINISTIC rather than accidentally return whichever store happens to be first
	// in registration order. See getStoreForField()'s own docblock.
	const first = createStore( { fields: { registry_collision_field: { id: 'registry_collision_field' } } } );
	const second = createStore( { fields: { registry_collision_field: { id: 'registry_collision_field' } } } );

	expect( getStoreForField( 'registry_collision_field' ) ).toBe( second );
	expect( getStoreForField( 'registry_collision_field' ) ).not.toBe( first );
} );
