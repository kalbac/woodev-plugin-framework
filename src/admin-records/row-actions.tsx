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
	return (
		<>
			<Button
				size="small"
				variant={ action.tone === 'primary' ? 'primary' : 'secondary' }
				isDestructive={ action.tone === 'danger' }
				disabled={ ! canRun }
				href={ canRun ? href : undefined }
				accessibleWhenDisabled
				aria-describedby={ reason === '' ? undefined : reasonId }
				data-action={ action.id }
				onClick={ onClick }
			>
				{ action.label }
			</Button>
			{ reason !== '' && (
				<span id={ reasonId } className="woodev-records__reason" style={ STYLES.reason }>
					{ reason }
				</span>
			) }
		</>
	);
}

export function RowActions( { row, rowKey, descriptors, onAction, blockedReason, children }: RowActionsProps ) {
	const describe = ( state: RowActionState ) => descriptors.find( ( action ) => action.id === state.id );
	const reasonFor = ( action: ActionDescriptor, state: RowActionState ) =>
		state.enabled ? blockedReason?.( action, row ) ?? '' : state.reason;

	return (
		<span className="woodev-records__actions" style={ STYLES.actions }>
			{ row.actions.map( ( state ) => {
				const action = describe( state );
				if ( ! action ) {
					// PHP dropped the descriptor: the user lacks the action's capability.
					return null;
				}

				const reason = reasonFor( action, state );
				const isLink = action.interaction === 'link';
				if ( isLink && ! state.url ) {
					// A link needs a destination; without one there is nothing to follow and nothing to run.
					return null;
				}

				return (
					<ActionButton
						key={ action.id }
						action={ action }
						reason={ reason }
						reasonId={ `woodev-records-reason-${ rowKey }-${ action.id }` }
						canRun={ reason === '' && ( isLink || onAction !== undefined ) }
						href={ isLink ? state.url : undefined }
						onClick={ () => ( isLink ? undefined : onAction?.( action, row ) ) }
					/>
				);
			} ) }
			{ children }
		</span>
	);
}
