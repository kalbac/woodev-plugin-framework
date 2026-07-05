/**
 * UI-kit — client-side field validation (mirror of the PHP server validator).
 *
 * Faithful port of Woodev_Setting::get_validation_error() — KEEP IN SYNC with
 * woodev/settings-api/class-setting.php. The authoritative rule table lives in
 * the SP-3 design spec §4. The server is the authoritative gate; this only
 * drives client UX (live errors + Save/«Продолжить» gating).
 *
 * @package woodev-plugin-framework
 */

/**
 * Whether a `required` flag applies to a control type (toggle/checkbox/range
 * always carry a value, so requiring them is a no-op).
 *
 * @param {string} controlType resolved control type.
 * @return {boolean} true when required is meaningful.
 */
export function isRequirable( controlType ) {
	return ! [ 'toggle', 'checkbox', 'range' ].includes( controlType );
}

/**
 * Whether a value counts as empty for the given control type.
 *
 * @param {string} controlType resolved control type.
 * @param {*}      value       value to inspect.
 * @return {boolean} true when empty.
 */
export function isEmpty( controlType, value ) {
	if ( Array.isArray( value ) ) {
		return 0 === value.length;
	}
	if ( [ 'select', 'radio' ].includes( controlType ) ) {
		return '' === String( value ?? '' );
	}
	return '' === String( value ?? '' ).trim();
}

/**
 * Permissive phone check: allowed chars only, at least 5 digits.
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidTel( value ) {
	const s = String( value );
	if ( ! /^[\d\s\-()+]+$/.test( s ) ) {
		return false;
	}
	return s.replace( /\D/g, '' ).length >= 5;
}

/**
 * Validates a URL: http(s):// prefix + parseable.
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidUrl( value ) {
	const s = String( value );
	if ( ! /^https?:\/\//.test( s ) ) {
		return false;
	}
	try {
		new URL( s );
		return true;
	} catch {
		return false;
	}
}

/**
 * Loose email check approximating WP is_email().
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidEmail( value ) {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value ) );
}

/**
 * Resolves the control kind from controlType, then legacy inference (mirrors
 * control-field.js resolveControl so validation keys on the same kind).
 *
 * @param {Object} schema field schema slice.
 * @return {string} control kind.
 */
function resolveKind( schema ) {
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
 * Returns a validation error message for a field value, or null when valid.
 *
 * @param {Object} schema field schema slice (controlType, required, min, max…).
 * @param {*}      value  current value.
 * @return {string|null} error message (Russian) or null.
 */
export function validateField( schema, value ) {
	const kind = resolveKind( schema );

	if ( schema.required && isRequirable( kind ) && isEmpty( kind, value ) ) {
		return 'Обязательное поле.';
	}

	if ( isEmpty( kind, value ) ) {
		return null;
	}

	// A field with a plugin-supplied server validator can't be checked client-side
	// (the PHP callback has no JS equivalent) — only required is enforced here; the
	// server is the authority for its format. Skip the default format check.
	if ( schema.server_validated ) {
		return null;
	}

	switch ( kind ) {
		case 'email':
			if ( ! isValidEmail( value ) ) {
				return 'Введите корректный email.';
			}
			break;
		case 'url':
			if ( ! isValidUrl( value ) ) {
				return 'Введите корректный URL (с http:// или https://).';
			}
			break;
		case 'tel':
			if ( ! isValidTel( value ) ) {
				return 'Введите корректный номер телефона.';
			}
			break;
		case 'number':
		case 'range': {
			const n = Number( value );
			if ( '' === String( value ).trim() || Number.isNaN( n ) ) {
				return 'Введите число.';
			}
			// eslint-disable-next-line eqeqeq
			if ( null != schema.min && n < schema.min ) {
				return `Значение не меньше ${ schema.min }.`;
			}
			// eslint-disable-next-line eqeqeq
			if ( null != schema.max && n > schema.max ) {
				return `Значение не больше ${ schema.max }.`;
			}
			break;
		}
		default:
			break;
	}

	// PHP also runs a legacy type check + enum-membership check here; intentionally
	// omitted client-side — the server is the authoritative gate for those.
	return null;
}

/**
 * Validates a whole section's fields against the current values.
 *
 * @param {Object} fields section.fields (settingId => schema).
 * @param {Object} values current edited values (settingId => value).
 * @return {Object} settingId => error message (only invalid fields).
 */
export function validateFields( fields, values ) {
	const errors = {};
	Object.keys( fields || {} ).forEach( ( id ) => {
		const schema = fields[ id ];
		const value = values[ id ] ?? schema.value;
		const error = validateField( schema, value );
		if ( error ) {
			errors[ id ] = error;
		}
	} );
	return errors;
}

/**
 * Normalizes a show_if group into { relation, members }.
 *
 * PHP serializes a mixed-key array (`['relation'=>…, 0=>{…}]`) to a JSON OBJECT,
 * and a pure list to a JSON ARRAY — so handle both. A bare single condition
 * (has a `setting` key) becomes a one-condition AND group.
 *
 * @param {Object|Array} conditions raw show_if from the schema.
 * @return {{relation: string, members: Array}} normalized group.
 */
function conditionGroup( conditions ) {
	if ( conditions && conditions.setting ) {
		return { relation: 'AND', members: [ conditions ] };
	}
	const relation = String( conditions.relation || 'AND' ).toUpperCase();
	const members = Object.keys( conditions )
		.filter( ( k ) => 'relation' !== k )
		.map( ( k ) => conditions[ k ] )
		.filter( ( c ) => c && 'object' === typeof c );
	return { relation, members };
}

/**
 * Coerces a value to its comparable string, matching PHP's (string) cast:
 * booleans become '1' / '' (not 'true' / 'false'). KEEP IN SYNC with the PHP
 * evaluator's is_scalar/(string) handling.
 *
 * @param {*} value value to coerce.
 * @return {string} comparable string.
 */
function toComparable( value ) {
	if ( 'boolean' === typeof value ) {
		return value ? '1' : '';
	}
	return String( value );
}

/**
 * Evaluates a show_if condition group against current field values.
 *
 * Faithful mirror of Woodev_Setting::evaluate_conditions() — KEEP IN SYNC with
 * woodev/settings-api/class-setting.php. Rule table: conditional-fields spec §5.
 * Pure + total: empty group → visible; string comparison; an unset/non-scalar
 * controlling value is the empty string; unknown operator → not matching.
 *
 * @param {Object|Array} conditions show_if group.
 * @param {Object}       values     controlling settingId => current value.
 * @return {boolean} true when visible.
 */
export function evaluateConditions( conditions, values ) {
	if ( ! conditions ) {
		return true;
	}

	const { relation, members } = conditionGroup( conditions );

	if ( 0 === members.length ) {
		return true;
	}

	for ( const condition of members ) {
		const settingId = String( condition.setting ?? '' );
		const operator = condition.operator || '=';
		const target = condition.value ?? '';
		const raw = values[ settingId ];
		const current =
			null === raw || undefined === raw || 'object' === typeof raw
				? ''
				: toComparable( raw );

		let match;
		switch ( operator ) {
			case '=':
				match = current === toComparable( target );
				break;
			case '!=':
				match = current !== toComparable( target );
				break;
			case 'in':
				match = ( Array.isArray( target ) ? target : [ target ] )
					.map( toComparable )
					.includes( current );
				break;
			case 'not_in':
				match = ! ( Array.isArray( target ) ? target : [ target ] )
					.map( toComparable )
					.includes( current );
				break;
			default:
				match = false; // unknown operator → fail-closed
				break;
		}

		if ( 'OR' === relation && match ) {
			return true;
		}
		if ( 'AND' === relation && ! match ) {
			return false;
		}
	}

	return 'AND' === relation;
}

/**
 * Whether a field is visible given the current values (schema.show_if absent → visible).
 *
 * @param {Object} schema field schema slice.
 * @param {Object} values controlling settingId => current value.
 * @return {boolean} true when visible.
 */
export function isFieldVisible( schema, values ) {
	if ( ! schema || ! schema.show_if ) {
		return true;
	}
	return evaluateConditions( schema.show_if, values );
}
