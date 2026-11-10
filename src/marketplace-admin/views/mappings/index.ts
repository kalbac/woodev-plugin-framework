/**
 * Registers the mappings view for the framework's `mappings` page.
 *
 * The routing bundle owns the route; the page body is whatever answers the `woodev.marketplace.page_view` filter. The
 * view answers for a `custom` page whose `view` handle is `mappings` and leaves every other page to whoever else is
 * listening.
 *
 * @package woodev-plugin-framework
 */

import { addFilter } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView } from '../../types';
import { MappingsView } from './mappings-view';

export const MAPPINGS_VIEW_HANDLE = 'mappings';

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-mappings-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'custom' && page.view === MAPPINGS_VIEW_HANDLE ? MappingsView : view
);

export { MappingsView };
