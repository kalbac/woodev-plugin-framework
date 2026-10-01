#!/usr/bin/env node
/**
 * Fails when the committed per-bundle JS translation JSON does not match the committed `.po` (#1032).
 *
 * The JS twin of `lint:mo`: it does not generate anything (CI has no rig and no `make-json`), it
 * READS the committed `woodev/languages/{domain}-{locale}-{handle}.json` files and checks them
 * against what the `.po` implies — see `expectedBundleMessages()`. It catches:
 *   - a translated JS msgid whose bundle has no JSON, or whose JSON lacks / misstates it (stale);
 *   - a JSON entry the `.po` no longer implies, or a JSON file no bundle owns;
 *   - a header whose Plural-Forms differs from the `.po`'s;
 *   - the handle table drifting from the PHP side: every handle in the table must be passed to
 *     `Script_Translations::register()` somewhere in `woodev/`, and every handle PHP registers
 *     must be in the table.
 *
 * TO REGENERATE (needs the wp-env rig): npm run build:js-i18n
 *
 * Run: npm run lint:js-i18n
 */

import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo } from './lib/po-file.mjs';
import { BUNDLE_HANDLES, DOMAIN, expectedBundleMessages, jsonFileName, jsonFilePath, localeOfPo } from './lib/js-translations.mjs';

const ROOT = process.argv[ 2 ] ? join( process.argv[ 2 ] ) : join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const LANG_DIR = join( ROOT, 'woodev/languages' );

const REGEN_HINT = 'Regenerate (rig up): npm run build:js-i18n — see docs-internal/gotchas/js-translations-are-handle-named-json-files.md.';

const po = parsePo( PO_PATH );
const locale = localeOfPo( PO_PATH );
const expected = expectedBundleMessages( po );
const expectedPluralForms = ( po.header[ 'Plural-Forms' ] || '' ).trim();
const problems = [];

/** Every `.php` file under `dir`, skipping built assets. */
function phpFiles( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const full = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			if ( entry.name !== 'assets' && entry.name !== 'vendor' ) out.push( ...phpFiles( full ) );
		} else if ( entry.name.endsWith( '.php' ) ) {
			out.push( full );
		}
	}
	return out;
}

// 1. The handle table agrees with the PHP call sites.
const registered = new Set();
for ( const file of phpFiles( join( ROOT, 'woodev' ) ) ) {
	for ( const m of readFileSync( file, 'utf8' ).matchAll( /Script_Translations::register\(\s*[^,]+,\s*'([^']+)'\s*\)/g ) ) {
		registered.add( m[ 1 ] );
	}
}
const tableHandles = new Set( Object.values( BUNDLE_HANDLES ) );
for ( const h of tableHandles ) {
	if ( ! registered.has( h ) ) problems.push( `handle "${ h }" is in the bundle table but no PHP file calls Script_Translations::register() for it.` );
}
for ( const h of registered ) {
	if ( ! tableHandles.has( h ) ) problems.push( `PHP registers handle "${ h }" but scripts/lib/js-translations.mjs does not know it.` );
}

// 2. Every bundle's JSON is exactly what the .po implies.
const owned = new Set();
for ( const [ dir, messages ] of expected ) {
	const handle = BUNDLE_HANDLES[ dir ];
	if ( ! handle ) {
		problems.push( `the .po references bundle "${ dir }" for translated strings, but it has no handle in the bundle table.` );
		continue;
	}

	const file = jsonFilePath( ROOT, locale, handle );
	owned.add( jsonFileName( locale, handle ) );

	if ( ! existsSync( file ) ) {
		problems.push( `${ jsonFileName( locale, handle ) } is missing (${ Object.keys( messages ).length } translated string(s) for bundle "${ dir }").` );
		continue;
	}

	let actual;
	try {
		actual = JSON.parse( readFileSync( file, 'utf8' ) ).locale_data.messages;
	} catch ( err ) {
		problems.push( `${ jsonFileName( locale, handle ) } is not valid Jed JSON: ${ err.message }` );
		continue;
	}

	const { '': header = {}, ...entries } = actual;

	if ( ( header[ 'plural-forms' ] || '' ).trim() !== expectedPluralForms ) {
		problems.push( `${ jsonFileName( locale, handle ) }: plural-forms "${ header[ 'plural-forms' ] }" does not match the .po's "${ expectedPluralForms }".` );
	}

	for ( const [ key, forms ] of Object.entries( messages ) ) {
		if ( JSON.stringify( entries[ key ] ) !== JSON.stringify( forms ) ) {
			problems.push( `${ jsonFileName( locale, handle ) }: "${ key.replace( /\x04/g, '␄' ).slice( 0, 60 ) }" is ${ entries[ key ] ? 'stale' : 'missing' }.` );
		}
	}
	for ( const key of Object.keys( entries ) ) {
		if ( ! ( key in messages ) ) problems.push( `${ jsonFileName( locale, handle ) }: "${ key.slice( 0, 60 ) }" is not in the .po for this bundle (removed or untranslated).` );
	}
}

// 3. No orphan JSON.
for ( const name of readdirSync( LANG_DIR ) ) {
	if ( name.startsWith( `${ DOMAIN }-${ locale }-` ) && name.endsWith( '.json' ) && ! owned.has( name ) ) {
		problems.push( `${ name } has no translated strings behind it in the .po — delete it.` );
	}
}

if ( problems.length ) {
	console.error( `JS translations: ${ problems.length } problem(s)\n` );
	for ( const p of problems ) console.error( `  ✗ ${ p }` );
	console.error( `\n${ REGEN_HINT }` );
	process.exit( 1 );
}

const count = [ ...expected.values() ].reduce( ( n, m ) => n + Object.keys( m ).length, 0 );
console.log( `JS translations: OK (${ expected.size } bundle(s), ${ count } translated string(s), ${ registered.size } handle(s) registered)` );
