/**
 * The classic-checkout pickup walkthrough, as a test instead of a ritual — issue #723.
 *
 * This is the procedure in `docs-internal/wiki/rig-pickup-walkthrough.md`, which has been
 * run BY HAND in more than twenty sessions. Every selector below was measured on the live
 * rig on 01.09.2026 rather than guessed, and one of those measurements already contradicted
 * the wiki: the pickup control is `button.woodev-pickup-trigger`, not the
 * `.woodev-pickup-list__toggle` the procedure names.
 *
 * WHAT THIS SUITE IS FOR, and what it is not:
 * it pins the things a human keeps re-checking and jsdom structurally cannot see — which
 * column the location fields land on, which rows the pickup policy hides, whether the
 * pickup control exists, and whether «Place order» is actually clickable. It does NOT
 * replace the operator's own pass: a scripted test only asserts what somebody thought to
 * assert, and #721 was found by a human noticing a dead button nobody had scripted.
 *
 * PRECONDITIONS ARE ASSERTED, NEVER SEEDED. The rig is the operator's interactive
 * environment; a test that quietly rewrote his options would be worse than one that fails.
 * Every precondition below fails with the exact option name and expected value.
 */

const { test, expect } = require( '@playwright/test' );

/** Product id that fills the cart — `docs-internal/CURRENT-STATE.md` → Local rig. */
const PRODUCT_ID = 12;

/**
 * Shipping method ids as WooCommerce renders their radio VALUES (`instance_id` suffixed).
 * `woodev_test_shipping` became a pickup method in #709.
 */
const METHOD_FREE   = 'free_shipping:1';
const METHOD_PICKUP = 'woodev_test_shipping:3';

/**
 * Fills the cart and lands on the classic checkout.
 *
 * `/checkout/` is the BLOCK checkout, where none of this exists — gotcha
 * `rig-checkout-url-is-the-block-checkout`.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openCheckout( page ) {
	await page.goto( `/?add-to-cart=${ PRODUCT_ID }` );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( 'form.checkout' ) ).toBeVisible();
	await settle( page );
}

/**
 * Waits for WooCommerce's `update_checkout` to finish.
 *
 * WooCommerce covers the review order with a `.blockOverlay` while the AJAX round-trip is in
 * flight, and the walkthrough's own step 6 warns that reading state before it clears gives a
 * transitional answer. So: no overlay, then one animation frame of quiet.
 *
 * @param {import('@playwright/test').Page} page
 */
async function settle( page ) {
	await page.waitForFunction(
		() => document.querySelectorAll( '.blockOverlay' ).length === 0,
		null,
		{ timeout: 30_000 }
	);
	await page.waitForTimeout( 400 );
}

/**
 * Selects a shipping method by its radio value and waits for the checkout to settle.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          value
 */
async function chooseShipping( page, value ) {
	const radio = page.locator( `input[name^="shipping_method"][value="${ value }"]` );

	await expect(
		radio,
		`Shipping method "${ value }" is not offered on the rig. The fixture plugin and the ` +
		'zz-rig-test-pickup-shipping mu-plugin must both be active.'
	).toHaveCount( 1 );

	await radio.check();
	await settle( page );
}

/**
 * The `_field` row WooCommerce wraps a checkout input in.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          id
 * @returns {import('@playwright/test').Locator}
 */
function row( page, id ) {
	return page.locator( `#${ id }_field` );
}

/**
 * The pickup trigger OF THE CARRIER UNDER TEST.
 *
 * ⚠ Scoped to its slot on purpose (s112, card #734). The rig now runs TWO carrier plugins
 * side by side — the ordinary production arrangement — so a bare
 * `button.woodev-pickup-trigger` matches two elements and Playwright fails the whole
 * assertion with a strict-mode violation rather than a useful message. The slot container is
 * keyed by the carrier's checkout FIELD id, which is what makes the two distinguishable.
 *
 * This walkthrough is about `woodev_test_shipping`, whose field is `carrier_pickup_point`.
 */
const PICKUP_TRIGGER = '#woodev-pickup-slot-carrier_pickup_point-review button.woodev-pickup-trigger';

test.describe( 'classic checkout — pickup walkthrough (#723)', () => {

	test( 'preconditions: the rig serves a checkout with our fixture methods', async ( { page } ) => {
		await openCheckout( page );

		// The location layer publishes its config under a per-plugin global; its absence means
		// the checkout handler never enqueued, which makes every later assertion meaningless.
		const configured = await page.evaluate( () =>
			Object.keys( window ).some( ( k ) => k.indexOf( 'woodev_checkout_field_config' ) === 0 )
		);

		expect(
			configured,
			'No woodev_checkout_field_config_* global on the page — the framework checkout ' +
			'handler did not enqueue. Is the test shipping-method fixture plugin active?'
		).toBe( true );

		await expect( page.locator( 'input[name^="shipping_method"]' ).first() ).toBeAttached();
	} );

	test( 'location fields fan out to BOTH address columns (Rule 7b, #458)', async ( { page } ) => {
		await openCheckout( page );

		// `effective_fields()` fans a `source_location()` field into billing_* AND shipping_*
		// on any store that is not force-ship-to-billing. Measured on the rig: all six exist.
		for ( const id of [
			'billing_state', 'billing_city', 'billing_address_1',
			'shipping_state', 'shipping_city', 'shipping_address_1',
		] ) {
			await expect( page.locator( `#${ id }` ), `${ id } must exist` ).toHaveCount( 1 );
		}
	} );

	test( 'a courier method leaves the address visible and offers no pickup control', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_FREE );

		await expect( row( page, 'billing_address_1' ) ).toBeVisible();
		await expect( row( page, 'billing_postcode' ) ).toBeVisible();

		// PRESENT but HIDDEN, measured 01.09.2026: the framework always renders the trigger and
		// hides its CONTAINER (`display: none` on the parent) when the chosen method is not a
		// pickup one. The first draft of this test asserted `toHaveCount( 0 )` and failed —
		// correctly, against a wrong expectation of mine, not against the code.
		await expect( page.locator( PICKUP_TRIGGER ) ).toBeHidden();
	} );

	test( 'a pickup method hides address and postcode on BOTH columns and offers the trigger', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_PICKUP );

		// `hide_for_pickup` policy (#362 §4.3), client-side by design — the class is the
		// contract, the invisibility is the effect. Assert both, so a CSS-only regression
		// and a logic-only regression are distinguishable.
		for ( const id of [
			'billing_address_1', 'billing_postcode',
			'shipping_address_1', 'shipping_postcode',
		] ) {
			await expect( row( page, id ), `${ id } row must carry the policy class` )
				.toHaveClass( /woodev-field--hidden-for-pickup/ );
			await expect( row( page, id ), `${ id } row must not be visible` ).toBeHidden();
		}

		// #709: this method is a pickup method in every declaration now, so the control exists.
		await expect( page.locator( PICKUP_TRIGGER ) ).toBeVisible();
	} );

	test( 'the A2 gate blocks while no pickup point is chosen, and does NOT block on a courier method (#721)', async ( { page } ) => {
		await openCheckout( page );

		// The defect direction. #721: takeover fields WooCommerce never rendered used to hold
		// this permanently disabled, so an order could never be placed by anyone.
		await chooseShipping( page, METHOD_FREE );
		await expect(
			page.locator( '#place_order' ),
			'«Place order» must be enabled on a courier method — this is the #721 regression'
		).toBeEnabled();

		// The direction that must KEEP working: a pickup method with no point picked is a
		// genuinely incomplete order, and the gate is the signal (#274 — no inline caption).
		await chooseShipping( page, METHOD_PICKUP );
		await expect(
			page.locator( '#place_order' ),
			'«Place order» must be disabled while a pickup method has no chosen point'
		).toBeDisabled();
	} );

	test( 'the pickup modal opens from the trigger', async ( { page } ) => {
		await openCheckout( page );
		await chooseShipping( page, METHOD_PICKUP );

		await page.locator( PICKUP_TRIGGER ).click();

		const dialog = page.locator( '.woodev-modal__content' );

		await expect( dialog ).toBeVisible();
		await expect( page.locator( '.woodev-modal__title' ) ).toHaveText( /pickup point|пункт/i );

		// Closing must restore the page rather than leaving the backdrop mounted.
		await page.locator( '.woodev-modal__close' ).click();
		await expect( dialog ).toBeHidden();
	} );

	test( 'the settlement field is a LIVE source: typing issues a request the server answers', async ( { page } ) => {
		await openCheckout( page );

		// Both levels are select2-enhanced `<select>`s on the standard rig config
		// (`field_mode_settlement = ajax-select2`). select2 replaces the control with its own UI,
		// so drive it the way a customer does rather than writing to the `<select>`.
		const settlement = page.locator( '#shipping_city' );

		await expect( settlement ).toHaveJSProperty( 'tagName', 'SELECT' );

		// WHAT THIS ASSERTS, and why it is not "Moscow comes back".
		// The settlement search is SCOPED BY THE REGION (#551/#552), including a region filled in
		// by the default-locality path, and the rig's stored region is whatever the operator last
		// left it as. Two earlier drafts of this test proved the point rather than the code: asking
		// for «Моск» under a Saint-Petersburg scope returned Petersburg settlements, and a bare «а»
		// returned «No results found» — both CORRECT behaviour, both making the assertion flaky.
		// So this pins the contract that does not move: typing reaches OUR endpoint and the server
		// answers it. Which settlements come back is the scope rule's job, and unit tests own that.
		const answered = page.waitForResponse(
			( response ) =>
				/\/woodev\/v1\/.*location/.test( response.url() ) && response.request().method() === 'GET',
			{ timeout: 40_000 }
		);

		await page.locator( '#shipping_city_field .select2-selection' ).click();

		const search = page.locator( 'input.select2-search__field' );

		await expect( search ).toBeVisible();
		await search.fill( 'ск' );

		const response = await answered;

		expect(
			response.status(),
			`The location endpoint answered ${ response.status() } for ${ response.url() }`
		).toBeLessThan( 400 );

		// select2's own loading row must clear — otherwise the transport never resolved at all,
		// which is a different failure from "the server said no results".
		await expect( page.locator( '.select2-results__option.loading-results' ) )
			.toHaveCount( 0, { timeout: 40_000 } );

		// Either real options or select2's honest "no results" row is an ANSWER. A blank dropdown
		// is not, and neither is a JS exception, which would leave the results list empty.
		await expect( page.locator( '.select2-results__option' ).first() ).toBeVisible();
	} );
} );
