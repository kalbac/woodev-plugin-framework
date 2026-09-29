/**
 * The checkout's address-field policy, as step ② «Адрес» uses it (#985).
 *
 * The rule itself lives in PHP — `Checkout_Field_Policy::address_rules()`, the object the storefront
 * checkout runs — and reaches the wizard through `GET …/orders/address-policy`. This file only
 * fetches it and reads it; it holds no copy of the rule. Until the server has answered (or when it
 * cannot — a failed request, WooCommerce absent) {@link FALLBACK_POLICY} stands in: the two fields
 * every carrier needs, a country and a city, and every field shown. The server judges the whole
 * payload again when the order is saved, so a stand-in never lets a wrong order through.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { fetchAddressPolicy } from './api';
import type { AddressFieldKey, AddressPolicy, AddressRule } from './types';

const KEYS: AddressFieldKey[] = [ 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ];

const optional = (): AddressRule => ( { required: false, hidden: false, removed: false } );

/** What step ② assumes before the server answers: a country and a city, every field shown. */
export const FALLBACK_POLICY: AddressPolicy = {
	country: { required: true, hidden: false, removed: false },
	state: optional(),
	city: { required: true, hidden: false, removed: false },
	address_1: optional(),
	address_2: optional(),
	postcode: optional(),
};

/**
 * Reads the route's `fields` into a full policy. `null` when the answer holds no rule (an empty list
 * or object, or nothing at all) — the caller then keeps the stand-in. The postcode is never
 * `required` here, whatever the route says (#999).
 *
 * @param {unknown} fields the response's `fields`.
 * @return {AddressPolicy|null} the policy.
 */
export function normalizeAddressPolicy( fields: unknown ): AddressPolicy | null {
	if ( ! fields || 'object' !== typeof fields || Array.isArray( fields ) ) {
		return null;
	}

	const raw = fields as Record<string, Partial<AddressRule> | undefined>;
	const policy = { ...FALLBACK_POLICY };
	let found = false;

	for ( const key of KEYS ) {
		const rule = raw[ key ];

		if ( rule && 'object' === typeof rule ) {
			policy[ key ] = { required: true === rule.required, hidden: true === rule.hidden, removed: true === rule.removed };
			found = true;
		}
	}

	// The wizard is for shop staff, who rarely know a postcode: it is never required here, whatever the
	// checkout's locale says (#999). Shown / hidden / removed still follow the shop's policy.
	policy.postcode = { ...policy.postcode, required: false };

	return found ? policy : null;
}

/** The rule of one field under a policy that may not have arrived yet. */
export function ruleOf( policy: AddressPolicy | null | undefined, key: AddressFieldKey ): AddressRule {
	return ( policy || FALLBACK_POLICY )[ key ];
}

/**
 * Asks the server for the rules of the current destination, once per country / tariff kind.
 *
 * `enabled` keeps the request off until step ② is on screen. A stand-in answer (`null` — nothing
 * came, or nothing usable) is remembered as asked but does not re-render: it changes nothing the
 * screen shows.
 *
 * @param {string}  country the delivery country.
 * @param {boolean} pickup  whether the chosen tariff is a pickup one (step ④'s answer).
 * @param {boolean} enabled whether the rules are needed now.
 * @return {AddressPolicy|null} the rules for this destination, or `null` while unknown.
 */
export function useAddressPolicy( country: string, pickup: boolean, enabled: boolean ): AddressPolicy | null {
	const code = country.trim().toUpperCase();
	const key = `${ code }:${ pickup ? 'pickup' : 'courier' }`;
	const known = useRef( new Map<string, AddressPolicy | null>() );
	const mounted = useRef( true );
	const [ , rerender ] = useState( 0 );

	useEffect( () => {
		mounted.current = true;

		return () => {
			mounted.current = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! enabled || '' === code || known.current.has( key ) ) {
			return;
		}

		// Marked at once, so a second render of the same destination never asks twice.
		known.current.set( key, null );

		fetchAddressPolicy( code, pickup )
			.then( ( answer ) => normalizeAddressPolicy( answer?.fields ) )
			.catch( () => null )
			.then( ( policy ) => {
				if ( policy ) {
					known.current.set( key, policy );

					if ( mounted.current ) {
						rerender( ( n ) => n + 1 );
					}
				}
			} );
	}, [ enabled, code, pickup, key ] );

	return known.current.get( key ) ?? null;
}
