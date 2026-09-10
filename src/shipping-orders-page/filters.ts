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

/** Query key the carrier `FilterPicker` owns — carrier SCOPE only, since #835. */
export const CARRIER_PARAM = 'carrier';

/**
 * Query key the display-mode `FilterPicker` owns — «Все заказы» vs
 * «Расширенные фильтры». Split from `CARRIER_PARAM` by operator decision,
 * recorded as a comment on #835, 08.09.2026: WooCommerce's own «Аналитика →
 * Заказы» folds both into one `carrier`-like `Show` picker, but that conflated
 * scope and display mode here — an unrecognised `carrier` value could never be
 * told apart from a deliberate "show advanced filters" pick. Two independent
 * pickers, two independent params.
 */
export const FILTER_PARAM = 'filter';

/** Carrier value meaning "every provider" — the aggregate #694 made the default. */
export const ALL_CARRIERS = 'all';

/**
 * The `filter` value that reveals `AdvancedFilters`. Modelled on WooCommerce's
 * own «Аналитика → Заказы» `Show` picker (rig measurement, 08.09.2026: exactly
 * two options, `All orders` and **`Advanced filters` last** — the advanced
 * block is not a permanently visible region there, it is revealed by the last
 * item of that picker) — except here it is its OWN picker on its OWN param
 * (`FILTER_PARAM`), not folded into the carrier picker (#835, operator
 * decision, 08.09.2026). Two `FilterPicker`s side by side is the native
 * WooCommerce composition for this, not a workaround — see `ReportFilters` in
 * `packages/js/components/src/filters/index.js`, which maps an array of
 * filter configs to one `FilterPicker` each.
 */
export const ADVANCED_FILTERS_VALUE = 'advanced';

/** Whether the advanced-filter block should be shown for this query. */
export function isAdvancedFiltersOpen( query: WcQuery ): boolean {
	return ADVANCED_FILTERS_VALUE === query[ FILTER_PARAM ];
}

/**
 * The query update that turns the advanced-filter block on or off.
 *
 * ⚠ Turning it OFF must also drop every advanced filter, and that is OUR job now.
 * It used to be WooCommerce's: `FilterPicker.update()` special-cases
 * `config.param === 'filter'` and, on any value other than `advanced`, clears the
 * active filters through `getQueryFromActiveFilters( [], … )`
 * (`packages/js/components/src/filter-picker/index.js:174`). The operator replaced
 * that picker with a toggle — a two-state control has no business being a list —
 * so the branch no longer runs and the `*_is` keys would otherwise stay in the URL,
 * invisible, still filtering a table whose filter block is hidden.
 *
 * `undefined` is how `@wordpress/url`'s `addQueryArgs()` removes a key, which is
 * what `updateQueryString()` ends up calling.
 *
 * @param open whether the advanced block should be open.
 * @return the query patch to hand to `wc.navigation.updateQueryString()`.
 */
export function advancedFiltersToggleQuery( open: boolean ): Record<string, string | undefined> {
	if ( open ) {
		return { [ FILTER_PARAM ]: ADVANCED_FILTERS_VALUE };
	}

	return {
		[ FILTER_PARAM ]: undefined,
		[ DELIVERY_STATUS_PARAM ]: undefined,
		[ DELIVERY_STATUS_NOT_PARAM ]: undefined,
		[ ORDER_STATUS_PARAM ]: undefined,
		[ ORDER_STATUS_NOT_PARAM ]: undefined,
		[ HAS_TRACKING_PARAM ]: undefined,
		[ HAS_PICKUP_POINT_PARAM ]: undefined,
		// `AdvancedFilters` writes this itself when its All/Any select is used.
		match: undefined,
	};
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

/**
 * The NEGATIVE rules (#836). WooCommerce composes a filter's URL key as
 * `${filterKey}_${rule}` (`getUrlKey()`, `packages/js/navigation/src/filters.js`), so a
 * second rule on the same filter simply owns a second key. Nothing here is invented.
 *
 * ⚠ Server-side every one of these is a NOT EXISTS group bound to the provider's own
 * marker, never a bare `NOT IN`: a lone `NOT IN` drops rows that have no such meta at
 * all, and an unbound negation OR-ed across providers matches the entire table. Both
 * defects have shipped here already (s127, s128).
 */
export const DELIVERY_STATUS_NOT_PARAM = 'delivery_status_is_not';
export const ORDER_STATUS_NOT_PARAM = 'status_is_not';

/** Presence of a pickup point (#836) — the fourth thing a provider declares a meta key for. */
export const HAS_PICKUP_POINT_PARAM = 'has_pickup_point_is';

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

	// #835: `carrier` and `filter` are independent params now, so nothing here
	// singles out `advanced` any more. An unrecognised carrier reaches the server
	// unchanged, and the server answers it — `Orders_Controller` returns a 400
	// «Неизвестный перевозчик» (`class-orders-controller.php`, pinned by
	// `OrdersRestTest::test_unknown_carrier_is_a_400_not_a_silent_fallback_to_the_aggregate`),
	// which the page now shows above a settled empty table rather than instead of
	// the whole page. Operator decision, 08.09.2026: a hand-edited carrier should
	// say WHY the table is empty, and what matters is only that it no longer
	// widens the selection to every order.
	return value ? value : ALL_CARRIERS;
}

/** '' means "no delivery-status filter" — a value the REST route's own `validate_delivery_status` also treats as valid. */
export function getDeliveryStatusFromQuery( query: WcQuery ): DeliveryStatusCanonical | '' {
	return ( query[ DELIVERY_STATUS_PARAM ] as DeliveryStatusCanonical | undefined ) || '';
}

/** The «не равен» half of the same filter (#836); '' means "not filtering that way". */
export function getDeliveryStatusNotFromQuery( query: WcQuery ): DeliveryStatusCanonical | '' {
	return ( query[ DELIVERY_STATUS_NOT_PARAM ] as DeliveryStatusCanonical | undefined ) || '';
}

/** WC order statuses the merchant asked to EXCLUDE (#836). */
export function getOrderStatusNotFromQuery( query: WcQuery ): string[] {
	const value = query[ ORDER_STATUS_NOT_PARAM ];
	return value ? [ value ] : [];
}

/**
 * Presence of a pickup point. `undefined` means "no filter" — distinct from `false`,
 * exactly like {@link getHasTrackingFromQuery}.
 */
export function getHasPickupPointFromQuery( query: WcQuery ): boolean | undefined {
	const value = query[ HAS_PICKUP_POINT_PARAM ];

	if ( HAS_TRACKING_YES === value ) {
		return true;
	}

	if ( HAS_TRACKING_NO === value ) {
		return false;
	}

	return undefined;
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
	/** #836: the «не равен» rule of the same filter — a separate URL key, not a flag. */
	deliveryStatusNot: DeliveryStatusCanonical | '';
	status: string[];
	/** #836: WC order statuses to EXCLUDE. */
	statusNot: string[];
	hasTracking: boolean | undefined;
	/** #836: presence of a pickup point; `undefined` is "no filter", as with tracking. */
	hasPickupPoint: boolean | undefined;
}

/** Whether two {@link UrlFilters} snapshots represent the same filter state. */
export function filtersEqual( a: UrlFilters, b: UrlFilters ): boolean {
	const sameList = ( x: string[], y: string[] ): boolean =>
		x.length === y.length && x.every( ( value, index ) => value === y[ index ] );

	return (
		a.carrier === b.carrier &&
		a.after === b.after &&
		a.before === b.before &&
		a.deliveryStatus === b.deliveryStatus &&
		a.deliveryStatusNot === b.deliveryStatusNot &&
		a.hasTracking === b.hasTracking &&
		a.hasPickupPoint === b.hasPickupPoint &&
		sameList( a.status, b.status ) &&
		sameList( a.statusNot, b.statusNot )
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
 * Builds the `AdvancedFilters` config: four filters, each with its OWN rules (#836).
 *
 * The set is bounded by what `Orders_Provider` actually declares a meta key for, measured
 * on the rig 09.09.2026 and recorded on #836. Two candidates were REJECTED by that
 * measurement rather than by taste:
 *
 * - **«Ожидает оплаты»** — `needs_payment()` is COMPUTED from status and total and stored
 *   nowhere, so the database cannot be asked about it. The control would look alive and
 *   filter nothing.
 * - **Метод доставки** — `get_method_ids()` declares an INCOMPLETE list (3 rig orders use a
 *   method no provider names), so the filter would silently lose rows. Card #842.
 *
 * ⚠ And two SHAPES are not available, which is why the operator's «сперва тип сравнения,
 * поле появляется только для нужных правил» is not built literally:
 *
 * 1. `AdvancedFilters` picks its input component per FILTER, never per rule
 *    (`componentMap` in `advanced-filters/item.tsx`), and an unknown component name renders
 *    NOTHING at all, silently — the map is closed.
 * 2. `getQueryFromActiveFilters()` skips any active filter whose `value` is falsy, so a
 *    rule that carries no value never reaches the URL. Presence therefore has to be a
 *    two-option select, not a valueless rule.
 *
 * Free-text matching on a tracking number needs the `Search` component, which requires an
 * autocompleter and `getLabels` — a separate piece of work, not a rule.
 *
 * `orderStatusOptions` is optional and omits that one filter when absent — the same
 * degrade-rather-than-crash rule `app.tsx`'s `RoiPanel` already follows — because the
 * option list has no framework-owned source; it comes from `window.wc.wcSettings`.
 */
export function buildAdvancedFiltersConfig(
	deliveryStatusLabels: Record<DeliveryStatusCanonical, string>,
	orderStatusOptions?: Record<string, string>,
	reachableDeliveryStatuses: string[] = []
): WcAdvancedFiltersConfig {
	const isRule = { value: 'is', label: __( 'равен', 'woodev-plugin-framework' ) };

	/**
	 * ⚠ The negation is honest ONLY because the server builds it as a `NOT EXISTS` group
	 * bound to each provider's own marker. A bare `NOT IN` drops every row that has no such
	 * meta at all, and an unbound negation OR-ed across providers matches the entire table —
	 * both have shipped here (s127 `has_tracking`, s128 `delivery_status=unknown`).
	 */
	const isNotRule = { value: 'is_not', label: __( 'не равен', 'woodev-plugin-framework' ) };

	/**
	 * ⚠ Only the states this shop can actually PRODUCE (#837 defect 4). The list used to
	 * be every canonical state, and one of them was unreachable everywhere measured: no
	 * carrier maps a raw status to `pending`, so «Ожидает отправки» returned an empty
	 * table and read as a broken filter. The server derives the reachable set from the
	 * providers' own `status_map`s.
	 *
	 * An EMPTY list means the bootstrap did not state it — not «this shop produces
	 * nothing» — so we fall back to the full set rather than rendering a filter with no
	 * options, which would be a worse version of the same defect. The server always
	 * includes `unknown`, so a real answer is never empty.
	 */
	const deliveryStatusOptions = Object.entries( deliveryStatusLabels )
		.filter(
			( [ value ] ) =>
				reachableDeliveryStatuses.length === 0 || reachableDeliveryStatuses.includes( value )
		)
		.map( ( [ value, label ] ) => ( { value, label } ) );

	/** Presence is a two-option select, not a valueless rule — see the module doc above. */
	const presenceOptions = [
		{ value: HAS_TRACKING_YES, label: __( 'Есть', 'woodev-plugin-framework' ) },
		{ value: HAS_TRACKING_NO, label: __( 'Отсутствует', 'woodev-plugin-framework' ) },
	];

	const filters: WcAdvancedFiltersConfig[ 'filters' ] = {
		delivery_status: {
			labels: {
				add: __( 'Статус доставки', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по статусу доставки', 'woodev-plugin-framework' ),
				title: __( 'Статус доставки {{rule /}} {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule, isNotRule ],
			input: {
				component: 'SelectControl',
				options: deliveryStatusOptions,
			},
			allowMultiple: false,
		},
		has_tracking: {
			labels: {
				add: __( 'Трек-номер', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по трек-номеру', 'woodev-plugin-framework' ),
				title: __( 'Трек-номер {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule ],
			input: { component: 'SelectControl', options: presenceOptions },
			allowMultiple: false,
		},
		has_pickup_point: {
			labels: {
				add: __( 'Пункт выдачи', 'woodev-plugin-framework' ),
				remove: __( 'Убрать фильтр по пункту выдачи', 'woodev-plugin-framework' ),
				title: __( 'Пункт выдачи {{filter /}}', 'woodev-plugin-framework' ),
			},
			rules: [ isRule ],
			input: { component: 'SelectControl', options: presenceOptions },
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
			rules: [ isRule, isNotRule ],
			input: {
				component: 'SelectControl',
				options: Object.entries( orderStatusOptions ).map( ( [ value, label ] ) => ( {
					value,
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
