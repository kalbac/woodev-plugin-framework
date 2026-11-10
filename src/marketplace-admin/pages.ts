/**
 * Turns the manifest into `woocommerce_admin_pages_list` entries.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import { PageBody } from './page-view';
import type { Manifest, ManifestMenu, ManifestPage, WcAdminPage, WcPageProps } from './types';

/**
 * The container wc-admin renders for one page.
 *
 * @param page The page.
 * @param menu The menu it belongs to.
 */
export function createPageContainer( page: ManifestPage, menu: ManifestMenu ) {
	return function MarketplacePage( props: WcPageProps ) {
		return createElement( PageBody, { page, menu, query: props.query ?? {} } );
	};
}

/**
 * One wc-admin page per manifest page. `wpOpenMenu` is the menu root's DOM id, so WordPress shows the menu open and the
 * root highlighted while any of its pages is on screen (WooCommerce's own pages do the same).
 *
 * @param manifest The manifest PHP published.
 */
export function buildAdminPages( manifest: Manifest ): WcAdminPage[] {
	return manifest.menus.flatMap( ( menu ) =>
		menu.pages.map( ( page ) => ( {
			container: createPageContainer( page, menu ),
			path: page.path,
			breadcrumbs: page.breadcrumbs,
			wpOpenMenu: menu.domId,
		} ) )
	);
}
