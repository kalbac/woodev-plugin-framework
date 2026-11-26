/**
 * The «Этикетка» button of the order screen: a label file is downloaded by the script itself, a page of stickers is offered as a
 * link the merchant opens.
 */

const path = require( 'node:path' );

const SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/order-label.js' );

const BUTTON = `
	<span class="woodev-mp-label-wrap">
		<button type="button" class="button woodev-mp-label" aria-disabled="false" data-url="https://x.test/records" data-account="3"
			data-row="3:700" data-nonce="n" data-preparing="Preparing" data-waiting="Waiting" data-failed="No answer"
			data-open="Open the stickers">Label</button>
		<span class="woodev-mp-label__status" role="status"></span>
	</span>`;

/** Lets the promises of the fake `fetch` settle. */
function settle() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * Loads the script over a fresh button whose first activity read says nothing is in the making.
 *
 * @param {Object} download The `download` of the label action's answer.
 */
async function click( download ) {
	document.body.innerHTML = BUTTON;
	global.fetch = jest.fn( ( url ) =>
		Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( url.endsWith( '/activity' ) ? { rows: [] } : { download } ),
		} )
	);

	jest.isolateModules( () => require( SCRIPT ) );
	await settle();

	document.querySelector( '.woodev-mp-label' ).click();
	await settle();
}

const status = () => document.querySelector( '.woodev-mp-label__status' );

describe( 'order label button', () => {
	let clicked;

	beforeEach( () => {
		clicked = [];
		jest.spyOn( window.HTMLAnchorElement.prototype, 'click' ).mockImplementation( function () {
			clicked.push( this.getAttribute( 'href' ) );
		} );
	} );

	afterEach( () => jest.restoreAllMocks() );

	test( 'a label file is downloaded at once', async () => {
		await click( { url: '/wp-admin/admin-post.php?document_id=1', filename: '', origins: [] } );

		expect( clicked ).toEqual( [ '/wp-admin/admin-post.php?document_id=1' ] );
		expect( status().querySelector( 'a' ) ).toBeNull();
	} );

	test( 'a page of stickers is offered as a link that opens in a new tab', async () => {
		await click( { url: '/wp-admin/admin-post.php?document_id=2', filename: '', origins: [], open: true } );

		const link = status().querySelector( 'a' );

		expect( clicked ).toEqual( [] );
		expect( link.getAttribute( 'href' ) ).toBe( '/wp-admin/admin-post.php?document_id=2' );
		expect( link.target ).toBe( '_blank' );
		expect( link.textContent ).toBe( 'Open the stickers' );
	} );

	test( 'a page on another site is not offered', async () => {
		await click( { url: 'https://evil.test/x', filename: '', origins: [ 'https://x.test' ], open: true } );

		expect( status().querySelector( 'a' ) ).toBeNull();
		expect( status().textContent ).toBe( 'No answer' );
	} );
} );
