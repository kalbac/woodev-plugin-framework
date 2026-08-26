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

import { readFileSync, readdirSync, existsSync, statSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
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
 *
 * WHAT THE NUMBER IS. 176 KB is an OPERATOR DECISION (#554, 27.08.2026), not
 * a derived limit. Do not cite it as one. It is a governance ceiling on how
 * much of a fresh agent's context is spent BEFORE it does any work.
 *
 * The sanity check that sized it — and every step of it is an ASSUMPTION, not
 * a measurement: a 200k-token context, of which 25% is judged acceptable to
 * spend on session-start reading (that 25% is a judgement call with no source
 * behind it) = 50k tokens; at roughly 3.5 bytes/token for mixed RU/EN markdown
 * (an estimate — no tokenizer was run) that lands near 176 KB. It says the
 * number is not absurd. It does not prove it. The previous 120 KB had no
 * derivation at all, which is the only sense in which this is an improvement.
 *
 * WHAT WAS ACTUALLY MEASURED. Byte sizes across s86 (a0bcace) -> s96 (5630663),
 * ten sessions:
 *
 *   GOTCHAS.md               46,810 -> 55,323   +8,513   (+851 B/session)
 *   AGENTS.md                21,914 -> 23,192   +1,278   (+128 B/session)
 *   CLAUDE.md                 7,224 ->  7,983     +759    (+76 B/session)
 *   next-session-prompt.md   10,920 -> 10,532     -388   (SHRANK)
 *   CURRENT-STATE.md         24,357 -> 23,907     -450   (SHRANK)
 *   whole set                                   +9,712   (+971 B/session)
 *
 * GOTCHAS.md is 88% of the growth, so it gets the slack. The other four are
 * NOT interchangeable, and an earlier draft of this comment got their causes
 * wrong twice — first calling all four "flat", then attributing both shrinking
 * files to one rule. What actually bounds each:
 *
 *   CURRENT-STATE.md       its own hard rule, "state only, never history"
 *                          (DOCS-SCHEMA.md -> CURRENT-STATE.md Format). That
 *                          rule names THIS FILE and no other.
 *   next-session-prompt.md REPLACED wholesale every session end, so it cannot
 *                          accumulate. A different mechanism, not that rule.
 *   AGENTS.md, CLAUDE.md   nothing bounds them but the caps below — which is
 *                          why they are the two that grow.
 *
 * At the whole-set rate, 176 KB is ~61 sessions of headroom.
 *
 * If this binds again, do not raise it a third time. The structural fix #554
 * also proposed: split GOTCHAS.md into per-tag indexes and read only the tag
 * map at session start.
 * ------------------------------------------------------------------ */

const START_SET = [
	[ 'AGENTS.md', join( ROOT, 'AGENTS.md' ), 28 * 1024 ],
	[ 'CLAUDE.md', join( ROOT, 'CLAUDE.md' ), 12 * 1024 ],
	[ 'docs-internal/next-session-prompt.md', join( INTERNAL, 'next-session-prompt.md' ), 16 * 1024 ],
	[ 'docs-internal/CURRENT-STATE.md', join( INTERNAL, 'CURRENT-STATE.md' ), 28 * 1024 ],
	[ 'docs-internal/GOTCHAS.md', join( INTERNAL, 'GOTCHAS.md' ), 96 * 1024 ],
];

const BUDGET = 176 * 1024;
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

	// The index may hand older entries to an archive it links to (s96: 95 paragraph-length
	// entries had filled this file's own 48 KB gate). Reachability is what this rule protects,
	// so an entry counts wherever it lives, as long as SESSION-LOG.md itself points there —
	// an archive nothing links to would leave those sessions unreachable and still fails.
	const archived = [ ...log.matchAll( /\((archive\/[^)]+\.md)\)/g ) ]
		.map( ( m ) => join( INTERNAL, m[ 1 ] ) )
		.filter( ( f ) => existsSync( f ) )
		.map( ( f ) => read( f ) )
		.join( '\n' );

	const indexed = `${ log }\n${ archived }`;

	for ( const f of sessionFiles ) {
		if ( ! indexed.includes( `sessions/${ f }` ) ) {
			fail( `sessions/${ f } is not linked from SESSION-LOG.md (or an archive it links to).` );
		}
	}
	notes.push( `sessions: ${ sessionFiles.length } files` );
}

/* ------------------------------------------------------------------ *
 * The handoff contract (docs-internal/next-session-prompt.md).
 *
 * Why this section exists, in the operator's own words (25.08.2026): "в каждой сессии ты пишешь
 * его так как ты хочешь. Нет чётких правил как писать, что в нём должно быть, а чего не должно".
 *
 * The handoff is the ONLY document read first, every session, without exception — and it was the
 * only one with no schema. The consequence is not a messy file; it is that **an unfinished
 * commitment leaves the project by simply not being mentioned again**, and no one finds out. The
 * operator's example: a task discussed in one session and not built in the next must arrive in the
 * one after that, carrying the fact that it was already discussed.
 *
 * The load-bearing check is therefore NOT about the current file's shape. It is the DIFF against
 * the previous committed version: every issue in the previous "Обязательства" section must still
 * be accounted for. Silence is what this gate makes impossible.
 * ------------------------------------------------------------------ */

const HANDOFF_REL = 'docs-internal/next-session-prompt.md';
const handoffPath = join( INTERNAL, 'next-session-prompt.md' );

// The `##` sections a handoff must carry, in this order. Matched by the leading keyword so the
// wording stays the author's; only the contract is fixed.
const HANDOFF_SECTIONS = [
	[ 'Ждёт кнопки', /^#+\s*.*Ждёт кнопки/imu ],
	[ 'Обязательства', /^#+\s*.*Обязательства/imu ],
	[ 'С чего начать', /^#+\s*.*С чего начать/imu ],
	[ 'Доказано замером', /^#+\s*.*Доказано замером/imu ],
	[ 'Ловушки', /^#+\s*.*Ловушки/imu ],
	[ 'Состояние на входе', /^#+\s*.*Состояние на входе/imu ],
];

/**
 * The bullet lines under one `##` section, or `null` when the section is absent.
 */
const sectionBody = ( text, re ) => {
	const m = re.exec( text );

	if ( ! m ) {
		return null;
	}

	const rest = text.slice( m.index + m[ 0 ].length );
	const next = /^#+\s/mu.exec( rest );

	return next ? rest.slice( 0, next.index ) : rest;
};

const issueRefs = ( body ) => new Set( ( body || '' ).match( /#\d+/g ) || [] );

if ( ! existsSync( handoffPath ) ) {
	fail( `${ HANDOFF_REL } is missing — it is the first thing every session reads.` );
} else {
	const handoff = read( handoffPath );

	// 1. Required sections, present and in order.
	let lastAt = -1;
	for ( const [ label, re ] of HANDOFF_SECTIONS ) {
		const m = re.exec( handoff );

		if ( ! m ) {
			fail( `${ HANDOFF_REL } has no "${ label }" section. Required by DOCS-SCHEMA.md → Handoff.` );
			continue;
		}

		if ( m.index < lastAt ) {
			fail( `${ HANDOFF_REL }: "${ label }" is out of order. Required order: ${ HANDOFF_SECTIONS.map( ( s ) => s[ 0 ] ).join( ' → ' ) }.` );
		}

		lastAt = m.index;
	}

	// 2. Every carry-over line names an issue AND the session that decided it — otherwise the
	//    next reader cannot tell a live commitment from a note, or find where it was settled.
	const carry = sectionBody( handoff, HANDOFF_SECTIONS[ 1 ][ 1 ] );

	if ( carry ) {
		// A carry-over item is a LOGICAL bullet, not a physical line: these entries wrap, and the
		// `sNN` saying where a thing was decided lands on a continuation line as often as not.
		// Checking raw lines flagged two CORRECT entries on this gate's very first run.
		const bullets = [];

		for ( const line of carry.split( '\n' ) ) {
			const t = line.trim();

			if ( t.startsWith( '- ' ) || /^\d+\./.test( t ) ) {
				bullets.push( t );
			} else if ( t && bullets.length ) {
				bullets[ bullets.length - 1 ] += ' ' + t;
			}
		}

		for ( const t of bullets ) {

			if ( ! /#\d+/.test( t ) ) {
				fail( `${ HANDOFF_REL }: carry-over line has no issue reference (#N): "${ t.slice( 0, 80 ) }"` );
			}

			if ( ! /\bs\d+\b/.test( t ) ) {
				fail( `${ HANDOFF_REL }: carry-over line does not say WHERE it was decided (sNN): "${ t.slice( 0, 80 ) }"` );
			}
		}
	}

	// 3. Gate numbers must carry the date they were measured. A figure copied forward from a
	//    previous handoff is an inference, and s93 lost real time to exactly that (two of s92's
	//    baselines were wrong and rode into the next session unchallenged).
	if ( /composer check/i.test( handoff ) && ! /\b\d{2}\.\d{2}\.\d{4}\b/.test( handoff ) ) {
		fail( `${ HANDOFF_REL }: gate numbers are quoted without a DD.MM.YYYY measurement date.` );
	}

	// 4. THE ONE THAT MATTERS — nothing leaves the carry-over silently.
	const snapshotPath = `${ handoffPath }.prev`;
	let previous = null;

	try {
		previous = execFileSync( 'git', [ 'show', `HEAD:${ HANDOFF_REL }` ], {
			cwd: ROOT,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'ignore' ],
		} );
	} catch {
		// The handoff is deliberately UNTRACKED here (this repo is public — see .gitignore), so
		// git is not a source at all after the first session. The snapshot below is then the only
		// way the drop check survives; without it the gate would silently degrade to "no previous
		// version" and stop catching the exact failure it exists for.
		previous = existsSync( snapshotPath ) ? read( snapshotPath ) : null;
	}

	if ( previous ) {
		const before = issueRefs( sectionBody( previous, HANDOFF_SECTIONS[ 1 ][ 1 ] ) );
		const stillThere = issueRefs( handoff ); // anywhere in the new file, not only the section

		for ( const ref of before ) {
			if ( ! stillThere.has( ref ) ) {
				fail(
					`${ HANDOFF_REL }: ${ ref } was a carry-over commitment in the PREVIOUS handoff and ` +
					`is not mentioned anywhere in this one. A commitment leaves only by being DONE or ` +
					`by the operator dropping it explicitly — say which, in the file. Never by silence.`
				);
			}
		}

		notes.push( `handoff: ${ before.size } carry-over commitment(s) checked against the previous version` );
	}

	// Refreshed only on a CLEAN run, so a failing run cannot erase the evidence the next run
	// needs. `.prev` is gitignored alongside the handoff itself.
	if ( ! errors.length ) {
		try {
			writeFileSync( snapshotPath, handoff, 'utf8' );
		} catch {
			// A read-only checkout is fine — git is then the only source, the normal case.
		}
	}
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
