/**
 * License card state derivation — PURE, no React, no side effects.
 *
 * Maps a get_state() object to a presentation descriptor that the LicenseCard
 * renders declaratively. Encodes the approved 7-group state machine on the real
 * EDD status tokens (spec: docs-internal/specs/2026-06-18-license-page-ui-ux-redesign.md).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';

// Raw EDD status tokens where the KEY ITSELF is suspect → field editable (group E).
const BAD_KEY_STATUSES = [
	'invalid',
	'key_mismatch',
	'item_name_mismatch',
	'invalid_item_id',
	'missing',
	'missing_url',
	'license_not_activable',
];

// Revoked/disabled → message only, no activate path (group F).
const REVOKED_STATUSES = [ 'disabled', 'revoked' ];

// Binding/limit → masked, re-activate to claim a slot (group D).
const BINDING_STATUSES = [ 'site_inactive', 'no_activations_left' ];

/**
 * Parses the raw expires value into a JS Date, or null when not a real date.
 *
 * @param {string|number|null} expires Raw expires ('lifetime'|'Y-m-d H:i:s'|timestamp|''|null).
 * @return {Date|null} Parsed date, or null for lifetime/empty/unknown.
 */
export function parseExpiry( expires ) {
	if ( ! expires || expires === 'lifetime' ) {
		return null;
	}
	if ( typeof expires === 'number' || /^\d+$/.test( String( expires ) ) ) {
		const date = new Date( Number( expires ) * 1000 );
		return isNaN( date.getTime() ) ? null : date;
	}
	// 'Y-m-d H:i:s' → ISO-ish for the Date parser.
	const date = new Date( String( expires ).replace( ' ', 'T' ) );
	return isNaN( date.getTime() ) ? null : date;
}

/**
 * Formats a Date as DD.MM.YY (or DD.MM when withYear is false).
 *
 * @param {Date}    date     The date.
 * @param {boolean} withYear Include the 2-digit year.
 * @return {string} Formatted date.
 */
export function formatExpiry( date, withYear = true ) {
	const dd = String( date.getDate() ).padStart( 2, '0' );
	const mm = String( date.getMonth() + 1 ).padStart( 2, '0' );
	if ( ! withYear ) {
		return `${ dd }.${ mm }`;
	}
	const yy = String( date.getFullYear() ).slice( -2 );
	return `${ dd }.${ mm }.${ yy }`;
}

/**
 * True when a valid license expires in under a month (group B′).
 *
 * @param {Date|null} expiry Parsed expiry date (null = lifetime).
 * @return {boolean} Whether it expires within ~30 days.
 */
function expiresSoon( expiry ) {
	if ( ! expiry ) {
		return false;
	}
	const ms = expiry.getTime() - Date.now();
	return ms > 0 && ms < 30 * 24 * 60 * 60 * 1000;
}

/**
 * Derives the presentation descriptor for a license card.
 *
 * @param {Object}  state           The get_state() object.
 * @param {boolean} editingKeyForce When true (user clicked «Изменить ключ»), force the editable+empty key path.
 * @return {Object} Descriptor: { group, accent, badge:{label,variant}, keyEditable, controlsEnabled, actions }.
 *                  actions: { activate, verify, renew, deactivate, changeKey, cancelEdit, renewAccent }.
 */
export function getCardView( state, editingKeyForce = false ) {
	const status = state.status || '';
	const hasKey = !! ( state.license_key && state.license_key.length );
	const expiry = parseExpiry( state.expires );

	// User explicitly chose to replace a saved key — behave like group A (no key).
	if ( editingKeyForce ) {
		return {
			group: 'editing',
			accent: 'neutral',
			badge: { label: __( 'Изменение ключа', 'woodev-plugin-framework' ), variant: 'info' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false, cancelEdit: true },
		};
	}

	// Group A — no key stored.
	if ( ! hasKey ) {
		return {
			group: 'no-key',
			accent: 'neutral',
			badge: { label: __( 'Не активирована', 'woodev-plugin-framework' ), variant: 'info' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false },
		};
	}

	// Group E — the key itself is suspect → editable to correct it.
	if ( BAD_KEY_STATUSES.includes( status ) ) {
		return {
			group: 'bad-key',
			accent: 'error',
			badge: { label: __( 'Неверный ключ', 'woodev-plugin-framework' ), variant: 'error' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false },
		};
	}

	// Group F — revoked/disabled → message only, masked, no activate.
	if ( REVOKED_STATUSES.includes( status ) ) {
		return {
			group: 'revoked',
			accent: 'error',
			badge: { label: __( 'Ключ отозван', 'woodev-plugin-framework' ), variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: false, deactivate: false, changeKey: false },
		};
	}

	// Group D — binding / limit → masked, re-activate to claim a slot.
	if ( BINDING_STATUSES.includes( status ) ) {
		const label = status === 'no_activations_left'
			? __( 'Лимит исчерпан', 'woodev-plugin-framework' )
			: __( 'Активна на другом сайте', 'woodev-plugin-framework' );
		// changeKey is intentionally OFF here: the key is GENUINE (the problem is
		// site binding / slot limit, not the key), so per the editability principle
		// the field stays masked + read-only — re-activate retries the stored key.
		return {
			group: 'binding',
			accent: 'error',
			badge: { label, variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: true, verify: true, renew: false, deactivate: false, changeKey: false },
		};
	}

	// Group C — expired → masked, renew (accent) + deactivate.
	if ( status === 'expired' ) {
		const when = expiry ? ` · ${ formatExpiry( expiry, true ) }` : '';
		return {
			group: 'expired',
			accent: 'error',
			badge: { label: __( 'Истекла', 'woodev-plugin-framework' ) + when, variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true, renewAccent: true },
		};
	}

	// Group B / B′ — valid.
	if ( status === 'valid' ) {
		if ( expiresSoon( expiry ) ) {
			return {
				group: 'expiring',
				accent: 'warning',
				badge: { label: __( 'Истекает ', 'woodev-plugin-framework' ) + formatExpiry( expiry, false ), variant: 'warning' },
				keyEditable: false,
				controlsEnabled: true,
				actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true },
			};
		}
		const label = expiry
			? __( 'Активна · до ', 'woodev-plugin-framework' ) + formatExpiry( expiry, true )
			: __( 'Активна · Бессрочно', 'woodev-plugin-framework' );
		return {
			group: 'active',
			accent: 'success',
			badge: { label, variant: 'success' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true },
		};
	}

	// Fallback — unknown status: masked, show the server label, allow re-verify.
	return {
		group: 'unknown',
		accent: 'info',
		badge: {
			label: state.status_label || __( 'Неизвестный статус', 'woodev-plugin-framework' ),
			variant: state.message_variant || 'info',
		},
		keyEditable: false,
		controlsEnabled: true,
		// changeKey OFF: an unrecognised status must not open the editable path and
		// weaken the "editable only in A and E" rule; re-verify is still available.
		actions: { activate: false, verify: true, renew: false, deactivate: false, changeKey: false },
	};
}
