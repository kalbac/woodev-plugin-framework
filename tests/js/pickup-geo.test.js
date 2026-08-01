/**
 * Tests for pickup-geo.js
 *
 * Covers `safeColor()` (rejects anything that is not a hex colour) and
 * `contrastFor()` (picks black/white text by luminance) — SP-5 Task 8B, D-15.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-geo.js
 */

'use strict';

const WoodevPickupGeo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );
const safeColor = WoodevPickupGeo.safeColor;
const contrastFor = WoodevPickupGeo.contrastFor;

test( 'rejects anything that is not a hex colour', () => {
	expect( safeColor( 'red; } body {', '#06aedd' ) ).toBe( '#06aedd' );
	expect( safeColor( 'rgb(1,2,3)', '#06aedd' ) ).toBe( '#06aedd' );
	expect( safeColor( undefined, '#06aedd' ) ).toBe( '#06aedd' );
	expect( safeColor( '#FCE000', '#06aedd' ) ).toBe( '#FCE000' );
	expect( safeColor( '#fff', '#06aedd' ) ).toBe( '#fff' );
} );

test( 'picks dark text on a light accent and light text on a dark one', () => {
	expect( contrastFor( '#FCE000' ) ).toBe( '#000000' ); // Yandex yellow
	expect( contrastFor( '#1937ff' ) ).toBe( '#ffffff' ); // Pochta blue
	expect( contrastFor( '#0a8c37' ) ).toBe( '#ffffff' ); // CDEK green
} );

test( 'accepts an 8-digit hex colour (with alpha) as safe', () => {
	expect( safeColor( '#fce000ff', '#06aedd' ) ).toBe( '#fce000ff' );
} );

test( 'accepts a 3-digit hex colour as safe', () => {
	expect( safeColor( '#abc', '#06aedd' ) ).toBe( '#abc' );
} );

test( 'contrastFor ignores the trailing alpha byte of an 8-digit colour', () => {
	expect( contrastFor( '#FCE000ff' ) ).toBe( contrastFor( '#FCE000' ) );
} );

test( 'contrastFor expands a 3-digit colour before computing luminance', () => {
	// #000 -> #000000: unambiguously the darkest possible colour, must get white text.
	expect( contrastFor( '#000' ) ).toBe( '#ffffff' );
	// #fff -> #ffffff: unambiguously the lightest possible colour, must get black text.
	expect( contrastFor( '#fff' ) ).toBe( '#000000' );
} );
