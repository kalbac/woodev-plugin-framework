#!/usr/bin/env node
/**
 * Fails when the Russian translation catalogue has an ENGLISH msgid with no translation.
 *
 * WHY THIS EXISTS. Card #771: 147 admin/customer-facing strings had accumulated with an English
 * msgid and an empty msgstr — the merchant reads them in English in an otherwise Russian admin.
 * The rule was already written down (AGENTS.md → Conventions → "Translatable strings") but nothing
 * enforced it, so the gap grew silently for months. This is the enforcement.
 *
 * IS-IT-ENGLISH RULE. A msgid is treated as English when it contains no Cyrillic character
 * (`[Ѐ-ӿ]`). This is deliberately the SAME test used to measure card #771 (298 Russian-msgid
 * entries with an empty msgstr are normal — gettext returns the bare msgid then — and are never
 * flagged). It is a conservative, over-inclusive test: a msgid that is only a placeholder (`%s`),
 * a number, or a URL contains no Cyrillic either and IS flagged, even though it carries no
 * language-dependent content. That is intentional — false positives are cheap (one allowlist line
 * with a reason) and false negatives are not, so the gate leans toward flagging.
 *
 * NEVER-RENDERED / UNREACHABLE STRINGS. A msgid that is genuinely never read from a screen (a log
 * line, an exception message nothing catches, a `_doing_it_wrong()` developer notice, or a msgid
 * pointing at a source file that no longer exists) does not need translating at all — see
 * docs-internal/gotchas/classify-an-i18n-string-by-its-render-path-not-its-file-path.md for how to
 * tell. Those go in `scripts/i18n-allowlist.json` with a one-line reason each — never a regex, so
 * every skip is reviewable in a diff.
 *
 * Run: npm run lint:i18n
 */

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo, poKey } from './lib/po-file.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const ALLOWLIST_PATH = join( ROOT, 'scripts/i18n-allowlist.json' );

const isCyrillic = ( s ) => /[Ѐ-ӿ]/.test( s );
const isEmpty = ( entry ) =>
	( entry.msgstr === '' || entry.msgstr === null ) &&
	( ! entry.msgstrPlural || entry.msgstrPlural.every( ( s ) => ! s ) );

const { entries } = parsePo( PO_PATH );
const allowlist = JSON.parse( readFileSync( ALLOWLIST_PATH, 'utf8' ) ).entries;

const allowlistByKey = new Map();
for ( const a of allowlist ) {
	const key = ( a.msgctxt ? a.msgctxt + '\x04' : '' ) + a.msgid;
	allowlistByKey.set( key, a );
}

const catalogueKeys = new Set( entries.map( poKey ) );
const errors = [];
const usedAllowlistKeys = new Set();

for ( const entry of entries ) {
	if ( isCyrillic( entry.msgid ) || ! isEmpty( entry ) ) {
		continue;
	}

	const key = poKey( entry );
	const allowed = allowlistByKey.get( key );

	if ( allowed ) {
		usedAllowlistKeys.add( key );
		continue;
	}

	errors.push(
		`English msgid with no translation (line ${ entry.lineNo }): "${ entry.msgid.slice( 0, 100 ) }"\n` +
			`    referenced from: ${ entry.references[ 0 ] || '(no reference)' }\n` +
			'    Either add a Russian msgstr to woodev/languages/woodev-plugin-framework-ru_RU.po, ' +
			'or, if the string never reaches a screen, add it to scripts/i18n-allowlist.json with a reason.'
	);
}

// A stale allowlist entry — msgid removed from the catalogue, or renamed — hides nothing (it
// simply never matches), but it does mean the reason on file no longer describes anything real.
for ( const a of allowlist ) {
	const key = ( a.msgctxt ? a.msgctxt + '\x04' : '' ) + a.msgid;

	if ( ! catalogueKeys.has( key ) ) {
		errors.push(
			`scripts/i18n-allowlist.json has an entry for "${ a.msgid.slice( 0, 100 ) }" that no longer ` +
				'exists in the catalogue (msgid changed or the entry was removed). Delete the stale allowlist entry.'
		);
	}
}

if ( errors.length ) {
	console.error( `\ni18n catalogue: ${ errors.length } problem(s)\n` );
	for ( const e of errors ) {
		console.error( `  ✗ ${ e }\n` );
	}
	console.error( 'Rules: AGENTS.md → Conventions → "Translatable strings"\n' );
	process.exit( 1 );
}

console.log(
	`i18n catalogue: OK (${ entries.length } entries, ${ usedAllowlistKeys.size } allowlisted as never-rendered)`
);
