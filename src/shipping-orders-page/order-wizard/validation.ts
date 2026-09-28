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
import { validateField } from '../../components/validate';
import { carrierFieldValue, visibleCarrierFields } from './delivery-state';
import { ruleOf } from './address-policy';
import type { AddressFieldKey, AddressPolicy, FieldErrors, ServerError, WizardData, WizardStepId } from './types';
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
 * What a required address field reads when left empty — the sentences of
 * `Order_Payload_Validator::address_field_messages()`, word for word.
 */
export function requiredAddressMessages(): Partial<Record<AddressFieldKey, string>> {
	return {
		state: __( 'Укажите регион доставки.', 'woodev-plugin-framework' ),
		city: __( 'Укажите город или населённый пункт.', 'woodev-plugin-framework' ),
		address_1: __( 'Укажите улицу и дом.', 'woodev-plugin-framework' ),
		address_2: __( 'Укажите квартиру или офис.', 'woodev-plugin-framework' ),
		postcode: __( 'Укажите индекс.', 'woodev-plugin-framework' ),
	};
}

/**
 * ② Адрес. `states` is the bootstrap's region list for the chosen country — a country that
 * has one takes only a code from it (the validator's `invalid_state`), one that has none takes
 * free text.
 *
 * `policy` is the checkout's own rule for the delivery address (#985, `Checkout_Field_Policy`, fetched
 * by `useAddressPolicy()`): a field it marks required and shows must be filled, a hidden one is never
 * asked. Without it (not answered yet) only the country and the city are required — the server judges
 * the whole payload again on save, by the same rule.
 */
export function validateAddress(
	data: WizardData,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>,
	policy?: AddressPolicy | null
): FieldErrors {
	const errors: FieldErrors = {};
	const { country, state } = data.shipping;
	const stateRule = ruleOf( policy, 'state' );

	if ( '' === country.trim() ) {
		add( errors, 'shipping.country', __( 'Укажите страну доставки.', 'woodev-plugin-framework' ) );
	} else if ( Object.keys( countries ).length > 0 && ! ( country in countries ) ) {
		add( errors, 'shipping.country', __( 'Такой страны нет в справочнике магазина.', 'woodev-plugin-framework' ) );
	} else if ( state && ! stateRule.hidden && ! stateRule.removed && states[ country ] && ! ( state in states[ country ] ) ) {
		add( errors, 'shipping.state', __( 'Такого региона нет в справочнике магазина для выбранной страны.', 'woodev-plugin-framework' ) );
	}

	const messages = requiredAddressMessages();

	for ( const key of Object.keys( messages ) as AddressFieldKey[] ) {
		const rule = ruleOf( policy, key );

		if ( rule.required && ! rule.hidden && '' === data.shipping[ key as keyof typeof data.shipping ].trim() ) {
			add( errors, `shipping.${ key }`, messages[ key ] as string );
		}
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

/**
 * ④ Доставка. The same three rules, under the same field paths and words, as
 * `Order_Payload_Validator::check_shipping_line()` / `check_pickup_point()`: a tariff must be
 * chosen, its price a number not below zero, and a pickup tariff needs a point (checkout parity,
 * spec A2) — plus the carrier's own fields under the chosen tariff (D7), by the settings page's rules. Whether the tariff belongs to a carrier that is allowed at all (O12) and whether the
 * point still exists are the server's to decide — it is asked when the order is saved, and a 422
 * comes back onto these same paths.
 */
export function validateDelivery( data: WizardData ): FieldErrors {
	const errors: FieldErrors = {};
	const line = data.rest.shipping_line;

	// A tariff chosen against an earlier package is not confirmed until the new answer lands.
	if ( data.rest.rates_pending ) {
		add( errors, 'shipping_line', __( 'Дождитесь расчёта тарифов.', 'woodev-plugin-framework' ) );

		return errors;
	}

	if ( ! line ) {
		add( errors, 'shipping_line', __( 'Выберите способ доставки.', 'woodev-plugin-framework' ) );

		return errors;
	}

	const cost = String( line.cost ?? '' ).trim();

	if ( '' === cost || ! Number.isFinite( Number( cost ) ) || Number( cost ) < 0 ) {
		add( errors, 'shipping_line.cost', __( 'Стоимость доставки должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
	}

	const point = data.rest.pickup_point;
	const hasPoint = !! point && '' !== String( point.id ?? '' ).trim();

	if ( data.rest.rate_is_pickup && ! hasPoint ) {
		add( errors, 'pickup_point.id', __( 'Для этого тарифа выберите пункт выдачи.', 'woodev-plugin-framework' ) );
	}

	// The carrier's own fields (D7): the settings page's rules, over the fields the tariff asks for now.
	// The server re-checks them against the same declaration; a problem only it can see lands on the same path.
	for ( const field of visibleCarrierFields( data.rest.carrier_schema, data.rest.carrier_fields ) ) {
		const message = validateField( field, carrierFieldValue( field, data.rest.carrier_fields ) );

		if ( message ) {
			add( errors, `carrier_fields.${ field.id }`, message );
		}
	}

	return errors;
}

/**
 * ⑤ Оплата. The payment method and the status are picked from lists the server built from the
 * same sources the validator checks (`Order_Payload_Validator::check_payment_method()` /
 * `check_status()`), so a manager cannot pick a wrong one — this only catches a value the
 * lists do not know (a prefilled status of a plugin that has since gone). An empty list means the
 * server sent none (an older bootstrap): nothing to compare with, the server has the last word.
 */
export function validatePayment( data: WizardData, statuses: Record<string, string> | undefined ): FieldErrors {
	const errors: FieldErrors = {};
	const known = Object.keys( statuses || {} );
	const status = data.rest.status.replace( /^wc-/, '' );

	if ( '' !== status && known.length > 0 && ! known.includes( status ) ) {
		add( errors, 'status', __( 'Такого статуса заказа нет.', 'woodev-plugin-framework' ) );
	}

	return errors;
}

/** Runs the check of step `index`. */
export function validateStep(
	index: number,
	data: WizardData,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>,
	statuses?: Record<string, string>,
	addressPolicy?: AddressPolicy | null
): FieldErrors {
	switch ( WIZARD_STEPS[ index ] ) {
		case 'customer':
			return validateCustomer( data );
		case 'address':
			return validateAddress( data, countries, states, addressPolicy );
		case 'items':
			return validateItems( data );
		case 'delivery':
			return validateDelivery( data );
		case 'payment':
			return validatePayment( data, statuses );
		default:
			return {};
	}
}

/**
 * Every step's check, merged — the last look before the order is sent. The manager can only get
 * to ⑤ through «Далее», which checked each step on the way, but a step's data can change
 * behind its back (the rates answering late, a tariff dropping out), and one 422 round trip is
 * a poor way to learn it.
 */
export function validateAll(
	data: WizardData,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>,
	statuses?: Record<string, string>,
	addressPolicy?: AddressPolicy | null
): FieldErrors {
	const all: FieldErrors = {};

	WIZARD_STEPS.forEach( ( _step, index ) => {
		Object.assign( all, validateStep( index, data, countries, states, statuses, addressPolicy ) );
	} );

	return all;
}
