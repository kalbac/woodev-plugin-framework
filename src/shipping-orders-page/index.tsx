/**
 * Shipping orders page entry — attaches `OrdersPage` into WooCommerce's own
 * admin app through `woocommerce_admin_pages_list`, the same extension point
 * WooCommerce's own developer docs use
 * (`docs/extensions/settings-and-config/working-with-woocommerce-admin-pages.md`
 * in the `woocommerce/woocommerce` monorepo) — this is a `wc-admin` page now
 * (SP-10 spec D1/D7), not a page we render ourselves, so there is no DOM
 * mount point here any more.
 *
 * `path` must match `Orders_Registry::register_page()`'s
 * `wc_admin_register_page( [ 'path' => ... ] )` call exactly.
 *
 * @package woodev-plugin-framework
 */

import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import PageTabs from './page-tabs';
import { addDeliveryReport, REPORT_FILTER, REPORT_FILTER_NAMESPACE } from './analytics/report-registration';
import type { WcAdminPage } from './wc-globals';
import './style.scss';

addFilter(
	'woocommerce_admin_pages_list',
	'woodev/shipping-orders',
	( pages: WcAdminPage[] ) => {
		pages.push( {
			container: PageTabs,
			path: '/woodev-shipping-orders',
			breadcrumbs: [ __( 'Заказы доставки', 'woodev-plugin-framework' ) ],
			// The page sits under the WooCommerce menu, so it opens the same item
			// WooCommerce's own «Клиенты» does. Without this the WP menu collapses
			// while our page is on screen — WordPress renders the menu server-side
			// and never sees `path`, so only the app can highlight it.
			wpOpenMenu: 'toplevel_page_woocommerce',
		} );

		return pages;
	}
);

// The «Доставка перевозчиками» report in WooCommerce → Аналитика (#711): the page's «Аналитика» tab body on `/analytics/woodev-delivery`.
// Same bundle — `Orders_Registry::enqueue_assets()` puts it on every registered `wc-admin` page, and the report's menu entry is one
// (`Analytics::register_pages()` passes the entries of `woocommerce_analytics_report_menu_items` to `wc_admin_register_page()`).
addFilter( REPORT_FILTER, REPORT_FILTER_NAMESPACE, addDeliveryReport );
