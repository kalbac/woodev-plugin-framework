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
}

declare global {
	interface Window {
		wc?: {
			components?: {
				TableCard: ComponentType< WcTableCardProps >;
			};
		};
	}
}
