/**
 * The values an order action with fields collects (#1180), and the checks that need no server.
 *
 * Pure — no React, no DOM — so the dialog (`./action-input-modal`) stays a thin view over it and the rules are
 * pinned by a plain unit test. The SERVER is the authority: `Order_Action_Fields::validate()` checks the same
 * declaration again and answers 422 with per-field errors. These checks exist so an empty required field or a
 * window that ends before it starts is caught without a round trip.
 *
 * @package woodev-plugin-framework
 */

import { __, sprintf } from '@wordpress/i18n';
import type {
	OrderActionField,
	OrderActionFieldError,
	OrderActionPayload,
	OrderActionTimeRange,
} from './rest';

/** What the dialog holds while the merchant types: text for every field, a pair for a time range. */
export type ActionInputValues = Record< string, string | OrderActionTimeRange >;

/** The declared defaults, the way the server sent them — what the dialog opens with. */
export function initialValues( fields: OrderActionField[] ): ActionInputValues {
	const values: ActionInputValues = {};

	fields.forEach( ( field ) => {
		values[ field.id ] =
			'time_range' === field.type ? { ...field.default } : field.default;
	} );

	return values;
}

/** The request body: every declared field, an empty one as `''` (a time range with empty ends). */
export function toPayload( fields: OrderActionField[], values: ActionInputValues ): OrderActionPayload {
	const payload: OrderActionPayload = {};

	fields.forEach( ( field ) => {
		const value = values[ field.id ];

		if ( 'time_range' === field.type ) {
			const range = ( 'object' === typeof value && value ) || { from: '', to: '' };

			payload[ field.id ] = { from: range.from, to: range.to };
			return;
		}

		payload[ field.id ] = 'string' === typeof value ? value : '';
	} );

	return payload;
}

/**
 * The errors the browser can find alone: a required field left empty, a date or time outside its bounds,
 * a window that does not end after it starts, a text over its limit. At most one per field.
 */
export function validateInput( fields: OrderActionField[], values: ActionInputValues ): OrderActionFieldError[] {
	const errors: OrderActionFieldError[] = [];
	const add = ( field: OrderActionField, code: string, message: string ) =>
		errors.push( { field: field.id, code, message } );
	const required = __( 'Заполните это поле.', 'woodev-plugin-framework' );
	const outOfRange = __( 'Значение вне допустимых пределов.', 'woodev-plugin-framework' );

	fields.forEach( ( field ) => {
		const value = values[ field.id ];

		if ( 'time_range' === field.type ) {
			const { from, to } = ( 'object' === typeof value && value ) || { from: '', to: '' };

			if ( ! from && ! to ) {
				if ( field.required ) {
					add( field, 'required', required );
				}
			} else if ( ! from || ! to ) {
				add( field, 'invalid', __( 'Укажите время начала и окончания.', 'woodev-plugin-framework' ) );
			} else if ( from >= to ) {
				add( field, 'invalid_range', __( 'Время окончания должно быть позже времени начала.', 'woodev-plugin-framework' ) );
			} else if ( ( field.min && from < field.min ) || ( field.max && to > field.max ) ) {
				add( field, 'out_of_range', outOfRange );
			}
			return;
		}

		const text = 'string' === typeof value ? value.trim() : '';

		if ( '' === text ) {
			if ( field.required ) {
				add( field, 'required', required );
			}
			return;
		}

		if ( 'date' === field.type && ( ( field.min && text < field.min ) || ( field.max && text > field.max ) ) ) {
			add( field, 'out_of_range', outOfRange );
		}

		if ( 'textarea' === field.type && text.length > field.maxlength ) {
			add(
				field,
				'too_long',
				/* translators: %d: the longest the text may be, in characters. */
				sprintf( __( 'Не больше %d символов.', 'woodev-plugin-framework' ), field.maxlength )
			);
		}
	} );

	return errors;
}
