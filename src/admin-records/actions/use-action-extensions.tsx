/**
 * The action part of a records table, as the shell's extension seams (`../extensions.ts`): row-action activation,
 * the bulk toolbar and the dialog host. A page calls the hook next to its `RecordsTable` and passes the result:
 *
 * ```tsx
 * const extensions = useActionExtensions();
 * <RecordsTable owner={ owner } table={ table } extensions={ extensions } />
 * ```
 *
 * It sets no `blockedReason` or activity members; the activity part adds its own to the same object.
 *
 * @package woodev-plugin-framework
 */

import type { RecordsExtensions } from '../extensions';
import { ActionDialog } from './action-dialog';
import { BulkToolbar } from './bulk-toolbar';
import { useActionFlow } from './use-action-flow';

export function useActionExtensions(): RecordsExtensions {
	const flow = useActionFlow();

	return {
		onRowAction: ( action, row, table ) => flow.begin( action, [ row.id ], row.id, table ),
		renderBulkActions: ( selection, table ) => <BulkToolbar flow={ flow } selection={ selection } table={ table } />,
		renderActions: ( table ) => <ActionDialog flow={ flow } table={ table } />,
	};
}
