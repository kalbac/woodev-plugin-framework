/**
 * Registers the diagnostics view for the framework's `diagnostics` page.
 *
 * @package woodev-plugin-framework
 */

import { addFilter } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView } from '../../types';
import { DiagnosticsView } from './diagnostics-view';

export const DIAGNOSTICS_VIEW_HANDLE = 'diagnostics';

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-diagnostics-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'custom' && page.view === DIAGNOSTICS_VIEW_HANDLE ? DiagnosticsView : view
);

export { DiagnosticsView };
