/**
 * Setup Wizard — step body renderer.
 *
 * Renders a settings step's fields (from the Settings-API schema slice) or a
 * content step's server-rendered markup. Classic JSX runtime: createElement /
 * Fragment imported and used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment } from '@wordpress/element';
import ControlField from './control-field';

/**
 * Step body.
 *
 * @param {Object}   props          component props.
 * @param {Object}   props.step     step descriptor (id, label, type, fields, content).
 * @param {Object}   props.values   current field values for this step.
 * @param {Function} props.onChange step values change handler.
 * @return {Object} React element.
 */
export default function StepView( { step, values, onChange } ) {
	const body = 'settings' === step.type
		? Object.entries( step.fields ).map( ( [ id, schema ] ) =>
			createElement( ControlField, {
				key: id,
				schema,
				value: values[ id ] ?? schema.value,
				onChange: ( v ) => onChange( { ...values, [ id ]: v } ),
			} ) )
		: createElement( 'div', {
			className: 'woodev-setup-step-content',
			// Content originates from the plugin's own trusted server-side callback.
			dangerouslySetInnerHTML: { __html: step.content || '' },
		} );

	return createElement(
		Fragment,
		null,
		createElement( 'h2', { className: 'woodev-setup-step-title' }, step.label ),
		body
	);
}
