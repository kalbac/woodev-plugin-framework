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

/**
 * `@woocommerce/date`'s `getCurrentDates()` return shape (SP-10 spec D11, increment 7)
 * — `primary`/`secondary` `DateValue` objects, each carrying real `moment` instances
 * for `before`/`after` (`packages/js/date/README.md`, not recalled: `getCurrentDates`
 * returns `{ primary: DateValue, secondary: DateValue }` where `DateValue` is
 * `{ label, range, before: Moment, after: Moment }`). Only `.format()` is typed here
 * — this page never does date arithmetic of its own, only formats what
 * `@woocommerce/date` already resolved.
 */
export interface WcMomentLike {
	format: ( dateFormat: string ) => string;
}

export interface WcDateValue {
	label: string;
	range: string;
	before: WcMomentLike | null;
	after: WcMomentLike | null;
}

export interface WcDateParams {
	period: string;
	compare: string;
	before: WcMomentLike | null;
	after: WcMomentLike | null;
}

/**
 * `DateRangeFilterPicker`'s required `dateQuery` prop
 * (`packages/js/components/src/date-range-filter-picker/README.md`): the raw
 * `period`/`compare`/`before`/`after` plus the two resolved `DateValue`s the
 * component actually renders from.
 */
export interface WcDateRangeFilterPickerDateQuery extends WcDateParams {
	primaryDate: WcDateValue;
	secondaryDate: WcDateValue;
}

export interface WcDateRangeFilterPickerProps {
	dateQuery: WcDateRangeFilterPickerDateQuery;
	isoDateFormat: string;
	/**
	 * Called with the new `{ period, compare, before, after }` on a pick. The
	 * component itself does not navigate (unlike `FilterPicker`) — the caller is
	 * expected to push it into the URL via `wc.navigation.updateQueryString()`,
	 * the same way WooCommerce's own `ReportFilters` wires it.
	 */
	onRangeSelect: ( update: Record<string, string> ) => void;
}

/**
 * One selectable value of an {@link WcAdvancedFiltersFilterDef}'s `SelectControl`
 * input (`packages/js/components/src/advanced-filters/README.md`).
 */
export interface WcAdvancedFiltersSelectOption {
	key: string;
	label: string;
}

/** One filter's match rule (e.g. "Is" / "Is Not") — the `<rule/>` slot in its `title`. */
export interface WcAdvancedFiltersRule {
	value: string;
	label: string;
}

/**
 * One entry of an {@link WcAdvancedFiltersConfig}'s `filters` map. Every filter
 * this page declares uses `component: 'SelectControl'` with exactly one rule and
 * `allowMultiple: false`, so each produces exactly one query key in WooCommerce's
 * own `{filterKey}_{rule}=value` convention (confirmed against the README's own
 * worked example, `status_is=pending` — not recalled).
 */
export interface WcAdvancedFiltersFilterDef {
	labels: {
		add: string;
		remove: string;
		title: string;
		rule?: string;
		filter?: string;
	};
	rules: WcAdvancedFiltersRule[];
	input: {
		component: 'SelectControl';
		options: WcAdvancedFiltersSelectOption[];
	};
	allowMultiple: boolean;
}

export interface WcAdvancedFiltersConfig {
	title: string;
	filters: Record<string, WcAdvancedFiltersFilterDef>;
}

export interface WcAdvancedFiltersProps {
	config: WcAdvancedFiltersConfig;
	path: string;
	query?: Record<string, string | undefined>;
	onAdvancedFilterAction?: () => void;
	/** Optional, default `'en_US'` — this page always passes the admin's own Russian locale. */
	siteLocale?: string;
	/**
	 * Required by `AdvancedFilters`' own propTypes, an instance of
	 * `@woocommerce/currency`'s `CurrencyFactory()` — this page never declares a
	 * `Number`/currency-typed filter, so nothing here actually reads its fields;
	 * it exists only to satisfy the component's contract.
	 */
	currency?: Record<string, unknown>;
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
				/**
				 * WooCommerce's own loading skeletons, reused as the ROI panel's frame.
				 * Prop names read off WC's app bundle (`SummaryListPlaceholder,{numberOfItems:…}`)
				 * and `ChartPlaceholder`'s `defaultProps` (`{height:0}` — so a height
				 * must be passed or it collapses), not recalled.
				 */
				SummaryListPlaceholder?: ComponentType< { numberOfItems: number } >;
				ChartPlaceholder?: ComponentType< { height: number } >;
				/** SP-10 spec D11 (increment 7) — the date-range half of the filter row. */
				DateRangeFilterPicker?: ComponentType< WcDateRangeFilterPickerProps >;
				/** SP-10 spec D10 (increment 7) — the delivery-status/order-status/tracking filters, named by the operator. */
				AdvancedFilters?: ComponentType< WcAdvancedFiltersProps >;
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
				/**
				 * `updateQueryString(query, path, currentQuery)` (`packages/js/navigation/README.md`)
				 * — merges `query` into `currentQuery` and navigates, the same way
				 * `FilterPicker` does internally. `DateRangeFilterPicker` does NOT
				 * navigate on its own, so this page wires its `onRangeSelect` through
				 * this function explicitly.
				 */
				updateQueryString?: (
					query: Record< string, string | undefined >,
					path: string,
					currentQuery: Record< string, string | undefined >
				) => void;
			};
			/**
			 * `@woocommerce/date`, behind the `wc-date` handle (SP-10 spec D11's own
			 * contract note: `getDateParamsFromQuery()`, `getCurrentDates()`,
			 * `isoDateFormat`).
			 */
			date?: {
				getDateParamsFromQuery: (
					query: Record< string, string | undefined >,
					defaultDateRange: string
				) => WcDateParams;
				getCurrentDates: (
					query: Record< string, string | undefined >,
					defaultDateRange: string
				) => { primary: WcDateValue; secondary: WcDateValue };
				isoDateFormat: string;
			};
			/**
			 * `@woocommerce/currency`, behind the `wc-currency` handle.
			 *
			 * ⚠ **This is a MODULE OBJECT and is NOT callable** — measured on the rig
			 * (WC 11.1.0): `typeof wc.currency === 'object'`, and the factory lives on
			 * it as `CurrencyFactory`. An earlier version of this declaration typed the
			 * module itself as the factory; the page then called it and threw
			 * `TypeError: B is not a function`, which crashes the ENTIRE wc-admin app
			 * rather than just this control. TypeScript could not catch that, because a
			 * hand-written declaration for a runtime global is an assertion about
			 * someone else's bundle, not a check of it — so anything added here must be
			 * measured in the browser first.
			 *
			 * `CurrencyFactory(storeSettings?)` returns the instance `AdvancedFilters`
			 * requires as its `currency` prop; this page never reads its fields.
			 */
			currency?: {
				CurrencyFactory?: ( storeSettings?: Record< string, unknown > ) => Record< string, unknown >;
			};
			/**
			 * `@woocommerce/settings`, behind the `wc-settings` handle — read-only
			 * access to data WooCommerce's own admin already registers (WC docs:
			 * `overview-of-data-flow.md`, `wc.wcSettings.getSetting( name, fallback )`).
			 *
			 * ⚠ `wc-settings` is deliberately NOT one of `Orders_Registry::enqueue_assets()`'s
			 * declared script dependencies (measured against WC 11.1.0: it is only
			 * conditionally registered, so a hard dependency on it can drop this
			 * whole bundle silently — see that method's own comment). It is picked
			 * up here opportunistically — present transitively whenever `wc-currency`/
			 * `wc-navigation` are, absent otherwise — for the order-status filter's
			 * options only; the page degrades, omitting that one filter, when it is
			 * absent, same as everywhere else a runtime surface is optional.
			 */
			wcSettings?: {
				getSetting: <T>( name: string, fallback: T ) => T;
			};
		};
	}
}
