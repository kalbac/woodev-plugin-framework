/**
 * The shared dialog of the plain-PHP marketplace screens (`woodev-dialog.js`): the parts it builds, how it opens and closes,
 * and how it wires a dialog that PHP printed.
 */

const WoodevDialog = require( '../../woodev/marketplace/assets/js/admin/woodev-dialog.js' );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'WoodevDialog.build', () => {
	test( 'builds the header with the title and «✕», the body and, when asked, the footer, in that order', () => {
		const handle = WoodevDialog.build( { title: 'Проверка', closeLabel: 'Закрыть', footer: true } );
		const parts  = Array.from( handle.element.children ).map( ( part ) => part.className );

		expect( handle.element.tagName ).toBe( 'DIALOG' );
		expect( handle.element.className ).toBe( 'woodev-dialog' );
		expect( parts ).toEqual( [ 'woodev-dialog__header', 'woodev-dialog__body', 'woodev-dialog__footer' ] );
		expect( handle.title.textContent ).toBe( 'Проверка' );
		expect( handle.element.querySelector( '.woodev-dialog__header' ).contains( handle.title ) ).toBe( true );
		expect( handle.element.querySelector( '.woodev-dialog__close' ).getAttribute( 'aria-label' ) ).toBe( 'Закрыть' );
	} );

	test( 'has no footer unless asked for one', () => {
		const handle = WoodevDialog.build( { title: 'T' } );

		expect( handle.footer ).toBeNull();
		expect( handle.element.querySelector( '.woodev-dialog__footer' ) ).toBeNull();
	} );

	test( 'names the dialog by its title, for a screen reader', () => {
		const handle = WoodevDialog.build( { title: 'T' } );

		expect( handle.element.getAttribute( 'aria-labelledby' ) ).toBe( handle.title.id );
	} );

	test( 'two dialogs never share a title id', () => {
		expect( WoodevDialog.build( {} ).title.id ).not.toBe( WoodevDialog.build( {} ).title.id );
	} );

	test( 'writes the title as text, never as markup', () => {
		const handle = WoodevDialog.build( { title: '<img src=x onerror=alert(1)>' } );

		expect( handle.title.querySelector( 'img' ) ).toBeNull();
		expect( handle.title.textContent ).toBe( '<img src=x onerror=alert(1)>' );
	} );
} );

describe( 'opening and closing', () => {
	test( 'open() shows the dialog as a modal where the browser has one, and close() hides it', () => {
		const handle = WoodevDialog.build( { title: 'T' } );

		handle.element.showModal = jest.fn( () => handle.element.setAttribute( 'open', '' ) );
		handle.element.close     = jest.fn( () => handle.element.removeAttribute( 'open' ) );

		handle.open();
		expect( handle.element.showModal ).toHaveBeenCalledTimes( 1 );
		expect( handle.element.open ).toBe( true );

		handle.open(); // already open: showModal() would throw.
		expect( handle.element.showModal ).toHaveBeenCalledTimes( 1 );

		handle.close();
		expect( handle.element.open ).toBe( false );
	} );

	test( 'falls back to the open attribute where the element has no showModal()', () => {
		const handle = WoodevDialog.build( { title: 'T' } );

		handle.element.showModal = undefined;
		handle.element.close     = undefined;

		handle.open();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( true );

		handle.close();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( false );
	} );

	test( 'the «✕» and any [data-woodev-dialog-close] inside close it; a click on the content does not', () => {
		const handle = WoodevDialog.build( { title: 'T', footer: true } );
		const extra  = document.createElement( 'button' );

		extra.setAttribute( 'data-woodev-dialog-close', '' );
		handle.footer.appendChild( extra );
		handle.body.textContent = 'text';

		handle.open();
		handle.body.click();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( true );

		handle.element.querySelector( '.woodev-dialog__close' ).click();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( false );

		handle.open();
		extra.click();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( false );
	} );

	test( 'a click on the backdrop (the dialog element itself) closes it', () => {
		const handle = WoodevDialog.build( { title: 'T' } );

		handle.open();
		handle.element.click();

		expect( handle.element.hasAttribute( 'open' ) ).toBe( false );
	} );
} );

describe( 'WoodevDialog.attach', () => {
	test( 'wires a dialog that PHP printed and finds its parts', () => {
		document.body.innerHTML = `
			<dialog class="woodev-dialog">
				<div class="woodev-dialog__header"><h2 class="woodev-dialog__title">T</h2>
					<button type="button" class="woodev-dialog__close" data-woodev-dialog-close></button></div>
				<div class="woodev-dialog__body">Body</div>
				<div class="woodev-dialog__footer"><button type="button" data-woodev-dialog-close>Нет</button></div>
			</dialog>`;
		const handle = WoodevDialog.attach( document.querySelector( 'dialog' ) );

		expect( handle.title.textContent ).toBe( 'T' );
		expect( handle.body.textContent ).toBe( 'Body' );
		expect( handle.footer ).not.toBeNull();

		handle.open();
		document.querySelector( '.woodev-dialog__footer button' ).click();
		expect( handle.element.hasAttribute( 'open' ) ).toBe( false );
	} );
} );
