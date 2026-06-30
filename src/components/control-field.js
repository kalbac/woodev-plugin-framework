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

import { createElement, useState } from '@wordpress/element';
import { validateField, isRequirable } from './validate';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	RadioControl,
	RangeControl,
} from '@wordpress/components';
import FieldRow from './field-row';
import SelectField from './select-field';
import WizardRichText from './richtext';

/**
 * Password input with a show/hide eye toggle.
 *
 * When `isSet` is true and the user has not typed anything, the input stays
 * empty with a "saved" placeholder (the stored secret never reaches the client);
 * typing a new value replaces it on save. The eye toggle appears only while
 * there is a typed value to reveal — revealing an empty masked field shows
 * nothing, so the toggle would be meaningless.
 *
 * @param {Object}   props          component props.
 * @param {string}   props.value    current value.
 * @param {Function} props.onChange change handler.
 * @param {boolean}  props.isSet    whether a secret is already stored.
 * @return {Object} React element.
 */
function PasswordControl( { value, onChange, isSet } ) {
	const [ show, setShow ] = useState( false );
	const hasValue = '' !== ( value ?? '' );

	return createElement(
		'div',
		{ className: 'woodev-field__password' },
		createElement( TextControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			type: show && hasValue ? 'text' : 'password',
			value: value ?? '',
			placeholder: isSet && ! hasValue ? '•••••• сохранено — введите новое для замены' : '',
			onChange,
		} ),
		hasValue &&
			createElement(
				'button',
				{
					type: 'button',
					className: 'woodev-field__password-toggle',
					onClick: () => setShow( ( s ) => ! s ),
					'aria-label': show ? 'Скрыть' : 'Показать',
					'aria-pressed': show,
				},
				createElement(
					'svg',
					{ width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': true },
					show
						? createElement( 'path', {
							d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7zm10 3a3 3 0 100-6 3 3 0 000 6zM3 3l18 18',
							stroke: 'currentColor',
							strokeWidth: 2,
							strokeLinecap: 'round',
						} )
						: createElement( 'path', {
							d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7zm10 3a3 3 0 100-6 3 3 0 000 6z',
							stroke: 'currentColor',
							strokeWidth: 2,
							strokeLinecap: 'round',
						} )
				)
			)
	);
}

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
 * Wraps a control with the shared field anatomy (label + tooltip + description + error).
 *
 * @param {Object}      schema  field schema slice.
 * @param {Object}      control rendered control element.
 * @param {string|null} error   validation error message, if any.
 * @return {Object} React element.
 */
function withAnatomy( schema, control, error ) {
	return createElement(
		FieldRow,
		{
			label: schema.name,
			required: schema.required && isRequirable( resolveControl( schema ) ),
			tooltip: schema.tooltip,
			description: schema.description,
			error,
		},
		control
	);
}

/**
 * Field control component.
 *
 * Implements blur-first / live-clear validation (SP-3): an error is shown only
 * after the field has been blurred, or when the parent forces reveal via
 * `showErrors` (e.g. on Save). Once an error is visible it is re-evaluated on
 * every input change and cleared live as soon as the value becomes valid.
 * A server-supplied `schema.serverError` always takes precedence.
 *
 * Toggle, checkbox, and range controls are non-requirable and carry no error UI.
 *
 * @param {Object}   props              component props.
 * @param {Object}   props.schema       field schema slice.
 * @param {*}        props.value        current value.
 * @param {Function} props.onChange     change handler.
 * @param {boolean}  props.showErrors   when true, reveal errors without waiting for blur.
 * @return {Object} React element.
 * @since 2.0.2
 */
export default function ControlField( { schema, value, onChange, showErrors } ) {
	// Must be called unconditionally before any early return (React hook rules).
	const [ touched, setTouched ] = useState( false );

	// A wp-config-backed secret is read-only and never editable here.
	if ( schema.constant_managed ) {
		return withAnatomy(
			schema,
			createElement(
				'div',
				{ className: 'woodev-field__constant' },
				createElement( 'code', null, schema.constant_name ),
				createElement(
					'span',
					{ className: 'woodev-field__constant-note' },
					' — задано в wp-config.php'
				)
			)
		);
	}

	// A sensitive value is masked: empty input + "saved" placeholder; typing a new
	// value replaces it on save (an untouched empty field is never sent).
	if ( schema.sensitive ) {
		return withAnatomy(
			schema,
			createElement( PasswordControl, {
				value: value ?? '',
				isSet: !! schema.is_set,
				onChange,
			} )
		);
	}

	// Blur-first: show error only after touch or when parent forces reveal on Save.
	// serverError (set by parent after REST rejection) always takes precedence.
	const error = schema.serverError || ( ( touched || showErrors ) ? validateField( schema, value ) : null );
	const onBlur = () => setTouched( true );

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
			// WC-style dropdown with search (zam.6): trigger button + popover list.
			return withAnatomy(
				schema,
				createElement( SelectField, {
					value: value ?? schema.value ?? '',
					options: normalizeOptions( schema.options ),
					onChange: ( next ) => { setTouched( true ); onChange( next ?? '' ); },
				} ),
				error
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
						onChange: ( next ) => { setTouched( true ); onChange( next ); },
					} )
				),
				error
			);

		case 'range':
			return withAnatomy(
				schema,
				createElement(
					'div',
					{ className: 'woodev-field__range-row' },
					createElement( RangeControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
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
					__next40pxDefaultSize: true,
					value: value ?? '',
					onChange,
					onBlur,
				} ),
				error
			);

		case 'richtext':
			return withAnatomy(
				schema,
				createElement( WizardRichText, {
					value: value ?? '',
					onChange,
				} ),
				error
			);

		case 'multiselect': {
			// Same WC-style dropdown as select, but multiple values (zam.9).
			const raw = value ?? schema.value;
			const current = Array.isArray( raw ) ? raw : ( raw ? [ raw ] : [] );

			return withAnatomy(
				schema,
				createElement( SelectField, {
					value: current,
					options: normalizeOptions( schema.options ),
					multi: true,
					onChange: ( next ) => { setTouched( true ); onChange( next ); },
				} ),
				error
			);
		}

		case 'color':
			return withAnatomy(
				schema,
				createElement( TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					type: 'color',
					value: value ?? schema.value ?? '',
					onChange,
				} ),
				error
			);

		case 'password':
			return withAnatomy(
				schema,
				createElement( PasswordControl, { value, onChange } ),
				error
			);

		case 'email':
		case 'url':
		case 'tel':
		case 'number':
		case 'date':
		case 'text':
		default: {
			const type = [ 'email', 'url', 'tel', 'number', 'date' ].includes( control ) ? control : 'text';
			const input = createElement( TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type,
				value: value ?? '',
				onChange,
				onBlur,
				'aria-invalid': !! error,
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
					: input,
				error
			);
		}
	}
}
