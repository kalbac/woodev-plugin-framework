/**
 * @jest-environment jsdom
 */

/**
 * Tests for the FIXTURE-ONLY Почта embedded-widget adapter script (issue #251).
 *
 * Covers F5 (rig finding, s63): the composed `address` must drop a CONSECUTIVE
 * duplicate part — e.g. `regionTo`/`cityTo` both `"г. Москва"` for a Moscow point,
 * measured on the rig — without reordering or dropping anything else from the
 * `regionTo, areaTo, cityTo, location, addressTo` field list this fixture copies
 * verbatim from the operator's own production plugin.
 *
 * @see tests/_fixtures/woodev-test-shipping-method/assets/js/woodev-test-pochta-embed-adapter.js
 */

'use strict';

require( '../_fixtures/woodev-test-shipping-method/assets/js/woodev-test-pochta-embed-adapter' );

afterEach( () => {
	delete window.WoodevTestPochtaEmbedConfig;
} );

test( 'F5: a consecutive duplicate part (regionTo === cityTo) is dropped from the composed address', () => {
	// The exact rig measurement (s63): Moscow's regionTo and cityTo are both "г. Москва".
	const point = window.WoodevPochtaEmbed.toPoint( {
		pvzData: {
			id: 43213,
			pvzType: 'russian_post',
			indexTo: '101000',
			regionTo: 'г. Москва',
			areaTo: null,
			cityTo: 'г. Москва',
			location: null,
			addressTo: 'ул. Никольская 7-9 стр. 4',
		},
	} );

	expect( point.address ).toBe( 'г. Москва, ул. Никольская 7-9 стр. 4' );
} );

test( 'F5: non-duplicate parts are all kept, in the same order, when nothing repeats', () => {
	const point = window.WoodevPochtaEmbed.toPoint( {
		pvzData: {
			id: 1,
			pvzType: 'postamat',
			indexTo: '111543',
			regionTo: 'Московская область',
			areaTo: 'Ленинский р-н',
			cityTo: 'г. Видное',
			location: 'мкр. Зеленый',
			addressTo: 'ул. Ленина, 10',
		},
	} );

	expect( point.address ).toBe(
		'Московская область, Ленинский р-н, г. Видное, мкр. Зеленый, ул. Ленина, 10'
	);
} );

test( 'F5: a value that repeats NON-consecutively (separated by another part) is NOT collapsed', () => {
	// Pathological but explicitly not assumed impossible by the dedup helper's own
	// docblock — areaTo and addressTo happen to coincide here, with cityTo between them.
	const point = window.WoodevPochtaEmbed.toPoint( {
		pvzData: {
			id: 2,
			pvzType: 'russian_post',
			indexTo: '2',
			regionTo: 'Регион',
			areaTo: 'Совпадение',
			cityTo: 'Город',
			location: null,
			addressTo: 'Совпадение',
		},
	} );

	expect( point.address ).toBe( 'Регион, Совпадение, Город, Совпадение' );
} );

test( 'a payload with no pvzData returns null, unaffected by the address dedup change', () => {
	expect( window.WoodevPochtaEmbed.toPoint( {} ) ).toBeNull();
	expect( window.WoodevPochtaEmbed.toPoint( null ) ).toBeNull();
} );
