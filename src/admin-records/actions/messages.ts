/**
 * The strings of the action dialog. Like the shell's, they all come from PHP: the definition's `messages` map,
 * overridden per response by the preview's or the apply's own `messages`. A key PHP did not send reads as an empty
 * string and the control that wanted it falls back to something structural — never to an English sentence.
 *
 * @package woodev-plugin-framework
 */

import type { Messages } from '../types';

export const ACTION_MESSAGE_KEYS = {
	/** Closes the dialog without running anything. */
	cancel: 'action_cancel',
	/** Closes the dialog after a result. */
	close: 'action_close',
	/** Button that asks the server for the plan. */
	preview: 'action_preview',
	/** The plan is being read. */
	planning: 'action_planning',
	/** The action is running. */
	applying: 'action_applying',
	/** Button that reads a failed plan again. */
	retry: 'action_retry',
	/** Count labels: how many rows were asked for, can go, were skipped, went, failed. */
	requested: 'action_requested',
	eligible: 'action_eligible',
	skipped: 'action_skipped',
	accepted: 'action_accepted',
	failed: 'action_failed',
	/** One sentence per outcome of an apply. */
	statusDone: 'action_status_done',
	statusPartial: 'action_status_partial',
	statusQueued: 'action_status_queued',
	statusFailed: 'action_status_failed',
	/** Headings of the reason groups, by kind. */
	groupSkipped: 'action_group_skipped',
	groupFailed: 'action_group_failed',
	groupWarning: 'action_group_warning',
	/** Heading of the plan's warnings. */
	warnings: 'action_warnings',
	/** Shown when no answer came back: the action may or may not have run; the table was read again. */
	recovery: 'action_recovery',
	/** A request failed and the server sent no sentence of its own. */
	error: 'action_error',
	/** Link to the file of a download action. */
	download: 'action_download',
	/** The file's address was refused by the browser-side check. */
	downloadBlocked: 'action_download_blocked',
	/** Placeholder entry of a select. */
	choose: 'action_choose',
	/** Button that reads the next page of a field's options. */
	optionsMore: 'action_options_more',
	/** The options of a field could not be read. */
	optionsError: 'action_options_error',
	/** The options of a field are being read. */
	optionsLoading: 'action_options_loading',
} as const;

export type ActionMessageKey = ( typeof ACTION_MESSAGE_KEYS )[ keyof typeof ACTION_MESSAGE_KEYS ];

/**
 * A PHP string, or an empty string when PHP sent none.
 *
 * @param messages The merged message map.
 * @param key      The key to read.
 */
export function actionMessage( messages: Messages, key: ActionMessageKey ): string {
	return messages[ key ] ?? '';
}
