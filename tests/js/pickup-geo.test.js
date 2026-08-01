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
	// CDEK green: L ~= 0.1909, just ABOVE the 0.179 equal-contrast threshold, so
	// BLACK wins (contrast ratio 4.82:1 vs 4.36:1 with white) -- even though CDEK's
	// own site uses white text here. Do not "fix" this back to white; see
	// contrastFor()'s own docblock for the read-it-once explanation.
	expect( contrastFor( '#0a8c37' ) ).toBe( '#000000' );
} );

/**
 * The three brand colours above are all comfortably clear of the 0.179
 * threshold -- none of them actually EXERCISES the boundary the whole
 * function is built around. These two grays do: 0x75/0x75/0x75 (#757575)
 * computes to L ~= 0.1779 (just BELOW 0.179 -> white wins), and
 * 0x76/0x76/0x76 (#767676) computes to L ~= 0.1812 (just ABOVE -> black
 * wins) -- one hex digit apart, opposite sides of the rule.
 */
test( 'picks the correct side right at the 0.179 threshold', () => {
	expect( contrastFor( '#757575' ) ).toBe( '#ffffff' );
	expect( contrastFor( '#767676' ) ).toBe( '#000000' );
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
