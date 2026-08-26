#!/usr/bin/env node
/**
 * TypeScript-by-default gate for src/ (#542).
 *
 * Why this exists: ESLint sees a file, not its history, so no stock rule can express "a NEW file
 * must be `.ts`". This script fakes that history with a baseline: every `.js`/`.jsx` file under
 * `src/` at the time #542 landed is grandfathered into ts-baseline.txt. Any `.js`/`.jsx` file the
 * gate finds that is NOT in that list did not exist back then — i.e. it is new — and fails the
 * build. Migrate-on-touch is the other half: rewriting a grandfathered file as `.ts`/`.tsx` means
 * deleting its line from the baseline, so the list shrinks monotonically and the ratchet is
 * visible in every diff that touches it.
 *
 * The baseline itself is also checked for rot: a line naming a file that no longer exists (moved,
 * renamed, deleted) is exactly as wrong as a missing line, so it fails too.
 *
 * Run: npm run lint:ts-baseline
 */

import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SRC = join( ROOT, 'src' );
const BASELINE_PATH = join( ROOT, 'scripts', 'ts-baseline.txt' );

const toPosix = ( p ) => p.split( '\\' ).join( '/' );

const walk = ( dir ) => {
	const out = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const full = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...walk( full ) );
		} else if ( /\.jsx?$/.test( entry.name ) ) {
			out.push( full );
		}
	}
	return out;
};

const baseline = new Set(
	readFileSync( BASELINE_PATH, 'utf8' )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( ( l ) => l && ! l.startsWith( '#' ) )
);

const onDisk = new Set(
	( existsSync( SRC ) ? walk( SRC ) : [] ).map( ( p ) => toPosix( relative( ROOT, p ) ) )
);

const errors = [];

for ( const file of onDisk ) {
	if ( ! baseline.has( file ) ) {
		errors.push(
			`${ file } is a new .js/.jsx file under src/. TypeScript is the default for new files ` +
				`(#542) — author it as .ts/.tsx instead. If it is actually a MIGRATION of a ` +
				`grandfathered file (same responsibility, renamed to .ts), delete that file's line ` +
				`from scripts/ts-baseline.txt instead of adding a new one.`
		);
	}
}

for ( const entry of baseline ) {
	if ( ! existsSync( join( ROOT, entry ) ) ) {
		errors.push(
			`scripts/ts-baseline.txt lists ${ entry }, which no longer exists. Remove its line — ` +
				`a stale baseline entry hides a migration instead of recording it.`
		);
	}
}

if ( errors.length ) {
	console.error( `\nTypeScript-by-default gate: ${ errors.length } problem(s)\n` );
	for ( const e of errors ) {
		console.error( `  ✗ ${ e }\n` );
	}
	process.exit( 1 );
}

console.log(
	`TypeScript-by-default gate: OK (${ baseline.size } grandfathered .js/.jsx file(s) left in src/)`
);
