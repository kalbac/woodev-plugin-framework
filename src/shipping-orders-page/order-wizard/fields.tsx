/**
 * Small field wrappers of the order wizard (#969): a control plus the server / client problems
 * of its request field path, shown right under it. One place decides how a per-field error
 * looks, so every step reports a problem the same way.
 *
 * @package woodev-plugin-framework
 */

import { CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import type { ReactNode } from 'react';
import type { FieldErrors } from './types';

/** The messages of any of the given field paths, in order, duplicates removed. */
export function errorsFor( errors: FieldErrors, ...paths: string[] ): string[] {
	const seen = new Set<string>();

	for ( const path of paths ) {
		for ( const message of errors[ path ] || [] ) {
			seen.add( message );
		}
	}

	return Array.from( seen );
}

/** The red lines under a control. Renders nothing when there is nothing to say. */
export function FieldErrorList( { messages }: { messages: string[] } ) {
	if ( 0 === messages.length ) {
		return null;
	}

	return (
		<div className="woodev-order-wizard__errors" role="alert">
			{ messages.map( ( message ) => (
				<p key={ message } className="woodev-order-wizard__error">
					{ message }
				</p>
			) ) }
		</div>
	);
}

interface FieldShellProps {
	messages: string[];
	className?: string;
	children: ReactNode;
}

function FieldShell( { messages, className = '', children }: FieldShellProps ) {
	return (
		<div className={ `woodev-order-wizard__field${ messages.length ? ' has-error' : '' } ${ className }`.trim() }>
			{ children }
			<FieldErrorList messages={ messages } />
		</div>
	);
}

interface TextFieldProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	/** Request field paths whose problems belong to this control. */
	errors?: string[];
	type?: 'text' | 'email' | 'tel' | 'number';
	help?: string;
	className?: string;
	disabled?: boolean;
	min?: string;
	step?: string;
	autoComplete?: string;
}

export function TextField( { label, value, onChange, errors = [], type = 'text', help, className, disabled, min, step, autoComplete }: TextFieldProps ) {
	return (
		<FieldShell messages={ errors } className={ className }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ value }
				type={ type }
				help={ help }
				disabled={ disabled }
				min={ min }
				step={ step }
				autoComplete={ autoComplete }
				aria-invalid={ errors.length > 0 || undefined }
				onChange={ onChange }
			/>
		</FieldShell>
	);
}

interface SelectFieldProps {
	label: string;
	value: string;
	options: Array<{ value: string; label: string }>;
	onChange: ( value: string ) => void;
	errors?: string[];
	className?: string;
	disabled?: boolean;
}

export function SelectField( { label, value, options, onChange, errors = [], className, disabled }: SelectFieldProps ) {
	return (
		<FieldShell messages={ errors } className={ className }>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ value }
				options={ options }
				disabled={ disabled }
				aria-invalid={ errors.length > 0 || undefined }
				onChange={ onChange }
			/>
		</FieldShell>
	);
}

interface CheckboxFieldProps {
	label: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	errors?: string[];
	help?: string;
}

export function CheckboxField( { label, checked, onChange, errors = [], help }: CheckboxFieldProps ) {
	return (
		<FieldShell messages={ errors }>
			<CheckboxControl __nextHasNoMarginBottom label={ label } checked={ checked } help={ help } onChange={ onChange } />
		</FieldShell>
	);
}
