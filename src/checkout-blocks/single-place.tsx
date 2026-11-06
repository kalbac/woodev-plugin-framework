/**
 * City mode, ONE listed place (range-shipping city mode spec §4.2): there is nothing to choose, so
 * the place is written into the delivery address instead of asked for, and shown as plain text —
 * the way WooCommerce shows the country when a shop sells to just one.
 *
 * - The native City row is hidden; a line of text carries the place in its stead (nothing at all
 *   when `hideSettlement` is on).
 * - The native State row is hidden once it holds the place's region, flag or not.
 *
 * The address STORE carries the values: Blocks submits the cart store's address, never the DOM, so
 * a row hidden by class posts exactly what a visible one would. Nothing here relies on a hidden
 * field being sent.
 *
 * The country needs no write: the place is looked up by the address's own country, so it is the
 * listed one by construction.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { publishSettlementScope } from './address-scope';
import { namesListedPlace } from './city-mode';
import { useHiddenNativeRows } from './native-rows';
import { resolveNativeAddress } from './mapping';
import type { AllowedPlace, LocationConfig } from './types';
import { isDeliveryAddressAuthoritative, readCountryStates, readDeliveryAddress, writeDeliveryLocality } from './wc-stores';

export interface SinglePlaceProps {
	config: LocationConfig;
	place: AllowedPlace;
	billingOnly: boolean;
}

export function SinglePlace( { config, place, billingOnly }: SinglePlaceProps ): JSX.Element | null {
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
		// A state the place maps to unambiguously is written when it is missing OR differs (a saved
		// Москва with a Tula State); with no such mapping the shopper's own State stands.
		const stateWrong = state !== null && address.state !== state;

		if ( cityOk && ! stateWrong ) {
			return;
		}

		// A saved city that is not the place: the street and postcode prefilled from it go too.
		const rejected = address.city.trim() !== '' && ! namesListedPlace( [ place ], address.city );

		// A different city: the state goes with it (blank when the place names none) — unless the
		// shop has no region field, which is not ours to touch.
		const nextState = cityOk || ! regionFieldRemoved ? ( cityOk ? state : state ?? '' ) : null;

		writeDeliveryLocality( place.value, nextState, billingOnly, rejected );
	}, [ address.city, address.state, address.country, authoritative, place, state, regionFieldRemoved, billingOnly ] );

	// The State row goes only once it carries what the place stands for: the mapped value, or —
	// where no single option maps — whatever the shopper has chosen.
	const stateKnown = regionFieldRemoved || ( state !== null ? address.state === state : address.state !== '' );
	const cityLabel = useHiddenNativeRows( billingOnly, { city: true, state: stateKnown } );

	if ( hide ) {
		return null;
	}

	return (
		<div className="woodev-city-mode-place">
			<span className="woodev-city-mode-place__label">{ cityLabel || config.i18n.label }</span>
			<strong className="woodev-city-mode-place__value">{ place.value }</strong>
		</div>
	);
}
