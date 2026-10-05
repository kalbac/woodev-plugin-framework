/**
 * The Checkout Blocks «locality» chooser (SP-11 C-1, #1087; operator decision D-1 A).
 *
 * A SEPARATE control next to the native address form — never an overlay on WooCommerce's City input.
 * The shopper searches the framework's location provider; choosing a locality persists it through the
 * existing location API and then writes the NATIVE City and State values. The native fields stay
 * editable and authoritative: hand-editing City (or changing the country) drops the selection.
 *
 * The invariant: the saved chain and the native City/State/Country never disagree in an order that
 * gets placed, and a server reply never overwrites what the shopper typed after the request began.
 * Every chain write goes through the page's one serialized queue ({@link sharedChainSync}) that
 * blocks Place Order while it drains — a pick included, until the rates for it are recalculated; the
 * native address is written only by a reply that is still the latest intent AND finds the form as
 * the shopper left it. «Disagree» is one contract with the server (`invalidation.ts`).
 *
 * Hidden — and the native fields work alone — when the location layer is inactive, or when the
 * shipping country is one the provider chain does not serve at the settlement level.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useId, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import type { KeyboardEvent } from 'react';
import { resetSharedChainSync, sharedChainSync } from './chain-sync';
import type { ChainSync } from './chain-sync';
import { hasLocalityMoved, isSelectionStale, judgeSavedRecord } from './invalidation';
import { recordCity, recordCityComponent, resolveNativeAddress } from './mapping';
import type { CountryStates } from './mapping';
import { forgetSelection, selectRecord, suggest, SuggestUnavailableError } from './rest';
import type { LocationConfig, Selection, Suggestion, WcAddress } from './types';
import {
	cartNeedsShipping,
	gateCheckout,
	isDeliveryAddressAuthoritative,
	readDeliveryAddress,
	readCountryStates,
	refreshRates,
	writeDeliveryLocality,
} from './wc-stores';
import { wcRuntime } from './wc-runtime';

const MIN_QUERY_LENGTH = 2;
const SEARCH_DELAY_MS = 250;
const FORGET_RETRY_DELAY_MS = 1000;

/**
 * The selection as the chooser last left it, kept across a remount. WooCommerce unmounts the
 * shipping address block (local pickup, a collapsed address card) and mounts it again with the SAME
 * page-load config, which by then describes a chain the shopper may have replaced or cleared.
 * `undefined` until a chooser has mounted on this page.
 */
let remembered: Selection | null | undefined;

/** Forgets the remount memory and the page's queue. Tests only. */
export function resetLocalityMemory(): void {
	remembered = undefined;
	resetSharedChainSync();
}

export interface InitialSelection {
	selection: Selection | null;
	/** A saved locality the address does NOT name: provenance for another place, which is cleared. */
	orphaned: boolean;
	/** The server's locality is not judged yet — nothing is claimed, nothing is cleared. */
	pending: boolean;
}

/**
 * What the chooser may start from.
 *
 * A saved locality is claimed ONLY while the native address names the same place — the record's own
 * settlement, region and country, never «the address has some city» — and cleared only when the
 * address names ANOTHER one. An address that cannot disagree yet (not loaded, or no City) leaves it
 * `pending` ({@link judgeSavedRecord}).
 *
 * `context` defaults to the live state list of the address's country and a loaded address.
 */
export function initialSelection(
	config: LocationConfig,
	address: WcAddress,
	context: { states?: CountryStates; authoritative?: boolean } = {}
): InitialSelection {
	if ( remembered !== undefined ) {
		if ( ! remembered ) {
			return { selection: null, orphaned: false, pending: false };
		}

		return isSelectionStale( remembered, address )
			? { selection: null, orphaned: true, pending: false }
			: { selection: remembered, orphaned: false, pending: false };
	}

	// The server publishes only a record the customer EXPLICITLY chose: the store's own default
	// (a fixed default locality, GeoIP) is never a pick.
	const record = config.selection?.record;

	if ( ! record ) {
		return { selection: null, orphaned: false, pending: false };
	}

	const verdict = judgeSavedRecord( record, address, {
		states: context.states ?? readCountryStates( address.country ),
		regionFieldRemoved: config.regionFieldRemoved === true,
		authoritative: context.authoritative ?? true,
	} );

	return {
		selection: verdict.status === 'claimed' ? verdict.selection : null,
		orphaned: verdict.status === 'orphaned',
		pending: verdict.status === 'pending',
	};
}

/** Whether the provider chain serves settlement suggestions for `country`. */
export function isCountrySupported( config: LocationConfig, country: string ): boolean {
	return country !== '' && config.countries.includes( country ) && config.levels[ country ]?.settlement === true;
}

export interface LocalityChooserProps {
	config: LocationConfig;
	/** The checkout address column this chooser serves. */
	addressTarget?: 'shipping' | 'billing';
	/** Pause before a failed `/forget` is tried once more. A test seam. */
	retryDelayMs?: number;
}

export function LocalityChooser( {
	config,
	addressTarget = 'shipping',
	retryDelayMs = FORGET_RETRY_DELAY_MS,
}: LocalityChooserProps ): JSX.Element | null {
	const billingOnly = addressTarget === 'billing';
	const getSetting = wcRuntime()?.wcSettings?.getSetting;
	const coreBillingOnly = typeof getSetting === 'function' && getSetting< boolean >( 'forcedBillingAddress', false ) === true;
	const address: WcAddress = useSelect( ( registrySelect ) => readDeliveryAddress( billingOnly, registrySelect ), [ billingOnly ] );
	const needsShipping = useSelect( ( registrySelect ) => cartNeedsShipping( registrySelect ), [] );
	const authoritative: boolean = useSelect(
		( registrySelect ) => isDeliveryAddressAuthoritative( billingOnly, registrySelect ),
		[ billingOnly ]
	);
	const country = address.country.toUpperCase();
	const supported = isCountrySupported( config, country );

	const inputId = useId();
	const listId = `${ inputId }-list`;

	const boot = useRef< InitialSelection | null >( null );

	if ( boot.current === null ) {
		boot.current = initialSelection( config, readDeliveryAddress( billingOnly ), {
			authoritative: isDeliveryAddressAuthoritative( billingOnly ),
		} );
	}

	const [ query, setQuery ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState< Suggestion[] >( [] );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ searching, setSearching ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ selection, setSelection ] = useState< Selection | null >( boot.current.selection );
	/** Generation of the pick being saved; 0 when none is. */
	const [ applying, setApplying ] = useState( 0 );
	const [ syncFailed, setSyncFailed ] = useState( false );
	const modeAgrees = config.billingOnly === billingOnly && config.billingOnly === coreBillingOnly;

	const sequence = useRef( 0 );
	const abort = useRef< AbortController | null >( null );
	const mounted = useRef( false );
	/**
	 * The selection, readable SYNCHRONOUSLY. The state above lags a store notification by a render
	 * (the address arrives through `useSyncExternalStore`, which re-renders at once), so judging
	 * staleness from the state would mistake the chooser's own native write for a hand edit.
	 */
	const held = useRef< Selection | null >( boot.current.selection );
	/**
	 * The locality the server holds has not been judged against the address yet (the cart is still
	 * loading, or the City is blank): it is neither claimed nor cleared until it can be.
	 */
	const undecided = useRef( boot.current.pending );
	const sync = useRef< ChainSync | null >( null );

	if ( sync.current === null ) {
		// The page's ONE queue: a remount joins the queue the previous mount left, cleanup included.
		sync.current = sharedChainSync( {
			select: ( record ) => selectRecord( config, record ),
			forget: () => forgetSelection( config ),
			refresh: () => refreshRates( billingOnly ),
			gate: gateCheckout,
			retryDelayMs,
		} );
	}

	const i18n = config.i18n;

	const commit = useCallback( ( next: Selection | null ) => {
		held.current = next;
		remembered = next;
		undecided.current = false;

		if ( mounted.current ) {
			setSelection( next );
		}
	}, [] );

	/** Erases the saved chain, through the queue; a failure that outlives its retry is shown. */
	const forget = useCallback( () => {
		setSyncFailed( false );
		sync.current?.request( { kind: 'forget' }, ( outcome ) => {
			if ( outcome.status === 'failed' && mounted.current ) {
				setSyncFailed( true );
			}
		} );
	}, [] );

	useEffect( () => {
		mounted.current = true;

		// An undecided locality stays unremembered: a remount judges the server's record again.
		if ( ! undecided.current ) {
			remembered = held.current;
		}

		// The server holds a locality the native address does not name: clear that provenance.
		if ( boot.current?.orphaned ) {
			boot.current.orphaned = false;
			forget();
		}

		return () => {
			mounted.current = false;

			// A pick still being saved can no longer be written into the address form: it is erased.
			if ( sync.current?.abandon() ) {
				held.current = null;
				remembered = null;
			}
		};
	}, [ forget ] );

	// Deferred hydration: judge the server's locality once the address can agree or disagree.
	useEffect( () => {
		const record = config.selection?.record;

		if ( ! undecided.current || ! record ) {
			return;
		}

		const current = readDeliveryAddress( billingOnly );
		const verdict = judgeSavedRecord( record, current, {
			states: readCountryStates( current.country ),
			regionFieldRemoved: config.regionFieldRemoved === true,
			authoritative: isDeliveryAddressAuthoritative( billingOnly ),
		} );

		if ( verdict.status === 'claimed' ) {
			commit( verdict.selection );
		} else if ( verdict.status === 'orphaned' ) {
			commit( null );
			forget();
		}
	}, [ address, authoritative, config, commit, forget ] );

	// Manual-edit invalidation: the native address no longer says what the selection stands for.
	useEffect( () => {
		const current = held.current;

		if ( ! current || ! isSelectionStale( current, readDeliveryAddress( billingOnly ) ) ) {
			return;
		}

		commit( null );
		setMessage( '' );
		forget();
	}, [ selection, address, commit, forget ] );

	// Debounced, sequence-guarded search.
	useEffect( () => {
		abort.current?.abort();

		const term = query.trim();

		if ( ! supported || term.length < MIN_QUERY_LENGTH ) {
			setSuggestions( [] );
			setSearching( false );

			return undefined;
		}

		const ticket = ++sequence.current;
		const controller = new AbortController();
		abort.current = controller;

		const timer = window.setTimeout( () => {
			setSearching( true );
			setMessage( '' );

			suggest( config, term, country, controller.signal )
				.then( ( found ) => {
					if ( ticket !== sequence.current ) {
						return;
					}

					setSuggestions( found );
					setActive( found.length > 0 ? 0 : -1 );
					setOpen( true );
					setSearching( false );

					if ( found.length === 0 ) {
						setMessage( i18n.noResults ?? '' );
					}
				} )
				.catch( ( error: unknown ) => {
					if ( ticket !== sequence.current || ( error as { name?: string } ).name === 'AbortError' ) {
						return;
					}

					setSuggestions( [] );
					setSearching( false );
					setMessage( error instanceof SuggestUnavailableError ? i18n.unavailable ?? '' : '' );
				} );
		}, SEARCH_DELAY_MS );

		return () => {
			window.clearTimeout( timer );
			controller.abort();
		};
	}, [ query, supported, country, config, i18n ] );

	const choose = useCallback(
		( suggestion: Suggestion ) => {
			const record = suggestion.record;
			// The native locality as the shopper left it when they picked: the reply is only written
			// into the form if this is still what the form says.
			const origin = readDeliveryAddress( billingOnly );

			abort.current?.abort();
			sequence.current++;
			setOpen( false );
			setSuggestions( [] );
			setSearching( false );
			setMessage( '' );
			setSyncFailed( false );

			// A record of another country, or one that names no city, has nothing to write.
			if ( recordCity( record ) === '' || origin.country.toUpperCase() !== record.country.toUpperCase() ) {
				return;
			}

			const ticket =
				sync.current?.request( { kind: 'select', record }, ( outcome ) => {
					if ( mounted.current ) {
						setApplying( ( pending ) => ( pending === ticket ? 0 : pending ) );
					}

					if ( outcome.status === 'superseded' ) {
						return undefined;
					}

					if ( outcome.status === 'refused' ) {
						if ( mounted.current ) {
							setMessage(
								outcome.reason === 'cancelled' && outcome.message
									? outcome.message
									: i18n.notPersisted ?? ''
							);
						}

						return undefined;
					}

					const current = readDeliveryAddress( billingOnly );

					// No answer (the record may have been saved), or an answer that arrived after the
					// shopper edited the address: their form stands, and the server must not be left
					// holding a locality it does not name.
					if ( outcome.status === 'failed' || ! mounted.current || hasLocalityMoved( origin, current ) ) {
						commit( null );

						if ( outcome.status === 'failed' && mounted.current ) {
							setMessage( i18n.notPersisted ?? '' );
						}

						forget();

						return undefined;
					}

					const patch = resolveNativeAddress( record, {
						states: readCountryStates( current.country ),
						regionFieldRemoved: config.regionFieldRemoved,
					} );

					// The selection is recorded BEFORE the address changes, so the write below is never
					// mistaken for a hand edit.
					commit( {
						key: suggestion.key,
						city: patch.city,
						cityType: recordCityComponent( record ).type,
						country: current.country.toUpperCase(),
						state: patch.state ? patch.state : null,
					} );
					setQuery( '' );

					if ( ! patch.stateMatched ) {
						setMessage( i18n.regionNotSet ?? '' );
					}

					writeDeliveryLocality( patch.city, patch.state, billingOnly );

					// Always recalculated before the checkout is let go: core pushes a changed address
					// only after its debounce (the old rates would stay orderable meanwhile), and an
					// unchanged one never.
					return true;
				} ) ?? 0;

			setApplying( ticket );
		},
		[ config, i18n, commit, forget ]
	);

	const clear = useCallback( () => {
		commit( null );
		setQuery( '' );
		setMessage( '' );
		forget();
	}, [ commit, forget ] );

	if ( ! modeAgrees || ! needsShipping || ! supported ) {
		return null;
	}

	const onKeyDown = ( event: KeyboardEvent< HTMLInputElement > ): void => {
		if ( event.key === 'ArrowDown' && suggestions.length > 0 ) {
			event.preventDefault();
			setOpen( true );
			setActive( ( index ) => ( index + 1 ) % suggestions.length );
		} else if ( event.key === 'ArrowUp' && suggestions.length > 0 ) {
			event.preventDefault();
			setActive( ( index ) => ( index <= 0 ? suggestions.length - 1 : index - 1 ) );
		} else if ( event.key === 'Enter' && open && active >= 0 && suggestions[ active ] ) {
			event.preventDefault();
			choose( suggestions[ active ] );
		} else if ( event.key === 'Escape' ) {
			setOpen( false );
		}
	};

	const unresolvedHint =
		! selection && config.savedCityUnresolved && config.savedCityUnresolved === address.city
			? i18n.pickFromSuggestions ?? ''
			: '';
	const failure = syncFailed ? i18n.syncFailed ?? '' : '';
	const helper = searching ? i18n.searching ?? '' : failure || message || unresolvedHint || i18n.hint || '';
	const showList = open && suggestions.length > 0;

	return (
		<div className="woodev-locality-chooser" data-address-type={ addressTarget }>
			<label className="woodev-locality-chooser__label" htmlFor={ inputId }>
				{ i18n.label }
			</label>
			<div className="woodev-locality-chooser__field">
				<input
					id={ inputId }
					className="woodev-locality-chooser__input"
					type="text"
					role="combobox"
					autoComplete="off"
					aria-autocomplete="list"
					aria-expanded={ showList }
					aria-controls={ listId }
					aria-activedescendant={ showList && active >= 0 ? `${ inputId }-opt-${ active }` : undefined }
					aria-busy={ searching || applying !== 0 }
					value={ selection && query === '' ? selection.city : query }
					onChange={ ( event ) => {
						// Typing over a chosen locality starts a new search; the old selection stays
						// until a new one replaces it or the native address is edited.
						setQuery( event.target.value );
						setOpen( true );
					} }
					onKeyDown={ onKeyDown }
					onBlur={ () => window.setTimeout( () => setOpen( false ), 120 ) }
				/>
				{ selection && (
					<button
						type="button"
						className="woodev-locality-chooser__clear"
						aria-label={ i18n.clear }
						onClick={ clear }
					>
						×
					</button>
				) }
			</div>
			<ul
				id={ listId }
				className="woodev-locality-chooser__list"
				role="listbox"
				aria-label={ i18n.listLabel }
				hidden={ ! showList }
			>
				{ suggestions.map( ( suggestion, index ) => (
					<li
						key={ suggestion.key }
						id={ `${ inputId }-opt-${ index }` }
						role="option"
						aria-selected={ index === active }
						className="woodev-locality-chooser__option"
						onMouseDown={ ( event ) => {
							// Before the input's blur closes the list.
							event.preventDefault();
							choose( suggestion );
						} }
						// Server-escaped by `to_response_records()` (`esc_html`) for direct display.
						// eslint-disable-next-line react/no-danger
						dangerouslySetInnerHTML={ { __html: suggestion.label } }
					/>
				) ) }
			</ul>
			<p className="woodev-locality-chooser__hint" aria-live="polite">
				{ helper }
			</p>
			{ syncFailed && (
				<button type="button" className="woodev-locality-chooser__retry" onClick={ forget }>
					{ i18n.retry }
				</button>
			) }
		</div>
	);
}
