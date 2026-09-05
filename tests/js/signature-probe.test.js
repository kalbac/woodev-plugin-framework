/**
 * Tests for the signature-compatibility probe (card #767).
 *
 * The probe is a plain Node CLI script (`scripts/signature-probe.mjs`), not a module consumed by
 * the app bundle, so these tests drive it the same way an operator would: spawn it against small
 * inline PHP fixtures and assert on its report. That also keeps the test independent of whether
 * Jest's transform pipeline (scoped to `.js`/`.ts`/`.tsx`, see `jest-unit.config.js`) can load a
 * plain ESM `.mjs` file directly.
 *
 * @see scripts/signature-probe.mjs
 */

'use strict';

const { execFileSync } = require( 'node:child_process' );
const { mkdtempSync, writeFileSync, mkdirSync, rmSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const path = require( 'node:path' );

const SCRIPT = path.resolve( __dirname, '../../scripts/signature-probe.mjs' );

/** Writes a fixture PHP file and returns its absolute path. */
function writeFixture( dir, relativePath, contents ) {
	const full = path.join( dir, relativePath );
	mkdirSync( path.dirname( full ), { recursive: true } );
	writeFileSync( full, contents, 'utf8' );
	return full;
}

function runProbe( root, subjectFile, subjectClass, baseFile, baseClass ) {
	const result = execFileSync(
		'node',
		[
			SCRIPT,
			'--root',
			root,
			'--pair',
			`${ subjectFile }:${ subjectClass }=${ baseFile }:${ baseClass }`,
		],
		{ encoding: 'utf8' }
	);
	return result;
}

describe( 'signature-probe', () => {
	let dir;

	beforeEach( () => {
		dir = mkdtempSync( path.join( tmpdir(), 'signature-probe-test-' ) );
	} );

	afterEach( () => {
		rmSync( dir, { recursive: true, force: true } );
	} );

	it( 'reports a final-method override as a FATAL', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			abstract class Base {
				final public function calculate_shipping( $package = [] ): void {}
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			class Subject extends Base {
				public function calculate_shipping( $package = [] ) {}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /FATALS \(1\)/ );
		expect( report ).toMatch( /\[final-override\] Subject::calculate_shipping\(\)/ );
	} );

	it( 'does not fatal on a private base method, but reports it as shadowed dead code', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			abstract class Base {
				private function should_send_cart_api_request(): bool {
					return true;
				}
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			class Subject extends Base {
				public function should_send_cart_api_request(): bool {
					return false;
				}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /FATALS \(0\)/ );
		expect( report ).toMatch( /SHADOWED BASE-PRIVATE METHODS \(1/ );
		expect( report ).toMatch( /Subject::should_send_cart_api_request\(\) has the same name as Base::should_send_cart_api_request\(\) \(private\)/ );
	} );

	it( 'treats a short class name and its FQCN as the same type', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			namespace Woodev\\Framework\\Shipping\\Settings;

			abstract class Base {
				public function get_plugin(): ?Shipping_Integration {
					return null;
				}
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			use Woodev\\Framework\\Shipping\\Settings\\Base;

			class Subject extends Base {
				public function get_plugin(): ?\\Woodev\\Framework\\Shipping\\Settings\\Shipping_Integration {
					return null;
				}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /FATALS \(0\)/ );
	} );

	it( 'still fatals when the resolved types genuinely differ', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			namespace Woodev\\Framework\\Shipping\\Settings;

			abstract class Base {
				public function get_plugin(): ?Shipping_Integration {
					return null;
				}
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			use Woodev\\Framework\\Shipping\\Settings\\Base;

			class Subject extends Base {
				public function get_plugin(): ?\\Totally\\Unrelated\\Type {
					return null;
				}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /FATALS \(1\)/ );
		expect( report ).toMatch( /\[incompatible-return-type\] Subject::get_plugin\(\)/ );
	} );

	it( 'reports an abstract method with no implementation anywhere in the subject chain', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			abstract class Base {
				abstract public function get_method_id(): string;
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			class Subject extends Base {
				public function unrelated() {}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /UNIMPLEMENTED ABSTRACTS \(1\)/ );
		expect( report ).toMatch( /Base::function get_method_id\(.*\): string — no implementation anywhere in Subject/ );
	} );

	it( 'flags visibility narrowing (protected -> private) as a FATAL', () => {
		const base = writeFixture(
			dir,
			'Base.php',
			`<?php
			abstract class Base {
				protected function get_shipping_classes_options(): array {
					return [];
				}
			}
			`
		);
		const subject = writeFixture(
			dir,
			'Subject.php',
			`<?php
			class Subject extends Base {
				private function get_shipping_classes_options() {
					return [];
				}
			}
			`
		);

		const report = runProbe( dir, subject, 'Subject', base, 'Base' );

		expect( report ).toMatch( /FATALS \(1\)/ );
		expect( report ).toMatch( /\[visibility-narrowed\] Subject::get_shipping_classes_options\(\)/ );
	} );

	it( 'reports the edostavka shipping-method acceptance figures (card #767): 3 fatals, 5 unimplemented abstracts', () => {
		const report = runProbe(
			path.resolve( __dirname, '../..' ),
			'plugins-reference/woocommerce-edostavka/includes/class-wc-edostavka-shipping-method.php',
			'WD_Edostavka_Shipping',
			'woodev/shipping-method/class-shipping-method.php',
			'Shipping_Method'
		);

		expect( report ).toMatch( /FATALS \(3\)/ );
		expect( report ).toMatch( /UNIMPLEMENTED ABSTRACTS \(5\)/ );
		expect( report ).toMatch( /\[final-override\] WD_Edostavka_Shipping::calculate_shipping\(\)/ );
	} );
} );
