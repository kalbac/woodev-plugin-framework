/**
 * Scenario 1 (spec §13): CDEK point → Yandex point → CDEK point in ONE classic-checkout session — the placed order carries
 * only the chosen carrier's point meta and the matching shipping address.
 *
 * The risk the spec names: «both plugins write the shipping address / postcode from the chosen point; the framework
 * `Pickup_Handler` is per plugin id, the Location layer is shared».
 *
 * TWO GROUPS, because the first run on the rig found a defect that hides everything else (report Y15, finding F2, fixed in
 * #1268):
 *
 *  1. «Yandex courier method off» — isolates the point logic.
 *  2. «Yandex courier method on» — the rig's standard shape (a zone offering pickup AND courier of both carriers). Choosing
 *     a pickup point there used to make the Yandex courier rate disappear and WooCommerce silently reset the chosen method to
 *     «Free shipping»; the chosen rate and the order's shipping line must now stay with the carrier the buyer picked.
 *
 * The block checkout is NOT covered here: «block-only gaps are not release blockers» (operator, 08.10.2026) and the block
 * picker has no hidden input to drive from outside; see the report for what was and was not tried.
 */

const { test, expect } = require( '@playwright/test' );
const {
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	SLOT_CDEK,
	SLOT_YANDEX,
	chooseShipping,
	choosePayment,
	chosenRate,
	expectCarrierOwnData,
	expectCarrierShippingLine,
	expectNoStaleKeys,
	expectOnlyCarrier,
	fillContact,
	openCheckout,
	pickFirstPoint,
	placeOrder,
	readOrder,
	requireRig,
	setInstanceEnabled,
	settle,
	slotValue,
	zoneInstances,
} = require( './helpers' );

/** Billing street the pickup flow wrote, i.e. what the order's shipping address must equal. */
const billingStreet = ( page ) => page.locator( '#billing_address_1' ).inputValue();

/** Courier instances as they stood before the spec touched them: [ { instance_id, is_enabled } ]. */
let courierBefore = [];

test.beforeAll( () => {
	requireRig();
	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );
} );

test.afterAll( () => {
	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) );
	}
} );

test.describe( 'scenario 1 — Yandex courier method off (the point logic alone)', () => {
	// The defect tests read the order the first test placed.
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( () => {
		for ( const row of courierBefore ) {
			setInstanceEnabled( row.instance_id, false );
		}
	} );

	/** What the CDEK → Yandex → CDEK session left behind, for the per-defect tests below. */
	const session = { orderId: 0, cdekStreet: '', yandexStreet: '', addressAfterSwitchBack: '' };

	test( 'CDEK → Yandex → CDEK in one session: the order is placed with the CDEK method and the CDEK point', async ( { page } ) => {
		await openCheckout( page );

		// 1. CDEK point.
		await chooseShipping( page, METHOD_CDEK_PICKUP );
		await pickFirstPoint( page, SLOT_CDEK );
		session.cdekStreet = await billingStreet( page );

		expect( await chosenRate( page ), 'CDEK pickup stays chosen after its point is confirmed' ).toMatch( /^edostavka_pickup:/ );
		expect( session.cdekStreet, 'the CDEK point wrote its address into the form' ).not.toBe( '' );

		// 2. Switch to Yandex and confirm a Yandex point.
		await chooseShipping( page, METHOD_YANDEX_PICKUP );
		await pickFirstPoint( page, SLOT_YANDEX );
		session.yandexStreet = await billingStreet( page );

		expect( await chosenRate( page ), 'Yandex pickup stays chosen after its point is confirmed' ).toMatch( /^yandex_delivery_pickup:/ );
		expect( session.yandexStreet, 'the Yandex point replaced the street with its own address' ).not.toBe( session.cdekStreet );

		// 3. Back to CDEK: the remembered CDEK point is restored (the framework keeps a per-method selection map).
		await chooseShipping( page, METHOD_CDEK_PICKUP );
		session.addressAfterSwitchBack = await billingStreet( page );

		const restored = await slotValue( page, SLOT_CDEK );

		expect( restored, 'the CDEK point is restored when CDEK is chosen again' ).not.toBe( '' );
		expect( await chosenRate( page ), 'CDEK pickup is the chosen rate at the end' ).toMatch( /^edostavka_pickup:/ );

		// 4. Place the order.
		await fillContact( page, 'sw1' );
		await choosePayment( page, 'cheque' );

		const { orderId, errors } = await placeOrder( page );

		expect( errors, 'checkout refused the order' ).toEqual( [] );
		expect( orderId ).not.toBeNull();

		session.orderId = orderId;

		const order = readOrder( orderId );

		expect( order.shipping_lines.map( ( l ) => l.method_id ), `order ${ orderId }: shipping line` ).toEqual( [ METHOD_CDEK_PICKUP ] );
		expect( order.meta.cdek_pickup_point, `order ${ orderId }: the CDEK point is the one restored` ).toBe( restored );
		expect( order.meta._wc_edostavka_pickup_point_data, `order ${ orderId }: the CDEK full point` ).toBeTruthy();
		expect( order.meta._wc_edostavka_shipping, `order ${ orderId }: the CDEK orders-page marker` ).toBe( '1' );
	} );

	test( 'F3: the CDEK order carries NO point meta of the Yandex pickup the buyer left', async () => {
		const order = readOrder( session.orderId );

		expectNoStaleKeys( order, 'cdek', `order ${ order.id }` );
	} );

	test( 'F5: the form and the CDEK order carry the CDEK point\'s address after switching back, not the Yandex one', async () => {
		const order = readOrder( session.orderId );

		expect( session.addressAfterSwitchBack, 'the form after switching back to CDEK' ).toBe( session.cdekStreet );
		expect( order.shipping.address_1, `order ${ order.id }: shipping address` ).toBe( session.cdekStreet );
		expect( order.billing.address_1, `order ${ order.id }: billing address` ).toBe( session.cdekStreet );
	} );

	test( 'F4: Yandex → CDEK → Yandex: the order is placed with the Yandex point (not refused by the Yandex guard)', async ( { page } ) => {
		await openCheckout( page );

		await chooseShipping( page, METHOD_YANDEX_PICKUP );
		await pickFirstPoint( page, SLOT_YANDEX );

		await chooseShipping( page, METHOD_CDEK_PICKUP );
		await pickFirstPoint( page, SLOT_CDEK );
		const cdekStreet = await billingStreet( page );

		await chooseShipping( page, METHOD_YANDEX_PICKUP );

		const yandexFinal = await slotValue( page, SLOT_YANDEX );

		expect( yandexFinal, 'the Yandex point is restored when Yandex is chosen again' ).not.toBe( '' );
		expect( await chosenRate( page ) ).toMatch( /^yandex_delivery_pickup:/ );

		await fillContact( page, 'sw2' );
		await choosePayment( page, 'cheque' );

		const { orderId, errors } = await placeOrder( page );

		expect( errors, 'checkout refused the order' ).toEqual( [] );

		const order = readOrder( orderId );

		expectOnlyCarrier( order, 'yandex', `order ${ orderId }` );
		expect.soft( order.meta._yandex_delivery_destination_station_id, `order ${ orderId }: the Yandex point is the one restored` ).toBe( yandexFinal );
		expect.soft( order.shipping.address_1, `order ${ orderId }: shipping address is the Yandex point's` ).not.toBe( cdekStreet );
	} );
} );

test.describe( 'scenario 1 — Yandex courier method on (the rig default)', () => {
	test.beforeAll( () => {
		for ( const row of courierBefore ) {
			setInstanceEnabled( row.instance_id, true );
		}
	} );

	test( 'F2: choosing a pickup point must not move the chosen method to Free shipping', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_CDEK_PICKUP );
		await pickFirstPoint( page, SLOT_CDEK );
		await settle( page );

		const rate = await chosenRate( page );

		await fillContact( page, 'f2' );
		await choosePayment( page, 'cheque' );

		const { orderId, errors } = await placeOrder( page );

		expect( errors, 'checkout refused the order' ).toEqual( [] );

		const order = readOrder( orderId );

		expect( rate, 'the chosen method after confirming a CDEK point' ).toMatch( /^edostavka_pickup:/ );
		expectCarrierShippingLine( order, 'cdek', `order ${ orderId }` );
		expectCarrierOwnData( order, 'cdek', `order ${ orderId }` );
		expectNoStaleKeys( order, 'cdek', `order ${ orderId }` );
	} );
} );
