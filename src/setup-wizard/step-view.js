/**
 * Setup Wizard — step body renderer.
 *
 * Renders a settings step's fields (from the Settings-API schema slice) or a
 * content step's server-rendered markup. Consecutive radio fields are grouped
 * into one bordered option-group, as are consecutive toggle fields (shared
 * border + dividers per the approved mockup); standalone controls render in
 * their own .woodev-setup__field blocks.
 *
 * Classic JSX runtime: createElement / Fragment used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment } from '@wordpress/element';
import ControlField from './control-field';

/**
 * Resolves a field's control kind for grouping (controlType, then inference).
 *
 * @param {Object} schema field schema slice.
 * @return {string} control kind.
 */
function controlKind( schema ) {
	if ( schema.controlType ) {
		return schema.controlType;
	}

	if ( 'boolean' === schema.type ) {
		return 'toggle';
	}

	if ( schema.options && Object.keys( schema.options ).length ) {
		return 'select';
	}

	return 'text';
}

/**
 * Renders the settings fields, grouping consecutive radio / toggle fields into
 * shared bordered option-groups.
 *
 * @param {Object}   step     step descriptor.
 * @param {Object}   values   current field values for this step.
 * @param {Function} onChange step values change handler.
 * @return {Array} list of React elements.
 */
function renderFields( step, values, onChange ) {
	const entries = Object.entries( step.fields || {} );
	const blocks = [];
	let group = null;

	const flush = () => {
		if ( group ) {
			blocks.push(
				createElement(
					'div',
					{ key: `group-${ blocks.length }`, className: 'woodev-setup__option-group' },
					group.items
				)
			);
			group = null;
		}
	};

	entries.forEach( ( [ id, schema ] ) => {
		const kind = controlKind( schema );
		// Only consecutive toggles share one bordered group; each radio field is
		// self-contained (own label + its own option-group, in control-field).
		const groupable = 'toggle' === kind || 'checkbox' === kind;
		const groupKind = 'toggle';

		const field = createElement( ControlField, {
			key: id,
			schema,
			value: values[ id ] ?? schema.value,
			onChange: ( v ) => onChange( { ...values, [ id ]: v } ),
		} );

		if ( groupable ) {
			if ( group && group.kind !== groupKind ) {
				flush();
			}
			if ( ! group ) {
				group = { kind: groupKind, items: [] };
			}
			group.items.push( field );
		} else {
			flush();
			blocks.push( field );
		}
	} );

	flush();

	return blocks;
}

/**
 * Step body.
 *
 * @param {Object}   props          component props.
 * @param {Object}   props.step     step descriptor (id, label, type, description, fields, content).
 * @param {Object}   props.values   current field values for this step.
 * @param {Function} props.onChange step values change handler.
 * @return {Object} React element.
 */
export default function StepView( { step, values, onChange } ) {
	const body = 'settings' === step.type
		? createElement(
			'div',
			{ className: 'woodev-setup__fields' },
			renderFields( step, values, onChange )
		)
		: createElement( 'div', {
			className: 'woodev-setup__content',
			// Content originates from the plugin's own trusted server-side callback.
			dangerouslySetInnerHTML: { __html: step.content || '' },
		} );

	return createElement(
		Fragment,
		null,
		createElement( 'h1', { className: 'woodev-setup__step-title' }, step.label ),
		step.description &&
			createElement( 'p', { className: 'woodev-setup__step-desc' }, step.description ),
		body
	);
}
