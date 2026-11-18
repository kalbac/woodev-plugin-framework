/**
 * A row's actions cell: one button per action PHP listed for the row, labelled and toned by the definition.
 *
 * A disabled action stays focusable and says why in visible text tied to the button — the reason is PHP's (the row
 * state) or the activity part's (a lock). Activation is handed to the action part; the shell runs nothing itself.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { Button } from '@wordpress/components';
import { STYLES } from './styles';
import type { ActionDescriptor, AdminRecord, RowActionState } from './types';

export interface RowActionsProps {
	row: AdminRecord;
	/** Unique on screen; a child row's key includes its parent. */
	rowKey: string;
	descriptors: ActionDescriptor[];
	/** Runs an action; when absent every button is disabled. */
	onAction?: ( action: ActionDescriptor, row: AdminRecord ) => void;
	/** Why an enabled action cannot run right now; empty when it can. */
	blockedReason?: ( action: ActionDescriptor, row: AdminRecord ) => string;
	/** Extra content of the cell (the row's activity). */
	children?: ReactNode;
}

interface ActionButtonProps {
	action: ActionDescriptor;
	reason: string;
	reasonId: string;
	canRun: boolean;
	/** Set for a `link` action that can run: the button is then an ordinary link to this address. */
	href?: string;
	onClick: () => void;
}

function ActionButton( { action, reason, reasonId, canRun, href, onClick }: ActionButtonProps ) {
	// A short label keeps the buttons of a row on one line; the full one stays the accessible name and the tooltip.
	const short = action.short_label !== undefined && action.short_label !== '' && action.short_label !== action.label;

	return (
		<Button
			size="small"
			variant={ action.tone === 'primary' ? 'primary' : 'secondary' }
			isDestructive={ action.tone === 'danger' }
			disabled={ ! canRun }
			href={ canRun ? href : undefined }
			accessibleWhenDisabled
			aria-describedby={ reason === '' ? undefined : reasonId }
			aria-label={ short ? action.label : undefined }
			title={ short ? action.label : undefined }
			data-action={ action.id }
			onClick={ onClick }
		>
			{ short ? action.short_label : action.label }
		</Button>
	);
}

interface RowButton {
	action: ActionDescriptor;
	state: RowActionState;
	reason: string;
	isLink: boolean;
}

export function RowActions( { row, rowKey, descriptors, onAction, blockedReason, children }: RowActionsProps ) {
	const describe = ( state: RowActionState ) => descriptors.find( ( action ) => action.id === state.id );
	const reasonFor = ( action: ActionDescriptor, state: RowActionState ) =>
		state.enabled ? blockedReason?.( action, row ) ?? '' : state.reason;
	const reasonId = ( action: ActionDescriptor ) => `woodev-records-reason-${ rowKey }-${ action.id }`;

	const buttons: RowButton[] = [];
	row.actions.forEach( ( state ) => {
		const action = describe( state );
		// PHP dropped the descriptor when the user lacks the action's capability.
		if ( ! action ) {
			return;
		}

		const isLink = action.interaction === 'link';
		// A link needs a destination; without one there is nothing to follow and nothing to run.
		if ( isLink && ! state.url ) {
			return;
		}

		buttons.push( { action, state, reason: reasonFor( action, state ), isLink } );
	} );

	return (
		<span className="woodev-records__actions" style={ STYLES.actions }>
			<span className="woodev-records__buttons" style={ STYLES.buttons }>
				{ buttons.map( ( { action, state, reason, isLink } ) => (
					<ActionButton
						key={ action.id }
						action={ action }
						reason={ reason }
						reasonId={ reasonId( action ) }
						canRun={ reason === '' && ( isLink || onAction !== undefined ) }
						href={ isLink ? state.url : undefined }
						onClick={ () => ( isLink ? undefined : onAction?.( action, row ) ) }
					/>
				) ) }
			</span>
			{ buttons
				.filter( ( { reason } ) => reason !== '' )
				.map( ( { action, reason } ) => (
					<span key={ action.id } id={ reasonId( action ) } className="woodev-records__reason" style={ STYLES.reason }>
						{ reason }
					</span>
				) ) }
			{ children }
		</span>
	);
}
