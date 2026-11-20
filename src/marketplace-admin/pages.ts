/**
 * Turns the manifest into `woocommerce_admin_pages_list` entries.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import { MovedPage } from './orders-page/moved-page';
import { createOrdersPageContainer } from './orders-page/orders-page';
import { PageBody } from './page-view';
import type { Manifest, ManifestMenu, ManifestPage, WcAdminPage, WcPageProps } from './types';

/**
 * The container wc-admin renders for one page; a page that moved forwards to where it is shown now.
 *
 * @param page The page.
 * @param menu The menu it belongs to.
 */
export function createPageContainer( page: ManifestPage, menu: ManifestMenu ) {
	return function MarketplacePage( props: WcPageProps ) {
		if ( page.movedTo ) {
			return createElement( MovedPage, { to: page.movedTo, query: props.query ?? {} } );
		}

		return createElement( PageBody, { page, menu, query: props.query ?? {} } );
	};
}

/**
 * One wc-admin page per manifest page, and «Заказы маркетплейсов» when PHP published it. `wpOpenMenu` is the DOM id of the
 * menu root the page belongs to, so WordPress shows that menu open and its root highlighted while the page is on screen
 * (WooCommerce's own pages do the same): a marketplace's own menu, or the WooCommerce menu for the shared orders page.
 *
 * @param manifest The manifest PHP published.
 */
export function buildAdminPages( manifest: Manifest ): WcAdminPage[] {
	const pages: WcAdminPage[] = manifest.menus.flatMap( ( menu ) =>
		menu.pages.map( ( page ) => ( {
			container: createPageContainer( page, menu ),
			path: page.path,
			breadcrumbs: page.breadcrumbs,
			wpOpenMenu: menu.domId,
		} ) )
	);

	if ( manifest.ordersPage && manifest.ordersPage.marketplaces.length > 0 ) {
		pages.push( {
			container: createOrdersPageContainer( manifest.ordersPage ),
			path: manifest.ordersPage.path,
			breadcrumbs: manifest.ordersPage.breadcrumbs,
			wpOpenMenu: manifest.ordersPage.domId,
		} );
	}

	return pages;
}
