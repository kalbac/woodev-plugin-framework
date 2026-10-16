/**
 * Two-carrier acceptance matrix, part 2 (framework #1179): «Минимальная сумма заказа», «Максимальная сумма заказа» and
 * «Минимальная/Максимальная стоимость доставки» on the method instances of BOTH carrier plugins, on the classic checkout.
 *
 * WHAT THE SETTINGS ARE (read off the code, not guessed):
 *  - ORDER AMOUNT — a method is HIDDEN when the cart subtotal is below the minimum or above the maximum; a limit equal to the
 *    subtotal is inside; a limit of 0/empty is no limit. CDEK: `min_price`/`max_price` (`Cdek_Shipping_Rate_Service::calculate()`);
 *    Yandex: `min_order_cost`/`max_order_cost` (`Yandex_Cost_Rules::order_amount_allowed()`).
 *  - DELIVERY COST — the framework holds the finished rate to `min_cost`/`max_cost` (`Shipping_Method::apply_cost_limits()`):
 *    a price below the minimum is RAISED to it, above the maximum LOWERED to it, equal limits make the price fixed, and a rate
 *    the carrier priced at 0 («free from») stays free whatever the minimum says. Both plugins share these two keys.
 *
 * THE COVERING MATRIX (cart = 1 × product 12 at the rig's price S, delivery to the default locality, Москва; no field-mode
 * variable here — part 1 owns those):
 *
 *   L0  nothing set                                  → all five carrier methods offered, prices recorded as the control
 *   L1  CDEK minimum above S (3 instances)           → CDEK methods hidden, Yandex untouched
 *   L2  Yandex minimum above S (2 instances)         → Yandex methods hidden, CDEK untouched
 *   L3  both carriers' minimum below S               → everything offered
 *   L4  maximum below S on CDEK pickup + Yandex courier (a mix) → exactly those two hidden
 *   L5  every limit EQUAL to S                       → everything offered (the limit is inclusive)
 *   L6  window 0.5S–1.5S on both pickups             → offered at S; the cart grown to 3S → hidden
 *   L7  contradicting limits (minimum above maximum) → hidden, whatever the cart
 *   L8  the carriers set differently, couriers/postamat mixed in → the exact hidden/offered split
 *   L9  L8 viewed by the admin, «Логирование» ON for both → the hint names the hidden methods
 *   L10 L8 viewed by the admin, Yandex «Логирование» OFF  → no Yandex line in the hint
 *   C1  maximum cost below the natural price on both couriers → the price is the maximum
 *   C2  «free from» below S + a minimum cost on both pickups → the rate stays free
 *   C3  minimum cost above the natural price on both pickups  → the price is the minimum; an order placed at it carries it
 *   C4  minimum = maximum on CDEK postamat + Yandex courier   → the fixed price; an order placed at it carries it
 *   C5  an order-amount window + a cost limit on one pickup each → offered, held to the cost limit; an order placed at it
 *
 * API BUDGET: the cells that only LOOK (L0–L5, L7, L8, C1, C2) share ONE guest checkout and re-read it after the settings changed
 * (`update_checkout`, one recalculation: ≤ 3 CDEK tariff + ≤ 2 Yandex pricing calls, fewer when a method is hidden before its
 * call); the admin cells share one admin checkout; L6 and the three cells that place an order open their own. The run's real
 * count is read from the outbound-request counter and printed as `API-CALLS 08 …` / `API-ENDPOINTS 08 …`: measured 15.10.2026,
 * 65 CDEK tariff and 20 Yandex pricing calculations, no order-creating or confirming request.
 *
 * The hidden-method hint is an ADMIN-only block (`ul.woodev-hidden-shipping-reasons`), shown only while the REPORTING plugin's
 * «Логирование» is on. Every instance setting the cells change is put back byte for byte after each cell, and the whole rig is
 * compared with its snapshot at the end.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const {
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	adminLogin,
	optionScope,
	requireRig,
	settle,
	wpEval,
} = require( './helpers' );
const M = require( './matrix-helpers' );

const CDEK_PICKUP    = METHOD_CDEK_PICKUP;
const CDEK_POSTAMAT  = 'edostavka_postamat';
const CDEK_COURIER   = 'edostavka_courier';
const YANDEX_PICKUP  = METHOD_YANDEX_PICKUP;
const YANDEX_COURIER = METHOD_YANDEX_COURIER;
const ALL            = [ CDEK_PICKUP, CDEK_POSTAMAT, CDEK_COURIER, YANDEX_PICKUP, YANDEX_COURIER ];
const CDEK_ALL       = [ CDEK_PICKUP, CDEK_POSTAMAT, CDEK_COURIER ];
const YANDEX_ALL     = [ YANDEX_PICKUP, YANDEX_COURIER ];

/** Setting keys per carrier: the order-amount pair differs, the cost pair and the free-from key too. */
const KEY = {
	cdek:   { min: 'min_price', max: 'max_price', free: 'free' },
	yandex: { min: 'min_order_cost', max: 'max_order_cost', free: 'free_cost' },
};
const carrierOf = ( methodId ) => M.CARRIER_METHODS[ methodId ];

const scope        = M.instanceScope();
const options      = optionScope();
const profile      = M.adminProfile();
const rigBefore    = {};
let unitPrice      = 0;
let natural        = {};
let counterSource  = '';

/** `{ methodId: {key: value} }` → applies it to the rig. */
function applySettings( settings ) {
	for ( const [ methodId, values ] of Object.entries( settings ) ) {
		scope.patch( methodId, Object.fromEntries( Object.entries( values ).map( ( [ k, v ] ) => [ k, String( v ) ] ) ) );
	}
}

/** Order-amount limits on the given methods, in each carrier's own keys. */
function orderLimits( methodIds, { min, max } ) {
	return Object.fromEntries(
		methodIds.map( ( id ) => {
			const keys = KEY[ carrierOf( id ) ];

			return [ id, { ...( undefined !== min ? { [ keys.min ]: min } : {} ), ...( undefined !== max ? { [ keys.max ]: max } : {} ) } ];
		} )
	);
}

/** Merges several `{ methodId: {…} }` maps. */
const merge = ( ...maps ) => maps.reduce( ( all, map ) => {
	for ( const [ id, values ] of Object.entries( map ) ) {
		all[ id ] = { ...( all[ id ] || {} ), ...values };
	}

	return all;
}, {} );

/** Cost limits (`min_cost`/`max_cost`, the framework's keys on both plugins). */
const costLimits = ( methodIds, { min, max } ) => Object.fromEntries( methodIds.map( ( id ) => [ id, { ...( undefined !== min ? { min_cost: min } : {} ), ...( undefined !== max ? { max_cost: max } : {} ) } ] ) );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8973';

/** Opens the classic checkout with `qty` × the rig's product in a fresh guest session. */
async function openCart( page, qty = 1 ) {
	await page.goto( `/?add-to-cart=12&quantity=${ qty }` );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( 'form.checkout' ) ).toBeVisible();
	await settle( page );
}

/* --------------------------------------------------------------------------------------------------------- shared checkouts */

/** The two checkouts the «look only» cells share: `{ context, page, updates }`, created on first use. */
const shared = { guest: null, admin: null };

/** Re-reads a shared checkout after the rig's settings changed: WooCommerce's own `update_checkout`, one recalculation. */
async function recalculate( view ) {
	const before = view.updates.count();

	await view.page.evaluate( () => window.jQuery( document.body ).trigger( 'update_checkout' ) );
	await M.waitForRecalculation( view.page, view.updates, before );
}

/**
 * The shared guest (or admin) checkout, recalculated for the settings now on the rig. The first call opens it (one cart of one
 * product); an admin view starts from an empty admin profile (no saved locality, no persistent cart).
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {'guest'|'admin'}                    kind
 * @returns {Promise<import('@playwright/test').Page>}
 */
async function view( browser, kind ) {
	if ( shared[ kind ] ) {
		await recalculate( shared[ kind ] );

		return shared[ kind ].page;
	}

	const context = await browser.newContext( { baseURL: BASE_URL, viewport: { width: 1280, height: 900 } } );
	const page    = await context.newPage();
	const updates = M.trackCheckoutUpdates( page );

	shared[ kind ] = { context, page, updates };

	if ( 'admin' === kind ) {
		M.resetAdminSession();
		await adminLogin( page );
	}

	await openCart( page );

	return page;
}

/** The cart subtotal the page shows. */
async function shownSubtotal( page ) {
	const text = await page.locator( '.cart-subtotal .amount' ).first().innerText();

	return Number( text.replace( /[^\d,]/g, '' ).replace( ',', '.' ) );
}

/** Asserts the carrier methods offered are exactly `shown`, and that every offered price is a sane number. */
function expectOffered( rates, shown, label ) {
	expect( Object.keys( rates ).sort(), `${ label }: offered carrier methods — ${ M.describeRates( rates ) }` ).toEqual( [ ...shown ].sort() );

	for ( const [ id, price ] of Object.entries( rates ) ) {
		expect( Number.isFinite( price ) && price > 0 && price < 20_000, `${ label }: ${ id } price ${ price } is a sane number` ).toBe( true );
	}
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async () => {
	requireRig();

	// A plugin that was deactivated and activated again (scenario 5 of 01–05 does that) recreates its lifecycle option on the
	// FIRST ADMIN request after (`admin_init`) — let it, so the snapshot below is of a settled rig.
	await M.settleActivation();

	Object.assign( rigBefore, M.rigSnapshot() );
	profile.save();
	counterSource = fs.readFileSync( path.join( __dirname, 'rig', 'zz-1179-api-counter.php' ), 'utf8' );
	M.installApiCounter( counterSource );

	unitPrice = Number( wpEval( 'echo wc_get_product( 12 )->get_price();' ) );
	expect( unitPrice, 'the rig product 12 has a price' ).toBeGreaterThan( 0 );
} );

test.afterEach( () => {
	scope.restore();
} );

test.afterAll( async () => {
	for ( const kind of Object.keys( shared ) ) {
		if ( shared[ kind ] ) {
			await shared[ kind ].context.close();
		}
	}

	const calls = M.apiCalls();

	// eslint-disable-next-line no-console
	console.log( `API-CALLS 08 cdek=${ calls.cdek } yandex=${ calls.yandex } dadata=${ calls.dadata } other=${ calls.other }` );
	// eslint-disable-next-line no-console
	console.log( `API-ENDPOINTS 08 ${ JSON.stringify( calls.byEndpoint ) }` );

	scope.restore();
	options.restore();
	profile.restore();
	M.removeApiCounter();

	// The rig must be exactly as the spec found it.
	expect( M.snapshotDiff( rigBefore, M.rigSnapshot() ), 'the rig differs from its snapshot taken before the spec' ).toEqual( [] );
} );

/* ----------------------------------------------------------------------------------------------------- order amount (L) */

test.describe( 'order amount: «Минимальная сумма заказа» / «Максимальная сумма заказа»', () => {
	test( 'L0 — nothing set: every carrier method is offered at a sane price (the control)', async ( { browser } ) => {
		const page = await view( browser, 'guest' );

		natural = await M.carrierRates( page );

		expectOffered( natural, ALL, 'L0' );
		expect( await shownSubtotal( page ), 'the cart subtotal is the product price' ).toBe( unitPrice );
	} );

	test( 'L1 — CDEK minimum above the cart: the CDEK methods are hidden, Yandex is untouched', async ( { browser } ) => {
		applySettings( orderLimits( CDEK_ALL, { min: unitPrice * 1.5 } ) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), YANDEX_ALL, 'L1' );
	} );

	test( 'L2 — Yandex minimum above the cart: the Yandex methods are hidden, CDEK is untouched', async ( { browser } ) => {
		applySettings( orderLimits( YANDEX_ALL, { min: unitPrice * 1.5 } ) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), CDEK_ALL, 'L2' );
	} );

	test( 'L3 — both carriers\' minimum below the cart: everything is offered', async ( { browser } ) => {
		applySettings( orderLimits( ALL, { min: unitPrice * 0.5 } ) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), ALL, 'L3' );
	} );

	test( 'L4 — maximum below the cart on CDEK pickup and Yandex courier: exactly those two are hidden', async ( { browser } ) => {
		applySettings( merge( orderLimits( [ CDEK_PICKUP ], { max: unitPrice * 0.5 } ), orderLimits( [ YANDEX_COURIER ], { max: unitPrice * 0.5 } ) ) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), [ CDEK_POSTAMAT, CDEK_COURIER, YANDEX_PICKUP ], 'L4' );
	} );

	test( 'L5 — a limit equal to the cart total is inside: everything is offered', async ( { browser } ) => {
		applySettings( merge(
			orderLimits( [ CDEK_PICKUP, YANDEX_COURIER ], { min: unitPrice } ),
			orderLimits( [ CDEK_COURIER, YANDEX_PICKUP ], { max: unitPrice } )
		) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), ALL, 'L5' );
	} );

	test( 'L6 — a window 0.5S–1.5S on both pickups: offered at S, hidden once the cart grows to 3S', async ( { page } ) => {
		applySettings( orderLimits( [ CDEK_PICKUP, YANDEX_PICKUP ], { min: unitPrice * 0.5, max: unitPrice * 1.5 } ) );
		await openCart( page );

		expectOffered( await M.carrierRates( page ), ALL, 'L6 at S' );

		// Two more of the product in the SAME session → 3 × S, outside the window.
		await page.goto( '/?add-to-cart=12&quantity=2' );
		await page.goto( '/classic-checkout/' );
		await settle( page );

		expect( await shownSubtotal( page ), 'the cart grew to 3 × the product price' ).toBe( unitPrice * 3 );
		expectOffered( await M.carrierRates( page ), [ CDEK_POSTAMAT, CDEK_COURIER, YANDEX_COURIER ], 'L6 at 3S' );
	} );

	test( 'L7 — contradicting limits (minimum above maximum): hidden, whatever the cart', async ( { browser } ) => {
		applySettings( orderLimits( [ CDEK_PICKUP, YANDEX_PICKUP ], { min: unitPrice * 2, max: unitPrice * 0.5 } ) );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), [ CDEK_POSTAMAT, CDEK_COURIER, YANDEX_COURIER ], 'L7' );
	} );

	/** The split L8–L10 use: two carriers, set differently, couriers and postamat mixed in. */
	const L8 = () => merge(
		orderLimits( [ CDEK_PICKUP, CDEK_COURIER ], { min: unitPrice * 1.5 } ),
		orderLimits( [ CDEK_POSTAMAT ], { max: unitPrice * 1.5 } ),
		orderLimits( [ YANDEX_PICKUP ], { max: unitPrice * 0.5 } ),
		orderLimits( [ YANDEX_COURIER ], { min: unitPrice * 0.5, max: unitPrice * 1.5 } )
	);

	test( 'L8 — the carriers set differently: the exact hidden/offered split', async ( { browser } ) => {
		applySettings( L8() );

		expectOffered( await M.carrierRates( await view( browser, 'guest' ) ), [ CDEK_POSTAMAT, YANDEX_COURIER ], 'L8' );
	} );

	test( 'L9 — admin view with «Логирование» ON for both: the hint says why each hidden method is hidden', async ( { browser } ) => {
		options.set( M.OPT_DEBUG.cdek, 'yes' );
		options.set( M.OPT_DEBUG.yandex, 'yes' );
		applySettings( L8() );

		const page = await view( browser, 'admin' );

		expectOffered( await M.carrierRates( page ), [ CDEK_POSTAMAT, YANDEX_COURIER ], 'L9' );

		const hint = ( await M.hiddenHint( page ) ).join( ' || ' );

		// Yandex reports the order-amount refusal to the framework's hint (`order_amount_refused()` → `hide()`).
		expect( hint, 'the hint names the hidden Yandex pickup method' ).toContain( 'Yandex pickup point' );

		expect( hint, 'the hint names the hidden CDEK pickup method' ).toContain( 'CDEK pickup point' );
		expect( hint, 'the hint names the hidden CDEK courier method' ).toContain( 'CDEK courier delivery' );
	} );

	test( 'L10 — the same admin checkout with Yandex «Логирование» OFF: no Yandex line in the hint', async ( { browser } ) => {
		options.set( M.OPT_DEBUG.yandex, 'no' );
		applySettings( L8() );

		const page = await view( browser, 'admin' );

		expectOffered( await M.carrierRates( page ), [ CDEK_POSTAMAT, YANDEX_COURIER ], 'L10' );
		expect( ( await M.hiddenHint( page ) ).join( ' || ' ), 'a plugin with logging off adds nothing to the hint' ).not.toContain( 'Yandex' );
	} );
} );

/* ------------------------------------------------------------------------------------------------- delivery cost (C) */

test.describe( 'delivery cost: «Минимальная/Максимальная стоимость доставки»', () => {
	// The control prices: recorded by L0 when the whole spec runs, measured here when only this group does (one more checkout).
	test.beforeAll( async ( { browser } ) => {
		if ( Object.keys( natural ).length ) {
			return;
		}

		const page = await browser.newPage( { baseURL: BASE_URL } );

		await openCart( page );
		natural = await M.carrierRates( page );
		await page.close();

		expect( Object.keys( natural ).sort(), 'the control checkout offers every carrier method' ).toEqual( [ ...ALL ].sort() );
	} );

	/** Placing an order at the clamped price: the order's shipping total is what the page showed and what the limit says. */
	async function placeAndExpect( page, methodId, expected, tag ) {
		const { orderId, order, shownPrice } = await M.placeOrderWithMethod( page, methodId, tag );

		expect( shownPrice, `${ tag }: the page showed the clamped price before «Place order»` ).toBe( expected );
		expect( order.shipping_lines.map( ( l ) => l.method_id ), `order ${ orderId }: shipping line` ).toEqual( [ methodId ] );
		expect( Number( order.shipping_total ), `order ${ orderId }: shipping total is the clamped price` ).toBe( expected );
	}

	test( 'C1 — maximum cost below the natural price on both couriers: the price is the maximum', async ( { browser } ) => {
		applySettings( costLimits( [ CDEK_COURIER, YANDEX_COURIER ], { max: 150 } ) );

		const rates = await M.carrierRates( await view( browser, 'guest' ) );

		expectOffered( rates, ALL, 'C1' );
		expect( rates[ CDEK_COURIER ], 'CDEK courier is lowered to the maximum' ).toBe( 150 );
		expect( rates[ YANDEX_COURIER ], 'Yandex courier is lowered to the maximum' ).toBe( 150 );
		expect( rates[ CDEK_PICKUP ], 'a method without a limit keeps its price' ).toBeCloseTo( natural[ CDEK_PICKUP ], 0 );
	} );

	test( 'C2 — «free from» below the cart and a minimum cost on both pickups: the rate stays free', async ( { browser } ) => {
		applySettings( merge(
			costLimits( [ CDEK_PICKUP, YANDEX_PICKUP ], { min: 700 } ),
			{ [ CDEK_PICKUP ]: { [ KEY.cdek.free ]: unitPrice * 0.5 }, [ YANDEX_PICKUP ]: { [ KEY.yandex.free ]: unitPrice * 0.5 } }
		) );

		const rates = await M.carrierRates( await view( browser, 'guest' ) );

		expect( Object.keys( rates ).sort(), `C2: offered carrier methods — ${ M.describeRates( rates ) }` ).toEqual( [ ...ALL ].sort() );
		expect( rates[ CDEK_PICKUP ], 'CDEK pickup is free: the minimum does not make it paid' ).toBe( 0 );
		expect( rates[ YANDEX_PICKUP ], 'Yandex pickup is free: the minimum does not make it paid' ).toBe( 0 );
	} );

	test( 'C3 — minimum cost above the natural price on both pickups: the price is the minimum, the order carries it', async ( { page } ) => {
		applySettings( costLimits( [ CDEK_PICKUP, YANDEX_PICKUP ], { min: 900 } ) );
		await openCart( page );

		const rates = await M.carrierRates( page );

		expectOffered( rates, ALL, 'C3' );
		expect( natural[ CDEK_PICKUP ], 'the control price is below the minimum, else the cell proves nothing' ).toBeLessThan( 900 );
		expect( natural[ YANDEX_PICKUP ], 'the control price is below the minimum, else the cell proves nothing' ).toBeLessThan( 900 );
		expect( rates[ CDEK_PICKUP ], 'CDEK pickup is raised to the minimum' ).toBe( 900 );
		expect( rates[ YANDEX_PICKUP ], 'Yandex pickup is raised to the minimum' ).toBe( 900 );
		expect( rates[ CDEK_COURIER ], 'a method without a limit keeps its price' ).toBeCloseTo( natural[ CDEK_COURIER ], 0 );

		await placeAndExpect( page, CDEK_PICKUP, 900, 'c3-cdek' );
	} );

	test( 'C4 — minimum = maximum on CDEK postamat and Yandex courier: the price is fixed, the order carries it', async ( { page } ) => {
		applySettings( costLimits( [ CDEK_POSTAMAT, YANDEX_COURIER ], { min: 500, max: 500 } ) );
		await openCart( page );

		const rates = await M.carrierRates( page );

		expectOffered( rates, ALL, 'C4' );
		expect( rates[ CDEK_POSTAMAT ], 'CDEK postamat is fixed at 500' ).toBe( 500 );
		expect( rates[ YANDEX_COURIER ], 'Yandex courier is fixed at 500' ).toBe( 500 );

		await placeAndExpect( page, YANDEX_COURIER, 500, 'c4-yandex' );
	} );

	test( 'C5 — an order-amount window plus a cost limit on one pickup each: offered, held to the cost limit, order placed at it', async ( { page } ) => {
		applySettings( merge(
			orderLimits( [ YANDEX_PICKUP ], { max: unitPrice * 5 } ),
			costLimits( [ YANDEX_PICKUP ], { min: 700 } ),
			orderLimits( [ CDEK_PICKUP ], { min: unitPrice * 0.5 } ),
			costLimits( [ CDEK_PICKUP ], { max: 200 } )
		) );
		await openCart( page );

		const rates = await M.carrierRates( page );

		expectOffered( rates, ALL, 'C5' );
		expect( rates[ YANDEX_PICKUP ], 'Yandex pickup is raised to the minimum cost' ).toBe( 700 );
		expect( rates[ CDEK_PICKUP ], 'CDEK pickup is lowered to the maximum cost' ).toBe( 200 );

		await placeAndExpect( page, YANDEX_PICKUP, 700, 'c5-yandex' );
	} );
} );
