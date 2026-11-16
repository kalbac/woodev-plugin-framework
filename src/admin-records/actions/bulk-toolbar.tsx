/**
 * The bulk actions PHP declared for this user, in two places. A selection action waits for a selection and sits in the
 * table card's header as a select with «Применить» ({@link BulkToolbar}); an account-wide action does not need one and
 * is a button above the filters ({@link AccountActions}), and its dialog says how far it reaches before anything runs.
 *
 * When the page has an activity part, a bulk action is also off — with the reason written beside it — while the
 * account line blocks it or a selected row's lock does. A selected row is a top-level row or an expanded child (a
 * variation); the refresh loop keeps both fresh, so the button follows a lock that appears after the row was ticked.
 * A parent's own lock only says that every variation is blocked; a selected expanded child adds its own.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { Fragment, useState } from '@wordpress/element';
import { BulkActionSelect } from '../../components/bulk-action-select';
import { accountBlockedReason, createActivityState, useActivityState, type ActivityExtensions } from '../activity';
import type { ActivityHealth } from '../activity';
import type { SelectionHandle, TableHandle } from '../extensions';
import { MESSAGE_KEYS, message } from '../messages';
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
 * Why a bulk action cannot run now: the account line blocks it, or a selected row's lock does. Visible text from PHP.
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

	const locked = [ ...table.rows, ...table.children ].find(
		( row ) => selectedIds.includes( row.id ) && row.activity?.blocked_actions.includes( action.id )
	);

	return locked?.activity?.reason ?? '';
}

/**
 * A link is plain navigation; it has no bulk form. An account action that needs a hidden value has no standalone button
 * either: only the account line, which holds the value, can run it.
 */
function formActions( table: TableHandle, scope: ActionDescriptor[ 'scope' ] ): ActionDescriptor[] {
	return table.definition.bulk_actions.filter(
		( action ) =>
			action.interaction !== 'link' &&
			action.scope === scope &&
			! ( scope === 'account' && action.fields.some( ( field ) => field.type === 'hidden' && field.required ) )
	);
}

/**
 * The select of the selection actions, for the table card's header. While «every row under the filter» is on, only the
 * actions that declared `all_matching` are listed: the others have no way to reach rows that are not on screen.
 */
export function BulkToolbar( { flow, selection, table, activity }: BulkToolbarProps ) {
	const health = useActivityState( activity?.state ?? NO_ACTIVITY );
	const [ chosen, setChosen ] = useState( '' );
	const actions = formActions( table, 'selection' ).filter( ( entry ) => selection.matching === null || entry.all_matching === true );
	const action = actions.find( ( candidate ) => candidate.id === chosen );

	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<BulkActionSelect
			actions={ actions.map( ( entry ) => ( { value: entry.id, label: entry.label } ) ) }
			value={ action ? action.id : '' }
			onChange={ setChosen }
			onApply={ () =>
				action &&
				( selection.matching
					? flow.begin( action, [], '', table, { onApplied: selection.clear, matching: selection.matching } )
					: flow.begin( action, selection.selectedIds, '', table, { onApplied: selection.clear } ) )
			}
			disabled={ selection.selectedIds.length === 0 && selection.matching === null }
			disabledReason={ action ? blockedReason( action, selection.selectedIds, table, health ) : '' }
			labels={ {
				placeholder: message( table.messages, MESSAGE_KEYS.bulkPlaceholder ),
				apply: message( table.messages, MESSAGE_KEYS.bulkApply ),
				label: message( table.messages, MESSAGE_KEYS.bulkLabel ),
			} }
		/>
	);
}

/** The account-wide actions, as secondary buttons above the filters; a blocked one says why under the row. */
export function AccountActions( { flow, selection, table, activity }: BulkToolbarProps ) {
	const health = useActivityState( activity?.state ?? NO_ACTIVITY );
	const actions = formActions( table, 'account' );

	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__account-actions" style={ STYLES.bulk }>
			{ actions.map( ( action ) => {
				const reason = blockedReason( action, selection.selectedIds, table, health );
				const reasonId = `woodev-records-bulk-reason-${ action.id }`;

				return (
					<Fragment key={ action.id }>
						<Button
							variant="secondary"
							isDestructive={ action.tone === 'danger' }
							disabled={ reason !== '' }
							accessibleWhenDisabled
							aria-describedby={ reason === '' ? undefined : reasonId }
							data-action={ action.id }
							onClick={ () => flow.begin( action, [], '', table, { onApplied: selection.clear } ) }
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
