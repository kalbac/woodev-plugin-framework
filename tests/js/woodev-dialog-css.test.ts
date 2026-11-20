/**
 * The shared dialog stylesheet keeps a dialog inside the screen: jsdom has no layout, so the rules that do it are read from the file.
 * A tall dialog (the account deletion preview at 1024x768) once ran past the Modal's percentage limit and hid its footer.
 *
 * @see woodev/marketplace/assets/css/admin/woodev-dialog.css
 */

import { readFileSync } from 'fs';
import { join } from 'path';

const css = readFileSync( join( __dirname, '../../woodev/marketplace/assets/css/admin/woodev-dialog.css' ), 'utf8' );

/** The declarations of the first rule whose selector is exactly `selector`. */
function rule( selector: string ): string {
	const start = css.indexOf( `${ selector } {` );
	expect( start ).toBeGreaterThan( -1 );

	return css.slice( start, css.indexOf( '}', start ) );
}

describe( 'the shared dialog stylesheet', () => {
	test( 'the React frame is limited to the viewport, so the page never hides the footer', () => {
		expect( rule( '.woodev-dialog.components-modal__frame' ) ).toMatch( /max-height:\s*calc\(100v?d?h - 64px\)/ );
	} );

	test( 'the content takes the frame limit and only the body scrolls, header and footer stay', () => {
		expect( rule( '.woodev-dialog .components-modal__content' ) ).toMatch( /max-height:\s*inherit/ );
		expect( rule( '.woodev-dialog .components-modal__content' ) ).toMatch( /overflow:\s*hidden/ );
		expect( rule( '.woodev-dialog__body' ) ).toMatch( /overflow-y:\s*auto/ );
		expect( rule( '.woodev-dialog__body' ) ).toMatch( /min-height:\s*0/ );
		expect( rule( '.woodev-dialog__header' ) ).toMatch( /flex:\s*0 0 auto/ );
		expect( rule( '.woodev-dialog__footer' ) ).toMatch( /flex:\s*0 0 auto/ );
	} );

	test( 'the native dialog is limited to the viewport as well', () => {
		expect( rule( 'dialog.woodev-dialog' ) ).toMatch( /max-height:\s*min\(680px, calc\(100vh - 64px\)\)/ );
	} );
} );
