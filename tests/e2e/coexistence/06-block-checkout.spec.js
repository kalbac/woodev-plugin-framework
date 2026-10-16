/**
 * Scenario 1 on the BLOCK checkout (`/checkout/`): CDEK point → Yandex point → CDEK point in one session.
 *
 *  - the order placed after re-picking the CDEK point is clean;
 *  - B1 (fixed in #1263): choosing CDEK again restores the remembered CDEK point, as the classic checkout does. The Yandex point
 *    had written its own street and postcode into the form, so the block confirms the CDEK point again and moves the address
 *    back; «Place order» then needs no re-pick (before the fix: «Select a pickup point» again, the Yandex address kept, the
 *    order refused).
 *
 * The block checkout has no default locality on the rig for a guest: the buyer finds a locality first («Find your locality»),
 * and only then do the carriers' methods appear. The Yandex courier method is off for the run, as in scenarios 1 and 2.
 */

const { test, expect } = require( '@playwright/test' );
const {
	METHOD_YANDEX_COURIER,
	PRODUCT_ID,
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

		const cdekPoint = ( await page.locator( '.woodev-pickup-block__chosen strong' ).innerText() ).trim();
		const cdekAddress = await page.evaluate( () => window.wp.data.select( 'wc/store/cart' ).getCustomerData().shippingAddress.address_1 );

		await chooseRate( page, 'Yandex pickup point' );
		await pickPoint( page );
		await chooseRate( page, 'CDEK pickup point' );

		// The remembered CDEK point comes back on its own — shown, and the address moved back from the Yandex point's.
		await expect( page.locator( '.woodev-pickup-block__chosen strong' ) ).toHaveText( cdekPoint, { timeout: 60_000 } );
		await expect
			.poll( () => page.evaluate( () => window.wp.data.select( 'wc/store/cart' ).getCustomerData().shippingAddress.address_1 ), { timeout: 30_000 } )
			.toBe( cdekAddress );

		const { orderId, errors } = await fillAndPlace( page );

		expect( errors.join( ' | ' ), 'placing the order without re-picking' ).toBe( '' );

		expectOnlyCarrier( readOrder( orderId ), 'cdek', `order ${ orderId }` );
	} );
} );

/*
 * #1279 — WooCommerce core, reproduced on a clean WooCommerce 11.2.1 with none of our plugins: the buyer edits the postcode and
 * presses «Place order» straight away. The press takes focus from the field, core pushes the address at once, the checkout turns
 * «calculating» and the button is disabled before the mouse is released — the click is lost. The framework's guard
 * (`src/checkout-blocks/place-order-guard.ts`) carries that one press out when the button is enabled again.
 */
test.describe( 'the first «Place order» press right after a postcode edit (#1279)', () => {
	test( 'places the order', async ( { page } ) => {
		await openBlockCheckout( page );

		const courier = page.locator( '.wc-block-components-radio-control__option', { hasText: 'CDEK courier delivery' } ).first();

		await courier.click();
		await page.waitForTimeout( 3000 );
		await page.fill( '#email', `y15-block-1279-${ Date.now() }@example.com` );

		for ( const [ name, value ] of [ [ 'first_name', 'Тест' ], [ 'last_name', 'Y15' ], [ 'phone', '+79001234567' ], [ 'address_1', 'ул. Тверская, 1' ], [ 'postcode', '125009' ] ] ) {
			for ( const prefix of [ '#shipping-', '#billing-' ] ) {
				const field = page.locator( prefix + name );

				if ( ( await field.count() ) && ( await field.isVisible() ) ) {
					await field.fill( value );
				}
			}
		}

		const postcode = page.locator( '#billing-postcode:visible, #shipping-postcode:visible' ).first();

		// Every edit above pushed and answered: the button is enabled and the form is settled.
		await postcode.blur();
		await expect( page.locator( '.wc-block-components-checkout-place-order-button' ) ).toBeEnabled( { timeout: 60_000 } );
		await page.waitForTimeout( 5000 );

		// The edit the buyer makes last, and the press that follows it — a human press, not an instant one.
		await postcode.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Backspace' );
		await page.keyboard.type( '5' );

		const button = page.locator( '.wc-block-components-checkout-place-order-button' );

		await button.scrollIntoViewIfNeeded();

		const box = await button.boundingBox();
		const checkoutPosts = [];

		page.on( 'request', ( request ) => {
			const url = decodeURIComponent( request.url() );

			if ( 'POST' === request.method() && /wc\/store\/v1\/checkout/.test( url ) && ! /calc_totals/.test( url + ( request.postData() || '' ) ) ) {
				checkoutPosts.push( url );
			}
		} );

		await page.mouse.move( box.x + ( box.width / 2 ), box.y + ( box.height / 2 ) );
		await page.mouse.down();
		await page.waitForTimeout( 100 );
		await page.mouse.up();

		await page.waitForURL( /order-received\/\d+/, { timeout: 90_000 } );

		const orderId = Number( page.url().match( /order-received\/(\d+)/ )[ 1 ] );

		recordOrder( orderId );
		expect( checkoutPosts, 'one order request for one press' ).toHaveLength( 1 );

		const order = readOrder( orderId );

		expect( order.shipping_lines.map( ( line ) => line.method_id ), `order ${ orderId }: shipping line` ).toEqual( [ 'edostavka_courier' ] );
		// Placed for the address the buyer had just typed, not the one before the edit.
		expect( [ order.billing.postcode, order.shipping.postcode ], `order ${ orderId }: postcode` ).toContain( '125005' );
	} );
} );
