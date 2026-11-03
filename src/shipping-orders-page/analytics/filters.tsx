/**
 * The filter row of the «Аналитика» tab (#711): the period with its comparison (WooCommerce's own
 * `DateRangeFilterPicker`), the carrier, and «Обновить».
 *
 * The row is the «Заказы» tab's own (`.woodev-orders__header` / `__basic-filters`, measured there against WooCommerce's Analytics):
 * the carrier is the same `FilterPicker` as on that tab — a label above, the same width bounds, the bottoms of the controls on one
 * line — so the two tabs cannot drift apart, and nothing here restates their metrics.
 *
 * «Всё время» is not offered: an aggregate over the store's whole history is not what the tab is for, and the REST route
 * caps a range at 800 days. The picker does not navigate by itself, so its selection is written to the URL here.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { OrdersProvider } from '../rest';
import type { WcFilterPickerConfig } from '../wc-globals';
import type { AnalyticsDateRange } from './date-range';
import { navigate, type WcQuery } from './wc-query';

/**
 * The picker's identity: the date query it shows. `DateRangeFilterPicker` copies `dateQuery` into its own state when it is created
 * and never again (the installed component syncs only in its constructor), so after a browser Back / Forward the report and the
 * button label follow the URL while an unkeyed picker would keep editing the period it was first opened on — and its next «Применить»
 * would apply dates the merchant did not intend. WooCommerce's own `Filters` wrapper keys it the same way.
 */
export function pickerKey( range: AnalyticsDateRange ): string {
	const { period, compare } = range.dateQuery;

	return JSON.stringify( [ period, compare, range.after, range.before, range.compareAfter, range.compareBefore ] );
}

/**
 * The URL keys a carrier pick must carry along: `FilterPicker` rewrites ONLY its own param and the ones listed here, so anything
 * unlisted (the period, the chart and its type) would be dropped by the pick.
 */
const CARRIER_STATIC_PARAMS = [ 'period', 'compare', 'after', 'before', 'chart', 'chartType', 'interval' ];

interface FiltersProps {
	range: AnalyticsDateRange | null;
	query: WcQuery;
	providers: OrdersProvider[];
	loading: boolean;
	/** The `wc-admin` path the filters navigate on. */
	path: string;
	onRefresh: () => void;
}

export function Filters( { range, query, providers, loading, path, onRefresh }: FiltersProps ) {
	const DateRangeFilterPicker = window.wc?.components?.DateRangeFilterPicker;
	const FilterPicker = window.wc?.components?.FilterPicker;
	const isoDateFormat = window.wc?.date?.isoDateFormat;

	// `providers` already starts with «Все перевозчики» (`Orders_Registry::build_bootstrap_providers()`), so it is the option list as it
	// is; with no carrier besides it there is nothing to choose, and the picker is not drawn (as on the «Заказы» tab).
	const carrierConfig: WcFilterPickerConfig = {
		label: __( 'Перевозчик', 'woodev-plugin-framework' ),
		param: 'carrier',
		staticParams: CARRIER_STATIC_PARAMS,
		showFilters: () => true,
		defaultValue: 'all',
		filters: providers.map( ( provider ) => ( { value: provider.id, label: provider.label } ) ),
	};

	return (
		<div className="woodev-orders__header">
			<div className="woodev-orders__basic-filters">
				{ DateRangeFilterPicker && range && isoDateFormat && (
					<DateRangeFilterPicker
						key={ pickerKey( range ) }
						dateQuery={ range.dateQuery }
						isoDateFormat={ isoDateFormat }
						onRangeSelect={ ( selection ) => navigate( selection, query, path ) }
					/>
				) }
				{ FilterPicker && providers.length > 1 && (
					<FilterPicker config={ carrierConfig } path={ path } query={ query } />
				) }
				<Button variant="secondary" onClick={ onRefresh } disabled={ loading } isBusy={ loading }>
					{ __( 'Обновить', 'woodev-plugin-framework' ) }
				</Button>
			</div>
		</div>
	);
}
