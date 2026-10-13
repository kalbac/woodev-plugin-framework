/**
 * Shared helpers for the CDEK + Yandex coexistence specs (spec §13 / D9, kalbac/woocommerce-yandex-delivery#18).
 *
 * WHAT THESE SPECS NEED THAT THE OTHER e2e SPECS DO NOT: order data. A checkout assertion is only half of a coexistence
 * claim — «the order carries only the chosen carrier's point» is a statement about stored meta, and the browser cannot see
 * it. So the helpers reach into the rig's wp-cli (`docker exec … wp …`) the way the walkthrough docs do, read an order back
 * as JSON and let the specs assert on the meta.
 *
 * RIG REQUIREMENTS (asserted by `requireRig()`, never seeded behind the operator's back):
 *  - dev rig on `:8973` with BOTH carrier plugins active (`woocommerce-edostavka`, `woocommerce-yandex-delivery`);
 *  - a CDEK pickup instance and a Yandex pickup instance in the zone that serves the Russian cart;
 *  - the rig helper mu-plugin (`tests/e2e/coexistence/rig/install.sh`) — the flaky gateway of scenario 2.
 */

const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { expect, test } = require( '@playwright/test' );

/**
 * The ledger of orders the specs created, one line per order: `<id>`, or `keep:<id>` for an order cleanup must spare (the one
 * exported to CDEK for real). `rig/cleanup-orders.js` deletes EXACTLY these ids and nothing else. Lives in the OS temp dir so
 * it never rides along in a commit; `WOODEV_E2E_ORDER_LEDGER` overrides the path.
 */
const ORDER_LEDGER = process.env.WOODEV_E2E_ORDER_LEDGER || path.join( os.tmpdir(), 'woodev-y15-coexistence-orders.txt' );

/**
 * Records an order id this run created. Appends one line, so parallel workers cannot clobber each other.
 *
 * @param {number|null} id
 * @param {boolean}     keep The order must survive `cleanup-orders.js`.
 */
function recordOrder( id, keep = false ) {
	if ( id ) {
		fs.appendFileSync( ORDER_LEDGER, `${ keep ? 'keep:' : '' }${ Number( id ) }\n` );
	}
}

/**
 * Reads the ledger.
 *
 * @returns {{ ids: number[], keep: number[] }}
 */
function recordedOrders() {
	const lines = fs.existsSync( ORDER_LEDGER ) ? fs.readFileSync( ORDER_LEDGER, 'utf8' ).split( '\n' ).filter( Boolean ) : [];
	const keep  = lines.filter( ( l ) => l.startsWith( 'keep:' ) ).map( ( l ) => Number( l.slice( 5 ) ) );
	const ids   = lines.filter( ( l ) => ! l.startsWith( 'keep:' ) ).map( Number );

	return { ids: [ ...new Set( ids ) ].filter( ( id ) => ! keep.includes( id ) ), keep: [ ...new Set( keep ) ] };
}

/** Product id that fills the cart — `docs-internal/CURRENT-STATE.md` → Local rig. */
const PRODUCT_ID = 12;

const METHOD_CDEK_PICKUP   = 'edostavka_pickup';
const METHOD_YANDEX_PICKUP = 'yandex_delivery_pickup';
const METHOD_YANDEX_COURIER = 'yandex_delivery_courier';

/** Checkout slot (hidden input name) of each carrier's pickup point. */
const SLOT_CDEK   = 'cdek_pickup_point';
const SLOT_YANDEX = '_yandex_delivery_destination_station_id';

/** Order meta of each carrier's chosen point, as the installed-site contract names them. */
const CDEK_META   = [ 'cdek_pickup_point', '_wc_edostavka_pickup_point_data' ];
const YANDEX_META = [
	'_yandex_delivery_destination_station_id',
	'_yandex_delivery_destination_station_address',
	'_yandex_delivery_pickup_point_data',
];

/** The meta each carrier's orders page finds its orders by (spec §13 scenario 3). */
const CDEK_MARKER   = '_wc_edostavka_shipping';
const YANDEX_MARKER = '_yandex_delivery_state_status';

/**
 * The wp-env dev cli container, unless `WOODEV_E2E_WP_CLI_CONTAINER` names it. wp-env derives the name from the repo path, so
 * it differs per machine; the test stack's `tests-cli` container is never the one.
 *
 * @returns {string}
 */
function cliContainer() {
	if ( process.env.WOODEV_E2E_WP_CLI_CONTAINER ) {
		return process.env.WOODEV_E2E_WP_CLI_CONTAINER;
	}

	const names = execFileSync( 'docker', [ 'ps', '--format', '{{.Names}}' ], { encoding: 'utf8' } ).split( '\n' );
	const found = names.find( ( n ) => /^wp-env-.*-cli-1$/.test( n ) && ! n.includes( 'tests' ) );

	if ( ! found ) {
		throw new Error( 'No wp-env dev cli container is running. Start the rig, or set WOODEV_E2E_WP_CLI_CONTAINER.' );
	}

	return found;
}

/**
 * Runs wp-cli in the rig and returns its trimmed stdout.
 *
 * @param {...string} args wp-cli arguments, one per element (no shell).
 * @returns {string}
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'exec', cliContainer(), 'wp', ...args ], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
}

/**
 * Evaluates PHP in the rig and returns its trimmed stdout.
 *
 * Piped in as a file, not passed to `wp eval`: the gotcha `wp-eval-file-prints-nothing-for-a-probe-with-one-very-long-line`
 * is about very long lines, so every statement below stays short, and `docker cp` into the container fails on a bind mount
 * (gotcha `docker-cp-into-the-wp-env-container-fails-pipe-the-probe-instead`), hence the stdin pipe.
 *
 * @param {string} php PHP code without the opening tag.
 * @returns {string}
 */
function wpEval( php ) {
	const file = `/tmp/y15-${ process.pid }-${ Date.now() }.php`;

	execFileSync( 'docker', [ 'exec', '-i', cliContainer(), 'sh', '-c', `cat > ${ file }` ], { input: `<?php\n${ php }\n` } );

	try {
		return wp( 'eval-file', file );
	} finally {
		execFileSync( 'docker', [ 'exec', cliContainer(), 'rm', '-f', file ] );
	}
}

/**
 * @param {string} name
 * @returns {string|null} The option value as wp-cli prints it, or null when the option does not exist.
 */
function getOption( name ) {
	try {
		return wp( 'option', 'get', name );
	} catch ( error ) {
		return null;
	}
}

/**
 * Sets an option, or deletes it when `value` is null.
 *
 * @param {string}      name
 * @param {string|null} value
 */
function setOption( name, value ) {
	if ( null === value ) {
		wp( 'option', 'delete', name );
	} else {
		wp( 'option', 'update', name, value );
	}
}

/**
 * Remembers options and puts them back: a spec changes rig options only through this, so an aborted run is one `restore()`
 * away from the rig it found.
 *
 * @returns {{ set: ( name: string, value: string|null ) => void, restore: () => void }}
 */
function optionScope() {
	const saved = new Map();

	return {
		set( name, value ) {
			if ( ! saved.has( name ) ) {
				saved.set( name, getOption( name ) );
			}

			setOption( name, value );
		},
		restore() {
			for ( const [ name, value ] of saved ) {
				setOption( name, value );
			}

			saved.clear();
		},
	};
}

/**
 * Enables or disables a shipping-zone method instance (the `is_enabled` flag, which is what the zone screen toggles).
 * `wp wc shipping_zone_method update` cannot be used: gotcha `wp-wc-shipping-zone-method-delete-takes-the-first-number-as-the-instance`
 * is the same family — its two numbers are not what they look like.
 *
 * @param {number}  instanceId
 * @param {boolean} enabled
 */
function setInstanceEnabled( instanceId, enabled ) {
	wp( 'db', 'query', `UPDATE wp_woocommerce_shipping_zone_methods SET is_enabled = ${ enabled ? 1 : 0 } WHERE instance_id = ${ Number( instanceId ) }` );
	wpEval( 'WC_Cache_Helper::get_transient_version( "shipping", true ); wp_cache_flush();' );
}

/**
 * @param {string} methodId Bare shipping method id.
 * @returns {{ instance_id: number, is_enabled: number }[]}
 */
function zoneInstances( methodId ) {
	return JSON.parse(
		wpEval(
			`global $wpdb; echo wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT instance_id, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s", ${ JSON.stringify( methodId ) } ), ARRAY_A ) );`
		)
	);
}

/**
 * The newest order id, or 0. Used to tell «the order this attempt created» from the orders earlier runs left behind.
 *
 * @returns {number}
 */
function latestOrderId() {
	return Number( wpEval( '$ids = wc_get_orders( [ "limit" => 1, "orderby" => "ID", "order" => "DESC", "return" => "ids", "type" => "shop_order" ] ); echo $ids ? $ids[0] : 0;' ) );
}

/**
 * Reads an order back the way the specs assert on it: status, totals, shipping lines, addresses and every non-WooCommerce
 * meta key (the `_wc_order_attribution_*` noise is dropped).
 *
 * @param {number} id
 * @returns {{
 *   id: number, status: string, total: string, shipping_total: string,
 *   shipping_lines: { method_id: string, instance_id: number, name: string }[],
 *   shipping: { city: string, address_1: string, postcode: string },
 *   billing: { city: string, address_1: string, postcode: string },
 *   meta: Record<string, string>
 * }}
 */
function readOrder( id ) {
	return JSON.parse(
		wpEval(
			`
$o = wc_get_order( ${ Number( id ) } );
$out = [ 'id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'shipping_total' => $o->get_shipping_total() ];
$out['shipping_lines'] = [];
foreach ( $o->get_items( 'shipping' ) as $i ) {
	$out['shipping_lines'][] = [ 'method_id' => $i->get_method_id(), 'instance_id' => $i->get_instance_id(), 'name' => $i->get_name() ];
}
$out['shipping'] = [ 'city' => $o->get_shipping_city(), 'address_1' => $o->get_shipping_address_1(), 'postcode' => $o->get_shipping_postcode() ];
$out['billing']  = [ 'city' => $o->get_billing_city(), 'address_1' => $o->get_billing_address_1(), 'postcode' => $o->get_billing_postcode() ];
$out['meta']     = [];
foreach ( $o->get_meta_data() as $m ) {
	if ( 0 === strpos( $m->key, '_wc_order_attribution' ) ) {
		continue;
	}
	$v = $m->value;
	$out['meta'][ $m->key ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
}
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
`
		)
	);
}

/** Order meta keys / markers of the carrier. */
const carrierKeys = ( carrier ) => ( 'cdek' === carrier ? CDEK_META : YANDEX_META );

/** The shipping method id of the carrier's pickup rate. */
const carrierMethod = ( carrier ) => ( 'cdek' === carrier ? METHOD_CDEK_PICKUP : METHOD_YANDEX_PICKUP );

/**
 * The keys of the OTHER carrier that the order still carries — point meta and the orders-page marker. Empty is the goal.
 *
 * @param {ReturnType<typeof readOrder>} order
 * @param {'cdek'|'yandex'}              carrier The carrier whose point the order must carry.
 * @returns {string[]}
 */
function staleKeys( order, carrier ) {
	const marker = 'cdek' === carrier ? YANDEX_MARKER : CDEK_MARKER;

	return [ ...carrierKeys( 'cdek' === carrier ? 'yandex' : 'cdek' ), marker ].filter( ( key ) => undefined !== order.meta[ key ] );
}

/**
 * Soft-asserts the part of an order that is the chosen carrier's own: its pickup meta and a shipping address. (No shipping
 * line, no stale keys — those are separate named assertions, see `expectOnlyCarrier`.)
 *
 * @param {ReturnType<typeof readOrder>} order
 * @param {'cdek'|'yandex'}              carrier
 * @param {string}                       label   Prefix for the messages, e.g. «order 1412».
 */
function expectCarrierOwnData( order, carrier, label ) {
	for ( const key of carrierKeys( carrier ) ) {
		expect.soft( order.meta[ key ], `${ label }: ${ carrier } meta ${ key } must be present` ).toBeTruthy();
	}

	expect.soft( order.shipping.address_1, `${ label }: shipping address is the chosen point's` ).toBeTruthy();
}

/**
 * Asserts the order's shipping line is the carrier's pickup method.
 *
 * @param {ReturnType<typeof readOrder>} order
 * @param {'cdek'|'yandex'}              carrier
 * @param {string}                       label
 */
function expectCarrierShippingLine( order, carrier, label ) {
	expect( order.shipping_lines.map( ( l ) => l.method_id ), `${ label }: shipping line` ).toEqual( [ carrierMethod( carrier ) ] );
}

/**
 * Asserts the order carries NO key of the other carrier.
 *
 * @param {ReturnType<typeof readOrder>} order
 * @param {'cdek'|'yandex'}              carrier
 * @param {string}                       label
 */
function expectNoStaleKeys( order, carrier, label ) {
	expect( staleKeys( order, carrier ), `${ label }: stale meta of the OTHER carrier` ).toEqual( [] );
}

/**
 * Soft-asserts that an order carries ONLY one carrier's pickup meta — the point, its address, the matching shipping line.
 * The all-in-one form, for specs where nothing is a known defect; a known defect asserts the parts through
 * `expectKnownDefect()` one at a time.
 *
 * Soft on purpose: when a scenario fails, the run should show every wrong key of the order at once, not stop at the first.
 *
 * @param {ReturnType<typeof readOrder>} order
 * @param {'cdek'|'yandex'}              carrier The carrier whose point the order must carry.
 * @param {string}                       label   Prefix for the messages, e.g. «order 1412».
 */
function expectOnlyCarrier( order, carrier, label ) {
	expect.soft( order.shipping_lines.map( ( l ) => l.method_id ), `${ label }: shipping line` ).toEqual( [ carrierMethod( carrier ) ] );
	expectCarrierOwnData( order, carrier, label );
	expect.soft( staleKeys( order, carrier ), `${ label }: stale meta of the OTHER carrier` ).toEqual( [] );
}

/**
 * Runs ONE named assertion that documents a KNOWN DEFECT, and inverts exactly that assertion — nothing else in the test.
 *
 *  - `check` fails (a Playwright assertion error) → the defect is still there; the failure is recorded as an annotation and
 *    the test goes on. This is the expected state today.
 *  - `check` passes → the defect is FIXED, and this call throws: the test turns red, and the cue is to delete the wrapper
 *    and keep `check` as an ordinary assertion. A fixed defect can never hide behind some other assertion that still fails,
 *    because every other assertion of the test runs outside this call and fails the test normally.
 *  - `check` throws anything that is NOT an assertion failure (a timeout, a crashed page, a bad JSON) → rethrown: the rig is
 *    broken, the defect is neither confirmed nor fixed.
 *
 * `check` must use HARD `expect` (not `expect.soft`, which records without throwing and would read as «fixed»). Put the
 * assertions the defect does not own BEFORE the call. The call RETURNS `true` while the defect stands, so a test whose later
 * steps need the fixed behaviour (an order that was never placed) can `if ( await expectKnownDefect( … ) ) { return; }`.
 *
 * `WOODEV_E2E_STRICT=1` runs `check` as an ordinary assertion and shows the real failure message.
 *
 * @param {string}                  id     Defect id, e.g. «F3».
 * @param {string}                  reason One line: what is wrong and where.
 * @param {() => (void|Promise<void>)} check The assertion that is correct once the defect is fixed.
 * @returns {Promise<boolean>} `true` while the defect stands (strict mode: never — a standing defect throws).
 */
async function expectKnownDefect( id, reason, check ) {
	if ( process.env.WOODEV_E2E_STRICT ) {
		await check();

		return false;
	}

	try {
		await check();
	} catch ( error ) {
		if ( ! error || ! error.matcherResult ) {
			throw error;
		}

		test.info().annotations.push( { type: 'known-defect', description: `${ id }: ${ reason } — ${ String( error.message ).split( '\n' )[ 0 ] }` } );

		return true;
	}

	throw new Error( `${ id } is FIXED — its assertion now passes. Delete the expectKnownDefect() wrapper and keep the check as an ordinary assertion. (${ reason })` );
}

/**
 * Fails fast, with the exact thing to fix, when the rig is not in the shape these specs need.
 */
function requireRig() {
	const active = wp( 'plugin', 'list', '--status=active', '--field=name' ).split( '\n' );

	for ( const slug of [ 'woocommerce-edostavka', 'woocommerce-yandex-delivery' ] ) {
		if ( ! active.includes( slug ) ) {
			throw new Error( `The coexistence specs need ${ slug } ACTIVE on the rig (wp plugin activate ${ slug }).` );
		}
	}

	for ( const method of [ METHOD_CDEK_PICKUP, METHOD_YANDEX_PICKUP ] ) {
		if ( ! zoneInstances( method ).some( ( i ) => 1 === Number( i.is_enabled ) ) ) {
			throw new Error( `No enabled ${ method } instance in any shipping zone — add one to the zone that serves Russia.` );
		}
	}

	if ( 'yes' !== wpEval( 'echo file_exists( WP_CONTENT_DIR . "/mu-plugins/zz-y15-coexistence-helper.php" ) ? "yes" : "no";' ) ) {
		throw new Error( 'Rig helper missing — run tests/e2e/coexistence/rig/install.sh.' );
	}
}

/* ------------------------------------------------------------------------------------------------------------- browser */

/**
 * Waits for WooCommerce's `update_checkout` to finish: no `.blockOverlay`, then a beat of quiet. Polls — a timer here
 * invents defects (gotcha `a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there`).
 *
 * @param {import('@playwright/test').Page} page
 */
async function settle( page ) {
	await page.waitForFunction( () => 0 === document.querySelectorAll( '.blockOverlay' ).length, null, { timeout: 60_000 } );
	await page.waitForTimeout( 800 );
}

/**
 * Fills the cart and lands on the CLASSIC checkout (`/checkout/` is the block one — gotcha `rig-checkout-url-is-the-block-checkout`).
 *
 * @param {import('@playwright/test').Page} page
 */
async function openCheckout( page ) {
	await page.goto( `/?add-to-cart=${ PRODUCT_ID }` );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( 'form.checkout' ) ).toBeVisible();
	await settle( page );
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>} The chosen shipping rate id of the page, e.g. `edostavka_pickup:47`, or ''.
 */
async function chosenRate( page ) {
	return page.evaluate( () => {
		const radio = [ ...document.querySelectorAll( 'input[name^="shipping_method"]' ) ].find( ( i ) => i.checked );

		return radio ? radio.value : '';
	} );
}

/**
 * Selects a shipping method by method id and waits for the checkout to settle.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          methodId Method id without the instance suffix.
 */
async function chooseShipping( page, methodId ) {
	const radio = page.locator( `input[name^="shipping_method"][value^="${ methodId }:"]` );

	await expect( radio, `Shipping method "${ methodId }" is not offered on the rig.` ).toHaveCount( 1 );
	await radio.check();
	await settle( page );
}

/**
 * The value the checkout form holds for a carrier's pickup slot — the point id the order will be placed with.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          slot
 * @returns {Promise<string>}
 */
async function slotValue( page, slot ) {
	return page.locator( `input[name="${ slot }"]` ).inputValue();
}

/**
 * Opens the carrier's picker and confirms the first point of its list, the way a customer does.
 *
 * The picker is a two-step flow: «Choose this point» confirms, then the same button reads «Continue to checkout» and closes the
 * dialog. The list sits behind a toggle that is collapsed on a 1280-wide viewport.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          slot   Slot of the carrier whose method is chosen.
 * @returns {Promise<string>} The point id the form now holds.
 */
async function pickFirstPoint( page, slot ) {
	await page.locator( `#woodev-pickup-slot-${ slot }-review button.woodev-pickup-trigger` ).click();
	await page.waitForSelector( '.woodev-modal__content' );
	await page.waitForSelector( '.woodev-pickup-list__item', { state: 'attached' } );

	const first = page.locator( '.woodev-pickup-list__item' ).first();

	if ( ! ( await first.isVisible() ) ) {
		await page.locator( '.woodev-pickup-list__toggle' ).click();
	}

	await first.click();

	const cta    = page.locator( '.woodev-pickup-card__cta' );
	const before = ( await cta.innerText() ).trim();

	await cta.click();
	// Language-agnostic: the second step is a DIFFERENT label on the same button, whatever the locale.
	await expect( cta ).not.toHaveText( before, { timeout: 30_000 } );
	await cta.click();
	await page.waitForSelector( '.woodev-modal__content', { state: 'detached' } );
	await settle( page );

	const id = await slotValue( page, slot );

	expect( id, `the ${ slot } slot must hold the confirmed point` ).not.toBe( '' );

	return id;
}

/**
 * The checkout's contact fields. Billing postcode and street are NOT filled: a pickup method owns them.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          tag  Marks the orders of one spec in the e-mail address.
 */
async function fillContact( page, tag ) {
	await page.fill( '#billing_first_name', 'Тест' );
	await page.fill( '#billing_last_name', 'Y15' );
	await page.fill( '#billing_phone', '+79001234567' );
	await page.fill( '#billing_email', `y15-${ tag }-${ Date.now() }@example.com` );
}

/**
 * Chooses a payment gateway by clicking its label — the radio itself is covered by it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          gateway Gateway id, e.g. `cheque`.
 */
async function choosePayment( page, gateway ) {
	await page.locator( `label[for="payment_method_${ gateway }"]` ).click();
	await settle( page );
}

/**
 * Presses «Place order» and reports what came back: the order id on success, the checkout's error text on a refusal.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{ orderId: number|null, errors: string[] }>}
 */
async function placeOrder( page ) {
	// A refusal of an EARLIER attempt is still on the page (WooCommerce prints it above the form and keeps it): without
	// clearing it, the race below would «see» the old error at once and report a retry that is still in flight as refused.
	await page.evaluate( () => document.querySelectorAll( '.woocommerce-NoticeGroup-checkout, .woocommerce-error' ).forEach( ( n ) => n.remove() ) );
	await page.locator( '#place_order' ).click();

	const outcome = await Promise.race( [
		page.waitForURL( /order-received\/\d+/, { timeout: 120_000 } ).then( () => 'received' ),
		page.locator( '.woocommerce-error, .woocommerce-NoticeGroup-checkout .woocommerce-error' ).first().waitFor( { timeout: 120_000 } ).then( () => 'error' ),
	] );

	if ( 'received' === outcome ) {
		const match = page.url().match( /order-received\/(\d+)/ );

		const orderId = match ? Number( match[ 1 ] ) : null;

		recordOrder( orderId );

		return { orderId, errors: [] };
	}

	return { orderId: null, errors: await page.locator( '.woocommerce-error li, .woocommerce-error' ).allInnerTexts() };
}

/**
 * Places a complete pickup order with one carrier in a FRESH session (cookies cleared, so no point of an earlier order is
 * remembered) and returns its id. Pays by cheque — a pickup point of CDEK does not take cash on delivery.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'cdek'|'yandex'}                 carrier
 * @param {string}                          tag     Marks the order's e-mail address.
 * @returns {Promise<number>}
 */
async function placePickupOrder( page, carrier, tag ) {
	await page.context().clearCookies();
	await openCheckout( page );
	await chooseShipping( page, 'cdek' === carrier ? METHOD_CDEK_PICKUP : METHOD_YANDEX_PICKUP );
	await pickFirstPoint( page, 'cdek' === carrier ? SLOT_CDEK : SLOT_YANDEX );
	await fillContact( page, tag );
	await choosePayment( page, 'cheque' );

	const { orderId, errors } = await placeOrder( page );

	expect( errors.join( ' | ' ), `the ${ carrier } order was refused` ).toBe( '' );
	expect( orderId ).not.toBeNull();

	return orderId;
}

/**
 * What a carrier's «Заказы доставки» list answers — the page's own data source, dispatched in-process as the admin user.
 *
 * @param {string} carrier `all`, `edostavka`, `yandex_delivery`, …
 * @returns {{ ids: number[], carriers: string[], counts: Record<string, number>, total: number }}
 */
function ordersPageRows( carrier ) {
	return JSON.parse(
		wpEval(
			`
wp_set_current_user( 1 );
$r = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
$r->set_query_params( [ 'carrier' => ${ JSON.stringify( carrier ) }, 'per_page' => 100 ] );
$d = rest_do_request( $r )->get_data();
$rows = $d['rows'] ?? [];
echo wp_json_encode( [
	'ids'      => array_map( static fn( $row ) => (int) $row['id'], $rows ),
	'carriers' => array_values( array_unique( array_map( static fn( $row ) => (string) $row['carrier']['id'], $rows ) ) ),
	'counts'   => $d['carrier_counts'] ?? [],
	'total'    => (int) ( $d['total'] ?? 0 ),
] );
`
		)
	);
}

/**
 * Every option the two carriers SHARE through the framework (location provider, checkout fields, pickup map, boxes, default
 * dimensions, popular settlements) plus each carrier's own settings and method-instance rows, as `{ name: value }`. Two
 * snapshots compare with `toEqual`: that is how scenario 5 proves a deactivation deleted nothing.
 *
 * Updater caches (`woodev_<md5>`) and transients are not in the patterns — they move by themselves (gotcha
 * `the-licence-updater-cache-options-change-by-themselves-during-a-rig-run`).
 *
 * @returns {{ shared: Record<string, string>, cdek: Record<string, string>, yandex: Record<string, string> }}
 */
function optionSnapshot() {
	return JSON.parse(
		wpEval(
			`
global $wpdb;
$groups = [
	'shared' => [ 'woodev\\_location\\_%', 'woodev\\_checkout\\_fields\\_%', 'woodev\\_pickup\\_map\\_%', 'woodev\\_boxes\\_%', 'woodev\\_default\\_dimensions\\_%', 'woodev\\_popular\\_%' ],
	'cdek'   => [ 'woocommerce\\_edostavka%' ],
	'yandex' => [ 'woocommerce\\_yandex\\_delivery%' ],
];
$out = [];
foreach ( $groups as $group => $patterns ) {
	$out[ $group ] = [];
	foreach ( $patterns as $pattern ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $pattern ), ARRAY_A );
		foreach ( $rows as $row ) {
			$out[ $group ][ $row['option_name'] ] = $row['option_value'];
		}
	}
}
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
`
		)
	);
}

/**
 * Logs the admin user in (the dev rig's `admin` / `password`).
 *
 * @param {import('@playwright/test').Page} page
 */
async function adminLogin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Opens the framework's «Заказы доставки» page (a WooCommerce Admin page) and waits for its table.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openOrdersPage( page ) {
	await page.goto( '/wp-admin/admin.php?page=wc-admin&path=%2Fwoodev-shipping-orders' );
	await page.waitForSelector( 'table tbody tr', { timeout: 60_000 } );
}

module.exports = {
	optionSnapshot,
	adminLogin,
	openOrdersPage,
	ordersPageRows,
	placePickupOrder,
	CDEK_MARKER,
	CDEK_META,
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	PRODUCT_ID,
	SLOT_CDEK,
	SLOT_YANDEX,
	YANDEX_MARKER,
	YANDEX_META,
	chooseShipping,
	choosePayment,
	chosenRate,
	cliContainer,
	ORDER_LEDGER,
	expectCarrierOwnData,
	expectCarrierShippingLine,
	expectKnownDefect,
	expectNoStaleKeys,
	expectOnlyCarrier,
	fillContact,
	getOption,
	latestOrderId,
	openCheckout,
	optionScope,
	pickFirstPoint,
	placeOrder,
	readOrder,
	recordOrder,
	recordedOrders,
	requireRig,
	setInstanceEnabled,
	settle,
	slotValue,
	wp,
	wpEval,
	zoneInstances,
};
