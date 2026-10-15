#!/usr/bin/env node
/**
 * Writes a minified `name.min.js` / `name.min.css` sibling next to every hand-written
 * framework asset (card #247).
 *
 * WHAT IS A SOURCE. Every `*.js` / `*.css` under the `assets/` roots below that is NOT:
 *   - already `*.min.*` (vendored third-party files such as `font-awesome.min.css`,
 *     `jquery.jquery-confirm.min.js`, `imask.min.js` — shipped as they are);
 *   - under a `vendor/`, `build/` or `dist/` directory (`build/` is the wp-scripts output, which
 *     minifies itself; `dist/` holds Parcel bundles that are already minified).
 *
 * WHY PER-FILE AND NOT A BUNDLE. ADR-011 keeps the classic-checkout layer "served raw, no
 * bundler": each file keeps its own handle, dependency list and `filemtime()`/version, and a
 * `.min` sibling is a 1:1 compression of one source. No concatenation, no transpiling, no
 * source maps.
 *
 * WHICH ONE LOADS. `Woodev_Plugin::get_assets_suffix()` returns `''` under `SCRIPT_DEBUG` and
 * `'.min'` otherwise, exactly like WooCommerce; every enqueue of a hand-written file appends it.
 * `tests/unit/MinifiedAssetsContractTest.php` fails when an enqueued file has no `.min` sibling
 * or a `.min` file has no source.
 *
 * DETERMINISM. Output is byte-stable for a given lockfile (terser + cssnano, no timestamps),
 * written with LF endings (`.gitattributes` pins `*.min.js` / `*.min.css` to LF), so the CI
 * `assets` job can rebuild and `git diff` the result like it does for `woodev/assets/build/`.
 *
 * Usage:
 *   node scripts/build-min-assets.mjs          # rewrite every .min sibling (also run by `npm run build`)
 *   node scripts/build-min-assets.mjs --check  # exit 1 when any .min file is stale, missing or orphaned
 *
 * @package woodev-plugin-framework
 */

import { readFileSync, writeFileSync, existsSync, readdirSync, unlinkSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import postcss from 'postcss';
import cssnano from 'cssnano';

const ROOT = fileURLToPath( new URL( '..', import.meta.url ) );

/** Every `assets/` root whose hand-written css/js ships to a browser. */
const ASSET_ROOTS = [
	'woodev/assets/js',
	'woodev/assets/css',
	'woodev/payment-gateway/assets/js',
	'woodev/payment-gateway/assets/css',
	'woodev/shipping-method/assets/js',
	'woodev/shipping-method/assets/css',
];

const SKIPPED_DIRECTORIES = new Set( [ 'vendor', 'build', 'dist', 'fonts', 'images' ] );

const check = process.argv.includes( '--check' );

/**
 * @param {string} directory Absolute directory.
 * @return {string[]} Absolute file paths, sorted so output order is stable.
 */
function walk( directory ) {
	if ( ! existsSync( directory ) ) {
		return [];
	}

	return readdirSync( directory, { withFileTypes: true } )
		.sort( ( a, b ) => ( a.name < b.name ? -1 : 1 ) )
		.flatMap( ( entry ) => {
			const full = join( directory, entry.name );

			if ( entry.isDirectory() ) {
				return SKIPPED_DIRECTORIES.has( entry.name ) ? [] : walk( full );
			}

			return [ full ];
		} );
}

const isMinified = ( file ) => /\.min\.(js|css)$/.test( file );
const isAsset = ( file ) => /\.(js|css)$/.test( file );
const minifiedName = ( file ) => file.replace( /\.(js|css)$/, '.min.$1' );
const display = ( file ) => relative( ROOT, file ).split( sep ).join( '/' );

/** @param {string} text */
const toLf = ( text ) => text.replace( /\r\n?/g, '\n' );

/**
 * @param {string} file Absolute path to a `.js` source.
 * @return {Promise<string>} Minified source, LF, trailing newline.
 */
async function minifyScript( file ) {
	const source = toLf( readFileSync( file, 'utf8' ) );
	const result = await minify( source, {
		compress: true,
		// Top-level names are NOT mangled (terser's default): these files publish globals such as
		// `window.WoodevLocationRenderers`, and a few are plain scripts that rely on top-level names.
		mangle: true,
		format: {
			// Keep licence banners and `/*! … */` credits; drop every other comment.
			comments: /^!|@license|@preserve/,
		},
	} );

	if ( ! result.code ) {
		throw new Error( `terser produced no output for ${ display( file ) }` );
	}

	return `${ toLf( result.code ) }\n`;
}

/**
 * @param {string} file Absolute path to a `.css` source.
 * @return {Promise<string>} Minified source, LF, trailing newline.
 */
async function minifyStyle( file ) {
	const source = toLf( readFileSync( file, 'utf8' ) );
	const result = await postcss( [ cssnano( { preset: 'default' } ) ] ).process( source, { from: undefined } );

	return `${ toLf( result.css ).trim() }\n`;
}

const files = ASSET_ROOTS.flatMap( ( root ) => walk( join( ROOT, root ) ) );
const sources = files.filter( ( file ) => isAsset( file ) && ! isMinified( file ) );
const expected = new Set( sources.map( minifiedName ) );

let failures = 0;

for ( const source of sources ) {
	const target = minifiedName( source );
	const output = source.endsWith( '.css' ) ? await minifyStyle( source ) : await minifyScript( source );

	if ( check ) {
		const current = existsSync( target ) ? readFileSync( target, 'utf8' ) : null;

		if ( current !== output ) {
			failures++;
			console.error( `${ current === null ? 'missing' : 'stale' }: ${ display( target ) }` );
		}
	} else {
		writeFileSync( target, output );
	}
}

// A `.min` file whose source is gone — most often a source that was deleted or renamed. Vendored
// third-party `.min` files are recognised by having no un-minified twin AND living outside the
// list below; they are the only legitimate "orphans".
const VENDORED = new Set( [
	'woodev/assets/css/admin/font-awesome.min.css',
	'woodev/assets/css/admin/jquery-confirm.min.css',
	'woodev/assets/js/admin/jquery.jquery-confirm.min.js',
] );

for ( const file of files.filter( isMinified ) ) {
	if ( expected.has( file ) || VENDORED.has( display( file ) ) ) {
		continue;
	}

	if ( check ) {
		failures++;
		console.error( `orphan: ${ display( file ) } has no source` );
	} else {
		unlinkSync( file );
		console.log( `removed orphan ${ display( file ) }` );
	}
}

if ( check ) {
	if ( failures > 0 ) {
		console.error( `\n${ failures } minified asset(s) out of date. Run: npm run build:min` );
		process.exit( 1 );
	}

	console.log( `build:min --check: ${ sources.length } minified asset(s) are current.` );
} else {
	console.log( `build:min: wrote ${ sources.length } minified asset(s).` );
}
