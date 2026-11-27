/**
 * What a connection check answered, as the settings page shows it.
 *
 * @package woodev-plugin-framework
 */

/** `Woodev_Connection_Result::to_array()`: the answer of a check, live or stored with its section. */
export interface SectionStatus {
	success?: boolean;
	message?: string;
	/** A connection that works with limits (a key with part of its rights). */
	warning?: boolean;
}

/** The colour of a status line. */
export type StatusTone = 'ok' | 'warning' | 'error' | 'pending';

/**
 * The tone of a check's answer: a working connection with limits is a warning, not a success.
 *
 * @param {SectionStatus} status the answer.
 * @return {StatusTone} its tone.
 */
export function connectionTone( status: SectionStatus ): StatusTone {
	if ( ! status.success ) {
		return 'error';
	}

	return status.warning ? 'warning' : 'ok';
}
