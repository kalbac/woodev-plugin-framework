/**
 * Scenario 5 (spec §13): deactivate each plugin in turn — the other's settings, methods, orders page and checkout keep
 * working, and the options the two SHARE (`woodev_location_*`, the checkout-field policy, the pickup map, boxes, default
 * dimensions, popular settlements) survive.
 *
 * Both plugins are reactivated by `afterAll` whatever happens; the final state of the rig is «both active».
 *
 * DEACTIVATING CDEK IS OPT-IN (`WOODEV_E2E_CDEK_DEACTIVATE=1`, default OFF). CDEK's deactivation hook removes its remote webhook
 * subscription in the CDEK account and reactivation does not bring it back — an external side effect a plain run must not
 * have. Without the variable the two «CDEK deactivated» tests are skipped; the Yandex-deactivated half always runs.
 *
 * THE REAL CDEK EXPORT. Spec §13 asks for «an export of the remaining plugin». The CDEK production account is a test account
 * (a few calculations and ONE real export are agreed), so the export runs only with `WOODEV_E2E_CDEK_EXPORT=1` and exactly once
 * per such run — it creates a real order in the CDEK account and is never repeated by a plain run. A Yandex export is not
 * possible: its production billing is inactive, and `offers/confirm` / `request/*` are never sent (the remaining-Yandex half
 * is verified by settings, methods, the orders page and a priced checkout, not by an export).
 */

const { test, expect } = require( '@playwright/test' );
const {
	CDEK_MARKER,
	CDEK_META,
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	YANDEX_MARKER,
	adminLogin,
	expectKnownDefect,
	openCheckout,
	openOrdersPage,
	optionSnapshot,
	ordersPageRows,
	placePickupOrder,
	readOrder,
	recordOrder,
	requireRig,
	setInstanceEnabled,
	wp,
	wpEval,
	zoneInstances,
} = require( './helpers' );

/** Deactivating CDEK deletes its remote webhook subscription (see the file header), so it needs an explicit yes. */
const CDEK_DEACTIVATION_OPTED_IN = Boolean( process.env.WOODEV_E2E_CDEK_DEACTIVATE );
const CDEK_DEACTIVATION_SKIP     = 'Deactivating CDEK removes its remote webhook subscription — set WOODEV_E2E_CDEK_DEACTIVATE=1 to run this test.';

const CDEK_SLUG   = 'woocommerce-edostavka';
const YANDEX_SLUG = 'woocommerce-yandex-delivery';

let courierBefore = [];

/** Ids of existing carrier orders, found by their markers — the ones a deactivation must leave alone. */
function orderIdsByMarker( marker ) {
	return JSON.parse(
		wpEval(
			`echo wp_json_encode( array_map( 'intval', wc_get_orders( [ 'limit' => 5, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids', 'meta_key' => ${ JSON.stringify( marker ) }, 'meta_compare' => 'EXISTS' ] ) ) );`
		)
	);
}

function pluginsActive() {
	return wp( 'plugin', 'list', '--status=active', '--field=name' ).split( '\n' );
}

/**
 * Runs a block with one plugin deactivated and ALWAYS reactivates it.
 *
 * @param {string}   slug
 * @param {Function} body
 */
async function withDeactivated( slug, body ) {
	wp( 'plugin', 'deactivate', slug );

	try {
		await body();
	} finally {
		wp( 'plugin', 'activate', slug );
	}
}

/** Method ids a classic checkout offers, read from the page. */
async function offeredMethods( page ) {
	return page.evaluate( () => [ ...document.querySelectorAll( 'input[name^="shipping_method"]' ) ].map( ( i ) => i.value.split( ':' )[ 0 ] ) );
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	requireRig();

	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, false );
	}
} );

test.afterAll( () => {
	for ( const slug of [ CDEK_SLUG, YANDEX_SLUG ] ) {
		if ( ! pluginsActive().includes( slug ) ) {
			wp( 'plugin', 'activate', slug );
		}
	}

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) );
	}
} );

test.describe( 'scenario 5 — Yandex deactivated, CDEK keeps working', () => {
	test( 'options and rows survive, CDEK checkout, orders page and (opt-in) export work, reactivation restores Yandex', async ( { page } ) => {
		const yandexOrders = orderIdsByMarker( YANDEX_MARKER );

		expect( yandexOrders.length, 'precondition: Yandex orders exist (run scenario 3 first)' ).toBeGreaterThan( 0 );

		const before = optionSnapshot();

		await withDeactivated( YANDEX_SLUG, async () => {
			expect( pluginsActive() ).not.toContain( YANDEX_SLUG );

			// Nothing was deleted by the deactivation.
			expect( optionSnapshot(), 'every shared option and every carrier setting survived' ).toEqual( before );
			expect( zoneInstances( METHOD_YANDEX_PICKUP ).length, 'the Yandex zone rows survived' ).toBeGreaterThan( 0 );

			// The Yandex orders are untouched data.
			for ( const id of yandexOrders ) {
				expect( readOrder( id ).meta[ YANDEX_MARKER ], `Yandex order ${ id } keeps its marker` ).toBeTruthy();
			}

			// Checkout: CDEK methods offered, Yandex's gone, and a CDEK order can be placed.
			await openCheckout( page );

			const offered = await offeredMethods( page );

			expect( offered, 'CDEK pickup is offered' ).toContain( METHOD_CDEK_PICKUP );
			expect( offered.filter( ( m ) => m.startsWith( 'yandex_delivery' ) ), 'no Yandex method is offered' ).toEqual( [] );

			const orderId = await placePickupOrder( page, 'cdek', 'dy' );
			const order   = readOrder( orderId );

			expect( order.shipping_lines.map( ( l ) => l.method_id ), `order ${ orderId }` ).toEqual( [ METHOD_CDEK_PICKUP ] );

			for ( const key of CDEK_META ) {
				expect( order.meta[ key ], `order ${ orderId }: ${ key }` ).toBeTruthy();
			}

			expect( order.meta[ CDEK_MARKER ], `order ${ orderId }: CDEK marker` ).toBe( '1' );

			// Orders page: opens, lists the CDEK order, offers no Yandex carrier.
			expect( ordersPageRows( 'edostavka' ).ids, 'the CDEK list holds the new order' ).toContain( orderId );

			await adminLogin( page );
			await openOrdersPage( page );
			await page.getByText( /Все перевозчики/ ).first().click();
			await expect( page.getByText( /^СДЭК \(\d+\)$/ ), 'the picker offers CDEK' ).toBeVisible();
			await expect( page.getByText( /^Яндекс Доставка \(\d+\)$/ ), 'the picker no longer offers Yandex' ).toHaveCount( 0 );

			// The one real CDEK export (opt-in, see the file header).
			if ( process.env.WOODEV_E2E_CDEK_EXPORT ) {
				const outcome = JSON.parse(
					wpEval(
						`
wp_set_current_user( 1 );
$r = new WP_REST_Request( 'POST', '/woodev/v1/shipping/orders/${ orderId }/actions/export' );
$res = rest_do_request( $r );
$d = $res->get_data();
echo wp_json_encode( [ 'status' => $res->get_status(), 'data' => is_array( $d ) ? $d : (string) $d ], JSON_UNESCAPED_UNICODE );
`
					)
				);

				recordOrder( orderId, true ); // A real CDEK order now exists in the account: cleanup-orders.js must spare it.

				test.info().annotations.push( { type: 'cdek-export', description: `order ${ orderId }: HTTP ${ outcome.status } ${ JSON.stringify( outcome.data ).slice( 0, 300 ) }` } );
				expect( outcome.status, `the CDEK export answered ${ JSON.stringify( outcome.data ).slice( 0, 300 ) }` ).toBeLessThan( 300 );

				const exported = readOrder( orderId );

				expect( exported.meta._wc_edostavka_cdek_order_id, `order ${ orderId } carries the CDEK order id after the export` ).toBeTruthy();
				expect( exported.meta[ YANDEX_MARKER ], 'the exported CDEK order never gets the Yandex marker' ).toBeUndefined();
			}
		} );

		// Reactivated: nothing was lost on the way back, and the Yandex carrier is back on the orders page.
		expect( pluginsActive() ).toContain( YANDEX_SLUG );
		expect( optionSnapshot().yandex, 'the Yandex settings are what they were' ).toEqual( before.yandex );
		expect( optionSnapshot().shared, 'the shared options are what they were' ).toEqual( before.shared );
		expect( ordersPageRows( 'yandex_delivery' ).ids, 'the Yandex orders page lists the Yandex orders again' ).toEqual( expect.arrayContaining( yandexOrders ) );
	} );
} );

test.describe( 'scenario 5 — CDEK deactivated, Yandex keeps working', () => {
	test.skip( ! CDEK_DEACTIVATION_OPTED_IN, CDEK_DEACTIVATION_SKIP );

	test( 'options and rows survive, the Yandex orders page works, reactivation restores CDEK', async ( { page } ) => {
		const cdekOrders = orderIdsByMarker( CDEK_MARKER );

		expect( cdekOrders.length, 'precondition: CDEK orders exist' ).toBeGreaterThan( 0 );

		const before = optionSnapshot();

		await withDeactivated( CDEK_SLUG, async () => {
			expect( pluginsActive() ).not.toContain( CDEK_SLUG );

			expect( optionSnapshot(), 'every shared option and every carrier setting survived' ).toEqual( before );
			expect( zoneInstances( METHOD_CDEK_PICKUP ).length, 'the CDEK zone rows survived' ).toBeGreaterThan( 0 );

			for ( const id of cdekOrders ) {
				expect( readOrder( id ).meta[ CDEK_MARKER ], `CDEK order ${ id } keeps its marker` ).toBe( '1' );
			}

			// Orders page: Yandex orders are listed, no CDEK carrier is offered.
			const yandexOrders = orderIdsByMarker( YANDEX_MARKER );

			expect( ordersPageRows( 'yandex_delivery' ).ids, 'the Yandex list holds the Yandex orders' ).toEqual( expect.arrayContaining( yandexOrders ) );

			await adminLogin( page );
			await openOrdersPage( page );
			await page.getByText( /Все перевозчики/ ).first().click();
			await expect( page.getByText( /^Яндекс Доставка \(\d+\)$/ ), 'the picker offers Yandex' ).toBeVisible();
			await expect( page.getByText( /^СДЭК \(\d+\)$/ ), 'the picker no longer offers CDEK' ).toHaveCount( 0 );
		} );

		expect( pluginsActive() ).toContain( CDEK_SLUG );
		expect( optionSnapshot().cdek, 'the CDEK settings are what they were' ).toEqual( before.cdek );
		expect( optionSnapshot().shared, 'the shared options are what they were' ).toEqual( before.shared );
		expect( ordersPageRows( 'edostavka' ).ids, 'the CDEK orders page lists the CDEK orders again' ).toEqual( expect.arrayContaining( cdekOrders ) );
	} );

	test( 'F7: with CDEK deactivated the Yandex methods are still offered at checkout', async ( { page } ) => {
		await withDeactivated( CDEK_SLUG, async () => {
			await openCheckout( page );

			const offered = await offeredMethods( page );

			await expectKnownDefect(
				'F7',
				'the shared default-locality record belongs to the CDEK location provider; without CDEK the settlement is absent, the DaData fallback names it «Moscow» and the Yandex adapter finds no geo_id, so no Yandex rate is built.',
				() => expect( offered, 'a Yandex pickup rate is offered' ).toContain( METHOD_YANDEX_PICKUP )
			);
		} );
	} );
} );
