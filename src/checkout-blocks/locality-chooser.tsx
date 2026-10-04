/**
 * The Checkout Blocks «locality» chooser (SP-11 C-1, #1087; operator decision D-1 A).
 *
 * A SEPARATE control next to the native address form — never an overlay on WooCommerce's City input.
 * The shopper searches the framework's location provider; choosing a locality persists it through the
 * existing location API and then writes the NATIVE City and State values. The native fields stay
 * editable and authoritative: hand-editing City (or changing the country) drops the selection.
 *
 * Hidden — and the native fields work alone — when the location layer is inactive, or when the
 * shipping country is one the provider chain does not serve at the settlement level.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useId, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import type { KeyboardEvent } from 'react';
import { isSelectionStale } from './invalidation';
import { resolveNativeAddress } from './mapping';
import { forgetSelection, selectRecord, suggest, SuggestUnavailableError } from './rest';
import type { ChainEntry, LocationConfig, Selection, Suggestion, WcAddress } from './types';
import {
	readCountryStates,
	readShippingAddress,
	refreshCart,
	writeNativeLocality,
} from './wc-stores';

const MIN_QUERY_LENGTH = 2;
const SEARCH_DELAY_MS = 250;

type Status = 'idle' | 'searching' | 'applying';

/** The chain entry standing at the settlement level, when the server kept one the shopper PICKED. */
export function hydratedSettlement( config: LocationConfig ): ChainEntry | null {
	// An implicit record is the store's own default (a fixed default locality, GeoIP) — never a pick.
	if ( config.implicit ) {
		return null;
	}

	const chain = config.chain;
	const entry = Array.isArray( chain ) ? undefined : chain.settlement;

	if ( entry ) {
		return entry;
	}

	return config.current && config.current.level === 'settlement' ? config.current : null;
}

/** Whether the provider chain serves settlement suggestions for `country`. */
export function isCountrySupported( config: LocationConfig, country: string ): boolean {
	return country !== '' && config.countries.includes( country ) && config.levels[ country ]?.settlement === true;
}

export interface LocalityChooserProps {
	config: LocationConfig;
}

export function LocalityChooser( { config }: LocalityChooserProps ): JSX.Element | null {
	const address: WcAddress = useSelect( ( registrySelect ) => readShippingAddress( registrySelect ), [] );
	const country = address.country.toUpperCase();
	const supported = isCountrySupported( config, country );

	const inputId = useId();
	const listId = `${ inputId }-list`;

	const [ query, setQuery ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState< Suggestion[] >( [] );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ message, setMessage ] = useState( '' );
	const [ selection, setSelection ] = useState< Selection | null >( () => {
		const entry = hydratedSettlement( config );
		const current = readShippingAddress();

		// Hydrate (guest and logged in alike) only when the native address still carries a city the
		// saved locality can stand behind; an empty city has nothing for the selection to describe.
		return entry && current.city.trim() !== ''
			? { key: entry.key, city: current.city, country: current.country.toUpperCase() }
			: null;
	} );

	const sequence = useRef( 0 );
	const abort = useRef< AbortController | null >( null );
	const applying = useRef( false );

	const i18n = config.i18n;

	// Manual-edit invalidation: the native address no longer says what the selection wrote.
	useEffect( () => {
		if ( ! selection || applying.current || ! isSelectionStale( selection, address ) ) {
			return;
		}

		setSelection( null );
		setMessage( '' );
		void forgetSelection( config ).then( ( forgotten ) => {
			if ( forgotten ) {
				refreshCart();
			}
		} );
	}, [ selection, address, config ] );

	// Debounced, sequence-guarded search.
	useEffect( () => {
		abort.current?.abort();

		const term = query.trim();

		if ( ! supported || term.length < MIN_QUERY_LENGTH || applying.current ) {
			setSuggestions( [] );
			setStatus( ( previous ) => ( previous === 'applying' ? previous : 'idle' ) );

			return undefined;
		}

		const ticket = ++sequence.current;
		const controller = new AbortController();
		abort.current = controller;

		const timer = window.setTimeout( () => {
			setStatus( 'searching' );
			setMessage( '' );

			suggest( config, term, country, controller.signal )
				.then( ( found ) => {
					if ( ticket !== sequence.current ) {
						return;
					}

					setSuggestions( found );
					setActive( found.length > 0 ? 0 : -1 );
					setOpen( true );
					setStatus( 'idle' );

					if ( found.length === 0 ) {
						setMessage( i18n.noResults ?? '' );
					}
				} )
				.catch( ( error: unknown ) => {
					if ( ticket !== sequence.current || ( error as { name?: string } ).name === 'AbortError' ) {
						return;
					}

					setSuggestions( [] );
					setStatus( 'idle' );
					setMessage( error instanceof SuggestUnavailableError ? i18n.unavailable ?? '' : '' );
				} );
		}, SEARCH_DELAY_MS );

		return () => window.clearTimeout( timer );
	}, [ query, supported, country, config, i18n ] );

	const choose = useCallback(
		async ( suggestion: Suggestion ) => {
			if ( applying.current ) {
				return;
			}

			applying.current = true;
			abort.current?.abort();
			sequence.current++;
			setOpen( false );
			setSuggestions( [] );
			setStatus( 'applying' );
			setMessage( '' );

			try {
				const saved = await selectRecord( config, suggestion.record );

				if ( ! saved.ok ) {
					setMessage( saved.reason === 'cancelled' && saved.message ? saved.message : i18n.notPersisted ?? '' );

					return;
				}

				// The shopper changed the country while the write was in flight: the record belongs to
				// a destination the address no longer names.
				const current = readShippingAddress();

				if ( current.country.toUpperCase() !== suggestion.record.country.toUpperCase() ) {
					return;
				}

				const patch = resolveNativeAddress( suggestion.record, {
					states: readCountryStates( current.country ),
					regionFieldRemoved: config.regionFieldRemoved,
				} );

				if ( patch.city === '' ) {
					return;
				}

				// The selection is recorded BEFORE the address changes, so the write below is never
				// mistaken for a hand edit.
				setSelection( { key: suggestion.key, city: patch.city, country: current.country.toUpperCase() } );
				setQuery( '' );
				writeNativeLocality( patch.city, patch.state );

				if ( ! patch.stateMatched ) {
					setMessage( i18n.regionNotSet ?? '' );
				}
			} finally {
				applying.current = false;
				setStatus( 'idle' );
			}
		},
		[ config, i18n ]
	);

	const clear = useCallback( () => {
		setSelection( null );
		setQuery( '' );
		setMessage( '' );
		void forgetSelection( config ).then( ( forgotten ) => {
			if ( forgotten ) {
				refreshCart();
			}
		} );
	}, [ config ] );

	if ( ! supported ) {
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
			void choose( suggestions[ active ] );
		} else if ( event.key === 'Escape' ) {
			setOpen( false );
		}
	};

	const unresolvedHint =
		! selection && config.savedCityUnresolved && config.savedCityUnresolved === address.city
			? i18n.pickFromSuggestions ?? ''
			: '';
	const helper = status === 'searching' ? i18n.searching ?? '' : message || unresolvedHint || i18n.hint || '';
	const showList = open && suggestions.length > 0;

	return (
		<div className="woodev-locality-chooser" data-address-type="shipping">
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
					aria-busy={ status !== 'idle' }
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
							void choose( suggestion );
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
		</div>
	);
}
