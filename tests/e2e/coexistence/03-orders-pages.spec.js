/**
 * Scenario 3 (spec §13): both carriers have an exported order — each carrier's orders page shows only its own.
 *
 * The framework owns ONE «Заказы доставки» page; each plugin registers an `Orders_Provider` with a marker meta key
 * (CDEK `_wc_edostavka_shipping`, Yandex `_yandex_delivery_state_status`) and the registry scopes every carrier's list by it.
 * The risk the spec names: the Yandex marker is `state_status`, which every Yandex order has — so a CDEK order must never
 * receive it.
 *
 * HOW THE ORDERS ARE «EXPORTED» (no live carrier call is made by this spec):
 *  - the CDEK order is a real checkout order. It carries its marker from the moment it is placed, which is what the page
 *    scopes by; the one real CDEK export of the whole task is done by scenario 5, with Yandex deactivated;
 *  - the Yandex order is a real checkout order too, then «exported» by writing `_yandex_delivery_request_id` and moving
 *    `_yandex_delivery_state_status` from NEW to CREATED — the two meta keys a real `offers/confirm` leaves. Production
 *    billing of the Yandex account is inactive, so no `offers/confirm` or `request/*` is ever sent. The fake request id is
 *    deleted again afterwards so the status poller never asks Yandex about it.
 *
 * The Yandex courier method is off for the run (defect F2 of scenario 1 would otherwise flip the chosen method).
 */

const { test, expect } = require( '@playwright/test' );
const {
	CDEK_MARKER,
	METHOD_YANDEX_COURIER,
	YANDEX_MARKER,
	adminLogin,
	openOrdersPage,
	ordersPageRows,
	placePickupOrder,
	readOrder,
	requireRig,
	setInstanceEnabled,
	wpEval,
	zoneInstances,
} = require( './helpers' );

const REQUEST_ID_META = '_yandex_delivery_request_id';

let courierBefore = [];

/** Orders the setup test placed. */
const orders = { cdek: 0, yandex: 0 };

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	requireRig();

	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, false );
	}
} );

test.afterAll( () => {
	if ( orders.yandex ) {
		wpEval( `$o = wc_get_order( ${ orders.yandex } ); $o->delete_meta_data( ${ JSON.stringify( REQUEST_ID_META ) } ); $o->update_meta_data( ${ JSON.stringify( YANDEX_MARKER ) }, 'NEW' ); $o->save();` );
	}

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) );
	}
} );

test.describe( 'scenario 3 — each orders page shows only its own orders', () => {
	test( 'setup: a CDEK order and an «exported» Yandex order, both from the checkout', async ( { page } ) => {
		orders.cdek   = await placePickupOrder( page, 'cdek', 'op-cdek' );
		orders.yandex = await placePickupOrder( page, 'yandex', 'op-yandex' );

		// «Export» the Yandex order the way offers/confirm leaves it.
		wpEval(
			`$o = wc_get_order( ${ orders.yandex } ); $o->update_meta_data( ${ JSON.stringify( REQUEST_ID_META ) }, 'y15-e2e-request-${ Date.now() }' ); $o->update_meta_data( ${ JSON.stringify( YANDEX_MARKER ) }, 'CREATED' ); $o->save();`
		);

		const cdek   = readOrder( orders.cdek );
		const yandex = readOrder( orders.yandex );

		expect( cdek.meta[ CDEK_MARKER ], 'the CDEK order carries its marker' ).toBe( '1' );
		expect( yandex.meta[ YANDEX_MARKER ], 'the Yandex order carries its marker' ).toBe( 'CREATED' );
		expect( yandex.meta[ REQUEST_ID_META ], 'the Yandex order is «exported»' ).toBeTruthy();

		// The risk the spec names: the Yandex marker is on every Yandex order, so the CDEK order must never get it.
		expect( cdek.meta[ YANDEX_MARKER ], 'a CDEK order must never get _yandex_delivery_state_status' ).toBeUndefined();
		expect( cdek.meta[ REQUEST_ID_META ], 'a CDEK order must never get _yandex_delivery_request_id' ).toBeUndefined();
		expect( yandex.meta[ CDEK_MARKER ], 'a Yandex order must never get the CDEK marker' ).toBeUndefined();
	} );

	test( 'the page\'s data source lists each carrier\'s orders and nothing else', () => {
		const cdek   = ordersPageRows( 'edostavka' );
		const yandex = ordersPageRows( 'yandex_delivery' );
		const all    = ordersPageRows( 'all' );

		expect( cdek.ids, 'the CDEK list holds the CDEK order' ).toContain( orders.cdek );
		expect( cdek.ids, 'the CDEK list does not hold the Yandex order' ).not.toContain( orders.yandex );
		expect( cdek.carriers, 'every row of the CDEK list is a CDEK row' ).toEqual( [ 'edostavka' ] );

		expect( yandex.ids, 'the Yandex list holds the Yandex order' ).toContain( orders.yandex );
		expect( yandex.ids, 'the Yandex list does not hold the CDEK order' ).not.toContain( orders.cdek );
		expect( yandex.carriers, 'every row of the Yandex list is a Yandex row' ).toEqual( [ 'yandex_delivery' ] );

		expect( all.ids, 'the aggregate holds both' ).toEqual( expect.arrayContaining( [ orders.cdek, orders.yandex ] ) );

		// The counts the picker prints agree with the lists (both fit one page of 100).
		expect( cdek.total ).toBe( cdek.counts.edostavka );
		expect( yandex.total ).toBe( yandex.counts.yandex_delivery );
	} );

	test( 'the page itself: choosing a carrier in the picker shows only that carrier\'s orders', async ( { page } ) => {
		await adminLogin( page );
		await openOrdersPage( page );

		const link = ( id ) => page.getByRole( 'link', { name: new RegExp( `^Заказ ${ id }$` ) } );

		// The aggregate shows both (positive control: the selectors below do match when the order is there).
		await expect( link( orders.cdek ) ).toBeVisible();
		await expect( link( orders.yandex ) ).toBeVisible();

		await page.getByText( /Все перевозчики/ ).first().click();
		await page.getByText( /^Яндекс Доставка \(\d+\)$/ ).click();
		await expect( link( orders.yandex ), 'the Yandex page shows the Yandex order' ).toBeVisible();
		await expect( link( orders.cdek ), 'the Yandex page does not show the CDEK order' ).toHaveCount( 0 );

		await page.getByText( /^Яндекс Доставка \(\d+\)$/ ).first().click();
		await page.getByText( /^СДЭК \(\d+\)$/ ).click();
		await expect( link( orders.cdek ), 'the CDEK page shows the CDEK order' ).toBeVisible();
		await expect( link( orders.yandex ), 'the CDEK page does not show the Yandex order' ).toHaveCount( 0 );
	} );
} );
