/**
 * Two-carrier acceptance matrix, part 1 (framework #1179): every combination of the checkout location field modes, with big
 * cities AND small settlements picked from the suggestions, on the classic checkout with CDEK v2 and Yandex Delivery v2 active
 * together.
 *
 * THE MODES (read off `Location_Provider_Registry`, not guessed). Two independent axes, each stored in its own option:
 *  - «Регион»            `woodev_location_field_mode_region`     — `typeahead` «Текст с подсказками», `related-list`
 *                         «Предустановленный список», `ajax-select2` «Список с поиском»; and the field itself can be REMOVED
 *                         (`woodev_checkout_fields_region_field` = `remove`: «Регион» disabled).
 *  - «Населённый пункт»  `woodev_location_field_mode_settlement` — `typeahead` or `ajax-select2` only (`related-list` is not
 *                         offered on this axis; a stored one reads as `ajax-select2`).
 * That is 3 × 2 = 6 combinations with the region widget, and with the region REMOVED the region mode is moot, so 2 more: 8
 * cells cover every meaningful combination — a full matrix, not a sample. The settlement search is SCOPED to the picked region
 * (`within=`), so with a region widget the region is picked first; with the region removed the search is unscoped.
 *
 * THE CELLS (id: region / settlement → places picked, in one session). A cell opens ONE checkout and picks its places one
 * after another from the suggestions; after the last pick it confirms the pickup point of EACH carrier that offers one and
 * places an order with the cell's method.
 *
 *   (region mode / settlement mode — `text` = text with suggestions, `preset` = preset list, `list` = searchable list)
 *   A  text / text           Москва (big, own region)           → Суздаль       (small, all five)      → order CDEK pickup
 *   B  text / list           Новосибирск (big)                  → Дивеево       (small, CDEK courier only) → order CDEK courier
 *   C  preset / text         Санкт-Петербург (big, own region)  → Эльтон        (small, Yandex pickup only) → order Yandex pickup
 *   D  preset / list         Екатеринбург (big)                 → Мышкин        (small, mixed)         → order Yandex pickup
 *   E  list / text           Чемал (small, CDEK only)                                                  → order CDEK pickup
 *   F  list / list           Красная Поляна (small, four)                                              → order Yandex courier
 *   G  REMOVED / text        Приозерск (small, four)                                                   → order Yandex pickup
 *   H  REMOVED / list        Ферапонтово (small, NOBODY serves it) — viewed by the admin, «Логирование» ON → no order; the hint says why
 *
 * Together: 4 big cities + 8 small settlements in 12 regions, 2 big cities that are their own region (Москва, Санкт-Петербург),
 * two settlements one carrier serves alone (Дивеево — CDEK, Эльтон — Yandex), one that nobody serves (Ферапонтово), and each
 * carrier's pickup picker confirmed in 5 cells. The expected sets below were MEASURED on the live accounts on 15.10.2026 (a settlement's
 * coverage is carrier data, so a drift shows as a failing set with the observed rates in the message — prove it with a
 * second run before calling it a defect).
 *
 * API BUDGET (measured 15.10.2026 by the outbound-request counter, printed as `API-CALLS 07 …` / `API-ENDPOINTS 07 …`): a checkout
 * opening costs ≤ 3 CDEK tariff + ≤ 2 Yandex pricing calls TWICE (the page, then WooCommerce's own `update_checkout`), a region
 * or settlement pick one recalculation each, a confirmed point or a typed street one more. The whole spec made 129 CDEK tariff
 * calculations (+ 11 CDEK reads of points and cities) and 39 Yandex pricing calculations; no order-creating or confirming request.
 *
 * Every option the cells change goes back in `afterAll`, and the whole rig is compared with its snapshot at the end.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const {
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	SLOT_CDEK,
	SLOT_YANDEX,
	adminLogin,
	chooseShipping,
	chosenRate,
	expectCarrierOwnData,
	expectNoStaleKeys,
	openCheckout,
	optionScope,
	pickFirstPoint,
	requireRig,
	slotValue,
	zoneInstances,
} = require( './helpers' );
const M = require( './matrix-helpers' );

const CDEK_POSTAMAT  = 'edostavka_postamat';
const CDEK_COURIER   = 'edostavka_courier';
const ALL            = [ METHOD_CDEK_PICKUP, CDEK_POSTAMAT, CDEK_COURIER, METHOD_YANDEX_PICKUP, METHOD_YANDEX_COURIER ];

const { MODE } = M;

/** The places. `serves` = the carrier methods the buyer is offered there (measured 15.10.2026). */
const PLACE = {
	moscow:      { name: 'Москва', region: 'Москва', kind: 'big, own region', serves: ALL },
	spb:         { name: 'Санкт-Петербург', region: 'Санкт-Петербург', kind: 'big, own region', serves: ALL },
	novosibirsk: { name: 'Новосибирск', region: 'Новосибирская область', kind: 'big', serves: ALL },
	ekb:         { name: 'Екатеринбург', region: 'Свердловская область', kind: 'big', serves: ALL },
	suzdal:      { name: 'Суздаль', region: 'Владимирская область', kind: 'small town', serves: ALL },
	diveevo:     { name: 'Дивеево', region: 'Нижегородская область', kind: 'village, CDEK only', serves: [ CDEK_COURIER ] },
	elton:       { name: 'Эльтон', region: 'Волгоградская область', kind: 'village, Yandex only', serves: [ METHOD_YANDEX_PICKUP ] },
	myshkin:     { name: 'Мышкин', region: 'Ярославская область', kind: 'small town, mixed', serves: [ CDEK_COURIER, METHOD_YANDEX_PICKUP ] },
	chemal:      { name: 'Чемал', region: 'Республика Алтай', kind: 'village, CDEK only', serves: [ METHOD_CDEK_PICKUP, CDEK_COURIER ] },
	krPolyana:   { name: 'Красная Поляна', region: 'Краснодарский край', kind: 'village', serves: [ METHOD_CDEK_PICKUP, CDEK_COURIER, METHOD_YANDEX_PICKUP, METHOD_YANDEX_COURIER ] },
	priozersk:   { name: 'Приозерск', region: 'Ленинградская область', kind: 'small town', serves: [ METHOD_CDEK_PICKUP, CDEK_COURIER, METHOD_YANDEX_PICKUP, METHOD_YANDEX_COURIER ] },
	ferapontovo: { name: 'Ферапонтово', region: 'Вологодская область', kind: 'village, nobody serves it', serves: [] },
};

/**
 * `points` = the carriers whose pickup point the cell confirms (one carrier per cell, both in A, to keep the API budget small). F's
 * order is a Yandex COURIER one, typed with the street of the Yandex point confirmed there — Yandex geocodes the address and
 * hides the rate for a street it cannot find.
 */
const CELLS = [
	{ id: 'A', region: MODE.TYPEAHEAD, settlement: MODE.TYPEAHEAD, picks: [ PLACE.moscow, PLACE.suzdal ], points: [ METHOD_CDEK_PICKUP, METHOD_YANDEX_PICKUP ], order: METHOD_CDEK_PICKUP },
	{ id: 'B', region: MODE.TYPEAHEAD, settlement: MODE.AJAX, picks: [ PLACE.novosibirsk, PLACE.diveevo ], points: [], order: CDEK_COURIER },
	{ id: 'C', region: MODE.LIST, settlement: MODE.TYPEAHEAD, picks: [ PLACE.spb, PLACE.elton ], points: [ METHOD_YANDEX_PICKUP ], order: METHOD_YANDEX_PICKUP },
	{ id: 'D', region: MODE.LIST, settlement: MODE.AJAX, picks: [ PLACE.ekb, PLACE.myshkin ], points: [ METHOD_YANDEX_PICKUP ], order: METHOD_YANDEX_PICKUP },
	{ id: 'E', region: MODE.AJAX, settlement: MODE.TYPEAHEAD, picks: [ PLACE.chemal ], points: [ METHOD_CDEK_PICKUP ], order: METHOD_CDEK_PICKUP },
	{ id: 'F', region: MODE.AJAX, settlement: MODE.AJAX, picks: [ PLACE.krPolyana ], points: [ METHOD_YANDEX_PICKUP ], order: METHOD_YANDEX_COURIER },
	{ id: 'G', region: MODE.TYPEAHEAD, settlement: MODE.TYPEAHEAD, regionField: 'remove', picks: [ PLACE.priozersk ], points: [ METHOD_YANDEX_PICKUP ], order: METHOD_YANDEX_PICKUP },
	// H is viewed by the admin with «Логирование» ON for both plugins: nobody serves the place, so the hint must say why.
	{ id: 'H', region: MODE.TYPEAHEAD, settlement: MODE.AJAX, regionField: 'remove', picks: [ PLACE.ferapontovo ], points: [], order: null, admin: true },
];

const label = ( cell ) => `${ cell.id }: «Регион» ${ 'remove' === cell.regionField ? 'отключён' : cell.region } / «Населённый пункт» ${ cell.settlement }`;

const options   = optionScope();
const profile   = M.adminProfile();
const rigBefore = {};

/** What each mode must render for the location fields (`tagName` of the live control; null = the field is not on the page). */
const expectedTag = ( cell ) => ( {
	state: 'remove' === cell.regionField ? null : ( MODE.TYPEAHEAD === cell.region ? 'INPUT' : 'SELECT' ),
	city: MODE.TYPEAHEAD === cell.settlement ? 'INPUT' : 'SELECT',
} );

const tags = ( page ) => page.evaluate( () => {
	const tag = ( id ) => ( document.getElementById( id ) ? document.getElementById( id ).tagName : null );

	return { state: tag( 'billing_state' ), city: tag( 'billing_city' ) };
} );

/** Sane = a finite number above zero and below a very generous ceiling for a 1-product parcel. */
const sane = ( price ) => Number.isFinite( price ) && price > 0 && price < 20_000;

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async () => {
	requireRig();

	// A plugin that was deactivated and activated again (scenario 5 of 01–05 does that) recreates its lifecycle option on the
	// FIRST request after — let it, so the snapshot below is of a settled rig.
	await fetch( `${ process.env.WP_BASE_URL || 'http://localhost:8973' }/` );

	Object.assign( rigBefore, M.rigSnapshot() );
	profile.save();
	M.installApiCounter( fs.readFileSync( path.join( __dirname, 'rig', 'zz-1179-api-counter.php' ), 'utf8' ) );

	// The field-mode cells assume the region axis offers all three modes; it does only while the active provider can list.
	expect( zoneInstances( METHOD_CDEK_PICKUP ).length, 'the rig has the CDEK pickup instance' ).toBeGreaterThan( 0 );
} );

test.afterAll( () => {
	const calls = M.apiCalls();

	// eslint-disable-next-line no-console
	console.log( `API-CALLS 07 cdek=${ calls.cdek } yandex=${ calls.yandex } dadata=${ calls.dadata } other=${ calls.other }` );
	// eslint-disable-next-line no-console
	console.log( `API-ENDPOINTS 07 ${ JSON.stringify( calls.byEndpoint ) }` );

	options.restore();
	profile.restore();
	M.removeApiCounter();

	// The rig must be exactly as the spec found it.
	expect( M.snapshotDiff( rigBefore, M.rigSnapshot() ), 'the rig differs from its snapshot taken before the spec' ).toEqual( [] );
} );

for ( const cell of CELLS ) {
	test( `cell ${ label( cell ) } — ${ cell.picks.map( ( p ) => p.name ).join( ' → ' ) }`, async ( { page } ) => {
		M.applyFieldModes( options, cell );

		if ( cell.admin ) {
			options.set( M.OPT_DEBUG.cdek, 'yes' );
			options.set( M.OPT_DEBUG.yandex, 'yes' );
			M.resetAdminSession();
			await adminLogin( page );
		}

		const updates = M.trackCheckoutUpdates( page );

		await openCheckout( page );

		// 1. The fields render as the mode says.
		expect( await tags( page ), `${ cell.id }: the location fields render as the modes say` ).toEqual( expectedTag( cell ) );

		// 2. Each place is picked from the suggestions; the form then holds it and the buyer sees the carriers' rates.
		let rates = {};

		for ( const place of cell.picks ) {
			await M.pickLocation( page, cell, place, updates );

			const fields = await M.locationFields( page );

			expect( fields.city, `${ cell.id } ${ place.name }: «Населённый пункт» holds the picked settlement` ).toBe( place.name );

			if ( 'remove' !== cell.regionField ) {
				expect( fields.state.toLowerCase(), `${ cell.id } ${ place.name }: «Регион» holds the picked region` ).toBe( place.region.toLowerCase() );
			}

			rates = await M.carrierRates( page );

			expect( Object.keys( rates ).sort(), `${ cell.id } ${ place.name } (${ place.kind }): offered carrier methods — ${ M.describeRates( rates ) }` ).toEqual( [ ...place.serves ].sort() );

			for ( const [ id, price ] of Object.entries( rates ) ) {
				expect( sane( price ), `${ cell.id } ${ place.name }: ${ id } price ${ price } is a sane number` ).toBe( true );
			}
		}

		const last = cell.picks[ cell.picks.length - 1 ];

		// 3. Pickup-point selection works for each carrier that offers a pickup here.
		const picked    = {};
		const addresses = [];

		for ( const [ methodId, slot ] of [ [ METHOD_CDEK_PICKUP, SLOT_CDEK ], [ METHOD_YANDEX_PICKUP, SLOT_YANDEX ] ] ) {
			if ( ! cell.points.includes( methodId ) ) {
				continue;
			}

			expect( methodId in rates, `${ cell.id } ${ last.name }: ${ methodId } is offered, so its point can be confirmed` ).toBe( true );

			await chooseShipping( page, methodId );

			picked[ methodId ] = await pickFirstPoint( page, slot );

			const heldCity = async () => ( await M.locationFields( page ) ).city;

			expect( await heldCity(), `${ cell.id } ${ last.name }: «Населённый пункт» still holds the settlement after the ${ methodId } point is confirmed` ).toBe( last.name );

			expect( await slotValue( page, slot ), `${ cell.id } ${ last.name }: the ${ methodId } point survives` ).toBe( picked[ methodId ] );

			// The street the point wrote into the form is a real street of this settlement — what a courier order is typed with.
			addresses.push( { address: await page.locator( '#billing_address_1' ).inputValue(), postcode: await page.locator( '#billing_postcode' ).inputValue() } );

			expect( await chosenRate( page ), `${ cell.id } ${ last.name }: ${ methodId } stays the chosen rate after its point is confirmed` ).toMatch( new RegExp( `^${ methodId }:` ) );
			expect( sane( ( await M.carrierRates( page ) )[ methodId ] ), `${ cell.id } ${ last.name }: ${ methodId } keeps a sane price after its point is confirmed` ).toBe( true );
		}

		// 4. The order can be placed with the cell's method.
		if ( null === cell.order ) {
			expect( Object.keys( rates ), `${ cell.id } ${ last.name }: nothing of the two carriers is offered, so there is nothing to order` ).toEqual( [] );

			if ( cell.admin ) {
				const hint = ( await M.hiddenHint( page ) ).join( ' || ' );

				expect( hint, `${ cell.id }: the hint says why the Yandex pickup method is hidden` ).toContain( 'Yandex pickup point' );
				expect( hint, `${ cell.id }: the hint says why the Yandex courier method is hidden` ).toContain( 'Yandex courier' );

				expect( hint, `${ cell.id }: the hint says why the CDEK pickup method is hidden` ).toContain( 'CDEK pickup point' );
				expect( hint, `${ cell.id }: the hint says why the CDEK courier method is hidden` ).toContain( 'CDEK courier delivery' );

				M.resetAdminSession();
			}

			return;
		}

		expect( cell.order in rates, `${ cell.id } ${ last.name }: the cell's order method ${ cell.order } is offered` ).toBe( true );

		const { orderId, order, shownPrice } = await M.placeOrderWithMethod( page, cell.order, `fm${ cell.id.toLowerCase() }`, addresses[ addresses.length - 1 ] || {} );
		const carrier                       = M.CARRIER_METHODS[ cell.order ];
		const label2                        = `order ${ orderId } (${ cell.id } ${ last.name })`;

		expect( order.shipping_lines.map( ( l ) => l.method_id ), `${ label2 }: shipping line` ).toEqual( [ cell.order ] );
		expect( Number( order.shipping_total ), `${ label2 }: shipping total is the price the page showed` ).toBe( shownPrice );
		expect( order.billing.city, `${ label2 }: the order holds the picked settlement` ).toBe( last.name );
		expectNoStaleKeys( order, carrier, label2 );

		if ( picked[ cell.order ] ) {
			expectCarrierOwnData( order, carrier, label2 );
			expect( Object.values( order.meta ).some( ( v ) => String( v ).includes( picked[ cell.order ] ) ), `${ label2 }: the confirmed point ${ picked[ cell.order ] } is on the order` ).toBe( true );
		}
	} );
}
