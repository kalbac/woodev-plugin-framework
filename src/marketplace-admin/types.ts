/**
 * Shapes shared by the marketplace routing bundle.
 *
 * The manifest is built by PHP (`Menu::build_manifest()`); the bundle only reads it. Titles, breadcrumbs and
 * paths arrive finished, so nothing here holds copy (AGENT-RULES Rule 9).
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType } from 'react';
import type { HelpLinkData } from '../components/help-link';

export type PageKind = 'records' | 'custom' | 'settings';

/** A breadcrumb: a plain label, or `[ path, label ]` for a link. */
export type Breadcrumb = string | [ string, string ];

export interface ManifestPage {
	id: string;
	title: string;
	path: string;
	kind: PageKind;
	tables: string[];
	/** The label of each table, by id, when the page offers several tables to switch between; absent otherwise. */
	tableLabels?: Record< string, string >;
	view: string;
	breadcrumbs: Breadcrumb[];
	/** The page's «Подробнее…» link, resolved by PHP against the plugin's help table; absent when none. */
	help?: HelpLinkData;
	/** The «Частые проблемы» link every framework-drawn error and note of the page shows; absent when the plugin's table has no topic. */
	problems?: HelpLinkData;
	/** The reserved analytics slot: always null, never requested. */
	analytics: null;
	/** Set when another page shows this one now (the plugin's «Заказы» → «Заказы маркетплейсов»): the route forwards there. */
	movedTo?: MovedTo;
}

/** Where a page that moved is shown now: a wc-admin path and the arguments it opens with. */
export interface MovedTo {
	path: string;
	query: Record< string, string >;
}

/** One marketplace on «Заказы маркетплейсов»: its plugin, whose `orders` table is shown, and the help links of its old page. */
export interface OrdersPageMarketplace {
	/** The plugin id: the records owner and the value of the `marketplace` URL argument. */
	id: string;
	label: string;
	table: string;
	help?: HelpLinkData;
	problems?: HelpLinkData;
}

/** The shared «Заказы маркетплейсов» page (`Marketplace_Orders_Page::build_manifest()`). */
export interface OrdersPageManifest {
	path: string;
	title: string;
	breadcrumbs: Breadcrumb[];
	/** DOM id of the WooCommerce menu root, kept open while the page is on screen. */
	domId: string;
	marketplaces: OrdersPageMarketplace[];
	labels: {
		marketplace: string;
		tabs: string;
		orders: string;
		analytics: string;
		analyticsEmpty: string;
	};
}

export interface ManifestMenu {
	id: string;
	pluginId: string;
	title: string;
	/** DOM id of the menu root `<li>`; WooCommerce opens it for every page of the menu. */
	domId: string;
	pages: ManifestPage[];
}

/**
 * A page the menu leaves out, which the user may still open from a bookmark: it explains, in PHP's words, what to switch on
 * (`Admin_Collector::build_manifest()`; today only «Диагностика» while «Логирование» is off).
 */
export interface PageExplanation {
	path: string;
	title: string;
	breadcrumbs: Breadcrumb[];
	/** DOM id of the menu root, kept open while the page is on screen. */
	domId: string;
	message: string;
	/** Where the switch is; absent when the plugin has no settings page. */
	settings?: { url: string; label: string };
}

export interface Manifest {
	menus: ManifestMenu[];
	/** Pages the menu does not list but a bookmark can still reach; absent when there are none. */
	explanations?: PageExplanation[];
	/** Present when the user may open the orders of at least one marketplace. */
	ordersPage?: OrdersPageManifest;
}

/** What wc-admin hands a page container. */
export interface WcPageProps {
	path?: string;
	params?: Record< string, string >;
	query?: Record< string, string >;
}

/** What the page filter hands a view: the page, its menu and the route's query. */
export interface PageViewProps {
	page: ManifestPage;
	menu: ManifestMenu;
	query: Record< string, string >;
}

export type PageView = ComponentType< PageViewProps >;

/** An entry of `woocommerce_admin_pages_list`. */
export interface WcAdminPage {
	container: ComponentType< WcPageProps >;
	path: string;
	breadcrumbs: Breadcrumb[];
	wpOpenMenu: string;
	capability?: string;
}

declare global {
	interface Window {
		woodevMarketplaceAdmin?: Manifest;
	}
}
