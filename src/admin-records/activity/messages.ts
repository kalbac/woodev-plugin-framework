/**
 * The activity part's strings. Like the shell's, they come from PHP (the definition's `messages`); a key PHP did
 * not send reads as an empty string, and nothing is shown in its place.
 *
 * @package woodev-plugin-framework
 */

import type { Messages } from '../types';

export const ACTIVITY_MESSAGE_KEYS = {
	/** The last good read is older than the table's `stale_after_ms`. */
	stale: 'activity_stale',
	/** The last read failed; the table is trying again. */
	failed: 'activity_failed',
} as const;

/**
 * The notice for the table's health: the stale sentence wins over the failed one, and nothing is said when all is
 * well or PHP sent no sentence.
 *
 * @param messages The table's messages.
 * @param health   Whether reads are stale or failing.
 */
export function healthNotice( messages: Messages, health: { stale: boolean; failing: boolean } ): string {
	if ( health.stale ) {
		return messages[ ACTIVITY_MESSAGE_KEYS.stale ] ?? '';
	}

	return health.failing ? messages[ ACTIVITY_MESSAGE_KEYS.failed ] ?? '' : '';
}
