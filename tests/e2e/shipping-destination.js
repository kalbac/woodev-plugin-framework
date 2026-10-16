/**
 * The shipping-destination mode a classic-checkout spec runs under (#1306).
 *
 * The rig runs `woocommerce_ship_to_destination = billing_only` (operator decision, s181: the CIS norm — ship to the billing
 * address, no separate shipping column), so a spec that asserts «on BOTH columns» cannot rely on whatever the rig holds. A
 * spec states the mode it needs: `useShippingDestination( mode )` inside a `describe` sets the WooCommerce option for that
 * describe and puts the original back afterwards — through the coexistence specs' `optionScope()`, so an aborted run is one
 * `restore()` away from the rig it found, and the rig is never left switched (gotcha
 * `a-rig-option-left-switched-after-an-acceptance-reads-as-stale-e2e-tests`).
 *
 * This is not a spec file (no `.spec.js` suffix), so Playwright does not pick it up as one.
 */

const { test } = require( '@playwright/test' );
const { optionScope } = require( './coexistence/helpers' );

const OPTION = 'woocommerce_ship_to_destination';

/**
 * The two modes every classic-checkout scenario runs under.
 *
 *  - `shipping`     WooCommerce's default: a separate shipping column beside billing. `columns` are the address columns on
 *                   the page; `live` is the column the location cascade is live on (Rule 7c: the shipping one while it is
 *                   shown).
 *  - `billing_only` the rig's own norm: billing is the only address column, the shipping fields do not exist.
 */
const DESTINATIONS = [
	{ mode: 'shipping', label: 'separate shipping column', columns: [ 'billing', 'shipping' ], live: 'shipping' },
	{ mode: 'billing_only', label: 'billing_only', columns: [ 'billing' ], live: 'billing' },
];

/**
 * Sets `woocommerce_ship_to_destination` for the enclosing `describe` and restores the original afterwards.
 *
 * @param {string} mode `shipping`, `billing` or `billing_only`.
 */
function useShippingDestination( mode ) {
	const options = optionScope();

	test.beforeAll( () => {
		options.set( OPTION, mode );
	} );

	test.afterAll( () => {
		options.restore();
	} );
}

module.exports = { DESTINATIONS, useShippingDestination };
