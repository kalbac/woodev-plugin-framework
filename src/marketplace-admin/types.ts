/**
 * Shapes shared by the marketplace routing bundle.
 *
 * The manifest is built by PHP (`Menu::build_manifest()`); the bundle only reads it. Titles, breadcrumbs and
 * paths arrive finished, so nothing here holds copy (AGENT-RULES Rule 9).
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType } from 'react';

export type PageKind = 'records' | 'custom' | 'settings';

/** A breadcrumb: a plain label, or `[ path, label ]` for a link. */
export type Breadcrumb = string | [ string, string ];

export interface ManifestPage {
	id: string;
	title: string;
	path: string;
	kind: PageKind;
	tables: string[];
	view: string;
	breadcrumbs: Breadcrumb[];
	/** The reserved analytics slot: always null, never requested. */
	analytics: null;
}

export interface ManifestMenu {
	id: string;
	pluginId: string;
	title: string;
	/** DOM id of the menu root `<li>`; WooCommerce opens it for every page of the menu. */
	domId: string;
	pages: ManifestPage[];
}

export interface Manifest {
	menus: ManifestMenu[];
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
