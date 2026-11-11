/**
 * The activity part of the records library: locks, blocked actions, processes and the bounded refresh loop.
 *
 * ```tsx
 * const activity = useMemo( () => createActivityExtensions(), [] );
 * <RecordsTable extensions={ { ...actionExtensions, ...activity.extensions } } … />
 * ```
 *
 * `activity.ownership` is for the action part: claim the rows an action is writing and release them when its
 * response has landed, so a refresh that was already in flight never overwrites the result. `activity.state` carries
 * the account line (`useActivityState`, `accountBlockedReason`) for a bulk toolbar that must disable account actions.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import type { RecordsExtensions } from '../extensions';
import type { AdminRecord, Definition } from '../types';
import { createOwnership, type Ownership } from './ownership';
import { createActivityState, type ActivityState } from './state';
import { ActivityLoop, RowActivityView } from './views';

export interface ActivityExtensions {
	/** The three seams of the activity part; spread them into `RecordsTable`'s `extensions`. */
	extensions: Required< Pick< RecordsExtensions, 'blockedReason' | 'renderRowActivity' | 'renderActivity' > >;
	ownership: Ownership;
	state: ActivityState;
}

/**
 * Whether the row's actions cell already prints the lock sentence: `RowActions` writes `blockedReason` beside every
 * action PHP left enabled and the definition still lists.
 *
 * @param row        The row.
 * @param definition The table's definition.
 */
function reasonIsBesideAnAction( row: AdminRecord, definition: Definition ): boolean {
	const blocked = row.activity?.blocked_actions ?? [];
	const listed = definition.row_actions.map( ( action ) => action.id );

	return row.actions.some( ( state ) => state.enabled && blocked.includes( state.id ) && listed.includes( state.id ) );
}

export function createActivityExtensions(): ActivityExtensions {
	const ownership = createOwnership();
	const state = createActivityState();

	return {
		ownership,
		state,
		extensions: {
			// Only the row's own blocked actions: PHP already disabled the rest, with its own reason.
			blockedReason: ( action, row ) =>
				row.activity?.blocked_actions.includes( action.id ) ? row.activity.reason : '',
			renderRowActivity: ( row, table ) =>
				createElement( RowActivityView, {
					activity: row.activity,
					reasonShown: reasonIsBesideAnAction( row, table.definition ),
				} ),
			renderActivity: ( table ) => createElement( ActivityLoop, { table, state, ownership } ),
		},
	};
}

export { createOwnership, type ClaimToken, type Ownership } from './ownership';
export { accountBlockedReason, createActivityState, useActivityState, type ActivityHealth, type ActivityState } from './state';
export { ACTIVITY_MESSAGE_KEYS } from './messages';
export type * from './types';
