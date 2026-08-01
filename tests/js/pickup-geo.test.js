/**
 * Tests for pickup-geo.js
 *
 * Covers `safeColor()` (rejects anything that is not a hex colour) and
 * `contrastFor()` (picks black/white text by luminance) — SP-5 Task 8B, D-15 —
 * plus `groupByPosition()` (Task 9, D-4).
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-geo.js
 */

'use strict';

const WoodevPickupGeo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );
const safeColor = WoodevPickupGeo.safeColor;
const contrastFor = WoodevPickupGeo.contrastFor;
const groupByPosition = WoodevPickupGeo.groupByPosition;

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

// -----------------------------------------------------------------------
// groupByPosition — Task 9, D-4
// -----------------------------------------------------------------------

const p = ( id, lat, lng, type ) => ( { id, lat, lng, type: { code: type, label: type } } );

describe( 'groupByPosition', () => {
	it( 'keeps distinct positions apart', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.7601, 37.6210 ) ] );

		expect( groups ).toHaveLength( 2 );
		expect( groups.every( ( g ) => g.points.length === 1 ) ).toBe( true );
	} );

	it( 'folds identical coordinates into one group', () => {
		const groups = groupByPosition( [
			p( 'a', 55.7558, 37.6173, 'pvz' ),
			p( 'b', 55.7558, 37.6173, 'postamat' ),
		] );

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].points.map( ( x ) => x.id ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'folds coordinates that differ below the 4-decimal key', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.75580001, 37.61730002 ) ] );

		expect( groups ).toHaveLength( 1 );
	} );

	it( 'keeps coordinates apart at the 4-decimal boundary', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.7559, 37.6173 ) ] );

		expect( groups ).toHaveLength( 2 );
	} );

	it( 'takes its position and icon from the first point of the group', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173, 'pvz' ), p( 'b', 55.7558, 37.6173, 'postamat' ) ] );

		expect( groups[ 0 ].lat ).toBe( 55.7558 );
		expect( groups[ 0 ].typeCode ).toBe( 'pvz' );
		expect( groups[ 0 ].size ).toBe( 2 );
	} );

	it( 'preserves input order of groups so the sidebar is stable', () => {
		const groups = groupByPosition( [ p( 'b', 55.99, 37.99 ), p( 'a', 55.11, 37.11 ) ] );

		expect( groups.map( ( g ) => g.points[ 0 ].id ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'skips points without usable coordinates instead of grouping them at 0,0', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), { id: 'x' }, p( 'c', null, null ) ] );

		expect( groups ).toHaveLength( 1 );
	} );

	// --- Extra tests beyond the spec, closing mutation-sweep holes ---------

	it( 'pins the exact grouping key, catching a truncation-vs-rounding or field-swap mutation', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ) ] );

		expect( groups[ 0 ].key ).toBe( '55.7558,37.6173' );
	} );

	it( 'rounds the key rather than truncating it, on both sides of the rounding boundary', () => {
		// 55.75585 rounds UP to 55.7559 (toFixed rounding, not truncation) and must NOT
		// fold into the 55.7558 bucket even though truncation would put it there.
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.75585, 37.6173 ) ] );

		expect( groups ).toHaveLength( 2 );
	} );

	it( 'rounds negative coordinates the same way, so a sign bug in the southern/western hemisphere is caught', () => {
		const groups = groupByPosition( [ p( 'a', -55.7558, -37.6173 ), p( 'b', -55.75580001, -37.61730002 ) ] );

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].key ).toBe( '-55.7558,-37.6173' );
	} );

	it( 'rejects NaN, Infinity and numeric-string coordinates, not just null/undefined', () => {
		const groups = groupByPosition( [
			p( 'a', 55.7558, 37.6173 ),
			p( 'nan', NaN, 37.6173 ),
			p( 'inf', Infinity, 37.6173 ),
			p( 'str', '55.7558', '37.6173' ),
		] );

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].points ).toHaveLength( 1 );
	} );

	it( 'counts size correctly for a group of three, catching an off-by-one from push-before-count ordering', () => {
		const groups = groupByPosition( [
			p( 'a', 1, 1, 'pvz' ),
			p( 'b', 1, 1, 'postamat' ),
			p( 'c', 1, 1, 'pvz' ),
		] );

		expect( groups[ 0 ].size ).toBe( 3 );
		expect( groups[ 0 ].points ).toHaveLength( 3 );
	} );

	it( 'defaults typeCode to an empty string when the first point has no type, instead of throwing', () => {
		const groups = groupByPosition( [ { id: 'a', lat: 1, lng: 1 } ] );

		expect( groups[ 0 ].typeCode ).toBe( '' );
	} );

	it( 'returns an empty array for an empty or missing input, without throwing', () => {
		expect( groupByPosition( [] ) ).toEqual( [] );
		expect( groupByPosition( undefined ) ).toEqual( [] );
	} );
} );
