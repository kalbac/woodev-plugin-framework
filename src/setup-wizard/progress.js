/**
 * Setup Wizard — step progress indicator.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';

/**
 * Step progress list.
 *
 * @param {Object} props       component props.
 * @param {Array}  props.steps step descriptors.
 * @param {number} props.index current step index.
 * @return {Object} React element.
 */
export default function Progress( { steps, index } ) {
	return createElement(
		'ol',
		{ className: 'woodev-setup-steps' },
		steps.map( ( step, i ) =>
			createElement(
				'li',
				{
					key: step.id,
					className: i === index ? 'active' : ( i < index ? 'done' : '' ),
				},
				step.label
			)
		)
	);
}
