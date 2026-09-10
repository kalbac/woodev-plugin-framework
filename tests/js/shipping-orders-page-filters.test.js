/**
 * Tests for the pure URL <-> filter-state translation (SP-10 spec D10/D11,
 * increment 7).
 *
 * @see src/shipping-orders-page/filters.ts
 */

import {
	ADVANCED_FILTERS_VALUE,
	ALL_CARRIERS,
	DEFAULT_DATE_RANGE,
	FILTER_PARAM,
	advancedFiltersToggleQuery,
	buildAdvancedFiltersConfig,
	filtersEqual,
	getCarrierFromQuery,
	getDeliveryStatusFromQuery,
	getDeliveryStatusNotFromQuery,
	getHasPickupPointFromQuery,
	getHasTrackingFromQuery,
	getOrderStatusFromQuery,
	getOrderStatusNotFromQuery,
	getScopeFromQuery,
	isAdvancedFiltersOpen,
	isExportedForScope,
	readDateFilters,
	scopeQuery,
} from '../../src/shipping-orders-page/filters';

function fakeMoment( isoString ) {
	return { format: () => isoString };
}

describe( 'getCarrierFromQuery', () => {
	test( 'an absent carrier param is the aggregate', () => {
		expect( getCarrierFromQuery( {} ) ).toBe( ALL_CARRIERS );
	} );

	test( 'a present carrier param is returned verbatim', () => {
		expect( getCarrierFromQuery( { carrier: 'cdek' } ) ).toBe( 'cdek' );
	} );

	/**
	 * #835: `carrier` and `filter` are independent params now — an unrecognised
	 * (or `advanced`) carrier must reach the server UNCHANGED, no client-side
	 * coercion to the aggregate. `Orders_Query::resolve_providers()` already
	 * answers an unknown carrier with an empty result set.
	 */
	test( 'an unrecognized carrier value — including "advanced" — is returned verbatim, never coerced', () => {
		expect( getCarrierFromQuery( { carrier: 'advanced' } ) ).toBe( 'advanced' );
		expect( getCarrierFromQuery( { carrier: 'nonexistent-carrier' } ) ).toBe( 'nonexistent-carrier' );
	} );
} );

describe( 'isAdvancedFiltersOpen', () => {
	/** #835: the advanced block is revealed by `FILTER_PARAM`, not `CARRIER_PARAM`. */
	test( 'reads the filter param, not the carrier param', () => {
		expect( isAdvancedFiltersOpen( { [ FILTER_PARAM ]: ADVANCED_FILTERS_VALUE } ) ).toBe( true );
		expect( isAdvancedFiltersOpen( { carrier: ADVANCED_FILTERS_VALUE } ) ).toBe( false );
	} );

	test( 'any other filter value, or no filter param at all, is closed', () => {
		expect( isAdvancedFiltersOpen( {} ) ).toBe( false );
		expect( isAdvancedFiltersOpen( { [ FILTER_PARAM ]: 'all' } ) ).toBe( false );
	} );
} );

describe( 'getDeliveryStatusFromQuery', () => {
	test( 'no delivery_status_is param means no filter', () => {
		expect( getDeliveryStatusFromQuery( {} ) ).toBe( '' );
	} );

	test( 'reads the value verbatim', () => {
		expect( getDeliveryStatusFromQuery( { delivery_status_is: 'in_transit' } ) ).toBe( 'in_transit' );
	} );
} );

describe( 'getOrderStatusFromQuery', () => {
	test( 'no status_is param is an empty array — not [""]', () => {
		expect( getOrderStatusFromQuery( {} ) ).toEqual( [] );
	} );

	test( 'wraps the single value into a one-element array — the REST route`s own shape', () => {
		expect( getOrderStatusFromQuery( { status_is: 'processing' } ) ).toEqual( [ 'processing' ] );
	} );
} );

describe( 'getHasTrackingFromQuery', () => {
	test( 'absent stays undefined — distinct from false', () => {
		expect( getHasTrackingFromQuery( {} ) ).toBeUndefined();
	} );

	test( '"yes" is true', () => {
		expect( getHasTrackingFromQuery( { has_tracking_is: 'yes' } ) ).toBe( true );
	} );

	test( '"no" is false, not falsy-and-therefore-undefined', () => {
		expect( getHasTrackingFromQuery( { has_tracking_is: 'no' } ) ).toBe( false );
	} );

	test( 'an unrecognized value is treated as absent', () => {
		expect( getHasTrackingFromQuery( { has_tracking_is: 'maybe' } ) ).toBeUndefined();
	} );
} );

describe( 'filtersEqual', () => {
	// #836 added three dimensions; a snapshot missing one is not a valid UrlFilters, so the
	// base here carries every field the type declares.
	const base = {
		carrier: 'all',
		scope: 'all',
		after: '',
		before: '',
		deliveryStatus: '',
		deliveryStatusNot: '',
		status: [],
		statusNot: [],
		hasTracking: undefined,
		hasPickupPoint: undefined,
	};

	test( 'two identical snapshots are equal', () => {
		expect( filtersEqual( base, { ...base } ) ).toBe( true );
	} );

	test( 'a different carrier is not equal', () => {
		expect( filtersEqual( base, { ...base, carrier: 'cdek' } ) ).toBe( false );
	} );

	/**
	 * ⚠ #841. The scope has to be part of this comparison, not merely part of the URL:
	 * `filtersEqual` is what decides whether a navigation refetches and resets the page
	 * (#850's guard), so a scope left out of it would switch the link's `current` mark
	 * and go on showing the previous scope's rows.
	 */
	test( 'a different scope is not equal', () => {
		expect( filtersEqual( base, { ...base, scope: 'new' } ) ).toBe( false );
		expect( filtersEqual( { ...base, scope: 'new' }, { ...base, scope: 'new' } ) ).toBe( true );
	} );

	test( 'a different date range is not equal', () => {
		expect( filtersEqual( base, { ...base, after: '2026-01-01' } ) ).toBe( false );
	} );

	test( 'a different delivery status is not equal', () => {
		expect( filtersEqual( base, { ...base, deliveryStatus: 'delivered' } ) ).toBe( false );
	} );

	test( 'a different hasTracking (including undefined vs false) is not equal', () => {
		expect( filtersEqual( base, { ...base, hasTracking: false } ) ).toBe( false );
		expect( filtersEqual( { ...base, hasTracking: true }, { ...base, hasTracking: false } ) ).toBe( false );
	} );

	test( 'status arrays compare by content, not by reference', () => {
		expect( filtersEqual( { ...base, status: [ 'processing' ] }, { ...base, status: [ 'processing' ] } ) ).toBe(
			true
		);
		expect( filtersEqual( { ...base, status: [ 'processing' ] }, { ...base, status: [ 'completed' ] } ) ).toBe(
			false
		);
		expect( filtersEqual( { ...base, status: [] }, { ...base, status: [ 'processing' ] } ) ).toBe( false );
	} );
} );

describe( 'readDateFilters', () => {
	/** The compare values `@woocommerce/date` actually accepts — measured off the shipped bundle. */
	const ALLOWED_COMPARE = [ 'previous_period', 'previous_year' ];

	/**
	 * Mirrors `@woocommerce/date`'s real contract, including the part that bit us:
	 * `getCurrentDates()` resolves `compare` against a fixed list and THROWS
	 * `Cannot find compare:` when it is absent. The previous fake silently
	 * defaulted it, so a default range carrying no `compare` passed every test
	 * and crashed the whole wc-admin app on the rig.
	 */
	function fakeDateApi( { period, before, after } = {} ) {
		const fromDefault = ( defaultDateRange ) =>
			Object.fromEntries( new URLSearchParams( defaultDateRange ).entries() );

		return {
			getDateParamsFromQuery: ( query, defaultDateRange ) => {
				const defaults = fromDefault( defaultDateRange );

				return {
					period: query.period || defaults.period,
					compare: query.compare || defaults.compare,
					before: query.before ? fakeMoment( query.before ) : null,
					after: query.after ? fakeMoment( query.after ) : null,
				};
			},
			getCurrentDates: ( query, defaultDateRange ) => {
				const defaults = fromDefault( defaultDateRange );
				const compare = query.compare || defaults.compare;

				if ( ! ALLOWED_COMPARE.includes( compare ) ) {
					throw new Error( `Cannot find compare: ${ compare || '' }` );
				}

				return {
					primary: {
						label: 'range',
						range: '',
						before: fakeMoment( query.before || before || '2026-09-08' ),
						after: fakeMoment( query.after || after || '2026-01-01' ),
					},
					secondary: { label: 'previous range', range: '', before: null, after: null },
				};
			},
			isoDateFormat: 'YYYY-MM-DD',
		};
	}

	/**
	 * The regression guard for the rig crash: `DEFAULT_DATE_RANGE` must carry a
	 * `compare` even though this page renders no comparison control and never
	 * sends `compare` to our REST route. Without it `getCurrentDates()` throws
	 * and takes the entire WooCommerce admin app down with it, not just the
	 * filter.
	 */
	test( 'DEFAULT_DATE_RANGE carries both a period and an accepted compare', () => {
		const parsed = Object.fromEntries( new URLSearchParams( DEFAULT_DATE_RANGE ).entries() );

		expect( parsed.period ).toBe( 'year' );
		expect( ALLOWED_COMPARE ).toContain( parsed.compare );
	} );

	test( 'an empty query resolves the default period into after/before', () => {
		const result = readDateFilters( fakeDateApi(), {} );

		expect( result.after ).toBe( '2026-01-01' );
		expect( result.before ).toBe( '2026-09-08' );
		expect( result.dateQuery.period ).toBe( 'year' );
		expect( result.dateQuery.compare ).toBe( 'previous_year' );
	} );

	test( 'a custom range in the query resolves to its own after/before', () => {
		const result = readDateFilters( fakeDateApi(), {
			period: 'custom',
			before: '2026-03-01',
			after: '2026-02-01',
		} );

		expect( result.after ).toBe( '2026-02-01' );
		expect( result.before ).toBe( '2026-03-01' );
		expect( result.dateQuery.period ).toBe( 'custom' );
	} );

	test( 'a missing primary.after/before resolves to an empty string, never a crash', () => {
		const dateApi = {
			getDateParamsFromQuery: () => ( { period: 'year', compare: 'previous_period', before: null, after: null } ),
			getCurrentDates: () => ( {
				primary: { label: '', range: '', before: null, after: null },
				secondary: { label: '', range: '', before: null, after: null },
			} ),
			isoDateFormat: 'YYYY-MM-DD',
		};

		const result = readDateFilters( dateApi, {} );

		expect( result.after ).toBe( '' );
		expect( result.before ).toBe( '' );
	} );
} );

describe( 'buildAdvancedFiltersConfig', () => {
	const deliveryStatusLabels = { pending: 'Ожидает отправки', delivered: 'Доставлено' };

	test( 'always offers delivery status and tracking presence', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		expect( Object.keys( config.filters ) ).toEqual( expect.arrayContaining( [ 'delivery_status', 'has_tracking' ] ) );
	} );

	test( 'never offers a delivery-type filter — D10 found nothing to query', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { pending: 'В обработке' } );

		expect( config.filters ).not.toHaveProperty( 'delivery_type' );
		expect( config.filters ).not.toHaveProperty( 'type' );
	} );

	test( 'omits the order-status filter when no options are given', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		expect( config.filters ).not.toHaveProperty( 'status' );
	} );

	test( 'offers the order-status filter, with those exact options, once given some', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, {
			pending: 'В ожидании',
			completed: 'Выполнен',
		} );

		expect( config.filters.status.input.options ).toEqual( [
			{ value: 'pending', label: 'В ожидании' },
			{ value: 'completed', label: 'Выполнен' },
		] );
	} );

	test( 'the delivery-status filter offers exactly the labels it was given', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		expect( config.filters.delivery_status.input.options ).toEqual( [
			{ value: 'pending', label: 'Ожидает отправки' },
			{ value: 'delivered', label: 'Доставлено' },
		] );
	} );

	/**
	 * #837 defect 4. The list used to be every canonical state, and one of them was
	 * unreachable on every shop measured — no carrier maps a raw status to `pending`,
	 * so picking «Ожидает отправки» returned an empty table and read as a broken
	 * filter. The server now derives what the shop can produce; this is the client
	 * half of that contract.
	 */
	test( 'offers only the delivery statuses the shop can actually produce', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, undefined, [ 'delivered' ] );

		expect( config.filters.delivery_status.input.options ).toEqual( [
			{ value: 'delivered', label: 'Доставлено' },
		] );
	} );

	/**
	 * ⚠ Empty means «the bootstrap did not say», NOT «this shop produces nothing» —
	 * an older inlined bootstrap carries no list at all. Rendering a filter with zero
	 * options would be a worse version of the very defect this fixes, so the fallback
	 * is the full set. A real answer is never empty: the server always adds `unknown`.
	 */
	test( 'falls back to every label when the reachable list is not stated', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, undefined, [] );

		expect( config.filters.delivery_status.input.options ).toEqual( [
			{ value: 'pending', label: 'Ожидает отправки' },
			{ value: 'delivered', label: 'Доставлено' },
		] );
	} );

	test( 'a reachable list naming a state the shop has no label for offers nothing for it', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, undefined, [ 'returning' ] );

		expect( config.filters.delivery_status.input.options ).toEqual( [] );
	} );

	test( 'no filter here allows multiple instances of itself — one value per filter', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { pending: 'В обработке' } );

		Object.values( config.filters ).forEach( ( filter ) => {
			expect( filter.allowMultiple ).toBe( false );
		} );
	} );

	/**
	 * #837 defect 1's regression guard. Upstream's own `FilterOption` type
	 * (`packages/js/components/src/advanced-filters/types.ts`) is
	 * `{ value, label }`. The `SelectControl` this feeds renders
	 * `<option value={ option.value }>`; an option carrying `key` instead of
	 * `value` renders an option with NO value attribute at all, so React
	 * submits the option's TEXT (the Russian label) instead — which is exactly
	 * what silently broke both the «Filter» button (`updateDisabled` stayed
	 * `true`, per `getDefaultOptionValue()` reading `undefined`) and an
	 * explicit pick (submitted `delivery_status_is=Ожидает отправки` and the
	 * REST route answered `Invalid parameter(s)`). Worded so it fails for the
	 * old `{ key, label }` shape: `option.value` must be a non-empty string.
	 */
	test( 'every option in the built config carries a non-empty string "value" — never "key"', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { pending: 'В обработке' } );

		const allOptions = Object.values( config.filters ).flatMap( ( filter ) => filter.input.options );

		expect( allOptions.length ).toBeGreaterThan( 0 );

		allOptions.forEach( ( option ) => {
			expect( typeof option.value ).toBe( 'string' );
			expect( option.value.length ).toBeGreaterThan( 0 );
			expect( option ).not.toHaveProperty( 'key' );
		} );
	} );
} );

describe( 'advancedFiltersToggleQuery — the reset WooCommerce used to do for us', () => {
	/**
	 * Turning the block ON is the easy half: it must say only what it means.
	 */
	test( 'switching on writes only the mode', () => {
		expect( advancedFiltersToggleQuery( true ) ).toEqual( { filter: 'advanced' } );
	} );

	/**
	 * ⚠ The half that used to come free. While the mode was a `FilterPicker`, its
	 * `update()` special-cased `config.param === 'filter'` and cleared the active filters
	 * on the way out (`packages/js/components/src/filter-picker/index.js:174`). The
	 * operator replaced it with a toggle — a two-state control has no business being a
	 * list — so that branch never runs, and a stranded `*_is` key would keep filtering a
	 * table whose filter block is no longer on screen.
	 *
	 * `undefined` is the removal signal: it is how `@wordpress/url`'s `addQueryArgs()`
	 * drops a key, which is what `updateQueryString()` ends up calling.
	 */
	test( 'switching off drops every advanced key, not just the mode', () => {
		const patch = advancedFiltersToggleQuery( false );

		[ 'filter', 'delivery_status_is', 'status_is', 'has_tracking_is', 'match' ].forEach( ( key ) => {
			expect( patch ).toHaveProperty( key );
			expect( patch[ key ] ).toBeUndefined();
		} );
	} );

	/** It must not touch the carrier scope or the date range — those are other controls. */
	test( 'switching off leaves the carrier and date keys alone', () => {
		const patch = advancedFiltersToggleQuery( false );

		[ 'carrier', 'period', 'compare', 'before', 'after', 'paged' ].forEach( ( key ) => {
			expect( patch ).not.toHaveProperty( key );
		} );
	} );
} );

describe( 'per-filter rules and the pickup-point filter (#836)', () => {
	const deliveryStatusLabels = { pending: 'Ожидает отправки', in_transit: 'В пути' };

	/**
	 * The operator's complaint was that every filter had ONE fixed rule. Delivery status and
	 * order status now carry a real negation; presence filters do not, because «нет» is
	 * already one of their two values.
	 */
	test( 'the two status filters carry both rules, the presence filters carry one', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { 'wc-processing': 'В обработке' } );

		expect( config.filters.delivery_status.rules.map( ( r ) => r.value ) ).toEqual( [ 'is', 'is_not' ] );
		expect( config.filters.status.rules.map( ( r ) => r.value ) ).toEqual( [ 'is', 'is_not' ] );
		expect( config.filters.has_tracking.rules.map( ( r ) => r.value ) ).toEqual( [ 'is' ] );
		expect( config.filters.has_pickup_point.rules.map( ( r ) => r.value ) ).toEqual( [ 'is' ] );
	} );

	/**
	 * ⚠ A presence filter keeps its single rule but does NOT ask for it in the title,
	 * and the two halves of that sentence are load-bearing in opposite directions
	 * (operator, 09.09.2026: «зачем этот селект если нет выбора кроме равен»).
	 *
	 * `AdvancedFilters` interpolates the rule SelectControl only where the title asks
	 * for `{{rule /}}`, so dropping the token is what removes the un-operable control.
	 * The rule itself must stay: `getUrlKey( key, rule )` builds the query key from it
	 * (`has_tracking_is`), and `getActiveFiltersFromQuery()` iterates `rules` to read
	 * the filter back OUT of the URL.
	 *
	 * Measured on the rig before it was written this way — with `rules: []` the
	 * component returns `[]` for a URL that plainly carries the filter, so the block
	 * shows nothing after the merchant applies it: not editable, not clearable. The
	 * cheap-looking fix is the one that breaks the round trip.
	 */
	test( 'a presence filter asks for no rule in its title, yet still declares one', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		[ 'has_tracking', 'has_pickup_point' ].forEach( ( key ) => {
			expect( config.filters[ key ].labels.title ).not.toContain( '{{rule' );
			expect( config.filters[ key ].labels.title ).toContain( '{{filter' );
			// The round trip depends on this staying non-empty.
			expect( config.filters[ key ].rules.length ).toBe( 1 );
		} );
	} );

	test( 'a filter with a real choice of rules still asks for it', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { 'wc-processing': 'В обработке' } );

		expect( config.filters.delivery_status.labels.title ).toContain( '{{rule' );
		expect( config.filters.status.labels.title ).toContain( '{{rule' );
	} );

	/**
	 * ⚠ Presence must be a two-option SELECT, never a valueless rule:
	 * `getQueryFromActiveFilters()` skips any active filter whose `value` is falsy, so a rule
	 * carrying no value never reaches the URL at all. Measured in the upstream source, s128.
	 */
	test( 'the pickup-point filter is a presence select, matching the tracking one', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		const values = config.filters.has_pickup_point.input.options.map( ( o ) => o.value );

		expect( values ).toEqual( [ 'yes', 'no' ] );
		expect( config.filters.has_pickup_point.input.component ).toBe( 'SelectControl' );
	} );

	/** Every option still has to satisfy WooCommerce's own `{ value, label }` contract. */
	test( 'every option of every filter still carries a non-empty string value', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { 'wc-processing': 'В обработке' } );

		Object.values( config.filters )
			.flatMap( ( filter ) => filter.input.options )
			.forEach( ( option ) => {
				expect( typeof option.value ).toBe( 'string' );
				expect( option.value.length ).toBeGreaterThan( 0 );
				expect( option ).not.toHaveProperty( 'key' );
			} );
	} );

	test( 'the negation readers take their own URL keys, not a flag on the positive one', () => {
		expect( getDeliveryStatusNotFromQuery( { delivery_status_is_not: 'in_transit' } ) ).toBe( 'in_transit' );
		expect( getDeliveryStatusNotFromQuery( { delivery_status_is: 'in_transit' } ) ).toBe( '' );

		expect( getOrderStatusNotFromQuery( { status_is_not: 'wc-completed' } ) ).toEqual( [ 'wc-completed' ] );
		expect( getOrderStatusNotFromQuery( {} ) ).toEqual( [] );
	} );

	/** `undefined` is "no filter" and is distinct from `false`, exactly as for tracking. */
	test( 'the pickup-point reader distinguishes absent from false', () => {
		expect( getHasPickupPointFromQuery( { has_pickup_point_is: 'yes' } ) ).toBe( true );
		expect( getHasPickupPointFromQuery( { has_pickup_point_is: 'no' } ) ).toBe( false );
		expect( getHasPickupPointFromQuery( {} ) ).toBeUndefined();
	} );

	/**
	 * ⚠ Leaving advanced mode must drop the NEW keys too. The clearing is ours now — the
	 * branch inside `FilterPicker` that used to do it went away with the picker — so every
	 * key added to the block has to be added here as well, or it keeps filtering a table
	 * whose filter block is no longer on screen.
	 */
	test( 'switching the block off drops every advanced key, the new ones included', () => {
		const patch = advancedFiltersToggleQuery( false );

		[
			'filter',
			'delivery_status_is',
			'delivery_status_is_not',
			'status_is',
			'status_is_not',
			'has_tracking_is',
			'has_pickup_point_is',
			'match',
		].forEach( ( key ) => {
			expect( patch ).toHaveProperty( key );
			expect( patch[ key ] ).toBeUndefined();
		} );
	} );
} );

/**
 * The «Все / Новые» scope (#841). Three pure functions, and the reason each exists is
 * a contract that a display-side shortcut would break:
 *
 *  - `getScopeFromQuery` degrades an unrecognised value to «Все», so a hand-edited URL
 *    shows the whole queue rather than an empty table under an unselected link;
 *  - `isExportedForScope` owns the TRI-STATE — «Все» is the ABSENCE of `is_exported`,
 *    not `true`, because the REST route reads that arg's presence and `true` would
 *    filter to the exported ones instead of to everything;
 *  - `scopeQuery` REMOVES the key for «Все» rather than writing `scope=all`, so there is
 *    exactly one spelling of the default view.
 */
describe( 'the «Все / Новые» scope (#841)', () => {
	test( 'an absent, empty or unrecognised scope param reads as «Все»', () => {
		expect( getScopeFromQuery( {} ) ).toBe( 'all' );
		expect( getScopeFromQuery( { scope: '' } ) ).toBe( 'all' );
		expect( getScopeFromQuery( { scope: 'all' } ) ).toBe( 'all' );
		expect( getScopeFromQuery( { scope: 'НЕЧТО' } ) ).toBe( 'all' );
	} );

	test( 'scope=new is the «Новые» scope', () => {
		expect( getScopeFromQuery( { scope: 'new' } ) ).toBe( 'new' );
	} );

	/**
	 * ⚠ `undefined` and not `true`. `false` here would be a tri-state collapsed to a
	 * boolean, and the REST arg's PRESENCE is what decides whether the query filters at
	 * all — «Все» has to send nothing.
	 */
	test( '«Все» sends no is_exported at all, «Новые» sends false', () => {
		expect( isExportedForScope( 'all' ) ).toBeUndefined();
		expect( isExportedForScope( '' ) ).toBeUndefined();
		expect( isExportedForScope( 'new' ) ).toBe( false );
	} );

	test( 'the «Все» link removes the scope key instead of writing scope=all', () => {
		const patch = scopeQuery( 'all' );

		expect( patch ).toHaveProperty( 'scope' );
		expect( patch.scope ).toBeUndefined();
	} );

	test( 'the «Новые» link writes scope=new', () => {
		expect( scopeQuery( 'new' ) ).toEqual( { scope: 'new' } );
	} );

	/**
	 * ⚠ The scope is NOT an advanced filter. Closing the «Расширенные фильтры» block
	 * while standing in «Новые» must leave the merchant in «Новые» — the block holds
	 * pointwise conditions, the scope says which work queue is on screen.
	 */
	test( 'closing the advanced block does not clear the scope', () => {
		expect( advancedFiltersToggleQuery( false ) ).not.toHaveProperty( 'scope' );
		expect( advancedFiltersToggleQuery( true ) ).not.toHaveProperty( 'scope' );
	} );
} );
