/**
 * WooCommerce's OWN «Самовывоз» (`local_pickup`) on the classic checkout — issue #1176.
 *
 * Two things the framework now does for a method it does not own, and that jsdom/phpunit cannot see:
 *
 *  1. `hide_for_pickup` applies to it: choosing core pickup hides the address and postcode rows and takes their
 *     `required` away, exactly like a plugin pickup method (the list that hides the rows names core pickup too —
 *     `Checkout_Config::field_policy_pickup_method_ids()`);
 *  2. the city limit applies to it: with «Доступен только в городах» set on the instance, a customer whose city
 *     is not in the list is not offered it, and a customer whose city is, is.
 *
 * ⛔ NOT RUN by its author — the rig serves the PRIMARY checkout and the bundle is built there; the coordinator runs
 * this after building. PRECONDITIONS ARE ASSERTED, NEVER SEEDED (see `checkout-pickup.spec.js`): the rig is the
 * operator's interactive environment. What the rig needs, once:
 *
 *  - a `local_pickup` instance in the Russia zone (WooCommerce → Settings → Shipping → Russia → Add method);
 *  - `woodev_checkout_fields_address_field` / `_postcode_field` = `hide_for_pickup` (the rig's standard);
 *  - for the city-limit test only: `WOODEV_E2E_CITY_LIMIT=1`, the CDEK plugin DEACTIVATED (it drops the `test-cdek`
 *    fixture provider — gotcha `a-fixture-plugin-below-the-winners-backwards-compatible-floor-is-silently-dropped`),
 *    `woodev_location_active_provider=test-cdek`, and the `local_pickup` instance set to «Доступен только в городах»
 *    with the single city «Пушкин». Restore all of it afterwards.
 */

const { test, expect } = require( '@playwright/test' );

/** Product id that fills the cart — `docs-internal/CURRENT-STATE.md` → Local rig. */
const PRODUCT_ID = 12;

const METHOD_CORE_PICKUP = 'local_pickup';
const METHOD_FREE        = 'free_shipping';

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function settle( page ) {
	await page.waitForFunction(
		() => document.querySelectorAll( '.blockOverlay' ).length === 0,
		null,
		{ timeout: 30_000 }
	);
	await page.waitForTimeout( 600 );
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function openCheckout( page ) {
	await page.goto( `/?add-to-cart=${ PRODUCT_ID }` );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( 'form.checkout' ) ).toBeVisible();
	await settle( page );
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string}                          methodId Method id without the instance suffix.
 * @returns {Promise<void>}
 */
async function chooseShipping( page, methodId ) {
	const radio = page.locator( `input[name^="shipping_method"][value^="${ methodId }:"]` );

	await expect(
		radio,
		`Shipping method "${ methodId }" is not offered on the rig. WooCommerce's «Самовывоз» needs an instance in the ` +
		'Russia zone (see this file\'s header).'
	).toHaveCount( 1 );

	await radio.check();
	await settle( page );
}

const row = ( page, id ) => page.locator( `#${ id }_field` );

test.describe( 'classic checkout — WooCommerce core pickup (#1176)', () => {

	test( 'core pickup hides the address and postcode rows and a courier brings them back', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_CORE_PICKUP );

		for ( const id of [ 'shipping_address_1', 'shipping_postcode' ] ) {
			await expect( row( page, id ), `${ id } row must carry the policy class` ).toHaveClass( /woodev-field--hidden-for-pickup/ );
			await expect( row( page, id ), `${ id } row must not be visible` ).toBeHidden();
		}

		// What was required stays decided by the page: the row is hidden, so it must not block the order.
		await expect( page.locator( '#shipping_address_1' ) ).not.toHaveJSProperty( 'required', true );

		await chooseShipping( page, METHOD_FREE );

		for ( const id of [ 'shipping_address_1', 'shipping_postcode' ] ) {
			await expect( row( page, id ) ).not.toHaveClass( /woodev-field--hidden-for-pickup/ );
			await expect( row( page, id ) ).toBeVisible();
		}
	} );

	test( 'a method that only looks like core pickup does not hide the address', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_FREE );

		await expect( row( page, 'shipping_address_1' ) ).toBeVisible();
	} );

	test( 'city limit: core pickup is offered in a listed city and not elsewhere', async ( { page } ) => {
		test.skip( ! process.env.WOODEV_E2E_CITY_LIMIT, 'Needs the rig prepared as the file header says; set WOODEV_E2E_CITY_LIMIT=1.' );

		await openCheckout( page );

		const corePickup = page.locator( `input[name^="shipping_method"][value^="${ METHOD_CORE_PICKUP }:"]` );

		/** Picks a settlement in the framework's city select. */
		async function pickCity( name ) {
			await page.click( '#shipping_city_field .select2-selection' );
			await page.click( `.select2-results__option:has-text("${ name }")` );
			await settle( page );
		}

		await pickCity( 'Москва' );
		await expect( corePickup, 'Moscow is not in the list: core pickup must not be offered' ).toHaveCount( 0 );

		await pickCity( 'Пушкин' );
		await expect( corePickup, 'Pushkin is in the list: core pickup must be offered' ).toHaveCount( 1 );
	} );
} );
