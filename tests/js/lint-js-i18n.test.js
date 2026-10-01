/**
 * Tests for the JS-translations gate (#1032, `scripts/lint-js-i18n.mjs`).
 *
 * The gate takes a project root as argv[2], so each case builds a tiny throwaway tree — a `.po`,
 * the handle-named JSON, and one PHP file carrying the `Script_Translations::register()` calls —
 * and runs the real script against it. Every failure case starts from the passing tree and breaks
 * ONE thing, so a green "bad" case means the gate stopped checking that thing.
 *
 * @see scripts/lint-js-i18n.mjs
 */

const { spawnSync } = require( 'node:child_process' );
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const path = require( 'node:path' );

const SCRIPT = path.resolve( __dirname, '../../scripts/lint-js-i18n.mjs' );
const PLURAL = 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);';
const HANDLES = [
	'woodev-license-app',
	'woodev-plugins-app',
	'woodev-setup-wizard',
	'woodev-settings-page',
	'woodev-shipping-orders-page',
	'woodev-ui-kit-gallery',
];

const PO = `msgid ""
msgstr ""
"Plural-Forms: ${ PLURAL }\\n"
"Language: ru_RU\\n"

#: assets/build/settings-page/index.js:1
msgid "Show password"
msgstr "Показать пароль"

#: assets/build/settings-page/index.js:1
msgid "Untranslated"
msgstr ""
`;

function json( messages, pluralForms = PLURAL ) {
	return JSON.stringify( {
		source: 'assets/build/settings-page/index.js',
		domain: 'messages',
		locale_data: { messages: { '': { domain: 'messages', lang: 'ru', 'plural-forms': pluralForms }, ...messages } },
	} );
}

const GOOD_JSON = json( { 'Show password': [ 'Показать пароль' ] } );

let root;

/** Builds the passing tree, lets the case break it, runs the gate. */
function run( mutate = () => {} ) {
	const files = {
		'woodev/languages/woodev-plugin-framework-ru_RU.po': PO,
		'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json': GOOD_JSON,
		'woodev/a.php': HANDLES.map( ( h ) => `\\Woodev\\Framework\\Handlers\\Script_Translations::register( $plugin, '${ h }' );` ).join( '\n' ),
	};
	mutate( files );

	for ( const [ rel, body ] of Object.entries( files ) ) {
		if ( body === null ) continue;
		mkdirSync( path.dirname( path.join( root, rel ) ), { recursive: true } );
		writeFileSync( path.join( root, rel ), body );
	}

	const r = spawnSync( process.execPath, [ SCRIPT, root ], { encoding: 'utf8' } );
	return { code: r.status, out: r.stdout + r.stderr };
}

beforeEach( () => {
	root = mkdtempSync( path.join( tmpdir(), 'lint-js-i18n-' ) );
} );
afterEach( () => rmSync( root, { recursive: true, force: true } ) );

describe( 'lint-js-i18n', () => {
	test( 'passes on a consistent tree', () => {
		const r = run();
		expect( r.out ).toMatch( /OK/ );
		expect( r.code ).toBe( 0 );
	} );

	test( 'fails when a bundle with translated strings has no JSON', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json' ] = null; // null = not written
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /settings-page\.json is missing/ );
	} );

	test( 'fails when the JSON carries a stale translation', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json' ] = json( { 'Show password': [ 'Старое' ] } );
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /"Show password" is stale/ );
	} );

	test( 'fails when the JSON lacks a translated msgid', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json' ] = json( {} );
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /"Show password" is missing/ );
	} );

	test( 'fails on a JSON entry the .po no longer implies', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json' ] = json( {
				'Show password': [ 'Показать пароль' ],
				Untranslated: [ '' ],
			} );
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /"Untranslated" is not in the \.po/ );
	} );

	test( 'fails on a plural-forms header that differs from the .po', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-settings-page.json' ] = json(
				{ 'Show password': [ 'Показать пароль' ] },
				'nplurals=2; plural=(n != 1);'
			);
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /plural-forms/ );
	} );

	test( 'fails on a JSON file no bundle owns', () => {
		const r = run( ( f ) => {
			f[ 'woodev/languages/woodev-plugin-framework-ru_RU-woodev-ghost.json' ] = GOOD_JSON;
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /woodev-ghost\.json has no translated strings/ );
	} );

	test( 'fails when a table handle has no PHP register() call', () => {
		const r = run( ( f ) => {
			f[ 'woodev/a.php' ] = HANDLES.slice( 1 )
				.map( ( h ) => `Script_Translations::register( $plugin, '${ h }' );` )
				.join( '\n' );
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /"woodev-license-app" is in the bundle table but no PHP file calls/ );
	} );

	test( 'fails when PHP registers a handle the table does not know', () => {
		const r = run( ( f ) => {
			f[ 'woodev/b.php' ] = "Script_Translations::register( $this->plugin, 'woodev-new-app' );";
		} );
		expect( r.code ).toBe( 1 );
		expect( r.out ).toMatch( /PHP registers handle "woodev-new-app"/ );
	} );
} );
