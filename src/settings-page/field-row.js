/**
 * Settings page — one field rendered as a WooCommerce-style two-column row.
 *
 * Layout idiom is intentionally distinct from the setup wizard's vertical
 * `ControlField` anatomy: a recurring settings page reads best as
 * [ label (left) | control + help (right) ], mirroring core WC settings. The
 * shared sub-controls (dropdown, richtext, icons) are reused; only the row
 * scaffold and the control-type mapping live here so the shipped wizard stays
 * untouched.
 *
 * Classic JSX runtime: createElement is imported and used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	RadioControl,
	RangeControl,
	FormTokenField,
	SelectControl,
} from '@wordpress/components';
import { InfoIcon } from '../components/icons';
import WizardRichText from '../components/richtext';

/**
 * Normalizes a schema's options ({key:label} object OR array) into a list of
 * { value, label } pairs.
 *
 * @param {Object|Array} options raw options from the schema.
 * @return {Array} normalized [{ value, label }] list.
 */
function normalizeOptions( options ) {
	if ( ! options ) {
		return [];
	}

	if ( Array.isArray( options ) ) {
		return options.map( ( label, i ) => ( { value: String( i ), label } ) );
	}

	return Object.entries( options ).map( ( [ value, label ] ) => ( { value, label } ) );
}

/**
 * Resolves which control to render from controlType, then legacy inference.
 *
 * @param {Object} schema field schema slice.
 * @return {string} resolved control kind.
 */
function resolveControl( schema ) {
	if ( schema.controlType ) {
		return schema.controlType;
	}

	if ( 'boolean' === schema.type ) {
		return 'toggle';
	}

	if ( schema.is_multi ) {
		return 'multiselect';
	}

	if ( schema.options && Object.keys( schema.options ).length ) {
		return 'select';
	}

	return 'text';
}

/**
 * Renders the bare interactive control for a field (no label / no description).
 *
 * @param {Object}   schema   field schema slice.
 * @param {*}        value    current value.
 * @param {Function} onChange change handler.
 * @return {Object} React element.
 */
function renderControl( schema, value, onChange ) {
	const control = resolveControl( schema );
	const suffix = schema.suffix || schema.unit || '';

	switch ( control ) {
		case 'toggle':
		case 'checkbox':
			return createElement( ToggleControl, {
				__nextHasNoMarginBottom: true,
				checked: !! value,
				onChange,
			} );

		case 'select':
			return createElement( SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				value: value ?? schema.value ?? '',
				options: normalizeOptions( schema.options ),
				onChange,
			} );

		case 'radio':
			return createElement(
				'div',
				{ className: 'woodev-settings__option-group' },
				createElement( RadioControl, {
					selected: value ?? schema.value ?? '',
					options: normalizeOptions( schema.options ),
					onChange,
				} )
			);

		case 'range':
			return createElement(
				'div',
				{ className: 'woodev-settings__range-row' },
				createElement( RangeControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					value: Number( value ?? schema.value ?? 0 ),
					min: schema.min ?? 0,
					max: schema.max ?? 100,
					step: schema.step ?? 1,
					onChange,
				} ),
				suffix && createElement( 'span', { className: 'woodev-settings__suffix' }, suffix )
			);

		case 'textarea':
			return createElement( TextareaControl, {
				__nextHasNoMarginBottom: true,
				value: value ?? '',
				onChange,
			} );

		case 'richtext':
			return createElement( WizardRichText, {
				value: value ?? '',
				onChange,
			} );

		case 'multiselect': {
			const opts = normalizeOptions( schema.options );
			const labelByValue = {};
			const valueByLabel = {};
			opts.forEach( ( option ) => {
				labelByValue[ String( option.value ) ] = option.label;
				valueByLabel[ option.label ] = option.value;
			} );

			const raw = value ?? schema.value;
			const current = Array.isArray( raw ) ? raw : ( raw ? [ raw ] : [] );
			const tokenValue = current.map( ( v ) => labelByValue[ String( v ) ] ?? String( v ) );

			return createElement( FormTokenField, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				__experimentalShowHowTo: false,
				value: tokenValue,
				suggestions: opts.map( ( option ) => option.label ),
				onChange: ( tokens ) =>
					onChange( tokens.map( ( token ) => ( token in valueByLabel ? valueByLabel[ token ] : token ) ) ),
				label: '',
			} );
		}

		case 'color':
			return createElement(
				'div',
				{ className: 'woodev-settings__input-row' },
				createElement( TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					type: 'color',
					value: value ?? schema.value ?? '',
					onChange,
				} )
			);

		case 'email':
		case 'password':
		case 'number':
		case 'date':
		case 'text':
		default: {
			const type = [ 'email', 'password', 'number', 'date' ].includes( control ) ? control : 'text';
			const input = createElement( TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type,
				value: value ?? '',
				onChange,
			} );

			return suffix
				? createElement(
					'div',
					{ className: 'woodev-settings__input-row' },
					input,
					createElement( 'span', { className: 'woodev-settings__suffix' }, suffix )
				)
				: input;
		}
	}
}

/**
 * One settings field as a two-column row.
 *
 * @param {Object}   props          component props.
 * @param {Object}   props.schema   field schema slice.
 * @param {*}        props.value    current value.
 * @param {Function} props.onChange change handler.
 * @return {Object} React element.
 */
export default function FieldRow( { schema, value, onChange } ) {
	const label = createElement(
		'div',
		{ className: 'woodev-settings__row-label' },
		createElement( 'span', { className: 'woodev-settings__row-name' }, schema.name ),
		schema.tooltip &&
			createElement(
				'span',
				{
					className: 'woodev-settings__row-tip',
					tabIndex: 0,
					role: 'img',
					'aria-label': schema.tooltip,
				},
				createElement( InfoIcon ),
				createElement( 'span', { className: 'woodev-settings__row-tip-bubble' }, schema.tooltip )
			)
	);

	const control = createElement(
		'div',
		{ className: 'woodev-settings__row-control' },
		renderControl( schema, value, onChange ),
		schema.description &&
			createElement( 'p', { className: 'woodev-settings__row-help' }, schema.description )
	);

	return createElement( 'div', { className: 'woodev-settings__row' }, label, control );
}
