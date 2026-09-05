#!/usr/bin/env node
/**
 * Fails when the committed `.mo` does not match what the committed `.po` compiles to.
 *
 * WHAT THE COMMITTED `.mo` MUST BE. Card #771 / gotcha `the-mo-is-reproducible-from-the-po`: the
 * committed `.mo` IS `wp i18n make-mo` of the committed `.po`, byte for byte. There is no
 * `msgfmt`/wp-cli in CI, so this script does not COMPILE the `.po` — it only READS the committed
 * `.mo` (a fraction of the code a compiler needs) and checks every msgid/msgstr pair the `.po`
 * implies is actually present, correctly, in the binary.
 *
 * TO REGENERATE the `.mo` after editing the `.po` (needs the wp-env rig container; see the gotcha
 * for the exact command):
 *
 *   docker exec -w /var/www/html/woodev-framework <cli-container> sh -c \
 *     'wp i18n make-mo woodev/languages/woodev-plugin-framework-ru_RU.po woodev/languages/'
 *
 * MO FORMAT NOTES this reader relies on (see the GNU gettext manual, "MO Files"):
 *   - header: magic (LE 0x950412de), revision, N, originals-table offset, translations-table
 *     offset, hash-table size/offset (ignored here — this repo's catalogue has none).
 *   - each table is N pairs of (length, offset) into a trailing block of NUL-terminated strings.
 *   - a `msgctxt` entry is keyed `msgctxt + "\x04" + msgid`; a plural entry is keyed
 *     `msgid + "\x00" + msgid_plural` with `msgstr[n]` joined by `"\x00"` as the value.
 *   - an untranslated `.po` entry is OMITTED from the `.mo` entirely (msgfmt's convention) —
 *     see `expectedMoEntries()` in scripts/lib/po-file.mjs.
 *
 * THE HEADER ENTRY (key `""`) IS CHECKED SEPARATELY FROM EVERY OTHER PAIR. `wp i18n make-mo`
 * re-serializes the `.po` header block (reordering fields, filling in defaults) rather than
 * copying it verbatim — confirmed by comparing this repo's own pre-#771 `.mo` against its `.po`,
 * where the two already disagreed on field order despite `wp i18n make-mo` reproducing the `.mo`
 * byte-for-byte. Reproducing that reserialization here would mean re-implementing the part of
 * `wp i18n make-mo` this script exists to avoid depending on. So the header is checked only for
 * the one field runtime plural selection actually depends on: `Plural-Forms` must be present and
 * identical to the `.po`'s.
 *
 * Run: npm run lint:mo
 */

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo, expectedMoEntries } from './lib/po-file.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const MO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.mo' );

const MAGIC_LE = 0x950412de;
const MAGIC_BE = 0xde120495;

/** Reads a compiled `.mo` buffer into `{ key, value }` pairs. */
function readMo( buf ) {
	if ( buf.length < 28 ) {
		throw new Error( '.mo file is too short to contain a header.' );
	}

	const magicLE = buf.readUInt32LE( 0 );
	if ( magicLE === MAGIC_BE ) {
		throw new Error( 'Big-endian .mo files are not supported by this reader (none are used in this repo).' );
	}
	if ( magicLE !== MAGIC_LE ) {
		throw new Error( `Not a gettext .mo file (magic 0x${ magicLE.toString( 16 ) }).` );
	}

	const N = buf.readUInt32LE( 8 );
	const origTableOffset = buf.readUInt32LE( 12 );
	const transTableOffset = buf.readUInt32LE( 16 );

	const pairs = [];
	for ( let i = 0; i < N; i++ ) {
		const oLen = buf.readUInt32LE( origTableOffset + i * 8 );
		const oOff = buf.readUInt32LE( origTableOffset + i * 8 + 4 );
		const tLen = buf.readUInt32LE( transTableOffset + i * 8 );
		const tOff = buf.readUInt32LE( transTableOffset + i * 8 + 4 );

		pairs.push( {
			key: buf.toString( 'utf8', oOff, oOff + oLen ),
			value: buf.toString( 'utf8', tOff, tOff + tLen ),
		} );
	}
	return pairs;
}

function die( message, hint ) {
	console.error( message );
	if ( hint ) console.error( hint );
	process.exit( 1 );
}

const po = parsePo( PO_PATH );
const expected = expectedMoEntries( po );
const expectedByKey = new Map( expected.map( ( p ) => [ p.key, p.value ] ) );
const expectedPluralForms = po.header[ 'Plural-Forms' ];

let mo;
try {
	mo = readFileSync( MO_PATH );
} catch {
	die( 'The .mo file does not exist.', 'Regenerate it — see the header of this script for the command.' );
}

let actual;
try {
	actual = readMo( mo );
} catch ( err ) {
	die( `Could not parse the committed .mo: ${ err.message }` );
}

const REGEN_HINT =
	'Regenerate with (inside the wp-env rig container):\n' +
	"    wp i18n make-mo woodev/languages/woodev-plugin-framework-ru_RU.po woodev/languages/\n" +
	'  See docs-internal/gotchas/the-mo-is-reproducible-from-the-po.md.';

const actualByKey = new Map( actual.map( ( p ) => [ p.key, p.value ] ) );
const problems = [];

// The header entry: existence + the one field runtime plural selection depends on.
if ( ! actualByKey.has( '' ) ) {
	problems.push( 'the .mo has no header entry (empty msgid) at all.' );
} else {
	const headerText = actualByKey.get( '' );
	const m = /^Plural-Forms:\s?(.*)$/m.exec( headerText );
	const actualPluralForms = m ? m[ 1 ].trim() : null;

	if ( actualPluralForms !== ( expectedPluralForms || '' ).trim() ) {
		problems.push(
			`the .mo header's Plural-Forms ("${ actualPluralForms }") does not match the .po's ("${ expectedPluralForms }").`
		);
	}
}

const actualTranslatableCount = actual.length - ( actualByKey.has( '' ) ? 1 : 0 );
if ( actualTranslatableCount !== expected.length ) {
	problems.push(
		`the .mo has ${ actualTranslatableCount } translatable entries; the .po implies ${ expected.length }.`
	);
}

for ( const [ key, value ] of expectedByKey ) {
	if ( ! actualByKey.has( key ) ) {
		problems.push( `missing from the .mo: "${ key.replace( /\x00|\x04/g, '␀' ).slice( 0, 80 ) }"` );
	} else if ( actualByKey.get( key ) !== value ) {
		problems.push( `translation mismatch for "${ key.replace( /\x00|\x04/g, '␀' ).slice( 0, 80 ) }"` );
	}
}
for ( const key of actualByKey.keys() ) {
	if ( key !== '' && ! expectedByKey.has( key ) ) {
		problems.push( `present in the .mo but not implied by the .po: "${ key.replace( /\x00|\x04/g, '␀' ).slice( 0, 80 ) }"` );
	}
}

if ( problems.length ) {
	console.error( `\nThe committed .mo does not match the .po (${ problems.length } problem(s)):\n` );
	for ( const p of problems.slice( 0, 20 ) ) {
		console.error( `  ✗ ${ p }` );
	}
	if ( problems.length > 20 ) {
		console.error( `  …and ${ problems.length - 20 } more.` );
	}
	console.error( `\n${ REGEN_HINT }\n` );
	process.exit( 1 );
}

console.log(
	`.mo matches the .po (${ actualTranslatableCount } translated entries + header, Plural-Forms verified).`
);
