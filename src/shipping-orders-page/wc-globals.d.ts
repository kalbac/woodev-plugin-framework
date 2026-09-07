/**
 * Ambient types for the WooCommerce Admin runtime this page consumes directly
 * off `window.wc` (SP-10 spec D7, build-seam Route B): `@wordpress/dependency-extraction-webpack-plugin`
 * (what `wp-scripts` ships) does not know `@woocommerce/*` packages, and
 * `@woocommerce/components`/`@woocommerce/dependency-extraction-webpack-plugin`
 * are not installed in this repo's `node_modules` at all — adding them would
 * need `npm install`, which the worktree contract forbids and which would
 * mutate the `node_modules` this worktree shares with the primary checkout.
 * So the component is read off the global WooCommerce exposes at runtime
 * behind the `wc-components` script handle `Orders_Registry::enqueue_assets()`
 * declares by hand, and typed here against the subset of the real
 * `TableCardProps` this page actually uses — read from
 * `packages/js/components/src/table/{types.ts,index.tsx}` in the
 * `woocommerce/woocommerce` monorepo, not recalled.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, ReactNode } from 'react';

export interface WcTableHeader {
	key: string;
	label: string;
	isSortable?: boolean;
	required?: boolean;
}

export interface WcTableRowCell {
	display: ReactNode;
	value: string | number | boolean;
}

export interface WcTableCardQuery {
	orderby?: string;
	order?: string;
	paged?: string;
}

export interface WcTableCardProps {
	title: string;
	headers: WcTableHeader[];
	rows: WcTableRowCell[][];
	rowsPerPage: number;
	totalRows: number;
	query?: WcTableCardQuery;
	isLoading?: boolean;
	emptyMessage?: string;
	summary?: Array<{ label: string; value: string | number }>;
	actions?: ReactNode[];
	hasSearch?: boolean;
	showMenu?: boolean;
	className?: string;
	onSort?: ( key: string, direction: string ) => void;
	onPageChange?: ( newPage: number ) => void;
	onQueryChange?: ( param: string ) => ( value: string ) => void;
}

/** One entry of the `woocommerce_admin_pages_list` filter's array (WC docs: `working-with-woocommerce-admin-pages.md`). */
export interface WcAdminPage {
	container: ComponentType;
	path: string;
	breadcrumbs: string[];
	/**
	 * DOM id of the WP admin menu item to open and highlight while this page is
	 * shown. WordPress renders the menu server-side and cannot see the `path`
	 * query arg, so a `wc-admin` page's parent menu is highlighted by the
	 * WooCommerce app CLIENT-side, from this property — read off WC's own
	 * `assets/client/admin/app/index.js`, where `/customers` declares
	 * `wpOpenMenu: 'toplevel_page_woocommerce'` and the app does
	 * `document.querySelector( '#' + page.wpOpenMenu )`.
	 */
	wpOpenMenu?: string;
}

/** One selectable option of a {@link WcFilterPickerConfig}. */
export interface WcFilterPickerFilter {
	label: string;
	value: string;
}

/**
 * `FilterPicker`'s `config`, per `packages/js/components/src/filter-picker/README.md`.
 *
 * ⚠ `FilterPicker` is URL-driven, not state-driven: picking an option rewrites
 * the query parameter named by `param` and navigates, rather than calling back
 * with a value. `staticParams` lists the query parameters to carry across that
 * navigation — ours is empty on purpose, so a carrier change drops `paged` and
 * returns to page 1.
 */
export interface WcFilterPickerConfig {
	label: string;
	param: string;
	staticParams: string[];
	showFilters: () => boolean;
	filters: WcFilterPickerFilter[];
	defaultValue?: string;
}

export interface WcFilterPickerProps {
	config: WcFilterPickerConfig;
	path: string;
	query: Record< string, string | undefined >;
}

declare global {
	interface Window {
		wc?: {
			components?: {
				TableCard: ComponentType< WcTableCardProps >;
				FilterPicker?: ComponentType< WcFilterPickerProps >;
			};
			/**
			 * `@woocommerce/navigation`, behind the `wc-navigation` script handle
			 * (declared by hand in `Orders_Registry::enqueue_assets()` — Route B).
			 * `addHistoryListener` returns its own unlisten function; verified
			 * against the live runtime, not recalled.
			 */
			navigation?: {
				getQuery: () => Record< string, string | undefined >;
				getPath: () => string;
				addHistoryListener: ( listener: () => void ) => () => void;
			};
		};
	}
}
