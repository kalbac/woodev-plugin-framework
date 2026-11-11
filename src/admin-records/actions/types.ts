/**
 * Wire shapes of the action routes, as `Woodev\Framework\Admin\Records\Action_Response` sends them
 * (`POST R/actions/{action}/preview` and `POST R/actions/{action}`), read from that class and not recalled.
 *
 * @package woodev-plugin-framework
 */

import type { ActionDescriptor, ActionField, AdminRecord, Messages, Option } from '../types';

export type GroupKind = 'skipped' | 'failed' | 'warning';

/** One reason, written by the server: how many rows it touched, what to do about it, a few examples. */
export interface ReportGroup {
	kind: GroupKind;
	reason: string;
	count: number;
	fix_label: string;
	fix_url: string;
	examples: string[];
}

export interface ActionScope {
	type: 'selection' | 'account';
	/** Says how far the action reaches; for an account-wide action it is never empty. */
	sentence: string;
}

export interface PreviewResponse {
	action: string;
	scope: ActionScope;
	requested: number;
	eligible: number;
	skipped: number;
	warnings: string[];
	groups: ReportGroup[];
	messages: Messages;
	fields: ActionField[];
	confirm_label: string;
	can_apply: boolean;
}

/** Derived by the server from the counts: a partial result is never reported as done. */
export type ApplyStatus = 'done' | 'partial' | 'queued' | 'failed';

export interface DownloadTarget {
	url: string;
	filename: string;
	/** The origins the server serves this site from (the front end and the admin may differ); the guard's allowlist. */
	origins: string[];
}

export interface ApplyResponse {
	status: ApplyStatus;
	rows: AdminRecord[];
	removed_ids: string[];
	requested: number;
	accepted: number;
	skipped: number;
	failed: number;
	groups: ReportGroup[];
	messages: Messages;
	download?: DownloadTarget;
}

export interface FieldOptionsPage {
	options: Option[];
	next_cursor: string | null;
}

/** What the form holds for one field; the server types it again. */
export type FieldValue = string | boolean | string[];
export type Payload = Record< string, FieldValue >;

export type { ActionDescriptor };
