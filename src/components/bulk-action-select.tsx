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
	/** Heading the option is listed under; options with none come first, unheaded. */
	group?: string;
}

export interface BulkActionLabels {
	/** The empty entry of the select: an instruction, not the control's name. */
	placeholder: string;
	/** The button. */
	apply: string;
	/** The select's accessible name (the label is not drawn). */
	label: string;
	/** Written beside the group while rows are ticked («Выбрано: 3»); empty while none is, and `undefined` when there is no such sentence. */
	selected?: string;
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

/**
 * The options, with a heading (`<optgroup>`) per group in the order the groups first appear. `SelectControl` takes option
 * elements as `children` in every WordPress that ships it, which `options` has no way to express.
 *
 * @param actions The actions, in the order PHP declared them.
 * @param placeholder The empty entry.
 */
function renderOptions( actions: BulkActionOption[], placeholder: string ) {
	const groups: string[] = [];

	actions.forEach( ( action ) => {
		if ( action.group && ! groups.includes( action.group ) ) {
			groups.push( action.group );
		}
	} );

	const option = ( entry: BulkActionOption ) => (
		<option key={ entry.value } value={ entry.value }>
			{ entry.label }
		</option>
	);

	return (
		<>
			<option value="">{ placeholder }</option>
			{ actions.filter( ( entry ) => ! entry.group ).map( option ) }
			{ groups.map( ( group ) => (
				<optgroup key={ group } label={ group }>
					{ actions.filter( ( entry ) => entry.group === group ).map( option ) }
				</optgroup>
			) ) }
		</>
	);
}

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
					onChange={ ( next: string ) => onChange( next ) }
				>
					{ renderOptions( actions, labels.placeholder ) }
				</SelectControl>
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
			{ labels.selected !== undefined && (
				<span role="status" className="woodev-table-bulk__selected">
					{ labels.selected }
				</span>
			) }
			{ blocked && (
				<span id={ REASON_ID } className="woodev-table-bulk__reason">
					{ disabledReason }
				</span>
			) }
		</div>
	);
}
