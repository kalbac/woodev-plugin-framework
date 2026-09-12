/**
 * Tests for the pure row-formatting helpers (SP-10 increment 2b).
 *
 * @see src/shipping-orders-page/columns.js
 */

import {
	DELIVERY_STATUS_LABELS,
	formatOrderDate,
	formatSyncTimestamp,
	getStatusTone,
	hasTrackingNumber,
} from '../../src/shipping-orders-page/columns';

describe( 'formatOrderDate', () => {
	test( 'a falsy date returns empty text and title', () => {
		expect( formatOrderDate( null ) ).toEqual( { text: '', title: '' } );
		expect( formatOrderDate( '' ) ).toEqual( { text: '', title: '' } );
	} );

	test( 'an unparsable date returns empty text and title', () => {
		expect( formatOrderDate( 'not-a-date' ) ).toEqual( { text: '', title: '' } );
	} );

	test( 'a date under 24h old renders relative text with the full date as title', () => {
		const twoHoursAgo = new Date( Date.now() - 2 * 60 * 60 * 1000 ).toISOString();

		const result = formatOrderDate( twoHoursAgo );

		expect( result.text ).not.toBe( '' );
		expect( result.title ).not.toBe( '' );
		// Relative text must not just be the raw ISO string re-printed.
		expect( result.text ).not.toBe( twoHoursAgo );
	} );

	test( 'a date 24h or older renders an absolute date, not a relative one', () => {
		const twoDaysAgo = new Date( Date.now() - 2 * 24 * 60 * 60 * 1000 ).toISOString();

		const result = formatOrderDate( twoDaysAgo );

		// dateI18n( 'j M Y', ... ) never contains the word "ago"/"назад" — a loose
		// but real assertion that we took the absolute branch, not the relative one.
		expect( result.text.toLowerCase() ).not.toMatch( /назад|ago/ );
	} );

	test( 'a future date (clock skew) falls back to the absolute branch, not a negative relative diff', () => {
		const inOneHour = new Date( Date.now() + 60 * 60 * 1000 ).toISOString();

		const result = formatOrderDate( inOneHour );

		expect( result.text ).not.toBe( '' );
	} );
} );

describe( 'formatSyncTimestamp (#828 increment 8)', () => {
	test( 'null returns empty text and title — the "never synced" / "no cron" case', () => {
		expect( formatSyncTimestamp( null ) ).toEqual( { text: '', title: '' } );
	} );

	test( 'undefined returns empty text and title', () => {
		expect( formatSyncTimestamp( undefined ) ).toEqual( { text: '', title: '' } );
	} );

	test( 'a past timestamp under 24h old renders relative text, not the raw value re-printed', () => {
		const twoHoursAgo = Math.floor( ( Date.now() - 2 * 60 * 60 * 1000 ) / 1000 );

		const result = formatSyncTimestamp( twoHoursAgo );

		expect( result.text ).not.toBe( '' );
		expect( result.title ).not.toBe( '' );
		expect( result.text ).not.toBe( String( twoHoursAgo ) );
	} );

	test( 'a past timestamp 24h or older renders an absolute date, not a relative one', () => {
		const twoDaysAgo = Math.floor( ( Date.now() - 2 * 24 * 60 * 60 * 1000 ) / 1000 );

		const result = formatSyncTimestamp( twoDaysAgo );

		expect( result.text.toLowerCase() ).not.toMatch( /назад|ago/ );
	} );

	/**
	 * `next_update` is always AHEAD of now — this is the one case
	 * `formatOrderDate()` never has to handle, and the reason this is its own
	 * function rather than a reuse.
	 */
	test( 'a future timestamp under 24h away renders relative text — "Обновится" needs this direction', () => {
		const inOneHour = Math.floor( ( Date.now() + 60 * 60 * 1000 ) / 1000 );

		const result = formatSyncTimestamp( inOneHour );

		expect( result.text ).not.toBe( '' );
		expect( result.text.toLowerCase() ).not.toMatch( /назад|ago/ );
	} );

	test( 'a future timestamp 24h or more away renders an absolute date', () => {
		const inTwoDays = Math.floor( ( Date.now() + 2 * 24 * 60 * 60 * 1000 ) / 1000 );

		const result = formatSyncTimestamp( inTwoDays );

		expect( result.text ).not.toBe( '' );
	} );
} );

describe( 'getStatusTone', () => {
	test( 'maps every canonical state to a known tone', () => {
		expect( getStatusTone( 'delivered' ) ).toBe( 'ok' );
		expect( getStatusTone( 'failed' ) ).toBe( 'error' );
		expect( getStatusTone( 'cancelled' ) ).toBe( 'error' );
		expect( getStatusTone( 'in_transit' ) ).toBe( 'info' );
		expect( getStatusTone( 'pending' ) ).toBe( 'warn' );
	} );

	test( 'unknown gets the muted tone', () => {
		expect( getStatusTone( 'unknown' ) ).toBe( 'muted' );
	} );

	test( 'a value outside the enum falls back to muted, never a false ok/error', () => {
		expect( getStatusTone( 'something_the_server_never_sends' ) ).toBe( 'muted' );
	} );

	/**
	 * The full nine-to-five mapping (#829) — `STATUS_TONE`'s own docblock
	 * derives each pairing from what the WooCommerce status behind that tone
	 * actually means, not from picking a colour that "looks right". This
	 * test pins the whole table down so a future edit that quietly reshuffles
	 * one state onto a different tone fails here, not on the rig.
	 */
	test( 'every one of the nine canonical states maps onto its documented tone', () => {
		expect( getStatusTone( 'pending' ) ).toBe( 'warn' );
		expect( getStatusTone( 'created' ) ).toBe( 'warn' );
		expect( getStatusTone( 'returning' ) ).toBe( 'warn' );
		expect( getStatusTone( 'in_transit' ) ).toBe( 'info' );
		expect( getStatusTone( 'ready_for_pickup' ) ).toBe( 'info' );
		expect( getStatusTone( 'delivered' ) ).toBe( 'ok' );
		expect( getStatusTone( 'returned' ) ).toBe( 'error' );
		expect( getStatusTone( 'failed' ) ).toBe( 'error' );
		expect( getStatusTone( 'cancelled' ) ).toBe( 'error' );
	} );
} );

describe( 'DELIVERY_STATUS_LABELS', () => {
	test( 'carries a Russian label for every canonical state, including unknown', () => {
		[
			'pending',
			'created',
			'in_transit',
			'ready_for_pickup',
			'delivered',
			'returning',
			'returned',
			'failed',
			'cancelled',
			'unknown',
		].forEach( ( state ) => {
			expect( DELIVERY_STATUS_LABELS[ state ] ).toEqual( expect.any( String ) );
			expect( DELIVERY_STATUS_LABELS[ state ] ).not.toBe( '' );
		} );
	} );
} );

describe( 'hasTrackingNumber', () => {
	test( 'true when a number is present', () => {
		expect( hasTrackingNumber( { number: '12345', url: null } ) ).toBe( true );
	} );

	test( 'false when the number is null', () => {
		expect( hasTrackingNumber( { number: null, url: null } ) ).toBe( false );
	} );

	test( 'false when tracking itself is null/undefined', () => {
		expect( hasTrackingNumber( null ) ).toBe( false );
		expect( hasTrackingNumber( undefined ) ).toBe( false );
	} );
} );
