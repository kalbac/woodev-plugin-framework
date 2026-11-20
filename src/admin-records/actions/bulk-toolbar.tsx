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
import { MESSAGE_KEYS, fillMessage, message } from '../messages';
import type { ActionDescriptor } from '../types';
import type { ActionFlow } from './use-action-flow';

export interface BulkToolbarProps {
	flow: ActionFlow;
	selection: SelectionHandle;
	table: TableHandle;
	/** The page's activity part; without it nothing is ever blocked here. */
	activity?: ActivityExtensions;
}

export interface AccountActionsProps extends BulkToolbarProps {
	/** Only the actions of these ids (the empty table offers one of them in its own body); every account action when absent. */
	only?: string[];
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
 * «Выбрано: 3» for the ticked rows: empty while none is ticked or the whole filter is taken (that has its own bar below),
 * `undefined` when PHP sent no such sentence.
 *
 * @param table     The table on screen.
 * @param selection The selection.
 */
function selectedText( table: TableHandle, selection: SelectionHandle ): string | undefined {
	const sentence = message( table.messages, MESSAGE_KEYS.selectedCount );

	if ( sentence === '' ) {
		return undefined;
	}

	return selection.matching === null && selection.selectedIds.length > 0
		? fillMessage( sentence, { count: String( selection.selectedIds.length ) } )
		: '';
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
			actions={ actions.map( ( entry ) => ( { value: entry.id, label: entry.label, group: entry.group } ) ) }
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
				// «Every row under the filter» has its own bar below; the count here is for ticked rows.
				// `undefined` when the table has no such sentence: nothing is reserved for it. With one, the counter's place is
				// kept while nothing is ticked, so the buttons after it do not jump when the first row is.
				selected: selectedText( table, selection ),
			} }
		/>
	);
}

/**
 * The account-wide actions as a row of buttons: at the right end of the filter row, where the orders page puts its «Статус данных».
 *
 * A blocked one says why in its tooltip and, for assistive technology, in a hidden sentence: the account line above the table
 * already says the same in words, and a sentence written beside a button here once pushed the toolbar apart.
 */
export function AccountActions( { flow, selection, table, activity, only }: AccountActionsProps ) {
	const health = useActivityState( activity?.state ?? NO_ACTIVITY );
	const actions = formActions( table, 'account' ).filter( ( action ) => only === undefined || only.includes( action.id ) );

	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__account-actions">
			{ actions.map( ( action ) => {
				const reason = blockedReason( action, selection.selectedIds, table, health );
				const reasonId = `woodev-records-bulk-reason-${ action.id }`;
				// The click has started it and the answer is not back yet: the button says so, and cannot be clicked twice.
				const starting = flow.state?.action.id === action.id && flow.state.stage === 'applying';

				return (
					<Fragment key={ action.id }>
						<Button
							__next40pxDefaultSize
							variant={ action.tone === 'primary' ? 'primary' : 'secondary' }
							isDestructive={ action.tone === 'danger' }
							disabled={ reason !== '' || starting }
							isBusy={ starting }
							accessibleWhenDisabled
							aria-describedby={ reason === '' ? undefined : reasonId }
							title={ reason === '' ? undefined : reason }
							data-action={ action.id }
							onClick={ () => flow.begin( action, [], '', table, { onApplied: selection.clear } ) }
						>
							{ action.label }
						</Button>
						{ reason !== '' && (
							<span id={ reasonId } className="screen-reader-text">
								{ reason }
							</span>
						) }
					</Fragment>
				);
			} ) }
		</div>
	);
}
