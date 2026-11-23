/**
 * Row selection for bulk actions: a set of row ids, capped at the table's `max_selection`, and one mode on top of it —
 * «every row under the filter» (the pattern of Gmail's «select all conversations»).
 *
 * The server enforces the cap again and resolves a parent and its children to distinct targets; the shell only keeps
 * the merchant from building a selection that would be refused. A list read drops the selection — and the mode with
 * it — because the ids belong to rows that may no longer be there, and the filter the mode froze is not the one on
 * screen any more. Unticking any row, or the page's header box, leaves the mode as well.
 *
 * A page with a busy row (a process of its own in flight) cannot take the mode: the filter would reach that row, which
 * no bulk action may touch. The mode is not offered while the page has one, and a row that goes busy under a mode that
 * is already on ends it — the ticked rows stay as an ordinary selection.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import type { MatchingQuery } from './types';

export interface Selection {
	selectedIds: string[];
	/** The last attempt to select did not fit under the cap. */
	limitReached: boolean;
	/** Every row under the filter is selected: the mode is on. The ticked rows are only the ones on screen. */
	matching: boolean;
	/** The filter and search as they were at the click that turned the mode on; `null` while it is off. */
	matchingQuery: MatchingQuery | null;
	/** The page has a busy row: the mode cannot be turned on, and is off. */
	matchingBlocked: boolean;
	/** Selects or deselects several rows at once (a parent with its children, or the whole page). */
	set: ( ids: string[], selected: boolean ) => void;
	/** Turns the mode on for the query on screen now; the ticked rows stay ticked. */
	selectMatching: ( query: MatchingQuery ) => void;
	clear: () => void;
}

interface SelectionState {
	ids: string[];
	limitReached: boolean;
	matching: MatchingQuery | null;
}

const NOTHING: SelectionState = { ids: [], limitReached: false, matching: null };

function add( known: SelectionState, ids: string[], limit: number ): SelectionState {
	const fresh = ids.filter( ( id ) => ! known.ids.includes( id ) );
	const room = Math.max( 0, limit - known.ids.length );

	return { ...known, ids: [ ...known.ids, ...fresh.slice( 0, room ) ], limitReached: fresh.length > room };
}

/**
 * Unticking rows. Taking a row out of «every row under the filter» corrects the whole choice, so it starts again from no
 * selection rather than leaving a partial page behind.
 */
function remove( known: SelectionState, ids: string[] ): SelectionState {
	if ( known.matching !== null ) {
		return NOTHING;
	}

	return { ...known, ids: known.ids.filter( ( id ) => ! ids.includes( id ) ), limitReached: false };
}

/**
 * @param limit           The table's `max_selection`.
 * @param epoch           Changes with every list read that replaces the rows; a silent reload keeps the selection.
 * @param matchingBlocked Whether the page holds a busy row, which the filter-wide mode would take along.
 */
export function useSelection( limit: number, epoch: number, matchingBlocked = false ): Selection {
	const [ state, setState ] = useState< SelectionState >( NOTHING );

	useEffect( () => setState( NOTHING ), [ epoch ] );

	// The mode ends in the same render that finds a busy row (below); the state follows, so it does not come back when the row frees up.
	useEffect( () => {
		if ( matchingBlocked ) {
			setState( ( known ) => ( known.matching === null ? known : { ...known, matching: null } ) );
		}
	}, [ matchingBlocked ] );

	const set = useCallback(
		( ids: string[], selected: boolean ) =>
			setState( ( known ) =>
				selected
					? add( known, ids, limit )
					: known.matching !== null
					? // Taking a row out of «every row under the filter» is a correction of the whole choice: start again.
					  NOTHING
					: { ...known, ids: known.ids.filter( ( id ) => ! ids.includes( id ) ), limitReached: false }
			),
		[ limit ]
	);

	const selectMatching = useCallback(
		( query: MatchingQuery ) => {
			if ( ! matchingBlocked ) {
				setState( ( known ) => ( { ...known, limitReached: false, matching: query } ) );
			}
		},
		[ matchingBlocked ]
	);
	const matching = matchingBlocked ? null : state.matching;

	return {
		selectedIds: state.ids,
		limitReached: state.limitReached,
		matching: matching !== null,
		matchingQuery: matching,
		matchingBlocked,
		set,
		selectMatching,
		clear: useCallback( () => setState( NOTHING ), [] ),
	};
}
