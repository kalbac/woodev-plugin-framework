/**
 * Setup Wizard — inline icons.
 *
 * Self-contained SVGs (no @wordpress/icons / dashicons dependency) so the
 * standalone full-screen page never relies on an icon font being enqueued.
 * Every icon inherits currentColor and is aria-hidden / non-focusable.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';

/**
 * Checkmark icon (inherits currentColor).
 *
 * @return {Object} React element.
 */
export function CheckIcon() {
	return createElement(
		'svg',
		{
			width: 20,
			height: 20,
			viewBox: '0 0 24 24',
			fill: 'none',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', {
			d: 'M20 6 9 17l-5-5',
			stroke: 'currentColor',
			strokeWidth: 2.5,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
		} )
	);
}

/**
 * Filled checkmark icon (uses fill:currentColor) — finish hero / dropdown tick.
 *
 * @return {Object} React element.
 */
export function CheckFilledIcon() {
	return createElement(
		'svg',
		{
			width: 18,
			height: 18,
			viewBox: '0 0 24 24',
			fill: 'currentColor',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', { d: 'M9 16.2l-3.5-3.5L4 14.2 9 19.2 20 8.2l-1.4-1.4z' } )
	);
}

/**
 * Info "i" circle icon (inherits currentColor).
 *
 * @return {Object} React element.
 */
export function InfoIcon() {
	return createElement(
		'svg',
		{
			width: 16,
			height: 16,
			viewBox: '0 0 24 24',
			fill: 'currentColor',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', {
			d: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 5a1.3 1.3 0 1 1 0 2.6A1.3 1.3 0 0 1 12 7zm1.2 10h-2.4v-6h2.4v6z',
		} )
	);
}

/**
 * Chevron-down caret icon (inherits currentColor) — dropdown trigger.
 *
 * @return {Object} React element.
 */
export function ChevronIcon() {
	return createElement(
		'svg',
		{
			width: 18,
			height: 18,
			viewBox: '0 0 20 20',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', { d: 'M5 8l5 5 5-5' } )
	);
}

/**
 * Gear / settings icon (uses fill:currentColor).
 *
 * @return {Object} React element.
 */
export function GearIcon() {
	return createElement(
		'svg',
		{
			width: 16,
			height: 16,
			viewBox: '0 0 24 24',
			fill: 'currentColor',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', {
			d: 'M19.14 12.94a7.49 7.49 0 0 0 .05-.94 7.49 7.49 0 0 0-.05-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.62-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.8a.5.5 0 0 0 .12.64l2.03 1.58c-.03.31-.05.62-.05.94s.02.63.05.94l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.42.32.66.22l2.39-.96c.49.38 1.03.7 1.62.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.24.1.52.02.66-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z',
		} )
	);
}

/**
 * Star / review icon (uses fill:currentColor).
 *
 * @return {Object} React element.
 */
export function StarIcon() {
	return createElement(
		'svg',
		{
			width: 16,
			height: 16,
			viewBox: '0 0 24 24',
			fill: 'currentColor',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', {
			d: 'M12 17.27l5.18 3.13a.5.5 0 0 0 .74-.54l-1.37-5.9 4.58-3.97a.5.5 0 0 0-.28-.87l-6.03-.52-2.36-5.55a.5.5 0 0 0-.92 0L9.16 8.6l-6.03.52a.5.5 0 0 0-.28.87l4.58 3.97-1.37 5.9a.5.5 0 0 0 .74.54L12 17.27z',
		} )
	);
}
