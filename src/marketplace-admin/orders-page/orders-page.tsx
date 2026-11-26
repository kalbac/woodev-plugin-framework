/**
 * «Заказы маркетплейсов»: one page under the WooCommerce menu for the orders of every active marketplace plugin, shaped like
 * «Заказы доставки» — the tabs «Заказы» and «Аналитика», then the filters, the «Статус данных» block and the table.
 *
 * The table is the marketplace plugin's own `orders` records table (its routes, actions and words come from PHP); this page
 * only picks whose. With several marketplaces their select leads the filter row; with one it is not drawn, the way the account
 * select is not drawn for a single account.
 *
 * @package woodev-plugin-framework
 */

import { SelectControl } from '@wordpress/components';
import { useEffect, useMemo } from '@wordpress/element';
import { RecordsTable, useActionExtensions } from '../../admin-records';
import { createActivityExtensions } from '../../admin-records/activity';
import { getNavigate } from '../../admin-records/wc-runtime';
import { FolderTabs } from '../../components/folder-tabs';
import HelpLink, { ProblemsHelpContext } from '../../components/help-link';
import type { OrdersPageManifest, OrdersPageMarketplace, WcPageProps } from '../types';
import { AnalyticsTab } from './analytics/analytics-tab';
import {
	ANALYTICS_TAB,
	ORDERS_TAB,
	activeMarketplace,
	activeTab,
	marketplacePatch,
	patchedQuery,
	stalePresetPatch,
	tabPatch,
} from './route';

interface PageProps {
	page: OrdersPageManifest;
	query: Record< string, string >;
}

interface MarketplaceProps extends PageProps {
	marketplace: OrdersPageMarketplace;
}

/** The marketplace select at the head of the filter row; a choice shows that marketplace's table from scratch. */
function MarketplaceSelect( { page, query, marketplace }: MarketplaceProps ) {
	if ( page.marketplaces.length < 2 ) {
		return null;
	}

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ page.labels.marketplace }
			value={ marketplace.id }
			options={ page.marketplaces.map( ( entry ) => ( { value: entry.id, label: entry.label } ) ) }
			onChange={ ( id: string ) => getNavigate()?.( marketplacePatch( query, id ) ) }
		/>
	);
}

/**
 * The marketplace's table with the action part (row actions, the cancel dialog) and the activity part (locks, the refresh
 * loop). Both keep their state here, so the table is mounted once per marketplace (`key`): leaving one drops it all.
 */
function OrdersTable( props: MarketplaceProps ) {
	const { page, query, marketplace } = props;
	const activity = useMemo( () => createActivityExtensions(), [] );
	const actions = useActionExtensions( activity );

	return (
		<RecordsTable
			owner={ marketplace.id }
			table={ marketplace.table }
			title={ page.title }
			query={ query }
			extensions={ { ...actions, ...activity.extensions } }
			leadingFilter={ <MarketplaceSelect { ...props } /> }
		/>
	);
}

/**
 * The page. A URL naming a marketplace the page does not have is put right first: the table is mounted with the cleaned query at
 * once (never with the stale account and filters), and the URL is then patched the same way through wc-admin's navigation.
 */
export function OrdersPage( { page, query: routeQuery }: PageProps ) {
	const stale = stalePresetPatch( page, routeQuery );
	const query = stale === null ? routeQuery : patchedQuery( routeQuery, stale );
	const marketplace = activeMarketplace( page, query );
	const tab = activeTab( query );

	useEffect( () => {
		if ( stale !== null ) {
			getNavigate()?.( stale );
		}
	}, [ stale === null ? '' : JSON.stringify( stale ) ] ); // eslint-disable-line react-hooks/exhaustive-deps -- the patch's content, not its identity.

	return (
		<ProblemsHelpContext.Provider value={ marketplace.problems ?? null }>
			<HelpLink help={ marketplace.help } className="woodev-marketplace-page__help" />
			<div className="woodev-marketplace-view woodev-marketplace-orders">
				<div style={ { marginBottom: 16 } }>
					<FolderTabs
						label={ page.labels.tabs }
						tabs={ [
							{ id: ORDERS_TAB, label: page.labels.orders },
							{ id: ANALYTICS_TAB, label: page.labels.analytics },
						] }
						active={ tab }
						onSelect={ ( id ) => getNavigate()?.( tabPatch( query, id ) ) }
					/>
				</div>
				{ tab === ANALYTICS_TAB ? (
					<AnalyticsTab
						key={ marketplace.id }
						marketplace={ marketplace.id }
						messages={ marketplace.analytics }
						path={ page.path }
						query={ query }
						leading={ <MarketplaceSelect page={ page } query={ query } marketplace={ marketplace } /> }
					/>
				) : (
					<OrdersTable key={ marketplace.id } page={ page } query={ query } marketplace={ marketplace } />
				) }
			</div>
		</ProblemsHelpContext.Provider>
	);
}

/**
 * The container wc-admin renders for the page.
 *
 * @param page The page PHP published.
 */
export function createOrdersPageContainer( page: OrdersPageManifest ) {
	return function MarketplaceOrdersPage( props: WcPageProps ) {
		return <OrdersPage page={ page } query={ props.query ?? {} } />;
	};
}
