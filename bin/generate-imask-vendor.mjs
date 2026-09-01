#!/usr/bin/env node
/**
 * Regenerates the vendored IMask bundle read by `phone-mask.js` at
 * `woodev/shipping-method/assets/js/vendor/imask.min.js`, from the `imask` devDependency.
 *
 * WHY THIS IS GENERATED RATHER THAN FETCHED BY HAND. The file arrived in s109 (card #503,
 * ADR-011) as the framework's FIRST vendored third-party RUNTIME library, downloaded once from a
 * pinned jsDelivr URL. That left it with no update path at all: Dependabot cannot see a file in
 * the repository, only an entry in `package.json`, and Composer cannot see a frontend library at
 * all. Card #704 is the consequence — a year later we could be shipping a bundle with known bugs
 * and have no mechanism that would tell us. Making `imask` a devDependency and generating the
 * vendored copy from it puts the version under Dependabot's eye while keeping the SHIPPED asset
 * a plain file with no build step at that layer, which is what ADR-011 chose deliberately.
 *
 * WHY imask STAYS A devDependency. Same reasoning as `libphonenumber-js` in
 * `bin/generate-phone-masks.mjs`: the decision is made at build time and only the RESULT ships.
 * `src/` is bundled by wp-scripts, but the checkout frontend under
 * `woodev/shipping-method/assets/js/frontend/` is served raw — it must not gain a build step.
 *
 * THE THREE PLACES A VERSION IS WRITTEN, which is exactly why this script exists. Before it, a
 * bump meant remembering all three by hand, and the enqueue comment said so in prose:
 *   1. `package.json` -> devDependencies.imask   (the source of truth)
 *   2. this file's banner in the vendored bundle  (written here)
 *   3. `wp_enqueue_script( 'woodev-imask', ..., '<version>', ... )` in class-checkout-handler.php
 * `--check` fails when any of the three disagree, so the reminder is now a gate.
 *
 * Usage:
 *   node bin/generate-imask-vendor.mjs          # rewrite the vendored bundle in place
 *   node bin/generate-imask-vendor.mjs --check  # verify it is current, exit 1 if not (CI-friendly)
 *
 * @package woodev-plugin-framework
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const UPSTREAM_MIN = new URL( '../node_modules/imask/dist/imask.min.js', import.meta.url );
const UPSTREAM_PKG = new URL( '../node_modules/imask/package.json', import.meta.url );
const TARGET = new URL( '../woodev/shipping-method/assets/js/vendor/imask.min.js', import.meta.url );
const ENQUEUE = new URL( '../woodev/shipping-method/checkout/class-checkout-handler.php', import.meta.url );

const check = process.argv.includes( '--check' );

function die( message, hint ) {
	console.error( message );

	if ( hint ) {
		console.error( hint );
	}

	process.exit( 1 );
}

if ( ! existsSync( UPSTREAM_MIN ) || ! existsSync( UPSTREAM_PKG ) ) {
	die(
		'Refusing to run — the `imask` devDependency is not installed.',
		'Run: npm ci   (or `npm install` if you are adding/bumping it)'
	);
}

const version = JSON.parse( readFileSync( UPSTREAM_PKG, 'utf8' ) ).version;
const body = readFileSync( UPSTREAM_MIN, 'utf8' ).trimStart();

/**
 * The banner. Upstream's own build already carries a one-line `/*! ... *\/` credit; this replaces
 * it with the same credit plus the provenance a reader of THIS repository needs — most of all,
 * that the file is generated and must not be hand-edited.
 */
const banner = [
	'/*!',
	` * IMask.js v${ version } (https://imask.js.org)`,
	' * Repository: https://github.com/uNmAnNeR/imaskjs',
	' * License: MIT (https://github.com/uNmAnNeR/imaskjs/blob/master/LICENSE)',
	' *',
	' * GENERATED FILE — do not edit by hand, and do not fetch a replacement from a CDN.',
	' * Produced by `npm run generate:imask` from the `imask` devDependency, so the shipped',
	' * version is the one Dependabot watches in package.json (card #704). `npm run lint:imask`',
	' * fails when this file, package.json and the `woodev-imask` enqueue version disagree.',
	' *',
	' * Vendored rather than CDN-loaded on purpose: a shop\'s checkout must not depend on a',
	' * third-party host. Served raw, with no build step at this layer — see ADR-011.',
	' */',
	'',
].join( '\n' );

// Upstream ships its own `/*! ... */` credit line; drop it so the file carries exactly one banner.
const generated = banner + body.replace( /^\/\*![\s\S]*?\*\/\s*/, '' );

/** Reads the version string the `woodev-imask` handle is enqueued with. */
function enqueuedVersion() {
	const php = readFileSync( ENQUEUE, 'utf8' );
	const match = php.match( /'woodev-imask',[\s\S]{0,400}?\[\s*\],\s*'([^']+)'/ );

	return match ? match[ 1 ] : null;
}

const enqueued = enqueuedVersion();

if ( null === enqueued ) {
	die(
		'Refusing to run — could not read the `woodev-imask` enqueue version from class-checkout-handler.php.',
		'The wp_enqueue_script() call was probably reshaped; update the regex in this script to match.'
	);
}

if ( enqueued !== version ) {
	die(
		`The \`woodev-imask\` enqueue version is '${ enqueued }' but the installed imask is ${ version }.`,
		'Update the version string in woodev/shipping-method/checkout/class-checkout-handler.php to match, ' +
			'then re-run. Cache busting depends on it.'
	);
}

if ( check ) {
	const committed = existsSync( TARGET ) ? readFileSync( TARGET, 'utf8' ) : '';

	if ( committed !== generated ) {
		die(
			'The committed IMask bundle is stale or hand-edited.',
			'Run: npm run generate:imask'
		);
	}

	console.log( `Vendored IMask is current (v${ version }, enqueue and package.json agree).` );
	process.exit( 0 );
}

writeFileSync( TARGET, generated );
console.log(
	`Wrote imask.min.js — v${ version }, ${ generated.length } bytes ` +
		`(enqueue version '${ enqueued }' matches).`
);
