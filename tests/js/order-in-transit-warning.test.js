/**
 * Tests for order-in-transit-warning.js — card #1204.
 *
 * PHP enqueues the script only for an order whose parcel is already on its way, with the sentence and the
 * status to watch in `window.woodevShippingInTransit`. When the manager picks that status in the order's
 * status select (jQuery announces a selectWoo pick, a native listener would never hear it) a non-blocking
 * inline warning appears under the select; any other status removes it. The form is never touched.
 *
 * @see woodev/shipping-method/assets/js/admin/order-in-transit-warning.js
 * @see woodev/shipping-method/admin/orders/class-orders-registry.php::enqueue_in_transit_warning()
 */

import $ from 'jquery';

global.jQuery = $;

const SCRIPT = '../../woodev/shipping-method/assets/js/admin/order-in-transit-warning.js';
const CONFIG = { status: 'wc-cancelled', message: 'Посылка уже в пути, отмена в магазине её не остановит.' };

function screen() {
	document.body.innerHTML =
		'<form id="order"><p class="form-field">' +
		'<select id="order_status" name="order_status">' +
		'<option value="wc-processing" selected>Processing</option>' +
		'<option value="wc-completed">Completed</option>' +
		'<option value="wc-cancelled">Cancelled</option>' +
		'</select></p></form>';
}

const warning = () => document.querySelector( '.woodev-in-transit-warning' );
const pick = ( value ) => $( '#order_status' ).val( value ).trigger( 'change' );

let mod;

beforeEach( () => {
	jest.resetModules();
	delete window.woodevShippingInTransit;
	mod = require( SCRIPT );
	screen();
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

test( 'picking the watched status shows the warning under the select', () => {
	expect( mod.init( CONFIG ) ).toBe( true );

	pick( 'wc-cancelled' );

	expect( warning() ).not.toBeNull();
	expect( warning().textContent ).toBe( CONFIG.message );
	expect( warning().parentNode ).toBe( document.querySelector( '.form-field' ) );
	expect( warning().className ).toContain( 'notice-warning' );
} );

test( 'picking another status takes the warning away again', () => {
	mod.init( CONFIG );

	pick( 'wc-cancelled' );
	pick( 'wc-completed' );

	expect( warning() ).toBeNull();
} );

test( 'picking the watched status twice leaves ONE warning', () => {
	mod.init( CONFIG );

	pick( 'wc-cancelled' );
	pick( 'wc-completed' );
	pick( 'wc-cancelled' );

	expect( document.querySelectorAll( '.woodev-in-transit-warning' ) ).toHaveLength( 1 );
} );

test( 'nothing is shown until the manager picks the status', () => {
	mod.init( CONFIG );

	expect( warning() ).toBeNull();
} );

test( 'the warning never blocks or alters the form', () => {
	const submit = jest.fn( ( event ) => event.preventDefault() );
	document.getElementById( 'order' ).addEventListener( 'submit', submit );
	mod.init( CONFIG );

	pick( 'wc-cancelled' );
	const event = new Event( 'submit', { cancelable: true } );
	document.getElementById( 'order' ).dispatchEvent( event );

	expect( submit ).toHaveBeenCalledTimes( 1 );
	expect( $( '#order_status' ).val() ).toBe( 'wc-cancelled' );
} );

test( 'without PHP-supplied settings, or without the status select, it does nothing', () => {
	expect( mod.init( undefined ) ).toBe( false );
	expect( mod.init( { status: 'wc-cancelled' } ) ).toBe( false );

	document.body.innerHTML = '';

	expect( mod.init( CONFIG ) ).toBe( false );
} );
