/**
 * Wire shapes of the activity contract, as `Woodev\Framework\Admin\Records\Activity_Normalizer` and
 * `Activity_Reader` send them.
 *
 * @package woodev-plugin-framework
 */

import type { AdminRecord, Messages } from '../types';

export type ProcessState = 'queued' | 'running' | 'waiting' | 'failed' | 'completed';

export interface ActivityProcess {
	/** Opaque observation id — never a claim token. */
	id: string;
	state: ProcessState;
	label: string;
	/** `null` counts and a `null` percent mean "no measurable total": the indicator is indeterminate. */
	completed: number | null;
	total: number | null;
	percent: number | null;
	text: string;
}

/** What is going on with one row. Several processes can coexist; none hides another. */
export interface RowActivity {
	locked: boolean;
	/** Visible sentence for the lock and for every blocked action. */
	reason: string;
	/** Actions (row or bulk) the row cannot take right now. Read-only details stay available. */
	blocked_actions: string[];
	processes: ActivityProcess[];
	observed_at: string;
}

/**
 * A button of the account line: it runs the named account action through the normal action flow, with a payload the
 * server put there (the id of the run it concerns). The payload goes back to the server unchanged.
 */
export interface AccountLineAction {
	action: string;
	label: string;
	payload: Record< string, string >;
}

/** Work that belongs to the whole account (not to a row). */
export interface AccountActivity {
	active: boolean;
	text: string;
	/** Account actions that take the account's claim themselves and cannot run meanwhile. */
	blocked_actions: string[];
	/** At most two buttons for the line; absent when the provider offers none. */
	actions?: AccountLineAction[];
	observed_at: string;
}

/** `POST R/activity`. */
export interface ActivityResponse {
	rows: AdminRecord[];
	removed_ids: string[];
	account_activity: AccountActivity;
	observed_at: string;
	refresh_after_ms: number;
	messages: Messages;
}
