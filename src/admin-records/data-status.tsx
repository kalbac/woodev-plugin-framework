/**
 * The «Статус данных» block at the end of the filter row: a label above a bordered bar with «label above value» columns
 * («Обновлено», «Обновится»), the shape «Заказы доставки» copied from WooCommerce's own block. Every word comes from PHP — the
 * list response's `data_status` and the definition's `data_status` message.
 *
 * @package woodev-plugin-framework
 */

import type { DataStatusBlock } from './types';

interface Props {
	/** The block the last list read carried; nothing is drawn without one. */
	status: DataStatusBlock | undefined;
	/** The label above the bar. */
	label: string;
}

export function DataStatus( { status, label }: Props ) {
	if ( ! status || status.items.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__data-status">
			{ label !== '' && <div className="woodev-records__data-status-label">{ label }</div> }
			{ /* `role="status"`: the values arrive after a read, and a screen reader must hear them without the focus moving. */ }
			<div
				className="woodev-records__data-status-bar"
				role="status"
				aria-live="polite"
				aria-atomic="true"
				title={ status.title || undefined }
			>
				{ status.items.map( ( item ) => (
					<span key={ item.label } className="woodev-records__data-status-item">
						<span className="woodev-records__data-status-item-label">{ item.label }</span>
						<span className="woodev-records__data-status-item-value">{ item.value }</span>
					</span>
				) ) }
			</div>
		</div>
	);
}
