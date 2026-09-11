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
import type { WcAdvancedFiltersConfig, WcDateParams, WcDateValue } from './wc-globals';

export type WcQuery = Record<string, string | undefined>;

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

/**
 * Query key the «Все / Новые» scope links own (#841). Its own param, like every
 * other filter here, so the view is linkable and the browser's back button works
 * on it — the links are real `href`s that navigate, not buttons that call back.
 *
 * ⚠ It is NOT one of the advanced-filter keys and must never be cleared by
 * {@link advancedFiltersToggleQuery}: the scope is which work queue the merchant is
 * looking at, not one of the pointwise conditions the toggle reveals. Closing the
 * advanced block while standing in «Новые» must leave them in «Новые».
 */
export const SCOPE_PARAM = 'scope';

/**
 * The `scope` value for «Новые» — orders the carrier has no order id for yet.
 * «Все» is the ABSENCE of the param rather than a value of its own, the same way
 * this page expresses every other "no filter" state, so the default view carries a
 * clean URL and an unrecognised value degrades to it.
 */
export const NEW_SCOPE = 'new';

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

/** The URL keys the «Период» control owns (#855). */
export const PERIOD_PARAM = 'period';
export const COMPARE_PARAM = 'compare';
export const AFTER_PARAM = 'after';
export const BEFORE_PARAM = 'before';

/**
 * The `period` value that means "a hand-picked pair of dates" — `@woocommerce/date`'s
 * own spelling, not ours, and the ONLY period value that also requires `after`/`before`.
 */
export const CUSTOM_PERIOD = 'custom';

/**
 * «Всё время» is the ABSENCE of `period`, exactly like «Все» is the absence of
 * {@link SCOPE_PARAM} — not a value of its own (#855).
 *
 * ⚠ It cannot be a value. `@woocommerce/date`'s `getCurrentDates()` resolves the period
 * against its own fixed `presetValues` list and throws `Cannot find period: all` for
 * anything else, and that throw takes the WHOLE wc-admin app down, not just this
 * control (measured by the operator on the rig, 12.09.2026). So an unknown period never
 * reaches `wc.date` — {@link getPeriodFromQuery} degrades it to «всё время» here.
 */
export const ALL_TIME_PERIOD = '';

/**
 * ⚠ `compare` travels with every `period` we write, and NOT because of the dropdown
 * WooCommerce draws for it — this page renders no comparison control at all (#855).
 *
 * It is load-bearing twice over, both measured off the shipped `wc-date` bundle
 * (WooCommerce 10.9.4, `assets/client/admin/date/index.js`):
 *
 * 1. `getDateParamsFromQuery()` reads the query's own `after`/`before` ONLY when the
 *    query carries BOTH `period` and `compare` (`if (period && compare) return {…}`);
 *    otherwise it discards them and resolves the default range instead. Write
 *    `period=custom&after=…&before=…` without `compare` and the merchant's own dates
 *    are silently replaced by the default range's.
 * 2. `getCurrentDates()` resolves the compare value against a fixed list and throws
 *    `Cannot find compare:` when it is absent — again taking the whole app down.
 *
 * `previous_year` is WooCommerce's own default and nothing here reads it back.
 */
export const DEFAULT_COMPARE = 'previous_year';

/**
 * The period presets, in WooCommerce's own order and with WooCommerce's own values —
 * `presetValues` from the shipped `wc-date` bundle, `custom` excluded because it is
 * reached through «Произвольный период» and needs two dates beside it.
 *
 * The labels are ours: WooCommerce's «Week to date» means "from the start of the week
 * until today", which is what «С начала недели» says in Russian without the jargon.
 *
 * ⚠ This list is also the GUARD. A `period` the list does not know never reaches
 * `wc.date` (see {@link ALL_TIME_PERIOD}), so it has to hold every value the control can
 * write, and only those.
 */
export const PERIOD_PRESETS: { value: string; label: string }[] = [
	{ value: 'today', label: __( 'Сегодня', 'woodev-plugin-framework' ) },
	{ value: 'yesterday', label: __( 'Вчера', 'woodev-plugin-framework' ) },
	{ value: 'week', label: __( 'С начала недели', 'woodev-plugin-framework' ) },
	{ value: 'last_week', label: __( 'Прошлая неделя', 'woodev-plugin-framework' ) },
	{ value: 'month', label: __( 'С начала месяца', 'woodev-plugin-framework' ) },
	{ value: 'last_month', label: __( 'Прошлый месяц', 'woodev-plugin-framework' ) },
	{ value: 'quarter', label: __( 'С начала квартала', 'woodev-plugin-framework' ) },
	{ value: 'last_quarter', label: __( 'Прошлый квартал', 'woodev-plugin-framework' ) },
	{ value: 'year', label: __( 'С начала года', 'woodev-plugin-framework' ) },
	{ value: 'last_year', label: __( 'Прошлый год', 'woodev-plugin-framework' ) },
];

/**
 * A REQUIRED ARGUMENT OF SOMEONE ELSE'S API, and nothing more (#855).
 *
 * ⚠ It is NOT this page's default period. The page's default is «всё время» — no
 * `period`, no `after`, no `before` in the URL and no date bound on the query — and
 * {@link readDateFilters} answers that state itself, without asking `wc.date`
 * anything. The `year` in here is a leftover of the period that default USED to be,
 * and a docblock explaining that choice would be explaining a decision that no longer
 * exists.
 *
 * `getCurrentDates( query, defaultDateRange )` takes this second argument and falls
 * back to it whenever the query carries no `period`+`compare` pair. {@link
 * readDateFilters} always hands it a complete pair, so the fallback is unreachable —
 * but the parameter is not optional, and passing a string that would THROW if it ever
 * were reached is not a saving.
 */
export const DEFAULT_DATE_RANGE = `period=year&compare=${ DEFAULT_COMPARE }`;

/**
 * Which period the URL is currently asking for: {@link ALL_TIME_PERIOD}, one of
 * {@link PERIOD_PRESETS}' values, or {@link CUSTOM_PERIOD}.
 *
 * Everything else — an unknown preset, or `custom` without BOTH dates beside it —
 * reads as «всё время». That is not tidiness: both would throw inside `@woocommerce/date`
 * and take the entire wc-admin app down with them (`Cannot find period: X`, and
 * `Custom date range requires both after and before dates.`), so the degrade has to
 * happen HERE, at the boundary, and not at each place that reads the period.
 */
export function getPeriodFromQuery( query: WcQuery ): string {
	const period = query[ PERIOD_PARAM ];

	if ( ! period ) {
		return ALL_TIME_PERIOD;
	}

	if ( CUSTOM_PERIOD === period ) {
		return query[ AFTER_PARAM ] && query[ BEFORE_PARAM ] ? CUSTOM_PERIOD : ALL_TIME_PERIOD;
	}

	return PERIOD_PRESETS.some( ( preset ) => preset.value === period )
		? period
		: ALL_TIME_PERIOD;
}

/**
 * The query patch one period pick navigates to (#855).
 *
 * «Всё время» REMOVES all four keys (`undefined` is how `@wordpress/url`'s
 * `addQueryArgs()` drops one, which is what `updateQueryString()` ends up calling), so
 * the default view's URL stays clean and there is exactly one spelling of it — the same
 * rule {@link scopeQuery} follows. `compare` goes with them: it exists only to keep
 * `@woocommerce/date`'s input contract for a period we are no longer asking about.
 *
 * @param period the picked period: {@link ALL_TIME_PERIOD}, a preset value, or {@link CUSTOM_PERIOD}.
 * @param after  ISO `YYYY-MM-DD` lower bound — required for, and only read for, {@link CUSTOM_PERIOD}.
 * @param before ISO `YYYY-MM-DD` upper bound — likewise.
 * @return the query patch to hand to `wc.navigation.updateQueryString()`.
 */
export function periodQuery(
	period: string,
	after = '',
	before = ''
): Record< string, string | undefined > {
	if ( CUSTOM_PERIOD === period ) {
		return {
			[ PERIOD_PARAM ]: CUSTOM_PERIOD,
			[ COMPARE_PARAM ]: DEFAULT_COMPARE,
			[ AFTER_PARAM ]: after,
			[ BEFORE_PARAM ]: before,
		};
	}

	if ( ALL_TIME_PERIOD === period ) {
		return {
			[ PERIOD_PARAM ]: undefined,
			[ COMPARE_PARAM ]: undefined,
			[ AFTER_PARAM ]: undefined,
			[ BEFORE_PARAM ]: undefined,
		};
	}

	return {
		[ PERIOD_PARAM ]: period,
		[ COMPARE_PARAM ]: DEFAULT_COMPARE,
		// A preset resolves its own dates, so leftover custom ones would both
		// contradict it and — through `getDateParamsFromQuery()` — survive into the
		// next custom pick as its starting value.
		[ AFTER_PARAM ]: undefined,
		[ BEFORE_PARAM ]: undefined,
	};
}

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

/**
 * Which scope link is the current one (#841). Anything other than {@link NEW_SCOPE} —
 * absent, empty, or a hand-edited value — reads as «Все», so a broken URL shows the
 * whole queue rather than an empty table under a link that looks unselected.
 */
export function getScopeFromQuery( query: WcQuery ): 'all' | typeof NEW_SCOPE {
	return NEW_SCOPE === query[ SCOPE_PARAM ] ? NEW_SCOPE : 'all';
}

/**
 * The `is_exported` value a scope sends to {@link import('./rest').fetchOrders} —
 * `false` for «Новые», `undefined` (i.e. "do not filter on export state at all")
 * for «Все».
 *
 * Derived HERE, at the boundary, rather than at the call site: `is_exported` is a
 * tri-state whose PRESENCE is what the REST route reads, and the one place that
 * knows «Все» means absence and not `true` is this module.
 */
export function isExportedForScope( scope: string ): boolean | undefined {
	return NEW_SCOPE === scope ? false : undefined;
}

/**
 * The query patch one scope link navigates to. «Все» REMOVES the key (`undefined` is
 * how `@wordpress/url`'s `addQueryArgs()` drops one) instead of writing `scope=all`,
 * so the default view's URL stays clean and there is exactly one spelling of it.
 */
export function scopeQuery( scope: string ): Record<string, string | undefined> {
	return { [ SCOPE_PARAM ]: NEW_SCOPE === scope ? NEW_SCOPE : undefined };
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
	/**
	 * #841: which of the «Все / Новые» links is current. In this snapshot — and so in
	 * {@link filtersEqual} — because it scopes the fetch: leave it out and switching
	 * scope neither refetches nor resets the page, while a navigation that changed
	 * only the scope would read as "no filter changed" (#850's guard).
	 */
	scope: string;
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
		a.scope === b.scope &&
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
	/** Resolved ISO `after`/`before` for `fetchOrders()` — `compare` never reaches it (D11). */
	after: string;
	before: string;
}

/**
 * Resolves the URL's `period`/`compare`/`after`/`before` into the plain ISO
 * `after`/`before` the REST route actually understands. `@woocommerce/date` is what
 * turns a period preset into real dates; `compare` is written only to satisfy its input
 * contract (see {@link DEFAULT_COMPARE}) and is dropped before it ever reaches
 * {@link import('./rest').fetchOrders}.
 *
 * ⚠ An EMPTY pair is «всё время», not «не удалось» (#855). It is this page's default
 * state and its own answer: `fetchOrders()` omits an empty `after`/`before` entirely
 * (`rest.ts`), so no date bound reaches the query — which is the whole point, because
 * a work queue's oldest stuck order is exactly the one the merchant opens the page for.
 * It is returned WITHOUT asking `wc.date` anything, because there is no period to
 * resolve and `@woocommerce/date` has no "all time" to resolve it into.
 */
export function readDateFilters( dateApi: WcDateApi, query: WcQuery ): DateFilterState {
	const period = getPeriodFromQuery( query );

	if ( ALL_TIME_PERIOD === period ) {
		return { after: '', before: '' };
	}

	/**
	 * ⚠ `wc.date` is handed a query BUILT HERE, never the raw URL one. Every input
	 * `getCurrentDates()` rejects it rejects by THROWING, and the throw escapes this
	 * page and kills the whole wc-admin app — an unknown period, a `custom` missing one
	 * of its two dates, an absent `compare`. {@link getPeriodFromQuery} has already
	 * decided which of those the URL is, so passing the decision instead of the raw
	 * query is what makes those branches unreachable rather than merely unlikely.
	 */
	const { primary } = dateApi.getCurrentDates(
		{
			[ PERIOD_PARAM ]: period,
			[ COMPARE_PARAM ]: DEFAULT_COMPARE,
			[ AFTER_PARAM ]: query[ AFTER_PARAM ],
			[ BEFORE_PARAM ]: query[ BEFORE_PARAM ],
		},
		DEFAULT_DATE_RANGE
	);

	return {
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
