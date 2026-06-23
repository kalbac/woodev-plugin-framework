/**
 * Setup Wizard — single field control.
 *
 * Renders one Settings-API field by its declared control-type, falling back to
 * legacy value-type inference when controlType is null (so existing plugins keep
 * working). Each field renders the anatomy:
 *   [ label + optional InfoIcon tooltip ] / [ optional description ] / [ control ]
 *
 * Classic JSX runtime: createElement is imported and used directly (no JSX), so
 * no react/jsx-runtime handle is emitted (WP 6.3+ compatible).
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
	Tooltip,
} from '@wordpress/components';
import { InfoIcon } from './icons';
import WizardDropdown from './dropdown';

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
 * Renders the field label (with optional required marker + tooltip icon).
 *
 * @param {Object} schema field schema slice.
 * @return {Object|null} React element or null when there is no label.
 */
function renderLabel( schema ) {
	if ( ! schema.name ) {
		return null;
	}

	return createElement(
		'div',
		{ className: 'woodev-setup__field-label' },
		schema.name,
		schema.required &&
			createElement( 'span', { className: 'woodev-setup__field-req' }, '*' ),
		schema.tooltip &&
			createElement(
				Tooltip,
				{ text: schema.tooltip },
				createElement(
					'span',
					{ className: 'woodev-setup__field-tip', tabIndex: 0, role: 'img', 'aria-label': schema.tooltip },
					createElement( InfoIcon )
				)
			)
	);
}

/**
 * Wraps a control with the field anatomy (label + description + control).
 *
 * @param {Object} schema  field schema slice.
 * @param {Object} control rendered control element.
 * @return {Object} React element.
 */
function withAnatomy( schema, control ) {
	return createElement(
		'div',
		{ className: 'woodev-setup__field' },
		renderLabel( schema ),
		schema.description &&
			createElement( 'div', { className: 'woodev-setup__field-desc' }, schema.description ),
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
			// Toggle rows are laid out by step-view's option-group; here we render
			// the WC-pill toggle with its meta column.
			return createElement(
				'div',
				{ className: 'woodev-setup__toggle-row' },
				createElement(
					'div',
					{ className: 'woodev-setup__toggle-meta' },
					createElement( 'div', { className: 'woodev-setup__toggle-label' }, schema.name ),
					schema.description &&
						createElement( 'div', { className: 'woodev-setup__toggle-desc' }, schema.description )
				),
				createElement( ToggleControl, {
					__nextHasNoMarginBottom: true,
					checked: !! value,
					onChange,
				} )
			);

		case 'select':
			return withAnatomy(
				schema,
				createElement( WizardDropdown, {
					value: value ?? schema.value ?? '',
					options: normalizeOptions( schema.options ),
					onChange,
				} )
			);

		case 'radio':
			// Each radio field is self-contained: its own label + description above
			// a bordered option-group holding the options.
			return withAnatomy(
				schema,
				createElement(
					'div',
					{ className: 'woodev-setup__option-group' },
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
					{ className: 'woodev-setup__range-row' },
					createElement( RangeControl, {
						__nextHasNoMarginBottom: true,
						value: Number( value ?? schema.value ?? 0 ),
						min: schema.min ?? 0,
						max: schema.max ?? 100,
						step: schema.step ?? 1,
						onChange,
					} ),
					suffix &&
						createElement( 'span', { className: 'woodev-setup__suffix' }, suffix )
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
				createElement(
					'div',
					{ className: 'woodev-setup__richtext' },
					createElement(
						'div',
						{ className: 'woodev-setup__richtext-toolbar', 'aria-hidden': 'true' },
						createElement( 'button', { type: 'button', className: 'is-bold', tabIndex: -1 }, 'B' ),
						createElement( 'button', { type: 'button', className: 'is-italic', tabIndex: -1 }, 'I' ),
						createElement( 'button', { type: 'button', tabIndex: -1 }, '☰' ),
						createElement( 'button', { type: 'button', tabIndex: -1 }, '🔗' )
					),
					createElement( TextareaControl, {
						__nextHasNoMarginBottom: true,
						className: 'woodev-setup__richtext-body',
						value: value ?? '',
						onChange,
					} )
				)
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
						{ className: 'woodev-setup__input-row' },
						input,
						createElement( 'span', { className: 'woodev-setup__suffix' }, suffix )
					)
					: input
			);
		}
	}
}
