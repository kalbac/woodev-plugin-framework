/**
 * The seller action dialog of the order screen: it is drawn from the button's declaration, sends the typed fields as they are,
 * and lists every unit that was not done.
 */

const path = require( 'node:path' );

const SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/order-actions.js' );

const DIALOG = `
	<dialog id="woodev-mp-action-dialog" data-url="https://x.test/ajax" data-button=".woodev-mp-action" data-sending="Sending"
		data-loading="Loading" data-choose="Choose" data-close="Close" data-failed="No answer">
		<h2 id="woodev-mp-action-title"></h2>
		<p id="woodev-mp-action-text"></p>
		<div id="woodev-mp-action-fields"></div>
		<p id="woodev-mp-action-status"></p>
		<ul id="woodev-mp-action-lines"></ul>
		<button type="button" id="woodev-mp-action-close" data-label="Cancel">Cancel</button>
		<button type="button" id="woodev-mp-action-confirm"></button>
	</dialog>`;

const CONFIG = {
	id: 'marking',
	label: 'Marking code',
	confirmation: '',
	danger: false,
	fields: [ { id: 'sgtin', label: 'Code', type: 'code', required: true, help: '', options: [], source: false } ],
};

let sent;
let answers;

function button( config ) {
	const element = document.createElement( 'button' );

	element.className = 'woodev-mp-action';
	element.setAttribute( 'data-order-id', '7' );
	element.setAttribute( 'data-nonce', 'n' );
	element.setAttribute( 'data-run-action', 'run' );
	element.setAttribute( 'data-options-action', 'options' );
	element.setAttribute( 'data-unit', 'u-1' );
	element.setAttribute( 'data-config', JSON.stringify( config ) );
	document.body.appendChild( element );

	return element;
}

/** Lets the promises of the fake `fetch` settle. */
function settle() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

// The script finds its dialog and listens on `document` once, when it loads: one load serves every test, and opening the dialog
// resets it, as on the order screen.
beforeAll( () => {
	document.body.innerHTML = DIALOG;
	window.WoodevDialog = {
		attach: ( dialog ) => ( { open: () => dialog.setAttribute( 'open', '' ) } ),
	};
	global.fetch = jest.fn( ( url, options ) => {
		sent.push( Object.fromEntries( options.body.entries() ) );

		const answer = answers.shift();

		return Promise.resolve( { json: () => Promise.resolve( answer ) } );
	} );
	require( SCRIPT );
} );

beforeEach( () => {
	sent = [];
	answers = [];
	document.querySelectorAll( '.woodev-mp-action' ).forEach( ( element ) => element.remove() );
} );

describe( 'seller action dialog', () => {
	test( 'a scanned code is sent with its group separator when the scanner presses Enter', async () => {
		button( CONFIG ).click();

		const input = document.querySelector( '[data-field="sgtin"]' );

		input.value = '0104\u001d91AA';
		answers.push( { success: false, data: { message: 'Check the fields.', errors: { sgtin: 'No separator.' } } } );
		input.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );
		await settle();

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ] ).toEqual( {
			action: 'run',
			order_id: '7',
			nonce: 'n',
			seller_action: 'marking',
			unit: 'u-1',
			'fields[sgtin]': '0104\u001d91AA',
		} );
		expect( document.querySelector( '[data-error="sgtin"]' ).textContent ).toBe( 'No separator.' );
		expect( document.getElementById( 'woodev-mp-action-status' ).textContent ).toBe( 'Check the fields.' );
		expect( document.getElementById( 'woodev-mp-action-confirm' ).disabled ).toBe( false );
	} );

	test( 'every unit that was not done is listed as plain text, and the action cannot be sent again', async () => {
		button( CONFIG ).click();
		answers.push( { success: true, data: { message: 'Partly done', lines: [ 'Cap — <b>refused</b>' ], changed: true } } );
		document.getElementById( 'woodev-mp-action-confirm' ).click();
		await settle();

		const lines = document.querySelectorAll( '#woodev-mp-action-lines li' );

		expect( lines ).toHaveLength( 1 );
		expect( lines[ 0 ].textContent ).toBe( 'Cap — <b>refused</b>' );
		expect( lines[ 0 ].querySelector( 'b' ) ).toBeNull();
		expect( document.getElementById( 'woodev-mp-action-confirm' ).hidden ).toBe( true );
		expect( document.getElementById( 'woodev-mp-action-close' ).textContent ).toBe( 'Close' );
	} );

	test( 'a late answer of a closed dialog changes nothing in the dialog opened for another unit', async () => {
		const status = () => document.getElementById( 'woodev-mp-action-status' ).textContent;
		const confirm = document.getElementById( 'woodev-mp-action-confirm' );
		const open = ( unit ) => {
			const opener = button( CONFIG );

			opener.setAttribute( 'data-unit', unit );
			opener.click();
			document.querySelector( '[data-field="sgtin"]' ).value = unit + ' code';
		};
		const deferred = () => {
			let settleWith;
			let breakWith;
			const promise = new Promise( ( resolve, reject ) => {
				settleWith = resolve;
				breakWith = reject;
			} );

			answers.push( promise );

			return { answer: settleWith, fail: breakWith };
		};

		// Unit A is sent, the dialog is closed, unit B is opened and sent before A answers.
		open( 'a' );
		const first = deferred();
		confirm.click();
		open( 'b' );
		const second = deferred();
		confirm.click();

		first.answer( { success: false, data: { message: 'A refused', errors: { sgtin: 'A field error' } } } );
		await settle();

		expect( status() ).toBe( 'Sending' );
		expect( document.querySelector( '[data-error="sgtin"]' ).textContent ).toBe( '' );
		expect( confirm.disabled ).toBe( true );

		confirm.click();

		expect( sent.map( ( request ) => request.unit ) ).toEqual( [ 'a', 'b' ] );

		second.answer( { success: false, data: { message: 'B refused' } } );
		await settle();

		expect( status() ).toBe( 'B refused' );
		expect( confirm.disabled ).toBe( false );

		// A lost late answer and a late report say nothing either.
		open( 'c' );
		const lost = deferred();
		confirm.click();
		open( 'd' );
		const report = deferred();
		confirm.click();
		open( 'e' );

		lost.fail( new Error( 'offline' ) );
		report.answer( { success: true, data: { message: 'D partly done', lines: [ 'D line' ], changed: false } } );
		await settle();

		expect( status() ).toBe( '' );
		expect( document.querySelectorAll( '#woodev-mp-action-lines li' ) ).toHaveLength( 0 );
		expect( confirm.hidden ).toBe( false );
		expect( confirm.disabled ).toBe( false );
	} );

	test( 'a list whose choices come from the shop asks for them', async () => {
		answers.push( { success: true, data: { options: [ { value: 'WB-1', label: 'Batch 1' } ] } } );
		button( {
			...CONFIG,
			id: 'supply',
			fields: [ { id: 'batch', label: 'Batch', type: 'select', required: true, help: '', options: [], source: true } ],
		} ).click();
		await settle();

		expect( sent[ 0 ] ).toMatchObject( { action: 'options', seller_action: 'supply', field: 'batch' } );
		expect( Array.from( document.querySelectorAll( '[data-field="batch"] option' ), ( option ) => option.value ) ).toEqual( [ '', 'WB-1' ] );
	} );
} );
