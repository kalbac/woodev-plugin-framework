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

/** What the dialog holds while the merchant types: text for every field, a pair for a time range, a list of ids for orders. */
export type ActionInputValues = Record< string, string | OrderActionTimeRange | string[] >;

/** The declared defaults, the way the server sent them — what the dialog opens with. */
export function initialValues( fields: OrderActionField[] ): ActionInputValues {
	const values: ActionInputValues = {};

	fields.forEach( ( field ) => {
		if ( 'time_range' === field.type ) {
			values[ field.id ] = { ...field.default };
		} else if ( 'orders' === field.type ) {
			values[ field.id ] = [ ...field.default ];
		} else {
			values[ field.id ] = field.default;
		}
	} );

	return values;
}

/**
 * What a form keeps when the carrier hands it a refreshed declaration (a toolbar dialog after a run, s164): the
 * merchant's day, window and comment stay as typed; the chosen orders stay chosen only while the server still offers
 * them — so after a partial failure the orders that failed are still selected and the ones that went through are gone;
 * a select whose option vanished falls back to its default. A field new to the form opens with its default.
 */
export function reconcileValues( fields: OrderActionField[], previous: ActionInputValues ): ActionInputValues {
	const fresh = initialValues( fields );

	fields.forEach( ( field ) => {
		const before = previous[ field.id ];

		if ( undefined === before ) {
			return;
		}

		if ( 'orders' === field.type ) {
			const offered = new Set( field.options.map( ( option ) => option.value ) );

			fresh[ field.id ] = Array.isArray( before ) ? before.filter( ( id ) => offered.has( id ) ) : fresh[ field.id ];
		} else if ( 'select' === field.type ) {
			fresh[ field.id ] =
				'string' === typeof before && field.options.some( ( option ) => option.value === before )
					? before
					: fresh[ field.id ];
		} else if ( 'time_range' === field.type ) {
			fresh[ field.id ] = 'object' === typeof before && ! Array.isArray( before ) ? before : fresh[ field.id ];
		} else {
			fresh[ field.id ] = 'string' === typeof before ? before : fresh[ field.id ];
		}
	} );

	return fresh;
}

/** The request body: every declared field, an empty one as `''` (a time range with empty ends). */
export function toPayload( fields: OrderActionField[], values: ActionInputValues ): OrderActionPayload {
	const payload: OrderActionPayload = {};

	fields.forEach( ( field ) => {
		const value = values[ field.id ];

		if ( 'orders' === field.type ) {
			payload[ field.id ] = Array.isArray( value ) ? [ ...value ] : [];
			return;
		}

		if ( 'time_range' === field.type ) {
			const range = ( 'object' === typeof value && ! Array.isArray( value ) && value ) || { from: '', to: '' };

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

		if ( 'orders' === field.type ) {
			// A run for no order is no run: the server refuses it whether or not the field is `required`.
			if ( ! Array.isArray( value ) || 0 === value.length ) {
				add( field, 'required', __( 'Выберите хотя бы один заказ.', 'woodev-plugin-framework' ) );
			}
			return;
		}

		if ( 'time_range' === field.type ) {
			const { from, to } = ( 'object' === typeof value && ! Array.isArray( value ) && value ) || { from: '', to: '' };

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
