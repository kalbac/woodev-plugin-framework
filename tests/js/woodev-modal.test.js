/**
 * Tests for woodev-modal.js
 *
 * Covers the vanilla modal shell's aria contract, focus management, scroll
 * lock, and error/empty degradation states — SP-5 Task 10.
 *
 * @see woodev/assets/js/frontend/woodev-modal.js
 */

'use strict';

const WoodevModal = require( '../../woodev/assets/js/frontend/woodev-modal' );

// Real jQuery (not a hand-rolled fake) — needed to prove the native/jQuery bridge
// documented in D-14: a native CustomEvent dispatched on document.body is seen by
// BOTH addEventListener and jQuery's .on(), but the reverse does not hold.
global.window.jQuery = require( 'jquery' );

/**
 * Build a trigger button appended to the document, so returnFocusTo has a
 * real, focusable element to return to.
 */
function makeTrigger() {
	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.textContent = 'Open';
	document.body.appendChild( btn );
	btn.focus();
	return btn;
}

afterEach( () => {
	document.body.innerHTML = '';
	document.body.className = '';
} );

test( 'open() renders a dialog with the aria contract', () => {
	const modal = new WoodevModal( { title: 'Пункты выдачи' } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog ).not.toBeNull();
	expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );

	const labelledBy = dialog.getAttribute( 'aria-labelledby' );
	expect( labelledBy ).toBeTruthy();
	const titleEl = document.getElementById( labelledBy );
	expect( titleEl ).not.toBeNull();
	expect( titleEl.textContent ).toBe( 'Пункты выдачи' );

	modal.destroy();
} );

test( 'open() carries the D-13 BEM class contract: root, backdrop, and content box', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();

	// `.woodev-modal` is the shell's ROOT marker — Task 2's event surface and Task 3's
	// woodev-modal.css box-sizing reset both hang off it, so it must be queryable on the
	// same node that also carries the overlay's own `.woodev-modal-backdrop` class.
	const root = document.querySelector( '.woodev-modal' );
	expect( root ).not.toBeNull();
	expect( root.classList.contains( 'woodev-modal-backdrop' ) ).toBe( true );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.classList.contains( 'woodev-modal__content' ) ).toBe( true );
	expect( root.contains( dialog ) ).toBe( true );

	modal.destroy();
} );

test( 'Escape closes it', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	expect( document.querySelector( '[role="dialog"]' ) ).not.toBeNull();

	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	modal.destroy();
} );

test( 'focus returns to the trigger on close', () => {
	const trigger = makeTrigger();
	const modal = new WoodevModal( { title: 'T', returnFocusTo: trigger } );

	modal.open();
	expect( document.activeElement ).not.toBe( trigger );

	modal.close();
	expect( document.activeElement ).toBe( trigger );

	modal.destroy();
} );

test( 'getContainer() gives the provider its mount point', () => {
	const modal = new WoodevModal( { title: 'T' } );
	const container = modal.getContainer();

	expect( container ).toBeInstanceOf( HTMLElement );

	modal.open();
	// Same node identity before and after open() — the provider can hold a
	// reference across the modal's lifecycle.
	expect( modal.getContainer() ).toBe( container );

	const marker = document.createElement( 'span' );
	marker.className = 'provider-marker';
	container.appendChild( marker );
	expect( document.querySelector( '.provider-marker' ) ).not.toBeNull();

	modal.destroy();
} );

test( 'showError() replaces the body instead of leaving it blank', () => {
	const modal = new WoodevModal( { title: 'T' } );
	const container = modal.getContainer();
	container.appendChild( document.createElement( 'div' ) ); // pretend the provider drew something

	modal.open();
	modal.showError( 'Не удалось загрузить карту' );

	const body = modal.getContainer();
	expect( body.textContent ).toContain( 'Не удалось загрузить карту' );
	// Replaced, not appended alongside — the old provider content is gone.
	expect( body.children.length ).toBe( 1 );

	modal.destroy();
} );

test( 'showEmpty() renders an explicit empty state', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	modal.showEmpty( 'Пункты выдачи не найдены' );

	const body = modal.getContainer();
	expect( body.textContent ).toContain( 'Пункты выдачи не найдены' );
	// No retry control for an empty state — there is nothing to retry.
	expect( body.querySelector( 'button' ) ).toBeNull();

	modal.destroy();
} );

test( 'showNotice() renders a banner ALONGSIDE the body, never replacing its content', () => {
	const modal = new WoodevModal( { title: 'T' } );
	const container = modal.getContainer();
	const marker = document.createElement( 'div' );
	marker.className = 'provider-marker';
	container.appendChild( marker ); // pretend the provider already drew a map here

	modal.open();
	modal.showNotice( 'Не удалось обновить пункты выдачи' );

	// The provider's own content survives untouched.
	expect( document.querySelector( '.provider-marker' ) ).not.toBeNull();
	expect( modal.getContainer() ).toBe( container );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Не удалось обновить пункты выдачи' );

	const notice = dialog.querySelector( '.woodev-modal__notice' );
	expect( notice ).not.toBeNull();
	expect( container.contains( notice ) ).toBe( false ); // sibling of the body, not a child

	modal.destroy();
} );

test( 'showNotice() with no onRetry renders no retry control (the empty-after-drawn case)', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	modal.showNotice( 'Пункты выдачи не найдены в этой области' );

	const notice = document.querySelector( '.woodev-modal__notice' );
	expect( notice.querySelector( '.woodev-modal__notice-retry' ) ).toBeNull();
	// Still has its own dismiss control.
	expect( notice.querySelector( '.woodev-modal__notice-dismiss' ) ).not.toBeNull();

	modal.destroy();
} );

test( 'showNotice() retry control invokes the callback', () => {
	const modal = new WoodevModal( { title: 'T', retryLabel: 'Повторить' } );
	modal.open();

	const onRetry = jest.fn();
	modal.showNotice( 'Ошибка обновления', onRetry );

	const retryBtn = document.querySelector( '.woodev-modal__notice-retry' );
	expect( retryBtn ).not.toBeNull();
	expect( retryBtn.textContent ).toBe( 'Повторить' );

	retryBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( onRetry ).toHaveBeenCalledTimes( 1 );

	modal.destroy();
} );

test( 'showNotice() dismiss control removes the banner without touching the body', () => {
	const modal = new WoodevModal( { title: 'T' } );
	const container = modal.getContainer();
	container.appendChild( document.createElement( 'div' ) );

	modal.open();
	modal.showNotice( 'Что-то пошло не так' );

	const dismissBtn = document.querySelector( '.woodev-modal__notice-dismiss' );
	dismissBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( document.querySelector( '.woodev-modal__notice' ) ).toBeNull();
	expect( container.children.length ).toBe( 1 ); // the pre-existing content is untouched

	modal.destroy();
} );

test( 'showNotice() called twice replaces the first banner rather than stacking a second', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();

	modal.showNotice( 'Первое сообщение' );
	modal.showNotice( 'Второе сообщение' );

	const notices = document.querySelectorAll( '.woodev-modal__notice' );
	expect( notices.length ).toBe( 1 );
	expect( notices[ 0 ].textContent ).toContain( 'Второе сообщение' );

	modal.destroy();
} );

test( 'showError() retry control invokes the callback', () => {
	const modal = new WoodevModal( { title: 'T', retryLabel: 'Повторить' } );
	modal.open();

	const onRetry = jest.fn();
	modal.showError( 'Ошибка', onRetry );

	const retryBtn = modal.getContainer().querySelector( 'button' );
	expect( retryBtn ).not.toBeNull();
	expect( retryBtn.textContent ).toBe( 'Повторить' );

	retryBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( onRetry ).toHaveBeenCalledTimes( 1 );

	modal.destroy();
} );

test( 'backdrop click closes, but a click inside the dialog does not', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	dialog.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( document.querySelector( '[role="dialog"]' ) ).not.toBeNull();

	const backdrop = dialog.parentNode;
	backdrop.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();

	modal.destroy();
} );

test( 'Tab wraps forward from the last focusable element to the first', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	modal.showError( 'Ошибка', jest.fn() ); // adds a second focusable (retry) after the close button

	const dialog = document.querySelector( '[role="dialog"]' );
	const focusables = dialog.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]' );
	expect( focusables.length ).toBeGreaterThanOrEqual( 2 );

	const last = focusables[ focusables.length - 1 ];
	const first = focusables[ 0 ];
	last.focus();

	const evt = new KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true, cancelable: true } );
	document.dispatchEvent( evt );

	expect( document.activeElement ).toBe( first );

	modal.destroy();
} );

test( 'Shift+Tab wraps backward from the first focusable element to the last', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	modal.showError( 'Ошибка', jest.fn() );

	const dialog = document.querySelector( '[role="dialog"]' );
	const focusables = dialog.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]' );
	const last = focusables[ focusables.length - 1 ];
	const first = focusables[ 0 ];
	first.focus();

	const evt = new KeyboardEvent( 'keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true } );
	document.dispatchEvent( evt );

	expect( document.activeElement ).toBe( last );

	modal.destroy();
} );

test( 'body gets a scroll-lock class while open, removed on close', () => {
	const modal = new WoodevModal( { title: 'T' } );
	expect( document.body.className ).not.toMatch( /woodev-modal-lock/ );

	modal.open();
	expect( document.body.className ).toMatch( /woodev-modal-lock/ );

	modal.close();
	expect( document.body.className ).not.toMatch( /woodev-modal-lock/ );

	modal.destroy();
} );

test( 'open() twice does not produce two dialogs', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();
	modal.open();

	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	modal.destroy();
} );

test( 'destroy() removes the node and every listener it owns', () => {
	const trigger = makeTrigger();
	const modal = new WoodevModal( { title: 'T', returnFocusTo: trigger } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	const backdrop = dialog.parentNode;

	modal.destroy();

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	expect( document.body.contains( backdrop ) ).toBe( false );
	expect( document.body.className ).not.toMatch( /woodev-modal-lock/ );

	// A subsequent Escape must be a no-op — no document-level listener survives.
	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 0 );

	// A subsequent backdrop click must be a no-op too (the node is detached
	// but dispatch still works against a detached EventTarget).
	backdrop.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( document.body.contains( backdrop ) ).toBe( false );
} );

test( 'destroy() removes the close-button click listener too', () => {
	const modal = new WoodevModal( { title: 'T' } );
	modal.open();

	const closeButton = document.querySelector( '.woodev-modal__close' );
	modal.destroy();

	// Detect whether the listener created at construction time is still
	// attached to the (now detached) button node — dispatch a click and see
	// whether it still reaches the instance. `close` is looked up dynamically
	// inside the listener closure, so overriding it on the instance lets us
	// observe whether the closure still fires.
	modal.close = jest.fn();
	closeButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( modal.close ).not.toHaveBeenCalled();
} );

test( 'close() before any open() is a harmless no-op', () => {
	const modal = new WoodevModal( { title: 'T' } );
	expect( () => modal.close() ).not.toThrow();
	modal.destroy();
} );

test( 'title text is escaped as text, not injected as markup', () => {
	const modal = new WoodevModal( { title: '<img src=x onerror=alert(1)>' } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( 'img' ) ).toBeNull();

	modal.destroy();
} );

describe( 'WoodevModal loading overlay', () => {
	it( 'shows the message WITHOUT removing what a consumer already mounted', () => {
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		modal.open();

		// A consumer's content, mounted the way a map provider mounts its canvas.
		const mounted = document.createElement( 'div' );
		mounted.className = 'consumer-canvas';
		modal.getContainer().appendChild( mounted );

		modal.showLoading( 'Загрузка…' );

		// Additive, not a replacement: showError()/showEmpty() wipe the body, and doing that
		// here would delete the node the consumer is drawing into while it loads.
		expect( modal.getContainer().querySelector( '.consumer-canvas' ) ).not.toBeNull();
		expect( modal.getContainer().querySelector( '.woodev-modal__loading' ).textContent ).toBe( 'Загрузка…' );

		modal.hideLoading();

		expect( modal.getContainer().querySelector( '.woodev-modal__loading' ) ).toBeNull();
		expect( modal.getContainer().querySelector( '.consumer-canvas' ) ).not.toBeNull();

		modal.destroy();
	} );

	it( 'keeps a single overlay when shown twice, and hiding twice is a no-op', () => {
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		modal.open();

		modal.showLoading( 'a' );
		modal.showLoading( 'b' );

		expect( modal.getContainer().querySelectorAll( '.woodev-modal__loading' ) ).toHaveLength( 1 );
		expect( modal.getContainer().querySelector( '.woodev-modal__loading' ).textContent ).toBe( 'b' );

		modal.hideLoading();

		expect( () => modal.hideLoading() ).not.toThrow();

		modal.destroy();
	} );
} );

describe( 'WoodevModal events', () => {
	// Every listener a test attaches to document.body is tracked here and torn down in
	// afterEach — a listener left behind by one test (e.g. an unconditional preventDefault()
	// on woodev_modal_before_close) would otherwise silently veto or double-count events in
	// every later test in this file, since jest gives the FILE a fresh jsdom, not each test.
	const bodyListeners = [];

	const onBody = ( type, handler ) => {
		document.body.addEventListener( type, handler );
		bodyListeners.push( { type: type, handler: handler } );
	};

	const listen = ( type ) => {
		const seen = [];
		onBody( type, ( e ) => seen.push( e ) );
		return seen;
	};

	afterEach( () => {
		bodyListeners.forEach( ( entry ) => document.body.removeEventListener( entry.type, entry.handler ) );
		bodyListeners.length = 0;
	} );

	it( 'fires woodev_modal_opened with modalId and context', () => {
		const seen = listen( 'woodev_modal_opened' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T', context: { a: 1 } } );
		modal.open();

		expect( seen ).toHaveLength( 1 );
		expect( seen[ 0 ].detail ).toEqual( { modalId: 'test-modal', context: { a: 1 } } );
		expect( seen[ 0 ].bubbles ).toBe( true );

		modal.destroy();
	} );

	it( 'defaults context to an empty object when omitted', () => {
		const seen = listen( 'woodev_modal_opened' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		modal.open();

		expect( seen[ 0 ].detail ).toEqual( { modalId: 'test-modal', context: {} } );

		modal.destroy();
	} );

	it( 'fires before_close then closed, carrying the reason', () => {
		const before = listen( 'woodev_modal_before_close' );
		const closed = listen( 'woodev_modal_closed' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );

		modal.open();
		modal.close( 'escape' );

		expect( before ).toHaveLength( 1 );
		expect( before[ 0 ].detail ).toEqual( { modalId: 'test-modal', reason: 'escape' } );
		expect( before[ 0 ].cancelable ).toBe( true );
		expect( before[ 0 ].bubbles ).toBe( true );

		expect( closed ).toHaveLength( 1 );
		expect( closed[ 0 ].detail ).toEqual( { modalId: 'test-modal', reason: 'escape' } );
		expect( closed[ 0 ].cancelable ).toBe( false );
		expect( closed[ 0 ].bubbles ).toBe( true );

		modal.destroy();
	} );

	it( 'defaults the reason to button when close() is called with no argument', () => {
		const closed = listen( 'woodev_modal_closed' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );

		modal.open();
		modal.close();

		expect( closed[ 0 ].detail ).toEqual( { modalId: 'test-modal', reason: 'button' } );

		modal.destroy();
	} );

	it( 'routes Escape, backdrop click, and the header close button through their own reasons', () => {
		const closed = listen( 'woodev_modal_closed' );

		const escModal = new WoodevModal( { modalId: 'esc-modal', title: 'T' } );
		escModal.open();
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( closed[ closed.length - 1 ].detail ).toEqual( { modalId: 'esc-modal', reason: 'escape' } );
		escModal.destroy();

		const backdropModal = new WoodevModal( { modalId: 'backdrop-modal', title: 'T' } );
		backdropModal.open();
		const backdrop = document.querySelector( '.woodev-modal-backdrop' );
		backdrop.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( closed[ closed.length - 1 ].detail ).toEqual( { modalId: 'backdrop-modal', reason: 'backdrop' } );
		backdropModal.destroy();

		const buttonModal = new WoodevModal( { modalId: 'button-modal', title: 'T' } );
		buttonModal.open();
		document.querySelector( '.woodev-modal__close' ).dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( closed[ closed.length - 1 ].detail ).toEqual( { modalId: 'button-modal', reason: 'button' } );
		buttonModal.destroy();

		expect( closed ).toHaveLength( 3 );
	} );

	it( 'aborts the close when before_close is prevented, tearing nothing down', () => {
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		const closed = listen( 'woodev_modal_closed' );

		// Tracked via onBody(), not a raw addEventListener() — removed in afterEach so this
		// permanent preventDefault() can never veto a later test's close() (regression-guarded
		// by the next test).
		onBody( 'woodev_modal_before_close', ( e ) => e.preventDefault() );
		modal.open();
		const result = modal.close( 'button' );

		expect( result ).toBe( false );
		expect( closed ).toHaveLength( 0 );
		expect( document.querySelector( '.woodev-modal' ) ).not.toBeNull();
		// Nothing was torn down: still tracked as open, scroll lock still applied,
		// and a second close() attempt is free to try again (not short-circuited
		// by a stale "already closed" state).
		expect( document.body.className ).toMatch( /woodev-modal-lock/ );

		// destroy() is the forced, unconditional teardown (bypasses the still-active veto by
		// design, see woodev-modal.js) — the correct way to dispose of a modal a test is done
		// with, rather than hand-clearing body.innerHTML/className.
		modal.destroy();
	} );

	it( 'a normal close still fires closed after a previous test\'s before_close listener is cleaned up', () => {
		// Regression guard for the veto test above: if onBody()'s afterEach cleanup ever
		// stopped removing that permanent preventDefault() listener, this modal's close()
		// would be silently vetoed too and `closed` would stay empty.
		const closed = listen( 'woodev_modal_closed' );
		const modal = new WoodevModal( { modalId: 'post-veto-modal', title: 'T' } );

		modal.open();
		const result = modal.close( 'button' );

		expect( result ).toBe( true );
		expect( closed ).toHaveLength( 1 );
		expect( closed[ 0 ].detail ).toEqual( { modalId: 'post-veto-modal', reason: 'button' } );

		modal.destroy();
	} );

	it( 'does not fire before_close/closed when destroy() tears down an open modal', () => {
		const before = listen( 'woodev_modal_before_close' );
		const closed = listen( 'woodev_modal_closed' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );

		modal.open();
		modal.destroy();

		expect( before ).toHaveLength( 0 );
		expect( closed ).toHaveLength( 0 );
		expect( document.querySelector( '.woodev-modal' ) ).toBeNull();
	} );

	it( 'one dispatch reaches BOTH addEventListener and jQuery .on() — the D-14 bridge', () => {
		// Native delivery alone is already covered by other tests; what D-14 actually claims —
		// and the reason the event is a native CustomEvent rather than a jQuery.trigger() — is
		// that a SINGLE dispatch reaches both mechanisms at once. Assert both from one open().
		const nativeSeen = listen( 'woodev_modal_opened' );

		const jqueryCalls = [];
		const $body = window.jQuery( document.body );
		$body.on( 'woodev_modal_opened', () => jqueryCalls.push( 1 ) );

		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		modal.open();

		expect( nativeSeen ).toHaveLength( 1 );
		expect( jqueryCalls ).toHaveLength( 1 );

		$body.off( 'woodev_modal_opened' ); // jQuery's own binding isn't tracked by onBody()
		modal.destroy();
	} );
} );
