/**
 * The URL of «Заказы маркетплейсов»: which marketplace and which tab it shows, and the patches that switch them.
 *
 * Like every control of the delivery orders page, both live in the URL, so reload, a bookmark and Back show the same view. A
 * switch starts the new view from scratch: every argument of the table that was left (its account, filters, page, sort) goes.
 *
 * @package woodev-plugin-framework
 */

import type { OrdersPageManifest, OrdersPageMarketplace } from '../types';

/** The URL argument of the marketplace; PHP's `Marketplace_Orders_Page::MARKETPLACE_ARG`. */
export const MARKETPLACE_ARGUMENT = 'marketplace';

/** The URL argument of the tab; absent means the orders. */
export const TAB_ARGUMENT = 'tab';

export const ANALYTICS_TAB = 'analytics';

export const ORDERS_TAB = 'orders';

/** The arguments a switch keeps: wc-admin's own and the page's two. */
const KEPT = [ 'page', 'path', MARKETPLACE_ARGUMENT, TAB_ARGUMENT ];

type Query = Record< string, string | undefined >;

/**
 * The marketplace the URL asks for, when the page has it; the first one for anything else (an argument naming no marketplace
 * of the page is a stale link, never a way to another plugin's table).
 *
 * @param page  The page.
 * @param query The route's query.
 */
export function activeMarketplace( page: OrdersPageManifest, query: Query ): OrdersPageMarketplace {
	return page.marketplaces.find( ( marketplace ) => marketplace.id === query[ MARKETPLACE_ARGUMENT ] ) ?? page.marketplaces[ 0 ];
}

/**
 * @param query The route's query.
 * @return The open tab.
 */
export function activeTab( query: Query ): string {
	return query[ TAB_ARGUMENT ] === ANALYTICS_TAB ? ANALYTICS_TAB : ORDERS_TAB;
}

/**
 * The patch that drops every argument a switch does not keep and sets the ones given.
 *
 * @param query The route's query.
 * @param set   The arguments to set; `undefined` removes one.
 */
function fresh( query: Query, set: Query ): Query {
	const patch: Query = { ...set };

	Object.keys( query )
		.filter( ( key ) => ! KEPT.includes( key ) )
		.forEach( ( key ) => {
			patch[ key ] = undefined;
		} );

	return patch;
}

/**
 * The patch that shows another marketplace's orders.
 *
 * @param query       The route's query.
 * @param marketplace The plugin id.
 */
export function marketplacePatch( query: Query, marketplace: string ): Query {
	return fresh( query, { [ MARKETPLACE_ARGUMENT ]: marketplace } );
}

/**
 * The patch that opens a tab; the orders tab is the absence of the argument, so the plugins' links keep opening it.
 *
 * @param query The route's query.
 * @param tab   The tab id.
 */
export function tabPatch( query: Query, tab: string ): Query {
	return fresh( query, { [ TAB_ARGUMENT ]: tab === ANALYTICS_TAB ? ANALYTICS_TAB : undefined } );
}
