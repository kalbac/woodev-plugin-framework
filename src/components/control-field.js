/**
 * UI-kit — single field control.
 *
 * Renders one Settings-API field by its declared control-type, falling back to
 * legacy value-type inference when controlType is null (so existing plugins keep
 * working). The field anatomy (label + tooltip + description + control) is laid
 * out by the shared FieldRow; layout orientation is decided by the surface.
 *
 * Classic createElement (works under the automatic JSX runtime too).
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
	ComboboxControl,
} from '@wordpress/components';
import FieldRow from './field-row';
import WizardRichText from './richtext';

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
 * Wraps a control with the shared field anatomy (label + tooltip + description).
 *
 * @param {Object} schema  field schema slice.
 * @param {Object} control rendered control element.
 * @return {Object} React element.
 */
function withAnatomy( schema, control ) {
	return createElement(
		FieldRow,
		{
			label: schema.name,
			required: schema.required,
			tooltip: schema.tooltip,
			description: schema.description,
		},
		control
	);
}

/**
 * Field control component.
 *
 * @param {Object}   props          component props.
 * @param {Object}   props.schema   field schema slice.
 * @param {*}        props.value    current value.
 * @param {Function} props.onChange change handler.
 * @return {Object} React element.
 */
export default function ControlField( { schema, value, onChange } ) {
	const control = resolveControl( schema );
	const suffix = schema.suffix || schema.unit || '';

	switch ( control ) {
		case 'toggle':
		case 'checkbox':
			// Toggle rows carry their own label/description meta beside the pill.
			return createElement(
				'div',
				{ className: 'woodev-field__toggle-row' },
				createElement(
					'div',
					{ className: 'woodev-field__toggle-meta' },
					createElement( 'div', { className: 'woodev-field__toggle-label' }, schema.name ),
					schema.description &&
						createElement( 'div', { className: 'woodev-field__toggle-desc' }, schema.description )
				),
				createElement( ToggleControl, {
					__nextHasNoMarginBottom: true,
					checked: !! value,
					onChange,
				} )
			);

		case 'select':
			// Searchable combobox (zam.6): in-list filtering out of the box; the
			// options array is fully controlled, so a plugin can feed it async.
			return withAnatomy(
				schema,
				createElement( ComboboxControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					value: ( value ?? schema.value ?? '' ) === '' ? null : String( value ?? schema.value ),
					options: normalizeOptions( schema.options ),
					onChange: ( next ) => onChange( next ?? '' ),
					allowReset: false,
				} )
			);

		case 'radio':
			// Self-contained: label + description above a bordered option-group.
			return withAnatomy(
				schema,
				createElement(
					'div',
					{ className: 'woodev-field__option-group' },
					createElement( RadioControl, {
						selected: value ?? schema.value ?? '',
						options: normalizeOptions( schema.options ),
						onChange,
					} )
				)
			);

		case 'range':
			return withAnatomy(
				schema,
				createElement(
					'div',
					{ className: 'woodev-field__range-row' },
					createElement( RangeControl, {
						__nextHasNoMarginBottom: true,
						value: Number( value ?? schema.value ?? 0 ),
						min: schema.min ?? 0,
						max: schema.max ?? 100,
						step: schema.step ?? 1,
						onChange,
					} ),
					suffix &&
						createElement( 'span', { className: 'woodev-field__suffix' }, suffix )
				)
			);

		case 'textarea':
			return withAnatomy(
				schema,
				createElement( TextareaControl, {
					__nextHasNoMarginBottom: true,
					value: value ?? '',
					onChange,
				} )
			);

		case 'richtext':
			return withAnatomy(
				schema,
				createElement( WizardRichText, {
					value: value ?? '',
					onChange,
				} )
			);

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

			return withAnatomy(
				schema,
				createElement( FormTokenField, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					__experimentalShowHowTo: false,
					value: tokenValue,
					suggestions: opts.map( ( option ) => option.label ),
					onChange: ( tokens ) =>
						onChange( tokens.map( ( token ) => ( token in valueByLabel ? valueByLabel[ token ] : token ) ) ),
					label: '',
				} )
			);
		}

		case 'color':
			return withAnatomy(
				schema,
				createElement( TextControl, {
					__nextHasNoMarginBottom: true,
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
				type,
				value: value ?? '',
				onChange,
			} );

			return withAnatomy(
				schema,
				suffix
					? createElement(
						'div',
						{ className: 'woodev-field__input-row' },
						input,
						createElement( 'span', { className: 'woodev-field__suffix' }, suffix )
					)
					: input
			);
		}
	}
}
