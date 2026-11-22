/**
 * Source-level guards for the records tables' fit (#1918: Ozon Seller «Товары» and «Сопоставление» scrolled inside their card at a
 * 1024px window with the WordPress menu expanded: 883px in a 784px card, 772px in a 750px card).
 *
 * jsdom does not run the cascade, so these cannot prove the geometry — that was measured on the rig (Playwright, the compiled
 * stylesheet served in place of the rig's, 1280 and 1024px, the menu expanded and folded). What they pin are the decisions a later
 * edit would silently undo:
 *
 *  1. the cells pad 8px through one variable, like «Заказы доставки» (#1909);
 *  2. a sortable left-aligned heading's button pads nothing on EITHER side: its 12px right padding sat after the sort arrow of every
 *     sortable column;
 *  3. the article column is 14ch everywhere (the products table's own 19ch rule is gone), a price and a stock are 12ch, the name's
 *     floor is 12ch.
 *
 * @see src/admin-records/style.scss
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const scss = fs.readFileSync( path.join( __dirname, '../../src/admin-records/style.scss' ), 'utf8' );

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

describe( 'the records table cells', () => {
	test( 'pad 8px through one variable', () => {
		expect( scss ).toContain( '$cell-padding: 8px;' );

		const cells = block( scss, '.woocommerce-table__header,\n\t.woocommerce-table__item {' );

		expect( cells ).toMatch( /padding-left: \$cell-padding;[^}]*padding-right: \$cell-padding;/ );
	} );

	test( 'a sortable left-aligned heading pads like its cells and its button pads nothing on either side', () => {
		const sortable = block( scss, '.woocommerce-table__header.is-left-aligned.is-sortable {' );

		expect( sortable ).toMatch( /^\s*padding-left: \$cell-padding;/ );

		const button = block( sortable, '.components-button' );

		expect( button ).toMatch( /padding-left: 0;/ );
		expect( button ).toMatch( /padding-right: 0;/ );
	} );
} );

describe( 'the columns of the products and mappings tables', () => {
	test( 'the article is 14ch in every table, with no products-only widening', () => {
		const article = block( scss, '.woodev-records__col-sku,\n\t.woodev-records__col-offer_id {' );

		expect( article ).toMatch( /max-width: 14ch;\s*width: 14ch;/ );
		expect( scss ).not.toMatch( /\.woodev-records__col-sku\s*\{/ );
		expect( scss ).not.toContain( '19ch' );
	} );

	test( 'a price and a stock are 12ch', () => {
		const numbers = block( scss, '.woodev-records__col-price,\n\t.woodev-records__col-stock {' );

		expect( numbers ).toMatch( /width: 12ch;/ );
	} );

	test( 'the name column has a 12ch floor and no width', () => {
		const name = block( scss, '.woodev-records__col-name {\n\t\tmin-width' );

		expect( name ).toMatch( /min-width: 12ch;/ );
		expect( name ).not.toMatch( /(^|[^-])width: / );
	} );
} );

describe( 'the mappings page', () => {
	test( 'keeps a 13ch floor for the name link («сопоставление» in one line)', () => {
		const mappings = fs.readFileSync(
			path.join( __dirname, '../../src/marketplace-admin/views/mappings/style.scss' ),
			'utf8'
		);
		const name = block( mappings, '.woodev-records .woodev-records__col-name {' );

		expect( name ).toMatch( /min-width: 13ch;/ );
	} );
} );

describe( 'the records table footer', () => {
	test( 'a table of one page hides the whole card footer: size control, arrows and count', () => {
		const hidden = block( scss, '&__no-footer .components-card__footer {' );

		expect( hidden ).toMatch( /display:\s*none/ );
	} );
} );

describe( 'the busy-row bar (#1937)', () => {
	test( 'sits above the pinned cells (sticky, z-index 2), or it would start after them', () => {
		const shared = fs.readFileSync( path.join( __dirname, '../../src/components/_row-busy.scss' ), 'utf8' );
		const pinned = Number( /\.woodev-records__col-name \{[^}]*?\}/.test( scss ) && /position: sticky;\s*z-index: (\d+)/.exec( scss )[ 1 ] );
		const bar = Number( /^\s*z-index: (\d+);/m.exec( shared )[ 1 ] );

		expect( bar ).toBeGreaterThan( pinned );
	} );
} );
