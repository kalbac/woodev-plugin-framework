/**
 * Tests for the i18n-sources gate (card #791, `scripts/lint-i18n-sources.mjs`).
 *
 * Same approach as `signature-probe.test.js`: the gate is a plain Node CLI script, not a module
 * consumed by the app bundle, so it is driven the same way `npm run lint:i18n-sources` drives it —
 * spawned as a subprocess against fixture files — rather than imported. Jest's transform pipeline
 * (scoped to `.js`/`.ts`/`.tsx`) cannot load a plain ESM `.mjs` file directly (confirmed: both a
 * CJS `require()` and a dynamic `import()` of an `.mjs` fail under this repo's jest config with
 * "Cannot use import statement outside a module"), so a pure-unit test of the comparison core
 * would need the same workaround anyway.
 *
 * NO REAL WP-CLI. Standing up the real `wp i18n make-pot` here would mean shipping a wp-cli.phar
 * fixture and a `php` interpreter as a jest dependency, for a job (`test-js` in ci.yml) that sets
 * up only Node. Instead, these tests put a fake `wp` executable on `PATH` — a plain Node script —
 * that answers `--version` and `i18n make-pot` with a body the test controls entirely via
 * `FAKE_WP_CLI_POT_BODY`. This exercises the gate's actual code path (arg building, wp-cli
 * resolution via `PATH`, `.pot`/`.po` parsing, the comparison core, exit codes, and error text)
 * without depending on wp-cli's real extraction being correct — that correctness is what the
 * coordinator measured by hand against the real WP-CLI 2.12.0 (see card #791), not something this
 * suite re-proves.
 *
 * WHAT IS LEFT UNPROVEN. Whether the real `wp i18n make-pot` output this gate parses in CI
 * actually matches what these fixtures simulate — i.e. whether `parsePo` correctly round-trips
 * real wp-cli output — is covered by `lint-i18n.mjs`/`lint-mo.mjs` already exercising `parsePo`
 * against the real committed catalogue in every CI run, not by this file.
 *
 * @see scripts/lint-i18n-sources.mjs
 */

'use strict';

const { execFileSync } = require( 'node:child_process' );
const { mkdtempSync, writeFileSync, mkdirSync, rmSync, chmodSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const path = require( 'node:path' );

const SCRIPT = path.resolve( __dirname, '../../scripts/lint-i18n-sources.mjs' );
const IS_WINDOWS = process.platform === 'win32';

const POT_HEADER = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n';

/** Writes a fake `wp` executable (a plain Node/CJS script) into `binDir`, resolvable via PATH. */
function installFakeWpCli( binDir ) {
	const implPath = path.join( binDir, 'wp-fake-impl.cjs' );
	writeFileSync(
		implPath,
		`
const fs = require('fs');
const argv = process.argv.slice(2);
if (argv[0] === '--version') {
	console.log('WP-CLI fake');
	process.exit(0);
}
if (argv[0] === 'i18n' && argv[1] === 'make-pot') {
	fs.writeFileSync(argv[3], process.env.FAKE_WP_CLI_POT_BODY || '', 'utf8');
	console.log('Success: POT file successfully generated.');
	process.exit(0);
}
console.error('unsupported fake wp invocation: ' + JSON.stringify(argv));
process.exit(1);
`,
		'utf8'
	);

	if ( IS_WINDOWS ) {
		writeFileSync( path.join( binDir, 'wp.cmd' ), `@node "${ implPath }" %*\r\n`, 'utf8' );
	} else {
		const shimPath = path.join( binDir, 'wp' );
		writeFileSync( shimPath, `#!/usr/bin/env node\nrequire(${ JSON.stringify( implPath ) });\n`, 'utf8' );
		chmodSync( shimPath, 0o755 );
	}
}

/** Runs the gate with a fake `wp` on PATH and the given fixtures; never throws — returns `{ status, stdout, stderr }`. */
function runGate( { potBody, poBody, sourcePotBody, withFakeWpCli = true } ) {
	const dir = mkdtempSync( path.join( tmpdir(), 'lint-i18n-sources-test-' ) );
	try {
		const potPath = path.join( dir, 'catalogue.pot' );
		const poPath = path.join( dir, 'catalogue.po' );
		const sourceDir = path.join( dir, 'source' );
		mkdirSync( sourceDir );
		writeFileSync( potPath, POT_HEADER + potBody, 'utf8' );
		writeFileSync( poPath, POT_HEADER + poBody, 'utf8' );

		const env = { ...process.env, FAKE_WP_CLI_POT_BODY: POT_HEADER + sourcePotBody };
		delete env.WP_CLI_PHAR;

		if ( withFakeWpCli ) {
			const binDir = path.join( dir, 'bin' );
			mkdirSync( binDir );
			installFakeWpCli( binDir );
			env.PATH = binDir + path.delimiter + process.env.PATH;
		} else {
			// A directory that cannot contain `wp` or `php`, so wp-cli resolution genuinely fails
			// regardless of what happens to be installed on the machine running this test.
			env.PATH = path.join( dir, 'empty-path' );
			mkdirSync( env.PATH );
		}

		try {
			// `process.execPath` (an absolute path), never the bare `node` command: the fixture
			// PATH below is deliberately too restrictive for `withFakeWpCli: false` to resolve
			// `wp`/`php` inside the gate — it must not also break resolving `node` for this very
			// spawn.
			const stdout = execFileSync( process.execPath, [ SCRIPT, sourceDir, potPath, poPath ], {
				encoding: 'utf8',
				env,
			} );
			return { status: 0, stdout, stderr: '' };
		} catch ( err ) {
			return { status: err.status, stdout: err.stdout || '', stderr: err.stderr || '' };
		}
	} finally {
		rmSync( dir, { recursive: true, force: true } );
	}
}

describe( 'lint-i18n-sources', () => {
	it( 'passes when every extracted msgid is present in both catalogue files', () => {
		const result = runGate( {
			sourcePotBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
			potBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
			poBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
		} );

		expect( result.status ).toBe( 0 );
		expect( result.stdout ).toMatch( /OK/ );
	} );

	it( 'fails and names the msgid when it is missing from both catalogue files', () => {
		const result = runGate( {
			sourcePotBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n\n#: b.php:42\nmsgid "New string"\nmsgstr ""\n',
			potBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
			poBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
		} );

		expect( result.status ).toBe( 1 );
		expect( result.stderr ).toMatch( /New string/ );
		expect( result.stderr ).toMatch( /b\.php:42/ );
		expect( result.stderr ).toMatch( /\.pot and \.po|missing from \.pot and \.po/ );
	} );

	it( 'reports a msgid missing from only one catalogue file, not the other', () => {
		const result = runGate( {
			sourcePotBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
			potBody: '#: a.php:1\nmsgid "Привет"\nmsgstr ""\n',
			poBody: '', // present in .pot, absent from .po
		} );

		expect( result.status ).toBe( 1 );
		expect( result.stderr ).toMatch( /missing from \.po/ );
		expect( result.stderr ).not.toMatch( /missing from \.pot/ );
	} );

	it( 'treats the same msgid text under different msgctxt as distinct entries (#791: km/m/mi)', () => {
		const result = runGate( {
			sourcePotBody:
				'msgctxt "distance unit abbreviation: metres"\nmsgid "m"\nmsgstr ""\n\n' +
				'msgctxt "distance unit abbreviation: miles"\nmsgid "m"\nmsgstr ""\n',
			// Only the "metres" context made it into the catalogue — the "miles" one (same msgid
			// text "m", different msgctxt) must still be reported missing.
			potBody: 'msgctxt "distance unit abbreviation: metres"\nmsgid "m"\nmsgstr ""\n',
			poBody: 'msgctxt "distance unit abbreviation: metres"\nmsgid "m"\nmsgstr ""\n',
		} );

		expect( result.status ).toBe( 1 );
		expect( result.stderr ).toMatch( /distance unit abbreviation: miles/ );
	} );

	it( 'never passes silently when wp-cli cannot be resolved at all', () => {
		const result = runGate( {
			sourcePotBody: '',
			potBody: '',
			poBody: '',
			withFakeWpCli: false,
		} );

		expect( result.status ).toBe( 1 );
		expect( result.stderr ).toMatch( /could not find wp-cli/i );
	} );
} );
