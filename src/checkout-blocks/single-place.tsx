/**
 * City mode, ONE listed place (range-shipping city mode spec §4.2): there is nothing to choose, so
 * the place is written into the delivery address instead of asked for.
 *
 * - Field shown: the native City is filled with the place and locked (read-only).
 * - Field hidden (`hideSettlement`): the City row — and the State row once it holds the place's
 *   region — is hidden by the `woodev-field--hidden` class. The address STORE carries the values:
 *   Blocks submits the cart store's address, never the DOM, so a row hidden by class posts exactly
 *   what a visible one would. Nothing here relies on a hidden field being sent.
 *
 * The country needs no write: the place is looked up by the address's own country, so it is the
 * listed one by construction.
 *
 * Renders nothing; it is the chooser's replacement while one place is listed.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { publishSettlementScope } from './address-scope';
import { namesListedPlace } from './city-mode';
import { resolveNativeAddress } from './mapping';
import type { AllowedPlace, LocationConfig } from './types';
import { isDeliveryAddressAuthoritative, readCountryStates, readDeliveryAddress, writeDeliveryLocality } from './wc-stores';

const HIDDEN_CLASS = 'woodev-field--hidden';

/** The row Blocks wraps a native address field in. */
function rowOf( input: HTMLElement | null, key: 'city' | 'state' ): HTMLElement | null {
	return input?.closest< HTMLElement >( `.wc-block-components-address-form__${ key }` ) ?? input?.parentElement ?? null;
}

export interface SinglePlaceProps {
	config: LocationConfig;
	place: AllowedPlace;
	billingOnly: boolean;
}

export function SinglePlace( { config, place, billingOnly }: SinglePlaceProps ): null {
	const address = useSelect( ( registrySelect ) => readDeliveryAddress( billingOnly, registrySelect ), [ billingOnly ] );
	const authoritative: boolean = useSelect(
		( registrySelect ) => isDeliveryAddressAuthoritative( billingOnly, registrySelect ),
		[ billingOnly ]
	);
	const hide = config.hideSettlement === true;
	const regionFieldRemoved = config.regionFieldRemoved === true;
	const patch = resolveNativeAddress( place.record, {
		states: readCountryStates( address.country ),
		regionFieldRemoved,
		cityValue: place.value,
	} );
	// The state the place stands for — `null` when there is none to write (no region field, or no
	// single option matches: the shopper picks it).
	const state = patch.state !== null && patch.stateMatched && patch.state !== '' ? patch.state : null;

	// The address suggestions search inside the place.
	useEffect( () => {
		publishSettlementScope( place.key );

		return () => publishSettlementScope( null );
	}, [ place.key ] );

	// Write the place into the address — once per state of the form, never repeating itself.
	const lastSeen = useRef( '' );

	useEffect( () => {
		const seen = `${ address.city }|${ address.state }|${ address.country }`;

		// WooCommerce's empty pre-load defaults are not the shopper's address.
		if ( ! authoritative || seen === lastSeen.current ) {
			return;
		}

		lastSeen.current = seen;

		const cityOk = address.city === place.value;
		const stateMissing = state !== null && address.state === '';

		if ( cityOk && ! stateMissing ) {
			return;
		}

		// A saved city that is not the place: the street and postcode prefilled from it go too.
		const rejected = address.city.trim() !== '' && ! namesListedPlace( [ place ], address.city );

		// A different city: the state goes with it (blank when the place names none) — unless the
		// shop has no region field, which is not ours to touch.
		const nextState = cityOk || ! regionFieldRemoved ? ( cityOk ? state : state ?? '' ) : null;

		writeDeliveryLocality( place.value, nextState, billingOnly, rejected );
	}, [ address.city, address.state, address.country, authoritative, place, state, regionFieldRemoved, billingOnly ] );

	// Hide, or lock, the native fields. React may replace them at any time: re-apply on every change.
	const stateKnown = regionFieldRemoved || address.state !== '';

	useEffect( () => {
		const prefix = billingOnly ? 'billing' : 'shipping';
		const touched: Array< () => void > = [];

		const apply = (): void => {
			const city = document.getElementById( `${ prefix }-city` ) as HTMLInputElement | null;

			if ( city && hide ) {
				const row = rowOf( city, 'city' );
				row?.classList.add( HIDDEN_CLASS );
				touched.push( () => row?.classList.remove( HIDDEN_CLASS ) );
			} else if ( city && ! city.readOnly ) {
				city.readOnly = true;
				touched.push( () => {
					city.readOnly = false;
				} );
			}

			const stateField = document.getElementById( `${ prefix }-state` );

			if ( stateField && hide && stateKnown ) {
				const row = rowOf( stateField, 'state' );
				row?.classList.add( HIDDEN_CLASS );
				touched.push( () => row?.classList.remove( HIDDEN_CLASS ) );
			}
		};

		apply();

		const observer = new MutationObserver( apply );
		observer.observe( document.body, { childList: true, subtree: true } );

		return () => {
			observer.disconnect();
			touched.forEach( ( undo ) => undo() );
		};
	}, [ billingOnly, hide, stateKnown ] );

	return null;
}
