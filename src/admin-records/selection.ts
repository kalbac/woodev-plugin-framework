/**
 * Row selection for bulk actions: a set of row ids, capped at the table's `max_selection`, and one mode on top of it —
 * «every row under the filter» (the pattern of Gmail's «select all conversations»).
 *
 * The server enforces the cap again and resolves a parent and its children to distinct targets; the shell only keeps
 * the merchant from building a selection that would be refused. A list read drops the selection — and the mode with
 * it — because the ids belong to rows that may no longer be there, and the filter the mode froze is not the one on
 * screen any more. Unticking any row, or the page's header box, leaves the mode as well.
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
 * @param limit The table's `max_selection`.
 * @param epoch Changes with every list read.
 */
export function useSelection( limit: number, epoch: number ): Selection {
	const [ state, setState ] = useState< SelectionState >( NOTHING );

	useEffect( () => setState( NOTHING ), [ epoch ] );

	const set = useCallback(
		( ids: string[], selected: boolean ) =>
			setState( ( known ) =>
				selected
					? add( known, ids, limit )
					: { ids: known.ids.filter( ( id ) => ! ids.includes( id ) ), limitReached: false, matching: null }
			),
		[ limit ]
	);

	const selectMatching = useCallback(
		( query: MatchingQuery ) => setState( ( known ) => ( { ...known, limitReached: false, matching: query } ) ),
		[]
	);

	return {
		selectedIds: state.ids,
		limitReached: state.limitReached,
		matching: state.matching !== null,
		matchingQuery: state.matching,
		set,
		selectMatching,
		clear: useCallback( () => setState( NOTHING ), [] ),
	};
}
