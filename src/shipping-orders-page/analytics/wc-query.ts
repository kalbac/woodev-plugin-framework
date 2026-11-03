/**
 * The URL side of the tab (#711): the `wc-admin` query as a React value, and the single way the tab changes it.
 *
 * Every control of the tab is URL-driven, like the orders list. The history listener only raises a counter and the
 * query is read in a LATER effect — `@woocommerce/navigation` fires its event BEFORE the real `pushState`, so
 * `getQuery()` inside the listener would read the previous URL (gotcha `addhistorylistener-fires-before-the-url-changes`).
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';

export type WcQuery = Record< string, string | undefined >;

/** `path` of the shipping orders page inside `wc-admin`; must match `Orders_Registry::build_page_args()`. */
export const PAGE_PATH = '/woodev-shipping-orders';

export function readQuery(): WcQuery {
	return window.wc?.navigation?.getQuery() || {};
}

/** Merges `patch` into the current query and navigates; a `undefined` value removes the key. */
export function navigate( patch: WcQuery, current: WcQuery = readQuery() ): void {
	window.wc?.navigation?.updateQueryString?.( patch, PAGE_PATH, current );
}

export function useWcQuery(): WcQuery {
	const [ query, setQuery ] = useState< WcQuery >( readQuery );
	const [ changes, setChanges ] = useState( 0 );

	useEffect( () => window.wc?.navigation?.addHistoryListener( () => setChanges( ( count ) => count + 1 ) ), [] );

	useEffect( () => {
		if ( changes > 0 ) {
			setQuery( readQuery() );
		}
	}, [ changes ] );

	return query;
}
