/**
 * The shell's strings. All of them come from PHP: the definition's `messages` map, overridden per response by the
 * list envelope's own `messages` (count sentences arrive pluralised there).
 *
 * The shell holds no copy. A key PHP did not send reads as an empty string, and the control that wanted it falls
 * back to something structural — a column or filter label, or the row's own name — never to an English sentence.
 *
 * @package woodev-plugin-framework
 */

import type { Messages } from './types';

/**
 * The keys the shell reads. A provider's definition should send all of them; the action and activity parts add
 * their own keys to the same map.
 */
export const MESSAGE_KEYS = {
	/** Empty table. */
	empty: 'empty',
	/** Empty table while a search or filter is on. Falls back to `empty`. */
	emptyFiltered: 'empty_filtered',
	/** A read failed and the server sent no sentence of its own. */
	error: 'error',
	/** Button that repeats a failed read. */
	retry: 'retry',
	/** Button that clears search and filters. */
	resetFilters: 'reset_filters',
	/** One sentence about the result (for example the pluralised total), usually from the list response. */
	summary: 'summary',
	/** Screen-reader header of the column that holds the expand buttons. */
	expandColumn: 'expand_column',
	/** Expand button of a collapsed row. */
	expand: 'expand',
	/** Expand button of an expanded row. */
	collapse: 'collapse',
	/** Child rows are being read. */
	childrenLoading: 'children_loading',
	/** Button that reads the next page of child rows. */
	childrenMore: 'children_more',
	/** Screen-reader header of an actions column without a label. */
	actionsColumn: 'actions_column',
	/** Checkbox that selects every selectable row on screen. */
	selectAll: 'select_all',
	/** Checkbox of one row; the row's name is appended. */
	selectRow: 'select_row',
	/** The selection reached the table's `max_selection`. */
	selectionLimit: 'selection_limit',
	/** "Any" entry of a select filter. */
	filterAny: 'filter_any',
	/** Start of a date range. */
	dateFrom: 'date_from',
	/** End of a date range. */
	dateTo: 'date_to',
} as const;

export type MessageKey = ( typeof MESSAGE_KEYS )[ keyof typeof MESSAGE_KEYS ];

/**
 * A PHP string, or an empty string when PHP sent none.
 *
 * @param messages The merged message map.
 * @param key      The key to read.
 */
export function message( messages: Messages, key: MessageKey ): string {
	return messages[ key ] ?? '';
}

/**
 * The first of several keys PHP sent a string for.
 *
 * @param messages The merged message map.
 * @param keys     Keys in order of preference.
 */
export function firstMessage( messages: Messages, keys: MessageKey[] ): string {
	return keys.map( ( key ) => message( messages, key ) ).find( ( text ) => text !== '' ) ?? '';
}

/**
 * Joins the parts of an accessible name, leaving out the ones that are empty.
 *
 * @param parts Name parts, most general first.
 */
export function joinLabel( ...parts: string[] ): string {
	return parts.filter( ( part ) => part !== '' ).join( ': ' );
}
