/**
 * The bulk-action control of a table card: a select with a button, drawn as one bordered group. It is the control of
 * «Заказы доставки» (`.woodev-orders-bulk`) as a component that holds no state and no words of its own: the caller
 * passes the actions, the chosen one and every visible string.
 *
 * «Применить» is off while nothing is chosen, while the caller says the choice cannot run now (`disabled`: nothing is
 * selected) and while it is blocked (`disabledReason`, which is also written beside the group and described to
 * assistive technology). A disabled button stays focusable, so the reason can be reached.
 *
 * @package woodev-plugin-framework
 */

import { Button, SelectControl } from '@wordpress/components';

export interface BulkActionOption {
	value: string;
	label: string;
}

export interface BulkActionLabels {
	/** The empty entry of the select: an instruction, not the control's name. */
	placeholder: string;
	/** The button. */
	apply: string;
	/** The select's accessible name (the label is not drawn). */
	label: string;
}

export interface BulkActionSelectProps {
	actions: BulkActionOption[];
	/** The chosen action's value; empty for none. */
	value: string;
	onChange: ( value: string ) => void;
	onApply: () => void;
	/** The choice cannot run yet — for a selection action, nothing is selected. */
	disabled?: boolean;
	/** Why the chosen action is blocked; empty when it is not. */
	disabledReason?: string;
	labels: BulkActionLabels;
}

const REASON_ID = 'woodev-table-bulk-reason';

export function BulkActionSelect( {
	actions,
	value,
	onChange,
	onApply,
	disabled = false,
	disabledReason = '',
	labels,
}: BulkActionSelectProps ) {
	const blocked = disabledReason !== '';

	return (
		<div className="woodev-table-bulk-row">
			<div className="woodev-table-bulk">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ labels.label }
					value={ value }
					options={ [ { value: '', label: labels.placeholder }, ...actions ] }
					onChange={ ( next: string ) => onChange( next ) }
				/>
				<Button
					className="woodev-table-bulk__apply"
					variant="secondary"
					disabled={ value === '' || disabled || blocked }
					accessibleWhenDisabled
					aria-describedby={ blocked ? REASON_ID : undefined }
					onClick={ onApply }
				>
					{ labels.apply }
				</Button>
			</div>
			{ blocked && (
				<span id={ REASON_ID } className="woodev-table-bulk__reason">
					{ disabledReason }
				</span>
			) }
		</div>
	);
}
