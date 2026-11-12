/**
 * Registers the tools view for the framework's `tools` page.
 *
 * The page body is whatever answers the `woodev.marketplace.page_view` filter; this view answers for a `custom` page whose
 * `view` handle is `tools` and leaves every other page to whoever else is listening.
 *
 * @package woodev-plugin-framework
 */

import { addFilter } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView } from '../../types';
import { ToolsView } from './tools-view';

export const TOOLS_VIEW_HANDLE = 'tools';

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-tools-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'custom' && page.view === TOOLS_VIEW_HANDLE ? ToolsView : view
);

export { ToolsView };
