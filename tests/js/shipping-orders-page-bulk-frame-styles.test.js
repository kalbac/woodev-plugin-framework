/**
 * Source-level guards for the bulk «Применить действие» button's frame (#1192 round, operator's rig finding 09.10.2026).
 *
 * jsdom does not run the cascade, so these cannot prove the frame is closed — that was measured on the rig
 * (Playwright, the compiled stylesheet injected into the live orders page, `getComputedStyle()` plus a 4x clip of the
 * group). What they pin are the three decisions a later edit would silently undo:
 *
 *  1. an ENABLED button's left side takes the frame colour — the grey divider alone replaced the left side of the blue
 *     frame, which is the whole defect (blue top/right/bottom, grey left);
 *  2. the button's outer corners follow the group's INNER curve — the group clips with `overflow: hidden`, and square
 *     corners had their frame cut where the curve passes;
 *  3. focus is drawn INSIDE the button — WordPress draws it as an outside ring, which the same clip halved.
 *
 * The disabled look keeps its grey divider: the colour rule is guarded by `:not( :disabled … )`.
 *
 * @see src/shipping-orders-page/style.scss
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const scss = fs.readFileSync( path.join( __dirname, '../../src/shipping-orders-page/style.scss' ), 'utf8' );

/** The body of the first block whose opening line contains `needle`, braces balanced. */
function block( source, needle ) {
	const start = source.indexOf( needle );

	expect( start ).toBeGreaterThanOrEqual( 0 );

	const open = source.indexOf( '{', start );
	let depth = 0;

	for ( let i = open; i < source.length; i++ ) {
		depth += '{' === source[ i ] ? 1 : '}' === source[ i ] ? -1 : 0;

		if ( 0 === depth ) {
			return source.slice( open + 1, i );
		}
	}

	throw new Error( 'unbalanced block for ' + needle );
}

const group = block( scss, '.woodev-orders-bulk {' );
const apply = block( group, '&__apply.components-button' );

describe( 'the bulk apply button frame', () => {
	test( 'stays ONE group: the group owns the outer frame and clips its children', () => {
		expect( group ).toMatch( /border:\s*1px solid/ );
		expect( group ).toMatch( /overflow:\s*hidden/ );
	} );

	test( 'keeps the grey divider by default (the disabled look)', () => {
		expect( apply ).toMatch( /^\s*(?:\/\/.*\n\s*)*border-left:\s*1px solid wd\.\$border;/m );
	} );

	test( 'an enabled button closes its frame: the left side takes the frame colour', () => {
		const enabled = block( apply, "&:not( :disabled, [aria-disabled='true'] )" );

		expect( enabled ).toMatch( /border-left-color:\s*currentColor;/ );
	} );

	test( 'the outer corners follow the group\'s inner curve instead of being square', () => {
		expect( apply ).toMatch( /border-radius:\s*0 calc\( #\{ wd\.\$radius-sm \} - 1px \) calc\( #\{ wd\.\$radius-sm \} - 1px \) 0;/ );
		expect( apply ).not.toMatch( /border-radius:\s*0;/ );
	} );

	test( 'the focus ring is drawn inside the button, where the group\'s clip cannot cut it', () => {
		const focus = block( apply, '&:focus-visible' );

		expect( focus ).toMatch( /box-shadow:\s*inset 0 0 0/ );
	} );
} );
