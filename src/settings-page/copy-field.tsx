/**
 * A value the merchant copies somewhere else — the address a marketplace sends notifications to, say — in a read-only field
 * with a «Копировать» button (`Settings_Section::with_copy_field()`).
 *
 * The field selects its text on focus, so where the browser blocks clipboard access (a plain-http admin) the merchant can
 * still copy with the keyboard; the button then says so instead of claiming success.
 *
 * @package woodev-plugin-framework
 */

import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/** `Settings_Section::get_copy_field()` as the registry serialises it. */
export interface CopyFieldSchema {
	label: string;
	value: string;
}

type Outcome = '' | 'copied' | 'manual';

/**
 * @param {{field: CopyFieldSchema}} props the field to show.
 */
export default function CopyField( { field }: { field: CopyFieldSchema } ) {
	const input = useRef< HTMLInputElement >( null );
	const [ outcome, setOutcome ] = useState< Outcome >( '' );

	const copy = () => {
		const node = input.current;

		if ( node ) {
			node.focus();
			node.select();
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( field.value ).then(
				() => setOutcome( 'copied' ),
				() => setOutcome( 'manual' )
			);
			return;
		}

		setOutcome( 'manual' );
	};

	return (
		<dl className="woodev-facts woodev-copy-field">
			<dt>{ field.label }</dt>
			<dd>
				<div className="woodev-copy-field__row">
					<input
						ref={ input }
						type="text"
						readOnly
						className="woodev-copy-field__input"
						value={ field.value }
						aria-label={ field.label }
						onFocus={ ( event ) => event.currentTarget.select() }
					/>
					<Button variant="secondary" onClick={ copy }>
						{ 'copied' === outcome ? __( 'Скопировано', 'woodev-plugin-framework' ) : __( 'Копировать', 'woodev-plugin-framework' ) }
					</Button>
				</div>
				{ 'manual' === outcome && (
					<p className="woodev-copy-field__note" role="status">
						{ __( 'Браузер не дал скопировать сам — адрес выделен, нажмите Ctrl+C.', 'woodev-plugin-framework' ) }
					</p>
				) }
			</dd>
		</dl>
	);
}
