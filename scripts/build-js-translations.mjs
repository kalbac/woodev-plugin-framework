#!/usr/bin/env node
/**
 * Writes the per-bundle JS translation JSON files (#1032) from the committed `.po`.
 *
 * WHY. A built admin bundle's `__()` is answered by `@wordpress/i18n`, which is fed by
 * `wp_set_script_translations()` -> `{domain}-{locale}-{handle}.json`. Nothing used to produce
 * those files, so every JS msgid rendered as written. See `scripts/lib/js-translations.mjs` for why
 * they are named by handle, and `docs-internal/gotchas/js-translations-are-handle-named-json-files.md`.
 *
 * HOW. `wp i18n make-json` (the rig container's wp-cli, the same place `make-mo` runs) does the
 * extraction, so plural and msgctxt keys are exactly what wp-cli writes. Two things about it are
 * handled here, both measured:
 *   - it runs on a COPY of the `.po` with `--no-purge`. Its default PURGES the JS strings out of the
 *     `.po` it is given — pointed at the real file it deleted 1128 lines (and the `#:` references
 *     the next `lint:i18n-sources` run needs) while still exiting 0.
 *   - it names files by md5(source path) and keeps untranslated entries as `[""]`. This script
 *     renames each file to its handle (bundle table in `scripts/lib/js-translations.mjs`) and drops
 *     the untranslated entries.
 *
 * Needs the wp-env rig up (`npx @wordpress/env start`). Run after editing the `.po` or after
 * `npm run build` changes which bundle references a msgid, then `npm run lint:js-i18n`.
 *
 * Run: npm run build:js-i18n
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BUNDLE_HANDLES, DOMAIN, bundleOfReference, jsonFilePath, localeOfPo } from './lib/js-translations.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const LANG_DIR = join( ROOT, 'woodev/languages' );

function sh( command, args ) {
	return execFileSync( command, args, { stdio: [ 'ignore', 'pipe', 'inherit' ], timeout: 120_000 } ).toString().trim();
}

function riglessDie() {
	console.error( 'build:js-i18n needs the wp-env rig container (wp-cli). Start it: npx @wordpress/env start' );
	process.exit( 1 );
}

const locale = localeOfPo( PO_PATH );

let container;
try {
	container = sh( join( ROOT, 'scripts/machine/rig-container.sh' ), [ 'cli' ] );
} catch {
	riglessDie();
}

const work = mkdtempSync( join( tmpdir(), 'js-i18n-' ) );
const remote = `/tmp/js-i18n-${ process.pid }`;

try {
	sh( 'docker', [ 'exec', container, 'mkdir', '-p', `${ remote }/in`, `${ remote }/out` ] );
	sh( 'docker', [ 'cp', PO_PATH, `${ container }:${ remote }/in/${ basename( PO_PATH ) }` ] );
	sh( 'docker', [
		'exec', container, 'wp', 'i18n', 'make-json',
		`${ remote }/in/${ basename( PO_PATH ) }`, `${ remote }/out`, '--no-purge',
	] );
	sh( 'docker', [ 'cp', `${ container }:${ remote }/out/.`, work ] );
} finally {
	try {
		sh( 'docker', [ 'exec', container, 'rm', '-rf', remote ] );
	} catch {
		// best effort — it is a /tmp dir inside the rig.
	}
}

// Drop what a previous run wrote, so a handle that lost its last translation does not linger.
for ( const name of readdirSync( LANG_DIR ) ) {
	if ( name.startsWith( `${ DOMAIN }-${ locale }-` ) && name.endsWith( '.json' ) ) {
		rmSync( join( LANG_DIR, name ) );
	}
}

let written = 0;

for ( const name of readdirSync( work ).sort() ) {
	if ( ! name.endsWith( '.json' ) ) continue;

	const json = JSON.parse( readFileSync( join( work, name ), 'utf8' ) );
	const dir = bundleOfReference( json.source );

	if ( ! dir ) {
		console.error( `make-json produced a file for a source this repo does not ship as a bundle: ${ json.source }` );
		process.exit( 1 );
	}
	if ( ! BUNDLE_HANDLES[ dir ] ) {
		console.error( `Bundle "${ dir }" has no handle in scripts/lib/js-translations.mjs — add it (and its Script_Translations::register() call).` );
		process.exit( 1 );
	}

	const { '': header, ...messages } = json.locale_data.messages;
	const translated = Object.fromEntries( Object.entries( messages ).filter( ( [ , forms ] ) => forms.some( Boolean ) ) );

	if ( ! Object.keys( translated ).length ) continue;

	const out = {
		'translation-revision-date': '',
		generator: 'scripts/build-js-translations.mjs (wp i18n make-json)',
		source: json.source,
		domain: 'messages',
		locale_data: { messages: { '': header, ...translated } },
	};

	writeFileSync( jsonFilePath( ROOT, locale, BUNDLE_HANDLES[ dir ] ), JSON.stringify( out, null, '\t' ) + '\n' );
	written++;
}

rmSync( work, { recursive: true, force: true } );
console.log( `build:js-i18n: wrote ${ written } handle-named JSON file(s) to woodev/languages/` );
