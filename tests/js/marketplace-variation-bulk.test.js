/**
 * The variation panel's marketplace bulk actions: the server answers with a marked comment, the script draws a
 * WooCommerce-style notice above the variations — and leaves every other bulk request alone.
 */

const path = require( 'node:path' );

const SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/variation-bulk.js' );
const PREFIX = 'woodev_fake_bulk_';

global.jQuery = require( 'jquery' );
global.$ = global.jQuery;
window.jQuery = global.jQuery;
window.woodevMarketplaceVariationBulk = [ { prefix: PREFIX, failed: 'The page did not answer.', dismiss: 'Dismiss this notice.' } ];

// The script attaches its handlers to `document` once, when it loads.
require( SCRIPT );

/**
 * Fires the global AJAX event WooCommerce's request would, the way jQuery does after `$.ajax()`.
 *
 * @param {string} event        `ajaxSuccess` or `ajaxError`.
 * @param {string} bulkAction   The `bulk_action` the request carried.
 * @param {string} responseText The response body.
 */
function fire( event, bulkAction, responseText ) {
	const data = 'action=woocommerce_bulk_edit_variations&security=x&product_id=7&bulk_action=' + bulkAction;

	$( document ).trigger( event, [ { responseText }, { data } ] );
}

function comment( notice ) {
	return '<!--woodev-bulk:' + JSON.stringify( notice ) + '-->';
}

beforeEach( () => {
	document.body.innerHTML = '<div id="variable_product_options"><div id="variable_product_options_inner"><div class="toolbar"></div></div></div>';
} );

describe( 'variation bulk notice', () => {
	test( 'a marketplace action draws the notice first in the variation panel, with the progress link', () => {
		fire(
			'ajaxSuccess',
			PREFIX + '1_export',
			comment( { tone: 'success', text: 'Принято в работу: 3 товара.', url: 'https://x.test/products', link: 'Открыть «Товары»' } )
		);

		const notice = document.querySelector( '#variable_product_options_inner > .woodev-mp-variation-notice' );

		expect( notice ).not.toBeNull();
		expect( notice.classList.contains( 'notice' ) ).toBe( true );
		expect( notice.classList.contains( 'inline' ) ).toBe( true );
		expect( notice.classList.contains( 'notice-info' ) ).toBe( true );
		expect( notice.classList.contains( 'is-dismissible' ) ).toBe( true );
		expect( notice.previousElementSibling ).toBeNull();
		expect( notice.querySelector( 'p' ).textContent ).toBe( 'Принято в работу: 3 товара. Открыть «Товары»' );
		expect( notice.querySelector( 'button.notice-dismiss .screen-reader-text' ).textContent ).toBe( 'Dismiss this notice.' );
		expect( notice.querySelector( 'a' ).getAttribute( 'href' ) ).toBe( 'https://x.test/products' );
	} );

	test( 'a second action replaces the first notice instead of stacking', () => {
		fire( 'ajaxSuccess', PREFIX + '1_export', comment( { tone: 'success', text: 'First', url: '', link: '' } ) );
		fire( 'ajaxSuccess', PREFIX + '1_detach', comment( { tone: 'warning', text: 'Second', url: '', link: '' } ) );

		const notices = document.querySelectorAll( '.woodev-mp-variation-notice' );

		expect( notices ).toHaveLength( 1 );
		expect( notices[ 0 ].querySelector( 'p' ).textContent ).toBe( 'Second' );
		expect( notices[ 0 ].classList.contains( 'notice-warning' ) ).toBe( true );
		expect( notices[ 0 ].querySelector( 'a' ) ).toBeNull();
	} );

	test( 'the text is plain text, never markup', () => {
		fire( 'ajaxSuccess', PREFIX + '1_export', comment( { tone: 'error', text: '<img src=x onerror=alert(1)>', url: '', link: '' } ) );

		const notice = document.querySelector( '.woodev-mp-variation-notice' );

		expect( notice.querySelector( 'img' ) ).toBeNull();
		expect( notice.querySelector( 'p' ).textContent ).toBe( '<img src=x onerror=alert(1)>' );
	} );

	test( 'an unknown tone is drawn as a warning', () => {
		fire( 'ajaxSuccess', PREFIX + '1_export', comment( { tone: 'bogus', text: 'Text', url: '', link: '' } ) );

		expect( document.querySelector( '.notice-warning' ) ).not.toBeNull();
	} );

	test( 'WooCommerce\'s own bulk actions and other requests draw nothing', () => {
		fire( 'ajaxSuccess', 'variable_regular_price', comment( { tone: 'success', text: 'No', url: '', link: '' } ) );
		fire( 'ajaxSuccess', 'woodev_other_bulk_1_export', comment( { tone: 'success', text: 'No', url: '', link: '' } ) );
		$( document ).trigger( 'ajaxSuccess', [ { responseText: comment( { text: 'No' } ) }, { data: 'action=something_else&bulk_action=' + PREFIX + '1_export' } ] );
		$( document ).trigger( 'ajaxSuccess', [ { responseText: '' }, { data: undefined } ] );

		expect( document.querySelector( '.woodev-mp-variation-notice' ) ).toBeNull();
	} );

	test( 'a response without the comment, or with a broken one, draws nothing', () => {
		fire( 'ajaxSuccess', PREFIX + '1_export', '' );
		fire( 'ajaxSuccess', PREFIX + '1_export', '<!--woodev-bulk:{broken-->' );

		expect( document.querySelector( '.woodev-mp-variation-notice' ) ).toBeNull();
	} );

	test( 'a request that failed says so, for our action only', () => {
		fire( 'ajaxError', 'variable_regular_price', '' );

		expect( document.querySelector( '.woodev-mp-variation-notice' ) ).toBeNull();

		fire( 'ajaxError', PREFIX + '1_export', '' );

		const notice = document.querySelector( '.woodev-mp-variation-notice' );

		expect( notice.classList.contains( 'notice-error' ) ).toBe( true );
		expect( notice.querySelector( 'p' ).textContent ).toBe( 'The page did not answer.' );
	} );

	test( 'the dismiss button removes the notice', () => {
		fire( 'ajaxSuccess', PREFIX + '1_export', comment( { tone: 'warning', text: 'Nothing changed', url: '', link: '' } ) );

		expect( document.querySelector( '.notice-warning' ) ).not.toBeNull();

		document.querySelector( '.woodev-mp-variation-notice .notice-dismiss' ).click();

		expect( document.querySelector( '.woodev-mp-variation-notice' ) ).toBeNull();
	} );
} );
