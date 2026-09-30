/**
 * Tests for order-metabox-actions.js — card #1012.
 *
 * The carrier metabox sits INSIDE WooCommerce's order form, so its action buttons may not be
 * forms of their own (a nested `</form>` closed the outer order form and the status select was
 * never submitted). The script turns a click on `button[data-woodev-order-action]` into a POST
 * from a detached form on `document.body`. These tests pin: the form is built OUTSIDE the order
 * form, it carries exactly the fields `handle_order_action()` reads, a destructive action's
 * confirm is respected, and a disabled button / a second click never submits.
 *
 * @see woodev/shipping-method/assets/js/admin/order-metabox-actions.js
 * @see woodev/shipping-method/admin/views/html-admin-order-metabox.php
 */

'use strict';

const SCRIPT = '../../woodev/shipping-method/assets/js/admin/order-metabox-actions.js';

let registeredListener;
let submitSpy;
let confirmSpy;
let mod;

function loadModule() {
	jest.resetModules();

	const original = document.addEventListener.bind( document );
	jest.spyOn( document, 'addEventListener' ).mockImplementation( ( type, listener, options ) => {
		if ( type === 'click' ) {
			registeredListener = listener;
		}
		return original( type, listener, options );
	} );

	mod = require( SCRIPT );
}

/** The markup the PHP view renders, wrapped in a stand-in for WooCommerce's order form. */
function renderScreen( { destructive = false, disabled = false } = {} ) {
	document.body.innerHTML = `
		<form id="order" method="post" action="post.php">
			<select id="order_status" name="order_status"><option value="wc-processing">Processing</option></select>
			<div class="woodev-shipping-order-metabox">
				<p>
					<button
						type="button"
						class="button woodev-shipping-order-action"
						data-woodev-order-action="${ destructive ? 'cancel' : 'export' }"
						data-post-url="https://example.test/wp-admin/admin-post.php"
						data-post-action="woodev_shipping_order_action"
						data-order-id="123"
						data-nonce="nonce-abc"
						${ destructive ? 'data-confirm="Вы уверены?"' : '' }
						${ disabled ? 'disabled' : '' }
					><span class="label">Label</span></button>
				</p>
			</div>
		</form>`;

	return document.querySelector( 'button[data-woodev-order-action]' );
}

function fields( form ) {
	return Object.fromEntries( Array.from( form.querySelectorAll( 'input' ) ).map( ( i ) => [ i.name, i.value ] ) );
}

beforeEach( () => {
	submitSpy = jest.spyOn( HTMLFormElement.prototype, 'submit' ).mockImplementation( () => {} );
	confirmSpy = jest.spyOn( window, 'confirm' ).mockReturnValue( true );
	loadModule();
} );

afterEach( () => {
	document.removeEventListener( 'click', registeredListener );
	jest.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'order-metabox-actions', () => {
	it( 'submits a detached form that lives OUTSIDE the order form', () => {
		const button = renderScreen();

		button.click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );

		const form = submitSpy.mock.instances[ 0 ];
		expect( form.parentNode ).toBe( document.body );
		expect( document.getElementById( 'order' ).contains( form ) ).toBe( false );
		expect( document.querySelectorAll( '#order form' ) ).toHaveLength( 0 );
	} );

	it( 'posts to admin-post with exactly the fields handle_order_action() reads', () => {
		renderScreen().click();

		const form = submitSpy.mock.instances[ 0 ];

		expect( form.method ).toBe( 'post' );
		expect( form.getAttribute( 'action' ) ).toBe( 'https://example.test/wp-admin/admin-post.php' );
		expect( fields( form ) ).toEqual( {
			_wpnonce: 'nonce-abc',
			action: 'woodev_shipping_order_action',
			order_id: '123',
			woodev_shipping_order_action: 'export',
		} );
	} );

	it( 'acts when the click lands on a child element of the button', () => {
		renderScreen().querySelector( '.label' ).click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks nothing for a non-destructive action', () => {
		renderScreen().click();

		expect( confirmSpy ).not.toHaveBeenCalled();
	} );

	it( 'asks the data-confirm question for a destructive action and submits on OK', () => {
		renderScreen( { destructive: true } ).click();

		expect( confirmSpy ).toHaveBeenCalledWith( 'Вы уверены?' );
		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
		expect( fields( submitSpy.mock.instances[ 0 ] ).woodev_shipping_order_action ).toBe( 'cancel' );
	} );

	it( 'submits nothing when the merchant cancels the confirm', () => {
		confirmSpy.mockReturnValue( false );

		renderScreen( { destructive: true } ).click();

		expect( submitSpy ).not.toHaveBeenCalled();
		expect( document.querySelectorAll( 'body > form:not(#order)' ) ).toHaveLength( 0 );
	} );

	it( 'never submits a disabled button (an order another manager is editing)', () => {
		const button = renderScreen( { disabled: true } );

		// A real disabled button swallows the click itself; call the handler directly too, so the
		// script's own guard is what is proven, not the browser's.
		button.click();
		mod.onClick( { target: button, preventDefault() {} } );

		expect( submitSpy ).not.toHaveBeenCalled();
		expect( document.querySelectorAll( 'body > form:not(#order)' ) ).toHaveLength( 0 );
	} );

	it( 'ignores a click on anything that is not an action button', () => {
		renderScreen();

		document.getElementById( 'order_status' ).click();
		document.body.click();

		expect( submitSpy ).not.toHaveBeenCalled();
	} );

	it( 'ignores a second click while the first POST is in flight', () => {
		const button = renderScreen();

		button.click();
		button.click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
	} );
} );
