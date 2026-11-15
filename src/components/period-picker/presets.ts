/**
 * The ten named periods of the period picker and the arithmetic behind them. Pure: the caller hands in "today", so a
 * test can stand on any day and nothing here reads the clock.
 *
 * Days are plain calendar days of the browser's local day, written `YYYY-MM-DD` — the shape the records table keeps in
 * its URL (`date=from~to`). Weeks start on Monday, quarters are the calendar ones.
 *
 * @package woodev-plugin-framework
 */

export const PRESET_IDS = [
	'today',
	'yesterday',
	'week',
	'last_week',
	'month',
	'last_month',
	'quarter',
	'last_quarter',
	'year',
	'last_year',
] as const;

export type PresetId = ( typeof PRESET_IDS )[ number ];

export interface DayRange {
	from: string;
	to: string;
}

/**
 * A local calendar day as `YYYY-MM-DD`.
 *
 * @param year  Full year.
 * @param month Month, 0-based; may overflow into the neighbouring years, which `Date` normalises.
 * @param day   Day of the month; 0 is the last day of the month before.
 */
function iso( year: number, month: number, day: number ): string {
	const date = new Date( year, month, day );
	const pad = ( value: number ) => String( value ).padStart( 2, '0' );

	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad( date.getDate() ) }`;
}

function quarterStartMonth( today: Date ): number {
	return Math.floor( today.getMonth() / 3 ) * 3;
}

/** Days since Monday: 0 on a Monday, 6 on a Sunday. */
function daysSinceMonday( today: Date ): number {
	return ( today.getDay() + 6 ) % 7;
}

/**
 * The first and last day of a period.
 *
 * @param preset A preset id.
 * @param today  The day the range is counted from.
 */
export function presetRange( preset: PresetId, today: Date ): DayRange {
	const year = today.getFullYear();
	const month = today.getMonth();
	const day = today.getDate();
	const now = iso( year, month, day );
	const weekStart = day - daysSinceMonday( today );
	const quarter = quarterStartMonth( today );

	switch ( preset ) {
		case 'today':
			return { from: now, to: now };
		case 'yesterday':
			return { from: iso( year, month, day - 1 ), to: iso( year, month, day - 1 ) };
		case 'week':
			return { from: iso( year, month, weekStart ), to: now };
		case 'last_week':
			return { from: iso( year, month, weekStart - 7 ), to: iso( year, month, weekStart - 1 ) };
		case 'month':
			return { from: iso( year, month, 1 ), to: now };
		case 'last_month':
			return { from: iso( year, month - 1, 1 ), to: iso( year, month, 0 ) };
		case 'quarter':
			return { from: iso( year, quarter, 1 ), to: now };
		case 'last_quarter':
			return { from: iso( year, quarter - 3, 1 ), to: iso( year, quarter, 0 ) };
		case 'year':
			return { from: iso( year, 0, 1 ), to: now };
		case 'last_year':
			return { from: iso( year - 1, 0, 1 ), to: iso( year - 1, 11, 31 ) };
	}
}

/**
 * The preset a range is, today, or `null` for a range that is none. When two presets cover the same days (a Monday's
 * «С начала недели» is «Сегодня»), the one listed first wins.
 *
 * @param range A range with both ends.
 * @param today The day presets are counted from.
 */
export function matchPreset( range: { from?: string; to?: string }, today: Date ): PresetId | null {
	if ( ! range.from || ! range.to ) {
		return null;
	}

	return (
		PRESET_IDS.find( ( preset ) => {
			const candidate = presetRange( preset, today );

			return candidate.from === range.from && candidate.to === range.to;
		} ) ?? null
	);
}
