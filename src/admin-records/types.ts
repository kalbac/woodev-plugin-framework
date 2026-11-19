/**
 * Wire shapes of the records engine, as `Woodev\Framework\Admin\Records\*` sends them.
 *
 * Read from `Definition_Parser`, `Row_Normalizer` and `Records_Rest_Controller` (F4), not recalled. Everything a
 * merchant reads — labels, messages, reasons — arrives finished from PHP; the shell holds no copy and never branches
 * on a table, owner or marketplace id.
 *
 * @package woodev-plugin-framework
 */

import type { AccountActivity, RowActivity } from './activity/types';

export type CellType = 'text' | 'link' | 'badge' | 'number' | 'date' | 'stack' | 'actions' | 'progress';
export type FilterType = 'search' | 'select' | 'multi_select' | 'boolean' | 'date_range';
export type Alignment = 'left' | 'center' | 'right';
export type Direction = 'asc' | 'desc';
export type Tone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'danger';

export interface Option {
	value: string;
	label: string;
}

/** The account selector; `null` when the table is not account-scoped. */
export interface ContextSelector {
	id: string;
	label: string;
	options: Option[];
	default: string;
}

export interface Column {
	id: string;
	label: string;
	cell_type: CellType;
	sort_key: string | null;
	required: boolean;
	visible: boolean;
	align: Alignment;
}

export interface DateRange {
	from?: string;
	to?: string;
}

/** A typed filter value; a filter that is not set has no entry at all (an omitted boolean is not `false`). */
export type FilterValue = string | string[] | boolean | DateRange;

export interface Filter {
	id: string;
	label: string;
	type: FilterType;
	options: Option[];
	/** Options come with the list response (`facets`) instead of the definition. */
	options_source: boolean;
	default?: FilterValue;
	/**
	 * A `select` drawn as the counted row above the table, not as a select. Its options need not partition the rows: when they
	 * overlap, the provider's facets carry an entry with the empty value — the real count for «Все».
	 */
	views?: boolean;
	/** On a `date_range`: the site's current day (`YYYY-MM-DD`), which the named periods are counted from. */
	today?: string;
}

export interface Sorts {
	keys: string[];
	directions: Direction[];
	default: { key: string; direction: Direction } | null;
}

export interface Pagination {
	default_per_page: number;
	per_page_options: number[];
	max_per_page: number;
	max_selection: number;
}

export interface ActionField {
	id: string;
	label: string;
	type: string;
	required: boolean;
	options: Option[];
	options_source: boolean;
	/** A select whose options are listed by the server may be searched: the merchant types, the server filters. */
	searchable?: boolean;
	/** What an empty text field or search box says it wants («Начните вводить название категории»). */
	placeholder?: string;
	/** A boolean drawn as a switch instead of a checkbox. */
	toggle?: boolean;
	/** The id of a boolean field of the same action; while that one is on, this field is not asked (it is not required and is not sent). */
	hidden_when?: string;
	help: string;
}

export interface ActionDescriptor {
	id: string;
	label: string;
	/** Heading the action is listed under in the bulk select; empty (or absent) for none. */
	group?: string;
	/** The dialog's first button when the action asks for a preview («Проверить связи»); the shared «Проверить» when empty. */
	preview_label?: string;
	/** What a row button shows when the full label does not fit in a table row; the full label stays its name and tooltip. */
	short_label?: string;
	tone: Tone;
	capability: string;
	effect: 'read' | 'mutate';
	interaction: 'link' | 'submit' | 'dialog' | 'download';
	scope: 'selection' | 'account';
	scope_sentence: string;
	confirm_label: string;
	skip_preview: boolean;
	/**
	 * A selection action that can also run over every row under the table's filter («все по фильтру»). PHP always sends it;
	 * an old fixture may not.
	 */
	all_matching?: boolean;
	fields: ActionField[];
}

/**
 * «Every row under the filter»: the filters and search of the table as they were when the merchant clicked, frozen. The
 * server validates them by the list read's own rules; page, page size and sort are not part of it.
 */
export interface MatchingQuery {
	filters: Record< string, FilterValue >;
	search: string;
}

export type Messages = Record< string, string >;

export interface Definition {
	context: ContextSelector | null;
	columns: Column[];
	filters: Filter[];
	sorts: Sorts;
	pagination: Pagination;
	row_actions: ActionDescriptor[];
	bulk_actions: ActionDescriptor[];
	children: { default_per_page: number; max_per_page: number } | null;
	activity: { refresh_ms: number; max_refresh_ms: number; stale_after_ms: number; id_limit: number } | null;
	messages: Messages;
	/** The reserved analytics slot: always null, never requested. */
	analytics: null;
}

export interface RecordsContext {
	account_id: string;
}

export interface DefinitionEnvelope {
	schema_version: number;
	definition: Definition;
	context: RecordsContext;
}

export interface BadgeCell {
	label: string;
	tone: Tone;
}

export interface LinkCell {
	label: string;
	url: string;
}

export interface StackItem {
	type: 'text' | 'badge' | 'link';
	value: unknown;
}

export interface ProgressCell {
	completed: number | null;
	total: number | null;
	percent: number | null;
	text: string;
}

export interface RowActionState {
	id: string;
	enabled: boolean;
	/** Why the action is off; PHP always sends one for a disabled action. */
	reason: string;
	/** Where a `link` action goes for this row (http(s) or a path on this site); only link actions carry one. */
	url?: string;
}

export interface AdminRecord {
	id: string;
	revision: string;
	/** One entry per declared column, `null` for an empty cell. */
	cells: Record< string, unknown >;
	actions: RowActionState[];
	selectable: boolean;
	has_children: boolean;
	/** Locks, blocked actions and processes (the activity part); PHP always sends it, an old fixture may not. */
	activity?: RowActivity;
}

export interface Facet {
	/** Empty for the count with the filter itself left out (see {@link Filter.views}). */
	value: string;
	label: string;
	count: number;
}

export interface ListEnvelope {
	rows: AdminRecord[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
	facets: Record< string, Facet[] >;
	/** Work that belongs to the whole account; absent from a children page. */
	account_activity?: AccountActivity;
	messages: Messages;
}

/** The table's query: what the URL holds and what a list request sends. */
export interface RecordsQuery {
	/** Requested account; empty = the definition's default. */
	account: string;
	page: number;
	perPage: number;
	search: string;
	/** Sort key (not column id); empty for a table that is not sortable. */
	sort: string;
	direction: Direction;
	filters: Record< string, FilterValue >;
}

/** The route query as wc-admin hands it to a page. */
export type RouteQuery = Record< string, string | undefined >;
