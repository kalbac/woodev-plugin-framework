/**
 * The cancel window of the order screen: it shows the server's sentence, and the HTTP status of an answer that was replaced
 * on the way (#2065).
 */

const path = require( 'node:path' );

const SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/order-cancel.js' );

const DIALOG = `
	<dialog id="woodev-mp-cancel-dialog" data-url="https://x.test/ajax" data-button=".woodev-mp-cancel" data-reasonless="0"
		data-loading="Loading" data-sending="Sending" data-failed="No answer" data-foreign="Replaced (HTTP %d)">
		<select id="woodev-mp-cancel-reason"></select>
		<p id="woodev-mp-cancel-message-row"><textarea id="woodev-mp-cancel-message"></textarea></p>
		<p id="woodev-mp-cancel-status"></p>
		<button type="button" id="woodev-mp-cancel-confirm"></button>
	</dialog>
	<button type="button" class="woodev-mp-cancel" data-order-id="7" data-nonce="n" data-reasons-action="reasons"
		data-cancel-action="cancel">Cancel</button>`;

/** Lets the promises of the fake `fetch` settle. */
function settle() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

const status = () => document.getElementById( 'woodev-mp-cancel-status' ).textContent;

beforeAll( () => {
	document.body.innerHTML = DIALOG;
	window.WoodevDialog = {
		attach: ( dialog ) => ( { open: () => dialog.setAttribute( 'open', '' ) } ),
	};
	global.fetch = jest.fn();
	require( SCRIPT );
} );

/** Opens the window and answers the read of the reasons. */
async function open( answer ) {
	global.fetch.mockImplementationOnce( () => Promise.resolve( answer ) );
	document.querySelector( '.woodev-mp-cancel' ).click();
	await settle();
}

describe( 'cancel window', () => {
	test( 'shows the sentence of a refusal', async () => {
		await open( { status: 422, json: () => Promise.resolve( { success: false, data: { message: 'Already shipped.' } } ) } );

		expect( status() ).toBe( 'Already shipped.' );
	} );

	test.each( [
		[ 'a page of its own', 502, () => Promise.reject( new SyntaxError( 'Unexpected token <' ) ) ],
		[ 'a JSON body without a message', 503, () => Promise.resolve( { error: 'blocked' } ) ],
		[ 'a bare WordPress refusal', 400, () => Promise.resolve( 0 ) ],
	] )( 'names the status of an answer replaced by %s', async ( name, code, json ) => {
		await open( { status: code, json } );

		expect( status() ).toBe( `Replaced (HTTP ${ code })` );
	} );

	test( 'a request that got no answer keeps the connection sentence', async () => {
		global.fetch.mockImplementationOnce( () => Promise.reject( new TypeError( 'Failed to fetch' ) ) );
		document.querySelector( '.woodev-mp-cancel' ).click();
		await settle();

		expect( status() ).toBe( 'No answer' );
	} );
} );
