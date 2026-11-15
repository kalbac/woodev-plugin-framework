/**
 * Where the mappings page is, kept in the URL so Back, Forward and a bookmark work.
 *
 * `tab=profiles` (absent means the mappings tab), `mapping=new|<id>` and `profile=new|<id>` (absent means the list).
 * wc-admin hands the page its query and rewrites it through `wc.navigation`; outside wc-admin (the view's unit tests)
 * the page keeps the same arguments in its own state.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { getNavigate } from '../../../admin-records/wc-runtime';
import type { RouteQuery } from '../../../admin-records/types';

export type Tab = 'mappings' | 'profiles';

/** `null` is a new record; a number is a saved one; `undefined` is the list. */
export type Selection = number | null | undefined;

export interface Route {
	tab: Tab;
	mapping: Selection;
	profile: Selection;
	/**
	 * Applies a change to the arguments; a key set to `undefined` is removed. `replace` rewrites the current history entry
	 * instead of adding one, for a step that must not be a stop of its own on the way Back (a saved new record).
	 */
	go: ( patch: RouteQuery, replace?: boolean ) => void;
}

function parseSelection( value: string | undefined ): Selection {
	if ( value === 'new' ) {
		return null;
	}

	return value !== undefined && /^[1-9][0-9]*$/.test( value ) ? Number( value ) : undefined;
}

/** The URL argument for a selection. */
export function selectionArgument( selection: Selection ): string | undefined {
	return selection === undefined ? undefined : selection === null ? 'new' : String( selection );
}

interface WcHistory {
	navigation?: {
		getHistory?: () => { replace: ( path: string ) => void };
		getNewPath?: ( query: RouteQuery ) => string;
	};
}

/** wc-admin's own «rewrite this entry», or `null` when the runtime does not offer it (the step is then pushed). */
function getReplace(): ( ( patch: RouteQuery ) => void ) | null {
	const navigation = ( window as unknown as { wc?: WcHistory } ).wc?.navigation;
	const history = navigation?.getHistory?.();
	const toPath = navigation?.getNewPath;

	return history && toPath ? ( patch ) => history.replace( toPath( patch ) ) : null;
}

/**
 * @param query The route's query, as wc-admin hands it to the page.
 */
export function useRoute( query: Record< string, string > ): Route {
	const [ local, setLocal ] = useState< RouteQuery >( {} );
	const navigate = getNavigate();
	const current: RouteQuery = navigate ? query : { ...query, ...local };

	return {
		tab: current.tab === 'profiles' ? 'profiles' : 'mappings',
		mapping: parseSelection( current.mapping ),
		profile: parseSelection( current.profile ),
		go: ( patch, replace = false ) => {
			const rewrite = replace ? getReplace() : null;

			if ( rewrite ) {
				rewrite( patch );
			} else if ( navigate ) {
				navigate( patch );
			} else {
				setLocal( ( previous ) => ( { ...previous, ...patch } ) );
			}
		},
	};
}
