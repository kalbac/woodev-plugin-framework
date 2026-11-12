/**
 * The input form of an action, drawn from the fields PHP declared: text, textarea, number, select, multi-select and
 * boolean. Options either come with the field or are read from the server's local source. A refused field shows the
 * server's sentence beside it and is marked invalid, so the merchant is told which input and why.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { Button, CheckboxControl, SelectControl, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import type { TableHandle } from '../extensions';
import type { ActionField, Messages, Option } from '../types';
import { actionMessage, ACTION_MESSAGE_KEYS } from './messages';
import { STYLES } from './styles';
import type { FieldValue } from './types';
import { useFieldOptions } from './use-field-options';

export interface FieldsFormProps {
	table: TableHandle;
	messages: Messages;
	action: string;
	rowId: string;
	fields: ActionField[];
	values: Record< string, FieldValue >;
	errors: Record< string, string >;
	disabled: boolean;
	onChange: ( fieldId: string, value: FieldValue ) => void;
}

interface FieldProps extends Omit< FieldsFormProps, 'fields' | 'values' | 'errors' > {
	field: ActionField;
	value: FieldValue | undefined;
	error: string;
}

/** The refusal is announced when it appears; the field's own help is plain description. */
function helpOf( field: ActionField, error: string ): ReactNode {
	if ( error !== '' ) {
		return <span role="alert">{ error }</span>;
	}

	return field.help === '' ? undefined : field.help;
}

function asText( value: FieldValue | undefined ): string {
	return typeof value === 'string' ? value : '';
}

function asList( value: FieldValue | undefined ): string[] {
	return Array.isArray( value ) ? value : [];
}

function toggled( known: string[], option: string, on: boolean ): string[] {
	return on ? [ ...known.filter( ( item ) => item !== option ), option ] : known.filter( ( item ) => item !== option );
}

function SelectField( { field, value, error, disabled, messages, onChange, options }: FieldProps & { options: Option[] } ) {
	const placeholder = { value: '', label: actionMessage( messages, ACTION_MESSAGE_KEYS.choose ) };

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			value={ asText( value ) }
			options={ [ placeholder, ...options ] }
			disabled={ disabled }
			help={ helpOf( field, error ) }
			aria-invalid={ error !== '' }
			aria-required={ field.required }
			onChange={ ( next: string ) => onChange( field.id, next ) }
		/>
	);
}

function MultiField( { field, value, error, disabled, onChange, options }: FieldProps & { options: Option[] } ) {
	const chosen = asList( value );

	return (
		<fieldset style={ STYLES.fieldset } aria-invalid={ error !== '' } aria-required={ field.required }>
			<legend>{ field.label }</legend>
			{ options.map( ( option ) => (
				<CheckboxControl
					key={ option.value }
					__nextHasNoMarginBottom
					label={ option.label }
					checked={ chosen.includes( option.value ) }
					disabled={ disabled }
					onChange={ ( on: boolean ) => onChange( field.id, toggled( chosen, option.value, on ) ) }
				/>
			) ) }
			{ ( error !== '' || field.help !== '' ) && <p>{ helpOf( field, error ) }</p> }
		</fieldset>
	);
}

/** How long the merchant must stop typing before the server is asked. */
export const SEARCH_DELAY_MS = 300;

/** The text, as it stood when the merchant stopped typing for {@link SEARCH_DELAY_MS}. */
function useSettled( typed: string ): string {
	const [ settled, setSettled ] = useState( typed );

	useEffect( () => {
		const timer = setTimeout( () => setSettled( typed ), SEARCH_DELAY_MS );

		return () => clearTimeout( timer );
	}, [ typed ] );

	return settled;
}

/**
 * A select whose options the server filters by what the merchant types (a product of the shop, say). The merchant types in a
 * search box and picks from the list below it; the list is exactly what the server returned, never filtered again here (a
 * client-side filter would hide a match the server found by another word of the query or by a field the label does not show).
 * The chosen option is kept next to the listed ones, so the control still names it after another search replaced the list.
 */
function SearchableField( props: FieldProps ) {
	const { table, messages, action, rowId, field, value, error, disabled, onChange } = props;
	const [ typed, setTyped ] = useState( '' );
	const search = useSettled( typed );
	const source = useFieldOptions( table, action, field.id, rowId, search );
	const [ chosen, setChosen ] = useState< Option | null >( null );
	const current = asText( value );

	useEffect( () => {
		if ( current === '' ) {
			setChosen( null );
		}
	}, [ current ] );

	const options = chosen && ! source.options.some( ( option ) => option.value === chosen.value ) ? [ chosen, ...source.options ] : source.options;
	const placeholder = { value: '', label: actionMessage( messages, ACTION_MESSAGE_KEYS.choose ) };

	return (
		<fieldset disabled={ disabled } style={ STYLES.fieldset }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="search"
				label={ field.label }
				value={ typed }
				onChange={ setTyped }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				hideLabelFromVision
				label={ field.label }
				value={ current }
				options={ [ placeholder, ...options ] }
				disabled={ source.status === 'loading' }
				help={ helpOf( field, error ) }
				aria-invalid={ error !== '' }
				aria-required={ field.required }
				onChange={ ( next: string ) => {
					setChosen( options.find( ( option ) => option.value === next ) ?? null );
					onChange( field.id, next );
				} }
			/>
			{ source.status === 'loading' && (
				<span role="status" aria-label={ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsLoading ) }>
					<Spinner />
				</span>
			) }
			{ source.status === 'error' && <p role="alert">{ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsError ) }</p> }
			{ source.hasMore && (
				<Button variant="link" disabled={ disabled } onClick={ source.loadMore }>
					{ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsMore ) }
				</Button>
			) }
		</fieldset>
	);
}

/** Select and multi-select whose options the server lists, a page at a time. */
function SourceField( props: FieldProps ) {
	const { table, messages, action, rowId, field } = props;
	const source = useFieldOptions( table, action, field.id, rowId );
	const Control = field.type === 'multi_select' ? MultiField : SelectField;

	return (
		<div>
			<Control { ...props } options={ source.options } disabled={ props.disabled || source.status === 'loading' } />
			{ source.status === 'loading' && (
				<span role="status" aria-label={ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsLoading ) }>
					<Spinner />
				</span>
			) }
			{ source.status === 'error' && <p role="alert">{ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsError ) }</p> }
			{ source.hasMore && (
				<Button variant="link" disabled={ props.disabled } onClick={ source.loadMore }>
					{ actionMessage( messages, ACTION_MESSAGE_KEYS.optionsMore ) }
				</Button>
			) }
		</div>
	);
}

function Field( props: FieldProps ) {
	const { field, value, error, disabled, onChange } = props;
	const common = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
		label: field.label,
		disabled,
		help: helpOf( field, error ),
		'aria-invalid': error !== '',
		'aria-required': field.required,
	};

	switch ( field.type ) {
		case 'textarea':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ field.label }
					value={ asText( value ) }
					disabled={ disabled }
					help={ helpOf( field, error ) }
					aria-invalid={ error !== '' }
					aria-required={ field.required }
					onChange={ ( next: string ) => onChange( field.id, next ) }
				/>
			);
		case 'boolean':
			return (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ field.label }
					checked={ value === true }
					disabled={ disabled }
					help={ helpOf( field, error ) }
					onChange={ ( on: boolean ) => onChange( field.id, on ) }
				/>
			);
		case 'select':
		case 'multi_select':
			if ( field.options_source ) {
				return field.searchable === true && field.type === 'select' ? <SearchableField { ...props } /> : <SourceField { ...props } />;
			}

			return field.type === 'select' ? (
				<SelectField { ...props } options={ field.options } />
			) : (
				<MultiField { ...props } options={ field.options } />
			);
		case 'number':
			return (
				<TextControl
					{ ...common }
					type="number"
					inputMode="decimal"
					value={ asText( value ) }
					onChange={ ( next: string ) => onChange( field.id, next ) }
				/>
			);
		default:
			return <TextControl { ...common } value={ asText( value ) } onChange={ ( next: string ) => onChange( field.id, next ) } />;
	}
}

export function FieldsForm( { fields, values, errors, ...rest }: FieldsFormProps ) {
	if ( fields.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__fields" style={ STYLES.form }>
			{ fields.map( ( field ) => (
				<Field key={ field.id } { ...rest } field={ field } value={ values[ field.id ] } error={ errors[ field.id ] ?? '' } />
			) ) }
		</div>
	);
}
