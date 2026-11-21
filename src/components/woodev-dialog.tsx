/**
 * The one dialog of the React screens of the marketplace admin.
 *
 * A `@wordpress/components` Modal brings the overlay, the focus trap, the focus return and Esc; this wrapper draws everything
 * inside it the same way every time — a header with the title and «✕», the body, an optional footer for the buttons — so no screen
 * builds a dialog of its own. The look is `woodev/marketplace/assets/css/admin/woodev-dialog.css`, the same file the plain-PHP
 * screens' `WoodevDialog` (`woodev/marketplace/assets/js/admin/woodev-dialog.js`) draws with; the SCSS of a bundle pulls it in:
 * `@use '../components/woodev-dialog';` (the partial `src/components/_woodev-dialog.scss`).
 *
 *     <WoodevDialog title="…" onRequestClose={ close } footer={ <Button variant="primary">…</Button> }>
 *         …the content…
 *     </WoodevDialog>
 *
 * `dismissible={ false }` is for a request in flight: no «✕», no Esc, no click outside.
 *
 * @package woodev-plugin-framework
 */

import { Modal } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export interface WoodevDialogProps {
	/** The title, shown in the header and read as the dialog's name. */
	title: string;
	/** Called by «✕», Esc and a click outside (the last one only when `shouldCloseOnClickOutside` is on). */
	onRequestClose: () => void;
	/** The buttons, laid out on the right of the footer. No footer is drawn without them. */
	footer?: ReactNode;
	/** False while a request runs: the dialog cannot be dismissed. Default true. */
	dismissible?: boolean;
	/** Whether a click on the overlay closes the dialog. Default false: a form must not be lost by a slip. */
	shouldCloseOnClickOutside?: boolean;
	/** The width, as `@wordpress/components` names it. Default `medium`. */
	size?: 'small' | 'medium' | 'large';
	/** An extra class of the frame, for the content's own rules. */
	className?: string;
	children: ReactNode;
}

export function WoodevDialog( {
	title,
	onRequestClose,
	footer,
	dismissible = true,
	shouldCloseOnClickOutside = false,
	size = 'medium',
	className,
	children,
}: WoodevDialogProps ) {
	const titleId = useId();

	return (
		<Modal
			// No `title` on the Modal: it would name the dialog by a heading of its own header, which is hidden. Ours names it.
			__experimentalHideHeader
			aria={ { labelledby: titleId } }
			overlayClassName="woodev-dialog-overlay"
			className={ [ 'woodev-dialog', className ].filter( Boolean ).join( ' ' ) }
			onRequestClose={ onRequestClose }
			shouldCloseOnClickOutside={ dismissible && shouldCloseOnClickOutside }
			shouldCloseOnEsc={ dismissible }
			isDismissible={ false }
			size={ size }
		>
			<div className="woodev-dialog__header">
				<h2 className="woodev-dialog__title" id={ titleId }>
					{ title }
				</h2>
				{ dismissible && (
					<button
						type="button"
						className="woodev-dialog__close"
						aria-label={ __( 'Закрыть', 'woodev-plugin-framework' ) }
						onClick={ onRequestClose }
					/>
				) }
			</div>
			<div className="woodev-dialog__body">{ children }</div>
			{ footer && <div className="woodev-dialog__footer">{ footer }</div> }
		</Modal>
	);
}
