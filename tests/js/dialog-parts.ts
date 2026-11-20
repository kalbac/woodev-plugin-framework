/**
 * Finding the parts of a `WoodevDialog` in a test. The header's «✕» and a footer button can carry the same name («Закрыть»),
 * so a test that means the footer button asks the footer.
 */

import { within } from '@testing-library/react';

/** The footer of a dialog, where its buttons are. */
export function footerOf( dialog: HTMLElement ): HTMLElement {
	const footer = dialog.querySelector( '.woodev-dialog__footer' ) as HTMLElement | null;

	if ( ! footer ) {
		throw new Error( 'The dialog has no footer.' );
	}

	return footer;
}

/** A button of the dialog's footer, by its name. */
export function footerButton( dialog: HTMLElement, name: string ): HTMLElement {
	return within( footerOf( dialog ) ).getByRole( 'button', { name } );
}
