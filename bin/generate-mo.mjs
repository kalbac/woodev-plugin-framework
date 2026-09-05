#!/usr/bin/env node
/**
 * Compiles woodev/languages/woodev-plugin-framework-ru_RU.po into the `.mo` file WordPress
 * actually loads at runtime (`load_plugin_textdomain()` never reads the `.po`).
 *
 * WHY THIS IS HAND-WRITTEN. There is no `msgfmt`/gettext toolchain on this machine (card #771),
 * and the binary `.mo` format is small and fully specified — a `MO` header, two offset tables,
 * and a block of NUL-terminated string data (see the GNU gettext manual, "MO Files"). Writing it
 * directly means a `.po` edit and its compiled `.mo` are produced by the same commit with no
 * external tool dependency.
 *
 * FORMAT NOTES that matter for correctness:
 *   - An entry with an EMPTY translation is OMITTED from the compiled file, matching msgfmt's own
 *     behaviour. Including it would map the msgid to an empty string, which gettext treats as "no
 *     translation" — the opposite of the untranslated-but-omitted convention every other Woodev
 *     `.mo` already relies on (the 298 Russian-msgid entries with an empty msgstr fall back to
 *     the msgid itself at runtime precisely because they are absent from the compiled table).
 *   - A `msgctxt` entry is keyed as `msgctxt + "\x04" + msgid` (gettext's own convention) — see
 *     gotcha `comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts`.
 *   - A plural entry is keyed as `msgid + "\x00" + msgid_plural`, and its value is every
 *     `msgstr[n]` joined with `"\x00"`, in form order.
 *   - The header entry (empty msgid) is REQUIRED and carries the `.po` header block verbatim —
 *     `Plural-Forms` in particular is read from here at runtime, not from the `.po`.
 *
 * SELF-VERIFICATION. Before writing anything, the freshly built buffer is parsed back with this
 * script's own reader and every key/value pair is compared against the `.po`'s. A mismatch refuses
 * the write — see gotcha `comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts` for why a
 * one-way build is not evidence the compiled file is correct.
 *
 * Usage:
 *   node bin/generate-mo.mjs          # rewrite the .mo in place
 *   node bin/generate-mo.mjs --check  # verify it is current, exit 1 if not (CI-friendly)
 *
 * @package woodev-plugin-framework
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo, poKey } from '../scripts/lib/po-file.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const MO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.mo' );

const MAGIC_LE = 0x950412de;

/** Builds the { key, value } pairs a compiled .mo must contain — empty translations are omitted. */
function collectPairs( entries, headerRaw ) {
	const pairs = [ { key: '', value: headerRaw } ];

	for ( const entry of entries ) {
		const ctxPrefix = entry.msgctxt ? entry.msgctxt + '\x04' : '';

		if ( entry.msgidPlural !== null ) {
			const forms = entry.msgstrPlural || [];
			if ( forms.every( ( s ) => ! s ) ) continue; // no plural form translated — omit
			const key = ctxPrefix + entry.msgid + '\x00' + entry.msgidPlural;
			const value = forms.map( ( s ) => s || '' ).join( '\x00' );
			pairs.push( { key, value } );
		} else {
			if ( ! entry.msgstr ) continue; // untranslated — omit, gettext falls back to the msgid
			pairs.push( { key: ctxPrefix + entry.msgid, value: entry.msgstr } );
		}
	}

	return pairs;
}

/** Builds the binary .mo buffer for a set of { key, value } pairs (key "" is the header). */
function buildMo( pairs ) {
	const sorted = [ ...pairs ].sort( ( a, b ) => Buffer.compare( Buffer.from( a.key, 'utf8' ), Buffer.from( b.key, 'utf8' ) ) );

	const N = sorted.length;
	const HEADER_SIZE = 28;
	const origTableOffset = HEADER_SIZE;
	const transTableOffset = origTableOffset + N * 8;
	const dataStart = transTableOffset + N * 8; // hash table size is 0, contributes no bytes

	const origBufs = sorted.map( ( p ) => Buffer.from( p.key, 'utf8' ) );
	const transBufs = sorted.map( ( p ) => Buffer.from( p.value, 'utf8' ) );

	const origMeta = [];
	const transMeta = [];
	let cursor = dataStart;

	for ( const buf of origBufs ) {
		origMeta.push( { length: buf.length, offset: cursor } );
		cursor += buf.length + 1; // + NUL terminator
	}
	for ( const buf of transBufs ) {
		transMeta.push( { length: buf.length, offset: cursor } );
		cursor += buf.length + 1;
	}

	const total = cursor;
	const out = Buffer.alloc( total );

	out.writeUInt32LE( MAGIC_LE, 0 );
	out.writeUInt32LE( 0, 4 ); // format revision
	out.writeUInt32LE( N, 8 );
	out.writeUInt32LE( origTableOffset, 12 );
	out.writeUInt32LE( transTableOffset, 16 );
	out.writeUInt32LE( 0, 20 ); // hash table size
	out.writeUInt32LE( dataStart, 24 ); // hash table offset (unused when size is 0)

	for ( let i = 0; i < N; i++ ) {
		out.writeUInt32LE( origMeta[ i ].length, origTableOffset + i * 8 );
		out.writeUInt32LE( origMeta[ i ].offset, origTableOffset + i * 8 + 4 );
		out.writeUInt32LE( transMeta[ i ].length, transTableOffset + i * 8 );
		out.writeUInt32LE( transMeta[ i ].offset, transTableOffset + i * 8 + 4 );
	}

	for ( let i = 0; i < N; i++ ) {
		origBufs[ i ].copy( out, origMeta[ i ].offset );
		out[ origMeta[ i ].offset + origBufs[ i ].length ] = 0;
	}
	for ( let i = 0; i < N; i++ ) {
		transBufs[ i ].copy( out, transMeta[ i ].offset );
		out[ transMeta[ i ].offset + transBufs[ i ].length ] = 0;
	}

	return out;
}

/** Reads a compiled .mo buffer back into { key, value } pairs — the independent check side. */
function readMo( buf ) {
	const magic = buf.readUInt32LE( 0 );
	if ( magic !== MAGIC_LE ) {
		throw new Error( `Not a little-endian .mo file (magic 0x${ magic.toString( 16 ) }).` );
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

const { header, headerRaw, entries } = parsePo( PO_PATH );

if ( ! header[ 'Plural-Forms' ] ) {
	die( 'Refusing to generate — the .po header has no Plural-Forms line.' );
}

const expectedPairs = collectPairs( entries, headerRaw );
const generated = buildMo( expectedPairs );

// Self-verification: parse the buffer THIS SCRIPT JUST BUILT back out, independently of the
// build path above, and require every key/value pair to match the .po exactly.
const roundTrip = readMo( generated );

if ( roundTrip.length !== expectedPairs.length ) {
	die(
		`Refusing to write — round-trip produced ${ roundTrip.length } entries, expected ${ expectedPairs.length }.`
	);
}

const expectedByKey = new Map( expectedPairs.map( ( p ) => [ p.key, p.value ] ) );
for ( const { key, value } of roundTrip ) {
	if ( ! expectedByKey.has( key ) ) {
		die( `Refusing to write — round-trip produced an unexpected key: "${ key.slice( 0, 80 ) }".` );
	}
	if ( expectedByKey.get( key ) !== value ) {
		die( `Refusing to write — round-trip value mismatch for key "${ key.slice( 0, 80 ) }".` );
	}
}

if ( process.argv.includes( '--check' ) ) {
	if ( ! existsSync( MO_PATH ) ) {
		die( 'The .mo file does not exist.', 'Run: npm run generate:mo' );
	}
	const committed = readFileSync( MO_PATH );
	if ( ! committed.equals( generated ) ) {
		die(
			'The committed .mo is stale relative to woodev-plugin-framework-ru_RU.po.',
			'Run: npm run generate:mo'
		);
	}
	console.log( `.mo is current (${ expectedPairs.length - 1 } translated entries + header, verified round-trip).` );
	process.exit( 0 );
}

writeFileSync( MO_PATH, generated );
console.log(
	`Wrote woodev-plugin-framework-ru_RU.mo — ${ expectedPairs.length - 1 } translated entries + header ` +
		`(${ generated.length } bytes, round-trip verified against the .po).`
);
