/**
 * The «Доставка перевозчиками» report in WooCommerce → Аналитика (#711, stage 3).
 *
 * It is the page's «Аналитика» tab body, unchanged, on the report's own `wc-admin` path: the same period / comparison controls, tiles, chart
 * and tables over the same REST route. Nothing is copied — `AnalyticsTab` takes the path it is shown on, and writes `period` / `compare` /
 * `chart` / `interval` / `chartType` / `carrier` there, which is the query WooCommerce's own reports use.
 *
 * Registered on `woocommerce_admin_reports_list` by `./report-registration`; the PHP half is `Orders_Registry::add_analytics_report_menu_item()`.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import AnalyticsTab from './analytics-tab';

/** Must match `Orders_Registry::ANALYTICS_REPORT_PATH`. */
export const REPORT_PATH = '/analytics/woodev-delivery';

export default function DeliveryReport() {
	return <AnalyticsTab path={ REPORT_PATH } />;
}
