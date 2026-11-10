/**
 * The two pieces of the WooCommerce Admin runtime the shell uses, read off `window.wc` (the bundle depends on
 * `wc-admin-app`, which loads `wc-components` and `wc-navigation` first). `@woocommerce/components` is not bundled
 * and DataViews is not used — the Q3 ruling in `docs-internal/wiki/marketplace-admin.md`.
 *
 * Q3 version check. {@link TableCardProps} lists only what exists in BOTH the oldest WooCommerce a plugin of ours
 * declares (7.0: `packages/js/components/src/table/{index,table}.js`) and trunk
 * (`…/table/{index,table}.tsx`, `types.ts`), read from the `woocommerce/woocommerce` repository on 10.11.2026:
 *
 * - `emptyMessage` and `tablePreface` are missing in 7.0, so the shell draws its own empty state and notices and
 *   never passes either.
 * - A header's `visible` exists in both: 7.0's `getShowCols( headers )` keeps a column only when `visible` is
 *   undefined or truthy, so a column PHP hides by default is hidden there too (checked against the 7.0.0 tag).
 * - Trunk keeps the visible-column list in `useState( initial )`, so headers that change after the first render
 *   would stay hidden. The shell mounts the card only once the definition is known and remounts it when the column
 *   set changes ({@see ./records-table}).
 * - Both versions call `onPageChange( page )` AND `onQueryChange( 'paged' )( page )` for one click; the shell
 *   handles the page in `onPageChange` only. `onQueryChange( 'per_page' )` is the only source of a page-size change.
 * - The page-size picker inside WooCommerce's `Pagination` offers its own fixed list (25/50/75/100) and 7.0's
 *   `TableCard` has no prop to switch it off. The shell hides it with CSS and draws a control from PHP's
 *   `per_page_options`; should the vendor picker still call in, a size PHP did not declare is dropped. One choice is
 *   two callbacks (`onQueryChange( 'per_page' )`, then `onPageChange` with the page clamped for the new size): they
 *   are applied as one navigation ({@see ./records-table}).
 *
 * The types are the shell's own, cast from `window`, so nothing is imported from the delivery page.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, ReactNode } from 'react';
import type { RouteQuery } from './types';

export interface TableHeader {
	key: string;
	label: ReactNode;
	/** Only together with a `label` that is not itself a control: WooCommerce hides the label from assistive tech. */
	screenReaderLabel?: string;
	isSortable?: boolean;
	isLeftAligned?: boolean;
	isNumeric?: boolean;
	required?: boolean;
	visible?: boolean;
	defaultSort?: boolean;
	defaultOrder?: string;
	cellClassName?: string;
}

export interface TableCell {
	display: ReactNode;
	value: string | number | boolean;
}

export interface TableCardQuery {
	orderby?: string;
	order?: string;
	paged?: string;
	per_page?: number;
}

export interface TableCardProps {
	title: string;
	headers: TableHeader[];
	rows: TableCell[][];
	rowsPerPage: number;
	totalRows: number;
	query: TableCardQuery;
	isLoading: boolean;
	showMenu: boolean;
	/** Index of the column whose cells are the row headers. */
	rowHeader: number | false;
	rowKey: ( row: TableCell[], index: number ) => string;
	onSort: ( key: string, direction: string ) => void;
	onPageChange: ( page: number ) => void;
	onQueryChange: ( param: string ) => ( value: string | number ) => void;
	className?: string;
}

interface WcRuntime {
	components?: { TableCard?: ComponentType< TableCardProps > };
	navigation?: { updateQueryString?: ( query: RouteQuery ) => void };
}

function runtime(): WcRuntime {
	return ( window as unknown as { wc?: WcRuntime } ).wc ?? {};
}

/** WooCommerce's `TableCard`, or `null` outside a wc-admin screen. */
export function getTableCard(): ComponentType< TableCardProps > | null {
	return runtime().components?.TableCard ?? null;
}

export type Navigate = ( patch: RouteQuery ) => void;

/**
 * wc-admin's own navigation: merges the patch into the current URL (a key set to `undefined` is removed) and pushes
 * a history entry, so Back and Forward step through table states. `null` outside a wc-admin screen.
 */
export function getNavigate(): Navigate | null {
	const update = runtime().navigation?.updateQueryString;

	return update ? ( patch ) => update( patch ) : null;
}
