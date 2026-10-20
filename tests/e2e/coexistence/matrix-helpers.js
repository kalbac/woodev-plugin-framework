/**
 * Helpers for the two-carrier acceptance matrix (framework #1179): the checkout field-mode cells (`07-…`) and the order-limit
 * cells (`08-…`). They sit on top of `helpers.js` (wp-cli through `docker exec`, the order ledger, the checkout steps) and add
 * what the matrix needs and the five scenario specs did not:
 *
 *  - driving the three location widgets the way a buyer does (text with suggestions, preset list, searchable list) and ALWAYS
 *    picking the settlement from the suggestions — typing a city leaves the default locality record in place (gotcha
 *    `rig-classic-checkout-city-must-be-selected-from-suggestions`);
 *  - reading what the buyer sees: the offered carrier rates with their prices, and the admin-only «method hidden» hint;
 *  - byte-exact backup/restore of the rig options a cell changes, plus a whole-rig snapshot to prove nothing leaked;
 *  - a counter of the outbound HTTP requests, so a run reports the carrier API calls it really made.
 */

const { execFileSync } = require( 'node:child_process' );
const { expect } = require( '@playwright/test' );
const {
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_PICKUP,
	SLOT_CDEK,
	SLOT_YANDEX,
	choosePayment,
	chooseShipping,
	chosenRate,
	cliContainer,
	fillContact,
	getOption,
	pickFirstPoint,
	placeOrder,
	readOrder,
	setOption,
	settle,
	slotValue,
	wp,
	wpEval,
} = require( './helpers' );

/* ------------------------------------------------------------------------------------------------------------ constants */

/** The rate ids (method id without the instance suffix) of the two carriers, in the order the checkout lists them. */
const CARRIER_METHODS = {
	edostavka_pickup: 'cdek',
	edostavka_postamat: 'cdek',
	edostavka_courier: 'cdek',
	yandex_delivery_pickup: 'yandex',
	yandex_delivery_courier: 'yandex',
};

/** The rig's instance ids (zone 1) — `docs-internal/wiki/local-rig.md`, read off the container, not guessed. */
const INSTANCE = {
	edostavka_pickup: 47,
	edostavka_courier: 48,
	edostavka_postamat: 430,
	yandex_delivery_pickup: 599,
	yandex_delivery_courier: 600,
};

/** Option name of an instance's settings. */
const instanceOption = ( methodId ) => `woocommerce_${ methodId }_${ INSTANCE[ methodId ] }_settings`;

/** Field modes (`Location_Provider_Registry::MODE_*`). */
const MODE = { TYPEAHEAD: 'typeahead', LIST: 'related-list', AJAX: 'ajax-select2' };

const OPT_REGION_MODE     = 'woodev_location_field_mode_region';
const OPT_SETTLEMENT_MODE = 'woodev_location_field_mode_settlement';
const OPT_REGION_FIELD    = 'woodev_checkout_fields_region_field';

/** «Логирование» of each plugin (`Advanced_Settings::SETTING_ENABLE_DEBUG`); the hidden-method hint is shown only while it is on. */
const OPT_DEBUG = { cdek: 'woodev_edostavka_advanced_enable_debug', yandex: 'woodev_yandex_delivery_advanced_enable_debug' };

/* ------------------------------------------------------------------------------------------------------- rig-state scopes */

/**
 * Reads an option's RAW stored value (the serialized string), or null when the option does not exist. `wp option get` re-formats
 * arrays, so it cannot be used to put an instance's settings back byte for byte.
 *
 * @param {string} name
 * @returns {string|null} base64 of the raw value.
 */
function rawOption( name ) {
	const out = wpEval( `global $wpdb; $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", ${ JSON.stringify( name ) } ) ); echo null === $v ? "NULL" : base64_encode( $v );` );

	return 'NULL' === out ? null : out;
}

/**
 * Writes an option's raw value back (or deletes it) and drops every cache that could still serve the old one — object cache and
 * WooCommerce's shipping-rate transient version, so the next checkout request recalculates.
 *
 * @param {string}      name
 * @param {string|null} base64 Raw value as `rawOption()` returned it.
 */
function putRawOption( name, base64 ) {
	const n = JSON.stringify( name );

	if ( null === base64 ) {
		wpEval( `global $wpdb; $wpdb->delete( $wpdb->options, [ 'option_name' => ${ n } ] ); wp_cache_flush(); WC_Cache_Helper::get_transient_version( "shipping", true );` );

		return;
	}

	wpEval(
		`global $wpdb; $name = ${ n }; $raw = base64_decode( ${ JSON.stringify( base64 ) } );
if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
	$wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'yes' ] );
} else {
	$wpdb->update( $wpdb->options, [ 'option_value' => $raw ], [ 'option_name' => $name ] );
}
wp_cache_flush(); WC_Cache_Helper::get_transient_version( "shipping", true );`
	);
}

/**
 * Changes keys of a method instance's settings array and remembers the raw original: `restore()` puts it back byte for byte.
 *
 * @returns {{ patch: ( methodId: string, values: Record<string, string> ) => void, reset: ( methodId: string ) => void, restore: () => void }}
 */
function instanceScope() {
	const saved = new Map();

	const remember = ( methodId ) => {
		const name = instanceOption( methodId );

		if ( ! saved.has( name ) ) {
			saved.set( name, rawOption( name ) );
		}

		return name;
	};

	return {
		/** Sets the given keys, leaves the rest of the instance as it is. */
		patch( methodId, values ) {
			const name = remember( methodId );

			wpEval(
				`$name = ${ JSON.stringify( name ) }; $s = get_option( $name ); $s = is_array( $s ) ? $s : [];
foreach ( json_decode( ${ JSON.stringify( JSON.stringify( values ) ) }, true ) as $k => $v ) { $s[ $k ] = $v; }
update_option( $name, $s ); wp_cache_flush(); WC_Cache_Helper::get_transient_version( "shipping", true );`
			);
		},
		/** Puts one instance back to the stored original right now (a later `patch` re-remembers it). */
		reset( methodId ) {
			const name = instanceOption( methodId );

			if ( saved.has( name ) ) {
				putRawOption( name, saved.get( name ) );
				saved.delete( name );
			}
		},
		restore() {
			for ( const [ name, base64 ] of saved ) {
				putRawOption( name, base64 );
			}

			saved.clear();
		},
	};
}

/**
 * Lets a plugin that was deactivated and activated again (scenario 5 of 01–05 does that) recreate its lifecycle option, so a
 * snapshot taken next is of a settled rig. The framework does it on `admin_init` (`Woodev_Lifecycle` — the option is
 * `woodev_<plugin>_is_active`), NOT on a storefront request: a plain front-page fetch leaves it missing, and the first ADMIN
 * request of the spec (cell H of 07 is the admin one) then creates it and the end-of-spec comparison reports «the rig differs»
 * (#1306). `admin-ajax.php` fires `admin_init` without a login.
 */
async function settleActivation() {
	await fetch( `${ process.env.WP_BASE_URL || 'http://localhost:8973' }/wp-admin/admin-ajax.php` );
}

/**
 * Every row of `wp_options` that is rig CONFIGURATION, plus the shipping-zone method table and the active plugin / mu-plugin
 * lists — a whole-rig fingerprint. The patterns drop what moves by itself: transients, WC sessions, the licence updater's
 * `woodev_<md5>` caches (gotcha `the-licence-updater-cache-options-change-by-themselves-during-a-rig-run`), cron, the
 * action scheduler, `wc_pending_batch_processes` (WooCommerce's own queue: placing an order schedules a log-cleanup batch) and
 * the delivery-sync stamp.
 *
 * @returns {Record<string, string>}
 */
function rigSnapshot() {
	return JSON.parse(
		wpEval(
			`
global $wpdb;
$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\\\_transient%' AND option_name NOT LIKE '\\\\_site\\\\_transient%' AND option_name NOT REGEXP '^woodev_[0-9a-f]{32}$' AND option_name NOT LIKE 'wc\\\\_session%' AND option_name NOT LIKE 'action\\\\_scheduler%' AND option_name NOT LIKE '%cron%' AND option_name NOT LIKE 'woocommerce\\\\_admin\\\\_%' AND option_name NOT LIKE 'jetpack%' AND option_name NOT LIKE 'woodev\\\\_shipping\\\\_delivery\\\\_synced%' AND option_name <> 'wc_pending_batch_processes' ORDER BY option_name", ARRAY_A );
$out = [];
foreach ( $rows as $r ) { $out[ 'option:' . $r['option_name'] ] = $r['option_value']; }
foreach ( $wpdb->get_results( "SELECT zone_id, instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id", ARRAY_A ) as $r ) { $out[ 'zone-method:' . $r['instance_id'] ] = wp_json_encode( $r ); }
$out['active-plugins'] = wp_json_encode( array_values( (array) get_option( 'active_plugins' ) ) );
$out['mu-plugins']     = wp_json_encode( array_keys( get_mu_plugins() ) );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
`
		)
	);
}

/**
 * The keys that differ between two `rigSnapshot()`s, for an assertion message.
 *
 * @param {Record<string, string>} before
 * @param {Record<string, string>} after
 * @returns {string[]}
 */
function snapshotDiff( before, after ) {
	return [ ...new Set( [ ...Object.keys( before ), ...Object.keys( after ) ] ) ]
		.filter( ( key ) => before[ key ] !== after[ key ] )
		.map( ( key ) => `${ key }: ${ String( before[ key ] ).slice( 0, 80 ) } -> ${ String( after[ key ] ).slice( 0, 80 ) }` );
}

/** The user-meta rows of the admin (user 1) a checkout visit as the admin writes: the saved address, the saved locality, the cart. */
const ADMIN_META_WHERE = "user_id = 1 AND ( meta_key LIKE 'billing\\\\_%' OR meta_key LIKE 'shipping\\\\_%' OR meta_key IN ( 'woodev_customer_location', 'wp_woodev_customer_location', '_woodev_location_saved_city_miss', '_woocommerce_persistent_cart_1' ) )";

/**
 * Empties what a logged-in admin carries from one checkout visit to the next, so a hint cell starts from «a customer with no
 * locality chosen» (the fixed default, Москва) and an empty cart. Three things carry: the WC session row (server-side — gotcha
 * `a-logged-in-customer-s-wc-session-outranks-the-profile`), the persistent cart WooCommerce restores from user meta at login
 * (measured: a second admin cell priced two products), and the locality the framework saved on the user (measured: an admin who
 * once picked a village the carriers do not serve is offered nothing in the next cell). The saved ADDRESS rows stay: use
 * `adminProfile()` to put the whole profile back at the end.
 */
function resetAdminSession() {
	wp( 'db', 'query', "DELETE FROM wp_woocommerce_sessions WHERE session_key = '1'" );
	wp( 'db', 'query', "DELETE FROM wp_usermeta WHERE user_id = 1 AND meta_key IN ( 'woodev_customer_location', 'wp_woodev_customer_location', '_woodev_location_saved_city_miss', '_woocommerce_persistent_cart_1' )" );
}

/**
 * Remembers the admin's customer profile (saved billing/shipping address, saved locality, persistent cart) byte for byte and puts
 * it back: a checkout visit as a logged-in customer writes all of them (WooCommerce saves the posted address to the user).
 *
 * @returns {{ save: () => void, restore: () => void }}
 */
function adminProfile() {
	let rows = null;

	return {
		save() {
			rows = JSON.parse( wpEval( `global $wpdb; $r = $wpdb->get_results( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE ${ ADMIN_META_WHERE }", ARRAY_A ); echo wp_json_encode( array_map( static fn( $x ) => [ $x['meta_key'], base64_encode( $x['meta_value'] ) ], $r ) );` ) );
		},
		restore() {
			if ( null === rows ) {
				return;
			}

			wpEval(
				`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE ${ ADMIN_META_WHERE }" );
foreach ( json_decode( ${ JSON.stringify( JSON.stringify( rows ) ) }, true ) as $row ) { $wpdb->insert( $wpdb->usermeta, [ 'user_id' => 1, 'meta_key' => $row[0], 'meta_value' => base64_decode( $row[1] ) ] ); }
wp_cache_delete( 1, 'user_meta' );`
			);
			wp( 'db', 'query', "DELETE FROM wp_woocommerce_sessions WHERE session_key = '1'" );
		},
	};
}

/* ------------------------------------------------------------------------------------------------------ field-mode scope */

/**
 * Applies a field-mode cell through the given `optionScope()`.
 *
 * @param {{ set: ( name: string, value: string|null ) => void }} scope
 * @param {{ region: string, settlement: string, regionField?: 'show'|'remove' }} cell
 */
function applyFieldModes( scope, cell ) {
	scope.set( OPT_REGION_MODE, cell.region );
	scope.set( OPT_SETTLEMENT_MODE, cell.settlement );
	scope.set( OPT_REGION_FIELD, cell.regionField || 'show' );
}

/* -------------------------------------------------------------------------------------------------------- location widgets */

const escapeRegExp = ( text ) => text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

/** A suggestion row «<name>[, <district>][, <region>]» whose name is exactly `name` and which mentions `hint` (if any). */
const optionPattern = ( name, hint ) => new RegExp( `^${ escapeRegExp( name ) }(,|$)${ hint ? `(?=.*${ escapeRegExp( hint ) })` : '' }`, 's' );

/**
 * Counts WooCommerce's `update_order_review` REQUESTS (the answer of a slow carrier takes 6-10 s, the request leaves at once), so a
 * pick can wait for the recalculation it triggered instead of a timer.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {{ count: () => number }}
 */
function trackCheckoutUpdates( page ) {
	let seen = 0;

	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'wc-ajax=update_order_review' ) ) {
			seen++;
		}
	} );

	return { count: () => seen };
}

/**
 * Waits for the recalculation a pick triggered: one more `update_order_review` request than before, then the checkout settled
 * (no overlay — which is when the slow carrier has answered). A pick that changes nothing (the record the form already holds)
 * triggers no update, so the wait for the request is bounded — short when the caller knows nothing can change — and falls through.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ count: () => number }}         updates
 * @param {number}                          before  `updates.count()` taken before the pick.
 * @param {boolean}                         [noop]  The pick re-selects what the form already holds.
 */
async function waitForRecalculation( page, updates, before, noop = false ) {
	await expect.poll( () => updates.count() > before, { timeout: noop ? 3_000 : 20_000, intervals: [ 250 ] } ).toBe( true ).catch( () => {} );
	await settle( page );
	// A second, quiet beat: the answer of a slow carrier arrives AFTER the first overlay lifts on a busy rig.
	await settle( page );
}

/**
 * Picks a REGION in the checkout the way a buyer does, whatever widget the mode renders.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          mode   `MODE.*` of the region axis.
 * @param {string}                          region Region name exactly as the suggestion / preset list shows it.
 */
async function pickRegion( page, mode, region ) {
	if ( MODE.TYPEAHEAD === mode ) {
		const input = page.locator( '#billing_state' );

		await input.click();
		await input.fill( '' );
		await input.pressSequentially( region.slice( 0, Math.min( region.length, 8 ) ), { delay: 50 } );
		await page.locator( 'li.woodev-location-option' ).filter( { hasText: new RegExp( `^${ escapeRegExp( region ) }$` ) } ).first().click( { timeout: 45_000 } );

		return;
	}

	await page.locator( '#billing_state_field .select2-selection' ).click();

	const search = page.locator( 'input.select2-search__field' );

	if ( await search.isVisible().catch( () => false ) ) {
		await search.fill( region );
	}

	await page.locator( '.select2-results__option' ).filter( { hasText: new RegExp( `^${ escapeRegExp( region ) }$` ) } ).first().click( { timeout: 45_000 } );
}

/**
 * Picks a SETTLEMENT from the suggestions of the widget the mode renders.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          mode     `MODE.*` of the settlement axis (never `related-list`).
 * @param {string}                          name     Settlement name as the suggestion starts with it.
 * @param {string}                          [hint]   Text the intended row also contains (a district, or the region when the search is unscoped).
 */
async function pickSettlement( page, mode, name, hint = '' ) {
	const pattern = optionPattern( name, hint );

	if ( MODE.TYPEAHEAD === mode ) {
		const input = page.locator( '#billing_city' );

		await input.click();
		await input.fill( '' );
		await input.pressSequentially( name, { delay: 50 } );
		await page.locator( 'li.woodev-location-option' ).filter( { hasText: pattern } ).first().click( { timeout: 60_000 } );

		return;
	}

	await page.locator( '#billing_city_field .select2-selection' ).click();
	await page.locator( 'input.select2-search__field' ).fill( name );
	await page.locator( '.select2-results__option' ).filter( { hasText: pattern } ).first().click( { timeout: 60_000 } );
}

/**
 * Picks a location in one go: the region first when the cell has a region widget (the settlement search is SCOPED to the chosen
 * region — measured: `within=<region>` — so a small settlement is only findable under its own region), then the settlement.
 * With «Регион» removed the search is unscoped and the row also names the region (unless the settlement IS the region), which
 * is what `hint` is then used for.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ region: string, settlement: string, regionField?: string }} cell Field modes of the cell.
 * @param {{ region: string, name: string, district?: string }}          place
 * @param {{ count: () => number }}                                      updates
 */
async function pickLocation( page, cell, place, updates ) {
	const removed = 'remove' === cell.regionField;
	const same    = ( held, wanted ) => ( held || '' ).toLowerCase() === wanted.toLowerCase();

	if ( ! removed ) {
		const held   = await locationFields( page );
		const before = updates.count();

		await pickRegion( page, cell.region, place.region );
		await waitForRecalculation( page, updates, before, same( held.state, place.region ) );
	}

	const held   = await locationFields( page );
	const before = updates.count();

	await pickSettlement( page, cell.settlement, place.name, removed ? ( place.region === place.name ? '' : place.region ) : place.district || '' );
	await waitForRecalculation( page, updates, before, same( held.city, place.name ) );
}

/** The values the form holds for the location fields — what the order would be placed with. */
async function locationFields( page ) {
	return page.evaluate( () => {
		const get = ( id ) => ( document.getElementById( id ) ? document.getElementById( id ).value : null );

		return { state: get( 'billing_state' ), city: get( 'billing_city' ) };
	} );
}

/* ---------------------------------------------------------------------------------------------------------- what is seen */

/**
 * The carrier rates the buyer sees: `{ edostavka_pickup: 356.85, yandex_delivery_courier: 553, … }`. A method that is not on the
 * page is absent; a method listed without a number (free) is `0`.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<Record<string, number>>}
 */
async function carrierRates( page ) {
	const rows = await page.evaluate( () =>
		[ ...document.querySelectorAll( 'input[name^="shipping_method"]' ) ].map( ( input ) => {
			const li = input.closest( 'li' );

			return { value: input.value, text: li ? li.innerText.replace( /\s+/g, ' ' ).trim() : '', price: li && li.querySelector( '.amount' ) ? li.querySelector( '.amount' ).innerText : '' };
		} )
	);
	const out = {};

	for ( const row of rows ) {
		const methodId = row.value.split( ':' )[ 0 ];

		if ( CARRIER_METHODS[ methodId ] ) {
			// «1 234,50 ₽» → 1234.5 ; no amount element = a free rate.
			const digits = row.price.replace( /[^\d,]/g, '' ).replace( ',', '.' );

			out[ methodId ] = '' === digits ? 0 : Number( digits );
		}
	}

	return out;
}

/** The lines of the admin-only «method hidden» hint under the shipping block ('' lines are not returned). */
async function hiddenHint( page ) {
	return page.locator( 'ul.woodev-hidden-shipping-reasons li' ).allInnerTexts();
}

/** Short text of a rates map for an assertion message, e.g. «cdek_pickup=356.85, ya_courier=553». */
function describeRates( rates ) {
	const keys = Object.keys( CARRIER_METHODS ).filter( ( id ) => id in rates );

	return keys.length ? keys.map( ( id ) => `${ id.replace( 'edostavka_', 'cdek_' ).replace( 'yandex_delivery_', 'ya_' ) }=${ rates[ id ] }` ).join( ', ' ) : '(no carrier rate)';
}

/* ------------------------------------------------------------------------------------------------------------ placing orders */

/** Slot of the pickup method of each carrier. */
const PICKUP_SLOT = { [ METHOD_CDEK_PICKUP ]: SLOT_CDEK, [ METHOD_YANDEX_PICKUP ]: SLOT_YANDEX };

/**
 * Chooses a carrier method and places an order with it, paying by cheque (the rig's offline gateway). A pickup method gets its
 * first point through the picker (unless one is already confirmed), a courier method an address — the form's own, the buyer typing a street and a postcode.
 * NEVER exports the order: no CDEK order, no Yandex `offers/confirm` or `request/*` is sent from here.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          methodId Rate method id, e.g. `yandex_delivery_courier`.
 * @param {string}                          tag      Marks the order's e-mail address.
 * @param {{ address?: string, postcode?: string }} [courier] The street and postcode a courier method is typed with. A carrier
 *          geocodes it (Yandex refuses a street it cannot find and the rate vanishes), so a cell passes a REAL street of the place.
 * @returns {Promise<{ orderId: number, order: ReturnType<typeof readOrder>, shownPrice: number|undefined }>} `shownPrice` is the
 *          rate as the page showed it right before «Place order».
 */
async function placeOrderWithMethod( page, methodId, tag, courier = {} ) {
	await chooseShipping( page, methodId );

	if ( PICKUP_SLOT[ methodId ] ) {
		// A point the buyer already confirmed for this method (the framework remembers it per method) is kept.
		if ( '' === ( await slotValue( page, PICKUP_SLOT[ methodId ] ) ) ) {
			await pickFirstPoint( page, PICKUP_SLOT[ methodId ] );
		}
	} else {
		await page.fill( '#billing_address_1', courier.address || 'Тверская ул., д. 1' );
		await page.fill( '#billing_postcode', courier.postcode || '125009' );
		await settle( page );

		// A courier rate the carrier cannot price for the typed street vanishes and WooCommerce falls back to the first method.
		expect( await chosenRate( page ), `${ methodId } is still the chosen rate after the street is typed` ).toMatch( new RegExp( `^${ methodId }:` ) );
	}

	await fillContact( page, tag );
	await choosePayment( page, 'cheque' );

	const shownPrice = ( await carrierRates( page ) )[ methodId ];
	const { orderId, errors } = await placeOrder( page );

	expect( errors.join( ' | ' ), `the order with ${ methodId } was refused` ).toBe( '' );
	expect( orderId ).not.toBeNull();

	return { orderId, order: readOrder( orderId ), shownPrice };
}

/* -------------------------------------------------------------------------------------------------------- API-call counter */

const COUNTER_FILE   = '/var/www/html/wp-content/uploads/1179-api-calls.log';
const COUNTER_TARGET = '/var/www/html/wp-content/mu-plugins/zz-1179-api-counter.php';

/** The web container (the cli container shares `wp-content` with it; the mu-plugin has to be written as root there). */
const webContainer = () => cliContainer().replace( /-cli-1$/, '-wordpress-1' );

/**
 * Installs the outbound-request counter (rig-only mu-plugin) and empties its log. `removeApiCounter()` takes it away again.
 *
 * @param {string} source Source of `rig/zz-1179-api-counter.php`.
 */
function installApiCounter( source ) {
	const web = webContainer();

	execFileSync( 'docker', [ 'exec', '-u', 'root', '-i', web, 'sh', '-c', `cat > ${ COUNTER_TARGET } && chmod 644 ${ COUNTER_TARGET } && : > ${ COUNTER_FILE } && chmod 666 ${ COUNTER_FILE }` ], { input: source } );
}

function removeApiCounter() {
	const web = webContainer();

	execFileSync( 'docker', [ 'exec', '-u', 'root', web, 'rm', '-f', COUNTER_TARGET, COUNTER_FILE ] );
}

/**
 * Outbound requests so far, grouped by what they were for.
 *
 * @returns {{ cdek: number, yandex: number, dadata: number, other: number, byEndpoint: Record<string, number>, lines: string[] }}
 */
function apiCalls() {
	const text  = execFileSync( 'docker', [ 'exec', cliContainer(), 'sh', '-c', `cat ${ COUNTER_FILE } 2>/dev/null || true` ], { encoding: 'utf8' } );
	const lines = text.split( '\n' ).filter( Boolean );
	const out   = { cdek: 0, yandex: 0, dadata: 0, other: 0, byEndpoint: {}, lines };

	for ( const line of lines ) {
		const target = line.split( ' ' )[ 2 ] || '';

		const endpoint = `${ line.split( ' ' )[ 1 ] || '' } ${ target }`;

		out.byEndpoint[ endpoint ] = ( out.byEndpoint[ endpoint ] || 0 ) + 1;

		if ( /cdek/i.test( target ) ) {
			out.cdek++;
		} else if ( /yandex|yandex\.net|go\.yandex|b2b/i.test( target ) ) {
			out.yandex++;
		} else if ( /dadata/i.test( target ) ) {
			out.dadata++;
		} else {
			out.other++;
		}
	}

	return out;
}

module.exports = {
	CARRIER_METHODS,
	INSTANCE,
	MODE,
	OPT_DEBUG,
	OPT_REGION_FIELD,
	OPT_REGION_MODE,
	OPT_SETTLEMENT_MODE,
	apiCalls,
	applyFieldModes,
	carrierRates,
	describeRates,
	getOption,
	hiddenHint,
	installApiCounter,
	instanceOption,
	instanceScope,
	locationFields,
	pickLocation,
	pickRegion,
	pickSettlement,
	PICKUP_SLOT,
	placeOrderWithMethod,
	removeApiCounter,
	adminProfile,
	resetAdminSession,
	rigSnapshot,
	setOption,
	settleActivation,
	snapshotDiff,
	trackCheckoutUpdates,
	waitForRecalculation,
};
