/**
 * Tests for the pure URL <-> filter-state translation (SP-10 spec D10/D11,
 * increment 7).
 *
 * @see src/shipping-orders-page/filters.ts
 */

import {
	ALL_CARRIERS,
	DEFAULT_DATE_RANGE,
	buildAdvancedFiltersConfig,
	filtersEqual,
	getCarrierFromQuery,
	getDeliveryStatusFromQuery,
	getHasTrackingFromQuery,
	getOrderStatusFromQuery,
	readDateFilters,
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
	const base = { carrier: 'all', after: '', before: '', deliveryStatus: '', status: [], hasTracking: undefined };

	test( 'two identical snapshots are equal', () => {
		expect( filtersEqual( base, { ...base } ) ).toBe( true );
	} );

	test( 'a different carrier is not equal', () => {
		expect( filtersEqual( base, { ...base, carrier: 'cdek' } ) ).toBe( false );
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
			{ key: 'pending', label: 'В ожидании' },
			{ key: 'completed', label: 'Выполнен' },
		] );
	} );

	test( 'the delivery-status filter offers exactly the labels it was given', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels );

		expect( config.filters.delivery_status.input.options ).toEqual( [
			{ key: 'pending', label: 'Ожидает отправки' },
			{ key: 'delivered', label: 'Доставлено' },
		] );
	} );

	test( 'no filter here allows multiple instances of itself — one value per filter', () => {
		const config = buildAdvancedFiltersConfig( deliveryStatusLabels, { pending: 'В обработке' } );

		Object.values( config.filters ).forEach( ( filter ) => {
			expect( filter.allowMultiple ).toBe( false );
		} );
	} );
} );
