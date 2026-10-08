/**
 * The dialog an order action with fields opens (#1180) — the courier call's day, time window and comment,
 * asked for BEFORE the action runs.
 *
 * Opened from the row's button and from the preview's, on the same `Modal` the page's confirm and preview
 * dialogs use; the order metabox opens the same form in the framework's vanilla modal shell
 * (`order-metabox-actions.js`). A field's error sits under it: the browser-side ones from `./action-input`
 * and the server's 422 `data.errors`, which win while the merchant has not touched that field again.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { initialValues, toPayload, validateInput } from './action-input';
import type { ActionInputValues } from './action-input';
import type {
	OrderActionField,
	OrderActionFieldError,
	OrderActionPayload,
	OrderRowAction,
} from './rest';

interface ActionInputModalProps {
	/** The action, with the `fields` it declared. */
	action: OrderRowAction & { fields: OrderActionField[] };
	/** The server's per-field errors from the last submit; `[]` before the first. */
	errors: OrderActionFieldError[];
	/** A failure that is not about one field (the carrier refused) — shown above the buttons. */
	message?: string;
	/** The request is in flight. */
	busy: boolean;
	onSubmit: ( payload: OrderActionPayload ) => void;
	onClose: () => void;
}

export function ActionInputModal( { action, errors, message, busy, onSubmit, onClose }: ActionInputModalProps ) {
	const fields = action.fields;
	const [ values, setValues ] = useState<ActionInputValues>( () => initialValues( fields ) );
	const [ clientErrors, setClientErrors ] = useState<OrderActionFieldError[]>( [] );
	// Fields touched since the server last spoke — their old server error is stale.
	const [ touched, setTouched ] = useState<Set<string>>( new Set() );

	useEffect( () => {
		setTouched( new Set() );
	}, [ errors ] );

	const shown: Record<string, string> = {};

	errors.forEach( ( error ) => {
		if ( ! touched.has( error.field ) ) {
			shown[ error.field ] = error.message;
		}
	} );
	clientErrors.forEach( ( error ) => {
		shown[ error.field ] = error.message;
	} );

	const change = ( id: string, value: ActionInputValues[ string ] ) => {
		setValues( ( current ) => ( { ...current, [ id ]: value } ) );
		setTouched( ( current ) => new Set( current ).add( id ) );
		setClientErrors( ( current ) => current.filter( ( error ) => error.field !== id ) );
	};

	const submit = ( event: { preventDefault: () => void } ) => {
		event.preventDefault();

		const found = validateInput( fields, values );

		setClientErrors( found );

		if ( found.length > 0 ) {
			return;
		}

		onSubmit( toPayload( fields, values ) );
	};

	return (
		<Modal
			title={ action.label }
			onRequestClose={ busy ? () => undefined : onClose }
			className="woodev-orders-actions__input"
			size="small"
		>
			<form className="woodev-action-form" onSubmit={ submit } noValidate>
				{ fields.map( ( field ) => (
					<FieldControl
						key={ field.id }
						field={ field }
						value={ values[ field.id ] }
						error={ shown[ field.id ] }
						disabled={ busy }
						onChange={ ( value ) => change( field.id, value ) }
					/>
				) ) }
				{ message && (
					<Notice status="error" isDismissible={ false } className="woodev-action-form__message">
						{ message }
					</Notice>
				) }
				<div className="woodev-action-form__buttons">
					<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
						{ __( 'Отмена', 'woodev-plugin-framework' ) }
					</Button>
					<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy }>
						{ action.label }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

interface FieldControlProps {
	field: OrderActionField;
	value: ActionInputValues[ string ] | undefined;
	error?: string;
	disabled: boolean;
	onChange: ( value: ActionInputValues[ string ] ) => void;
}

/** One declared field, drawn with the page's own WordPress controls. */
function FieldControl( { field, value, error, disabled, onChange }: FieldControlProps ) {
	const label = field.required ? `${ field.label } *` : field.label;
	const text = 'string' === typeof value ? value : '';
	let control;

	if ( 'select' === field.type ) {
		const options = field.required && field.default
			? field.options
			: [ { value: '', label: __( '— не выбрано —', 'woodev-plugin-framework' ) }, ...field.options ];

		control = (
			<SelectControl
				label={ label }
				value={ text }
				options={ options }
				disabled={ disabled }
				onChange={ onChange }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		);
	} else if ( 'textarea' === field.type ) {
		control = (
			<TextareaControl
				label={ label }
				value={ text }
				maxLength={ field.maxlength }
				rows={ 3 }
				disabled={ disabled }
				onChange={ onChange }
				__nextHasNoMarginBottom
			/>
		);
	} else if ( 'time_range' === field.type ) {
		const range = ( 'object' === typeof value && value ) || { from: '', to: '' };

		control = (
			<fieldset className="woodev-action-form__range">
				<legend>{ label }</legend>
				<TextControl
					type="time"
					label={ __( 'с', 'woodev-plugin-framework' ) }
					value={ range.from }
					min={ field.min }
					max={ field.max }
					disabled={ disabled }
					onChange={ ( from ) => onChange( { ...range, from } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<TextControl
					type="time"
					label={ __( 'до', 'woodev-plugin-framework' ) }
					value={ range.to }
					min={ field.min }
					max={ field.max }
					disabled={ disabled }
					onChange={ ( to ) => onChange( { ...range, to } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</fieldset>
		);
	} else {
		control = (
			<TextControl
				type="date"
				label={ label }
				value={ text }
				min={ field.min }
				max={ field.max }
				disabled={ disabled }
				onChange={ onChange }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		);
	}

	return (
		<div className={ `woodev-action-form__field woodev-action-form__field--${ field.type }` }>
			{ control }
			{ error && (
				<p className="woodev-action-form__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
