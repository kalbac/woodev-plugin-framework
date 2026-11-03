/**
 * Registration of the «Доставка перевозчиками» report (#711, stage 3) on WooCommerce's `woocommerce_admin_reports_list` filter.
 *
 * `report` is the tail of the path (`/analytics/woodev-delivery`) and `navArgs.id` the id of the menu entry the PHP side adds
 * (`Orders_Registry::ANALYTICS_REPORT_ID`) — both read from WooCommerce's reports README and the installed `app` bundle (WC 11.2.1), where the
 * list is built by `applyFilters( 'woocommerce_admin_reports_list', reports )`.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import '../rest';
import type { WcReport } from '../wc-globals';
import DeliveryReport from './delivery-report';

export const REPORT_FILTER = 'woocommerce_admin_reports_list';
export const REPORT_FILTER_NAMESPACE = 'woodev/shipping-delivery-report';

/** Must match `Orders_Registry::ANALYTICS_REPORT_ID`. */
export const REPORT_MENU_ID = 'woodev-shipping-delivery';

/**
 * Appends our report to WooCommerce's list when the server says this user may use it; WooCommerce's own reports are untouched.
 *
 * The gate is the PHP menu entry's (`window.woodevShippingOrders.analyticsReport`). It cannot be left to the menu alone: WooCommerce's generic
 * `/analytics/:report` route needs only `view_woocommerce_reports`, so a role with that and without the page capability would otherwise reach the
 * report by an in-app link and get a 403 screen from its REST route. Read when the filter RUNS, not when the bundle loads.
 */
export function addDeliveryReport( reports: WcReport[] ): WcReport[] {
	if ( true !== window.woodevShippingOrders?.analyticsReport ) {
		return reports;
	}

	return [
		...reports,
		{
			report: 'woodev-delivery',
			title: __( 'Доставка перевозчиками', 'woodev-plugin-framework' ),
			component: DeliveryReport,
			navArgs: { id: REPORT_MENU_ID },
		},
	];
}
