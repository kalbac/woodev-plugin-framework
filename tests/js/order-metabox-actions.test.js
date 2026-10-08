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

/**
 * An action WITH input fields (#1180) — the button carries `data-fields` / `data-labels`; the script opens the
 * framework's modal shell with the form and posts the values as `payload[<id>]` beside the usual fields.
 */
describe( 'order-metabox-actions — an action with input fields', () => {
	const FIELDS = [
		{ id: 'day', type: 'date', label: 'День', required: true, default: '2026-10-13', min: '2026-10-12', max: '2026-10-26' },
		{ id: 'window', type: 'time_range', label: 'Время', required: true, default: { from: '09:00', to: '18:00' }, min: '09:00', max: '21:00' },
		{
			id: 'service',
			type: 'select',
			label: 'Забор',
			required: false,
			default: 'standard',
			options: [ { value: 'standard', label: 'Обычный' }, { value: 'express', label: 'Срочный' } ],
		},
		{ id: 'comment', type: 'textarea', label: 'Комментарий курьеру', required: false, default: '', maxlength: 200 },
	];
	const LABELS = { cancel: 'Отмена', close: 'Закрыть', from: 'с', to: 'до', none: '— не выбрано —', required: 'обязательное поле' };

	function renderWithFields() {
		document.body.innerHTML = `
			<form id="order" method="post" action="post.php">
				<div class="woodev-shipping-order-metabox"><p>
					<button
						type="button"
						class="button woodev-shipping-order-action"
						data-woodev-order-action="call_courier"
						data-post-url="https://example.test/wp-admin/admin-post.php"
						data-post-action="woodev_shipping_order_action"
						data-order-id="123"
						data-nonce="nonce-abc"
						data-fields='${ JSON.stringify( FIELDS ) }'
						data-labels='${ JSON.stringify( LABELS ) }'
					>Вызвать курьера</button>
				</p></div>
			</form>`;

		return document.querySelector( 'button[data-woodev-order-action]' );
	}

	function dialog() {
		return document.querySelector( '.woodev-modal__content' );
	}

	beforeEach( () => {
		window.WoodevModal = require( '../../woodev/assets/js/frontend/woodev-modal' );
	} );

	afterEach( () => {
		delete window.WoodevModal;
	} );

	it( 'opens the framework modal with the form instead of posting', () => {
		renderWithFields().click();

		expect( submitSpy ).not.toHaveBeenCalled();
		expect( dialog() ).not.toBeNull();
		expect( dialog().querySelector( '.woodev-modal__title' ).textContent ).toBe( 'Вызвать курьера' );
		expect( dialog().querySelector( '[name="payload[day]"]' ).value ).toBe( '2026-10-13' );
		expect( dialog().querySelector( '[name="payload[window][from]"]' ).value ).toBe( '09:00' );
		expect( dialog().querySelector( '[name="payload[window][to]"]' ).value ).toBe( '18:00' );
		expect( dialog().querySelector( '[name="payload[service]"]' ).value ).toBe( 'standard' );
		expect( dialog().querySelector( '[name="payload[comment]"]' ).maxLength ).toBe( 200 );
	} );

	it( 'puts the declared bounds on the inputs', () => {
		renderWithFields().click();

		const day = dialog().querySelector( '[name="payload[day]"]' );

		expect( day.min ).toBe( '2026-10-12' );
		expect( day.max ).toBe( '2026-10-26' );
		expect( day.required ).toBe( true );
		expect( dialog().querySelector( '[name="payload[window][from]"]' ).min ).toBe( '09:00' );
	} );

	it( 'posts the values as payload[...] beside the usual fields', () => {
		renderWithFields().click();

		dialog().querySelector( '[name="payload[day]"]' ).value = '2026-10-15';
		dialog().querySelector( '[name="payload[window][from]"]' ).value = '10:00';
		dialog().querySelector( '[name="payload[service]"]' ).value = 'express';
		dialog().querySelector( '[name="payload[comment]"]' ).value = 'Позвонить';
		dialog().querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );

		const form = submitSpy.mock.instances[ 0 ];

		expect( form.parentNode ).toBe( document.body );
		expect( fields( form ) ).toEqual( {
			_wpnonce: 'nonce-abc',
			action: 'woodev_shipping_order_action',
			order_id: '123',
			woodev_shipping_order_action: 'call_courier',
			'payload[day]': '2026-10-15',
			'payload[window][from]': '10:00',
			'payload[window][to]': '18:00',
			'payload[service]': 'express',
			'payload[comment]': 'Позвонить',
		} );
	} );

	it( 'posts nothing while a required field is empty', () => {
		renderWithFields().click();

		dialog().querySelector( '[name="payload[day]"]' ).value = '';
		dialog().querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );

		expect( submitSpy ).not.toHaveBeenCalled();
		expect( dialog() ).not.toBeNull();
	} );

	it( 'posts nothing for a window that ends before it starts', () => {
		renderWithFields().click();

		dialog().querySelector( '[name="payload[window][to]"]' ).value = '08:00';
		dialog().querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );

		expect( submitSpy ).not.toHaveBeenCalled();
	} );

	/**
	 * The real thing: the labels include `range`, the submit button is CLICKED (so the browser's own validation runs
	 * first and refuses a submit while a custom error stands), the window is in bounds but reversed.
	 */
	it( 'lets a corrected window through: the custom error is recomputed as the times are edited', () => {
		const button = renderWithFields();
		const labels = { ...LABELS, range: 'Время окончания должно быть позже времени начала.' };

		button.setAttribute( 'data-labels', JSON.stringify( labels ) );
		button.click();

		const from = dialog().querySelector( '[name="payload[window][from]"]' );
		const to = dialog().querySelector( '[name="payload[window][to]"]' );
		const submit = dialog().querySelector( 'button[type="submit"]' );
		const edit = ( input, value ) => {
			input.value = value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		};

		edit( from, '18:00' );
		edit( to, '10:00' );
		submit.click();

		expect( submitSpy ).not.toHaveBeenCalled();
		expect( to.validationMessage ).toBe( labels.range );

		edit( to, '20:00' );

		expect( to.validity.customError ).toBe( false );

		submit.click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
		expect( fields( submitSpy.mock.instances[ 0 ] ) ).toMatchObject( {
			'payload[window][from]': '18:00',
			'payload[window][to]': '20:00',
		} );

		submit.click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a reversed window is flagged as soon as it is typed, before any submit', () => {
		const button = renderWithFields();

		button.setAttribute( 'data-labels', JSON.stringify( { ...LABELS, range: 'Конец должен быть позже начала.' } ) );
		button.click();

		const to = dialog().querySelector( '[name="payload[window][to]"]' );

		to.value = '09:00'; // equals the default start: not later than it
		to.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( to.validationMessage ).toBe( 'Конец должен быть позже начала.' );
	} );

	it( 'closes on cancel without posting', () => {
		renderWithFields().click();

		const cancel = Array.from( dialog().querySelectorAll( 'button' ) ).find( ( b ) => 'Отмена' === b.textContent );

		cancel.click();

		expect( dialog() ).toBeNull();
		expect( submitSpy ).not.toHaveBeenCalled();
	} );

	it( 'draws every sentence from the button\'s data-labels, and text only as text', () => {
		const button = renderWithFields();
		const hostile = JSON.parse( button.getAttribute( 'data-fields' ) );

		hostile[ 0 ].label = '<img src=x onerror=alert(1)>';
		button.setAttribute( 'data-fields', JSON.stringify( hostile ) );
		button.click();

		expect( dialog().querySelector( 'img' ) ).toBeNull();
		expect( dialog().textContent ).toContain( '<img src=x onerror=alert(1)>' );
		expect( dialog().textContent ).toContain( 'Отмена' );
	} );

	it( 'an action WITHOUT fields still posts at once, even with the shell loaded', () => {
		renderScreen().click();

		expect( submitSpy ).toHaveBeenCalledTimes( 1 );
		expect( dialog() ).toBeNull();
	} );
} );
