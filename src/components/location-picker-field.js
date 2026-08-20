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
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
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
 * @return {{state: string, label?: string, key?: string}} parsed state.
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

	return { state: 'ok', label: parsed.label, key: 'string' === typeof parsed.key ? parsed.key : '' };
}

/**
 * @param {Object}   props            component props.
 * @param {string}   [props.value]    current stored value — `''` or a `Location_Record` JSON string.
 * @param {string}   [props.country]  ISO-3166 alpha-2 country to scope suggest requests to (`schema.country`).
 * @param {boolean}  [props.disabled] whether the control is disabled (D11).
 * @param {Function} props.onChange   change handler — called with the serialized record, or `''`.
 * @return {JSX.Element} the picker.
 */
export default function LocationPickerField( { value, country, disabled = false, onChange } ) {
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
	}, [ search, country ] );

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

	return (
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
	);
}
