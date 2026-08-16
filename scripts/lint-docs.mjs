#!/usr/bin/env node
/**
 * Structural gate for docs-internal/.
 *
 * Why this exists: the rules it enforces were already written in DOCS-SCHEMA.md — "max 1 line per
 * index entry", "maximum 3 lines of last-session context", "gateway files must not duplicate
 * docs-internal" — and every one of them was violated for fourteen sessions straight. Written
 * rules did not hold; a failing job does.
 *
 * The metric that matters is the FIRST one: what a session must read before it can do anything.
 * Every session pays that cost, so it is the thing worth gating.
 *
 * Run: npm run lint:docs
 */

import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const INTERNAL = join( ROOT, 'docs-internal' );

const errors = [];
const notes = [];

const fail = ( msg ) => errors.push( msg );
const kb = ( bytes ) => `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
const read = ( p ) => readFileSync( p, 'utf8' );
const size = ( p ) => ( existsSync( p ) ? statSync( p ).size : 0 );

/* ------------------------------------------------------------------ *
 * 1. The session-start budget — the reason this gate exists.
 * ------------------------------------------------------------------ */

const START_SET = [
	[ 'AGENTS.md', join( ROOT, 'AGENTS.md' ), 24 * 1024 ],
	[ 'CLAUDE.md', join( ROOT, 'CLAUDE.md' ), 8 * 1024 ],
	[ 'docs-internal/next-session-prompt.md', join( INTERNAL, 'next-session-prompt.md' ), 16 * 1024 ],
	[ 'docs-internal/CURRENT-STATE.md', join( INTERNAL, 'CURRENT-STATE.md' ), 24 * 1024 ],
	[ 'docs-internal/GOTCHAS.md', join( INTERNAL, 'GOTCHAS.md' ), 56 * 1024 ],
];

const BUDGET = 120 * 1024;
let total = 0;

for ( const [ label, path, limit ] of START_SET ) {
	if ( ! existsSync( path ) ) {
		fail( `${ label } is missing — it is part of the session-start set.` );
		continue;
	}
	const bytes = size( path );
	total += bytes;
	if ( bytes > limit ) {
		fail(
			`${ label } is ${ kb( bytes ) }, over its ${ kb( limit ) } limit. ` +
				`This file is read at the start of EVERY session — move the detail to the file that owns it ` +
				`(history → SESSION-LOG.md/sessions/, gotcha detail → gotchas/{slug}.md, reference → wiki/).`
		);
	}
}

if ( total > BUDGET ) {
	fail(
		`Session-start reading is ${ kb( total ) }, over the ${ kb( BUDGET ) } budget. ` +
			`Every session pays this before doing any work.`
	);
} else {
	notes.push( `session-start reading: ${ kb( total )} of ${ kb( BUDGET ) } budget` );
}

/* ------------------------------------------------------------------ *
 * 2. CURRENT-STATE.md holds state, never history.
 * ------------------------------------------------------------------ */

const currentState = read( join( INTERNAL, 'CURRENT-STATE.md' ) );

const priorLines = currentState
	.split( '\n' )
	.filter( ( l ) => /^>\s*Prior:/i.test( l ) );

if ( priorLines.length ) {
	fail(
		`CURRENT-STATE.md carries ${ priorLines.length } "Prior:" line(s). It describes the CURRENT ` +
			`state only — previous sessions belong in sessions/sNN.md, indexed by SESSION-LOG.md.`
	);
}

if ( /^##\s+.*(Урок сессии|Lessons? (from|learned))/im.test( currentState ) ) {
	fail(
		`CURRENT-STATE.md has a lessons section. A lesson is a gotcha (gotchas/{slug}.md) when it is ` +
			`about code or a mechanism, and a session entry otherwise — never a third copy here.`
	);
}

/* ------------------------------------------------------------------ *
 * 3. GOTCHAS.md is an index: one line per entry, one file per gotcha.
 * ------------------------------------------------------------------ */

const gotchas = read( join( INTERNAL, 'GOTCHAS.md' ) );
const indexAt = gotchas.indexOf( '## Index' );

if ( indexAt === -1 ) {
	fail( 'GOTCHAS.md has no "## Index" heading.' );
} else {
	const headerLines = gotchas.slice( 0, indexAt ).split( '\n' ).length;
	if ( headerLines > 15 ) {
		fail(
			`GOTCHAS.md header is ${ headerLines } lines before "## Index" (max 15). ` +
				`A changelog of what was added when belongs in SESSION-LOG.md, not above the index.`
		);
	}

	// The Archive section holds resolved gotchas awaiting removal — they have no live detail file
	// by design, so the index rules stop where it begins.
	const archiveAt = gotchas.indexOf( '## Archive' );
	const indexBody = gotchas.slice( indexAt, archiveAt === -1 ? undefined : archiveAt );

	const ENTRY_MAX = 400;
	const entries = indexBody.split( '\n' ).filter( ( l ) => l.startsWith( '- [' ) );

	const tooLong = entries.filter( ( l ) => l.length > ENTRY_MAX );
	for ( const l of tooLong.slice( 0, 5 ) ) {
		fail(
			`GOTCHAS.md entry is ${ l.length } chars (max ${ ENTRY_MAX }): "${ l.slice( 0, 80 ) }…". ` +
				`The index carries a hook; the detail lives in the linked file.`
		);
	}
	if ( tooLong.length > 5 ) {
		fail( `…and ${ tooLong.length - 5 } more over-long GOTCHAS.md entries.` );
	}

	// every entry resolves to a file, every file is indexed
	const linked = new Set(
		[ ...indexBody.matchAll( /\(gotchas\/([a-z0-9-]+)\.md\)/g ) ].map( ( m ) => m[ 1 ] )
	);
	linked.delete( 'slug' ); // the format comment

	const files = new Set(
		readdirSync( join( INTERNAL, 'gotchas' ) )
			.filter( ( f ) => f.endsWith( '.md' ) && f !== 'README.md' )
			.map( ( f ) => f.replace( /\.md$/, '' ) )
	);

	for ( const slug of linked ) {
		if ( ! files.has( slug ) ) {
			fail( `GOTCHAS.md links gotchas/${ slug }.md, which does not exist.` );
		}
	}
	for ( const slug of files ) {
		if ( ! linked.has( slug ) ) {
			fail( `gotchas/${ slug }.md is not listed in GOTCHAS.md — an unindexed gotcha is invisible.` );
		}
	}

	const entriesWithoutLink = entries.filter( ( l ) => ! /\(gotchas\/[a-z0-9-]+\.md\)/.test( l ) );
	for ( const l of entriesWithoutLink ) {
		fail(
			`GOTCHAS.md entry has no detail file: "${ l.slice( 0, 80 ) }…". ` +
				`Every gotcha is its own file — an index-only note has nowhere to grow.`
		);
	}

	notes.push( `gotchas: ${ files.size } files, ${ entries.length } index entries` );
}

/* ------------------------------------------------------------------ *
 * 4. SESSION-LOG.md is an index over sessions/, not the log itself.
 * ------------------------------------------------------------------ */

const sessionLogPath = join( INTERNAL, 'SESSION-LOG.md' );
const SESSION_LOG_MAX = 48 * 1024;

if ( existsSync( sessionLogPath ) && size( sessionLogPath ) > SESSION_LOG_MAX ) {
	fail(
		`SESSION-LOG.md is ${ kb( size( sessionLogPath ) ) }, over ${ kb( SESSION_LOG_MAX ) }. ` +
			`It indexes sessions/sNN.md — one line per session; the session's detail goes in its own file.`
	);
}

const sessionsDir = join( INTERNAL, 'sessions' );
if ( existsSync( sessionsDir ) ) {
	const sessionFiles = readdirSync( sessionsDir ).filter( ( f ) => /^s\d+\.md$/.test( f ) );
	const log = existsSync( sessionLogPath ) ? read( sessionLogPath ) : '';
	for ( const f of sessionFiles ) {
		if ( ! log.includes( `sessions/${ f }` ) ) {
			fail( `sessions/${ f } is not linked from SESSION-LOG.md.` );
		}
	}
	notes.push( `sessions: ${ sessionFiles.length } files` );
}

/* ------------------------------------------------------------------ *
 * Report
 * ------------------------------------------------------------------ */

for ( const n of notes ) {
	console.log( `  ${ n }` );
}

if ( errors.length ) {
	console.error( `\ndocs-internal structure: ${ errors.length } problem(s)\n` );
	for ( const e of errors ) {
		console.error( `  ✗ ${ e }\n` );
	}
	console.error( 'Rules: docs-internal/DOCS-SCHEMA.md\n' );
	process.exit( 1 );
}

console.log( '\ndocs-internal structure: OK' );
