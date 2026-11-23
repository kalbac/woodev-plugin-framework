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
 *     floor is 10ch (#1945: 12ch, plus the variations' 28px expand column, made «Товары сайта» 813px in the 784px card).
 *  4. what makes a column rigid is allowed to wrap (#1945, #1930): a badge breaks at its spaces, a date breaks from its time, the buyer
 *     breaks anywhere, and a right-aligned sortable heading pads nothing around its button.
 *  5. a table that still scrolls draws its bar: the standard `scrollbar-color` switches `::-webkit-scrollbar` off, and on macOS the
 *     standard bar is the overlay one, hidden until the table is touched (#1930).
 *
 * Measured on the rig (Playwright against :8973, the page's marketplace stylesheet and bundle replaced by the compiled worktree ones,
 * 1024px, the WP menu expanded; the table's own scrollWidth, then its minimum content width in a 200px card): «Товары сайта» 813 → 784
 * (minimum 754), «Заказы маркетплейсов» of the second account 855 → 784 (minimum 740), «Карточки Ozon» 784/784 (minimum 699–741),
 * «Сопоставление» 750/750 (unchanged).
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

	test( 'the name column has a 10ch floor and no width', () => {
		const name = block( scss, '.woodev-records__col-name {\n\t\tmin-width' );

		expect( name ).toMatch( /min-width: 10ch;/ );
		expect( name ).not.toMatch( /(^|[^-])width: / );
	} );
} );

describe( 'what makes a column rigid may wrap (#1945, #1930)', () => {
	test( 'a badge breaks at its spaces instead of fixing its column at the full label', () => {
		const badge = block( scss, '.woodev-records__badge {' );

		expect( badge ).toMatch( /white-space: normal;/ );
		expect( badge ).not.toMatch( /white-space: nowrap/ );
		// The delivery page's 2.5em badge stays 2.5em tall on one line.
		expect( badge ).toMatch( /min-height: 2\.5em;/ );
	} );

	test( 'the date column is 10ch and not nowrap (the date and its time are the nowrap halves)', () => {
		const nowrap = block( scss, '.woodev-records__col-order,' );

		expect( nowrap ).toMatch( /white-space: nowrap/ );
		expect( scss.slice( 0, scss.indexOf( '.woodev-records__col-order {' ) ) ).not.toContain( 'woodev-records__col-date' );
		expect( block( scss, '.woodev-records__col-date {' ) ).toMatch( /width: 10ch;/ );
	} );

	test( 'the buyer breaks anywhere, like the delivery page\'s text columns', () => {
		expect( block( scss, '.woodev-records__col-customer {' ) ).toMatch( /overflow-wrap: anywhere;/ );
	} );

	test( 'a right-aligned sortable heading pads nothing around its button (WooCommerce pads 24px a side)', () => {
		const numeric = block( scss, '.woocommerce-table__header.is-sortable:not( .is-left-aligned ) .components-button {' );

		expect( numeric ).toMatch( /padding-left: 0;/ );
		expect( numeric ).toMatch( /padding-right: 0;/ );
	} );
} );

describe( 'the scroll bar of a table wider than its card (#1930)', () => {
	test( 'the always-drawn bar is the webkit one, and the standard properties are only for browsers without it', () => {
		const table = block( scss, '.woocommerce-table__table {\n\t\t// WooCommerce\'s edge fade' );
		const fallback = block( table, '@supports not selector( ::-webkit-scrollbar )' );

		expect( table ).toContain( '&::-webkit-scrollbar {' );
		expect( fallback ).toMatch( /scrollbar-color:/ );
		// Anywhere outside the fallback `scrollbar-color` turns the webkit bar off (macOS then shows the overlay one, hidden).
		expect( table.replace( fallback, '' ) ).not.toMatch( /scrollbar-(color|width)/ );
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

describe( 'the busy-row veil (operator, 23.11.2026)', () => {
	const shared = fs.readFileSync( path.join( __dirname, '../../src/components/_row-busy.scss' ), 'utf8' );
	const veil = block( shared, '@mixin veil(' );

	test( 'covers the whole row except the bar\'s 2px, above the pinned cells, centred both ways', () => {
		expect( veil ).toMatch( /position:\s*absolute/ );
		expect( veil ).toMatch( /inset:\s*0 0 2px/ );
		expect( veil ).toMatch( /display:\s*flex/ );
		expect( veil ).toMatch( /align-items:\s*center/ );
		expect( veil ).toMatch( /justify-content:\s*center/ );
		expect( Number( /^\s*z-index:\s*(\d+);/m.exec( veil )[ 1 ] ) ).toBeGreaterThan( 2 );
	} );

	test( 'adds nothing to the table\'s layout and leaves the row\'s links clickable', () => {
		expect( veil ).toMatch( /pointer-events:\s*none/ );
		expect( veil ).not.toMatch( /min-width|width:/ );
	} );

	test( 'the records table includes it, and «Заказы доставки» does not', () => {
		const orders = fs.readFileSync( path.join( __dirname, '../../src/shipping-orders-page/style.scss' ), 'utf8' );

		expect( scss ).toMatch( /row-busy\.veil\( '\.woodev-records__row-busy-veil' \)/ );
		expect( orders ).not.toMatch( /veil/ );
	} );

	test( 'a busy row\'s screen-reader-only sentences do not free the 1% actions column', () => {
		const selector = scss.slice( scss.indexOf( '.woocommerce-table__table:has(' ), scss.indexOf( '.woodev-records__col-actions {', scss.indexOf( '.woocommerce-table__table:has(' ) ) );

		expect( selector ).toMatch( /\.woodev-records__activity:not\( \.screen-reader-text \)/ );
		expect( block( scss, '.woodev-records__col-actions .woodev-records__reason' ) ).not.toMatch( /min-width/ );
	} );
} );

describe( 'the text of a busy or locked row in the actions column (#1937)', () => {
	const rule = block( scss, '.woodev-records__col-actions .woodev-records__reason' );

	test( 'wraps on a line of its own and adds nothing to the column minimum, so the table is never wider than without it', () => {
		expect( rule ).toMatch( /white-space:\s*normal/ );
		expect( rule ).toMatch( /overflow-wrap:\s*anywhere/ );
		expect( rule ).toMatch( /flex:\s*0 0 100%/ );
		expect( rule ).toMatch( /max-width:\s*12rem/ );
		// A floor would widen the column past the card (#1937: 813px idle, 940px busy in a 784px card).
		expect( rule ).not.toMatch( /min-width/ );
	} );

	test( 'only a table that shows such text frees the 1% actions column; a table without it keeps its layout', () => {
		expect( block( scss, '.woodev-records__col-actions {' ) ).toMatch( /width:\s*1%/ );

		const freed = block( scss, '.woocommerce-table__table:has(' );

		expect( freed ).toMatch( /width:\s*auto/ );
	} );

	test( 'the inline styles do not set a flex-basis of their own (it would beat the stylesheet)', () => {
		const inline = fs.readFileSync( path.join( __dirname, '../../src/admin-records/styles.ts' ), 'utf8' );
		const activity = fs.readFileSync( path.join( __dirname, '../../src/admin-records/activity/styles.ts' ), 'utf8' );

		expect( inline ).not.toMatch( /flexBasis/ );
		expect( activity ).not.toMatch( /flexBasis/ );
	} );
} );
