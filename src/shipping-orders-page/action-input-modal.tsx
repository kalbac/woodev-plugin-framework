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

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
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
import { OrdersSelect } from './orders-select';
import { fetchActionFieldOptions } from './rest';
import type {
	OrderActionField,
	OrderActionFieldError,
	OrderActionFieldOption,
	OrderActionPayload,
	OrderRowAction,
} from './rest';

interface ActionInputModalProps {
	/** The order the action is for — the options of an `async_options` select are loaded for it when the dialog opens. */
	orderId?: number;
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

/**
 * The state of a form of declared fields: the values as typed, the errors that apply to them, and the check that runs
 * before a submit. The server's per-field errors win until the merchant touches that field again; the browser's own
 * are shown at once. Shared by this dialog and the toolbar dialog's form tab (`./toolbar-dialog`).
 *
 * @param fields       the declared fields.
 * @param serverErrors the 422's `data.errors` from the last submit; `[]` before the first.
 */
export function useFieldValues( fields: OrderActionField[], serverErrors: OrderActionFieldError[] ) {
	const [ values, setValues ] = useState<ActionInputValues>( () => initialValues( fields ) );
	const [ clientErrors, setClientErrors ] = useState<OrderActionFieldError[]>( [] );
	// Fields touched since the server last spoke — their old server error is stale.
	const [ touched, setTouched ] = useState<Set<string>>( new Set() );

	useEffect( () => {
		setTouched( new Set() );
	}, [ serverErrors ] );

	const shown: Record<string, string> = {};

	serverErrors.forEach( ( error ) => {
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

	/** Runs the checks the browser can make alone; `true` when the form may be sent. */
	const check = (): boolean => {
		const found = validateInput( fields, values );

		setClientErrors( found );

		return 0 === found.length;
	};

	return { values, setValues, shown, change, check };
}

/** Where an `async_options` select stands: asking, answered (an empty list is «nothing to offer»), or failed. */
export type RemoteOptionsState =
	| { status: 'loading' }
	| { status: 'ready'; options: OrderActionFieldOption[] }
	| { status: 'error'; message: string };

/** Whether a field takes its options from the carrier when the dialog opens. */
const isRemote = ( field: OrderActionField ): boolean => 'select' === field.type && true === field.async_options;

/**
 * Loads the options of every `async_options` select of the action, ONCE, when the dialog opens — never before, so a list
 * row costs no carrier call. `retry( id )` asks again after a failure. A dialog closed meanwhile drops a late answer.
 *
 * @param orderId the order the action is for.
 * @param action  the action id.
 * @param fields  the declared fields.
 */
export function useRemoteOptions( orderId: number | undefined, action: string, fields: OrderActionField[] ) {
	const [ states, setStates ] = useState<Record<string, RemoteOptionsState>>( () => {
		const initial: Record<string, RemoteOptionsState> = {};

		fields.filter( isRemote ).forEach( ( field ) => {
			initial[ field.id ] = { status: 'loading' };
		} );

		return initial;
	} );
	const alive = useRef( true );

	const load = useCallback(
		( id: string ) => {
			setStates( ( current ) => ( { ...current, [ id ]: { status: 'loading' } } ) );

			if ( undefined === orderId ) {
				setStates( ( current ) => ( {
					...current,
					[ id ]: { status: 'error', message: __( 'Не удалось загрузить варианты. Попробуйте ещё раз.', 'woodev-plugin-framework' ) },
				} ) );
				return;
			}

			fetchActionFieldOptions( orderId, action, id )
				.then( ( answer ) => {
					if ( alive.current ) {
						setStates( ( current ) => ( { ...current, [ id ]: { status: 'ready', options: answer.options } } ) );
					}
				} )
				.catch( ( error: { message?: string } ) => {
					if ( alive.current ) {
						setStates( ( current ) => ( {
							...current,
							[ id ]: {
								status: 'error',
								message:
									error?.message ||
									__( 'Не удалось загрузить варианты. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
							},
						} ) );
					}
				} );
		},
		[ orderId, action ]
	);

	useEffect( () => {
		alive.current = true;
		fields.filter( isRemote ).forEach( ( field ) => load( field.id ) );

		return () => {
			alive.current = false;
		};
		// Once, on open: a re-render with the same declaration must not ask the carrier again.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return { states, retry: load };
}

export function ActionInputModal( { orderId, action, errors, message, busy, onSubmit, onClose }: ActionInputModalProps ) {
	const fields = action.fields;
	const { values, shown, change, check } = useFieldValues( fields, errors );
	const { states, retry } = useRemoteOptions( orderId, action.action, fields );
	const loading = Object.values( states ).some( ( state ) => 'loading' === state.status );

	const submit = ( event: { preventDefault: () => void } ) => {
		event.preventDefault();

		// Options still on their way: nothing to send yet.
		if ( loading || ! check() ) {
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
						remote={ states[ field.id ] }
						onRetry={ () => retry( field.id ) }
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
					<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy || loading }>
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
	/** An `async_options` select's load state; absent for every other field. */
	remote?: RemoteOptionsState;
	/** Asks the carrier for an `async_options` select's options again after a failure. */
	onRetry?: () => void;
	onChange: ( value: ActionInputValues[ string ] ) => void;
}

/**
 * One declared field, drawn with the page's own WordPress controls. Shared with the toolbar dialog's form tab
 * (`./toolbar-dialog`), which is why it is exported. A field's `help` is one plain sentence under the input.
 */
export function FieldControl( { field, value, error, disabled, remote, onRetry, onChange }: FieldControlProps ) {
	const label = field.required ? `${ field.label } *` : field.label;
	const text = 'string' === typeof value ? value : '';
	let control;

	if ( 'orders' === field.type ) {
		control = (
			<OrdersSelect
				label={ label }
				options={ field.options }
				value={ Array.isArray( value ) ? value : [] }
				disabled={ disabled }
				onChange={ onChange }
			/>
		);
	} else if ( 'select' === field.type && field.async_options && remote ) {
		const loaded = 'ready' === remote.status ? remote.options : [];
		const options = [
			{
				value: '',
				label: 'loading' === remote.status
					? __( 'Загружаем варианты…', 'woodev-plugin-framework' )
					: __( '— не выбрано —', 'woodev-plugin-framework' ),
			},
			...loaded,
		];

		control = (
			<>
				<SelectControl
					label={ label }
					value={ text }
					options={ options }
					disabled={ disabled || 'ready' !== remote.status || 0 === loaded.length }
					onChange={ onChange }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ 'ready' === remote.status && 0 === loaded.length && (
					<p className="woodev-action-form__status" role="status">
						{ field.empty_text || __( 'Нет доступных вариантов.', 'woodev-plugin-framework' ) }
					</p>
				) }
				{ 'error' === remote.status && (
					<p className="woodev-action-form__status is-error" role="status">
						{ remote.message }{ ' ' }
						{ onRetry && (
							<Button variant="link" onClick={ onRetry }>
								{ __( 'Повторить', 'woodev-plugin-framework' ) }
							</Button>
						) }
					</p>
				) }
			</>
		);
	} else if ( 'select' === field.type ) {
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
		const range = ( 'object' === typeof value && ! Array.isArray( value ) && value ) || { from: '', to: '' };

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
			{ field.help && <p className="woodev-action-form__help">{ field.help }</p> }
			{ error && (
				<p className="woodev-action-form__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
