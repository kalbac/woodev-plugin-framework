/**
 * The marketplace tab of the product screen: a confirmation armed on one button must not outlive
 * the shared result line it wrote its question to.
 */

const path = require( 'node:path' );
const { waitFor } = require( '@testing-library/dom' );

const DIALOG_SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/woodev-dialog.js' );
const TAB_SCRIPT = path.join( __dirname, '../../woodev/marketplace/assets/js/admin/product-tab.js' );
const CONFIRM_MS = 5000;

window.woodevMarketplaceProduct = {
	texts: { close: 'Close', working: 'Working…', failed: 'Failed' },
};
global.jQuery = require( 'jquery' );
global.$      = global.jQuery;
window.jQuery = global.jQuery;
window.ajaxurl = '/wp-admin/admin-ajax.php';

// The script attaches one delegated click handler to `document` when it loads: load it once, and
// reset only the markup and the mocked request between tests.
require( DIALOG_SCRIPT );
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

describe( 'the dialogs of a block', () => {
	/**
	 * Renders a block with a badge that opens a dialog, and the «?» whose dialog holds the category-wide button.
	 */
	function renderDialogs() {
		document.body.innerHTML = `
			<div class="woodev-mp-block" data-account="2">
				<h3 class="woodev-mp-block__title">Fake
					<button type="button" class="woodev-mp-badge woodev-mp-badge--problem" data-woodev-mp-dialog>
						<span class="woodev-mp-badge__label">Created</span></button><template data-title="Check"><p>No weight</p></template>
				</h3>
				<button type="button" class="woodev-mp-help" data-woodev-mp-dialog>?</button>
				<template data-title="Hints"><p>Hint</p>
					<button type="button" class="button-link woodev-mp-link-op" data-woodev-mp-op="link" data-action="woodev_mp"
						data-nonce="n" data-product="7" data-account="2">Link by SKU</button></template>
				<p class="woodev-mp-buttons"><span class="woodev-mp-result" role="status"></span></p>
			</div>`;

		return {
			badge: document.querySelector( '.woodev-mp-badge' ),
			help: document.querySelector( '.woodev-mp-help' ),
		};
	}

	afterEach( () => {
		document.querySelectorAll( 'dialog' ).forEach( ( element ) => element.remove() );
	} );

	test( 'the badge opens one dialog with its own title and content', () => {
		const { badge } = renderDialogs();

		badge.click();

		const dialog = document.querySelector( 'dialog.woodev-dialog' );
		expect( dialog ).not.toBeNull();
		expect( dialog.hasAttribute( 'open' ) ).toBe( true );
		expect( dialog.querySelector( '.woodev-dialog__title' ).textContent ).toBe( 'Check' );
		expect( dialog.querySelector( '.woodev-dialog__body' ).textContent ).toBe( 'No weight' );
		expect( dialog.querySelector( '.woodev-dialog__header .woodev-dialog__close' ).getAttribute( 'aria-label' ) ).toBe( 'Close' );
		expect( dialog.querySelector( '.woodev-dialog__footer button' ).textContent ).toBe( 'Close' );
	} );

	test( 'the «?» reuses the same dialog and replaces its content', () => {
		const { badge, help } = renderDialogs();

		badge.click();
		help.click();

		expect( document.querySelectorAll( 'dialog' ).length ).toBe( 1 );
		expect( document.querySelector( '.woodev-dialog__title' ).textContent ).toBe( 'Hints' );
		expect( document.querySelector( '.woodev-dialog__body' ).textContent ).toContain( 'Hint' );
		expect( document.querySelector( '.woodev-dialog__body' ).textContent ).not.toContain( 'No weight' );
	} );

	test( 'a badge in a variation header opens the dialog without the header\'s own click handler running', () => {
		document.body.innerHTML = `
			<div class="woocommerce_variation"><h3 id="header">
				<button type="button" class="woodev-mp-badge" data-woodev-mp-dialog>State</button><template data-title="Check"><p>No weight</p></template>
			</h3></div>`;
		const toggled = jest.fn();
		document.getElementById( 'header' ).addEventListener( 'click', toggled );

		document.querySelector( '.woodev-mp-badge' ).click();

		expect( toggled ).not.toHaveBeenCalled();
		expect( document.querySelector( 'dialog .woodev-dialog__body' ).textContent ).toBe( 'No weight' );
	} );

	test( 'the «✕» of the header and the button of the footer both close the dialog', () => {
		const { badge } = renderDialogs();

		badge.click();
		document.querySelector( '.woodev-dialog__header .woodev-dialog__close' ).click();
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );

		badge.click();
		document.querySelector( '.woodev-dialog__footer button' ).click();
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );
	} );

	test( 'the category-wide «Связать по артикулу» button of the help dialog posts its own op and answers on the block\'s result line', () => {
		const { help } = renderDialogs();

		help.click();
		document.querySelector( 'dialog .woodev-mp-link-op' ).click();

		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toMatchObject( { op: 'link', product_id: 7, account_id: 2 } );
		expect( document.querySelector( 'dialog' ).hasAttribute( 'open' ) ).toBe( false );
		request.resolve( { data: { message: 'Linked 3' } } );
		expect( document.querySelector( '.woodev-mp-result' ).textContent ).toBe( 'Linked 3' );
	} );

	test( 'a new state keeps the orange of a badge that carries problems and changes its name', () => {
		const { detach, result } = renderBlock();
		const block = detach.closest( '.woodev-mp-block' );

		block.insertAdjacentHTML( 'afterbegin', '<h3 class="woodev-mp-block__title"><span class="woodev-mp-badge woodev-mp-badge--problem"><span class="woodev-mp-badge__label">Old</span></span></h3>' );
		detach.click();
		detach.click();
		request.resolve( { data: { message: 'Done', badge: { label: 'New', tone: 'ok' } } } );

		const badge = block.querySelector( '.woodev-mp-badge' );
		expect( result.textContent ).toBe( 'Done' );
		expect( badge.textContent ).toBe( 'New' );
		expect( badge.classList.contains( 'woodev-mp-badge--problem' ) ).toBe( true );
		expect( badge.classList.contains( 'woodev-mp-badge--ok' ) ).toBe( false );
	} );
} );

describe( 'the article line', () => {
	/**
	 * Renders the article the way PHP prints it: the line with «изменить», and the hidden editor (the input and «отменить»).
	 */
	function renderArticle() {
		document.body.innerHTML = `
			<span class="woodev-mp-article"><span class="woodev-mp-article__value">SKU-1</span>
				<button type="button" class="button-link woodev-mp-article__edit" aria-controls="art-1">edit</button></span>
			<span class="woodev-mp-article__editor" hidden><input type="text" id="art-1" value="SKU-1" />
				<button type="button" class="button-link woodev-mp-article__cancel" aria-controls="art-1">cancel</button></span>`;

		return {
			line: document.querySelector( '.woodev-mp-article' ),
			editor: document.querySelector( '.woodev-mp-article__editor' ),
			input: document.getElementById( 'art-1' ),
		};
	}

	test( '«изменить» gives way to the input and focuses it', () => {
		const { line, editor, input } = renderArticle();

		document.querySelector( '.woodev-mp-article__edit' ).click();

		expect( line.hidden ).toBe( true );
		expect( editor.hidden ).toBe( false );
		expect( document.activeElement ).toBe( input );
	} );

	test( '«отменить» drops what was typed and brings the line back', () => {
		const { line, editor, input } = renderArticle();

		document.querySelector( '.woodev-mp-article__edit' ).click();
		input.value = 'NEW-ARTICLE';
		document.querySelector( '.woodev-mp-article__cancel' ).click();

		expect( input.value ).toBe( 'SKU-1' );
		expect( editor.hidden ).toBe( true );
		expect( line.hidden ).toBe( false );
	} );
} );

describe( 'the attention pulse of a badge with problems', () => {
	const rects = Element.prototype.getClientRects;

	beforeAll( () => {
		// jsdom has no layout: every element is «not visible» to jQuery unless it reports a box.
		Element.prototype.getClientRects = function () {
			return this.closest( '[hidden]' ) ? [] : [ {} ];
		};
	} );

	afterAll( () => {
		Element.prototype.getClientRects = rects;
	} );

	/**
	 * Renders the product data box with the marketplace tab and the Variations tab, and one badge with problems in each place.
	 */
	function renderTabs() {
		document.body.innerHTML = `
			<div id="woocommerce-product-data">
				<ul class="wc-tabs">
					<li class="general active"><a href="#g">General</a></li>
					<li class="woodev-mp-tab-link"><a href="#o">Ozon</a></li>
					<li class="variations_tab"><a href="#v">Variations</a></li>
				</ul>
				<div class="woodev-mp-tab"><button type="button" class="woodev-mp-badge woodev-mp-badge--problem" id="tab-badge">Created</button></div>
				<div id="variable_product_options"><div class="woocommerce_variation wc-metabox closed"><h3><button type="button" class="woodev-mp-badge woodev-mp-badge--problem" id="var-badge">Created</button></h3></div></div>
			</div>`;
	}

	test( 'opening the marketplace tab pulses its badge; clicking the tab that is already open does not', () => {
		renderTabs();
		const tab = document.querySelector( '.woodev-mp-tab-link' );

		tab.querySelector( 'a' ).click();
		jest.runOnlyPendingTimers();
		expect( document.getElementById( 'tab-badge' ).classList.contains( 'is-pulsing' ) ).toBe( true );

		document.getElementById( 'tab-badge' ).dispatchEvent( new Event( 'animationend', { bubbles: true } ) );
		expect( document.getElementById( 'tab-badge' ).classList.contains( 'is-pulsing' ) ).toBe( false );

		tab.classList.add( 'active' );
		tab.querySelector( 'a' ).click();
		jest.runOnlyPendingTimers();
		expect( document.getElementById( 'tab-badge' ).classList.contains( 'is-pulsing' ) ).toBe( false );
	} );

	test( 'the page loading pulses nothing', () => {
		renderTabs();
		jest.runOnlyPendingTimers();

		expect( document.querySelectorAll( '.is-pulsing' ).length ).toBe( 0 );
	} );

	test( 'a badge that is not on screen (another account\'s block) does not pulse', () => {
		renderTabs();
		document.querySelector( '.woodev-mp-tab' ).hidden = true;

		document.querySelector( '.woodev-mp-tab-link a' ).click();
		jest.runOnlyPendingTimers();

		expect( document.getElementById( 'tab-badge' ).classList.contains( 'is-pulsing' ) ).toBe( false );
	} );

	test( 'the variations loading, and a variation being opened, pulse the badges of the variations', () => {
		renderTabs();

		global.jQuery( document.body ).trigger( 'woocommerce_variations_loaded' );
		expect( document.getElementById( 'var-badge' ).classList.contains( 'is-pulsing' ) ).toBe( true );

		document.getElementById( 'var-badge' ).dispatchEvent( new Event( 'animationend', { bubbles: true } ) );
		const variation = document.querySelector( '.woocommerce_variation' );

		variation.classList.remove( 'closed' );
		variation.querySelector( 'h3' ).click();
		jest.runOnlyPendingTimers();

		expect( document.getElementById( 'var-badge' ).classList.contains( 'is-pulsing' ) ).toBe( true );
	} );
} );

describe( 'the actions on the variations of a variable product', () => {
	/**
	 * Renders the table of the variants (select, apply, ticks, the buttons of a row) and the buttons of an opened variation.
	 */
	function renderVariants() {
		document.body.innerHTML = `
			<div class="woodev-mp-variants" data-account="2" data-product="90" data-action="woodev_mp_variations" data-nonce="vn">
				<div class="woodev-mp-variants__toolbar">
					<select class="woodev-mp-variants__select"><option value="">Pick</option><option value="export">Export</option></select>
					<button type="button" class="woodev-mp-variants__apply">Apply</button>
					<span class="woodev-mp-result" role="status"></span></div>
				<table><thead><tr><td><input type="checkbox" class="woodev-mp-variants__all" /></td></tr></thead><tbody>
					<tr><td><input type="checkbox" class="woodev-mp-variants__check" value="5" /></td>
						<td><button type="button" data-woodev-mp-vop="detach" data-action="woodev_mp_variations" data-nonce="vn"
							data-product="90" data-variation="5" data-account="2" data-confirm="Sure?">Detach</button></td></tr>
					<tr><td><input type="checkbox" class="woodev-mp-variants__check" value="6" /></td></tr>
				</tbody></table></div>
			<p class="woodev-mp-buttons"><span class="woodev-mp-buttons__row">
				<button type="button" id="panel-export" data-woodev-mp-vop="export" data-action="woodev_mp_variations" data-nonce="vn"
					data-product="90" data-variation="7" data-account="2" data-confirm="">Export</button></span>
				<span class="woodev-mp-result" id="panel-result" role="status"></span></p>`;

		return {
			select: document.querySelector( '.woodev-mp-variants__select' ),
			apply: document.querySelector( '.woodev-mp-variants__apply' ),
			result: document.querySelector( '.woodev-mp-variants__toolbar .woodev-mp-result' ),
			checks: document.querySelectorAll( '.woodev-mp-variants__check' ),
			all: document.querySelector( '.woodev-mp-variants__all' ),
		};
	}

	test( 'the heading tick ticks every variant, and ticking them all by hand ticks the heading', () => {
		const { checks, all } = renderVariants();

		all.checked = true;
		all.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( [ ...checks ].every( ( box ) => box.checked ) ).toBe( true );

		checks[ 0 ].checked = false;
		checks[ 0 ].dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( all.checked ).toBe( false );

		checks[ 0 ].checked = true;
		checks[ 0 ].dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( all.checked ).toBe( true );
	} );

	test( '«Применить» posts the chosen action with the ticked variants and shows the notice with its link', () => {
		window.woodevMarketplaceProduct.texts.pickAction = 'Pick an action';
		const { select, apply, result, checks } = renderVariants();

		select.value = 'export';
		checks[ 1 ].checked = true;
		apply.click();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( global.jQuery.post.mock.calls[ 0 ][ 0 ] ).toBe( '/wp-admin/admin-ajax.php' );
		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toEqual( {
			action: 'woodev_mp_variations',
			_wpnonce: 'vn',
			bulk_action: 'export',
			account_id: 2,
			product_id: 90,
			variations: [ 6 ],
		} );
		expect( apply.disabled ).toBe( true );

		request.resolve( { data: { tone: 'success', text: 'Queued 1.', url: 'https://example.test/products', link: 'Open' } } );

		expect( apply.disabled ).toBe( false );
		expect( result.textContent ).toBe( 'Queued 1. Open' );
		expect( result.querySelector( 'a' ).getAttribute( 'href' ) ).toBe( 'https://example.test/products' );
		expect( result.classList.contains( 'woodev-mp-result--error' ) ).toBe( false );
	} );

	test( '«Применить» asks for an action and for variants before it sends anything', () => {
		window.woodevMarketplaceProduct.texts.pickAction = 'Pick an action';
		window.woodevMarketplaceProduct.texts.pickVariants = 'Tick variants';
		const { select, apply, result } = renderVariants();

		apply.click();
		expect( result.textContent ).toBe( 'Pick an action' );

		select.value = 'export';
		apply.click();
		expect( result.textContent ).toBe( 'Tick variants' );
		expect( result.classList.contains( 'woodev-mp-result--error' ) ).toBe( true );
		expect( global.jQuery.post ).not.toHaveBeenCalled();
	} );

	test( 'a refusal is shown in red', () => {
		const { select, apply, result, checks } = renderVariants();

		select.value = 'export';
		checks[ 0 ].checked = true;
		apply.click();
		request.resolve( { data: { tone: 'error', text: 'No rights.', url: '', link: '' } } );

		expect( result.textContent ).toBe( 'No rights.' );
		expect( result.classList.contains( 'woodev-mp-result--error' ) ).toBe( true );
	} );

	test( 'a failed request says so, with the server\'s words when it gave any', () => {
		const { select, apply, result, checks } = renderVariants();

		select.value = 'export';
		checks[ 0 ].checked = true;
		apply.click();
		request.reject( { responseJSON: { data: { message: 'Page is stale.' } } } );

		expect( result.textContent ).toBe( 'Page is stale.' );
		expect( apply.disabled ).toBe( false );
	} );

	test( 'a destructive button of a row asks twice, then posts its action for its own variation', () => {
		renderVariants();
		const detach = document.querySelector( 'tbody [data-woodev-mp-vop="detach"]' );
		const result = document.querySelector( '.woodev-mp-variants__toolbar .woodev-mp-result' );

		detach.click();
		expect( result.textContent ).toBe( 'Sure?' );
		expect( global.jQuery.post ).not.toHaveBeenCalled();

		detach.click();
		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toMatchObject( { bulk_action: 'detach', variations: [ 5 ], product_id: 90, account_id: 2 } );
	} );

	test( 'a button of an opened variation answers on its own line', () => {
		renderVariants();

		document.getElementById( 'panel-export' ).click();
		expect( global.jQuery.post.mock.calls[ 0 ][ 1 ] ).toMatchObject( { bulk_action: 'export', variations: [ 7 ] } );

		request.resolve( { data: { tone: 'success', text: 'Queued 1.', url: '', link: '' } } );

		expect( document.getElementById( 'panel-result' ).textContent ).toBe( 'Queued 1.' );
		expect( document.querySelector( '.woodev-mp-variants__toolbar .woodev-mp-result' ).textContent ).toBe( '' );
	} );
} );

describe( 'fitting to the width the panel has', () => {
	/**
	 * Renders the Variations area with a line of buttons and a table of the variants, with the sizes a browser would report.
	 *
	 * @param {Object} sizes `{ box, group, wrap, scroll }`: the width of the line, the width the group needs with its words, the width of the
	 *                       table's wrapper and the width its content needs.
	 */
	function renderFit( sizes ) {
		document.body.innerHTML = `
			<div id="variable_product_options">
				<p class="woodev-mp-buttons" style="padding-left: 100px"><span class="woodev-mp-buttons__row button-group"><button type="button">A</button></span></p>
				<p class="woodev-mp-buttons"><span class="woodev-mp-buttons__row button-group woodev-mp-buttons__row--many"><button type="button">A</button></span></p>
				<div class="woodev-mp-variants"><div class="woodev-mp-variations-wrap"><table></table></div></div>
			</div>`;

		const boxes = document.querySelectorAll( '.woodev-mp-buttons' );
		const wrap = document.querySelector( '.woodev-mp-variations-wrap' );

		boxes.forEach( ( box ) => Object.defineProperty( box, 'clientWidth', { configurable: true, value: sizes.box } ) );
		boxes[ 0 ].querySelector( '.woodev-mp-buttons__row' ).getBoundingClientRect = () => ( { width: sizes.group } );
		Object.defineProperty( wrap, 'clientWidth', { configurable: true, value: sizes.wrap } );
		Object.defineProperty( wrap, 'scrollWidth', { configurable: true, value: sizes.scroll } );

		global.jQuery( document.body ).trigger( 'woocommerce_variations_loaded' );

		return {
			row: boxes[ 0 ].querySelector( '.woodev-mp-buttons__row' ),
			many: boxes[ 1 ].querySelector( '.woodev-mp-buttons__row' ),
			variants: document.querySelector( '.woodev-mp-variants' ),
		};
	}

	test( 'a group whose words fit the width its line has keeps them (the line\'s own padding is not width)', () => {
		const { row } = renderFit( { box: 560, group: 460, wrap: 600, scroll: 600 } );

		expect( row.classList.contains( 'is-compact' ) ).toBe( false );
		expect( row.classList.contains( 'is-measuring' ) ).toBe( false );
	} );

	test( 'a group whose words do not fit drops them', () => {
		const { row } = renderFit( { box: 540, group: 460, wrap: 600, scroll: 600 } );

		expect( row.classList.contains( 'is-compact' ) ).toBe( true );
		expect( row.classList.contains( 'is-measuring' ) ).toBe( false );
	} );

	test( 'a group of more than three, and a line that is not on screen, are left alone', () => {
		const { many } = renderFit( { box: 10, group: 460, wrap: 600, scroll: 600 } );

		expect( many.classList.contains( 'is-compact' ) ).toBe( false );
		expect( renderFit( { box: 0, group: 460, wrap: 600, scroll: 600 } ).row.classList.contains( 'is-compact' ) ).toBe( false );
	} );

	test( 'a table that is wider than its panel is stacked, one that fits stays a table', () => {
		expect( renderFit( { box: 560, group: 460, wrap: 500, scroll: 700 } ).variants.classList.contains( 'is-stacked' ) ).toBe( true );
		expect( renderFit( { box: 560, group: 460, wrap: 500, scroll: 500 } ).variants.classList.contains( 'is-stacked' ) ).toBe( false );
	} );
} );

describe( 'the account select', () => {
	// One variation as the framework prints it for a product with two accounts: the slot in the collapsed header and the block in the opened
	// variation, one per account, and the slot of another marketplace that has the same account ids.
	const VARIATION = `
		<div class="woocommerce_variation"><h3>
			<span class="woodev-mp-header-state" data-marketplace="fake" data-account="2"></span>
			<span class="woodev-mp-header-state" data-marketplace="fake" data-account="4" hidden></span>
			<span class="woodev-mp-header-state" data-marketplace="other" data-account="2"></span>
			<span class="woodev-mp-header-state" data-marketplace="other" data-account="4" hidden></span></h3>
			<div class="woodev-mp-block woodev-mp-block--variation" data-marketplace="fake" data-account="2"></div>
			<div class="woodev-mp-block woodev-mp-block--variation" data-marketplace="fake" data-account="4" hidden></div></div>`;

	/**
	 * Tells whether a part of a variation is on screen.
	 *
	 * @param {string} selector Class of the part.
	 * @param {string} marketplace Marketplace id.
	 * @param {string} account Account id.
	 * @return {boolean} False when it carries `hidden`.
	 */
	function isShown( selector, marketplace, account ) {
		return ! document.querySelector( `#variable_product_options ${ selector }[data-marketplace="${ marketplace }"][data-account="${ account }"]` ).hidden;
	}

	beforeEach( () => {
		jest.useRealTimers();
	} );

	/**
	 * Loads the script afresh over a page that has the select (it sets itself up when the page is ready).
	 *
	 * @param {string|null} stored Account id kept from an earlier visit.
	 */
	async function loadWithSelect( stored ) {
		document.body.innerHTML = `
			<div id="woocommerce-product-data"><h2 class="hndle"><span>Product data</span></h2>
				<ul class="wc-tabs"><li class="general"><a href="#g">General</a></li><li class="woodev-mp-tab-link"><a href="#o">Ozon</a></li>
					<li class="variations_tab"><a href="#v">Variations</a></li></ul>
				<div class="woodev-mp-tab">
					<div class="woodev-mp-account-switch" data-remember="woodev_fake_account" data-marketplace="fake">
						<select><option value="2">One</option><option value="4">Two</option></select></div>
					<div class="woodev-mp-block" data-account="2"></div>
					<div class="woodev-mp-block" data-account="4" hidden></div>
				</div>
				<div id="variable_product_options">${ VARIATION }</div>
			</div>`;

		window.localStorage.clear();
		if ( null !== stored ) {
			window.localStorage.setItem( 'woodev_mp_woodev_fake_account', stored );
		}

		jest.isolateModules( () => {
			require( TAB_SCRIPT );
		} );

		// jQuery runs a ready callback on a timer, and the script waits one more tick after it. Wait for the
		// result, not for a guessed number of ms: a fixed 30 ms sleep lost the race on a loaded machine (#1892).
		// The header class is the last thing the setup does before it syncs the select's visibility.
		return waitFor( () => expect( document.querySelector( '.woodev-mp-account-switch--header' ) ).not.toBeNull() );
	}

	test( 'moves into the header, starts on the first account and shows only its block', async () => {
		await loadWithSelect( null );

		const header = document.querySelector( '#woocommerce-product-data .hndle' );
		expect( header.querySelector( '.woodev-mp-account-switch' ) ).not.toBeNull();
		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( false );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( true );
	} );

	test( 'starts on the account used last in this browser', async () => {
		await loadWithSelect( '4' );

		expect( document.querySelector( '.woodev-mp-account-switch select' ).value ).toBe( '4' );
		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( true );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( false );
	} );

	test( 'ignores a remembered account that is gone', async () => {
		await loadWithSelect( '99' );

		expect( document.querySelector( '.woodev-mp-account-switch select' ).value ).toBe( '2' );
	} );

	test( 'picking another account swaps the block and remembers the choice', async () => {
		await loadWithSelect( null );

		const select = document.querySelector( '.woodev-mp-account-switch select' );
		select.value = '4';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( document.querySelector( '[data-account="2"]' ).hidden ).toBe( true );
		expect( document.querySelector( '[data-account="4"]' ).hidden ).toBe( false );
		expect( window.localStorage.getItem( 'woodev_mp_woodev_fake_account' ) ).toBe( '4' );
	} );

	test( 'the variations start on the picked account: one slot in the header and one block per variation', async () => {
		await loadWithSelect( '4' );

		expect( isShown( '.woodev-mp-header-state', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-header-state', 'fake', '4' ) ).toBe( true );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '4' ) ).toBe( true );
	} );

	test( 'picking another account swaps the variations too, and leaves another marketplace\'s variations alone', async () => {
		await loadWithSelect( null );

		const select = document.querySelector( '.woodev-mp-account-switch select' );
		select.value = '4';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( isShown( '.woodev-mp-header-state', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-header-state', 'fake', '4' ) ).toBe( true );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '4' ) ).toBe( true );
		expect( isShown( '.woodev-mp-header-state', 'other', '2' ) ).toBe( true );
		expect( isShown( '.woodev-mp-header-state', 'other', '4' ) ).toBe( false );
	} );

	test( 'variations that WooCommerce loads later start on the picked account', async () => {
		await loadWithSelect( null );

		const select = document.querySelector( '.woodev-mp-account-switch select' );
		select.value = '4';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// The AJAX answer replaces the variations with the markup of the server, which starts on the default account.
		document.getElementById( 'variable_product_options' ).innerHTML = VARIATION;
		expect( isShown( '.woodev-mp-block--variation', 'fake', '2' ) ).toBe( true );

		global.jQuery( document.body ).trigger( 'woocommerce_variations_loaded' );

		expect( isShown( '.woodev-mp-header-state', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-header-state', 'fake', '4' ) ).toBe( true );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '2' ) ).toBe( false );
		expect( isShown( '.woodev-mp-block--variation', 'fake', '4' ) ).toBe( true );
	} );

	test( 'a variation added by hand starts on the picked account too', async () => {
		await loadWithSelect( null );

		const select = document.querySelector( '.woodev-mp-account-switch select' );
		select.value = '4';
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// «Add manually»: WooCommerce prepends the server's markup (default account) and fires its own event, not the loaded one.
		document.getElementById( 'variable_product_options' ).insertAdjacentHTML( 'afterbegin', VARIATION );
		global.jQuery( document.body ).trigger( 'woocommerce_variations_added' );

		const added = document.querySelector( '#variable_product_options .woocommerce_variation' );
		expect( added.querySelector( '.woodev-mp-block--variation[data-marketplace="fake"][data-account="2"]' ).hidden ).toBe( true );
		expect( added.querySelector( '.woodev-mp-block--variation[data-marketplace="fake"][data-account="4"]' ).hidden ).toBe( false );
		expect( added.querySelector( '.woodev-mp-header-state[data-marketplace="fake"][data-account="4"]' ).hidden ).toBe( false );
	} );

	test( 'two marketplaces each have their own select, tab and variations', async () => {
		document.body.innerHTML = `
			<div id="woocommerce-product-data"><h2 class="hndle"><span>Product data</span></h2>
				<ul class="wc-tabs"><li class="woodev-mp-tab-link"><a href="#fake_panel">Fake</a></li><li class="woodev-mp-tab-link"><a href="#other_panel">Other</a></li>
					<li class="variations_tab"><a href="#v">Variations</a></li></ul>
				<div id="fake_panel" class="woodev-mp-tab">
					<div class="woodev-mp-account-switch" data-remember="woodev_fake_account" data-marketplace="fake">
						<select id="fake-select"><option value="2">One</option><option value="4">Two</option></select></div>
					<div class="woodev-mp-block" data-account="2"></div><div class="woodev-mp-block" data-account="4" hidden></div></div>
				<div id="other_panel" class="woodev-mp-tab">
					<div class="woodev-mp-account-switch" data-remember="woodev_other_account" data-marketplace="other">
						<select id="other-select"><option value="7">Seven</option><option value="9">Nine</option></select></div>
					<div class="woodev-mp-block" data-account="7"></div><div class="woodev-mp-block" data-account="9" hidden></div></div>
				<div id="variable_product_options"><div class="woocommerce_variation">
					<span class="woodev-mp-header-state" data-marketplace="fake" data-account="2"></span>
					<span class="woodev-mp-header-state" data-marketplace="fake" data-account="4" hidden></span>
					<span class="woodev-mp-header-state" data-marketplace="other" data-account="7"></span>
					<span class="woodev-mp-header-state" data-marketplace="other" data-account="9" hidden></span></div></div>
			</div>`;
		window.localStorage.clear();

		jest.isolateModules( () => {
			require( TAB_SCRIPT );
		} );
		await waitFor( () => expect( document.querySelectorAll( '.woodev-mp-account-switch--header' ).length ).toBe( 2 ) );

		const other = document.getElementById( 'other-select' );
		other.value = '9';
		other.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( isShown( '.woodev-mp-header-state', 'other', '7' ) ).toBe( false );
		expect( isShown( '.woodev-mp-header-state', 'other', '9' ) ).toBe( true );
		expect( isShown( '.woodev-mp-header-state', 'fake', '2' ) ).toBe( true );
		expect( isShown( '.woodev-mp-header-state', 'fake', '4' ) ).toBe( false );
		expect( window.localStorage.getItem( 'woodev_mp_woodev_other_account' ) ).toBe( '9' );
		expect( window.localStorage.getItem( 'woodev_mp_woodev_fake_account' ) ).toBeNull();

		// Each select is shown with its own tab: opening the second marketplace's tab shows the second select only.
		const tab = document.querySelectorAll( '.woodev-mp-tab-link' )[ 1 ];
		tab.classList.add( 'active' );
		tab.querySelector( 'a' ).click();

		await waitFor( () => expect( document.querySelector( '[data-marketplace="other"].woodev-mp-account-switch' ).hidden ).toBe( false ) );
		expect( document.querySelector( '[data-marketplace="fake"].woodev-mp-account-switch' ).hidden ).toBe( true );
	} );

	test( 'is shown while the «Variations» tab is the open one, too', async () => {
		await loadWithSelect( null );

		const wrap = document.querySelector( '.woodev-mp-account-switch' );
		const tab  = document.querySelector( '.variations_tab' );
		tab.classList.add( 'active' );
		tab.querySelector( 'a' ).click();

		await waitFor( () => expect( wrap.hidden ).toBe( false ) );
	} );

	test( 'is shown only while the marketplace tab is the open one', async () => {
		await loadWithSelect( null );

		const wrap = document.querySelector( '.woodev-mp-account-switch' );
		expect( wrap.hidden ).toBe( true );

		const link = document.querySelector( '.woodev-mp-tab-link' );
		link.classList.add( 'active' );
		link.querySelector( 'a' ).click();

		// The script reads the active tab one tick after the click.
		await waitFor( () => expect( wrap.hidden ).toBe( false ) );
	} );
} );
