/**
 * The bulk toolbar: one button per bulk action PHP declared for this user. A selection action waits for a
 * selection; an account-wide action does not need one, and its dialog says how far it reaches before anything runs.
 *
 * When the page has an activity part, a bulk action is also off — with the reason written beside it — while the
 * account line blocks it or a selected row's lock does. A selected row is a top-level row or an expanded child (a
 * variation): the table handle lists only top-level rows, so the locks of selected children are read once, from the
 * activity route, whenever the selection or the list changes.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import {
	accountBlockedReason,
	createActivityState,
	useActivityState,
	type ActivityExtensions,
	type RowActivity,
} from '../activity';
import type { ActivityHealth } from '../activity';
import { readActivity } from '../activity/api';
import { isAbort } from '../api';
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

/** Row activity by id, for rows the table handle does not list. */
type ChildActivity = Record< string, RowActivity >;

const NO_CHILD_ACTIVITY: ChildActivity = {};

/** Most ids one activity read may carry when the definition states no limit. */
const DEFAULT_ID_LIMIT = 100;

/**
 * Activity of the selected rows that are not top-level rows: expanded children, such as the variations of a variable
 * product. The shell hands the toolbar top-level rows only; PHP refuses a blocked child on apply anyway, but a button that
 * says "go" for a row that cannot go is a lie, so the locks are read here.
 *
 * Reads are local and read-only (the same route the refresh loop uses), so repeating or aborting one is safe. A failed
 * read leaves the child unchecked — the server still decides on apply.
 *
 * @param selectedIds Selected row ids.
 * @param table       The table as it is on screen.
 * @param enabled     Whether the page has an activity part; without it nothing is read.
 */
function useChildActivity( selectedIds: string[], table: TableHandle, enabled: boolean ): ChildActivity {
	const [ known, setKnown ] = useState< ChildActivity >( NO_CHILD_ACTIVITY );
	const topLevel = new Set( table.rows.map( ( row ) => row.id ) );
	const onScreen = new Set( table.visibleIds );
	const childIds = selectedIds.filter( ( id ) => ! topLevel.has( id ) && onScreen.has( id ) );
	// Same selection, same list read: nothing new to ask.
	const key = `${ table.epoch }:${ childIds.join( '|' ) }`;
	const limit = table.definition.activity?.id_limit ?? DEFAULT_ID_LIMIT;

	useEffect( () => {
		if ( ! enabled || childIds.length === 0 ) {
			setKnown( NO_CHILD_ACTIVITY );
			return undefined;
		}

		const controller = new AbortController();
		const chunks: string[][] = [];
		for ( let at = 0; at < childIds.length; at += limit ) {
			chunks.push( childIds.slice( at, at + limit ) );
		}

		Promise.all( chunks.map( ( ids ) => readActivity( table.api, table.context, ids, controller.signal ) ) ).then(
			( answers ) => {
				const next: ChildActivity = {};
				answers.forEach( ( answer ) =>
					answer.rows.forEach( ( row ) => {
						if ( row.activity ) {
							next[ row.id ] = row.activity;
						}
					} )
				);
				setKnown( next );
			},
			( error: unknown ) => {
				if ( ! isAbort( error ) ) {
					setKnown( NO_CHILD_ACTIVITY );
				}
			}
		);

		return () => controller.abort();
		// `key` stands for the selected children and the list read; the rest are read from the same render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key, enabled ] );

	return known;
}

/**
 * Why a bulk action cannot run now: the account line blocks it, or a selected row's lock does. Visible text from PHP.
 */
function blockedReason(
	action: ActionDescriptor,
	selectedIds: string[],
	table: TableHandle,
	health: ActivityHealth,
	children: ChildActivity
): string {
	const account = accountBlockedReason( health, action.id );
	if ( account !== '' ) {
		return account;
	}

	const locked = table.rows.find(
		( row ) => selectedIds.includes( row.id ) && row.activity?.blocked_actions.includes( action.id )
	);
	if ( locked ) {
		return locked.activity?.reason ?? '';
	}

	const lockedChild = selectedIds.find( ( id ) => children[ id ]?.blocked_actions.includes( action.id ) );

	return lockedChild === undefined ? '' : children[ lockedChild ].reason;
}

export function BulkToolbar( { flow, selection, table, activity }: BulkToolbarProps ) {
	const health = useActivityState( activity?.state ?? NO_ACTIVITY );
	const children = useChildActivity( selection.selectedIds, table, activity !== undefined && table.definition.activity !== null );
	// A link is plain navigation; it has no bulk form.
	const actions = table.definition.bulk_actions.filter( ( action ) => action.interaction !== 'link' );
	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__bulk" role="toolbar" style={ STYLES.bulk }>
			{ actions.map( ( action ) => {
				const idle = action.scope === 'selection' && selection.selectedIds.length === 0;
				const reason = blockedReason( action, selection.selectedIds, table, health, children );
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
