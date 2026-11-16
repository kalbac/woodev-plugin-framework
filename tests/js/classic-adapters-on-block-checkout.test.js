/**
 * The classic adapters are INERT on a real block checkout (#1538).
 *
 * `Checkout_Handler` and `Pickup_Handler` now enqueue the classic scripts on every `is_checkout()`
 * page, also one whose content holds the Checkout block: a theme can draw the classic form over such
 * a page, and `wp_enqueue_scripts` runs before the body, so PHP cannot tell the two apart. That is
 * only safe if the scripts do nothing on the DOM a real block checkout renders — no `form.checkout`,
 * dashed field ids (`shipping-city`), no `#shipping_method`, no `#place_order`.
 *
 * Both scripts are booted here with their real configs against a block checkout's markup; the page
 * must come out byte-identical, with no request, no timer left running a mount, no console noise
 * (`@wordpress/jest-console` fails the test on any `console.error`/`warn`).
 *
 * @see woodev/shipping-method/assets/js/frontend/checkout-field-classic.js
 * @see woodev/shipping-method/assets/js/frontend/pickup-mount.js
 */

'use strict';

jest.useFakeTimers();

const FIELD_GLOBAL  = 'woodev_checkout_field_config_test';
const PICKUP_GLOBAL = 'woodev_pickup_config_test';
const PICKUP_FIELD  = 'carrier_pickup_point';

/**
 * The markup WooCommerce's Checkout block renders for a shipping address, a rate list and the
 * place-order button — dashed ids, radio names `radio-control-*`, no `form.checkout`.
 *
 * @returns {string}
 */
function blockCheckoutMarkup() {
	return `
		<div class="wp-block-woocommerce-checkout wc-block-checkout">
			<div class="wc-block-components-address-form">
				<select id="shipping-country"><option value="RU" selected>Россия</option></select>
				<input type="text" id="shipping-state" value="Москва" />
				<input type="text" id="shipping-city" value="Москва" />
				<input type="text" id="billing-city" value="" />
			</div>
			<div class="wc-block-components-shipping-rates-control">
				<input type="radio" name="radio-control-0" id="radio-control-0-carrier_pickup" value="carrier_pickup" checked />
				<label for="radio-control-0-carrier_pickup">ПВЗ</label>
			</div>
			<button type="button" class="wc-block-components-checkout-place-order-button" disabled>Оформить заказ</button>
		</div>
	`;
}

/**
 * The same page's configs as `Checkout_Handler` / `Pickup_Handler` localize them — fields with a
 * pickup slot and a city, exactly what would make the classic adapters act on a classic form.
 */
function installConfigs() {
	window[ FIELD_GLOBAL ] = {
		endpoint: 'https://example.test/wp-json/woodev/v1/test/field-source',
		nonce:    'test-nonce',
		i18n:     { placeholder: 'Выберите…' },
		fields:   {
			shipping_city:  { source_kind: 'suggest', required: true },
			[ PICKUP_FIELD ]: {
				id:             PICKUP_FIELD,
				is_pickup_slot: true,
				required:       { state: 'chosen_shipping_method', operator: 'in', value: [ 'carrier_pickup' ] },
			},
		},
		takeover: { shipping_city: { RU: true } },
	};

	window[ PICKUP_GLOBAL ] = {
		fieldId:        PICKUP_FIELD,
		replaceAddress: { billingOnly: false },
		i18n:           {},
	};
}

describe( 'classic adapters on a real block checkout (#1538)', () => {
	let before;
	let ajax;

	beforeAll( () => {
		jest.resetModules();

		document.body.innerHTML = blockCheckoutMarkup();
		before = document.body.innerHTML;

		global.jQuery = require( 'jquery' );
		global.$      = global.jQuery;
		window.jQuery = global.jQuery;

		ajax = jest.fn();
		global.jQuery.ajax = ajax;

		window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );
		require( '../../woodev/assets/js/frontend/woodev-modal' );
		installConfigs();

		require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );
		require( '../../woodev/shipping-method/assets/js/frontend/pickup-mount.js' );

		jest.runAllTimers();
	} );

	afterAll( () => {
		delete window[ FIELD_GLOBAL ];
		delete window[ PICKUP_GLOBAL ];
		delete window.WoodevCheckoutFieldStore;
		document.body.innerHTML = '';
	} );

	it( 'leaves the block checkout\'s DOM untouched after boot', () => {
		expect( document.body.innerHTML ).toBe( before );
		expect( document.querySelector( '[data-woodev-pickup-slot]' ) ).toBeNull();
		expect( document.querySelector( 'form.checkout' ) ).toBeNull();
	} );

	it( 'does not touch the block\'s place-order button', () => {
		const button = document.querySelector( '.wc-block-components-checkout-place-order-button' );

		expect( button.disabled ).toBe( true );
	} );

	it( 'stays inert through the events a block checkout fires', () => {
		const city = document.getElementById( 'shipping-city' );

		city.value = 'Казань';
		city.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		global.jQuery( city ).trigger( 'change' );
		global.jQuery( document.body ).trigger( 'updated_checkout' );
		document.body.dispatchEvent( new Event( 'updated_checkout', { bubbles: true } ) );

		jest.runAllTimers();

		expect( document.querySelector( '[data-woodev-pickup-slot]' ) ).toBeNull();
		expect( document.getElementById( 'shipping-city' ).value ).toBe( 'Казань' );
		expect( document.getElementById( 'shipping-city' ) ).toBe( city );
		expect( ajax ).not.toHaveBeenCalled();
		expect( window.WoodevPickupMount.getSession( PICKUP_FIELD ) ).toBeNull();
	} );
} );

/**
 * Order-pay is an `is_checkout()` page too (`/checkout/order-pay/<id>/`): the adapter loads there,
 * but WooCommerce renders `form#order_review` with its own `#place_order` and none of the checkout
 * fields. A required non-takeover field is absent and permanently empty, so the A2 gate used to
 * disable the PAYMENT button with nothing the buyer could fill in (#1538, critic round 1).
 */
describe( 'the field gate on checkout-less pages (#1538)', () => {
	const FIELD = 'delivery_note';

	const ORDER_PAY = `
		<form id="order_review" method="post">
			<table class="shop_table"><tbody></tbody></table>
			<div id="payment"><button type="submit" id="place_order">Оплатить заказ</button></div>
		</form>
	`;

	const CLASSIC_CHECKOUT = `
		<form class="checkout woocommerce-checkout">
			<p id="${ FIELD }_field"><input type="text" id="${ FIELD }" name="${ FIELD }" value="" /></p>
			<button type="submit" id="place_order">Оформить заказ</button>
		</form>
	`;

	/**
	 * Boots the adapter against `markup` with one REQUIRED, non-takeover managed field.
	 *
	 * @param {string} markup
	 * @param {Object} [extra] Extra config keys, e.g. `block_place_order`.
	 * @returns {void}
	 */
	function boot( markup, extra ) {
		jest.resetModules();
		document.body.innerHTML = markup;

		global.jQuery = require( 'jquery' );
		global.$      = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js' );
		window[ FIELD_GLOBAL ] = {
			endpoint: 'https://example.test/wp-json/woodev/v1/test/field-source',
			nonce:    'test-nonce',
			i18n:     { placeholder: 'Выберите…' },
			fields:   { [ FIELD ]: { id: FIELD, required: true } },
			takeover: {},
			...( extra || {} ),
		};

		require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );

		jest.runAllTimers();
	}

	afterEach( () => {
		delete window[ FIELD_GLOBAL ];
		delete window.WoodevCheckoutFieldStore;
		document.body.innerHTML = '';
	} );

	it( 'keeps the order-pay payment button enabled', () => {
		boot( ORDER_PAY );

		global.jQuery( document.body ).trigger( 'updated_checkout' );
		jest.runAllTimers();

		expect( document.getElementById( 'place_order' ).disabled ).toBe( false );
	} );

	it( 'does not release a payment button the page disabled, even with the gate switched off', () => {
		// `block_place_order: false` makes the gate release `#place_order` once on first run — fine on
		// a checkout, but on order-pay the button belongs to WooCommerce's payment form.
		document.body.innerHTML = ORDER_PAY;
		document.getElementById( 'place_order' ).disabled = true;

		boot( document.body.innerHTML, { block_place_order: false } );

		expect( document.getElementById( 'place_order' ).disabled ).toBe( true );
	} );

	it( 'survives the order-received page, which has no form and no button', () => {
		boot( '<section class="woocommerce-order"><p>Спасибо</p></section>' );

		expect( document.body.innerHTML ).toBe( '<section class="woocommerce-order"><p>Спасибо</p></section>' );
	} );

	it( 'still blocks the place-order button of a real classic checkout (the gate is intact)', () => {
		boot( CLASSIC_CHECKOUT );

		expect( document.getElementById( 'place_order' ).disabled ).toBe( true );
	} );
} );
