/**
 * The period picker's arithmetic: every named period, counted from days that sit on the edges — a Sunday, a month, a
 * quarter and a year boundary, a leap day.
 *
 * @see src/components/period-picker/presets.ts
 */

import { PRESET_IDS, matchPreset, presetRange, type PresetId } from '../../src/components/period-picker/presets';

/** A local calendar day. `new Date( '2026-11-15' )` would be UTC midnight, which is another day west of Greenwich. */
function day( year: number, month: number, date: number ): Date {
	return new Date( year, month - 1, date );
}

function expectRanges( today: Date, expected: Record< PresetId, [ string, string ] > ): void {
	PRESET_IDS.forEach( ( preset ) => {
		expect( [ preset, presetRange( preset, today ) ] ).toEqual( [
			preset,
			{ from: expected[ preset ][ 0 ], to: expected[ preset ][ 1 ] },
		] );
	} );
}

describe( 'presetRange()', () => {
	test( 'on a Sunday the week ends on that Sunday and starts on the Monday before', () => {
		expectRanges( day( 2026, 11, 15 ), {
			today: [ '2026-11-15', '2026-11-15' ],
			yesterday: [ '2026-11-14', '2026-11-14' ],
			week: [ '2026-11-09', '2026-11-15' ],
			last_week: [ '2026-11-02', '2026-11-08' ],
			month: [ '2026-11-01', '2026-11-15' ],
			last_month: [ '2026-10-01', '2026-10-31' ],
			quarter: [ '2026-10-01', '2026-11-15' ],
			last_quarter: [ '2026-07-01', '2026-09-30' ],
			year: [ '2026-01-01', '2026-11-15' ],
			last_year: [ '2025-01-01', '2025-12-31' ],
		} );
	} );

	test( 'on a Monday the week is that one day and the last week is the seven before', () => {
		const range = presetRange( 'week', day( 2026, 11, 9 ) );

		expect( range ).toEqual( { from: '2026-11-09', to: '2026-11-09' } );
		expect( presetRange( 'last_week', day( 2026, 11, 9 ) ) ).toEqual( { from: '2026-11-02', to: '2026-11-08' } );
	} );

	test( 'across a year boundary: the week, the month and the quarter reach back into the old year', () => {
		expectRanges( day( 2027, 1, 5 ), {
			today: [ '2027-01-05', '2027-01-05' ],
			yesterday: [ '2027-01-04', '2027-01-04' ],
			week: [ '2027-01-04', '2027-01-05' ],
			last_week: [ '2026-12-28', '2027-01-03' ],
			month: [ '2027-01-01', '2027-01-05' ],
			last_month: [ '2026-12-01', '2026-12-31' ],
			quarter: [ '2027-01-01', '2027-01-05' ],
			last_quarter: [ '2026-10-01', '2026-12-31' ],
			year: [ '2027-01-01', '2027-01-05' ],
			last_year: [ '2026-01-01', '2026-12-31' ],
		} );
	} );

	test( 'across a month and a quarter boundary in a leap year', () => {
		expectRanges( day( 2028, 3, 1 ), {
			today: [ '2028-03-01', '2028-03-01' ],
			yesterday: [ '2028-02-29', '2028-02-29' ],
			week: [ '2028-02-28', '2028-03-01' ],
			last_week: [ '2028-02-21', '2028-02-27' ],
			month: [ '2028-03-01', '2028-03-01' ],
			last_month: [ '2028-02-01', '2028-02-29' ],
			quarter: [ '2028-01-01', '2028-03-01' ],
			last_quarter: [ '2027-10-01', '2027-12-31' ],
			year: [ '2028-01-01', '2028-03-01' ],
			last_year: [ '2027-01-01', '2027-12-31' ],
		} );
	} );

	test( 'the first day of a quarter starts the quarter that day and ends the last one the day before', () => {
		expect( presetRange( 'quarter', day( 2026, 7, 1 ) ) ).toEqual( { from: '2026-07-01', to: '2026-07-01' } );
		expect( presetRange( 'last_quarter', day( 2026, 7, 1 ) ) ).toEqual( { from: '2026-04-01', to: '2026-06-30' } );
	} );
} );

describe( 'matchPreset()', () => {
	const sunday = day( 2026, 11, 15 );

	test( 'names a range that is a preset today', () => {
		expect( matchPreset( { from: '2026-11-09', to: '2026-11-15' }, sunday ) ).toBe( 'week' );
		expect( matchPreset( { from: '2025-01-01', to: '2025-12-31' }, sunday ) ).toBe( 'last_year' );
	} );

	test( 'a range that is no preset, or has only one end, is none', () => {
		expect( matchPreset( { from: '2026-11-01', to: '2026-11-09' }, sunday ) ).toBeNull();
		expect( matchPreset( { from: '2026-11-15' }, sunday ) ).toBeNull();
		expect( matchPreset( {}, sunday ) ).toBeNull();
	} );

	test( 'a bookmarked «Сегодня» is a fixed day: the next morning it reads as «Вчера», later as dates', () => {
		const range = presetRange( 'today', sunday );

		expect( matchPreset( range, sunday ) ).toBe( 'today' );
		expect( matchPreset( range, day( 2026, 11, 16 ) ) ).toBe( 'yesterday' );
		expect( matchPreset( range, day( 2026, 11, 17 ) ) ).toBeNull();
	} );

	test( 'two presets over the same days: the one listed first', () => {
		// On a Monday «С начала недели» is the same single day as «Сегодня».
		expect( matchPreset( { from: '2026-11-09', to: '2026-11-09' }, day( 2026, 11, 9 ) ) ).toBe( 'today' );
	} );
} );
