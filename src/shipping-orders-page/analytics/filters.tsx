/**
 * The filter row of the «Аналитика» tab (#711): the period with its comparison (WooCommerce's own
 * `DateRangeFilterPicker`), the carrier, and «Обновить».
 *
 * «Всё время» is not offered: an aggregate over the store's whole history is not what the tab is for, and the REST route
 * caps a range at 800 days. The picker does not navigate by itself, so its selection is written to the URL here.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { OrdersProvider } from '../rest';
import type { AnalyticsDateRange } from './date-range';
import { navigate, type WcQuery } from './wc-query';

interface FiltersProps {
	range: AnalyticsDateRange | null;
	query: WcQuery;
	carrier: string;
	providers: OrdersProvider[];
	loading: boolean;
	onRefresh: () => void;
}

export function Filters( { range, query, carrier, providers, loading, onRefresh }: FiltersProps ) {
	const DateRangeFilterPicker = window.wc?.components?.DateRangeFilterPicker;
	const isoDateFormat = window.wc?.date?.isoDateFormat;

	const options = [
		{ value: 'all', label: __( 'Все перевозчики', 'woodev-plugin-framework' ) },
		...providers.map( ( provider ) => ( { value: provider.id, label: provider.label } ) ),
	];

	return (
		<div className="woodev-analytics__filters">
			{ DateRangeFilterPicker && range && isoDateFormat && (
				<DateRangeFilterPicker
					key="daterange"
					dateQuery={ range.dateQuery }
					isoDateFormat={ isoDateFormat }
					onRangeSelect={ ( selection ) => navigate( selection, query ) }
				/>
			) }
			<SelectControl
				className="woodev-analytics__carrier"
				label={ __( 'Перевозчик', 'woodev-plugin-framework' ) }
				hideLabelFromVision
				value={ carrier }
				options={ options }
				onChange={ ( value: string ) => navigate( { carrier: 'all' === value ? undefined : value }, query ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Button variant="secondary" onClick={ onRefresh } disabled={ loading } isBusy={ loading }>
				{ __( 'Обновить', 'woodev-plugin-framework' ) }
			</Button>
		</div>
	);
}
