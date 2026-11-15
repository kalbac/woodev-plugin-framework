/**
 * Finding the control that runs a bulk action on a records table: an account-wide action is a button of its own above
 * the filters, a selection action is a choice in the card header's select followed by «Применить».
 */

import { fireEvent, screen, within } from '@testing-library/react';

/** The button of an account-wide action. */
export function accountButton( name: string ): HTMLElement {
	const row = document.querySelector( '.woodev-records__account-actions' ) as HTMLElement | null;

	if ( ! row ) {
		throw new Error( 'The table has no account actions.' );
	}

	return within( row ).getByRole( 'button', { name } );
}

/** Chooses a selection action in the bulk select and returns «Применить», the button that runs it. */
export function chooseBulk( name: string ): HTMLElement {
	const select = screen.getByRole( 'combobox', { name: 'Массовые действия' } );
	const option = within( select ).getByRole( 'option', { name } ) as HTMLOptionElement;

	fireEvent.change( select, { target: { value: option.value } } );

	return screen.getByRole( 'button', { name: 'Применить' } );
}

/** The button that runs the named bulk action, whichever kind it is. */
export function bulkButton( name: string ): HTMLElement {
	const row = document.querySelector( '.woodev-records__account-actions' );
	const isAccountAction = row !== null && within( row as HTMLElement ).queryByRole( 'button', { name } ) !== null;

	return isAccountAction ? accountButton( name ) : chooseBulk( name );
}
