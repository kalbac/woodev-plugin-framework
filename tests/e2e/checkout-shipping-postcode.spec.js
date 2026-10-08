/**
 * Classic checkout keeps manually entered shipping address values through update_checkout (#1170).
 *
 * Runs against the live dev rig; it must be served by the framework branch under test.
 */

const { test, expect } = require( '@playwright/test' );

test( 'typing the shipping phone keeps the postcode and street on classic checkout (#1170)', async ( { page } ) => {
	await page.goto( '/?add-to-cart=12' );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( 'form.checkout' ) ).toBeVisible();
	await expect( page.locator( '#shipping_postcode' ) ).toHaveValue( '101000' );
	await page.waitForFunction( () => document.querySelectorAll( '.blockOverlay' ).length === 0, null, { timeout: 40_000 } );

	await page.locator( '#shipping_postcode' ).fill( '101000' );
	await page.locator( '#shipping_address_1' ).fill( 'ул. Тверская, 1' );

	await page.locator( '#shipping_phone' ).fill( '+79991234567' );
	// Leave the phone field so every blur/change handler of the three fields has run.
	await page.locator( 'body' ).click( { position: { x: 5, y: 5 } } );
	await page.waitForFunction( () => document.querySelectorAll( '.blockOverlay' ).length === 0, null, { timeout: 40_000 } );

	// Poll, never sleep-and-read: before the fix the postcode was emptied a few hundred ms after the blur.
	await expect.poll( () => page.locator( '#shipping_postcode' ).inputValue(), { timeout: 10_000, intervals: [ 500, 1000, 2000 ] } ).toBe( '101000' );
	await expect( page.locator( '#shipping_postcode' ) ).toHaveValue( '101000' );
	await expect( page.locator( '#shipping_address_1' ) ).toHaveValue( 'ул. Тверская, 1' );

	// The values the next order review sends are the ones WooCommerce validates on «Place order».
	const update = page.waitForResponse( ( response ) => response.url().includes( 'wc-ajax=update_order_review' ), { timeout: 40_000 } );
	await page.evaluate( () => window.jQuery( document.body ).trigger( 'update_checkout' ) );
	const response = await update;
	const checkoutFields = new URLSearchParams(
		new URLSearchParams( response.request().postData() || '' ).get( 'post_data' ) || ''
	);

	expect( response.status() ).toBe( 200 );
	expect( checkoutFields.get( 'shipping_postcode' ) ).toBe( '101000' );
	expect( checkoutFields.get( 'shipping_address_1' ) ).toBe( 'ул. Тверская, 1' );
} );
