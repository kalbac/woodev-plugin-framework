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
import type { ReloadOptions } from './use-records';
import type { AccountActivity } from './activity/types';
import type { ActionDescriptor, AdminRecord, Definition, MatchingQuery, Messages, RecordsContext, RecordsQuery } from './types';

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
	/** Child rows (variations) of the expanded top-level rows, as far as they are loaded. */
	children: AdminRecord[];
	/** Ids of every row on screen, expanded children included — what an activity read asks about. */
	visibleIds: string[];
	/**
	 * Changes with every list read. An extension that started a read under one epoch drops the answer when the
	 * epoch has moved on: those rows are no longer the ones on screen.
	 */
	epoch: number;
	/** A list read is in flight. `epoch` has already moved on, but the rows on screen are still the old ones. */
	loading: boolean;
	/** The account-wide activity the last list read carried; the activity part keeps it fresh after that. */
	accountActivity: AccountActivity | null;
	/** Reads the current page again. `silent` keeps the rows, counts and summary on screen until the answer replaces them. */
	reload: ( options?: ReloadOptions ) => void;
	/** Replaces rows by id and removes the ids given, without a list read. */
	patchRows: ( rows: AdminRecord[], removedIds?: string[] ) => void;
	/**
	 * Runs a declared account action for a button of the account line, with the payload the server put on it. Absent when
	 * the page has no action part.
	 */
	runAccountAction?: ( actionId: string, payload: Record< string, string > ) => void;
}

export interface SelectionHandle {
	selectedIds: string[];
	/** The filter and search frozen by «every row under the filter»; `null` while the mode is off. */
	matching: MatchingQuery | null;
	clear: () => void;
}

export interface RecordsExtensions {
	/** F6 — runs a row action the merchant activated. Without it row actions render disabled. */
	onRowAction?: ( action: ActionDescriptor, row: AdminRecord, table: TableHandle ) => void;
	/**
	 * F6 — the select of the selection actions, drawn in the table card's header. The selection column exists only when
	 * this is set and PHP declares bulk actions.
	 */
	renderBulkActions?: ( selection: SelectionHandle, table: TableHandle ) => ReactNode;
	/**
	 * F6 — the account-wide actions: buttons at the top of the page, where «Заказы доставки» has «Создать заказ». With `only`,
	 * just the actions of those ids (the empty table offers one of them in its own body).
	 */
	renderAccountActions?: ( selection: SelectionHandle, table: TableHandle, only?: string[] ) => ReactNode;
	/**
	 * A control of the page drawn in the table card's header, after the bulk group and the search («Добавить сопоставление»). It
	 * is also in the header of the empty table.
	 */
	renderHeaderActions?: ( table: TableHandle ) => ReactNode;
	/** What the empty table offers in its own body, when PHP names no account action for it («Создать первое сопоставление»). */
	renderEmptyActions?: ( table: TableHandle ) => ReactNode;
	/** F6 — runs a declared account action for a button of the account line; the payload comes from the server unchanged. */
	onAccountAction?: ( action: ActionDescriptor, payload: Record< string, string >, table: TableHandle ) => void;
	/** F6 — always mounted, bulk actions or not: the host of the action dialog that a row action opens. */
	renderActions?: ( table: TableHandle ) => ReactNode;
	/** F7 — why an action PHP enabled is blocked right now (a lock, a running process); empty when it is not. */
	blockedReason?: ( action: ActionDescriptor, row: AdminRecord, table: TableHandle ) => string;
	/** F7 — a row's processes and lock reason, shown in its actions cell. */
	renderRowActivity?: ( row: AdminRecord, table: TableHandle ) => ReactNode;
	/** F7 — the account line and the refresh loop, above the table. */
	renderActivity?: ( table: TableHandle ) => ReactNode;
}
