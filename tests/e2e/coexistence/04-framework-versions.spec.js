/**
 * Scenario 4 (spec §13): two framework versions inside the two plugins — the NEWER copy runs, and both plugins work.
 *
 * WHAT THE LOADER'S RULE REALLY IS (read from `Framework_Resolver::load_plugins()`, measured here):
 *  - the first plugin file WordPress loads (alphabetical by directory) defines `Woodev_Loader`, `Woodev_Plugin_Bootstrap` and
 *    the resolver — three early classes, whichever copy happens to be first (`class_exists` guards);
 *  - every plugin registers a loader definition declaring its `framework_version`; at `plugins_loaded` the resolver sorts the
 *    definitions by that DECLARED version, highest first, registers the autoloader against the WINNER's `woodev/` and
 *    requires `class-plugin.php` from it. The framework code itself therefore comes from the newest declared copy.
 *
 * So the declared version lives in each plugin's MAIN FILE, which this spec must not edit in the plugin repositories (the rig
 * serves their primary checkouts). Instead it builds, inside the container only, a throw-away COPY of the Yandex plugin:
 *  - directory `0-y15-yandex-oldfw` — sorts BEFORE `woocommerce-edostavka`, so the OLD copy is the first one WordPress loads
 *    and its resolver does the choosing (the hard direction: «first loaded» and «newest» disagree);
 *  - its `woodev/` is `git archive` of an older framework commit (`OLD_FRAMEWORK_COMMIT`), `Woodev_Plugin::VERSION` patched to
 *    2.0.0, and its main file declares `framework_version` 2.0.0;
 *  - the real Yandex plugin is deactivated while the copy is active (same plugin id and download id), and restored afterwards.
 * CDEK (declaring 2.0.1, the framework primary checkout's `woodev/`) stays as it is.
 *
 * The rig defines `WOODEV_FRAMEWORK_DIR`, which makes every plugin take its bootstrap from the primary checkout whatever copy
 * it carries — that would make the older copy's resolver irrelevant, and no production site defines it. The spec removes the
 * `define` from the container's `wp-config.php` for the run and puts the file back byte for byte.
 */

const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const {
	METHOD_YANDEX_COURIER,
	adminLogin,
	cliContainer,
	placePickupOrder,
	readOrder,
	expectOnlyCarrier,
	requireRig,
	setInstanceEnabled,
	wp,
	wpEval,
	zoneInstances,
} = require( './helpers' );

/** An older framework commit that already has `woodev/loader.php` (2026-10-07, «one not-configured notice», #1154). */
const OLD_FRAMEWORK_COMMIT = '4b7296e3';
const OLD_DIR              = '0-y15-yandex-oldfw';
/** What the copy DECLARES. `WOODEV_E2E_OLD_DECLARES=2.0.2` is the negative control: the copy then outranks CDEK and test 1 must fail. */
const OLD_DECLARES         = process.env.WOODEV_E2E_OLD_DECLARES || '2.0.0';
const PLUGINS              = '/var/www/html/wp-content/plugins';
const REAL_YANDEX          = 'woocommerce-yandex-delivery/woocommerce-yandex-delivery.php';
const OLD_YANDEX           = `${ OLD_DIR }/woocommerce-yandex-delivery.php`;
const WP_CONFIG            = '/var/www/html/wp-config.php';
const WP_CONFIG_BACKUP     = '/var/www/html/wp-config.php.y15-scenario4';

const repoRoot = path.resolve( __dirname, '..', '..', '..' );

/** The container that serves WordPress (files are written there as root; wp-cli runs in the cli container). */
function wordpressContainer() {
	return cliContainer().replace( /-cli-1$/, '-wordpress-1' );
}

/** Runs a shell command as root in the wordpress container. */
function root( command, input ) {
	return execFileSync( 'docker', [ 'exec', '-i', '-u', 'root', wordpressContainer(), 'sh', '-c', command ], { encoding: 'utf8', input: input ?? '' } ).trim();
}

let courierBefore = [];

/** What the loader state of the request looked like — filled by the first test, read by the others. */
let loaded = null;

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	requireRig();

	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, false );
	}

	// 1. The throw-away old-framework copy of the Yandex plugin, in the container only.
	root( `rm -rf ${ PLUGINS }/${ OLD_DIR } && cp -a ${ PLUGINS }/woocommerce-yandex-delivery ${ PLUGINS }/${ OLD_DIR } && rm -rf ${ PLUGINS }/${ OLD_DIR }/woodev ${ PLUGINS }/${ OLD_DIR }/.git` );

	const archive = execFileSync( 'git', [ '-C', repoRoot, 'archive', OLD_FRAMEWORK_COMMIT, 'woodev' ], { maxBuffer: 256 * 1024 * 1024 } );

	execFileSync( 'docker', [ 'exec', '-i', '-u', 'root', wordpressContainer(), 'tar', '-x', '-C', `${ PLUGINS }/${ OLD_DIR }` ], { input: archive } );

	root( `sed -i "s/const VERSION = '[0-9.]*';/const VERSION = '2.0.0';/" ${ PLUGINS }/${ OLD_DIR }/woodev/class-plugin.php` );
	root( `sed -i "s/'framework_version'\\( *\\)=> '[0-9.]*'/'framework_version'\\1=> '${ OLD_DECLARES }'/" ${ PLUGINS }/${ OLD_DIR }/woocommerce-yandex-delivery.php` );

	expect( root( `grep -c "const VERSION = '2.0.0'" ${ PLUGINS }/${ OLD_DIR }/woodev/class-plugin.php` ), 'the old copy reports 2.0.0' ).toBe( '1' );
	expect( root( `grep -c "'framework_version' *=> '${ OLD_DECLARES }'" ${ PLUGINS }/${ OLD_DIR }/woocommerce-yandex-delivery.php` ), `the old copy declares ${ OLD_DECLARES }` ).toBe( '1' );

	// 2. No WOODEV_FRAMEWORK_DIR for the run (restored byte for byte in afterAll).
	root( `cp ${ WP_CONFIG } ${ WP_CONFIG_BACKUP } && sed -i "/define( 'WOODEV_FRAMEWORK_DIR'/d" ${ WP_CONFIG }` );
	expect( root( `grep -c WOODEV_FRAMEWORK_DIR ${ WP_CONFIG } || true` ), 'the constant is gone' ).toBe( '0' );

	// 3. Swap the active Yandex plugin for the old-framework copy.
	wp( 'plugin', 'deactivate', 'woocommerce-yandex-delivery' );
	wp( 'plugin', 'activate', OLD_DIR );
} );

test.afterAll( () => {
	const attempt = ( fn ) => {
		try {
			fn();
		} catch ( error ) {
			// Restoring must go on whatever one step does; the report names what failed.
			console.error( `scenario 4 cleanup step failed: ${ error.message }` );
		}
	};

	// `--skip-plugins`: if the old copy WON the version sort (the negative control), the site fatals on load and a plain wp-cli
	// call cannot even start; the flag lets it deactivate the culprit without loading any plugin.
	attempt( () => wp( 'plugin', 'deactivate', OLD_DIR, '--skip-plugins' ) );
	attempt( () => root( `[ -f ${ WP_CONFIG_BACKUP } ] && cp ${ WP_CONFIG_BACKUP } ${ WP_CONFIG } && rm -f ${ WP_CONFIG_BACKUP }` ) );
	attempt( () => wp( 'plugin', 'activate', 'woocommerce-yandex-delivery' ) );
	attempt( () => root( `rm -rf ${ PLUGINS }/${ OLD_DIR }` ) );

	for ( const row of courierBefore ) {
		attempt( () => setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) ) );
	}
} );

test.describe( 'scenario 4 — an older framework copy loads first, the newer one runs', () => {
	test( 'the winner is the newer declared copy, not the first one WordPress loaded', () => {
		const state = JSON.parse(
			wpEval(
				`
$plugins = untrailingslashit( WP_PLUGIN_DIR );
$file = static fn( string $class ): string => class_exists( $class, false ) ? str_replace( $plugins . '/', '', ( new ReflectionClass( $class ) )->getFileName() ) : '';
$files = array_map( static fn( $f ) => str_replace( $plugins . '/', '', $f ), get_included_files() );
echo wp_json_encode( [
	'active'        => array_values( get_option( 'active_plugins' ) ),
	'constant'      => defined( 'WOODEV_FRAMEWORK_DIR' ),
	'version'       => class_exists( 'Woodev_Plugin', false ) ? Woodev_Plugin::VERSION : '',
	'plugin_file'   => $file( 'Woodev_Plugin' ),
	'bootstrap'     => $file( 'Woodev_Plugin_Bootstrap' ),
	'loader'        => $file( 'Woodev_Loader' ),
	'autoloader'    => $file( 'Woodev_Framework_Autoloader' ),
	'old_runtime'   => array_values( array_filter( $files, static fn( $f ) => 0 === strpos( $f, '${ OLD_DIR }/woodev/' ) ) ),
	'methods'       => array_keys( WC()->shipping()->get_shipping_methods() ),
] );
`
			)
		);

		loaded = state;
		test.info().annotations.push( { type: 'loader-state', description: JSON.stringify( state ) } );
		console.log( `scenario 4 loader state: ${ JSON.stringify( state ) }` );

		expect( state.constant, 'WOODEV_FRAMEWORK_DIR is undefined for this run' ).toBe( false );
		expect( state.active.indexOf( OLD_YANDEX ), 'the old copy is active' ).toBeGreaterThanOrEqual( 0 );
		expect( state.active.indexOf( OLD_YANDEX ), 'the old copy is listed (hence loaded) BEFORE CDEK' ).toBeLessThan( state.active.indexOf( 'woocommerce-edostavka/woocommerce-edostavka.php' ) );

		// The three early classes come from the FIRST file WordPress loads — the old copy.
		expect( state.bootstrap, 'the rendezvous classes come from the first-loaded (old) copy' ).toBe( `${ OLD_DIR }/woodev/bootstrap.php` );
		expect( state.loader ).toBe( `${ OLD_DIR }/woodev/loader.php` );

		// The framework itself comes from the winner of the version sort.
		expect( state.version, 'the loaded Woodev_Plugin is the newer one' ).toBe( '2.0.1' );
		expect( state.plugin_file, 'Woodev_Plugin was loaded from CDEK\'s (newer) copy' ).toBe( 'woocommerce-edostavka/woodev/class-plugin.php' );
		expect( state.autoloader, 'the framework autoloader was registered from the winner' ).toMatch( /^woocommerce-edostavka\/woodev\// );
		expect( state.old_runtime, 'no framework runtime file of the old copy was ever included (only its three early classes)' )
			.toEqual( expect.not.arrayContaining( [ `${ OLD_DIR }/woodev/class-plugin.php` ] ) );

		// Both carriers' methods registered on the winner.
		expect( state.methods ).toEqual( expect.arrayContaining( [ 'edostavka_pickup', 'edostavka_courier', 'yandex_delivery_pickup', 'yandex_delivery_courier' ] ) );
	} );

	test( 'both plugins work on the newer copy: a CDEK and a Yandex pickup order are placed from the checkout', async ( { page } ) => {
		const cdek   = await placePickupOrder( page, 'cdek', 'fv-cdek' );
		const yandex = await placePickupOrder( page, 'yandex', 'fv-yandex' );

		expectOnlyCarrier( readOrder( cdek ), 'cdek', `order ${ cdek }` );
		expectOnlyCarrier( readOrder( yandex ), 'yandex', `order ${ yandex }` );
	} );

	test( 'the admin loads without a fatal and shows no framework-update notice', async ( { page } ) => {
		await adminLogin( page );
		await page.goto( '/wp-admin/plugins.php' );

		const body = await page.locator( 'body' ).innerText();

		expect( body, 'no PHP fatal on the plugins screen' ).not.toMatch( /Fatal error|Parse error/ );
		expect( body, 'neither plugin is reported as needing a framework update' ).not.toMatch( /framework.*(update|обнов)|устарел/i );
		expect( loaded, 'the first test ran' ).not.toBeNull();
	} );
} );
