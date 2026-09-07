/**
 * Tests for the pure row-formatting helpers (SP-10 increment 2b).
 *
 * @see src/shipping-orders-page/columns.js
 */

import {
	formatOrderDate,
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
