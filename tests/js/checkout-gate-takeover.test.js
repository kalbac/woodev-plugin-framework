/**
 * The A2 gate and takeover fields — issue #721.
 *
 * `refreshGate()` disables «Place order» whenever ANY field the store knows about is
 * `required` and empty. A takeover field is deliberately NOT injected onto the form
 * (`Checkout_Handler::inject()` skips it — "takeover fields are owned entirely by the
 * CLIENT"), and WooCommerce's own per-field visibility settings can remove its native
 * field too (`woocommerce_checkout_company_field = hidden`). Such a field is therefore
 * `required`, absent, and permanently empty — so the gate blocked checkout forever while
 * the SERVER happily accepted the very same order.
 *
 * That asymmetry is what the operator measured on the rig on 01.09.2026: every field
 * filled, a pickup point chosen, even the default `Free shipping` selected, and the button
 * still dead — but forcing it through the console produced a clean order.
 *
 * Issue #708 fixed the server half (`Checkout_Handler::validate()`). This file pins the
 * client half: the gate must not block on a field nobody rendered.
 */

const CONFIG_GLOBAL = 'woodev_checkout_field_config_test';
const ENDPOINT      = 'https://example.test/wp-json/woodev/v1/test/field-source';

/**
 * Classic-checkout markup carrying ONE real, rendered, fillable field.
 *
 * `billing_company` and `billing_address_2` are deliberately ABSENT from the DOM — that is
 * the whole point. WooCommerce hides both by default on this rig, and `inject()` never adds
 * them because they carry a `takeover_condition`.
 *
 * @returns {void}
 */
function installMarkup() {
	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<div class="woocommerce-billing-fields__field-wrapper">
				<p id="billing_country_field" class="form-row">
					<select id="billing_country" name="billing_country">
						<option value="RU" selected>Россия</option>
						<option value="BY">Беларусь</option>
					</select>
				</p>
				<p id="billing_city_field" class="form-row">
					<input type="text" id="billing_city" name="billing_city" value="Москва" />
				</p>
			</div>
			<div id="shipping_method"></div>
			<button type="submit" id="place_order"></button>
		</form>
	`;
}

/**
 * @param {Object} extraFields   Additional `fields` entries.
 * @param {Object} extraTakeover Additional `takeover` entries.
 * @param {Object} extraConfig   Additional top-level config keys (e.g. `block_place_order`).
 * @returns {Object}
 */
function buildConfig( extraFields, extraTakeover, extraConfig ) {
	return Object.assign(
		{
			endpoint: ENDPOINT,
			nonce:    'test-nonce',
			i18n:     { placeholder: 'Выберите…' },
			fields:   Object.assign(
				{ billing_city: { source_kind: 'suggest', required: true } },
				extraFields || {}
			),
			takeover: Object.assign( { billing_city: { RU: false, BY: false } }, extraTakeover || {} ),
		},
		extraConfig || {}
	);
}

/**
 * @returns {void}
 */
function boot( config ) {
	global.jQuery = require( 'jquery' );
	global.$      = global.jQuery;
	window.jQuery = global.jQuery;

	global.jQuery.ajax = jest.fn( function() {
		const chain = { done() { return chain; }, fail() { return chain; } };

		return chain;
	} );

	window.WoodevCheckoutFieldStore = require(
		'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
	);
	window[ CONFIG_GLOBAL ] = config;

	require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );
	jest.runAllTimers();
}

/**
 * @returns {boolean}
 */
function placeOrderDisabled() {
	return document.getElementById( 'place_order' ).disabled;
}

beforeEach( () => {
	jest.resetModules();
	jest.useFakeTimers();
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevCheckoutFieldStore;
	document.body.innerHTML = '';
} );

describe( 'A2 gate versus takeover fields (issue #721)', () => {

	it( 'does NOT block checkout on a required takeover field WooCommerce never rendered', () => {
		installMarkup();

		// The rig's exact shape: both are `set_required( true )` takeover fields, and both are
		// absent from the page because WooCommerce hides them.
		boot( buildConfig(
			{
				billing_company:   { source_kind: 'options', required: true },
				billing_address_2: { source_kind: 'suggest', required: true },
			},
			{
				// #294: the company demo takes over BY/KZ/UZ and deliberately NOT RU.
				billing_company:   { RU: false, BY: true },
				// The dependent-select demo DOES take over RU — which is why evaluating
				// ownership alone would still have left this one blocking.
				billing_address_2: { RU: true, BY: true },
			}
		) );

		expect( placeOrderDisabled() ).toBe( false );
	} );

	it( 'STILL blocks on a required field that is genuinely on the form and empty', () => {
		installMarkup();
		document.getElementById( 'billing_city' ).value = '';

		boot( buildConfig() );

		expect( placeOrderDisabled() ).toBe( true );
	} );

	it( 'blocks on a rendered takeover field the plugin really owns and the customer left empty', () => {
		installMarkup();

		// Present in the DOM this time — the client took it over and drew a control, so its
		// requiredness is real and enforceable.
		const wrapper = document.createElement( 'p' );

		wrapper.id        = 'billing_company_field';
		wrapper.className = 'form-row';
		wrapper.innerHTML = '<input type="text" id="billing_company" name="billing_company" value="" />';
		document.querySelector( '.woocommerce-billing-fields__field-wrapper' ).appendChild( wrapper );

		boot( buildConfig(
			{ billing_company: { source_kind: 'options', required: true } },
			{ billing_company: { RU: true, BY: true } }
		) );

		expect( placeOrderDisabled() ).toBe( true );
	} );

	it( 'does not block on a non-required takeover field that is absent', () => {
		installMarkup();

		boot( buildConfig(
			{ billing_company: { source_kind: 'options', required: false } },
			{ billing_company: { RU: true } }
		) );

		expect( placeOrderDisabled() ).toBe( false );
	} );
} );

/**
 * The «Блокировать оформление заказа» checkbox (issue #725) — `block_place_order` on
 * the checkout config. Default ON (absent key ≡ `true`) is already covered by every
 * test above, which never sets the key at all and still expects the gate to fire.
 */
describe( 'place-order gate on/off — «Блокировать оформление заказа» (issue #725)', () => {

	it( 'gates as before when the flag is explicitly on', () => {
		installMarkup();
		document.getElementById( 'billing_city' ).value = '';

		boot( buildConfig( {}, {}, { block_place_order: true } ) );

		expect( placeOrderDisabled() ).toBe( true );
	} );

	it( 'leaves #place_order alone when the flag is off, even for a change that would otherwise block it', () => {
		installMarkup();
		document.getElementById( 'billing_city' ).value = '';

		boot( buildConfig( {}, {}, { block_place_order: false } ) );

		expect( placeOrderDisabled() ).toBe( false );

		// A later change that WOULD block the button if the gate were on must still be
		// a pure no-op — the gate must not even re-evaluate required fields.
		global.jQuery( '#billing_city' ).val( '' ).trigger( 'change' );

		expect( placeOrderDisabled() ).toBe( false );
	} );

	it( 'releases a disabled button exactly once at boot, then never touches it again — not even to re-enable it', () => {
		installMarkup();

		// Simulates the button carrying a disabled state from before this run of the
		// gate (an earlier script instance, a restored page, etc.) — with the flag
		// off, this must be cleared exactly once at boot.
		document.getElementById( 'place_order' ).disabled = true;

		boot( buildConfig( {}, {}, { block_place_order: false } ) );

		expect( placeOrderDisabled() ).toBe( false );

		// Some OTHER plugin now legitimately disables the button (e.g. a payment
		// method still loading). The gate — off — must never touch it again, in
		// EITHER direction.
		document.getElementById( 'place_order' ).disabled = true;
		global.jQuery( '#billing_city' ).val( '' ).trigger( 'change' );

		expect( placeOrderDisabled() ).toBe( true );
	} );
} );
