/**
 * Records view: every manifest page of `kind: records` renders the generic records shell (`src/admin-records/`) over
 * the page's first table, owned by the page's marketplace plugin.
 *
 * @package woodev-plugin-framework
 */

import { useMemo } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { RecordsTable, useActionExtensions } from '../../../admin-records';
import { createActivityExtensions } from '../../../admin-records/activity';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView, PageViewProps } from '../../types';

/**
 * The page mounts both parts the shell leaves open: the activity part (locks, processes, the refresh loop, the account
 * line) and the action part (the selection column, the bulk toolbar, the dialog a row or bulk action opens). A table whose
 * definition declares no actions and no activity renders as before: neither part adds anything PHP did not declare.
 */
function RecordsView( { page, menu, query }: PageViewProps ) {
	const activity = useMemo( () => createActivityExtensions(), [] );
	const actions = useActionExtensions( activity );

	return (
		<RecordsTable
			owner={ menu.pluginId }
			table={ page.tables[ 0 ] }
			title={ page.title }
			query={ query }
			extensions={ { ...actions, ...activity.extensions } }
		/>
	);
}

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-records-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'records' && page.tables.length > 0 ? RecordsView : view
);
