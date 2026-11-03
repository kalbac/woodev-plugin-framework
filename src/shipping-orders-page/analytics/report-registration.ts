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
import type { WcReport } from '../wc-globals';
import DeliveryReport from './delivery-report';

export const REPORT_FILTER = 'woocommerce_admin_reports_list';
export const REPORT_FILTER_NAMESPACE = 'woodev/shipping-delivery-report';

/** Must match `Orders_Registry::ANALYTICS_REPORT_ID`. */
export const REPORT_MENU_ID = 'woodev-shipping-delivery';

/** Appends our report to WooCommerce's list; WooCommerce's own reports are untouched. */
export function addDeliveryReport( reports: WcReport[] ): WcReport[] {
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
