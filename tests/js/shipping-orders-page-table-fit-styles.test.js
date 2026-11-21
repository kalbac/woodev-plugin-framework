/**
 * Source-level guards for the «Заказы доставки» tables' alignment and fit (#1909).
 *
 * jsdom does not run the cascade, so these cannot prove the geometry — that was measured on the rig (Playwright, the compiled
 * stylesheet injected into the live pages, 1280 and 1024 px, the WP menu expanded and folded). What they pin are the decisions
 * a later edit would silently undo:
 *
 *  1. a sortable heading («Заказ», «Дата») pads exactly like the cells under it, and its button adds no left padding — otherwise
 *     the label stands 17px to the right of its values (the cell's 16px + the button's 12px against the cell's own padding);
 *  2. the cells pad 7px, the one value that keeps the orders table inside a 784px card (1024px window, menu expanded) even for
 *     a «Не доставлено» badge or four action icons;
 *  3. the analytics tables pad the same and let a heading wrap, so the nine-column carrier table is not 1195px wide.
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

describe( 'the orders table cells', () => {
	test( 'pad 7px through one variable', () => {
		expect( scss ).toContain( '$orders-cell-padding: 7px;' );

		const table = block( scss, '.woodev-orders .woocommerce-table__table {\n\tth,' );

		expect( table ).toMatch( /th,\s*td\s*\{[^}]*padding-left: \$orders-cell-padding;[^}]*padding-right: \$orders-cell-padding;/ );
	} );

	test( 'a sortable left-aligned heading pads like its cells and its button adds no left padding', () => {
		const table = block( scss, '.woodev-orders .woocommerce-table__table {\n\tth,' );
		const sortable = block( table, '.woocommerce-table__header.is-left-aligned.is-sortable' );

		expect( sortable ).toMatch( /^\s*padding-left: \$orders-cell-padding;/ );
		expect( block( sortable, '.components-button' ) ).toMatch( /padding-left: 0;/ );
	} );
} );

describe( 'the analytics tables', () => {
	const table = block( scss, '.woodev-analytics__table {' );

	test( 'pad like the orders table', () => {
		expect( block( table, '.woocommerce-table__header,' ) ).toMatch( /padding-left: \$orders-cell-padding;/ );
	} );

	test( 'let a heading wrap', () => {
		const header = block( table, '\t.woocommerce-table__header {' );

		expect( header ).toMatch( /white-space: normal;/ );
	} );
} );
