/**
 * Setup Wizard — inline icons.
 *
 * Self-contained SVGs (no @wordpress/icons / dashicons dependency) so the
 * standalone full-screen page never relies on an icon font being enqueued.
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
