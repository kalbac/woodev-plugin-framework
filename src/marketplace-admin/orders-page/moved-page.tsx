/**
 * The route of a page another page shows now (a plugin's «Заказы» → «Заказы маркетплейсов»). Nothing links to it any more —
 * the menu entry, the plugin's page URL and the v1 redirects lead to the new page — but an old bookmark still opens it, so it
 * forwards there in place (history `replace`, so Back does not return to a page that only forwards).
 *
 * @package woodev-plugin-framework
 */

import { useEffect } from '@wordpress/element';
import type { MovedTo } from '../types';

type Query = Record< string, string | undefined >;

interface WcNavigation {
	getNewPath?: ( query: Query, path: string, currentQuery: Query ) => string;
	getHistory?: () => { replace: ( path: string ) => void };
}

/** wc-admin's own arguments: they name the old route and are never carried over. */
const ROUTE_ARGUMENTS = [ 'page', 'path' ];

/**
 * The arguments the new page opens with: the old route's own (an account, a filter) and the ones the move presets.
 *
 * @param moved Where the page moved.
 * @param query The old route's query.
 */
export function forwardedQuery( moved: MovedTo, query: Query ): Query {
	const kept = Object.fromEntries( Object.entries( query ).filter( ( [ key ] ) => ! ROUTE_ARGUMENTS.includes( key ) ) );

	return { ...kept, ...moved.query };
}

/**
 * Forwards to the new page once, on mount.
 *
 * @param props       The move and the old route's query.
 * @param props.to    Where the page moved.
 * @param props.query The old route's query.
 */
export function MovedPage( { to, query }: { to: MovedTo; query: Query } ) {
	useEffect( () => {
		const navigation = ( window as unknown as { wc?: { navigation?: WcNavigation } } ).wc?.navigation;

		if ( navigation?.getNewPath && navigation.getHistory ) {
			navigation.getHistory().replace( navigation.getNewPath( forwardedQuery( to, query ), to.path, {} ) );
		}
		// Once: the route is left at once, and a second forward would stack.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return null;
}
