/**
 * Woodev UI-kit — admin default-locality picker (issue #376, closes #370).
 *
 * Forked from `select-field.js`: same trigger-button + `Dropdown`/`SearchControl`
 * popover shell, but the option list is never static — it is fetched, debounced,
 * from the ADMIN-ONLY `GET woodev/v1/location/default-locality/suggest` route
 * (manager-gated, {@see Location_Controller::handle_admin_suggest_request()}) as
 * the merchant types. `location-select-modes.js` (the checkout's own jQuery/
 * select2 adapter for the same wire shape) is deliberately NOT reused here — it
 * is welded to a live DOM `<input>` on the checkout page, not a React tree.
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
 * EMPTY / ERROR STATES (the operator's own requirement — a picker must never
 * silently look like a working empty field):
 * - fewer than `MIN_QUERY_LENGTH` characters typed -> a hint, never a request;
 * - a request in flight -> a spinner row;
 * - a completed search with zero suggestions -> "Ничего не найдено" (this ALSO
 *   covers "no provider configured" — `perform_suggest()` deliberately
 *   degrades both to the same `{ suggestions: [] }`, 200, per its own
 *   docblock, so the client cannot and need not tell them apart; the
 *   store-wide "provider not configured" admin notice — Block 1,
 *   `add_not_configured_notices()` — already covers that case separately);
 * - a rejected/failed request -> a distinct error row, never mistaken for
 *   "zero results";
 * - a stored value that fails to parse as a well-formed `{ key, label }`
 *   record -> the TRIGGER itself shows a distinct "повреждено" state instead
 *   of quietly rendering as an empty/placeholder trigger, which would read as
 *   "nothing chosen yet" when something IS stored, just unreadably.
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
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState, useEffect, useMemo, useRef, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Dropdown, SearchControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ChevronIcon, CheckFilledIcon } from './icons';

/** @type {number} debounce interval, in ms, before a typed query fires a request. */
const DEBOUNCE_MS = 300;

/** @type {number} minimum query length before a search is issued — mirrors the server's own `Location_Controller::MIN_QUERY_LENGTH`. */
const MIN_QUERY_LENGTH = 2;

/** @type {string} the level every admin default-locality search runs at — see the file docblock. */
const LEVEL = 'settlement';

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
export function namespaceRoot( restRoot ) {
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
 * @return {{state: string, label?: string, key?: string, providerId?: string}} parsed state.
 */
export function parseStoredRecord( raw ) {
	const s = 'string' === typeof raw ? raw : '';

	if ( '' === s.trim() ) {
		return { state: 'empty' };
	}

	let parsed;
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
export function getProviderMismatchError( raw, providerId, persistedProviderId = null ) {
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

/**
 * @param {Object}   props            component props.
 * @param {string}   [props.value]    current stored value — `''` or a `Location_Record` JSON string.
 * @param {string}   [props.country]  ISO-3166 alpha-2 country to scope suggest requests to (`schema.country`).
 * @param {string}   [props.provider] the `active_provider` select's LIVE, unsaved value (issue
 *                                    #380) — sent as the admin suggest route's `provider` override
 *                                    so the picker follows the select immediately, never the
 *                                    stored option. `''` (e.g. no sibling field wired it through)
 *                                    falls back to the server's own D15 chain resolution, matching
 *                                    this control's pre-#380 behaviour exactly.
 * @param {boolean}  [props.disabled] whether the control is disabled (D11).
 * @param {Function} props.onChange   change handler — called with the serialized record, or `''`.
 * @return {JSX.Element} the picker.
 */
export default function LocationPickerField( { value, country, provider = '', disabled = false, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState( 'idle' ); // idle | loading | error
	const [ results, setResults ] = useState( [] );
	const [ searched, setSearched ] = useState( false );
	const generationRef = useRef( 0 );
	const triggerRef = useRef( null );

	const stored = useMemo( () => parseStoredRecord( value ), [ value ] );

	useEffect( () => {
		const query = search.trim();

		if ( query.length < MIN_QUERY_LENGTH ) {
			// Invalidates any in-flight/pending request for a query that no longer
			// applies — mirrors location-typeahead.js's own generation-bump-on-
			// invalidate convention, adapted to React's cleanup-based cancellation.
			generationRef.current += 1;
			setStatus( 'idle' );
			setResults( [] );
			setSearched( false );

			return undefined;
		}

		const myGeneration = ++generationRef.current;
		setStatus( 'loading' );

		const timer = setTimeout( () => {
			const { restRoot, nonce } = window.woodevSettings || {};
			const root = namespaceRoot( restRoot );
			const params = new URLSearchParams( {
				q: query,
				level: LEVEL,
				country: country || '',

				// Admin-only override (issue #380) — the PUBLIC `/suggest` route
				// is never called from this file, so this param has no effect
				// there; see the file docblock's own "PROVIDER FOLLOWS THE
				// SELECT LIVE" section.
				...( provider ? { provider } : {} ),
			} );

			apiFetch( {
				url: `${ root }/location/default-locality/suggest?${ params.toString() }`,
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
			} )
				.then( ( res ) => {
					if ( myGeneration !== generationRef.current ) {
						return; // Stale response — a newer query already owns the UI.
					}
					setResults( res && Array.isArray( res.suggestions ) ? res.suggestions : [] );
					setStatus( 'idle' );
					setSearched( true );
				} )
				.catch( () => {
					if ( myGeneration !== generationRef.current ) {
						return;
					}
					setResults( [] );
					setStatus( 'error' );
					setSearched( true );
				} );
		}, DEBOUNCE_MS );

		return () => clearTimeout( timer );

		// `provider` in the deps (issue #380): switching the provider select
		// must re-issue the CURRENT search against the newly selected provider
		// immediately, the same way a `country` change already does — not wait
		// for the next keystroke.
	}, [ search, country, provider ] );

	const choose = ( entry, close ) => {
		onChange( JSON.stringify( entry.record ) );
		setSearch( '' );
		close();
	};

	const triggerLabel = ( () => {
		if ( 'ok' === stored.state ) {
			return stored.label;
		}
		if ( 'broken' === stored.state ) {
			return __( 'Некорректное сохранённое значение — выберите заново', 'woodev-plugin-framework' );
		}
		return __( 'Выберите локацию…', 'woodev-plugin-framework' );
	} )();

	const isPlaceholder = 'empty' === stored.state;
	const isBroken = 'broken' === stored.state;

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
			<Dropdown
				className="woodev-select woodev-location-picker"
				contentClassName="woodev-select__popover"
				popoverProps={ { placement: 'bottom-start', offset: 4 } }
				onToggle={ ( open ) => {
					if ( ! open ) {
						setSearch( '' );
						setResults( [] );
						setStatus( 'idle' );
						setSearched( false );
					}
				} }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<button
						type="button"
						ref={ triggerRef }
						className={
							'woodev-select__trigger'
							+ ( isOpen ? ' is-open' : '' )
							+ ( disabled ? ' is-disabled' : '' )
							+ ( isBroken ? ' is-broken' : '' )
						}
						onClick={ onToggle }
						disabled={ disabled }
						aria-expanded={ isOpen }
						aria-haspopup="listbox"
					>
						<span
							className={
								'woodev-select__value'
								+ ( isPlaceholder ? ' is-placeholder' : '' )
								+ ( isBroken ? ' is-broken' : '' )
							}
						>
							{ triggerLabel }
						</span>
						<span className="woodev-select__chevron"><ChevronIcon /></span>
					</button>
				) }
				renderContent={ ( { onClose } ) => (
					<div
						className="woodev-select__menu"
						style={ { minWidth: triggerRef.current ? triggerRef.current.offsetWidth + 'px' : undefined } }
					>
						<SearchControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							value={ search }
							onChange={ setSearch }
							placeholder={ __( 'Начните вводить название…', 'woodev-plugin-framework' ) }
						/>
						<div className="woodev-select__list woodev-location-picker__list" role="listbox">
							{ search.trim().length < MIN_QUERY_LENGTH && (
								<div className="woodev-select__empty">
									{ __( 'Введите минимум 2 символа для поиска', 'woodev-plugin-framework' ) }
								</div>
							) }
							{ search.trim().length >= MIN_QUERY_LENGTH && 'loading' === status && (
								<div className="woodev-location-picker__status">
									<Spinner />
									<span>{ __( 'Поиск…', 'woodev-plugin-framework' ) }</span>
								</div>
							) }
							{ 'error' === status && (
								<div className="woodev-select__empty woodev-location-picker__status--error">
									{ __( 'Не удалось загрузить подсказки. Попробуйте ещё раз.', 'woodev-plugin-framework' ) }
								</div>
							) }
							{ 'idle' === status && searched && 0 === results.length && (
								<div className="woodev-select__empty">
									{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }
								</div>
							) }
							{ 'idle' === status && results.map( ( entry ) => (
								<button
									key={ entry.key }
									type="button"
									role="option"
									aria-selected={ 'ok' === stored.state && stored.key === entry.key }
									className={
										'woodev-select__option'
										+ ( 'ok' === stored.state && stored.key === entry.key ? ' is-selected' : '' )
									}
									onClick={ () => choose( entry, onClose ) }
								>
									<span className="woodev-select__check">
										{ 'ok' === stored.state && stored.key === entry.key && <CheckFilledIcon /> }
									</span>
									<span className="woodev-select__option-label">{ entry.label }</span>
								</button>
							) ) }
						</div>
					</div>
				) }
			/>
			{ providerMismatch && (
				<div className="woodev-location-picker__mismatch" role="status">
					{ __( 'Зафиксированная локация была выбрана через другого провайдера и может не подойти текущему — рекомендуется выбрать её заново.', 'woodev-plugin-framework' ) }
				</div>
			) }
		</Fragment>
	);
}
