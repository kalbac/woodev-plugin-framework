/**
 * Records view: every manifest page of `kind: records` renders the generic records shell (`src/admin-records/`) over
 * the page's first table, owned by the page's marketplace plugin.
 *
 * @package woodev-plugin-framework
 */

import { addFilter } from '@wordpress/hooks';
import { RecordsTable } from '../../../admin-records';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView, PageViewProps } from '../../types';

function RecordsView( { page, menu, query }: PageViewProps ) {
	return <RecordsTable owner={ menu.pluginId } table={ page.tables[ 0 ] } title={ page.title } query={ query } />;
}

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-records-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'records' && page.tables.length > 0 ? RecordsView : view
);
