/**
 * The bulk toolbar: one button per bulk action PHP declared for this user. A selection action waits for a
 * selection; an account-wide action does not need one, and its dialog says how far it reaches before anything runs.
 *
 * When the page has an activity part, a bulk action is also off — with the reason written beside it — while the
 * account line blocks it or a selected row's lock does.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { accountBlockedReason, createActivityState, useActivityState, type ActivityExtensions } from '../activity';
import type { ActivityHealth } from '../activity';
import type { SelectionHandle, TableHandle } from '../extensions';
import type { ActionDescriptor } from '../types';
import { STYLES } from './styles';
import type { ActionFlow } from './use-action-flow';

export interface BulkToolbarProps {
	flow: ActionFlow;
	selection: SelectionHandle;
	table: TableHandle;
	/** The page's activity part; without it nothing is ever blocked here. */
	activity?: ActivityExtensions;
}

/** Stands in for the account line when the page has no activity part. */
const NO_ACTIVITY = createActivityState();

/**
 * Why a bulk action cannot run now: the account line blocks it, or a selected row's lock does. Visible text from
 * PHP. Only top-level rows are known to the handle; PHP still refuses a blocked child on apply.
 */
function blockedReason(
	action: ActionDescriptor,
	selectedIds: string[],
	table: TableHandle,
	health: ActivityHealth
): string {
	const account = accountBlockedReason( health, action.id );
	if ( account !== '' ) {
		return account;
	}

	const locked = table.rows.find(
		( row ) => selectedIds.includes( row.id ) && row.activity?.blocked_actions.includes( action.id )
	);

	return locked?.activity?.reason ?? '';
}

export function BulkToolbar( { flow, selection, table, activity }: BulkToolbarProps ) {
	const health = useActivityState( activity?.state ?? NO_ACTIVITY );
	// A link is plain navigation; it has no bulk form.
	const actions = table.definition.bulk_actions.filter( ( action ) => action.interaction !== 'link' );
	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__bulk" role="toolbar" style={ STYLES.bulk }>
			{ actions.map( ( action ) => {
				const idle = action.scope === 'selection' && selection.selectedIds.length === 0;
				const reason = blockedReason( action, selection.selectedIds, table, health );
				const reasonId = `woodev-records-bulk-reason-${ action.id }`;

				return (
					<Fragment key={ action.id }>
						<Button
							variant="secondary"
							isDestructive={ action.tone === 'danger' }
							disabled={ idle || reason !== '' }
							accessibleWhenDisabled
							aria-describedby={ reason === '' ? undefined : reasonId }
							data-action={ action.id }
							onClick={ () =>
								flow.begin( action, action.scope === 'account' ? [] : selection.selectedIds, '', table, {
									onApplied: selection.clear,
								} )
							}
						>
							{ action.label }
						</Button>
						{ reason !== '' && (
							<span id={ reasonId } className="woodev-records__reason" style={ STYLES.reason }>
								{ reason }
							</span>
						) }
					</Fragment>
				);
			} ) }
		</div>
	);
}
