/**
 * Woodev UI-kit — generalized location picker (#960, increment I4a of #710).
 *
 * The trigger-button + `Dropdown`/`SearchControl` popover shell of the admin
 * default-locality picker (`location-picker-field.tsx`, #376), with everything that
 * tied it to the settings page taken out and turned into props, so a second surface
 * (the order wizard's address step, #710) can reuse it instead of copying it:
 *
 * - `level` — `region` | `settlement` | `address`, the level a search runs at
 *   (was hard-wired to `settlement`);
 * - `within` — the locality KEY of an already-picked parent, sent as the suggest
 *   route's `within` param so an address search stays inside its settlement;
 * - `restRoot` / `nonce` / `endpoint` — where to ask. The picker no longer reads
 *   `window.woodevSettings` (a settings-page global): the host hands it the
 *   `woodev/v1` namespace root, and — by default — the picker calls the STATELESS
 *   public `GET /location/suggest`, never `/location/select` or `/location/forget`
 *   (those write the calling admin's own customer-location store; #710 D1 forbids
 *   them from admin). The settings page passes its admin-only
 *   `/location/default-locality/suggest` endpoint instead;
 * - `params` — extra query params for an endpoint that takes more (the admin
 *   route's `provider` override, #380).
 *
 * POSTCODE FILL: a picked record carries its own components and, when the provider
 * supplied one, a `postcode`. {@see fillFromRecord} derives from it the text every
 * address field at or above the picked level should carry — the pure port of the
 * checkout's `location-cascade.js` `backwardsFill()`/`fieldValueFor()` (that file is
 * raw JS served to the browser as-is and cannot be imported here) — and `onChange`
 * hands it over next to the suggestion. What the host writes where is its business.
 * One deliberate difference from the checkout: its per-level provider-ownership
 * check (a foreign provider never overwrites an ancestor another provider owns)
 * needs the checkout's `owners` config map, which an admin surface does not have, so
 * every ancestor the record carries is filled.
 *
 * SERVER CAVEAT (not this component's to fix): `within` is resolved by the
 * controller against the CALLER'S customer-location chain (`build_scope()`), which
 * an admin never populates from the wizard — an admin request therefore reports
 * `within_status: unknown_key` and the search runs country-wide. Sending the param is
 * still the correct contract; an admin variant of the scope resolution is the
 * server-side follow-up (see the increment report).
 *
 * EMPTY / ERROR STATES (a picker must never silently look like a working empty
 * field): fewer than `MIN_QUERY_LENGTH` characters → a hint, never a request; a
 * request in flight → a spinner row; a completed search with zero suggestions →
 * "Ничего не найдено" (this also covers "no provider configured": the route
 * degrades both to the same `{ suggestions: [] }`); a failed request → a distinct
 * error row; `broken` → the trigger itself shows a distinct "повреждено" state.
 *
 * @package woodev-plugin-framework
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Dropdown, SearchControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ChevronIcon, CheckFilledIcon } from './icons';

/** The levels a search can run at, outermost first (`Location_Record::LEVELS`). */
export const LOCATION_LEVELS = [ 'region', 'settlement', 'address' ] as const;

export type LocationLevel = typeof LOCATION_LEVELS[ number ];

/** A `{ name, type }` component of a record (`region`, `settlement`, `street`…). */
export interface LocationComponent {
	name?: string;
	type?: string;
}

/**
 * The wire shape of `Location_Record::to_array()` — only what the client reads; the
 * record travels whole, untouched, so unknown keys are allowed.
 */
export interface LocationRecord {
	key: string;
	provider_id?: string;
	level?: LocationLevel;
	country?: string;
	label?: string;
	region?: LocationComponent;
	settlement?: LocationComponent;
	street?: LocationComponent;
	house?: string;
	block?: string;
	flat?: string;
	postcode?: string;
	[ extra: string ]: unknown;
}

/** One entry of the suggest route's `suggestions` array (`to_response_records()`). */
export interface LocationSuggestion {
	key: string;
	label: string;
	level: LocationLevel;
	record: LocationRecord;
}

/** What {@see fillFromRecord} derives — only the keys the record can actually fill. */
export interface LocationFill {
	region?: string;
	settlement?: string;
	address?: string;
	postcode?: string;
}

/** The picked location as the trigger shows it. */
export interface LocationSelection {
	key: string;
	label: string;
}

export interface LocationPickerProps {
	/** The picked location, or `null` for none (the placeholder shows). */
	value: LocationSelection | null;
	/** The level every search of this picker runs at. */
	level: LocationLevel;
	/** ISO-3166 alpha-2 country to scope searches to; `''` lets the server pick the store default. */
	country: string;
	/** The `woodev/v1` namespace root, no trailing slash (e.g. `https://…/wp-json/woodev/v1`). */
	restRoot: string;
	/** `wp_rest` nonce, sent as `X-WP-Nonce` when given. */
	nonce?: string;
	/** Route under `restRoot`. Default: the stateless public `/location/suggest`. */
	endpoint?: string;
	/** Locality key of the already-picked parent this search is scoped to. */
	within?: string;
	/** Extra query params for endpoints that take more (the admin route's `provider`). */
	params?: Record<string, string>;
	disabled?: boolean;
	/** A value is stored but unreadable — the trigger shows a distinct state, never a bare placeholder. */
	broken?: boolean;
	/** Trigger text while nothing is picked. */
	placeholder?: string;
	/** Called with the picked suggestion and the field text {@see fillFromRecord} derives from it. */
	onChange: ( suggestion: LocationSuggestion, fill: LocationFill ) => void;
}

/** Debounce interval, in ms, before a typed query fires a request. */
const DEBOUNCE_MS = 300;

/** Minimum query length before a search is issued — mirrors the server's `Location_Controller::MIN_QUERY_LENGTH`. */
const MIN_QUERY_LENGTH = 2;

/** The stateless public suggest route. */
export const DEFAULT_ENDPOINT = '/location/suggest';

const isNonEmpty = ( text: unknown ): text is string => 'string' === typeof text && '' !== text.trim();

/**
 * `type + ' ' + name`, trimmed — the STREET part of an address value, where the type
 * is part of the name in ordinary use ("ул Тверская" reads as an address, "Тверская"
 * does not). Region and settlement values deliberately do not go through this.
 *
 * @param {LocationComponent|undefined} component a record component.
 * @return {string} display text, `''` for none.
 */
function formatComponent( component: LocationComponent | undefined ): string {
	const type = component && component.type ? String( component.type ) : '';
	const name = component && component.name ? String( component.name ) : '';

	return ( type + ' ' + name ).trim();
}

/**
 * The text a field of `level` should CARRY — derived from the record's own components,
 * never its `label` (a provider's label carries ancestors, «Московская обл., г Жуковский»,
 * which would repeat the region field and match no carrier dictionary).
 *
 * `region`/`settlement` → the component's bare `name`, type dropped on purpose;
 * `address` → street (with its type) plus house and block, joined `', '`. Falls back to
 * the record's `label` only when that yields nothing at all — a field left blank right
 * after a pick reads as the pick having failed.
 *
 * @param {LocationRecord} record the picked record.
 * @param {LocationLevel}  level  the level the value is for.
 * @return {string} the field text.
 */
function valueForLevel( record: LocationRecord, level: LocationLevel ): string {
	const parts = 'address' === level
		? [ formatComponent( record.street ), record.house, record.block ]
		: [ record[ level ]?.name ];

	const value = parts
		.filter( isNonEmpty )
		.map( ( part ) => String( part ).trim() )
		.join( ', ' );

	return value || ( isNonEmpty( record.label ) ? record.label : '' );
}

/**
 * Derives the address-field text a picked record fills — the checkout cascade's
 * "backwards fill" (`location-cascade.js` `backwardsFill()`), pure. Every level at or
 * ABOVE the record's own level is filled (an ancestor only when the record carries that
 * component; the record's own level always, label as the fallback), a level below it
 * never — picking a settlement never touches the address. `postcode` is filled whenever
 * the record carries one, whatever its level: a settlement's postcode is the fill the
 * order form wants after «НП», and a street's is more precise still.
 *
 * @param {LocationRecord} record the picked record.
 * @return {LocationFill} text per field, only for the keys the record can fill.
 */
export function fillFromRecord( record: LocationRecord ): LocationFill {
	const own = record.level ? LOCATION_LEVELS.indexOf( record.level ) : -1;
	const fill: LocationFill = {};

	LOCATION_LEVELS.forEach( ( level, i ) => {
		if ( own < 0 || i > own ) {
			return;
		}

		if ( i < own && ! record[ level ] ) {
			return; // An ancestor the record does not carry is left alone.
		}

		const value = valueForLevel( record, level );

		if ( value ) {
			fill[ level ] = value;
		}
	} );

	if ( isNonEmpty( record.postcode ) ) {
		fill.postcode = record.postcode.trim();
	}

	return fill;
}

type SearchStatus = 'idle' | 'loading' | 'error';

interface SuggestResponse {
	suggestions?: LocationSuggestion[];
}

/**
 * @param {LocationPickerProps} props component props.
 * @return {JSX.Element} the picker.
 */
export default function LocationPicker( {
	value,
	level,
	country,
	restRoot,
	nonce = '',
	endpoint = DEFAULT_ENDPOINT,
	within = '',
	params,
	disabled = false,
	broken = false,
	placeholder,
	onChange,
}: LocationPickerProps ) {
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState<SearchStatus>( 'idle' );
	const [ results, setResults ] = useState<LocationSuggestion[]>( [] );
	const [ searched, setSearched ] = useState( false );
	const generationRef = useRef( 0 );
	const triggerRef = useRef<HTMLButtonElement>( null );

	// A stable dependency for the extra params — a fresh object literal per render
	// must not re-fire the search.
	const paramsKey = JSON.stringify( params || {} );

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
			const query_params = new URLSearchParams( {
				q: query,
				level,
				country: country || '',
				...( within ? { within } : {} ),
				...( JSON.parse( paramsKey ) as Record<string, string> ),
			} );

			apiFetch<SuggestResponse>( {
				url: `${ restRoot }${ endpoint }?${ query_params.toString() }`,
				method: 'GET',
				headers: nonce ? { 'X-WP-Nonce': nonce } : {},
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

		return () => {
			clearTimeout( timer );
		};

		// `provider` (via `params`) is in the deps (issue #380): switching the settings
		// page's provider select must re-issue the CURRENT search against the newly
		// selected provider immediately, the same way a `country` change does — not wait
		// for the next keystroke. `level` and `within` follow the same rule.
	}, [ search, level, country, within, restRoot, endpoint, nonce, paramsKey ] );

	const choose = ( entry: LocationSuggestion, close: () => void ) => {
		onChange( entry, fillFromRecord( entry.record ) );
		setSearch( '' );
		close();
	};

	const triggerLabel = ( () => {
		if ( broken ) {
			return __( 'Некорректное сохранённое значение — выберите заново', 'woodev-plugin-framework' );
		}
		if ( value ) {
			return value.label;
		}
		return placeholder || __( 'Выберите локацию…', 'woodev-plugin-framework' );
	} )();

	const isPlaceholder = ! broken && ! value;

	return (
		<Dropdown
			className="woodev-select woodev-location-picker"
			contentClassName="woodev-select__popover"
			popoverProps={ { placement: 'bottom-start', offset: 4 } }
			onToggle={ ( open: boolean ) => {
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
						+ ( broken ? ' is-broken' : '' )
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
							+ ( broken ? ' is-broken' : '' )
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
						{ 'idle' === status && results.map( ( entry ) => {
							const isSelected = !! value && value.key === entry.key;

							return (
								<button
									key={ entry.key }
									type="button"
									role="option"
									aria-selected={ isSelected }
									className={ 'woodev-select__option' + ( isSelected ? ' is-selected' : '' ) }
									onClick={ () => choose( entry, onClose ) }
								>
									<span className="woodev-select__check">
										{ isSelected && <CheckFilledIcon /> }
									</span>
									<span className="woodev-select__option-label">{ entry.label }</span>
								</button>
							);
						} ) }
					</div>
				</div>
			) }
		/>
	);
}
