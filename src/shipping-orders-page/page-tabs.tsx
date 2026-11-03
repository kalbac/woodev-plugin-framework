/**
 * The two tabs of the shipping orders page (#711): «Заказы» (the list, unchanged) and «Аналитика».
 *
 * URL-driven like every other control of the page: `tab=analytics` selects the analytics tab, its absence the list, so the
 * existing links and the legacy-slug redirect keep opening the list. Switching clears the other tab's parameters — the list and
 * the analytics tab both use `after` / `before` and mean different things by them.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import OrdersPage from './app';
import AnalyticsTab from './analytics/analytics-tab';
import { navigate, useWcQuery } from './analytics/wc-query';

export const ANALYTICS_TAB = 'analytics';

export default function PageTabs() {
	const query = useWcQuery();
	const analytics = ANALYTICS_TAB === query.tab;

	return (
		<>
			<nav className="woodev-orders-tabs" role="tablist" aria-label={ __( 'Разделы страницы', 'woodev-plugin-framework' ) }>
				<button
					type="button"
					role="tab"
					aria-selected={ ! analytics }
					className={ analytics ? 'woodev-orders-tabs__tab' : 'woodev-orders-tabs__tab is-active' }
					onClick={ () => navigate( {}, {} ) }
				>
					{ __( 'Заказы', 'woodev-plugin-framework' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ analytics }
					className={ analytics ? 'woodev-orders-tabs__tab is-active' : 'woodev-orders-tabs__tab' }
					onClick={ () => navigate( { tab: ANALYTICS_TAB }, {} ) }
				>
					{ __( 'Аналитика', 'woodev-plugin-framework' ) }
				</button>
			</nav>
			{ analytics ? <AnalyticsTab /> : <OrdersPage /> }
		</>
	);
}
