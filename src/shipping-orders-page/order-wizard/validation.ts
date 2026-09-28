/**
 * Validation of the order wizard's steps ①–③ (#969), and the routing of SERVER errors to the
 * step and field they belong to.
 *
 * ⚠ The server validates a WHOLE payload, only on create / update (`Order_Payload_Validator`
 * — there is no validate-only route in I3): «Далее» therefore cannot ask it. The checks below
 * repeat the server's own rules for the fields steps ①–③ hold, under the SAME field paths
 * and the same wording, so a problem caught here reads exactly as it would have read from the
 * server, and a problem only the server can see (an unknown product, a customer deleted
 * meanwhile) still lands on the right field of the right step when the final request comes
 * back 422.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import type { FieldErrors, ServerError, WizardData, WizardStepId } from './types';
import { WIZARD_STEPS } from './types';

/** `is_email()`'s practical floor — one `@`, a dotted host, no spaces. The server has the last word. */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function add( errors: FieldErrors, field: string, message: string ): void {
	( errors[ field ] = errors[ field ] || [] ).push( message );
}

/** Which step owns a request field path. Unknown paths belong to the last step that could show them. */
export function stepOfField( field: string ): WizardStepId {
	if ( field.startsWith( 'customer' ) || 'billing.email' === field ) {
		return 'customer';
	}

	if ( /^billing\.(first_name|last_name|phone|company)$/.test( field ) ) {
		return 'customer';
	}

	if ( field.startsWith( 'billing' ) || field.startsWith( 'shipping.' ) ) {
		return 'address';
	}

	if ( field.startsWith( 'items' ) ) {
		return 'items';
	}

	if ( field.startsWith( 'shipping_line' ) || field.startsWith( 'pickup_point' ) || field.startsWith( 'carrier_fields' ) || field.startsWith( 'fields' ) ) {
		return 'delivery';
	}

	return 'payment';
}

/** Index of the earliest step that has an error, or -1 for none. */
export function firstStepWithErrors( errors: FieldErrors ): number {
	const indexes = Object.keys( errors )
		.filter( ( field ) => errors[ field ].length > 0 )
		.map( ( field ) => WIZARD_STEPS.indexOf( stepOfField( field ) ) );

	return indexes.length ? Math.min( ...indexes ) : -1;
}

/** Errors of one step only — what a step's «Далее» is judged by. */
export function errorsOfStep( errors: FieldErrors, step: WizardStepId ): FieldErrors {
	const out: FieldErrors = {};

	for ( const field of Object.keys( errors ) ) {
		if ( errors[ field ].length > 0 && stepOfField( field ) === step ) {
			out[ field ] = errors[ field ];
		}
	}

	return out;
}

/** Groups the server's `data.errors` list by field path. */
export function groupServerErrors( list: ServerError[] ): FieldErrors {
	const out: FieldErrors = {};

	for ( const entry of list ) {
		if ( entry && 'string' === typeof entry.field && 'string' === typeof entry.message ) {
			add( out, entry.field, entry.message );
		}
	}

	return out;
}

/** Every error message of a step joined — for a summary line. */
export function flattenErrors( errors: FieldErrors ): string[] {
	return Object.keys( errors ).reduce< string[] >( ( all, field ) => all.concat( errors[ field ] ), [] );
}

/** ① Покупатель. */
export function validateCustomer( data: WizardData ): FieldErrors {
	const errors: FieldErrors = {};
	const email = data.billing.email.trim();

	if ( email && ! EMAIL_PATTERN.test( email ) ) {
		add( errors, 'billing.email', __( 'Проверьте email покупателя.', 'woodev-plugin-framework' ) );
	} else if ( data.customer.create_account && ! email ) {
		add( errors, 'billing.email', __( 'Чтобы создать аккаунт, укажите email покупателя.', 'woodev-plugin-framework' ) );
	}

	return errors;
}

/**
 * ② Адрес. `states` is the bootstrap's region list for the chosen country — a country that
 * has one takes only a code from it (the validator's `invalid_state`), one that has none takes
 * free text.
 */
export function validateAddress(
	data: WizardData,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>
): FieldErrors {
	const errors: FieldErrors = {};
	const { country, state, city } = data.shipping;

	if ( '' === country.trim() ) {
		add( errors, 'shipping.country', __( 'Укажите страну доставки.', 'woodev-plugin-framework' ) );
	} else if ( Object.keys( countries ).length > 0 && ! ( country in countries ) ) {
		add( errors, 'shipping.country', __( 'Такой страны нет в справочнике магазина.', 'woodev-plugin-framework' ) );
	} else if ( state && states[ country ] && ! ( state in states[ country ] ) ) {
		add( errors, 'shipping.state', __( 'Такого региона нет в справочнике магазина для выбранной страны.', 'woodev-plugin-framework' ) );
	}

	if ( '' === city.trim() ) {
		add( errors, 'shipping.city', __( 'Укажите город или населённый пункт.', 'woodev-plugin-framework' ) );
	}

	return errors;
}

/** ③ Товары. Indexes are the line's position, which is the order the payload is sent in. */
export function validateItems( data: WizardData ): FieldErrors {
	const errors: FieldErrors = {};

	if ( 0 === data.items.length ) {
		add( errors, 'items', __( 'Добавьте в заказ хотя бы один товар.', 'woodev-plugin-framework' ) );

		return errors;
	}

	data.items.forEach( ( line, index ) => {
		const quantity = Number( line.quantity );
		const price = line.price.trim();

		if ( ! Number.isInteger( quantity ) || quantity < 1 ) {
			add( errors, `items.${ index }.quantity`, __( 'Количество должно быть не меньше единицы.', 'woodev-plugin-framework' ) );
		}

		if ( '' === price || ! Number.isFinite( Number( price ) ) || Number( price ) < 0 ) {
			add( errors, `items.${ index }.price`, __( 'Цена должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
		}
	} );

	return errors;
}

/** Runs the check of step `index`. Steps ④–⑤ are not built yet (I5a / I5b): nothing to check. */
export function validateStep(
	index: number,
	data: WizardData,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>
): FieldErrors {
	switch ( WIZARD_STEPS[ index ] ) {
		case 'customer':
			return validateCustomer( data );
		case 'address':
			return validateAddress( data, countries, states );
		case 'items':
			return validateItems( data );
		default:
			return {};
	}
}
