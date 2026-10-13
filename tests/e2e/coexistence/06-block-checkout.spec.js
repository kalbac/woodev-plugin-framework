/**
 * Scenario 1 on the BLOCK checkout (`/checkout/`): CDEK point → Yandex point → CDEK point in one session.
 *
 * «Block-only gaps are NOT release blockers» (operator, 08.10.2026) — this spec records them rather than gating on them:
 *  - the order placed after re-picking the CDEK point is clean (passes);
 *  - B1 (known gap, `expectKnownDefect`): classic checkout restores the remembered CDEK point when CDEK is chosen again, the block
 *    checkout shows «Select a pickup point» again and the form keeps the Yandex point's address and an empty postcode
 *    until the buyer re-picks, so «Place order» is refused («Please choose a pickup point», «valid postal code»).
 *
 * The block checkout has no default locality on the rig for a guest: the buyer finds a locality first («Find your locality»),
 * and only then do the carriers' methods appear. The Yandex courier method is off for the run (defect F2 of scenario 1).
 */

const { test, expect } = require( '@playwright/test' );
const {
	METHOD_YANDEX_COURIER,
	PRODUCT_ID,
	expectKnownDefect,
	expectOnlyCarrier,
	readOrder,
	recordOrder,
	requireRig,
	setInstanceEnabled,
	zoneInstances,
} = require( './helpers' );

let courierBefore = [];

test.beforeAll( () => {
	requireRig();

	courierBefore = zoneInstances( METHOD_YANDEX_COURIER );

	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, false );
	}
} );

test.afterAll( () => {
	for ( const row of courierBefore ) {
		setInstanceEnabled( row.instance_id, 1 === Number( row.is_enabled ) );
	}
} );

/** Opens the block checkout and finds the Moscow locality, so the carriers offer their methods. */
async function openBlockCheckout( page ) {
	await page.goto( `/?add-to-cart=${ PRODUCT_ID }` );
	await page.goto( '/checkout/' );
	await page.waitForSelector( '.wc-block-checkout', { timeout: 60_000 } );
	await page.locator( 'text=Find your locality' ).locator( 'xpath=following::input[1]' ).fill( 'Москва' );
	await page.locator( '[role=option]' ).first().click();
	await expect( page.locator( '.wc-block-components-radio-control__option', { hasText: 'CDEK pickup point' } ).first() ).toBeVisible( { timeout: 60_000 } );
	await page.waitForTimeout( 8000 );
}

const chosenRate = ( page ) => page.evaluate( () => ( [ ...document.querySelectorAll( '.wc-block-components-shipping-rates-control input[type=radio]' ) ].find( ( i ) => i.checked ) || {} ).value );

async function chooseRate( page, label ) {
	const wanted = 'CDEK pickup point' === label ? /^edostavka_pickup:/ : /^yandex_delivery_pickup:/;

	// The block checkout re-renders its rates after every locality / method change and can swallow a click made in
	// between, so the click is repeated until the choice sticks (a selection that never sticks still fails, below).
	await expect.poll(
		async () => {
			if ( ! wanted.test( ( await chosenRate( page ) ) || '' ) ) {
				await page.locator( '.wc-block-components-radio-control__option', { hasText: label } ).first().click();
				await page.waitForTimeout( 2500 );
			}

			return wanted.test( ( await chosenRate( page ) ) || '' );
		},
		{ timeout: 60_000, intervals: [ 500 ] }
	).toBe( true );
	await page.waitForTimeout( 3000 );
}

async function pickPoint( page ) {
	await page.locator( 'button:has-text("pickup point")' ).first().click();
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
	await expect( cta ).not.toHaveText( before, { timeout: 30_000 } );
	await cta.click();
	await page.waitForSelector( '.woodev-modal__content', { state: 'detached' } );
	await page.waitForTimeout( 3000 );
}

async function fillAndPlace( page ) {
	await page.fill( '#email', `y15-block-${ Date.now() }@example.com` );

	for ( const [ selector, value ] of [ [ '#shipping-first_name', 'Тест' ], [ '#billing-first_name', 'Тест' ], [ '#shipping-last_name', 'Y15' ], [ '#billing-last_name', 'Y15' ], [ '#shipping-phone', '+79001234567' ], [ '#billing-phone', '+79001234567' ] ] ) {
		if ( await page.locator( selector ).count() ) {
			await page.locator( selector ).fill( value );
		}
	}

	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();

	return Promise.race( [
		page.waitForURL( /order-received\/\d+/, { timeout: 90_000 } ).then( () => {
			const orderId = Number( page.url().match( /order-received\/(\d+)/ )[ 1 ] );

			recordOrder( orderId );

			return { orderId, errors: [] };
		} ),
		page.locator( '.wc-block-components-notice-banner.is-error, .wc-block-components-validation-error' ).first().waitFor( { timeout: 90_000 } )
			.then( async () => ( { orderId: null, errors: await page.locator( '.wc-block-components-notice-banner.is-error, .wc-block-components-validation-error' ).allInnerTexts() } ) ),
	] );
}

test.describe( 'scenario 1 on the block checkout', () => {
	test( 'CDEK → Yandex → CDEK, the CDEK point re-picked: the order carries only the CDEK point', async ( { page } ) => {
		await openBlockCheckout( page );

		await chooseRate( page, 'CDEK pickup point' );
		await pickPoint( page );
		await chooseRate( page, 'Yandex pickup point' );
		await pickPoint( page );
		await chooseRate( page, 'CDEK pickup point' );
		await pickPoint( page );

		const { orderId, errors } = await fillAndPlace( page );

		expect( errors.join( ' | ' ), 'the block checkout refused the order' ).toBe( '' );

		expectOnlyCarrier( readOrder( orderId ), 'cdek', `order ${ orderId }` );
	} );

	test( 'B1: switching back to CDEK restores its point, as the classic checkout does', async ( { page } ) => {
		await openBlockCheckout( page );

		await chooseRate( page, 'CDEK pickup point' );
		await pickPoint( page );
		await chooseRate( page, 'Yandex pickup point' );
		await pickPoint( page );
		await chooseRate( page, 'CDEK pickup point' );

		const { orderId, errors } = await fillAndPlace( page );

		// The one assertion the defect owns; no order exists while it stands, so the order check waits for the fix.
		if (
			await expectKnownDefect(
				'B1',
				'block-only: the block checkout does not restore the remembered CDEK point after another carrier was chosen; the form keeps the other carrier\'s address and an empty postcode.',
				() => expect( errors.join( ' | ' ), 'placing the order without re-picking' ).toBe( '' )
			)
		) {
			return;
		}

		expectOnlyCarrier( readOrder( orderId ), 'cdek', `order ${ orderId }` );
	} );
} );
