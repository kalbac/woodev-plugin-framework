/**
 * Pure URL <-> filter-state translation for the filter row (SP-10 spec D10/D11,
 * increment 7 — client half). Everything here takes plain query objects (and,
 * for the date helpers, the `window.wc.date` module as a parameter) and returns
 * plain data — no `window` access of its own, so it stays trivially
 * unit-testable, the same reason `columns.ts` never imports a component.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import type { DeliveryStatusCanonical } from './rest';
import type {
	WcAdvancedFiltersConfig,
	WcDateParams,
	WcDateRangeFilterPickerDateQuery,
	WcDateValue,
} from './wc-globals';

type WcQuery = Record<string, string | undefined>;

/** Query key `FilterPicker` (the existing carrier control) already owns. */
export const CARRIER_PARAM = 'carrier';

/** Carrier value meaning "every provider" — the aggregate #694 made the default. */
export const ALL_CARRIERS = 'all';

/**
 * The `carrier` value that reveals `AdvancedFilters` instead of scoping to a
 * carrier. Measured against WooCommerce's own «Аналитика → Заказы» on the rig
 * (08.09.2026): its «Show» picker carries exactly two options, `All orders` and
 * **`Advanced filters` last** — the advanced block is not a permanently visible
 * region there, it is revealed by the last item of that same picker. Operator
 * asked for the same shape, 08.09.2026.
 *
 * It shares the `carrier` parameter because `FilterPicker` owns exactly one, and
 * the two are mutually exclusive in the reference too: choosing the advanced view
 * scopes to every carrier.
 */
export const ADVANCED_FILTERS_VALUE = 'advanced';

/** Whether the advanced-filter block should be shown for this query. */
export function isAdvancedFiltersOpen( query: WcQuery ): boolean {
	return ADVANCED_FILTERS_VALUE === query[ CARRIER_PARAM ];
}

/**
 * `AdvancedFilters` query keys. Every filter below uses a single `is` rule and
 * `allowMultiple: false`, so each produces exactly one key in WooCommerce's own
 * `{filterKey}_{rule}=value` convention (confirmed against
 * `packages/js/components/src/advanced-filters/README.md`'s own worked
 * example, `status_is=pending` — not recalled).
 */
export const DELIVERY_STATUS_PARAM = 'delivery_status_is';
export const ORDER_STATUS_PARAM = 'status_is';
export const HAS_TRACKING_PARAM = 'has_tracking_is';

/** `has_tracking_is` values — `AdvancedFilters`' `SelectControl` input only ever carries strings. */
export const HAS_TRACKING_YES = 'yes';
export const HAS_TRACKING_NO = 'no';

/**
 * WooCommerce's date picker has no "all time" preset (D11) — the operator chose
 * the widest one instead: a delivery-orders list is a work queue, and an order
 * stuck two months ago is exactly the one a merchant opens the page for.
 *
 * ⚠ `compare` MUST be present here even though this page shows no period
 * comparison and never sends `compare` to our REST route. `getCurrentDates()`
 * resolves the compare value against a fixed list and throws
 * `Cannot find compare:` when it is absent — which crashes the whole wc-admin
 * app, not just this control. D11's "no period comparison" is about the UI and
 * the server args; it is not licence to break `@woocommerce/date`'s own input
 * contract. WooCommerce's own default is `period=month&compare=previous_year`;
 * only the period differs here.
 */
export const DEFAULT_DATE_RANGE = 'period=year&compare=previous_year';

export function getCarrierFromQuery( query: WcQuery ): string {
	const value = query[ CARRIER_PARAM ];

	// `advanced` selects a VIEW, not a carrier — the rows stay unscoped, exactly
	// as Analytics' own `Advanced filters` option leaves the report scope alone.
	if ( ! value || ADVANCED_FILTERS_VALUE === value ) {
		return ALL_CARRIERS;
	}

	return value;
}

/** '' means "no delivery-status filter" — a value the REST route's own `validate_delivery_status` also treats as valid. */
export function getDeliveryStatusFromQuery( query: WcQuery ): DeliveryStatusCanonical | '' {
	return ( query[ DELIVERY_STATUS_PARAM ] as DeliveryStatusCanonical | undefined ) || '';
}

/**
 * `status_is` carries one WC order-status slug (`allowMultiple: false` — see
 * the module doc). `fetchOrders()` still takes an array, because that is the
 * REST route's own shape; this just wraps the single value into a one-element one.
 */
export function getOrderStatusFromQuery( query: WcQuery ): string[] {
	const value = query[ ORDER_STATUS_PARAM ];
	return value ? [ value ] : [];
}

/**
 * `undefined` means "no filter" — distinct from `false`. D10: the REST route's
 * `has_tracking` arg carries no default, and its PRESENCE, not its truthiness,
 * decides whether the query applies it at all.
 */
export function getHasTrackingFromQuery( query: WcQuery ): boolean | undefined {
	const value = query[ HAS_TRACKING_PARAM ];

	if ( HAS_TRACKING_YES === value ) {
		return true;
	}

	if ( HAS_TRACKING_NO === value ) {
		return false;
	}

	return undefined;
}

/** One comparable snapshot of every URL-driven filter — used to decide whether a change should reset `paged` to 1 (requirement #4). */
export interface UrlFilters {
	carrier: string;
	after: string;
	before: string;
	deliveryStatus: DeliveryStatusCanonical | '';
	status: string[];
	hasTracking: boolean | undefined;
}

/** Whether two {@link UrlFilters} snapshots represent the same filter state. */
export function filtersEqual( a: UrlFilters, b: UrlFilters ): boolean {
	return (
		a.carrier === b.carrier &&
		a.after === b.after &&
		a.before === b.before &&
		a.deliveryStatus === b.deliveryStatus &&
		a.hasTracking === b.hasTracking &&
		a.status.length === b.status.length &&
		a.status.every( ( value, index ) => value === b.status[ index ] )
	);
}

/** The minimal `window.wc.date` surface {@link readDateFilters} needs, taken as a parameter so this stays testable with a stub. */
export interface WcDateApi {
	getDateParamsFromQuery: ( query: WcQuery, defaultDateRange: string ) => WcDateParams;
	getCurrentDates: (
		query: WcQuery,
		defaultDateRange: string
	) => { primary: WcDateValue; secondary: WcDateValue };
	isoDateFormat: string;
}

export interface DateFilterState {
	/** `DateRangeFilterPicker`'s own required prop. */
	dateQuery: WcDateRangeFilterPickerDateQuery;
	/** Resolved ISO `after`/`before` for `fetchOrders()` — `compare` never reaches it (D11). */
	after: string;
	before: string;
}

/**
 * Resolves `period`/`compare`/custom `before`/`after` out of the URL into both
 * `DateRangeFilterPicker`'s `dateQuery` prop and the plain ISO `after`/`before`
 * the REST route actually understands. `@woocommerce/date` is what turns a
 * period preset into real dates (D11's own contract note); `compare` is read
 * only because `dateQuery` must carry it, and is dropped before it ever
 * reaches {@link import('./rest').fetchOrders}.
 */
export function readDateFilters( dateApi: WcDateApi, query: WcQuery ): DateFilterState {
	const params = dateApi.getDateParamsFromQuery( query, DEFAULT_DATE_RANGE );
	const { primary, secondary } = dateApi.getCurrentDates( query, DEFAULT_DATE_RANGE );

	return {
		dateQuery: {
			period: params.period,
			compare: params.compare,
			before: params.before,
			after: params.after,
			primaryDate: primary,
			secondaryDate: secondary,
		},
		after: primary.after ? primary.after.format( dateApi.isoDateFormat ) : '',
		before: primary.before ? primary.before.format( dateApi.isoDateFormat ) : '',
	};
}

/**
 * Builds the `AdvancedFilters` config for the three filters D10 measured as
 * actually reachable — delivery status, WC order status, tracking presence.
 * No delivery-type entry: D10 found three hops ending in the shipping zones,
 * with nothing to query.
 *
 * `orderStatusOptions` is optional and omits that one filter when absent — the
 * same degrade-rather-than-crash rule `app.tsx`'s `RoiPanel` already follows —
 * because the option list has no framework-owned source; it comes from
 * `window.wc.wcSettings`, a WooCommerce Core admin setting this page only
 * reads defensively.
 */
export function buildAdvancedFiltersConfig(
	deliveryStatusLabels: Record<DeliveryStatusCanonical, string>,
	orderStatusOptions?: Record<string, string>
): WcAdvancedFiltersConfig {
	/** Every filter below uses this single rule — there is nothing server-side to negate ("is not") against. */
	const isRule = { value: 'is', label: __( 'равен', 'woodev-plugin-framework' ) };

	const filters: WcAdvancedFiltersConfig[ 'filters' ] = {
		delivery_status: {
			labels: {
				add: __( 'Статус доставки', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по статусу доставки', 'woodev-plugin-framework' ),
				title: __( 'Статус доставки {{rule /}} {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule ],
			input: {
				component: 'SelectControl',
				options: Object.entries( deliveryStatusLabels ).map( ( [ key, label ] ) => ( {
					key,
					label,
				} ) ),
			},
			allowMultiple: false,
		},
		has_tracking: {
			labels: {
				add: __( 'Трек-номер', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по трек-номеру', 'woodev-plugin-framework' ),
				title: __( 'Трек-номер {{rule /}} {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule ],
			input: {
				component: 'SelectControl',
				options: [
					{ key: HAS_TRACKING_YES, label: __( 'Есть', 'woodev-plugin-framework' ) },
					{ key: HAS_TRACKING_NO, label: __( 'Отсутствует', 'woodev-plugin-framework' ) },
				],
			},
			allowMultiple: false,
		},
	};

	if ( orderStatusOptions && Object.keys( orderStatusOptions ).length > 0 ) {
		filters.status = {
			labels: {
				add: __( 'Статус заказа', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по статусу заказа', 'woodev-plugin-framework' ),
				title: __( 'Статус заказа {{rule /}} {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule ],
			input: {
				component: 'SelectControl',
				options: Object.entries( orderStatusOptions ).map( ( [ key, label ] ) => ( {
					key,
					label,
				} ) ),
			},
			allowMultiple: false,
		};
	}

	return {
		title: __( 'Заказы соответствуют условиям', 'woodev-plugin-framework' ),
		filters,
	};
}
