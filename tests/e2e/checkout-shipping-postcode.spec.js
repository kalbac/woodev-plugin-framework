/**
 * Classic checkout keeps manually entered shipping address values through update_checkout (#1170).
 *
 * Runs against the live dev rig; it must be served by the framework branch under test.
 */

const { test, expect } = require( '@playwright/test' );
const { DESTINATIONS, useShippingDestination } = require( './shipping-destination' );

/**
 * Once per destination mode (#1306). The rig runs `billing_only`; with a shipping column the manually typed values live on the
 * shipping fields, under `billing_only` on the billing ones (the only column there is).
 */
for ( const { mode, label, live } of DESTINATIONS ) {
	test.describe( `classic checkout keeps manually typed address values, ${ label }`, () => {
		useShippingDestination( mode );

		test( `typing the ${ live } phone keeps the postcode and street on classic checkout (#1170)`, async ( { page } ) => {
			await page.goto( '/?add-to-cart=12' );
			await page.goto( '/classic-checkout/' );
			await expect( page.locator( 'form.checkout' ) ).toBeVisible();
			// The page is ready once the row is there. This used to assert the postcode was PREFILLED with 101000, which was the rig's
			// default-locality record of the day; the record now carries no postcode (`woodev_location_default_locality_record`), so
			// nothing is prefilled and the test types the value itself below — which is what the regression is about.
			await expect( page.locator( `#${ live }_postcode` ) ).toBeVisible();
			await page.waitForFunction( () => document.querySelectorAll( '.blockOverlay' ).length === 0, null, { timeout: 40_000 } );

			await page.locator( `#${ live }_postcode` ).fill( '101000' );
			await page.locator( `#${ live }_address_1` ).fill( 'ул. Тверская, 1' );

			await page.locator( `#${ live }_phone` ).fill( '+79991234567' );
			// Leave the phone field so every blur/change handler of the three fields has run.
			await page.locator( 'body' ).click( { position: { x: 5, y: 5 } } );
			await page.waitForFunction( () => document.querySelectorAll( '.blockOverlay' ).length === 0, null, { timeout: 40_000 } );

			// Poll, never sleep-and-read: before the fix the postcode was emptied a few hundred ms after the blur.
			await expect.poll( () => page.locator( `#${ live }_postcode` ).inputValue(), { timeout: 10_000, intervals: [ 500, 1000, 2000 ] } ).toBe( '101000' );
			// The page is ready once the row is there. This used to assert the postcode was PREFILLED with 101000, which was the rig's
			// default-locality record of the day; the record now carries no postcode (`woodev_location_default_locality_record`), so
			// nothing is prefilled and the test types the value itself below — which is what the regression is about.
			await expect( page.locator( `#${ live }_postcode` ) ).toBeVisible();
			await expect( page.locator( `#${ live }_address_1` ) ).toHaveValue( 'ул. Тверская, 1' );

			// The values the next order review sends are the ones WooCommerce validates on «Place order».
			const update = page.waitForResponse( ( response ) => response.url().includes( 'wc-ajax=update_order_review' ), { timeout: 40_000 } );
			await page.evaluate( () => window.jQuery( document.body ).trigger( 'update_checkout' ) );
			const response = await update;
			const checkoutFields = new URLSearchParams(
				new URLSearchParams( response.request().postData() || '' ).get( 'post_data' ) || ''
			);

			expect( response.status() ).toBe( 200 );
			expect( checkoutFields.get( `${ live }_postcode` ) ).toBe( '101000' );
			expect( checkoutFields.get( `${ live }_address_1` ) ).toBe( 'ул. Тверская, 1' );
		} );
	} );
}
