/**
 * The gate for card #867: `columns.ts`'s `DELIVERY_STATUS_LABELS` claims, in its own docblock,
 * to mirror `Delivery_Status::labels()` (`woodev/shipping-method/order/class-delivery-status.php`)
 * byte-for-byte. Nothing enforced that claim — the only prior test on the constant asserted each
 * label was a non-empty string, which cannot catch drift between the two sides. If they drift, the
 * TS-side status filter offers a label no PHP-side row can ever carry (the defect class of #837).
 *
 * APPROACH. Parse the PHP source directly with a pair of narrow regexes rather than generating one
 * side from the other: both sides are small, hand-authored, closed enums (ten states), so a
 * generator + staleness check (the `bin/generate-phone-masks.mjs` precedent) would be more
 * machinery than the invariant needs, and there is no natural "generate FROM which side" direction
 * here the way there is for phone masks (library metadata -> PHP table). The PHP shape this parses
 * is a plain `self::CONST => __( 'Label', 'woodev-plugin-framework' ),` array literal — regular
 * enough that a regex is not fragile — and the two regexes below are scoped tightly (anchored to
 * the `labels(): array` method body, and to `const NAME = 'value';` lines) so that unrelated PHP
 * elsewhere in the class cannot feed in silently.
 *
 * `@wordpress/i18n`'s `__()` returns its first argument unchanged when no locale data is loaded
 * (verified directly: `__( 'x', 'domain' )` === `'x'` under this suite), so `DELIVERY_STATUS_LABELS`
 * at test time holds the literal Russian text and can be compared to the PHP text as plain strings.
 *
 * @see src/shipping-orders-page/columns.ts
 * @see woodev/shipping-method/order/class-delivery-status.php
 */

'use strict';

const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );

import {
	DELIVERY_STATUS_LABELS,
	DELIVERY_STATUS_TONES,
} from '../../src/shipping-orders-page/columns';

const PHP_PATH = path.resolve(
	__dirname,
	'../../woodev/shipping-method/order/class-delivery-status.php'
);

/** Reads `Delivery_Status::labels()`'s array literal as a canonical-state -> label map. */
function readPhpDeliveryStatusLabels() {
	const php = readFileSync( PHP_PATH, 'utf8' );

	// Canonical-state value for every `const NAME = 'value';` on the class, so `self::NAME`
	// below resolves to the same string TS keys its object by — never assumed from casing.
	const constants = new Map();
	for ( const m of php.matchAll( /const\s+([A-Z_]+)\s*=\s*'([^']*)';/g ) ) {
		constants.set( m[ 1 ], m[ 2 ] );
	}

	const methodMatch = php.match(
		/public static function labels\(\):\s*array\s*\{\s*return\s*\[([\s\S]*?)\];\s*\}/
	);
	if ( ! methodMatch ) {
		throw new Error(
			'Could not find `public static function labels(): array { return [ ... ]; }` in ' +
				PHP_PATH +
				' — the gate\'s parser is out of sync with the PHP source shape, not a real label drift.'
		);
	}

	const labels = {};
	const rowPattern =
		/self::([A-Z_]+)\s*=>\s*__\(\s*'((?:\\'|[^'])*)'\s*,\s*'woodev-plugin-framework'\s*\)\s*,/g;
	for ( const m of methodMatch[ 1 ].matchAll( rowPattern ) ) {
		const [ , constName, rawLabel ] = m;
		if ( ! constants.has( constName ) ) {
			throw new Error(
				`labels() references self::${ constName }, which has no ` +
					`\`const ${ constName } = '...';\` on the class — the gate's parser is out of ` +
					'sync with the PHP source shape, not a real label drift.'
			);
		}
		labels[ constants.get( constName ) ] = rawLabel.replace( /\\'/g, "'" );
	}

	return labels;
}

/** Reads `Delivery_Status::tones()`'s canonical-state -> CSS-tone map. */
function readPhpDeliveryStatusTones() {
	const php = readFileSync( PHP_PATH, 'utf8' );
	const constants = new Map();
	for ( const m of php.matchAll( /const\s+([A-Z_]+)\s*=\s*'([^']*)';/g ) ) {
		constants.set( m[ 1 ], m[ 2 ] );
	}

	const methodMatch = php.match(
		/public static function tones\(\):\s*array\s*\{\s*return\s*\[([\s\S]*?)\];\s*\}/
	);
	if ( ! methodMatch ) {
		throw new Error( `Could not find Delivery_Status::tones() in ${ PHP_PATH }.` );
	}

	const tones = {};
	for ( const m of methodMatch[ 1 ].matchAll( /self::([A-Z_]+)\s*=>\s*'([^']*)',/g ) ) {
		const [ , constName, tone ] = m;
		if ( ! constants.has( constName ) ) {
			throw new Error( `tones() references unknown self::${ constName }.` );
		}
		tones[ constants.get( constName ) ] = tone;
	}

	return tones;
}

describe( 'DELIVERY_STATUS_LABELS mirrors Delivery_Status::labels() (#867)', () => {
	test( 'every canonical state\'s label matches the PHP source byte-for-byte', () => {
		const phpLabels = readPhpDeliveryStatusLabels();

		// Parsed something plausible, or a change to the PHP shape broke the parser silently
		// rather than the invariant actually holding.
		expect( Object.keys( phpLabels ).length ).toBeGreaterThan( 0 );

		expect( DELIVERY_STATUS_LABELS ).toEqual( phpLabels );
	} );

	test( 'every canonical state\'s tone matches the PHP source in both directions', () => {
		const phpTones = readPhpDeliveryStatusTones();

		expect( Object.keys( phpTones ).length ).toBeGreaterThan( 0 );
		expect( DELIVERY_STATUS_TONES ).toEqual( phpTones );
	} );
} );
