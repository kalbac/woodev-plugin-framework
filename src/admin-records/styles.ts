/**
 * The few styles the shell needs inline: the geometry of its flex rows. Everything else (the badge colours, the column
 * rules, the action button group) is in `./style.scss`, bundled with the marketplace bundle.
 *
 * @package woodev-plugin-framework
 */

import type { CSSProperties } from 'react';

/**
 * WooCommerce's pagination always offers 25/50/75/100 and cannot be told otherwise (7.0 `TableCard` passes no
 * `showPerPagePicker`); the shell draws PHP's own sizes in the same footer slot and hides the vendor control.
 */
export const HIDE_VENDOR_PAGE_SIZE =
	'.woodev-records .woocommerce-pagination__per-page-picker>:not(.woodev-records__page-size){display:none}';

export const STYLES = {
	toolbar: { display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'flex-end' },
	nowrap: { whiteSpace: 'nowrap' },
	stack: { display: 'flex', flexDirection: 'column', gap: '4px', alignItems: 'flex-start' },
	actions: { display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center' },
	// The buttons of one row: one group on one line — a row of buttons never wraps under itself.
	buttons: { display: 'inline-flex', flexWrap: 'nowrap', gap: '4px', alignItems: 'center' },
	reason: { display: 'block', flexBasis: '100%', color: '#50575e' },
	childCell: { display: 'block', paddingLeft: '24px' },
	dateRange: { display: 'flex', gap: '8px', alignItems: 'flex-end', border: 0, margin: 0, padding: 0 },
	state: { padding: '32px 16px', textAlign: 'center', background: '#fff', border: '1px solid #dcdcde' },
	summary: { margin: '0 0 12px' },
	selectionBar: {
		display: 'flex',
		flexWrap: 'wrap',
		alignItems: 'center',
		gap: '4px 12px',
		margin: '0 0 12px',
		padding: '8px 12px',
		background: '#f0f6fc',
		borderLeft: '4px solid #72aee6',
	},
} satisfies Record< string, CSSProperties >;
