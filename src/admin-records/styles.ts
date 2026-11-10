/**
 * The few styles the shell needs, inline: the library is imported by another bundle and brings no stylesheet of its
 * own, so nothing has to be enqueued for it. Colours are the WordPress admin palette.
 *
 * @package woodev-plugin-framework
 */

import type { CSSProperties } from 'react';
import type { Tone } from './types';

const TONE_COLOURS: Record< Tone, { background: string; text: string } > = {
	neutral: { background: '#f0f0f1', text: '#2c3338' },
	primary: { background: '#e5f0fa', text: '#0a4b78' },
	info: { background: '#e5f5fa', text: '#005c7a' },
	success: { background: '#edfaef', text: '#005c12' },
	warning: { background: '#fcf9e8', text: '#614200' },
	danger: { background: '#fcf0f1', text: '#8a2424' },
};

export function badgeStyle( tone: Tone ): CSSProperties {
	const colours = TONE_COLOURS[ tone ] ?? TONE_COLOURS.neutral;

	return {
		display: 'inline-block',
		padding: '2px 8px',
		borderRadius: '2px',
		background: colours.background,
		color: colours.text,
		lineHeight: 1.5,
	};
}

/**
 * WooCommerce's pagination always offers 25/50/75/100 and cannot be told otherwise (7.0 `TableCard` passes no
 * `showPerPagePicker`); the shell draws PHP's own sizes instead and hides that picker.
 */
export const HIDE_VENDOR_PAGE_SIZE = '.woodev-records .woocommerce-pagination__per-page-picker{display:none}';

export const STYLES = {
	toolbar: { display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'flex-end', marginBottom: '16px' },
	stack: { display: 'flex', flexDirection: 'column', gap: '4px', alignItems: 'flex-start' },
	actions: { display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center' },
	reason: { display: 'block', flexBasis: '100%', color: '#50575e' },
	childCell: { display: 'block', paddingLeft: '24px' },
	dateRange: { display: 'flex', gap: '8px', alignItems: 'flex-end', border: 0, margin: 0, padding: 0 },
	state: { padding: '32px 16px', textAlign: 'center', background: '#fff', border: '1px solid #dcdcde' },
	summary: { margin: '0 0 12px' },
	pageSize: { display: 'flex', justifyContent: 'flex-end', marginTop: '12px' },
} satisfies Record< string, CSSProperties >;
