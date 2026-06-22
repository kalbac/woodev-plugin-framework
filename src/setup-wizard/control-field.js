/**
 * Setup Wizard — single field control.
 *
 * Renders one Settings-API field by its declared type/control. Classic JSX
 * runtime: createElement is imported and used directly (no JSX), so no
 * react/jsx-runtime handle is emitted (WP 6.3+ compatible).
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import { TextControl, ToggleControl, SelectControl } from '@wordpress/components';

/**
 * Field control component.
 *
 * @param {Object}   props          component props.
 * @param {Object}   props.schema   field schema slice (type, name, options, value).
 * @param {*}        props.value    current value.
 * @param {Function} props.onChange change handler.
 * @return {Object} React element.
 */
export default function ControlField( { schema, value, onChange } ) {
	if ( 'boolean' === schema.type ) {
		return createElement( ToggleControl, {
			label: schema.name,
			checked: !! value,
			onChange,
		} );
	}

	if ( schema.options && Object.keys( schema.options ).length ) {
		const options = Object.entries( schema.options ).map( ( [ key, label ] ) => ( {
			label,
			value: key,
		} ) );

		return createElement( SelectControl, {
			label: schema.name,
			value,
			options,
			onChange,
		} );
	}

	return createElement( TextControl, {
		label: schema.name,
		value: value ?? '',
		onChange,
	} );
}
