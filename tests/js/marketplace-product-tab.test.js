/**
 * The marketplace tab of the product screen: a confirmation armed on one button must not outlive
 * the shared result line it wrote its question to.
 */

const path = require( 'node:path' );

const DIALOG_SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/woodev-dialog.js' );
const TAB_SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/product-tab.js' );
const CONFIRM_MS = 5000;

window.woodevMarketplaceProduct = {
	texts: { close: 'Close', working: 'Working…', failed: 'Failed' },
};
global.jQuery = require( 'jquery' );
global.$      = global.jQuery;
window.jQuery = global.jQuery;
window.ajaxurl = '/wp-admin/admin-ajax.php';

// The script attaches one delegated click handler to `document` when it loads: load it once, and
// reset only the markup and the mocked request between tests.
require( DIALOG_SCRIPT );
require( TAB_SCRIPT );

let request;

/**
 * Renders one account block: a destructive button that asks twice, a plain button and the shared result line.
 */
function renderBlock() {
	document.body.innerHTML = `
		<div class="woodev-mp-block">
			<button type="button" data-woodev-mp-op="detach" data-action="woodev_mp" data-nonce="n"
				data-product="7" data-account="2" data-confirm="Detach it? Click again.">Detach</button>
			<button type="button" data-woodev-mp-op="refresh" data-action="woodev_mp" data-nonce="n"
				data-product="7" data-account="2">Refresh</button>
			<span class="woodev-mp-result" role="status"></span>
		</div>`;

	return {
		detach:  document.querySelector( '[data-woodev-mp-op="detach"]' ),
		refresh: document.querySelector( '[data-woodev-mp-op="refresh"]' ),
		result:  document.querySelector( '.woodev-mp-result' ),
	};
}

beforeEach( () => {
	jest.useFakeTimers();
	request = global.jQuery.Deferred();
	global.jQuery.post = jest.fn( () => request );
} );

afterEach( () => {
	jest.clearAllTimers();
	jest.useRealTimers();
} );

describe( 'product tab confirmations', () => {
	test( 'a second click on the armed button runs the action', () => {
		const { detach, result } = renderBlock();

		detach.click();
		expect( result.textContent ).toBe( 'Detach it? Click again.' );
		expect( global.jQuery.post ).not.toHaveBeenCalled();

		detach.click();
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toMatchObject( { op: 'detach', product_id: 7, account_id: 2 } );
	} );

	test( 'the armed confirmation clears its own question when time runs out', () => {
		const { detach, result } = renderBlock();

		detach.click();
		jest.advanceTimersByTime( CONFIRM_MS );

		expect( result.textContent ).toBe( '' );
		expect( detach.classList.contains( 'is-armed' ) ).toBe( false );
	} );

	test( 'running another button disarms the first one and keeps its response on the line', () => {
		const { detach, refresh, result } = renderBlock();

		detach.click();
		expect( detach.classList.contains( 'is-armed' ) ).toBe( true );

		refresh.click();
		expect( detach.classList.contains( 'is-armed' ) ).toBe( false );
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ].op ).toBe( 'refresh' );

		request.resolve( { data: { message: 'Export queued' } } );
		expect( result.textContent ).toBe( 'Export queued' );

		// The first button's timer must not wipe the response that arrived after it was disarmed.
		jest.advanceTimersByTime( CONFIRM_MS );
		expect( result.textContent ).toBe( 'Export queued' );

		// Its question is gone, so the next click asks again instead of running the detach.
		detach.click();
		expect( result.textContent ).toBe( 'Detach it? Click again.' );
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a timer from an earlier prompt does not clear a later one', () => {
		const { detach, refresh, result } = renderBlock();

		detach.click();
		jest.advanceTimersByTime( 3000 );
		refresh.click();
		detach.click();

		// The first prompt would have expired at 5000 ms; the second one is still within its own window.
		jest.advanceTimersByTime( 2000 );
		expect( result.textContent ).toBe( 'Detach it? Click again.' );
		expect( detach.classList.contains( 'is-armed' ) ).toBe( true );

		jest.advanceTimersByTime( 3000 );
		expect( result.textContent ).toBe( '' );
		expect( detach.classList.contains( 'is-armed' ) ).toBe( false );
	} );
} );

describe( 'the dialogs of a block', () => {
	/**
	 * Renders a block with a badge that opens a dialog, and the «?» whose dialog holds the category-wide button.
	 */
	function renderDialogs() {
		document.body.innerHTML = `
			<div class="woodev-mp-block" data-account="2">
				<h3 class="woodev-mp-block__title">Fake
					<button type="button" class="woodev-mp-badge woodev-mp-badge--problem" data-woodev-mp-dialog>
						<span class="woodev-mp-badge__label">Created</span></button><template data-title="Check"><p>No weight</p></template>
				</h3>
				<button type="button" class="woodev-mp-help" data-woodev-mp-dialog>?</button>
				<template data-title="Hints"><p>Hint</p>
					<button type="button" class="button-link woodev-mp-link-op" data-woodev-mp-op="link" data-action="woodev_mp"
						data-nonce="n" data-product="7" data-account="2">Link by SKU</button></template>
				<p class="woodev-mp-buttons"><span class="woodev-mp-result" role="status"></span></p>
			</div>`;

		return {
			badge: document.querySelector( '.woodev-mp-badge' ),
			help: document.querySelector( '.woodev-mp-help' ),
		};
	}

	afterEach( () => {
		document.querySelectorAll( 'dialog' ).forEach( ( element ) => element.remove() );
	} );

	test( 'the badge opens one dialog with its own title and content', () => {
		const { badge } = renderDialogs();

		badge.click();

		const dialog = document.querySelector( 'dialog.woodev-dialog' );
		expect( dialog ).not.toBeNull();
		expect( dialog.hasAttribute( 'open' ) ).toBe( true );
		expect( dialog.querySelector( '.woodev-dialog__title' ).textContent ).toBe( 'Check' );
		expect( dialog.querySelector( '.woodev-dialog__body' ).textContent ).toBe( 'No weight' );
		expect( dialog.querySelector( '.woodev-dialog__header .woodev-dialog__close' ).getAttribute( 'aria-label' ) ).toBe( 'Close' );
		expect( dialog.querySelector( '.woodev-dialog__footer button' ).textContent ).toBe( 'Close' );
	} );

	test( 'the «?» reuses the same dialog and replaces its content', () => {
		const { badge, help } = renderDialogs();

		badge.click();
		help.click();

		expect( document.querySelectorAll( 'dialog' ).length ).toBe( 1 );
		expect( document.querySelector( '.woodev-dialog__title' ).textContent ).toBe( 'Hints' );
		expect( document.querySelector( '.woodev-dialog__body' ).textContent ).toContain( 'Hint' );
		expect( document.querySelector( '.woodev-dialog__body' ).textContent ).not.toContain( 'No weight' );
	} );

	test( 'a badge in a variation header opens the dialog without the header\'s own click handler running', () => {
		document.body.innerHTML = `
			<div class="woocommerce_variation"><h3 id="header">
				<button type="button" class="woodev-mp-badge" data-woodev-mp-dialog>State</button><template data-title="Check"><p>No weight</p></template>
			</h3></div>`;
		const toggled = jest.fn();
		document.getElementById( 'header' ).addEventListener( 'click', toggled );

		document.querySelector( '.woodev-mp-badge' ).click();

		expect( toggled ).not.toHaveBeenCalled();
		expect( document.querySelector( 'dialog .woodev-dialog__body' ).textContent ).toBe( 'No weight' );
	} );

	test( 'the «✕» of the header and the button of the footer both close the dialog', () => {
		const { badge } = renderDialogs();

		badge.click();
		document.querySelector( '.woodev-dialog__header .woodev-dialog__close' ).click();
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );

		badge.click();
		document.querySelector( '.woodev-dialog__footer button' ).click();
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );
	} );

	test( 'the category-wide «Связать по артикулу» button of the help dialog posts its own op and answers on the block\'s result line', () => {
		const { help } = renderDialogs();

		help.click();
		document.querySelector( 'dialog .woodev-mp-link-op' ).click();

		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toMatchObject( { op: 'link', product_id: 7, account_id: 2 } );
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );
		request.resolve( { data: { message: 'Linked 3' } } );
		expect( document.querySelector( '.woodev-mp-result' ).textContent ).toBe( 'Linked 3' );
	} );

	test( 'a new state keeps the orange of a badge that carries problems and changes its name', () => {
		const { detach, result } = renderBlock();
		const block = detach.closest( '.woodev-mp-block' );

		block.insertAdjacentHTML( 'afterbegin', '<h3 class="woodev-mp-block__title"><span class="woodev-mp-badge woodev-mp-badge--problem"><span class="woodev-mp-badge__label">Old</span></span></h3>' );
		detach.click();
		detach.click();
		request.resolve( { data: { message: 'Done', badge: { label: 'New', tone: 'ok' } } } );

		const badge = block.querySelector( '.woodev-mp-badge' );
		expect( result.textContent ).toBe( 'Done' );
		expect( badge.textContent ).toBe( 'New' );
		expect( badge.classList.contains( 'woodev-mp-badge--problem' ) ).toBe( true );
		expect( badge.classList.contains( 'woodev-mp-badge--ok' ) ).toBe( false );
	} );
} );

describe( 'the article line', () => {
	test( '«изменить» gives way to the input and focuses it', () => {
		document.body.innerHTML = `
			<span class="woodev-mp-article"><span>SKU-1</span>
				<button type="button" class="button-link woodev-mp-article__edit" aria-controls="art-1">edit</button></span>
			<input type="text" id="art-1" hidden />`;

		document.querySelector( '.woodev-mp-article__edit' ).click();

		expect( document.querySelector( '.woodev-mp-article' ).hidden ).toBe( true );
		expect( document.getElementById( 'art-1' ).hidden ).toBe( false );
		expect( document.activeElement ).toBe( document.getElementById( 'art-1' ) );
	} );
} );

describe( 'the account select', () => {
	beforeEach( () => {
		jest.useRealTimers();
	} );

	/**
	 * Loads the script afresh over a page that has the select (it sets itself up when the page is ready).
	 *
	 * @param {string|null} stored Account id kept from an earlier visit.
	 */
	async function loadWithSelect( stored ) {
		document.body.innerHTML = `
			<div id="woocommerce-product-data"><h2 class="hndle"><span>Product data</span></h2>
				<ul class="wc-tabs"><li class="general"><a href="#g">General</a></li><li class="woodev-mp-tab-link"><a href="#o">Ozon</a></li></ul>
				<div class="woodev-mp-tab">
					<div class="woodev-mp-account-switch" data-remember="woodev_fake_account">
						<select><option value="2">One</option><option value="4">Two</option></select></div>
					<div class="woodev-mp-block" data-account="2"></div>
					<div class="woodev-mp-block" data-account="4" hidden></div>
				</div>
			</div>`;

		window.localStorage.clear();
		if ( null !== stored ) {
			window.localStorage.setItem( 'woodev_mp_woodev_fake_account', stored );
		}

		jest.isolateModules( () => {
			require( TAB_SCRIPT );
		} );

		// jQuery runs a ready callback on a timer, and the script waits one more tick after it.
		return new Promise( ( resolve ) => window.setTimeout( resolve, 30 ) );
	}

	test( 'moves into the header, starts on the first account and shows only its block', async () => {
		await loadWithSelect( null );

		const header = document.querySelector( '#woocommerce-product-data .hndle' );
		expect( header.querySelector( '.woodev-mp-account-switch' ) ).not.toBeNull();
		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( false );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( true );
	} );

	test( 'starts on the account used last in this browser', async () => {
		await loadWithSelect( '4' );

		expect( document.querySelector( '.woodev-mp-account-switch select' ).value ).toBe( '4' );
		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( true );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( false );
	} );

	test( 'ignores a remembered account that is gone', async () => {
		await loadWithSelect( '99' );

		expect( document.querySelector( '.woodev-mp-account-switch select' ).value ).toBe( '2' );
	} );

	test( 'picking another account swaps the block and remembers the choice', async () => {
		await loadWithSelect( null );

		const select = document.querySelector( '.woodev-mp-account-switch select' );
		select.value = '4';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( true );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( false );
		expect( window.localStorage.getItem( 'woodev_mp_woodev_fake_account' ) ).toBe( '4' );
	} );

	test( 'is shown only while the marketplace tab is the open one', async () => {
		await loadWithSelect( null );

		const wrap = document.querySelector( '.woodev-mp-account-switch' );
		expect( wrap.hidden ).toBe( true );

		const link = document.querySelector( '.woodev-mp-tab-link' );
		link.classList.add( 'active' );
		link.querySelector( 'a' ).click();
		await new Promise( ( resolve ) => window.setTimeout( resolve, 30 ) );

		expect( wrap.hidden ).toBe( false );
	} );
} );
