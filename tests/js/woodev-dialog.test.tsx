/**
 * `WoodevDialog`, the one dialog of the React screens of the marketplace admin: its parts, its name, and the ways to close it.
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { WoodevDialog } from '../../src/components/woodev-dialog';
import { footerButton, footerOf } from './dialog-parts';

describe( 'WoodevDialog', () => {
	test( 'draws the title and «✕» in the header, the content in the body and the buttons in the footer', () => {
		render(
			<WoodevDialog title="Проверка товара" onRequestClose={ jest.fn() } footer={ <button type="button">Готово</button> }>
				<p>Содержимое</p>
			</WoodevDialog>
		);

		const dialog = screen.getByRole( 'dialog' );
		const header = dialog.querySelector( '.woodev-dialog__header' ) as HTMLElement;
		const body   = dialog.querySelector( '.woodev-dialog__body' ) as HTMLElement;

		expect( within( header ).getByRole( 'heading', { name: 'Проверка товара' } ) ).toBeInTheDocument();
		expect( within( header ).getByRole( 'button', { name: 'Закрыть' } ) ).toBeInTheDocument();
		expect( within( body ).getByText( 'Содержимое' ) ).toBeInTheDocument();
		expect( footerButton( dialog, 'Готово' ) ).toBeInTheDocument();
	} );

	test( 'is named by its title', () => {
		render( <WoodevDialog title="Удаление аккаунта" onRequestClose={ jest.fn() }>x</WoodevDialog> );

		expect( screen.getByRole( 'dialog', { name: 'Удаление аккаунта' } ) ).toBeInTheDocument();
	} );

	test( 'draws no footer without buttons', () => {
		render( <WoodevDialog title="T" onRequestClose={ jest.fn() }>x</WoodevDialog> );

		expect( () => footerOf( screen.getByRole( 'dialog' ) ) ).toThrow( 'no footer' );
	} );

	test( 'the overlay carries the class the shared stylesheet dims', () => {
		render( <WoodevDialog title="T" onRequestClose={ jest.fn() }>x</WoodevDialog> );

		expect( document.querySelector( '.components-modal__screen-overlay' ) ).toHaveClass( 'woodev-dialog-overlay' );
	} );

	test( 'the «✕» closes it', () => {
		const onRequestClose = jest.fn();
		render( <WoodevDialog title="T" onRequestClose={ onRequestClose }>x</WoodevDialog> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Закрыть' } ) );

		expect( onRequestClose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'Esc closes it', async () => {
		const onRequestClose = jest.fn();
		render( <WoodevDialog title="T" onRequestClose={ onRequestClose }>x</WoodevDialog> );

		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );

		// The Modal plays its exit animation first, then asks to close.
		await waitFor( () => expect( onRequestClose ).toHaveBeenCalledTimes( 1 ) );
	} );

	/** A press and release on the dark overlay, outside the frame. */
	function clickOutside() {
		const overlay = document.querySelector( '.components-modal__screen-overlay' ) as HTMLElement;

		// jsdom has no PointerEvent; a MouseEvent of the pointer's name carries the `button` the Modal reads.
		for ( const type of [ 'pointerdown', 'pointerup' ] ) {
			fireEvent( overlay, new MouseEvent( type, { bubbles: true, cancelable: true, button: 0 } ) );
		}
	}

	test( 'a click outside does not close it', async () => {
		const onRequestClose = jest.fn();
		render( <WoodevDialog title="T" onRequestClose={ onRequestClose }>x</WoodevDialog> );

		clickOutside();
		await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );

		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	test( 'a click outside closes it when asked to', async () => {
		const onRequestClose = jest.fn();
		render( <WoodevDialog title="T" onRequestClose={ onRequestClose } shouldCloseOnClickOutside>x</WoodevDialog> );

		clickOutside();

		await waitFor( () => expect( onRequestClose ).toHaveBeenCalledTimes( 1 ) );
	} );

	test( 'while it cannot be dismissed it has no «✕» and Esc does nothing', async () => {
		const onRequestClose = jest.fn();
		render( <WoodevDialog title="T" onRequestClose={ onRequestClose } dismissible={ false }>x</WoodevDialog> );

		expect( screen.queryByRole( 'button', { name: 'Закрыть' } ) ).toBeNull();
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
		await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	test( 'takes an extra class on the frame next to its own', () => {
		render( <WoodevDialog title="T" onRequestClose={ jest.fn() } className="woodev-account-delete">x</WoodevDialog> );

		expect( screen.getByRole( 'dialog' ) ).toHaveClass( 'woodev-dialog', 'woodev-account-delete' );
	} );
} );
