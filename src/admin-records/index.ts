/**
 * The records library: a generic, PHP-described admin table. Imported by a page bundle (the marketplace admin is
 * the first); it is not an entry point of its own and registers nothing by itself.
 *
 * @package woodev-plugin-framework
 */

export { RecordsTable, type RecordsTableProps } from './records-table';
export { createRecordsApi, errorMessage, type RecordsApi } from './api';
export { MESSAGE_KEYS, message, type MessageKey } from './messages';
export { useActionExtensions } from './actions';
export type { RecordsExtensions, SelectionHandle, TableHandle } from './extensions';
export type * from './types';
