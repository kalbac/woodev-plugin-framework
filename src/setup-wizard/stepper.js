/**
 * Setup Wizard — progress-line step indicator.
 *
 * Reproduces the WooCommerce setup-wizard stepper, recolored to the woodev.ru
 * cyan: an <ol> of equal-width items, each a label over a 4px progress line with
 * a ::before dot (styled in style.scss). Receives the full step list (including
 * the terminal 'finish' step) plus the active index; renders on the finish
 * screen too (all prior steps done, finish active).
 *
 * Classic JSX runtime: createElement used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';

/**
 * Step indicator.
 *
 * @param {Object}   props            component props.
 * @param {Array}    props.steps      step descriptors (incl. terminal 'finish').
 * @param {number}   props.index      current step index.
 * @param {Function} props.onNavigate optional (i)=>void invoked when a non-current
 *                                    step label is clicked.
 * @return {Object} React element.
 */
export default function Stepper( { steps, index, onNavigate } ) {
	return createElement(
		'ol',
		{ className: 'woodev-setup__steps' },
		steps.map( ( step, i ) => {
			const state = i < index ? 'done' : ( i === index ? 'active' : 'upcoming' );

			// The current step is a plain (non-clickable) label; any other step is a
			// button that navigates to it.
			const label = i === index
				? createElement( 'span', { className: 'woodev-setup__step-label' }, step.label )
				: createElement(
					'button',
					{
						type: 'button',
						className: 'woodev-setup__step-label',
						onClick: () => onNavigate && onNavigate( i ),
					},
					step.label
				);

			return createElement(
				'li',
				{ key: step.id, className: `is-${ state }` },
				label
			);
		} )
	);
}
