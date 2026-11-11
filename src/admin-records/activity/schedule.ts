/**
 * The refresh cadence: pure functions, so the loop's timing rules are testable without a timer.
 *
 * PHP sets the numbers (`refresh_ms`, `max_refresh_ms`, `stale_after_ms`, `id_limit`) in the table definition and
 * the hint `refresh_after_ms` in every answer; the browser only keeps them inside the declared bounds.
 *
 * @package woodev-plugin-framework
 */

import type { Definition } from '../types';

export type ActivityLimits = NonNullable< Definition[ 'activity' ] >;

/**
 * The wait after a good read: the server's hint, kept between the table's cadence and its slow ceiling so a wrong
 * hint can neither hammer the server nor freeze the table.
 *
 * @param limits The table's declared numbers.
 * @param hint   `refresh_after_ms` from the answer.
 */
export function delayAfterSuccess( limits: ActivityLimits, hint: number ): number {
	const wanted = Number.isFinite( hint ) ? hint : limits.refresh_ms;

	return Math.min( limits.max_refresh_ms, Math.max( limits.refresh_ms, wanted ) );
}

/**
 * The wait after the nth failure in a row: the cadence doubled each time, up to the slow ceiling.
 *
 * @param limits   The table's declared numbers.
 * @param failures Failures in a row, counting the one that just happened (at least 1).
 */
export function delayAfterFailure( limits: ActivityLimits, failures: number ): number {
	const doublings = Math.min( Math.max( failures, 1 ), 20 );

	return Math.min( limits.max_refresh_ms, limits.refresh_ms * 2 ** doublings );
}

/**
 * Splits ids into reads of at most `limit` ids. One tick sends every part, one after the other. No ids is still one
 * read: an empty table has an account line.
 *
 * @param ids   Ids on screen.
 * @param limit The table's `id_limit`.
 */
export function chunk( ids: string[], limit: number ): string[][] {
	const size = Math.max( 1, limit );
	const parts: string[][] = ids.length === 0 ? [ [] ] : [];

	for ( let start = 0; start < ids.length; start += size ) {
		parts.push( ids.slice( start, start + size ) );
	}

	return parts;
}
