/**
 * The seams the action part (`./actions/`, F6) and the activity part (`./activity/`, F7) plug into.
 *
 * The shell renders what PHP described and reads rows; it never previews, applies or polls. Each of those arrives as
 * a member of {@link RecordsExtensions}, handed the same {@link TableHandle}. A table without extensions still
 * renders every row action PHP sent — disabled, because nothing would run it.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import type { RecordsApi } from './api';
import type { ActionDescriptor, AdminRecord, Definition, Messages, RecordsContext, RecordsQuery } from './types';

/** What every extension gets: the table as it is on screen, and the ways to change it. */
export interface TableHandle {
	api: RecordsApi;
	definition: Definition;
	/** The context the server resolved; requests of an extension send this, never the URL's. */
	context: RecordsContext;
	messages: Messages;
	query: RecordsQuery;
	/** Top-level rows on screen. */
	rows: AdminRecord[];
	/** Ids of every row on screen, expanded children included — what an activity read asks about. */
	visibleIds: string[];
	/**
	 * Changes with every list read. An extension that started a read under one epoch drops the answer when the
	 * epoch has moved on: those rows are no longer the ones on screen.
	 */
	epoch: number;
	/** Reads the current page again. */
	reload: () => void;
	/** Replaces rows by id and removes the ids given, without a list read. */
	patchRows: ( rows: AdminRecord[], removedIds?: string[] ) => void;
}

export interface SelectionHandle {
	selectedIds: string[];
	clear: () => void;
}

export interface RecordsExtensions {
	/** F6 — runs a row action the merchant activated. Without it row actions render disabled. */
	onRowAction?: ( action: ActionDescriptor, row: AdminRecord, table: TableHandle ) => void;
	/** F6 — the bulk toolbar. The selection column exists only when this is set and PHP declares bulk actions. */
	renderBulkActions?: ( selection: SelectionHandle, table: TableHandle ) => ReactNode;
	/** F7 — why an action PHP enabled is blocked right now (a lock, a running process); empty when it is not. */
	blockedReason?: ( action: ActionDescriptor, row: AdminRecord, table: TableHandle ) => string;
	/** F7 — a row's processes and lock reason, shown in its actions cell. */
	renderRowActivity?: ( row: AdminRecord, table: TableHandle ) => ReactNode;
	/** F7 — the account line and the refresh loop, above the table. */
	renderActivity?: ( table: TableHandle ) => ReactNode;
}
