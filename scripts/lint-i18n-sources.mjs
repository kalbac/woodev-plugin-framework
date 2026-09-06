#!/usr/bin/env node
/**
 * Fails when a string reachable from `__()`/`_e()`/etc. in `woodev/` never made it into the
 * translation catalogue.
 *
 * WHY THIS EXISTS. Card #791: `npm run lint:i18n` (`scripts/lint-i18n.mjs`) reads only the
 * committed Russian `.po` and checks each of ITS entries for an untranslated English msgid. It
 * never scans the source, so its real contract is "the catalogue holds no untranslated English
 * entry" — not "the code holds no untranslated English string". A new translatable string that
 * never reached the catalogue does not exist for that gate and passes it green, which is exactly
 * how 28 strings — including a customer-facing one — accumulated silently. THIS gate answers the
 * question `lint:i18n` cannot: it re-extracts every msgid straight from the PHP sources with
 * `wp i18n make-pot`, the same scanner gettext semantics are defined by (a hand-rolled literal
 * scanner cannot see a concatenated or otherwise non-literal msgid — see gotcha
 * `a-concatenated-msgid-is-invisible-to-a-single-literal-scanner` — this sidesteps that
 * objection entirely), and asserts every extracted msgid is present in BOTH the committed `.pot`
 * and the committed `.po`. Both, because a msgid that reaches the `.pot` but not the `.po` is
 * still invisible to `lint:i18n`.
 *
 * ONE-DIRECTIONAL, DELIBERATELY. This gate only ever asks "is every SOURCE msgid present in the
 * catalogue?". It never asks the reverse — "is every CATALOGUE msgid still present in the
 * source?" — and a catalogue entry with no matching source is NOT an error here. Cleaning up
 * those stale entries is card #775, which is frozen behind #567; this gate must not do that
 * card's work as a side effect of a stricter check. At the time this gate was written, the
 * catalogue already carried 39 such stale entries and they are expected to stay.
 *
 * WHAT THIS DOES NOT CHECK. Whether a catalogue msgstr is actually translated — that is
 * `lint:i18n`'s job — and whether the compiled `.mo` matches the `.po` — that is `lint:mo`'s job.
 * This gate only checks reachability into the catalogue, nothing about translation quality.
 *
 * Run: npm run lint:i18n-sources
 * (accepts optional overrides for testing: `node lint-i18n-sources.mjs <sourceDir> <potPath> <poPath>`)
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo, poKey } from './lib/po-file.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SOURCE_DIR = process.argv[ 2 ] || 'woodev';
const POT_PATH = process.argv[ 3 ] ? resolve( process.argv[ 3 ] ) : join( ROOT, 'woodev/languages/woodev-plugin-framework.pot' );
const PO_PATH = process.argv[ 4 ] ? resolve( process.argv[ 4 ] ) : join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const DOMAIN = 'woodev-plugin-framework';

/**
 * Resolves how to invoke wp-cli, in order: `$WP_CLI_PHAR` (a path to the phar, invoked as
 * `php <phar>`), then a `wp` executable on `PATH`. Returns `null` if neither is available —
 * callers MUST treat that as a hard failure, never as "nothing to check". A gate that quietly
 * skips its own check when the tool it depends on is missing is worse than no gate at all,
 * because CI would report green while answering nothing.
 */
function resolveWpCli() {
	const pharPath = process.env.WP_CLI_PHAR;
	if ( pharPath ) {
		// `-d error_reporting=0 -d display_errors=0`: a newer PHP (e.g. 8.5 on a dev machine,
		// unlike the CI job's pinned 8.1) logs a wall of `Deprecated:` notices from wp-cli's own
		// vendored dependencies straight to stderr. Past a few hundred KB that overflows the pipe
		// buffer `execFileSync` reads from on Windows and the call fails with `ENOBUFS` — a
		// spurious failure that has nothing to do with whether the sources are missing from the
		// catalogue. Silencing PHP's own error output (wp-cli prints its OWN warnings, e.g.
		// missing `translators:` comments, through its own channel regardless of this flag) avoids
		// the failure at the source instead of just raising the buffer size.
		return { command: 'php', baseArgs: [ '-d', 'error_reporting=0', '-d', 'display_errors=0', pharPath ] };
	}

	const useShell = process.platform === 'win32';
	try {
		execFileSync( 'wp', [ '--version' ], { stdio: 'ignore', shell: useShell } );
		return { command: 'wp', baseArgs: [], shell: useShell };
	} catch {
		return null;
	}
}

/**
 * The pure comparison core (card #791 report item 6): given the msgids `wp i18n make-pot` just
 * extracted from the sources and the keys already present in each committed catalogue file,
 * returns one problem per source entry missing from `.pot` and/or `.po`. Takes plain entry/key
 * sets so it needs neither a filesystem nor wp-cli to test.
 */
export function findMissingSourceEntries( sourceEntries, potKeys, poKeys ) {
	const problems = [];

	for ( const entry of sourceEntries ) {
		const key = poKey( entry );
		const missingFrom = [];
		if ( ! potKeys.has( key ) ) missingFrom.push( '.pot' );
		if ( ! poKeys.has( key ) ) missingFrom.push( '.po' );

		if ( missingFrom.length ) {
			problems.push( {
				msgid: entry.msgid,
				msgctxt: entry.msgctxt,
				reference: entry.references[ 0 ] || null,
				missingFrom,
			} );
		}
	}

	return problems;
}

function die( message ) {
	console.error( message );
	process.exit( 1 );
}

const wpCli = resolveWpCli();
if ( ! wpCli ) {
	die(
		'i18n source gate: could not find wp-cli.\n' +
			'  Set WP_CLI_PHAR to a wp-cli.phar path, or put a `wp` executable on PATH.\n' +
			'  This gate refuses to pass silently without it — see the header of this script.'
	);
}

const tmpDir = mkdtempSync( join( tmpdir(), 'woodev-i18n-sources-' ) );
const tmpPotPath = join( tmpDir, 'source.pot' );

let sourceEntries;
try {
	try {
		execFileSync(
			wpCli.command,
			[ ...wpCli.baseArgs, 'i18n', 'make-pot', SOURCE_DIR, tmpPotPath, `--domain=${ DOMAIN }` ],
			{ cwd: ROOT, stdio: [ 'ignore', 'pipe', 'pipe' ], shell: wpCli.shell, maxBuffer: 20 * 1024 * 1024 }
		);
	} catch ( err ) {
		die(
			'i18n source gate: `wp i18n make-pot` failed.\n' +
				( err.stderr ? err.stderr.toString() : err.message )
		);
	}

	( { entries: sourceEntries } = parsePo( tmpPotPath ) );
} finally {
	rmSync( tmpDir, { recursive: true, force: true } );
}

const potKeys = new Set( parsePo( POT_PATH ).entries.map( poKey ) );
const poKeys = new Set( parsePo( PO_PATH ).entries.map( poKey ) );

const problems = findMissingSourceEntries( sourceEntries, potKeys, poKeys );

if ( problems.length ) {
	console.error( `\ni18n sources: ${ problems.length } msgid(s) reachable from code but missing from the catalogue\n` );
	for ( const p of problems ) {
		console.error(
			`  ✗ missing from ${ p.missingFrom.join( ' and ' ) }: "${ p.msgid.slice( 0, 120 ) }"` +
				( p.msgctxt ? ` (msgctxt: "${ p.msgctxt }")` : '' )
		);
		console.error( `    referenced from: ${ p.reference || '(no reference)' }` );
	}
	console.error(
		'\nAdd these msgids to woodev/languages/woodev-plugin-framework.pot and ' +
			'woodev-plugin-framework-ru_RU.po — never remove an existing entry to "fix" this. ' +
			'A catalogue entry with no matching source is a different, deliberately unchecked ' +
			'problem (card #775).\n'
	);
	process.exit( 1 );
}

console.log(
	`i18n sources: OK (${ sourceEntries.length } msgid(s) extracted from ${ SOURCE_DIR }/, all present in both catalogue files)`
);
