/**
 * The body of one marketplace page.
 *
 * The bundle owns routing only. A page's body is a view that another bundle registers through the
 * `woodev.marketplace.page_view` filter: the records table (`kind: records`), a framework view such as the mappings
 * editor, or a view a marketplace plugin brings (`kind: custom`, `view` = the handle). Until one registers, the page
 * shows its PHP-supplied title and nothing else; there is no classic fallback.
 *
 * @package woodev-plugin-framework
 */

import { applyFilters } from '@wordpress/hooks';
import type { ManifestMenu, ManifestPage, PageView, PageViewProps } from './types';

export const PAGE_VIEW_FILTER = 'woodev.marketplace.page_view';

/**
 * The view registered for a page, if any.
 *
 * @param page The page being opened.
 * @param menu The menu it belongs to.
 */
export function resolvePageView( page: ManifestPage, menu: ManifestMenu ): PageView | null {
	return applyFilters( PAGE_VIEW_FILTER, null, page, menu ) as PageView | null;
}

export function PageBody( { page, menu, query }: PageViewProps ) {
	const View = resolvePageView( page, menu );

	if ( View ) {
		return <View page={ page } menu={ menu } query={ query } />;
	}

	return (
		<div className="woodev-marketplace-page" data-page={ page.id }>
			<h2>{ page.title }</h2>
		</div>
	);
}
