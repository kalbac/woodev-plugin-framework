/**
 * Setup Wizard — numbered step indicator.
 *
 * Horizontal stepper: numbered markers joined by a connector, the current step
 * highlighted in the accent colour, completed steps marked with a check.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import { CheckIcon } from './icons';

/**
 * Step indicator.
 *
 * @param {Object} props       component props.
 * @param {Array}  props.steps step descriptors.
 * @param {number} props.index current step index.
 * @return {Object} React element.
 */
export default function Stepper( { steps, index } ) {
	return createElement(
		'ol',
		{ className: 'woodev-setup__stepper' },
		steps.map( ( step, i ) => {
			const state = i < index ? 'done' : ( i === index ? 'active' : 'upcoming' );

			return createElement(
				'li',
				{ key: step.id, className: `woodev-setup__step is-${ state }` },
				createElement(
					'span',
					{ className: 'woodev-setup__step-marker' },
					'done' === state ? createElement( CheckIcon ) : i + 1
				),
				createElement( 'span', { className: 'woodev-setup__step-label' }, step.label )
			);
		} )
	);
}
