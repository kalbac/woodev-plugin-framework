/**
 * Source-level guards for the order-edit metabox's stylesheet rules (s164).
 *
 * jsdom does not run the cascade, so these cannot prove a layout — the numbers came off the rig
 * (`getComputedStyle()` on the real order screen: the form was `display: block` before the fix, `flex` after;
 * the table was 305px wide in a 254px box before, equal after). What they CAN pin is the three decisions that
 * a later edit would silently undo, each of which cost a rig round:
 *
 *  1. the action form carries a `.woodev-modal …` selector, because `woodev-modal.css` resets `div`/`form` to
 *     `display: block` at (0,1,0) and loads AFTER this sheet;
 *  2. the metabox table is `table-layout: fixed` and its cells and badge may break (a uuid is one 36-char word);
 *  3. the destructive button is drawn from the UI-kit error token, not a hand-typed hex.
 *
 * @see src/shipping-orders-page/style.scss
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const scss = fs.readFileSync( path.join( __dirname, '../../src/shipping-orders-page/style.scss' ), 'utf8' );

/** The body of the first rule whose selector line contains `needle`, braces balanced. */
function ruleBody( needle ) {
	const start = scss.indexOf( needle );

	expect( start ).toBeGreaterThanOrEqual( 0 );

	const open = scss.indexOf( '{', start );
	let depth = 0;

	for ( let i = open; i < scss.length; i++ ) {
		depth += '{' === scss[ i ] ? 1 : '}' === scss[ i ] ? -1 : 0;

		if ( 0 === depth ) {
			return scss.slice( open + 1, i );
		}
	}

	throw new Error( 'unbalanced braces after ' + needle );
}

describe( 'order metabox stylesheet', () => {
	it( 'scopes the action form under .woodev-modal so the shell\'s display:block reset cannot beat it', () => {
		expect( scss ).toMatch( /\.woodev-action-form,[^{]*\.woodev-modal \.woodev-action-form \{\s*display: flex;/s );
	} );

	it( 'gives the form its own padding inside the shell, which has none', () => {
		expect( ruleBody( '.woodev-modal .woodev-action-form {\n\tpadding' ) ).toMatch( /padding: wd\.\$gap-lg/ );
	} );

	it( 'fixes the details table layout and lets every cell and the badge wrap', () => {
		const metabox = ruleBody( '.woodev-shipping-order-metabox {' );

		expect( metabox ).toMatch( /table-layout: fixed/ );
		expect( metabox ).toMatch( /overflow-wrap: anywhere/ );
		expect( metabox ).toMatch( /white-space: normal/ );
	} );

	it( 'keeps the action group on one row and never wraps it', () => {
		expect( ruleBody( '.woodev-shipping-order-actions-buttons {' ) ).toMatch( /flex-wrap: nowrap/ );
	} );

	it( 'draws a destructive action from the error tokens, on both surfaces', () => {
		const metabox = ruleBody( '.button.woodev-shipping-order-action--destructive:not( :disabled ) {' );

		expect( metabox ).toMatch( /rgba\( wd\.\$error, 0\.12 \)/ );
		expect( metabox ).toMatch( /color: wd\.\$error-text/ );
		expect( ruleBody( '&.woodev-orders-actions__button--stop {' ) ).toMatch( /rgba\( wd\.\$error, 0\.12 \)/ );
	} );
} );
