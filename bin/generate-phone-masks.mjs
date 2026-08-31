#!/usr/bin/env node
/**
 * Generates the country → phone-mask template table read by
 * `Woodev\Framework\Shipping\Checkout\Phone_Mask_Patterns` and, through it, by
 * `phone-mask.js`.
 *
 * WHY THIS IS GENERATED RATHER THAN TYPED. The operator's requirement (31.08.2026) is that a
 * mask match the COUNTRY's real number structure — calling code AND national digit count — not
 * merely look tidy. His example of what is unacceptable: `+998(925)123-45-67`, wrong on both
 * counts for Uzbekistan. A hand-typed table cannot hold that guarantee: the twelve templates
 * written by hand for card #503 were checked against metadata and ONE was wrong — Turkmenistan
 * accepted 7 national digits where the real length is 8. That is the whole reason this script
 * exists.
 *
 * WHY libphonenumber-js IS NOT SHIPPED. It is a devDependency and must stay one. Its metadata
 * covers ~240 countries and costs 44.5 KB gzip in the browser — measured 31.08.2026 — against
 * IMask's 15.8 KB, for countries this framework's consumers do not ship to. Structure is decided
 * here, at build time; the runtime carries the twelve-line result.
 *
 * Usage:
 *   node bin/generate-phone-masks.mjs          # rewrite the PHP table in place
 *   node bin/generate-phone-masks.mjs --check  # verify it is current, exit 1 if not (CI-friendly)
 *
 * @package woodev-plugin-framework
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { AsYouType, getExampleNumber } from 'libphonenumber-js';
// NOT a JSON import: the package maps this specifier to `examples.mobile.json.js`, a real
// module, so `with { type: 'json' }` fails with ERR_IMPORT_ATTRIBUTE_TYPE_INCOMPATIBLE.
import examples from 'libphonenumber-js/examples.mobile.json';

/**
 * The countries the framework ships a mask for. Adding one here and re-running is the whole
 * procedure — no template is ever authored by hand. A plugin that needs a country NOT on this
 * list adds it at runtime through `Phone_Mask_Patterns::FILTER_PATTERNS`, which is unaffected by
 * this script.
 */
const COUNTRIES = [ 'RU', 'KZ', 'BY', 'UA', 'AM', 'AZ', 'GE', 'KG', 'TJ', 'TM', 'UZ', 'MD' ];

/**
 * Cosmetic overrides, and ONLY cosmetic. The operator wrote his examples with brackets
 * (`+7 (800) 800-80-80`) and that grouping is the familiar one in RU/KZ, while libphonenumber's
 * own international grouping is `+7 ### ### ## ##`. Both describe the same number.
 *
 * An override may re-group and re-punctuate. It may NOT change the calling code or the national
 * digit count — that is asserted below, and a violation fails the run rather than shipping a mask
 * that lies about the country.
 */
const COSMETIC = {
	RU: '+7 (###) ###-##-##',
	KZ: '+7 (###) ###-##-##',
};

const TARGET = new URL( '../woodev/shipping-method/checkout/class-phone-mask-patterns.php', import.meta.url );

/** Counts the `#` digit placeholders that follow the literal calling code. */
const nationalDigits = ( template ) => ( template.replace( /^\+\d+/, '' ).match( /#/g ) || [] ).length;

/** Reads the literal calling code a template starts with. */
const callingCode = ( template ) => ( template.match( /^\+(\d+)/ ) || [ '', '' ] )[ 1 ];

function build() {
	const rows = [];
	const problems = [];

	for ( const iso of COUNTRIES ) {
		const example = getExampleNumber( iso, examples );

		if ( ! example ) {
			problems.push( `${ iso }: libphonenumber has no example number — cannot derive a template.` );
			continue;
		}

		// Only the NATIONAL digits become placeholders. The calling code stays literal — it is
		// part of the country's identity, not something the customer fills in, and masking it
		// would let `+375` be typed as `+123`.
		const formatted = new AsYouType( iso ).input( `+${ example.countryCallingCode }${ example.nationalNumber }` );
		const head = `+${ example.countryCallingCode }`;
		const derived = head + formatted.slice( head.length ).replace( /[0-9]/g, '#' );

		const template = COSMETIC[ iso ] ?? derived;

		// The override contract: cosmetics may differ, structure may not.
		if ( COSMETIC[ iso ] ) {
			if ( callingCode( template ) !== example.countryCallingCode ) {
				problems.push(
					`${ iso }: cosmetic override starts with +${ callingCode( template ) }, ` +
					`metadata says +${ example.countryCallingCode }.`
				);
			}

			if ( nationalDigits( template ) !== example.nationalNumber.length ) {
				problems.push(
					`${ iso }: cosmetic override takes ${ nationalDigits( template ) } national digits, ` +
					`metadata says ${ example.nationalNumber.length }.`
				);
			}
		}

		rows.push( { iso, template, digits: example.nationalNumber.length } );
	}

	if ( problems.length ) {
		console.error( 'Refusing to generate — a template does not match its country:' );
		problems.forEach( ( p ) => console.error( `  ${ p }` ) );
		process.exit( 1 );
	}

	return rows;
}

const rows = build();
// +3: two quotes and the single space WPCS wants before `=>`.
const width = Math.max( ...rows.map( ( r ) => r.iso.length ) ) + 3;
const block = rows
	.map( ( r ) => `\t\t\t\t${ `'${ r.iso }'`.padEnd( width ) }=> '${ r.template }',` )
	.join( '\n' );

const php = readFileSync( TARGET, 'utf8' );
const marker = /(\/\/ BEGIN GENERATED — bin\/generate-phone-masks\.mjs\n)[\s\S]*?(\n\t{4}\/\/ END GENERATED)/;

if ( ! marker.test( php ) ) {
	console.error( 'Refusing to generate — the BEGIN/END GENERATED markers are missing from the PHP table.' );
	process.exit( 1 );
}

const next = php.replace( marker, `$1${ block }$2` );

if ( process.argv.includes( '--check' ) ) {
	if ( next !== php ) {
		console.error( 'The committed phone-mask table is stale. Run: node bin/generate-phone-masks.mjs' );
		process.exit( 1 );
	}

	console.log( `Phone-mask table is current (${ rows.length } countries).` );
	process.exit( 0 );
}

writeFileSync( TARGET, next );
console.log( `Wrote ${ rows.length } templates:` );
rows.forEach( ( r ) => console.log( `  ${ r.iso }  ${ r.template.padEnd( 22 ) } ${ r.digits } national digits` ) );
