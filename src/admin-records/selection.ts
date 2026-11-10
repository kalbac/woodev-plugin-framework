/**
 * Row selection for bulk actions: a set of row ids, capped at the table's `max_selection`.
 *
 * The server enforces the cap again and resolves a parent and its children to distinct targets; the shell only keeps
 * the merchant from building a selection that would be refused. A list read drops the selection, because the ids
 * belong to rows that may no longer be there.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useState } from '@wordpress/element';

export interface Selection {
	selectedIds: string[];
	/** The last attempt to select did not fit under the cap. */
	limitReached: boolean;
	/** Selects or deselects several rows at once (a parent with its children, or the whole page). */
	set: ( ids: string[], selected: boolean ) => void;
	clear: () => void;
}

interface SelectionState {
	ids: string[];
	limitReached: boolean;
}

const NOTHING: SelectionState = { ids: [], limitReached: false };

function add( known: string[], ids: string[], limit: number ): SelectionState {
	const fresh = ids.filter( ( id ) => ! known.includes( id ) );
	const room = Math.max( 0, limit - known.length );

	return { ids: [ ...known, ...fresh.slice( 0, room ) ], limitReached: fresh.length > room };
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
				selected ? add( known.ids, ids, limit ) : { ids: known.ids.filter( ( id ) => ! ids.includes( id ) ), limitReached: false }
			),
		[ limit ]
	);

	return {
		selectedIds: state.ids,
		limitReached: state.limitReached,
		set,
		clear: useCallback( () => setState( NOTHING ), [] ),
	};
}
