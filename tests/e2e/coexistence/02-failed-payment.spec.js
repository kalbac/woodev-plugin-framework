/**
 * Scenario 2 (spec §13): a payment fails, the buyer re-checks out with the OTHER carrier on the SAME order — the previous
 * carrier's data must be gone (support case 16988).
 *
 * HOW THE FAILURE IS STAGED (document this, it took some finding):
 *  - WooCommerce core gateways cannot fail, and `woodev-test-payment-gateway` is only a plugin shell. So the rig gets a
 *    container-only mu-plugin (`rig/zz-y15-coexistence-helper.php`, installed by `rig/install.sh`) with a «Y15 flaky gateway»
 *    that declines the FIRST payment attempt of an order and accepts the next; it is offered only while the option
 *    `y15_flaky_gateway` is `yes`, which this spec sets and restores.
 *  - WooCommerce resumes the pending/failed order of the session only while `cart_hash` is unchanged
 *    (`WC_Checkout::create_order()`), and the hash includes the TOTAL — so two carriers with different prices would make the
 *    retry a brand-new order and nothing stale could ever survive. The helper's `y15_equal_rates` option makes both pickup
 *    rates cost the same, i.e. the situation of a shop where both carriers ship free above a threshold.
 *  - The Yandex courier method is switched off for the run (defect F2 of scenario 1 would otherwise flip the chosen method).
 *
 * The «isolated» variants blank the hidden slot of the carrier the buyer left before the retry. That is NOT something a
 * buyer can do; it separates the two defects the natural flow stacks (F3: the form still posts the left carrier's point;
 * F6: nothing ever deletes the meta the first attempt wrote on the reused order).
 */

const { test, expect } = require( '@playwright/test' );
const {
	CDEK_MARKER,
	CDEK_META,
	METHOD_CDEK_PICKUP,
	METHOD_YANDEX_COURIER,
	METHOD_YANDEX_PICKUP,
	SLOT_CDEK,
	SLOT_YANDEX,
	YANDEX_MARKER,
	YANDEX_META,
	chooseShipping,
	choosePayment,
	expectOnlyCarrier,
	fillContact,
	knownDefect,
	latestOrderId,
	openCheckout,
	optionScope,
	pickFirstPoint,
	placeOrder,
	readOrder,
	requireRig,
	setInstanceEnabled,
	zoneInstances,
} = require( './helpers' );

const options = optionScope();

let courierBefore = [];

test.beforeAll( () => {
	requireRig();

	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, false );
	}

	options.set( 'y15_flaky_gateway', 'yes' );
	options.set( 'y15_equal_rates', '300' );
} );

test.afterAll( () => {
	options.restore();

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) );
	}
} );

/** Slot, method id, point meta and marker of each carrier — what the data-driven runs below swap. */
const CARRIERS = {
	cdek: { method: METHOD_CDEK_PICKUP, slot: SLOT_CDEK, meta: CDEK_META, marker: CDEK_MARKER },
	yandex: { method: METHOD_YANDEX_PICKUP, slot: SLOT_YANDEX, meta: YANDEX_META, marker: YANDEX_MARKER },
};

/**
 * Fails the payment with the first carrier, then retries on the same order with the second.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'cdek'|'yandex'}                 first
 * @param {'cdek'|'yandex'}                 second
 * @param {boolean}                         blankLeftSlot Blank the first carrier's hidden slot before the retry (see the file header).
 * @returns {Promise<{ failedId: number, retriedId: number|null, errors: string[] }>}
 */
async function failThenSwitch( page, first, second, blankLeftSlot ) {
	const before = latestOrderId();

	await openCheckout( page );
	await chooseShipping( page, CARRIERS[ first ].method );
	await pickFirstPoint( page, CARRIERS[ first ].slot );
	await fillContact( page, `fp-${ first }` );
	await choosePayment( page, 'y15_flaky' );

	const failed = await placeOrder( page );

	expect( failed.orderId, 'the first attempt must NOT be paid' ).toBeNull();
	expect( failed.errors.join( ' ' ), 'the flaky gateway declined' ).toMatch( /payment declined/i );

	const failedId = latestOrderId();

	expect( failedId, 'the failed attempt created an order' ).toBeGreaterThan( before );

	const failedOrder = readOrder( failedId );

	expect( failedOrder.status ).toBe( 'failed' );
	expectOnlyCarrier( failedOrder, first, `failed order ${ failedId }` );

	// The retry, same session, other carrier.
	await chooseShipping( page, CARRIERS[ second ].method );
	await pickFirstPoint( page, CARRIERS[ second ].slot );

	if ( blankLeftSlot ) {
		await page.evaluate( ( name ) => {
			document.querySelectorAll( `input[name="${ name }"]` ).forEach( ( input ) => {
				input.value = '';
			} );
		}, CARRIERS[ first ].slot );
	}

	await choosePayment( page, 'y15_flaky' );

	const retried = await placeOrder( page );

	return { failedId, retriedId: retried.orderId, errors: retried.errors };
}

/**
 * The assertions of the scenario: the retry reused the failed order, and that order now carries ONLY the second carrier.
 *
 * @param {number}          failedId
 * @param {number|null}     retriedId
 * @param {string[]}        errors
 * @param {'cdek'|'yandex'} second
 */
function expectCleanRetry( failedId, retriedId, errors, second ) {
	expect( errors.join( ' | ' ), 'the retry was refused' ).toBe( '' );
	expect( retriedId, 'the retry resumed the SAME order (equal totals keep the cart hash)' ).toBe( failedId );

	const order = readOrder( failedId );

	expect.soft( order.status, `order ${ failedId } after the accepted retry` ).toBe( 'processing' );
	expectOnlyCarrier( order, second, `order ${ failedId }` );

}

test.describe( 'scenario 2 — failed payment, then the other carrier on the same order', () => {
	test( 'Yandex → declined → CDEK: the order keeps no Yandex data', async ( { page } ) => {
		knownDefect( test, 'F3+F6: the form re-posts the left carrier\'s point (Pickup_Handler::handle_checkout_order_processed) and nothing deletes what the first attempt wrote.' );

		const { failedId, retriedId, errors } = await failThenSwitch( page, 'yandex', 'cdek', false );

		expectCleanRetry( failedId, retriedId, errors, 'cdek' );
	} );

	test( 'Yandex → declined → CDEK, left slot blanked: the order keeps no Yandex data', async ( { page } ) => {
		knownDefect( test, 'F6: a reused order keeps the meta its first attempt wrote for the carrier the buyer then left; no code path deletes it.' );

		const { failedId, retriedId, errors } = await failThenSwitch( page, 'yandex', 'cdek', true );

		expectCleanRetry( failedId, retriedId, errors, 'cdek' );
	} );

	test( 'CDEK → declined → Yandex: the order keeps no CDEK data', async ( { page } ) => {
		knownDefect( test, 'F4 (+F3+F6): Yandex_Pickup_Guard refuses the stale CDEK point, so the retry cannot even be placed.' );

		const { failedId, retriedId, errors } = await failThenSwitch( page, 'cdek', 'yandex', false );

		expectCleanRetry( failedId, retriedId, errors, 'yandex' );
	} );

	test( 'CDEK → declined → Yandex, left slot blanked: the order keeps no CDEK data', async ( { page } ) => {
		knownDefect( test, 'F6: a reused order keeps the meta its first attempt wrote for the carrier the buyer then left; no code path deletes it.' );

		const { failedId, retriedId, errors } = await failThenSwitch( page, 'cdek', 'yandex', true );

		expectCleanRetry( failedId, retriedId, errors, 'yandex' );
	} );
} );
