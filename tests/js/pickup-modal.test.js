/**
 * Tests for pickup-modal.js
 *
 * Covers the vanilla modal shell's aria contract, focus management, scroll
 * lock, and error/empty degradation states — SP-5 Task 10.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-modal.js
 */

'use strict';

const WoodevPickupModal = require( '../../woodev/shipping-method/assets/js/frontend/pickup-modal' );

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
	const modal = new WoodevPickupModal( { title: 'Пункты выдачи' } );
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

test( 'Escape closes it', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();
	expect( document.querySelector( '[role="dialog"]' ) ).not.toBeNull();

	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	modal.destroy();
} );

test( 'focus returns to the trigger on close', () => {
	const trigger = makeTrigger();
	const modal = new WoodevPickupModal( { title: 'T', returnFocusTo: trigger } );

	modal.open();
	expect( document.activeElement ).not.toBe( trigger );

	modal.close();
	expect( document.activeElement ).toBe( trigger );

	modal.destroy();
} );

test( 'getContainer() gives the provider its mount point', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();
	modal.showEmpty( 'Пункты выдачи не найдены' );

	const body = modal.getContainer();
	expect( body.textContent ).toContain( 'Пункты выдачи не найдены' );
	// No retry control for an empty state — there is nothing to retry.
	expect( body.querySelector( 'button' ) ).toBeNull();

	modal.destroy();
} );

test( 'showNotice() renders a banner ALONGSIDE the body, never replacing its content', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
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

	const notice = dialog.querySelector( '.woodev-pickup-modal__notice' );
	expect( notice ).not.toBeNull();
	expect( container.contains( notice ) ).toBe( false ); // sibling of the body, not a child

	modal.destroy();
} );

test( 'showNotice() with no onRetry renders no retry control (the empty-after-drawn case)', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();
	modal.showNotice( 'Пункты выдачи не найдены в этой области' );

	const notice = document.querySelector( '.woodev-pickup-modal__notice' );
	expect( notice.querySelector( '.woodev-pickup-modal__notice-retry' ) ).toBeNull();
	// Still has its own dismiss control.
	expect( notice.querySelector( '.woodev-pickup-modal__notice-dismiss' ) ).not.toBeNull();

	modal.destroy();
} );

test( 'showNotice() retry control invokes the callback', () => {
	const modal = new WoodevPickupModal( { title: 'T', retryLabel: 'Повторить' } );
	modal.open();

	const onRetry = jest.fn();
	modal.showNotice( 'Ошибка обновления', onRetry );

	const retryBtn = document.querySelector( '.woodev-pickup-modal__notice-retry' );
	expect( retryBtn ).not.toBeNull();
	expect( retryBtn.textContent ).toBe( 'Повторить' );

	retryBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( onRetry ).toHaveBeenCalledTimes( 1 );

	modal.destroy();
} );

test( 'showNotice() dismiss control removes the banner without touching the body', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	const container = modal.getContainer();
	container.appendChild( document.createElement( 'div' ) );

	modal.open();
	modal.showNotice( 'Что-то пошло не так' );

	const dismissBtn = document.querySelector( '.woodev-pickup-modal__notice-dismiss' );
	dismissBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( document.querySelector( '.woodev-pickup-modal__notice' ) ).toBeNull();
	expect( container.children.length ).toBe( 1 ); // the pre-existing content is untouched

	modal.destroy();
} );

test( 'showNotice() called twice replaces the first banner rather than stacking a second', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();

	modal.showNotice( 'Первое сообщение' );
	modal.showNotice( 'Второе сообщение' );

	const notices = document.querySelectorAll( '.woodev-pickup-modal__notice' );
	expect( notices.length ).toBe( 1 );
	expect( notices[ 0 ].textContent ).toContain( 'Второе сообщение' );

	modal.destroy();
} );

test( 'showError() retry control invokes the callback', () => {
	const modal = new WoodevPickupModal( { title: 'T', retryLabel: 'Повторить' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
	expect( document.body.className ).not.toMatch( /woodev-pickup-modal-lock/ );

	modal.open();
	expect( document.body.className ).toMatch( /woodev-pickup-modal-lock/ );

	modal.close();
	expect( document.body.className ).not.toMatch( /woodev-pickup-modal-lock/ );

	modal.destroy();
} );

test( 'open() twice does not produce two dialogs', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();
	modal.open();

	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	modal.destroy();
} );

test( 'destroy() removes the node and every listener it owns', () => {
	const trigger = makeTrigger();
	const modal = new WoodevPickupModal( { title: 'T', returnFocusTo: trigger } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	const backdrop = dialog.parentNode;

	modal.destroy();

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	expect( document.body.contains( backdrop ) ).toBe( false );
	expect( document.body.className ).not.toMatch( /woodev-pickup-modal-lock/ );

	// A subsequent Escape must be a no-op — no document-level listener survives.
	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 0 );

	// A subsequent backdrop click must be a no-op too (the node is detached
	// but dispatch still works against a detached EventTarget).
	backdrop.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	expect( document.body.contains( backdrop ) ).toBe( false );
} );

test( 'destroy() removes the close-button click listener too', () => {
	const modal = new WoodevPickupModal( { title: 'T' } );
	modal.open();

	const closeButton = document.querySelector( '.woodev-pickup-modal__close' );
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
	const modal = new WoodevPickupModal( { title: 'T' } );
	expect( () => modal.close() ).not.toThrow();
	modal.destroy();
} );

test( 'title text is escaped as text, not injected as markup', () => {
	const modal = new WoodevPickupModal( { title: '<img src=x onerror=alert(1)>' } );
	modal.open();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( 'img' ) ).toBeNull();

	modal.destroy();
} );
