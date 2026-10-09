/**
 * The orders multi-select of a toolbar dialog (s164) — «which orders is this run for».
 *
 * Built on `@wordpress/components`' `FormTokenField`, the control WordPress itself uses for tags and the one the
 * page already ships with, rather than WooCommerce's `selectWoo` enhanced select: that one is a jQuery plugin
 * attached to a `<select>` in PHP-rendered markup (the legacy order screen), so it would need a ref-and-effect
 * wrapper around a library this React page otherwise never touches. `FormTokenField` gives the same shape — chips for
 * what is chosen, a type-ahead list for the rest — as a plain controlled component.
 *
 * Why NOT a checkbox list (the operator's call): ten or more eligible orders would be a wall. Every order starts
 * selected, so the common case is «press the button»; the chips live in a capped, scrolling area and a counter
 * with «Выбрать все» / «Снять все» keeps a long selection manageable.
 *
 * `FormTokenField` matches what is typed against the suggestion STRINGS themselves, so a token IS the order's label
 * («#1047 · Екатеринбург») and the search works on the city as well as the number. The label ⇄ id mapping is pure
 * ({@link buildOrderTokens}) and unit-tested.
 *
 * @package woodev-plugin-framework
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, FormTokenField } from '@wordpress/components';

export interface OrderOption {
	value: string;
	label: string;
}

/** The tokens a field's options turn into, and the two ways back to an id. */
export interface OrderTokens {
	/** Every token, in the options' order — the suggestions. */
	labels: string[];
	/** Option value → token. */
	labelOf: Record<string, string>;
	/** Token → option value. */
	valueOf: Record<string, string>;
}

/**
 * Turns the options into tokens. A token must be unique, because it is the key the field matches by: two orders
 * that share a label (a carrier that labelled both with the same city) get their id appended to the later one.
 */
export function buildOrderTokens( options: OrderOption[] ): OrderTokens {
	const labels: string[] = [];
	const labelOf: Record<string, string> = {};
	const valueOf: Record<string, string> = {};

	options.forEach( ( option ) => {
		let token = option.label;

		if ( undefined !== valueOf[ token ] ) {
			token = `${ option.label } (${ option.value })`;
		}

		labels.push( token );
		labelOf[ option.value ] = token;
		valueOf[ token ] = option.value;
	} );

	return { labels, labelOf, valueOf };
}

/**
 * The option values a token list stands for, in the order given. A token no option carries (typed freely and not
 * validated) is dropped, and so is a repeat.
 */
export function valuesOfTokens( tokens: ( string | { value: string } )[], valueOf: Record<string, string> ): string[] {
	const values: string[] = [];

	tokens.forEach( ( token ) => {
		const value = valueOf[ 'string' === typeof token ? token : token.value ];

		if ( undefined !== value && ! values.includes( value ) ) {
			values.push( value );
		}
	} );

	return values;
}

interface OrdersSelectProps {
	label: string;
	options: OrderOption[];
	/** The chosen option values. */
	value: string[];
	disabled?: boolean;
	onChange: ( value: string[] ) => void;
}

export function OrdersSelect( { label, options, value, disabled = false, onChange }: OrdersSelectProps ) {
	const { labels, labelOf, valueOf } = buildOrderTokens( options );
	const tokens = value.map( ( id ) => labelOf[ id ] ).filter( ( token ) => undefined !== token );
	const all = options.length;

	return (
		<div className="woodev-orders-select">
			<FormTokenField
				label={ label }
				value={ tokens }
				suggestions={ labels }
				onChange={ ( next ) => onChange( valuesOfTokens( next, valueOf ) ) }
				__experimentalExpandOnFocus
				__experimentalShowHowTo={ false }
				__experimentalValidateInput={ ( token ) => undefined !== valueOf[ token ] }
				placeholder={ __( 'Найти заказ по номеру или городу', 'woodev-plugin-framework' ) }
				maxSuggestions={ all }
				disabled={ disabled }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<div className="woodev-orders-select__summary">
				<span>
					{ sprintf(
						/* translators: 1: number of orders chosen, 2: number of orders offered. */
						__( 'Выбрано заказов: %1$d из %2$d', 'woodev-plugin-framework' ),
						value.length,
						all
					) }
				</span>
				<Button
					variant="link"
					disabled={ disabled || value.length === all }
					onClick={ () => onChange( options.map( ( option ) => option.value ) ) }
				>
					{ __( 'Выбрать все', 'woodev-plugin-framework' ) }
				</Button>
				<Button variant="link" disabled={ disabled || 0 === value.length } onClick={ () => onChange( [] ) }>
					{ __( 'Снять все', 'woodev-plugin-framework' ) }
				</Button>
			</div>
		</div>
	);
}
