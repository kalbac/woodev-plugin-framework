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
import { existsSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo, poKey } from './lib/po-file.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SOURCE_DIR = process.argv[ 2 ] || 'woodev';
const POT_PATH = process.argv[ 3 ] ? resolve( process.argv[ 3 ] ) : join( ROOT, 'woodev/languages/woodev-plugin-framework.pot' );
const PO_PATH = process.argv[ 4 ] ? resolve( process.argv[ 4 ] ) : join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const DOMAIN = 'woodev-plugin-framework';

/**
 * The wp-cli version this gate is pinned to. It is CI's pin AND the version the rig container
 * runs, which is not a coincidence: `make-pot`'s extraction is the gate's whole answer, so
 * letting it drift with whatever wp-cli happens to be installed would let the answer drift too.
 */
const WP_CLI_VERSION = '2.12.0';

/**
 * Where an auto-provisioned phar is kept. Deliberately OUTSIDE the repo: card #800's whole
 * complaint is that every agent worktree lacks wp-cli, and a per-worktree copy would be ~7 MB
 * each and would need someone to remember to update the pin. One cache under the user's home is
 * shared by the primary checkout and every worktree at once.
 */
const WP_CLI_CACHE = join( homedir(), '.cache', 'woodev-plugin-framework', `wp-cli-${ WP_CLI_VERSION }.phar` );

/** `php <phar>` with PHP's own error output silenced — see the long note in `resolveWpCli()`. */
const pharInvocation = ( pharPath ) => ( {
	command: 'php',
	baseArgs: [ '-d', 'error_reporting=0', '-d', 'display_errors=0', pharPath ],
} );

/**
 * Returns the version string a phar reports, or `null` if it cannot be run at all.
 */
function pharVersion( pharPath ) {
	try {
		const { command, baseArgs } = pharInvocation( pharPath );
		return execFileSync( command, [ ...baseArgs, '--version' ], {
			stdio: [ 'ignore', 'pipe', 'ignore' ],
			timeout: 60_000,
		} )
			.toString()
			.trim();
	} catch {
		return null;
	}
}

/**
 * Last-resort source for wp-cli: copy it out of the running rig container.
 *
 * Card #800 listed three options and this is a fourth, which the card did not have because the
 * fact underneath it was only measured in s123: **the wp-env container already ships wp-cli
 * 2.12.0 — the exact version CI pins.** So this needs no network call (the card's objection to
 * on-demand downloading), adds nothing to the repo or to `.worktreeinclude` (its objection to
 * vendoring the phar), and cannot drift from the rig, which is also the only thing allowed to
 * compile the `.mo`.
 *
 * The version is VERIFIED before the copy is cached. Choosing a tool silently obliges this to
 * prove it chose the right one; a container running some other wp-cli must fail loudly rather
 * than quietly change what the gate measures.
 *
 * Returns the cached path, or `null` when docker is absent, no container is up, or the version
 * does not match — every one of which leaves the caller to fail hard, per this file's contract.
 */
function provisionFromRigContainer() {
	let containers;
	try {
		containers = execFileSync( 'docker', [ 'ps', '--format', '{{.Names}}' ], {
			stdio: [ 'ignore', 'pipe', 'ignore' ],
			timeout: 30_000,
		} )
			.toString()
			.split( /\r?\n/ )
			.filter( ( name ) => /-cli-1$/.test( name ) );
	} catch {
		return null;
	}

	for ( const container of containers ) {
		mkdirSync( dirname( WP_CLI_CACHE ), { recursive: true } );

		try {
			// An argument ARRAY, never a shell string: Git-Bash rewrites `/usr/local/bin/wp` into
			// `C:/Program Files/Git/usr/local/bin/wp` when it passes through a shell (gotcha
			// `wpenv-windows-gitbash-path-mangling`), and `execFileSync` without `shell` never
			// gives MSYS the chance.
			execFileSync( 'docker', [ 'cp', `${ container }:/usr/local/bin/wp`, WP_CLI_CACHE ], {
				stdio: 'ignore',
				timeout: 120_000,
			} );
		} catch {
			continue;
		}

		if ( pharVersion( WP_CLI_CACHE )?.includes( WP_CLI_VERSION ) ) {
			return WP_CLI_CACHE;
		}

		rmSync( WP_CLI_CACHE, { force: true } );
	}

	return null;
}

/**
 * Resolves how to invoke wp-cli, in order: `$WP_CLI_PHAR` (a path to the phar, invoked as
 * `php <phar>`), a `wp` executable on `PATH`, an already-cached pinned phar, and finally one
 * copied out of the running rig container. Returns `null` if none is available — callers MUST
 * treat that as a hard failure, never as "nothing to check". A gate that quietly skips its own
 * check when the tool it depends on is missing is worse than no gate at all, because CI would
 * report green while answering nothing.
 *
 * The last two sources exist for card #800: in s121 two workers in a row could not run this gate
 * from their worktree and each honestly reported "could not run it". The reasoning was sound both
 * times, and that is precisely the shape in which a gate stops being one.
 *
 * Also returns a `source` label. A gate that silently picks its own tool has to say which one it
 * picked, or a version drift becomes invisible in exactly the log where it would be caught.
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
		return { ...pharInvocation( pharPath ), source: `$WP_CLI_PHAR (${ pharPath })` };
	}

	const useShell = process.platform === 'win32';
	try {
		execFileSync( 'wp', [ '--version' ], { stdio: 'ignore', shell: useShell } );
		return { command: 'wp', baseArgs: [], shell: useShell, source: '`wp` on PATH' };
	} catch {
		// Not on PATH — fall through to the cached/provisioned phar below.
	}

	if ( existsSync( WP_CLI_CACHE ) && pharVersion( WP_CLI_CACHE )?.includes( WP_CLI_VERSION ) ) {
		return { ...pharInvocation( WP_CLI_CACHE ), source: `cached phar (${ WP_CLI_CACHE })` };
	}

	const provisioned = provisionFromRigContainer();
	if ( provisioned ) {
		return { ...pharInvocation( provisioned ), source: `copied from the rig container to ${ provisioned }` };
	}

	return null;
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
		`i18n source gate: could not find wp-cli ${ WP_CLI_VERSION }.\n` +
			'  Tried, in order: $WP_CLI_PHAR, `wp` on PATH, the cached phar at\n' +
			`  ${ WP_CLI_CACHE }, and a copy out of a running wp-env container.\n` +
			'\n' +
			'  Easiest fix: start the rig (`npx wp-env start`) and re-run — this gate copies\n' +
			'  wp-cli out of the container itself and caches it for every worktree.\n' +
			`  Otherwise: curl -sSfL -o "${ WP_CLI_CACHE }" \\\n` +
			`    https://github.com/wp-cli/wp-cli/releases/download/v${ WP_CLI_VERSION }/wp-cli-${ WP_CLI_VERSION }.phar\n` +
			'\n' +
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
console.log( `  wp-cli: ${ wpCli.source }` );
