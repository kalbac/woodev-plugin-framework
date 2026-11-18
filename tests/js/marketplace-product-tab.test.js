/**
 * The marketplace tab of the product screen: a confirmation armed on one button must not outlive
 * the shared result line it wrote its question to.
 */

const path = require( 'node:path' );

const TAB_SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/product-tab.js' );
const CONFIRM_MS = 5000;

window.woodevMarketplaceProduct = {
	texts: { working: 'Working…', failed: 'Failed' },
};
global.jQuery = require( 'jquery' );
global.$      = global.jQuery;
window.jQuery = global.jQuery;
window.ajaxurl = '/wp-admin/admin-ajax.php';

// The script attaches one delegated click handler to `document` when it loads: load it once, and
// reset only the markup and the mocked request between tests.
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
