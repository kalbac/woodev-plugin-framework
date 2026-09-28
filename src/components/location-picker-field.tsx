/**
 * Woodev UI-kit — admin default-locality picker (issue #376, closes #370).
 *
 * The settings-page face of `location-picker.tsx` (#960 lifted the generic
 * trigger/popover/search machinery out of this file so the order wizard can reuse
 * it): what stays here is everything specific to the `default_locality_record`
 * setting — the stored-value parsing, the ADMIN-ONLY suggest route, the live
 * provider follow-through and the stale-record warning.
 *
 * WHY A REAL PICKER: `default_locality_record` holds a serialized
 * `Location_Record` JSON string, never free text. Before this control existed
 * the field had NO controlType at all (`Field_Schema::from_handler()` emits
 * `controlType: null` for an uncontrolled setting), so `resolveControl()` fell
 * back to a plain text input over that raw JSON — a merchant could type
 * anything and the `fixed` default-locality policy would silently stop working
 * (issue #370). Selecting a suggestion here stores `JSON.stringify(entry.record)`
 * verbatim — the same shape `Location_Record::to_array()`/`::from_array()`
 * round-trips on the server (D12/D5) — never the label, never hand-typed text.
 *
 * ROUTE: the picker asks `GET woodev/v1/location/default-locality/suggest`
 * (manager-gated, {@see Location_Controller::handle_admin_suggest_request()}), not
 * the public `/location/suggest` the generic picker defaults to — that admin route
 * is the one that honours the `provider` override below. `location-select-modes.js`
 * (the checkout's own jQuery/select2 adapter for the same wire shape) is
 * deliberately NOT reused: it is welded to a live DOM `<input>` on the checkout page.
 * The REST root and nonce come from the settings page's own `window.woodevSettings`
 * — read HERE, at the settings-page seam, and handed to the generic picker as props.
 *
 * COUNTRY: resolved server-side ONCE, at schema-build time, through
 * `Location_Service::resolve_default_country()` (store setting -> `RU`) and
 * carried on `schema.country` (`Field_Schema` / `Woodev_Control::$country`) —
 * the schema is the natural carrier since it is already the per-field channel
 * PHP uses to hand the client anything resolved server-side (tooltip,
 * placeholder, options…). The client never re-derives this cascade itself.
 *
 * LEVEL: fixed at `settlement` — "locality" already means the settlement level
 * everywhere else in this layer (`Locality_Key`, `location-typeahead.js`'s own
 * "НП" widget, `DadataProvider`'s settlement branch); a "Зафиксированная
 * локация" default is that same concept, not a region or a street address.
 *
 * A stored value that fails to parse as a well-formed `{ key, label }` record ->
 * the TRIGGER itself shows a distinct "повреждено" state (the generic picker's
 * `broken` prop) instead of quietly rendering as an empty/placeholder trigger,
 * which would read as "nothing chosen yet" when something IS stored, just
 * unreadably. The empty / loading / error states of the popover are the generic
 * picker's own.
 *
 * PROVIDER FOLLOWS THE SELECT LIVE (issue #380, closes the #375 gap this
 * picker itself used to have): `props.provider` is the `active_provider`
 * select's CURRENT, UNSAVED form value (threaded from `app.js`'s own
 * `conditionValues` map via `ControlField` — the same channel `show_if`
 * visibility already uses), sent as the admin-only `provider` query param on
 * every suggest request ({@see Location_Controller::handle_admin_suggest_request()}).
 * Switching the provider select therefore changes what THIS request asks for
 * immediately, without waiting for Save — before this, `perform_suggest()`
 * resolved the provider from the STORED option, so the picker kept
 * suggesting from whichever provider was active before the merchant's most
 * recent (unsaved) change, which read as broken.
 *
 * STALE-RECORD WARNING: a record picked under one provider may not mean
 * anything to a DIFFERENT one (same concern
 * `Location_Provider_Registry::apply_default_locality_status_note()` already
 * surfaces, load-time only, as the field's own description). This component
 * adds the LIVE equivalent: whenever a well-formed stored record's own
 * `provider_id` differs from the currently selected `provider`, a warning
 * renders under the trigger — computed from props alone, no extra request —
 * so switching the select surfaces the mismatch immediately rather than only
 * after Save re-fetches the schema.
 *
 * @package woodev-plugin-framework
 */

import { useMemo, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LocationPicker, { type LocationSuggestion } from './location-picker';

/** The admin-only suggest route, under the `woodev/v1` root. */
const ADMIN_SUGGEST_ENDPOINT = '/location/default-locality/suggest';

/** The level every admin default-locality search runs at — see the file docblock. */
const LEVEL = 'settlement';

/** What {@see parseStoredRecord} makes of a stored value. */
export interface StoredRecord {
	state: 'empty' | 'broken' | 'ok';
	label?: string;
	key?: string;
	providerId?: string;
}

/**
 * Derives the `woodev/v1` namespace root from the settings page's own
 * `restRoot` (`.../woodev/v1/settings`, localized by
 * `class-settings-page-registry.php`) — the admin suggest route lives on a
 * SIBLING path under the same namespace, which `window.woodevSettings` does
 * not localize separately; reusing the one root the page already carries
 * avoids adding a second localized global for one extra path segment.
 *
 * @param {string} restRoot the settings page's own REST root.
 * @return {string} the `woodev/v1` namespace root, no trailing slash.
 */
export function namespaceRoot( restRoot: string | undefined ): string {
	return String( restRoot || '' ).replace( /\/settings\/?$/, '' );
}

/**
 * Parses a stored `default_locality_record` value into a display-ready shape.
 *
 * Three states, never conflated: `empty` (nothing stored — the ordinary
 * unset case), `broken` (a non-empty value that is not valid JSON, or does
 * not carry a usable `label`/`key` — a hand-edited value, a stored record
 * from a format this client no longer understands, or corruption), and `ok`.
 *
 * @param {*} raw the raw stored value (a JSON string, or '').
 * @return {StoredRecord} parsed state.
 */
export function parseStoredRecord( raw: unknown ): StoredRecord {
	const s = 'string' === typeof raw ? raw : '';

	if ( '' === s.trim() ) {
		return { state: 'empty' };
	}

	let parsed: Record<string, unknown> | null;
	try {
		parsed = JSON.parse( s );
	} catch ( error ) {
		return { state: 'broken' };
	}

	if ( ! parsed || 'object' !== typeof parsed || 'string' !== typeof parsed.label || '' === parsed.label ) {
		return { state: 'broken' };
	}

	return {
		state: 'ok',
		label: parsed.label,
		key: 'string' === typeof parsed.key ? parsed.key : '',

		// `Location_Record::to_array()`'s own `provider_id` — round-tripped
		// verbatim, same as `key`/`label` above (issue #380: this is what the
		// live mismatch warning below compares against the currently
		// selected provider).
		providerId: 'string' === typeof parsed.provider_id ? parsed.provider_id : '',
	};
}

/**
 * Client-side PREVIEW of the server's authoritative save-blocking check
 * ({@see Location_Settings::validate_values()}, issue #406) — same message,
 * an APPROXIMATION of the same comparison (`parseStoredRecord().providerId`
 * vs the effective `active_provider` value for this save), reused by
 * `ControlField` (live, blur-gated inline error) and `app.js` (Save
 * disablement). When the loaded raw provider id does not match the record's
 * provider, the client deliberately fails open: deregistered-provider
 * fallback and `woodev_location_active_provider` filter substitutions are
 * only knowable on the server. The server remains the actual gate.
 *
 * @param {*}      raw                 the record field's raw stored/edited value.
 * @param {string} providerId          the effective `active_provider` value for this same save.
 * @param {string|null} [persistedProviderId] raw provider value before this form was edited.
 * @return {string|null} error message, or null when there is nothing to block.
 */
export function getProviderMismatchError(
	raw: unknown,
	providerId: string,
	persistedProviderId: string | null = null
): string | null {
	const stored = parseStoredRecord( raw );

	if ( 'ok' !== stored.state || ! stored.providerId || ! providerId || stored.providerId === providerId ) {
		return null;
	}

	// The server resolves raw provider ids through the registered-provider lookup
	// and a public filter. If the persisted raw id does not name the record's
	// provider, the client cannot prove that a raw mismatch is invalid (it may be
	// a deregistered-provider fallback or a filter substitution), so leave Save
	// available for the authoritative server check.
	if ( null !== persistedProviderId && stored.providerId !== persistedProviderId ) {
		return null;
	}

	return __( 'Зафиксированная локация выбрана для другого провайдера — выберите её заново или верните прежнего провайдера.', 'woodev-plugin-framework' );
}

export interface LocationPickerFieldProps {
	/** Current stored value — `''` or a `Location_Record` JSON string. */
	value?: string;
	/** ISO-3166 alpha-2 country to scope suggest requests to (`schema.country`). */
	country?: string;
	/**
	 * The `active_provider` select's LIVE, unsaved value (issue #380) — sent as the admin
	 * suggest route's `provider` override so the picker follows the select immediately,
	 * never the stored option. `''` (e.g. no sibling field wired it through) falls back to
	 * the server's own D15 chain resolution, matching this control's pre-#380 behaviour.
	 */
	provider?: string;
	/** Whether the control is disabled (D11). */
	disabled?: boolean;
	/** Change handler — called with the serialized record, or `''`. */
	onChange: ( value: string ) => void;
}

/**
 * @param {LocationPickerFieldProps} props component props.
 * @return {JSX.Element} the picker.
 */
export default function LocationPickerField( {
	value,
	country,
	provider = '',
	disabled = false,
	onChange,
}: LocationPickerFieldProps ) {
	const stored = useMemo( () => parseStoredRecord( value ), [ value ] );

	const { restRoot, nonce } = ( window as { woodevSettings?: { restRoot?: string; nonce?: string } } ).woodevSettings || {};

	const choose = ( entry: LocationSuggestion ) => {
		onChange( JSON.stringify( entry.record ) );
	};

	/*
	 * Live provider-mismatch warning (issue #380) — see the file docblock's
	 * own "STALE-RECORD WARNING" section. Deliberately requires ALL THREE:
	 * a well-formed stored record (`broken`/`empty` already have their own,
	 * more specific state), a KNOWN `providerId` on that record, and a
	 * currently selected `provider` to compare it against — an empty
	 * `provider` (no sibling field wired it through this render) must never
	 * be treated as "mismatched", only as "nothing to compare against".
	 */
	const providerMismatch =
		'ok' === stored.state && !! stored.providerId && !! provider && stored.providerId !== provider;

	return (
		<Fragment>
			<LocationPicker
				value={ 'ok' === stored.state ? { key: stored.key || '', label: stored.label || '' } : null }
				broken={ 'broken' === stored.state }
				level={ LEVEL }
				country={ country || '' }
				restRoot={ namespaceRoot( restRoot ) }
				nonce={ nonce }
				endpoint={ ADMIN_SUGGEST_ENDPOINT }
				params={ provider ? { provider } : undefined }
				disabled={ disabled }
				onChange={ choose }
			/>
			{ providerMismatch && (
				<div className="woodev-location-picker__mismatch" role="status">
					{ __( 'Зафиксированная локация была выбрана через другого провайдера и может не подойти текущему — рекомендуется выбрать её заново.', 'woodev-plugin-framework' ) }
				</div>
			) }
		</Fragment>
	);
}
