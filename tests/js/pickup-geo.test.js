/**
 * Tests for pickup-geo.js
 *
 * Covers `safeColor()` (rejects anything that is not a hex colour) and
 * `contrastFor()` (picks black/white text by luminance) — SP-5 Task 8B, D-15 —
 * `groupByPosition()` (Task 9, D-4), `distanceMeters()` / `formatDistance()`
 * / `nearest()` / `boundsFor()` (Task 10, D-6, D-12), and `matchPoints()`
 * (Task 11, D-6).
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-geo.js
 */

'use strict';

const WoodevPickupGeo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );
const safeColor = WoodevPickupGeo.safeColor;
const contrastFor = WoodevPickupGeo.contrastFor;
const groupByPosition = WoodevPickupGeo.groupByPosition;
const distanceMeters = WoodevPickupGeo.distanceMeters;
const formatDistance = WoodevPickupGeo.formatDistance;
const nearest = WoodevPickupGeo.nearest;
const boundsFor = WoodevPickupGeo.boundsFor;
const matchPoints = WoodevPickupGeo.matchPoints;

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

// -----------------------------------------------------------------------
// distanceMeters / formatDistance / nearest / boundsFor — Task 10, D-6, D-12
// -----------------------------------------------------------------------

describe( 'distanceMeters', () => {
	it( 'is zero for the same point', () => {
		expect( distanceMeters( [ 55.75, 37.61 ], [ 55.75, 37.61 ] ) ).toBe( 0 );
	} );

	it( 'matches a known distance within 1%', () => {
		// Red Square → Moscow City, ≈ 7.0 km
		const d = distanceMeters( [ 55.7539, 37.6208 ], [ 55.7473, 37.5389 ] );

		expect( d ).toBeGreaterThan( 5000 );
		expect( d ).toBeLessThan( 5300 );
	} );

	it( 'is symmetric', () => {
		const a = [ 55.75, 37.61 ], b = [ 59.93, 30.33 ];

		expect( distanceMeters( a, b ) ).toBeCloseTo( distanceMeters( b, a ), 3 );
	} );

	// --- Extra tests beyond the spec, closing mutation-sweep holes ---------

	it( 'pins the exact metres for one degree of latitude, catching a wrong earth-radius constant', () => {
		// Along a meridian (same lng) the haversine central angle equals the latitude
		// delta exactly, so the result is EARTH_RADIUS_METERS * (pi / 180) to the
		// millimetre -- this pins the 6371008.8 m radius, not just "some plausible number".
		expect( distanceMeters( [ 0, 0 ], [ 1, 0 ] ) ).toBeCloseTo( 111195.08023353292, 5 );
	} );

	it( 'does not swap lat/lng internally: a lng-only move and a lat-only move of the same size differ', () => {
		// At latitude 0 a 1-degree longitude move is the same length as a 1-degree
		// latitude move; at latitude 55 a 1-degree longitude move is shorter (cosine
		// shrink). A lat/lng argument swap inside the formula would make these equal.
		const latMove = distanceMeters( [ 55, 37 ], [ 56, 37 ] );
		const lngMove = distanceMeters( [ 55, 37 ], [ 55, 38 ] );

		expect( lngMove ).toBeLessThan( latMove );
	} );
} );

describe( 'formatDistance', () => {
	// --- Labels and unit system come from config, not from a hardcoded locale table (#646) --

	it( 'reads the metres label from config.i18n below the km threshold', () => {
		var config = { i18n: { distanceMeters: 'м' }, distanceUnitSystem: 'metric' };

		expect( formatDistance( 430, config ) ).toBe( '430 м' );
	} );

	it( 'reads the kilometres label from config.i18n at/above the km threshold', () => {
		var config = { i18n: { distanceKilometers: 'км' }, distanceUnitSystem: 'metric' };

		expect( formatDistance( 1240, config ) ).toBe( '1.2 км' );
	} );

	it( 'reads the miles label from config.i18n for the imperial unit system', () => {
		var config = { i18n: { distanceMiles: 'mi' }, distanceUnitSystem: 'imperial' };

		expect( formatDistance( 1609.34, config ) ).toBe( '1.0 mi' );
	} );

	it( 'an explicit distanceUnitSystem wins over the legacy lang region check', () => {
		// en_US would fall back to imperial if the flag were ignored -- pinning metric here
		// proves the flag, not the legacy fallback, decided the branch.
		var config = { distanceUnitSystem: 'metric', lang: 'en_US' };

		expect( formatDistance( 1609.34, config ) ).toBe( '1.6 km' );
	} );

	// --- The metric metres/kilometres threshold is unchanged --------------

	it( 'keeps whole metres just below the 1 km threshold', () => {
		expect( formatDistance( 999, { distanceUnitSystem: 'metric' } ) ).toBe( '999 m' );
	} );

	it( 'switches to one-decimal kilometres exactly at the 1 km threshold', () => {
		expect( formatDistance( 1000, { distanceUnitSystem: 'metric' } ) ).toBe( '1.0 km' );
	} );

	it( 'rounds sub-kilometre metres to a whole number, catching a dropped Math.round', () => {
		expect( formatDistance( 430.6, { distanceUnitSystem: 'metric' } ) ).toBe( '431 m' );
	} );

	it( 'pins the exact mile conversion for a non-boundary value, catching a wrong mile constant', () => {
		// 3218.68 m is exactly 2 * 1609.34 m -- if the mile constant used here drifted from
		// formatDistance's own (e.g. the common 1609.344), this would round to a visibly
		// different value than "2.0".
		expect( formatDistance( 3218.68, { distanceUnitSystem: 'imperial' } ) ).toBe( '2.0 mi' );
	} );

	// --- Fallback: config.i18n missing a label -> the English default (#646) --------------

	it( 'falls back to the English word when config.i18n has no label at all', () => {
		expect( formatDistance( 430, { distanceUnitSystem: 'metric' } ) ).toBe( '430 m' );
		expect( formatDistance( 1240, { distanceUnitSystem: 'metric' } ) ).toBe( '1.2 km' );
		expect( formatDistance( 1609.34, { distanceUnitSystem: 'imperial' } ) ).toBe( '1.0 mi' );
	} );

	it( 'never renders "undefined" -- an entirely empty config still produces a real label', () => {
		expect( formatDistance( 500, {} ) ).toBe( '500 m' );
		expect( formatDistance( 500 ) ).toBe( '500 m' );
	} );

	// --- Fallback: config.distanceUnitSystem missing -> exactly today's --------------------
	// --- 'US' === region check against the legacy config.lang locale string (#646) ---------

	it( 'falls back to the US-region check on config.lang when the flag is absent (stale config)', () => {
		expect( formatDistance( 1609.34, { lang: 'en_US' } ) ).toBe( '1.0 mi' );
		expect( formatDistance( 1240, { lang: 'ru_RU' } ) ).toBe( '1.2 km' );
	} );

	it( 'the lang fallback is decided by region, not language, exactly like before #646', () => {
		expect( formatDistance( 1609.34, { lang: 'ru_US' } ) ).toBe( '1.0 mi' );
		expect( formatDistance( 1240, { lang: 'en_RU' } ) ).toBe( '1.2 km' );
	} );
} );

describe( 'nearest', () => {
	const groups = [
		{ key: 'far',  lat: 55.80, lng: 37.61 },
		{ key: 'near', lat: 55.7501, lng: 37.61 },
		{ key: 'mid',  lat: 55.76, lng: 37.61 },
	];

	it( 'returns the N closest, closest first', () => {
		expect( nearest( groups, [ 55.75, 37.61 ], 2 ).map( ( g ) => g.key ) ).toEqual( [ 'near', 'mid' ] );
	} );

	it( 'returns everything when there are fewer than N', () => {
		expect( nearest( groups, [ 55.75, 37.61 ], 99 ) ).toHaveLength( 3 );
	} );

	it( 'returns an empty array when there is nothing to rank', () => {
		expect( nearest( [], [ 55.75, 37.61 ], 3 ) ).toEqual( [] );
	} );

	// --- Extra tests beyond the spec, closing mutation-sweep holes ---------

	it( 'returns the actual group objects, not distance-wrapper copies', () => {
		const result = nearest( groups, [ 55.75, 37.61 ], 1 );

		expect( result[ 0 ] ).toBe( groups[ 1 ] ); // same reference as the 'near' group
	} );

	it( 'does not mutate or reorder the caller\'s own array', () => {
		const original = groups.slice();

		nearest( groups, [ 55.75, 37.61 ], 2 );

		expect( groups ).toEqual( original );
		expect( groups[ 0 ] ).toBe( original[ 0 ] );
	} );
} );

describe( 'boundsFor', () => {
	it( 'spans the anchor and every supplied group', () => {
		const b = boundsFor( [ 55.75, 37.61 ], [ { lat: 55.80, lng: 37.70 }, { lat: 55.70, lng: 37.50 } ] );

		expect( b ).toEqual( [ [ 55.70, 37.50 ], [ 55.80, 37.70 ] ] );
	} );

	it( 'returns a degenerate box when only the anchor is known', () => {
		expect( boundsFor( [ 55.75, 37.61 ], [] ) ).toEqual( [ [ 55.75, 37.61 ], [ 55.75, 37.61 ] ] );
	} );

	// --- Extra tests beyond the spec, closing mutation-sweep holes ---------

	it( 'is pulled wider by the anchor itself when the anchor is the outlier, not only by the groups', () => {
		// If the anchor were dropped from the min/max reduction, this box would be
		// [[55.70, 37.50], [55.80, 37.70]] -- narrower than reality.
		const b = boundsFor( [ 55.60, 37.40 ], [ { lat: 55.80, lng: 37.70 }, { lat: 55.70, lng: 37.50 } ] );

		expect( b ).toEqual( [ [ 55.60, 37.40 ], [ 55.80, 37.70 ] ] );
	} );

	it( 'does not mutate the groups array it is given', () => {
		const groups = [ { lat: 55.80, lng: 37.70 }, { lat: 55.70, lng: 37.50 } ];
		const snapshot = JSON.parse( JSON.stringify( groups ) );

		boundsFor( [ 55.75, 37.61 ], groups );

		expect( groups ).toEqual( snapshot );
	} );
} );

// -----------------------------------------------------------------------
// matchPoints — Task 11, D-6
// -----------------------------------------------------------------------

const pool = [
	{ id: '1', name: 'ПВЗ «Магнит»', address: 'Москва, Ленина 5', short_address: 'Ленина, 5',
	  instruction: 'вход со двора', postal_code: '101000' },
	{ id: '2', name: 'Постамат №4', address: 'Москва, Тверская 12', short_address: 'Тверская, 12',
	  instruction: '', postal_code: '125009' },
];

describe( 'matchPoints', () => {
	it( 'matches on the point name, case-insensitively', () => {
		expect( matchPoints( pool, 'магнит' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
		// Mixed case on the QUERY side: with every query lowercased, dropping the
		// toLowerCase() on the query survives the assertion above.
		expect( matchPoints( pool, 'МаГнИт' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
		// And on the FIELD side: the pool's name is capitalised, so a query that is
		// lowercase only matches if the field is folded too.
		expect( matchPoints( pool, 'пвз' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
	} );

	it( 'matches on the address', () => {
		expect( matchPoints( pool, 'тверская' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
	} );

	it( 'matches on the postal code exactly', () => {
		expect( matchPoints( pool, '125009' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
	} );

	it( 'matches on the how-to-get-there instruction', () => {
		expect( matchPoints( pool, 'со двора' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
	} );

	it( 'returns nothing for a blank or too-short query', () => {
		expect( matchPoints( pool, '' ) ).toEqual( [] );
		expect( matchPoints( pool, 'ул' ) ).toEqual( [] );
	} );

	it( 'ignores the HTML entities that server-side escaping introduces', () => {
		const escaped = [ { id: '3', name: 'ПВЗ &quot;Ромашка&quot;', address: '', short_address: '',
		                    instruction: '', postal_code: '' } ];

		expect( matchPoints( escaped, 'ромашка' ).map( ( p ) => p.id ) ).toEqual( [ '3' ] );

		// The assertion above passes even if decoding is skipped entirely — `ромашка` sits
		// inside `&quot;Ромашка&quot;` untouched. Only a query containing the DECODED
		// character proves the round-trip actually happened.
		expect( matchPoints( escaped, '"Ромашка"' ).map( ( p ) => p.id ) ).toEqual( [ '3' ] );

		// The inverse, so a "decode" that merely strips the entity rather than resolving it
		// cannot pass either: the raw entity text must NOT match once decoding is in place.
		expect( matchPoints( escaped, 'quot' ) ).toEqual( [] );
	} );

	// --- Extra tests beyond the spec, closing mutation-sweep holes ---------

	it( 'matches on short_address too, not only the full address', () => {
		const short = [ { id: '4', name: 'ПВЗ', address: 'Не совпадёт', short_address: 'Арбат, 1',
		                  instruction: '', postal_code: '' } ];

		expect( matchPoints( short, 'арбат' ).map( ( p ) => p.id ) ).toEqual( [ '4' ] );
	} );

	it( 'requires a query of exactly 3 characters after trimming, not before', () => {
		// 5 raw characters, only 2 non-space -- must still be rejected as too short,
		// which only happens if the length check runs AFTER trim(), not before it.
		expect( matchPoints( pool, '  ул  ' ) ).toEqual( [] );
		// Exactly 3 non-space characters after trimming a padded query must match.
		expect( matchPoints( pool, '  тве  ' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
	} );

	it( 'treats a 3-character query as the minimum accepted length, not a lower bound that excludes it', () => {
		expect( matchPoints( pool, 'тве' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
	} );

	it( 'requires an EXACT postal-code match, not a substring one', () => {
		// `2500` is not a substring of `125009` in the way that matters — it IS one, but a
		// prefix query is the shape a naive `indexOf` would accept, so pin both ends.
		expect( matchPoints( pool, '125' ) ).toEqual( [] );
		expect( matchPoints( pool, '12500' ) ).toEqual( [] );
		expect( matchPoints( pool, '25009' ) ).toEqual( [] );
		expect( matchPoints( pool, '125009' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
	} );

	it( 'does not cross-contaminate two points: a query unique to one never returns the other', () => {
		const ids = matchPoints( pool, 'магнит' ).map( ( p ) => p.id );

		expect( ids ).not.toContain( '2' );
	} );

	it( 'matches against unescaped, pre-decoded fields the same way as the escaped ones', () => {
		// The decoding path must be a genuine no-op for a field with nothing to decode,
		// not something that only works when an entity is actually present.
		const plain = [ { id: '5', name: 'ПВЗ Ромашка', address: '', short_address: '',
		                  instruction: '', postal_code: '' } ];

		expect( matchPoints( plain, 'ромашка' ).map( ( p ) => p.id ) ).toEqual( [ '5' ] );
	} );

	it( 'decodes each field once and reuses it across calls, instead of re-decoding every keystroke', () => {
		// A pool of FRESH objects, never seen by matchPoints() before this test, so the
		// per-point decode cache is guaranteed empty going in.
		const freshPool = [
			{ id: '9', name: 'ПВЗ «Свежий»', address: 'Москва, Свежая 1', short_address: 'Свежая, 1',
			  instruction: '', postal_code: '111111' },
		];
		const setter = jest.spyOn( Element.prototype, 'innerHTML', 'set' );

		matchPoints( freshPool, 'свежий' );
		const writesAfterFirstCall = setter.mock.calls.length;

		matchPoints( freshPool, 'свежая' );
		const writesAfterSecondCall = setter.mock.calls.length;

		expect( writesAfterFirstCall ).toBeGreaterThan( 0 );
		expect( writesAfterSecondCall ).toBe( writesAfterFirstCall );

		setter.mockRestore();
	} );
} );
